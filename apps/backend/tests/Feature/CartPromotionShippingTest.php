<?php

use App\Domain\Promotion\PricingService;
use App\Domain\Shipping\RegionAddressResolver;
use App\Domain\Shipping\ShippingProvider;
use App\Domain\Shipping\ShippingQuote;
use App\Domain\Shipping\ShippingRateResolver;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
function cartRegion(string $province, string $city): array
{
    static $i = 40;
    $i++;
    $p = (string) $i;
    $r = "$p.01";
    $d = "$r.01";
    $v = "$d.2001";
    DB::table('region_provinces')->insert(['code' => $p, 'name' => $province]);
    DB::table('region_regencies')->insert(['code' => $r, 'province_code' => $p, 'name' => $city]);
    DB::table('region_districts')->insert(['code' => $d, 'regency_code' => $r, 'name' => 'Test']);
    DB::table('region_villages')->insert(['code' => $v, 'district_code' => $d, 'name' => 'Test', 'postal_code' => '12345']);

    return ['province_code' => $p, 'regency_code' => $r, 'district_code' => $d, 'village_code' => $v];
}

it('creates an opaque guest cart token and reuses it', function () {
    $variant = ProductVariant::factory()->create(['price' => '100000.00']);
    Inventory::factory()->create(['product_variant_id' => $variant->id, 'on_hand' => 5, 'reserved' => 1]);
    $created = $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertCreated();
    $token = $created->json('data.guest_token');
    expect($token)->toBeString()->not->toBeEmpty();
    $this->withHeader('X-Guest-Cart-Token', $token)->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.quantity', 2);
});

it('applies only highest priority product discount then stacks one voucher', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '100000.00']);
    $cart = Cart::create(['guest_token' => '00000000-0000-4000-8000-000000000001', 'status' => 'ACTIVE']);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
    $low = Promotion::create(['name' => 'Low', 'type' => 'PERCENTAGE', 'value' => '10.00', 'priority' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    $low->products()->attach($product);
    $high = Promotion::create(['name' => 'High', 'type' => 'FIXED_AMOUNT', 'value' => '50000.00', 'priority' => 10, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    $high->products()->attach($product);
    Voucher::create(['code' => 'SAVE20', 'name' => 'Save 20', 'type' => 'PERCENTAGE', 'value' => '20.00', 'minimum_amount' => '100000.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    $result = app(PricingService::class)->calculate($cart, 'SAVE20', '20000.00');
    // Promo nominal 50.000 berlaku per pasang: 2 pasang x 50.000. Voucher 20% dari sisa 100.000.
    expect($result['subtotal'])->toBe('200000.00')->and($result['product_discount'])->toBe('100000.00')
        ->and($result['items'][0]['discount'])->toBe('100000.00')->and($result['items'][0]['line_total'])->toBe('100000.00')
        ->and($result['voucher_discount'])->toBe('20000.00')->and($result['grand_total'])->toBe('100000.00');

    // Potongan per pasang tidak boleh melebihi harga satuan (harga tidak bisa minus).
    $high->update(['value' => '150000.00']);
    expect(app(PricingService::class)->calculate($cart, null, '0.00')['product_discount'])->toBe('200000.00');
});

it('limits a product-restricted voucher to the discounted price of those products', function () {
    $eligible = ProductVariant::factory()->create(['price' => '200000.00']);
    $other = ProductVariant::factory()->create(['price' => '800000.00']);
    $cart = Cart::create(['guest_token' => fake()->uuid(), 'status' => 'ACTIVE']);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $eligible->id, 'quantity' => 1]);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $other->id, 'quantity' => 1]);
    $promotion = Promotion::create(['name' => 'Promo', 'type' => 'PERCENTAGE', 'value' => '10.00', 'priority' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    $promotion->products()->attach($eligible->product_id);
    $percent = Voucher::create(['code' => 'ONLY50', 'name' => 'Only 50', 'type' => 'PERCENTAGE', 'value' => '50.00', 'minimum_amount' => '500000.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    $percent->products()->attach($eligible->product_id);
    $fixed = Voucher::create(['code' => 'ONLY500K', 'name' => 'Only 500K', 'type' => 'FIXED_AMOUNT', 'value' => '500000.00', 'minimum_amount' => '0.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    $fixed->products()->attach($eligible->product_id);

    // 50% dari harga produk yang berlaku setelah promo (180.000), bukan dari seluruh keranjang.
    // Minimum transaksi 500.000 terpenuhi oleh total keranjang (980.000).
    $result = app(PricingService::class)->calculate($cart, 'ONLY50', '20000.00');
    expect($result['voucher_discount'])->toBe('90000.00')->and($result['grand_total'])->toBe('910000.00');

    // Potongan nominal tidak boleh melebihi harga produk yang berlaku.
    expect(app(PricingService::class)->calculate($cart, 'ONLY500K', '20000.00')['voucher_discount'])->toBe('180000.00');
});

it('calculates shipping and free shipping voucher from server data', function () {
    $warehouse = Warehouse::factory()->create(['provider_area_id' => 'origin']);
    $variant = ProductVariant::factory()->create(['price' => '150000.00', 'weight_grams' => 900]);
    $cart = Cart::create(['guest_token' => '00000000-0000-4000-8000-000000000002', 'status' => 'ACTIVE']);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);
    Voucher::create(['code' => 'FREESHIP', 'name' => 'Free Shipping', 'type' => 'FREE_SHIPPING', 'value' => '0.00', 'minimum_amount' => '0.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    $this->withHeader('X-Guest-Cart-Token', $cart->guest_token)->postJson('/api/v1/checkout/quote', ['destination_area_id' => 'destination', 'courier' => 'jne', 'service' => 'REG', 'voucher_code' => 'FREESHIP'] + cartRegion('DKI Jakarta', 'Jakarta Selatan'))
        ->assertOk()->assertJsonPath('data.pricing.shipping_fee', '20000.00')->assertJsonPath('data.pricing.voucher_discount', '20000.00')->assertJsonPath('data.pricing.grand_total', '150000.00');
});
it('uses the configured provider for destinations outside Jabodetabek', function () {
    Warehouse::factory()->create(['provider_area_id' => 'origin']);
    $variant = ProductVariant::factory()->create(['price' => '150000.00', 'weight_grams' => 900]);
    $cart = Cart::create(['guest_token' => fake()->uuid(), 'status' => 'ACTIVE']);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);

    $this->withHeader('X-Guest-Cart-Token', $cart->guest_token)
        ->postJson('/api/v1/checkout/quote', ['service' => 'REG'] + cartRegion('Sumatera Utara', 'Medan'))
        ->assertOk()
        ->assertJsonPath('data.shipping.provider', 'FAKE')
        ->assertJsonPath('data.pricing.shipping_fee', '20000.00');
});

it('limits cart quantity using available stock instead of a fixed cap', function () {
    $variant = ProductVariant::factory()->create();
    Inventory::factory()->create(['product_variant_id' => $variant->id, 'on_hand' => 40, 'reserved' => 5]);

    $created = $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 30])
        ->assertCreated()
        ->assertJsonPath('data.items.0.quantity', 30);

    $this->withHeader('X-Guest-Cart-Token', $created->json('data.guest_token'))
        ->patchJson('/api/v1/cart/items/'.$created->json('data.items.0.id'), ['quantity' => 36])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('quantity');
});

it('maps internal regions to the provider area id on the server', function () {
    config(['gutshoes.shipping_driver' => 'biteship', 'services.biteship.api_key' => 'secret']);
    Http::fake(['*/v1/maps/areas*' => Http::response(['areas' => [['id' => 'ID-BITESHIP-12345', 'postal_code' => 12345, 'administrative_division_level_3_name' => 'Test']]])]);
    $address = app(RegionAddressResolver::class)->resolve(cartRegion('DKI Jakarta', 'Jakarta Selatan') + [
        'postal_code' => '12345',
        'provider_area_id' => 'client-value-must-not-be-used',
    ]);

    expect($address['provider_area_id'])->toBe('ID-BITESHIP-12345');
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'secret') && str_contains($request->url(), '/v1/maps/areas'));
});

it('keeps percentage discounts in whole rupiah so Midtrans amounts match', function () {
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '199999.00']);
    $cart = Cart::create(['guest_token' => fake()->uuid(), 'status' => 'ACTIVE']);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);
    Promotion::create(['name' => 'Fifteen', 'type' => 'PERCENTAGE', 'value' => '15.00', 'priority' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true])->products()->attach($product);
    Voucher::create(['code' => 'SEVEN', 'name' => 'Seven', 'type' => 'PERCENTAGE', 'value' => '7.00', 'minimum_amount' => '0.00', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);

    $result = app(PricingService::class)->calculate($cart, 'SEVEN', '20000.00');

    // 199999 x 15% = 29999.85 -> 30000; (199999 - 30000) x 7% = 11899.93 -> 11900.
    expect($result['product_discount'])->toBe('30000.00')->and($result['items'][0]['line_total'])->toBe('169999.00')
        ->and($result['voucher_discount'])->toBe('11900.00')->and($result['grand_total'])->toBe('178099.00');
});

function voucherOrder(string $status, ?int $userId = null, string $email = 'buyer@example.test'): Order
{
    return Order::create(['order_number' => 'GS-V-'.fake()->unique()->numerify('######'), 'user_id' => $userId, 'warehouse_id' => Warehouse::factory()->create()->id,
        'customer_email' => $email, 'customer_name' => 'Buyer', 'customer_phone' => '08123', 'status' => $status,
        'subtotal' => '100000.00', 'product_discount' => '0.00', 'voucher_discount' => '10000.00', 'shipping_fee' => '0.00',
        'grand_total' => '90000.00', 'currency' => 'IDR', 'idempotency_key' => fake()->uuid(), 'payload_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
}

it('frees voucher usage held by expired or cancelled orders', function () {
    $voucher = Voucher::create(['code' => 'ONCE', 'name' => 'Once', 'type' => 'FIXED_AMOUNT', 'value' => '10000.00', 'minimum_amount' => '0.00', 'usage_limit' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    foreach (['EXPIRED', 'CANCELLED'] as $status) {
        VoucherUsage::create(['voucher_id' => $voucher->id, 'order_id' => voucherOrder($status)->id, 'discount_amount' => '10000.00']);
    }
    $pricing = app(PricingService::class);
    $pricing->assertVoucherUsable($voucher, null, 'other@example.test');

    VoucherUsage::create(['voucher_id' => $voucher->id, 'order_id' => voucherOrder('PENDING_PAYMENT')->id, 'discount_amount' => '10000.00']);
    expect(fn () => $pricing->assertVoucherUsable($voucher, null, 'other@example.test'))->toThrow(DomainException::class, 'Kuota voucher sudah habis.');
});

it('enforces the per-customer voucher limit by account and email', function () {
    $voucher = Voucher::create(['code' => 'PERUSER', 'name' => 'Per user', 'type' => 'FIXED_AMOUNT', 'value' => '10000.00', 'minimum_amount' => '0.00', 'usage_limit_per_user' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    VoucherUsage::create(['voucher_id' => $voucher->id, 'order_id' => voucherOrder('PAID', null, 'repeat@example.test')->id, 'discount_amount' => '10000.00']);
    $pricing = app(PricingService::class);

    $pricing->assertVoucherUsable($voucher, null, 'fresh@example.test');
    expect(fn () => $pricing->assertVoucherUsable($voucher, null, 'repeat@example.test'))->toThrow(DomainException::class, 'Batas pemakaian voucher');
});

it('counts only stock in the active warehouse that checkout reserves from', function () {
    $variant = ProductVariant::factory()->create();
    Inventory::factory()->create(['product_variant_id' => $variant->id, 'on_hand' => 2]);
    Inventory::factory()->create(['product_variant_id' => $variant->id, 'on_hand' => 50, 'warehouse_id' => Warehouse::factory()->create(['is_active' => false])->id]);

    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 3])
        ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->postJson('/api/v1/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertCreated();
});

it('looks up promotions once per cart instead of once per item', function () {
    $cart = Cart::create(['guest_token' => fake()->uuid(), 'status' => 'ACTIVE']);
    $promotion = Promotion::create(['name' => 'Ten', 'type' => 'PERCENTAGE', 'value' => '10.00', 'priority' => 1, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'is_active' => true]);
    foreach (range(1, 5) as $i) {
        $variant = ProductVariant::factory()->create(['price' => '100000.00']);
        CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);
        $promotion->products()->attach($variant->product_id);
    }

    DB::enableQueryLog();
    $result = app(PricingService::class)->calculate($cart, null);
    $promotionQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'promotions'))->count();

    expect($result['product_discount'])->toBe('50000.00')->and($promotionQueries)->toBeLessThanOrEqual(2);
});

it('caches shipping quotes so one checkout calls the provider once', function () {
    config(['gutshoes.shipping_quote_cache_minutes' => 15]);
    $calls = 0;
    $this->app->instance(ShippingProvider::class, new class($calls) implements ShippingProvider
    {
        public function __construct(public int &$calls) {}

        public function quote(array $origin, array $destination, int $weightGrams, int $quantity, ?string $courier = null): array
        {
            $this->calls++;

            return [new ShippingQuote('RAJAONGKIR', 'jne', 'REG', 'Regular', '18000.00', 2)];
        }
    });
    $resolver = app(ShippingRateResolver::class);
    $origin = ['provider_area_id' => 'origin'];

    $first = $resolver->quotes($origin, ['provider_area_id' => 'dest-1'], 800, 1, 'jne');
    $again = $resolver->quotes($origin, ['provider_area_id' => 'dest-1'], 800, 1, 'JNE');
    $resolver->quotes($origin, ['provider_area_id' => 'dest-1'], 1600, 2, 'jne');
    $resolver->quotes($origin, ['provider_area_id' => 'dest-2'], 800, 1, 'jne');

    expect($calls)->toBe(3)->and($again)->toEqual($first);
});
