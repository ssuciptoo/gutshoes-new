<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StoreConfiguration;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns operational dashboard with database low stock threshold', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    StoreConfiguration::create(['key' => 'low_stock_threshold', 'value' => 3, 'is_public' => false, 'updated_by' => $admin->id]);
    Inventory::factory()->create(['on_hand' => 4, 'reserved' => 2]);
    $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.low_stock_threshold', 3)->assertJsonPath('data.low_stock_count', 1)->assertJsonPath('data.revenue.currency', 'IDR');
});

it('forbids non admins from every operations endpoint', function () {
    $customer = User::factory()->create(['role' => UserRole::CUSTOMER]);
    $this->actingAs($customer)->getJson('/api/v1/admin/orders')->assertForbidden();
    $this->actingAs($customer)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    $this->actingAs($customer)->putJson('/api/v1/admin/configurations/low_stock_threshold', ['value' => 5])->assertForbidden();
});

it('filters and caps paginated admin order queries', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Order::query()->limit(0);
    $this->actingAs($admin)->getJson('/api/v1/admin/orders?status=PAID&per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
});

it('writes a redacted immutable audit record for admin mutation', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->actingAs($admin)->withHeader('X-Request-ID', 'audit-request-1')->putJson('/api/v1/admin/configurations/low_stock_threshold', ['value' => 7, 'secret' => 'must-not-persist'])->assertOk();
    $log = AuditLog::first();
    expect($log)->not->toBeNull()->and($log->admin_id)->toBe($admin->id)->and($log->request_id)->toBe('audit-request-1')->and($log->metadata)->not->toHaveKey('secret');
    $this->assertDatabaseHas('store_configurations', ['key' => 'low_stock_threshold']);
});

it('lists immutable audit records for administrators', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    AuditLog::create(['admin_id' => $admin->id, 'action' => 'UPDATE', 'entity_type' => 'configuration', 'entity_id' => 1, 'description' => 'Configuration updated', 'request_id' => 'audit-list-1', 'ip_address' => '127.0.0.1', 'metadata' => [], 'created_at' => now()]);
    $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs')->assertOk()->assertJsonPath('data.0.action', 'UPDATE');
});
it('adjusts inventory through the domain engine and records its audit trail', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $inventory = Inventory::factory()->create(['on_hand' => 5, 'reserved' => 0]);
    $this->actingAs($admin)->patchJson("/api/v1/admin/inventories/{$inventory->id}/adjust", ['delta' => -2, 'reason' => 'Cycle count'])->assertOk()->assertJsonPath('data.on_hand', 3);
    $this->assertDatabaseHas('inventory_movements', ['inventory_id' => $inventory->id, 'type' => 'ADJUST']);
    expect(AuditLog::count())->toBe(1);
});
it('creates reads adjusts and safely deletes inventory records', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $created = $this->actingAs($admin)->postJson('/api/v1/admin/inventories', [
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'initial_stock' => 8,
        'reason' => 'Penerimaan supplier',
    ])->assertCreated()->assertJsonPath('data.on_hand', 8);
    $inventoryId = $created->json('data.id');
    $this->actingAs($admin)->getJson("/api/v1/admin/inventories/{$inventoryId}/movements")
        ->assertOk()->assertJsonPath('data.0.type', 'ADD');
    $this->actingAs($admin)->deleteJson("/api/v1/admin/inventories/{$inventoryId}")->assertUnprocessable();

    $emptyVariant = ProductVariant::factory()->create();
    $empty = $this->actingAs($admin)->postJson('/api/v1/admin/inventories', [
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $emptyVariant->id,
        'initial_stock' => 0,
        'reason' => 'Pendaftaran lokasi stok',
    ])->assertCreated();
    $this->actingAs($admin)->deleteJson('/api/v1/admin/inventories/'.$empty->json('data.id'))->assertOk();
});

it('returns customer operational detail and updates the shipping origin', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $customer = User::factory()->create(['role' => UserRole::CUSTOMER]);
    $warehouse = Warehouse::factory()->create(['is_active' => true]);

    $this->actingAs($admin)->getJson("/api/v1/admin/customers/{$customer->id}")
        ->assertOk()->assertJsonPath('data.customer.id', $customer->id)
        ->assertJsonPath('data.summary.order_count', 0);

    $this->actingAs($admin)->putJson('/api/v1/admin/warehouse', [
        'name' => 'Gudang Utama', 'phone' => '08123456789', 'address_line' => 'Jl. Contoh 1',
        'province' => 'DKI Jakarta', 'city' => 'Jakarta Selatan', 'district' => 'Kebayoran Baru', 'subdistrict' => 'Gunung',
        'postal_code' => '12110', 'provider_area_id' => 'IDNP6IDNC148IDND836IDZ12110',
    ])->assertOk()->assertJsonPath('data.name', 'Gudang Utama');

    expect($warehouse->refresh()->provider_area_id)->toBe('IDNP6IDNC148IDND836IDZ12110');
});

it('creates the shipping origin on a fresh database without demo data', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $origin = ['name' => 'Gudang Utama', 'phone' => null, 'address_line' => 'Jl. Contoh 1', 'province' => 'DKI Jakarta',
        'city' => 'Jakarta Pusat', 'district' => 'Gambir', 'subdistrict' => 'Gambir', 'postal_code' => '10110', 'provider_area_id' => '17473'];
    $this->actingAs($admin)->getJson('/api/v1/admin/warehouse')->assertOk()->assertJsonPath('data', null);

    $this->actingAs($admin)->putJson('/api/v1/admin/warehouse', $origin)->assertOk()->assertJsonPath('data.provider_area_id', '17473');
    // Simpan kedua memperbarui gudang yang sama, tidak membuat gudang baru.
    $this->actingAs($admin)->putJson('/api/v1/admin/warehouse', ['provider_area_id' => '17474'] + $origin)->assertOk();

    expect(Warehouse::count())->toBe(1)
        ->and((bool) Warehouse::first()->is_active)->toBeTrue()
        ->and(Warehouse::first()->provider_area_id)->toBe('17474');
});

it('rejects percentage promotions and vouchers above 100', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $dates = ['starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString(), 'is_active' => true];
    $promotion = ['name' => 'Promo', 'priority' => 1] + $dates;
    $voucher = ['code' => 'BIG', 'name' => 'Big', 'minimum_amount' => 0] + $dates;

    $this->actingAs($admin)->postJson('/api/v1/admin/promotions', $promotion + ['type' => 'PERCENTAGE', 'value' => 150])->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', $voucher + ['type' => 'PERCENTAGE', 'value' => 101])->assertUnprocessable()->assertJsonValidationErrors('value');
    $this->actingAs($admin)->postJson('/api/v1/admin/promotions', $promotion + ['type' => 'FIXED_AMOUNT', 'value' => 150000])->assertCreated();
    $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', $voucher + ['type' => 'PERCENTAGE', 'value' => 100])->assertCreated();
});

it('searches admin listings on the server and filters inventory by stock level', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    User::factory()->create(['role' => UserRole::CUSTOMER, 'name' => 'Budi Santoso', 'email' => 'budi@example.test']);
    User::factory()->create(['role' => UserRole::CUSTOMER, 'name' => 'Sari', 'email' => 'sari@example.test']);
    $this->actingAs($admin)->getJson('/api/v1/admin/customers?search=budi')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'budi@example.test')->assertJsonPath('meta.total', 1);
    $this->actingAs($admin)->getJson('/api/v1/admin/customers?search=%25')->assertOk()->assertJsonCount(0, 'data');

    StoreConfiguration::create(['key' => 'low_stock_threshold', 'value' => 3, 'is_public' => false, 'updated_by' => $admin->id]);
    Inventory::factory()->create(['on_hand' => 2, 'reserved' => 2]);
    Inventory::factory()->create(['on_hand' => 4, 'reserved' => 1]);
    Inventory::factory()->create(['on_hand' => 20, 'reserved' => 0]);
    $this->actingAs($admin)->getJson('/api/v1/admin/inventories?stock=out')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($admin)->getJson('/api/v1/admin/inventories?stock=low')->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($admin)->getJson('/api/v1/admin/inventories?stock=available')->assertOk()->assertJsonCount(1, 'data');
});

it('stores voucher periods sent as ISO timestamps and returns them as ISO', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $response = $this->actingAs($admin)->postJson('/api/v1/admin/vouchers', [
        'code' => 'HEMAT10', 'name' => 'Hemat', 'type' => 'PERCENTAGE', 'value' => 10, 'minimum_amount' => 0,
        'starts_at' => '2026-11-23T03:00:00.000Z', 'ends_at' => '2026-12-23T03:00:00.000Z', 'is_active' => true, 'product_ids' => [],
    ])->assertCreated();
    expect($response->json('data.starts_at'))->toStartWith('2026-11-23T03:00:00');
    $this->actingAs($admin)->getJson('/api/v1/admin/vouchers?search=hemat')->assertOk()->assertJsonCount(1, 'data');
});

it('records the target of admin mutations and lists audit logs with admin name and date range', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Rina Admin']);
    $inventory = Inventory::factory()->create(['on_hand' => 5, 'reserved' => 0]);
    $this->actingAs($admin)->patchJson("/api/v1/admin/inventories/{$inventory->id}/adjust", ['delta' => 1, 'reason' => 'Opname'])->assertOk();
    $this->assertDatabaseHas('audit_logs', ['admin_id' => $admin->id, 'action' => 'patch', 'entity_type' => 'inventories', 'entity_id' => $inventory->id]);

    $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs?action=patch')->assertOk()
        ->assertJsonPath('data.0.admin.name', 'Rina Admin');
    $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs?from='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs?to='.now()->toDateString())->assertOk()->assertJsonCount(1, 'data');
});

it('filters customers by email verification and returns their order count', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    User::factory()->create(['role' => UserRole::CUSTOMER, 'email_verified_at' => now()]);
    User::factory()->create(['role' => UserRole::CUSTOMER, 'email_verified_at' => null]);
    $this->actingAs($admin)->getJson('/api/v1/admin/customers?verified=0')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.email_verified_at', null)->assertJsonPath('data.0.orders_count', 0);
    $this->actingAs($admin)->getJson('/api/v1/admin/customers?verified=1')->assertOk()->assertJsonCount(1, 'data');
});

it('includes recent orders and 30 day revenue on the dashboard and the stock threshold on inventory listings', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.revenue.last_30_days', '0.00')->assertJsonPath('data.recent_orders', []);
    $this->actingAs($admin)->getJson('/api/v1/admin/inventories')->assertOk()->assertJsonPath('meta.low_stock_threshold', 5);
});

function adminTestOrder(string $status, DateTimeInterface $expiresAt): Order
{
    return Order::create(['order_number' => 'GS-A-'.fake()->unique()->numerify('######'), 'warehouse_id' => Warehouse::factory()->create()->id,
        'customer_email' => 'buyer@example.test', 'customer_name' => 'Buyer', 'customer_phone' => '08123', 'status' => $status,
        'subtotal' => '100000.00', 'shipping_fee' => '10000.00', 'grand_total' => '110000.00', 'currency' => 'IDR',
        'idempotency_key' => fake()->uuid(), 'payload_hash' => str_repeat('a', 64), 'expires_at' => $expiresAt]);
}

it('counts only overdue unpaid orders and shipments of paid orders on the dashboard', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $overdue = adminTestOrder('PENDING_PAYMENT', now()->subDay());
    $fresh = adminTestOrder('PENDING_PAYMENT', now()->addDay());
    $paid = adminTestOrder('PAID', now()->subDay());
    foreach ([$overdue, $fresh, $paid] as $order) {
        $order->shipment()->create(['provider' => 'test', 'courier' => 'jne', 'service' => 'REG', 'fee' => '10000.00', 'status' => 'PENDING', 'quote_snapshot' => []]);
    }

    $this->actingAs($admin)->getJson('/api/v1/admin/dashboard')->assertOk()
        ->assertJsonPath('data.operations.payments_overdue', 1)
        ->assertJsonPath('data.operations.shipments_pending', 1);
    $this->actingAs($admin)->getJson('/api/v1/admin/orders?status=OVERDUE')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', $overdue->order_number);
    $this->actingAs($admin)->getJson("/api/v1/admin/orders/{$overdue->id}")->assertOk()
        ->assertJsonPath('data.refunds', [])->assertJsonStructure(['data' => ['status_histories']]);
});

it('lets an admin manage public store info with per-field validation and clearable values', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->getJson('/api/v1/store-info')->assertOk()->assertJsonPath('data.email', null)->assertJsonPath('data.version', null);

    $this->actingAs($admin)->putJson('/api/v1/admin/configurations/store_email', ['value' => 'bukan-email'])->assertUnprocessable();
    $this->actingAs($admin)->putJson('/api/v1/admin/configurations/store_return_days', ['value' => 400])->assertUnprocessable();
    $this->actingAs($admin)->putJson('/api/v1/admin/configurations/store_email', ['value' => ' halo@gutshoes.test '])->assertOk();
    $this->actingAs($admin)->putJson('/api/v1/admin/configurations/store_copyright', ['value' => '© {tahun} PT GutShoes'])->assertOk();
    $this->actingAs($admin)->putJson('/api/v1/admin/configurations/store_version', ['value' => 'v1.0.0'])->assertOk();

    $this->getJson('/api/v1/store-info')->assertOk()
        ->assertJsonPath('data.email', 'halo@gutshoes.test')
        ->assertJsonPath('data.copyright', '© {tahun} PT GutShoes')
        ->assertJsonPath('data.version', 'v1.0.0');
    $this->assertDatabaseHas('store_configurations', ['key' => 'store_email', 'is_public' => true]);

    // Mengosongkan kolom menyembunyikannya di toko.
    $this->actingAs($admin)->putJson('/api/v1/admin/configurations/store_email', ['value' => null])->assertOk();
    $this->getJson('/api/v1/store-info')->assertOk()->assertJsonPath('data.email', null);
});

it('keeps non-store configuration values required', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->actingAs($admin)->putJson('/api/v1/admin/configurations/low_stock_threshold', ['value' => null])->assertUnprocessable();
});

it('saves many configuration values atomically in one request', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    // Satu kolom tidak valid: tidak ada yang tersimpan.
    $this->actingAs($admin)->putJson('/api/v1/admin/configurations', ['values' => [
        'store_legal_name' => 'PT Uji', 'store_email' => 'salah',
    ]])->assertUnprocessable()->assertJsonValidationErrors('values.store_email');
    expect(StoreConfiguration::count())->toBe(0);

    $this->actingAs($admin)->putJson('/api/v1/admin/configurations', ['values' => [
        'store_legal_name' => 'PT Uji', 'store_email' => 'cs@uji.test', 'store_version' => null, 'low_stock_threshold' => 4,
    ]])->assertOk();
    $this->getJson('/api/v1/store-info')->assertJsonPath('data.legal_name', 'PT Uji')->assertJsonPath('data.version', null);
    $this->assertDatabaseHas('store_configurations', ['key' => 'low_stock_threshold', 'is_public' => false]);

    $this->actingAs($admin)->putJson('/api/v1/admin/configurations', ['values' => ['bad key!' => 'x']])->assertUnprocessable();
});
