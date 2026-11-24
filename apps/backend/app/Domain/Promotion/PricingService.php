<?php

namespace App\Domain\Promotion;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Promotion;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use DomainException;

class PricingService
{
    /** @return array<string,mixed> */
    public function calculate(Cart $cart, ?string $voucherCode, string $shippingFee = '0.00'): array
    {
        $cart->load(['items.variant.product', 'items.variant']);
        $subtotal = 0.0;
        $productDiscount = 0.0;
        $lines = [];
        $lineTotals = [];
        // One query for every promotion that touches this cart instead of one per item.
        $productIds = $cart->items->map(fn ($item) => $item->variant->product_id)->unique()->values();
        $promotions = Promotion::query()->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>=', now())
            ->whereHas('products', fn ($q) => $q->whereIn('products.id', $productIds))
            ->with(['products' => fn ($q) => $q->whereIn('products.id', $productIds)->select('products.id')])
            ->orderByDesc('priority')->orderBy('id')->get();
        foreach ($cart->items as $item) {
            $unit = (float) $item->variant->price;
            $line = $unit * $item->quantity;
            $subtotal += $line;
            $promotion = $promotions->first(fn (Promotion $candidate) => $candidate->products->contains('id', $item->variant->product_id));
            // IDR has no minor unit at Midtrans; keep every amount in whole rupiah.
            $discount = $promotion ? min($line, round($promotion->type === 'PERCENTAGE' ? $line * ((float) $promotion->value / 100) : (float) $promotion->value)) : 0.0;
            $productDiscount += $discount;
            $lineTotals[] = ['product_id' => $item->variant->product_id, 'amount' => $line - $discount];
            $lines[] = ['variant_id' => $item->product_variant_id, 'sku' => $item->variant->sku, 'quantity' => $item->quantity, 'unit_price' => number_format($unit, 2, '.', ''), 'discount' => number_format($discount, 2, '.', ''), 'line_total' => number_format($line - $discount, 2, '.', '')];
        }
        $afterProducts = $subtotal - $productDiscount;
        $voucherDiscount = 0.0;
        $shipping = (float) $shippingFee;
        $voucher = null;
        if ($voucherCode) {
            $voucher = Voucher::where('code', $voucherCode)->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>=', now())->first();
            if (! $voucher) {
                throw new DomainException('Voucher tidak valid atau sudah kedaluwarsa.');
            } if ($afterProducts < (float) $voucher->minimum_amount) {
                throw new DomainException('Minimum transaksi untuk voucher ini belum terpenuhi.');
            }
            $this->assertVoucherUsable($voucher, $cart->user_id, null);
            $voucherProductIds = $voucher->products()->pluck('products.id');
            $applicable = $voucherProductIds->isEmpty() || $voucherProductIds->intersect($productIds)->isNotEmpty();
            if (! $applicable) {
                throw new DomainException('Voucher tidak berlaku untuk produk di keranjang.');
            }
            // Voucher khusus produk hanya memotong harga (setelah diskon produk) dari produk tersebut;
            // minimum transaksi tetap dihitung dari seluruh keranjang.
            $voucherBase = $voucherProductIds->isEmpty() ? $afterProducts : (float) collect($lineTotals)
                ->filter(fn (array $line) => $voucherProductIds->contains($line['product_id']))->sum('amount');
            if ($voucher->type === 'PERCENTAGE') {
                $voucherDiscount = $voucherBase * ((float) $voucher->value / 100);
            } elseif ($voucher->type === 'FIXED_AMOUNT') {
                $voucherDiscount = min($voucherBase, (float) $voucher->value);
            } else {
                $voucherDiscount = $shipping;
            }
            if ($voucher->maximum_discount !== null) {
                $voucherDiscount = min($voucherDiscount, (float) $voucher->maximum_discount);
            }
            $voucherDiscount = round($voucherDiscount);
        }

        return ['items' => $lines, 'subtotal' => number_format($subtotal, 2, '.', ''), 'product_discount' => number_format($productDiscount, 2, '.', ''), 'voucher_discount' => number_format($voucherDiscount, 2, '.', ''),
            'shipping_fee' => number_format($shipping, 2, '.', ''), 'grand_total' => number_format(max(0, round($afterProducts + $shipping - $voucherDiscount)), 2, '.', ''), 'currency' => 'IDR', 'voucher' => $voucher?->code];
    }

    /**
     * Usage held by orders that expired or were cancelled does not count.
     * Pass $lock inside the order transaction (with the voucher row locked) so
     * MySQL reads the latest committed usages instead of the transaction snapshot.
     */
    public function assertVoucherUsable(Voucher $voucher, ?int $userId, ?string $email, bool $lock = false): void
    {
        $active = fn () => VoucherUsage::query()->where('voucher_usages.voucher_id', $voucher->id)
            ->join('orders', 'orders.id', '=', 'voucher_usages.order_id')
            ->whereNotIn('orders.status', [OrderStatus::CANCELLED->value, OrderStatus::EXPIRED->value])
            ->when($lock, fn ($query) => $query->lockForUpdate());

        if ($voucher->usage_limit && $active()->count() >= $voucher->usage_limit) {
            throw new DomainException('Kuota voucher sudah habis.');
        }
        if ($voucher->usage_limit_per_user && ($userId !== null || filled($email))) {
            $used = $active()->where(fn ($query) => $query
                ->when($userId !== null, fn ($q) => $q->orWhere('orders.user_id', $userId))
                ->when(filled($email), fn ($q) => $q->orWhere('orders.customer_email', $email)))->count();
            if ($used >= $voucher->usage_limit_per_user) {
                throw new DomainException('Batas pemakaian voucher untuk akun/email ini sudah tercapai.');
            }
        }
    }
}
