<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Order\GuestOrderAccess;
use App\Domain\Payment\ApplyMidtransStatus;
use App\Domain\Payment\CreatePaymentService;
use App\Domain\Payment\FakeMidtransProvider;
use App\Domain\Payment\HttpMidtransProvider;
use App\Domain\Payment\MidtransProvider;
use App\Domain\Payment\ReconcileMidtransPayment;
use App\Domain\Refund\RefundProvider;
use App\Enums\UserRole;
use App\Jobs\ReconcilePendingPayments;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function payableOrder(): array
{
    $warehouse = Warehouse::factory()->create(['provider_area_id' => 'origin']);
    $variant = ProductVariant::factory()->create();
    $inventory = Inventory::create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'on_hand' => 2, 'reserved' => 0, 'sold' => 0]);
    $order = Order::create(['order_number' => 'GS-TEST-'.fake()->unique()->numerify('######'), 'warehouse_id' => $warehouse->id,
        'customer_email' => 'buyer@example.test', 'customer_name' => 'Buyer', 'customer_phone' => '08123', 'status' => 'PENDING_PAYMENT',
        'subtotal' => '100000.00', 'product_discount' => '0.00', 'voucher_discount' => '0.00', 'shipping_fee' => '20000.00',
        'grand_total' => '120000.00', 'currency' => 'IDR', 'idempotency_key' => fake()->uuid(), 'payload_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
    app(InventoryService::class)->reserve($inventory, $order, 1);
    $order->items()->create(['product_variant_id' => $variant->id, 'product_name' => 'Runner', 'brand_name' => 'Brand',
        'sku' => $variant->sku, 'size_label' => '42', 'unit_price' => '100000.00', 'discount_amount' => '0.00',
        'quantity' => 1, 'line_total' => '100000.00', 'weight_grams' => 500]);

    app(GuestOrderAccess::class)->issue($order);
    test()->withHeader('X-Guest-Order-Token', $order->guest_access_token);

    return compact('order', 'inventory');
}

function midtransPayload(Order $order, string $status): array
{
    $payload = ['order_id' => $order->order_number, 'transaction_id' => 'mid-'.$order->id, 'transaction_status' => $status,
        'status_code' => '200', 'gross_amount' => '120000.00', 'transaction_time' => '2026-08-23 12:00:00'];
    $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('services.midtrans.server_key'));

    return $payload;
}

beforeEach(function () {
    config(['services.midtrans.driver' => 'fake', 'services.midtrans.server_key' => 'test-server-key']);
});

it('creates one reusable Snap payment for a payable order', function () {
    ['order' => $order] = payableOrder();
    $url = "/api/v1/orders/{$order->order_number}/payment";
    $first = $this->postJson($url, ['email' => $order->customer_email])->assertCreated()
        ->assertJsonPath('data.payment.status', 'PENDING')
        ->assertJsonPath('data.payment.provider_status', null);
    $this->postJson($url, ['email' => $order->customer_email])->assertCreated()->assertJsonPath('data.payment.snap_token', $first->json('data.payment.snap_token'));
    expect(Payment::count())->toBe(1);
});

it('maps Midtrans provider statuses to the internal MVP payment contract', function (string $providerStatus, ?string $fraudStatus, string $internalStatus, string $orderStatus) {
    ['order' => $order] = payableOrder();
    $this->postJson("/api/v1/orders/{$order->order_number}/payment", ['email' => $order->customer_email])->assertCreated();
    $payload = midtransPayload($order, $providerStatus);
    if ($fraudStatus !== null) {
        $payload['fraud_status'] = $fraudStatus;
    }

    $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();

    $payment = $order->payment()->firstOrFail();
    expect($payment->status)->toBe($internalStatus)
        ->and($payment->provider_status)->toBe($providerStatus)
        ->and($order->refresh()->getRawOriginal('status'))->toBe($orderStatus);
})->with([
    'pending' => ['pending', null, 'PENDING', 'PENDING_PAYMENT'],
    'capture challenge' => ['capture', 'challenge', 'PENDING', 'PENDING_PAYMENT'],
    'capture accepted' => ['capture', 'accept', 'SUCCESS', 'PAID'],
    'settlement' => ['settlement', null, 'SUCCESS', 'PAID'],
    'deny' => ['deny', null, 'FAILED', 'PENDING_PAYMENT'],
    'failure' => ['failure', null, 'FAILED', 'PENDING_PAYMENT'],
    'cancel' => ['cancel', null, 'FAILED', 'EXPIRED'],
    'expire' => ['expire', null, 'FAILED', 'EXPIRED'],
]);

it('exposes internal and provider payment statuses to the admin audit API', function () {
    ['order' => $order] = payableOrder();
    $this->postJson("/api/v1/orders/{$order->order_number}/payment", ['email' => $order->customer_email])->assertCreated();
    $this->postJson('/api/v1/payments/midtrans/webhook', midtransPayload($order, 'settlement'))->assertOk();

    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->actingAs($admin)->getJson('/api/v1/admin/payments')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'SUCCESS')
        ->assertJsonPath('data.0.provider_status', 'settlement');
});

it('rejects webhook before persisting when signature is invalid', function () {
    ['order' => $order] = payableOrder();
    $this->postJson("/api/v1/orders/{$order->order_number}/payment", ['email' => $order->customer_email])->assertCreated();
    $payload = midtransPayload($order, 'settlement');
    $payload['signature_key'] = 'invalid';
    $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertUnauthorized()->assertJsonPath('code', 'INVALID_WEBHOOK_SIGNATURE');
    expect(PaymentWebhook::count())->toBe(0);
});

it('keeps a redacted webhook audit when business validation fails', function () {
    ['order' => $order, 'inventory' => $inventory] = payableOrder();
    $this->postJson("/api/v1/orders/{$order->order_number}/payment", ['email' => $order->customer_email])->assertCreated();
    $payload = midtransPayload($order, 'settlement');
    $payload['gross_amount'] = '1.00';
    $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('services.midtrans.server_key'));

    $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertStatus(422);

    $event = PaymentWebhook::sole();
    expect($event->processed_at)->not->toBeNull()
        ->and($event->processing_error)->toContain('Payment details do not match the order')
        ->and($event->payload_redacted['signature_key'])->toBe('[REDACTED]')
        ->and($order->payment->refresh()->status)->toBe('PENDING')
        ->and($inventory->refresh()->reserved)->toBe(1)
        ->and($inventory->sold)->toBe(0);
});

it('processes success exactly once and ignores a later expiry event', function () {
    ['order' => $order, 'inventory' => $inventory] = payableOrder();
    $this->postJson("/api/v1/orders/{$order->order_number}/payment", ['email' => $order->customer_email])->assertCreated();
    $payload = midtransPayload($order, 'settlement');
    $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();
    $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();
    $expiry = midtransPayload($order, 'expire');
    $expiry['transaction_time'] = '2026-08-24 12:00:00';
    $expiry['signature_key'] = hash('sha512', $expiry['order_id'].$expiry['status_code'].$expiry['gross_amount'].config('services.midtrans.server_key'));
    $this->postJson('/api/v1/payments/midtrans/webhook', $expiry)->assertOk();
    expect($order->refresh()->getRawOriginal('status'))->toBe('PAID')->and($inventory->refresh()->on_hand)->toBe(1)->and($inventory->reserved)->toBe(0)->and($inventory->sold)->toBe(1);
    expect($order->payment->refresh()->status)->toBe('SUCCESS')
        ->and($order->payment->provider_status)->toBe('expire');
    expect(PaymentWebhook::count())->toBe(2);
    $this->assertDatabaseMissing('payment_webhooks', ['payload_redacted' => $payload['signature_key']]);
});

it('keeps a webhook retryable after a transient processing failure', function () {
    ['order' => $order] = payableOrder();
    $this->postJson("/api/v1/orders/{$order->order_number}/payment")->assertCreated();
    $payload = midtransPayload($order, 'settlement');
    $real = app(ApplyMidtransStatus::class);
    $this->app->instance(ApplyMidtransStatus::class, new class($real) extends ApplyMidtransStatus
    {
        public bool $failed = false;

        public function __construct(private readonly ApplyMidtransStatus $real) {}

        public function apply(Order $order, Payment $payment, array $payload): void
        {
            if (! $this->failed) {
                $this->failed = true;
                throw new RuntimeException('Lock wait timeout exceeded');
            }
            $this->real->apply($order, $payment, $payload);
        }
    });

    $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertServerError();
    expect(PaymentWebhook::first()->processed_at)->toBeNull()->and($order->refresh()->getRawOriginal('status'))->toBe('PENDING_PAYMENT');

    $this->postJson('/api/v1/payments/midtrans/webhook', $payload)->assertOk();
    expect(PaymentWebhook::first()->processed_at)->not->toBeNull()->and($order->refresh()->getRawOriginal('status'))->toBe('PAID');
});

it('refuses the fake Midtrans driver or an empty server key in production', function (string $driver, ?string $key) {
    config(['services.midtrans.driver' => $driver, 'services.midtrans.server_key' => $key]);
    $this->app->detectEnvironment(fn () => 'production');

    expect(fn () => app(MidtransProvider::class))->toThrow(RuntimeException::class)
        ->and(fn () => app(RefundProvider::class))->toThrow(RuntimeException::class);
})->with([['fake', 'test-server-key'], ['http', null]]);

it('rejects webhook signatures when no server key is configured', function () {
    config(['services.midtrans.server_key' => null]);
    $payload = ['order_id' => 'GS-X', 'status_code' => '200', 'gross_amount' => '1.00'];
    $payload['signature_key'] = hash('sha512', 'GS-X2001.00');

    expect(app(FakeMidtransProvider::class)->verifyWebhook($payload))->toBeFalse()
        ->and(app(HttpMidtransProvider::class)->verifyWebhook($payload))->toBeFalse();
});

it('reconciles a paid order whose webhook never arrived', function () {
    ['order' => $order] = payableOrder();
    $this->postJson("/api/v1/orders/{$order->order_number}/payment")->assertCreated();
    $payment = $order->payment()->firstOrFail();
    $payment->forceFill(['created_at' => now()->subMinutes(10)])->save();
    $settled = midtransPayload($order, 'settlement');
    $this->app->instance(MidtransProvider::class, Mockery::mock(FakeMidtransProvider::class.'[getStatus]')
        ->shouldReceive('getStatus')->once()->andReturn($settled + ['currency' => 'IDR'])->getMock());

    (new ReconcilePendingPayments)->handle(app(ReconcileMidtransPayment::class));
    // A second run inside the interval must not ask Midtrans again (mock expects once).
    (new ReconcilePendingPayments)->handle(app(ReconcileMidtransPayment::class));

    expect($order->refresh()->getRawOriginal('status'))->toBe('PAID')->and($payment->refresh()->status)->toBe('SUCCESS');
});

it('leaves fresh, expired and unknown payments alone during reconciliation', function () {
    ['order' => $fresh] = payableOrder();
    $this->postJson("/api/v1/orders/{$fresh->order_number}/payment")->assertCreated();
    ['order' => $expired] = payableOrder();
    $this->postJson("/api/v1/orders/{$expired->order_number}/payment")->assertCreated();
    $expired->payment->forceFill(['created_at' => now()->subHour()])->save();
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    ['order' => $unknown] = payableOrder();
    $this->postJson("/api/v1/orders/{$unknown->order_number}/payment")->assertCreated();
    $unknown->payment->forceFill(['created_at' => now()->subHour()])->save();

    $provider = Mockery::mock(FakeMidtransProvider::class.'[getStatus]');
    // Only the old, still-payable order is checked; Midtrans has no charge for it yet.
    $provider->shouldReceive('getStatus')->once()->withArgs(fn (Order $order) => $order->id === $unknown->id)->andReturnNull();
    $this->app->instance(MidtransProvider::class, $provider);

    (new ReconcilePendingPayments)->handle(app(ReconcileMidtransPayment::class));

    expect($unknown->refresh()->getRawOriginal('status'))->toBe('PENDING_PAYMENT')
        ->and($unknown->payment->refresh()->status)->toBe('PENDING');
});

it('sends Snap a finish URL back to the order without exposing the guest token', function () {
    config(['services.storefront.url' => 'https://toko.test/']);
    ['order' => $order] = payableOrder();
    Http::fake(['*' => Http::response(['token' => 'snap-token', 'redirect_url' => 'https://pay.test/x'])]);

    (new HttpMidtransProvider)->createSnapTransaction($order);
    $order->update(['user_id' => User::factory()->create()->id]);
    (new HttpMidtransProvider)->createSnapTransaction($order->refresh());

    $finishUrls = Http::recorded()->map(fn ($pair) => $pair[0]['callbacks']['finish'] ?? null)->all();
    expect($finishUrls)->toBe(['https://toko.test/guest-orders', 'https://toko.test/orders/'.$order->order_number])
        ->and(implode(' ', $finishUrls))->not->toContain((string) $order->guest_access_token);
});

it('tells the tracking page whether a pending order can still be paid', function () {
    ['order' => $order] = payableOrder();
    $this->postJson('/api/v1/orders/track', ['order_number' => $order->order_number, 'email' => $order->customer_email])
        ->assertOk()->assertJsonPath('data.can_pay', true)->assertJsonMissingPath('data.payment');
    $order->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/v1/orders/track', ['order_number' => $order->order_number, 'email' => $order->customer_email])
        ->assertOk()->assertJsonPath('data.can_pay', false);
});

function signedMidtransPayload(string $providerOrderId, string $transactionId, string $status): array
{
    $payload = ['order_id' => $providerOrderId, 'transaction_id' => $transactionId, 'transaction_status' => $status,
        'status_code' => '200', 'gross_amount' => '120000.00', 'transaction_time' => '2026-08-23 12:30:00'];
    $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('services.midtrans.server_key'));

    return $payload;
}

it('lets the buyer change payment method with a new Midtrans order ID', function () {
    ['order' => $order, 'inventory' => $inventory] = payableOrder();
    $url = "/api/v1/guest/orders/{$order->order_number}/payment";
    $first = $this->postJson($url)->assertCreated();
    expect($first->json('data.payment.provider_order_id'))->toBe($order->order_number);
    // Pembeli memilih QRIS di Snap: Midtrans mencatat charge pending pada order_id pertama.
    $this->postJson('/api/v1/payments/midtrans/webhook', signedMidtransPayload($order->order_number, 'qris-1', 'pending'))->assertOk();

    $second = $this->postJson($url, ['change_method' => true])->assertCreated()
        ->assertJsonPath('data.payment.status', 'PENDING')
        ->assertJsonPath('data.payment.attempt', 2)
        ->assertJsonPath('data.payment.provider_order_id', $order->order_number.'-2');
    expect($second->json('data.redirect_url'))->not->toBe($first->json('data.redirect_url'));
    $this->assertDatabaseHas('payment_attempts', ['provider_order_id' => $order->order_number, 'provider_transaction_id' => 'qris-1']);

    // Notifikasi cancel untuk percobaan lama tidak boleh menutup pesanan atau melepas stok.
    $this->postJson('/api/v1/payments/midtrans/webhook', signedMidtransPayload($order->order_number, 'qris-1', 'cancel'))->assertOk();
    expect($order->refresh()->getRawOriginal('status'))->toBe('PENDING_PAYMENT')
        ->and($inventory->refresh()->reserved)->toBe(1)
        ->and($order->payment->refresh()->status)->toBe('PENDING');

    $this->postJson('/api/v1/payments/midtrans/webhook', signedMidtransPayload($order->order_number.'-2', 'va-2', 'settlement'))->assertOk();
    expect($order->refresh()->getRawOriginal('status'))->toBe('PAID')
        ->and($order->payment->refresh()->provider_transaction_id)->toBe('va-2')
        ->and($inventory->refresh()->sold)->toBe(1);
});

it('flags success on a replaced payment attempt for review without touching the order', function () {
    ['order' => $order] = payableOrder();
    $url = "/api/v1/guest/orders/{$order->order_number}/payment";
    $this->postJson($url)->assertCreated();
    $this->postJson('/api/v1/payments/midtrans/webhook', signedMidtransPayload($order->order_number, 'qris-1', 'pending'))->assertOk();
    $this->postJson($url, ['change_method' => true])->assertCreated();

    $this->postJson('/api/v1/payments/midtrans/webhook', signedMidtransPayload($order->order_number, 'qris-1', 'settlement'))->assertOk();

    expect($order->refresh()->getRawOriginal('status'))->toBe('PENDING_PAYMENT')
        ->and(PaymentWebhook::latest('id')->first()->processing_error)->toContain('manual review');
});

it('keeps a payment that lands while the old session is being closed', function () {
    ['order' => $order] = payableOrder();
    $url = "/api/v1/guest/orders/{$order->order_number}/payment";
    $this->postJson($url)->assertCreated();
    $this->postJson('/api/v1/payments/midtrans/webhook', signedMidtransPayload($order->order_number, 'qris-1', 'pending'))->assertOk();
    $this->app->bind(MidtransProvider::class, fn () => new class extends FakeMidtransProvider
    {
        public function closeTransaction(Order $order, string $action): array
        {
            return ['order_id' => $order->midtransOrderId(), 'transaction_status' => 'settlement',
                'transaction_id' => 'qris-1', 'gross_amount' => '120000.00'];
        }
    });

    $this->postJson($url, ['change_method' => true])->assertStatus(422);

    expect($order->refresh()->getRawOriginal('status'))->toBe('PAID')
        ->and($order->payment->refresh()->attempt)->toBe(1);
    $this->assertDatabaseCount('payment_attempts', 0);
});

it('does not change method when the old session cannot be closed', function () {
    ['order' => $order] = payableOrder();
    $url = "/api/v1/guest/orders/{$order->order_number}/payment";
    $first = $this->postJson($url)->assertCreated();
    $this->postJson('/api/v1/payments/midtrans/webhook', signedMidtransPayload($order->order_number, 'qris-1', 'pending'))->assertOk();
    $this->app->bind(MidtransProvider::class, fn () => new class extends FakeMidtransProvider
    {
        public function closeTransaction(Order $order, string $action): array
        {
            throw new RuntimeException('Timeout');
        }
    });

    $this->postJson($url, ['change_method' => true])->assertStatus(422)->assertJsonValidationErrors('payment');

    expect($order->payment->refresh()->snap_token)->toBe($first->json('data.payment.snap_token'))
        ->and($order->payment->attempt)->toBe(1);
});

it('issues a new token for the same Midtrans order ID when no charge exists yet', function () {
    ['order' => $order] = payableOrder();
    $url = "/api/v1/guest/orders/{$order->order_number}/payment";
    $this->postJson($url)->assertCreated();
    $this->app->bind(MidtransProvider::class, fn () => new class extends FakeMidtransProvider
    {
        public function closeTransaction(Order $order, string $action): array
        {
            throw new RuntimeException('Must not close a session without a charge');
        }
    });

    $this->postJson($url, ['change_method' => true])->assertCreated()
        ->assertJsonPath('data.payment.attempt', 1)
        ->assertJsonPath('data.payment.provider_order_id', $order->order_number);
    $this->assertDatabaseCount('payment_attempts', 0);
});

it('starts a new attempt after a failed charge without trying to close it', function () {
    ['order' => $order, 'inventory' => $inventory] = payableOrder();
    $url = "/api/v1/guest/orders/{$order->order_number}/payment";
    $this->postJson($url)->assertCreated();
    $this->postJson('/api/v1/payments/midtrans/webhook', signedMidtransPayload($order->order_number, 'qris-1', 'deny'))->assertOk();
    $this->app->bind(MidtransProvider::class, fn () => new class extends FakeMidtransProvider
    {
        public function closeTransaction(Order $order, string $action): array
        {
            throw new RuntimeException('Denied charges cannot be cancelled');
        }
    });

    $this->postJson($url, ['change_method' => true])->assertCreated()
        ->assertJsonPath('data.payment.status', 'PENDING')
        ->assertJsonPath('data.payment.provider_order_id', $order->order_number.'-2');
    $this->assertDatabaseHas('payment_attempts', ['provider_order_id' => $order->order_number, 'provider_status' => 'deny']);
    expect($order->refresh()->getRawOriginal('status'))->toBe('PENDING_PAYMENT')
        ->and($inventory->refresh()->reserved)->toBe(1);
});

it('shows the paid status when the buyer returns from Midtrans before the webhook arrives', function () {
    config(['services.midtrans.driver' => 'http', 'services.midtrans.server_key' => 'SB-Mid-server-test',
        'services.midtrans.snap_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        'services.midtrans.api_url' => 'https://api.sandbox.midtrans.com/v2']);
    Http::preventStrayRequests();
    ['order' => $order, 'inventory' => $inventory] = payableOrder();
    Http::fake([
        'app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token']),
        'api.sandbox.midtrans.com/v2/*/status' => Http::response(midtransPayload($order, 'settlement') + ['payment_type' => 'qris', 'fraud_status' => 'accept']),
    ]);
    app(CreatePaymentService::class)->create($order);

    // Tidak ada webhook: halaman pesanan sendiri yang menanyakan status ke Midtrans.
    $this->getJson('/api/v1/guest/orders/'.$order->order_number)->assertOk()->assertJsonPath('data.status', 'PAID');
    expect($order->payment->refresh()->status)->toBe('SUCCESS')
        ->and($inventory->refresh()->sold)->toBe(1);
});

it('asks Midtrans at most once per cooldown while the order page is reopened', function () {
    config(['services.midtrans.driver' => 'http', 'services.midtrans.server_key' => 'SB-Mid-server-test',
        'services.midtrans.snap_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
        'services.midtrans.api_url' => 'https://api.sandbox.midtrans.com/v2']);
    Http::preventStrayRequests();
    ['order' => $order] = payableOrder();
    Http::fake([
        'app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token']),
        'api.sandbox.midtrans.com/v2/*/status' => Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404),
    ]);
    app(CreatePaymentService::class)->create($order);

    $this->getJson('/api/v1/guest/orders/'.$order->order_number)->assertOk()->assertJsonPath('data.status', 'PENDING_PAYMENT');
    $this->getJson('/api/v1/guest/orders/'.$order->order_number)->assertOk();
    Http::assertSentCount(2); // satu pembuatan sesi Snap + satu pengecekan status
});
