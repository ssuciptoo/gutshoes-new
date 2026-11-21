<?php

namespace App\Domain\Order;

use App\Domain\Inventory\InventoryService;
use App\Domain\Promotion\PricingService;
use App\Domain\Shipping\ShippingQuote;
use App\Domain\Shipping\ShippingRateResolver;
use App\Enums\OrderStatus;
use App\Jobs\SendOrderEmail;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\Warehouse;
use DomainException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrderService
{
    public function __construct(private readonly PricingService $pricing, private readonly ShippingRateResolver $shipping, private readonly InventoryService $inventory, private readonly GuestOrderAccess $guestAccess) {}

    /** @param array<string,mixed> $payload */
    public function create(?Cart $cart, array $payload, string $idempotencyKey): CreateOrderResult
    {
        ksort($payload);
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($existing = Order::where('idempotency_key', $idempotencyKey)->first()) {
            return $this->existing($existing, $payloadHash);
        }

        if ($cart === null) {
            throw new DomainException('Keranjang kosong.');
        }

        // Quote shipping before opening the transaction: the provider call can take up to
        // its HTTP timeout and must not hold row locks that other checkouts wait on.
        $cart->load('items.variant');
        $warehouse = Warehouse::where('is_active', true)->firstOrFail();
        [$weight, $quantity] = $this->shippingLoad($cart);
        $quotes = $this->shipping->quotes(['provider_area_id' => $warehouse->provider_area_id], $payload['address'], $weight, $quantity, $payload['shipping']['courier'] ?? null);
        $quote = count($quotes) === 1 && $quotes[0]->service === 'REGIONAL_PER_ITEM' ? $quotes[0] : collect($quotes)->first(fn (ShippingQuote $item) => $item->service === $payload['shipping']['service']);
        if (! $quote instanceof ShippingQuote) {
            throw new DomainException('Layanan pengiriman yang dipilih tidak tersedia.');
        }

        return DB::transaction(function () use ($cart, $payload, $idempotencyKey, $payloadHash, $warehouse, $weight, $quantity, $quote): CreateOrderResult {
            if ($existing = Order::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) {
                return $this->existing($existing, $payloadHash);
            }
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);
            $cart->load(['items.variant.product.brand', 'items.variant.size']);
            if ($cart->status !== 'ACTIVE' || $cart->items->isEmpty()) {
                throw new DomainException('Keranjang kosong atau sudah tidak aktif.');
            }
            // The quote was priced for the cart and warehouse read above; reject if either changed.
            if ($this->shippingLoad($cart) !== [$weight, $quantity]
                || Warehouse::where('is_active', true)->value('id') !== $warehouse->id) {
                throw new DomainException('Keranjang berubah selama checkout. Periksa ongkir lalu coba lagi.');
            }
            // Lock the voucher before any limit check so concurrent checkouts serialize on it.
            $voucher = isset($payload['voucher_code']) ? Voucher::where('code', $payload['voucher_code'])->lockForUpdate()->first() : null;
            $totals = $this->pricing->calculate($cart, $payload['voucher_code'] ?? null, $quote->fee);
            if ($voucher) {
                $this->pricing->assertVoucherUsable($voucher, $cart->user_id, $payload['customer']['email'], true);
            }
            $order = Order::create([
                'order_number' => $this->orderNumber(), 'user_id' => $cart->user_id, 'warehouse_id' => $warehouse->id, 'voucher_id' => $voucher?->id,
                'customer_email' => $payload['customer']['email'], 'customer_name' => $payload['customer']['name'], 'customer_phone' => $payload['customer']['phone'],
                'status' => OrderStatus::PENDING_PAYMENT->value, 'subtotal' => $totals['subtotal'], 'product_discount' => $totals['product_discount'],
                'voucher_discount' => $totals['voucher_discount'], 'shipping_fee' => $totals['shipping_fee'], 'grand_total' => $totals['grand_total'],
                'currency' => 'IDR', 'idempotency_key' => $idempotencyKey, 'payload_hash' => $payloadHash, 'expires_at' => now()->addHours(24),
            ]);
            foreach ($cart->items as $index => $item) {
                $line = $totals['items'][$index];
                $order->items()->create([
                    'product_variant_id' => $item->product_variant_id, 'product_name' => $item->variant->product->name, 'brand_name' => $item->variant->product->brand->name,
                    'sku' => $item->variant->sku, 'size_label' => $item->variant->size->label, 'unit_price' => $line['unit_price'], 'discount_amount' => $line['discount'],
                    'quantity' => $item->quantity, 'line_total' => $line['line_total'], 'weight_grams' => $item->variant->weight_grams,
                ]);
                $stock = Inventory::where('warehouse_id', $warehouse->id)->where('product_variant_id', $item->product_variant_id)->first();
                if (! $stock) {
                    throw new DomainException('Stok untuk salah satu ukuran tidak tersedia.');
                }
                $this->inventory->reserve($stock, $order, $item->quantity);
            }
            $order->addresses()->create(['type' => 'SHIPPING'] + $payload['address']);
            $order->shipment()->create(['provider' => $quote->provider, 'courier' => $quote->courier, 'service' => $quote->service, 'fee' => $quote->fee, 'status' => 'PENDING', 'quote_snapshot' => $quote->toArray()]);
            $order->statusHistories()->create(['from_status' => null, 'to_status' => OrderStatus::PENDING_PAYMENT->value, 'note' => 'Order created']);
            if ($voucher) {
                VoucherUsage::create(['voucher_id' => $voucher->id, 'user_id' => $cart->user_id, 'order_id' => $order->id, 'discount_amount' => $totals['voucher_discount']]);
            }
            $cart->update(['status' => 'CONVERTED']);
            $this->guestAccess->issue($order);
            Bus::dispatch(new SendOrderEmail($order->id, 'order_created'));

            return new CreateOrderResult($this->loaded($order), true);
        }, 3);
    }

    private function existing(Order $order, string $payloadHash): CreateOrderResult
    {
        if (! hash_equals($order->payload_hash, $payloadHash)) {
            throw new IdempotencyConflict('Idempotency key was already used with a different payload.');
        }

        return new CreateOrderResult($this->loaded($order), false);
    }

    /** @return array{0:int,1:int} total weight in grams and item count */
    private function shippingLoad(Cart $cart): array
    {
        return [
            (int) $cart->items->sum(fn ($item) => $item->variant->weight_grams * $item->quantity),
            (int) $cart->items->sum('quantity'),
        ];
    }

    private function loaded(Order $order): Order
    {
        return $order->load(['items', 'addresses', 'shipment']);
    }

    private function orderNumber(): string
    {
        do {
            $number = 'GS-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
