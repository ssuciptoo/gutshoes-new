<?php

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('rejects unauthenticated profile access with API envelope', function () {
    $this->getJson('/api/v1/profile')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('authenticates an administrator and rotates the session', function () {
    $admin = User::factory()->admin()->create(['password' => Hash::make('Strong!Password123')]);
    $this->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'Strong!Password123'])
        ->assertOk()->assertJsonPath('data.role', UserRole::ADMIN->value);
    $this->assertAuthenticatedAs($admin);
});

it('prevents customers from reading another customer address', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();
    $address = Address::create(['user_id' => $owner->id, 'label' => 'Rumah', 'recipient_name' => 'Owner', 'phone' => '08123456789',
        'address_line' => 'Jalan Pengujian 1', 'province' => 'DKI Jakarta', 'city' => 'Jakarta', 'district' => 'Setiabudi', 'postal_code' => '12910']);
    $this->actingAs($attacker)->getJson("/api/v1/addresses/{$address->id}")->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
});

it('creates the first admin non-interactively without exposing credentials', function () {
    $this->artisan('gutshoes:create-admin', ['--email' => 'admin@example.test', '--name' => 'Admin Development', '--password' => 'Strong!Password123'])->assertSuccessful();
    $admin = User::where('email', 'admin@example.test')->firstOrFail();
    expect($admin->role)->toBe(UserRole::ADMIN)->and(Hash::check('Strong!Password123', $admin->password))->toBeTrue();
});

it('logs out and invalidates the authenticated session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.logged_out', true);
});

// PHP di Windows sering tidak punya openssl.cnf; sediakan konfigurasi minimal untuk membuat kunci uji.
function rsaKeyOptions(): array
{
    static $config = null;
    if ($config === null) {
        $config = tempnam(sys_get_temp_dir(), 'ossl');
        file_put_contents($config, "[req]
distinguished_name=dn
[dn]
");
    }

    return ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config];
}

function clerkKeys(): array
{
    static $keys = null;
    if ($keys === null) {
        $resource = openssl_pkey_new(rsaKeyOptions());
        openssl_pkey_export($resource, $private, null, rsaKeyOptions());
        $keys = ['private' => $private, 'public' => openssl_pkey_get_details($resource)['key']];
    }

    return $keys;
}

function configureClerk(): void
{
    config(['services.clerk' => [
        'secret_key' => 'sk_test_fake', 'frontend_api_url' => 'https://clerk.example.test', 'jwt_key' => clerkKeys()['public'],
        'api_url' => 'https://api.clerk.test/v1', 'authorized_parties' => ['http://localhost:5173'],
    ]]);
}

function clerkToken(string $sub = 'user_clerk_1', array $overrides = []): string
{
    return Firebase\JWT\JWT::encode(array_merge([
        'sub' => $sub, 'iss' => 'https://clerk.example.test', 'azp' => 'http://localhost:5173',
        'iat' => time(), 'nbf' => time() - 5, 'exp' => time() + 60, 'sid' => 'sess_1',
    ], $overrides), clerkKeys()['private'], 'RS256');
}

function fakeClerkUser(string $id, string $email, string $status = 'verified'): void
{
    Http::fake(['api.clerk.test/v1/users/*' => Http::response([
        'id' => $id, 'first_name' => 'Clerk', 'last_name' => 'Buyer', 'primary_email_address_id' => 'idn_1',
        'email_addresses' => [['id' => 'idn_1', 'email_address' => $email, 'verification' => ['status' => $status]]],
    ])]);
}

it('signs in a new customer with a Clerk session token', function () {
    configureClerk();
    fakeClerkUser('user_clerk_1', 'Buyer@Example.test');

    $this->withToken(clerkToken())->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.email', 'buyer@example.test')->assertJsonPath('data.role', 'CUSTOMER');

    $user = User::where('clerk_user_id', 'user_clerk_1')->firstOrFail();
    expect($user->email)->toBe('buyer@example.test')->and($user->email_verified_at)->not->toBeNull();
    // Request berikutnya memakai tautan lokal tanpa memanggil API Clerk lagi.
    $this->withToken(clerkToken())->getJson('/api/v1/profile')->assertOk();
    Http::assertSentCount(1);
});

it('links a Clerk login to an existing customer by email once', function () {
    configureClerk();
    $customer = User::factory()->create(['email' => 'link@example.test', 'role' => UserRole::CUSTOMER]);
    fakeClerkUser('user_link', 'link@example.test');

    $this->withToken(clerkToken('user_link'))->getJson('/api/v1/auth/me')->assertOk();

    expect($customer->refresh()->clerk_user_id)->toBe('user_link')->and(User::count())->toBe(1);
});

it('rejects Clerk logins for deleted customers, admin emails and unverified emails', function () {
    configureClerk();
    $blocked = User::factory()->create(['email' => 'blocked@example.test', 'role' => UserRole::CUSTOMER]);
    $blocked->delete();
    User::factory()->admin()->create(['email' => 'admin@example.test']);

    fakeClerkUser('user_blocked', 'blocked@example.test');
    $this->withToken(clerkToken('user_blocked'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    fakeClerkUser('user_admin', 'admin@example.test');
    $this->withToken(clerkToken('user_admin'))->getJson('/api/v1/auth/me')->assertUnauthorized();
    fakeClerkUser('user_unverified', 'new@example.test', 'unverified');
    $this->withToken(clerkToken('user_unverified'))->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect($blocked->refresh()->trashed())->toBeTrue()->and(User::where('clerk_user_id', '!=', null)->count())->toBe(0);
});

it('rejects Clerk tokens that are expired, from another origin, another issuer or badly signed', function () {
    configureClerk();
    fakeClerkUser('user_clerk_1', 'buyer@example.test');

    $this->withToken(clerkToken('user_clerk_1', ['exp' => time() - 10]))->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken(clerkToken('user_clerk_1', ['azp' => 'https://evil.example']))->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->withToken(clerkToken('user_clerk_1', ['iss' => 'https://other.clerk.test']))->getJson('/api/v1/auth/me')->assertUnauthorized();
    $other = openssl_pkey_new(rsaKeyOptions());
    openssl_pkey_export($other, $otherPrivate, null, rsaKeyOptions());
    $forged = Firebase\JWT\JWT::encode(['sub' => 'user_clerk_1', 'iss' => 'https://clerk.example.test', 'exp' => time() + 60], $otherPrivate, 'RS256');
    $this->withToken($forged)->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect(User::count())->toBe(0);
});

it('never lets a Clerk token act on admin endpoints', function () {
    configureClerk();
    User::factory()->create(['email' => 'buyer@example.test', 'role' => UserRole::CUSTOMER, 'clerk_user_id' => 'user_clerk_1']);

    $this->withToken(clerkToken())->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
});

function guestOrderFor(string $email): App\Models\Order
{
    return App\Models\Order::create(['order_number' => 'GS-G-'.fake()->unique()->numerify('######'), 'warehouse_id' => App\Models\Warehouse::factory()->create()->id,
        'customer_email' => $email, 'customer_name' => 'Buyer', 'customer_phone' => '08123', 'status' => 'PENDING_PAYMENT',
        'subtotal' => '100000.00', 'shipping_fee' => '10000.00', 'grand_total' => '110000.00', 'currency' => 'IDR',
        'idempotency_key' => fake()->uuid(), 'payload_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);
}

it('links guest orders created after the account exists when the customer signs in again', function () {
    configureClerk();
    $customer = User::factory()->create(['email' => 'buyer@example.test', 'role' => UserRole::CUSTOMER, 'clerk_user_id' => 'user_clerk_1']);
    $later = guestOrderFor('Buyer@Example.TEST');
    $other = guestOrderFor('someone@example.test');

    $this->withToken(clerkToken())->getJson('/api/v1/auth/me')->assertOk()->assertJsonStructure(['data' => ['created_at', 'updated_at']]);

    expect($later->refresh()->user_id)->toBe($customer->id)->and($other->refresh()->user_id)->toBeNull();
});

function clerkWebhook(array $payload, ?string $secretKey = null, ?int $timestamp = null): array
{
    $body = json_encode($payload);
    $id = 'msg_'.fake()->uuid();
    $timestamp ??= time();
    $key = $secretKey ?? random_bytes(24);
    config(['services.clerk.webhook_secret' => 'whsec_'.base64_encode($key)]);
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

    return [$body, ['svix-id' => $id, 'svix-timestamp' => (string) $timestamp, 'svix-signature' => "v1,{$signature}", 'Content-Type' => 'application/json']];
}

it('syncs a verified email change and fills a default name from a signed Clerk webhook', function () {
    $customer = User::factory()->create(['email' => 'old@example.test', 'name' => 'Pelanggan GutShoes', 'role' => UserRole::CUSTOMER, 'clerk_user_id' => 'user_clerk_1']);
    [$body, $headers] = clerkWebhook(['type' => 'user.updated', 'data' => [
        'id' => 'user_clerk_1', 'first_name' => 'Rina', 'last_name' => 'Putri', 'primary_email_address_id' => 'idn_2',
        'email_addresses' => [['id' => 'idn_2', 'email_address' => 'New@Example.test', 'verification' => ['status' => 'verified']]],
    ]]);

    $this->call('POST', '/api/v1/webhooks/clerk', [], [], [], collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all(), $body)->assertOk();

    expect($customer->refresh()->email)->toBe('new@example.test')->and($customer->name)->toBe('Rina Putri');
});

it('unlinks the Clerk identity on user.deleted and keeps the customer record', function () {
    $customer = User::factory()->create(['role' => UserRole::CUSTOMER, 'clerk_user_id' => 'user_gone']);
    [$body, $headers] = clerkWebhook(['type' => 'user.deleted', 'data' => ['id' => 'user_gone', 'deleted' => true]]);

    $this->call('POST', '/api/v1/webhooks/clerk', [], [], [], collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all(), $body)->assertOk();

    expect($customer->refresh()->clerk_user_id)->toBeNull()->and($customer->trashed())->toBeFalse();
});

it('rejects Clerk webhooks with a bad signature or an old timestamp', function () {
    $customer = User::factory()->create(['role' => UserRole::CUSTOMER, 'clerk_user_id' => 'user_keep']);
    $payload = ['type' => 'user.deleted', 'data' => ['id' => 'user_keep']];
    $server = fn (array $headers) => collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();

    [$body, $headers] = clerkWebhook($payload);
    $headers['svix-signature'] = 'v1,'.base64_encode(random_bytes(32));
    $this->call('POST', '/api/v1/webhooks/clerk', [], [], [], $server($headers), $body)->assertUnauthorized();

    [$body, $headers] = clerkWebhook($payload, null, time() - 3600);
    $this->call('POST', '/api/v1/webhooks/clerk', [], [], [], $server($headers), $body)->assertUnauthorized();

    expect($customer->refresh()->clerk_user_id)->toBe('user_keep');
});

it('saves a customer address without calling the shipping provider', function () {
    config(['gutshoes.shipping_driver' => 'rajaongkir']);
    Http::fake(fn () => throw new RuntimeException('Shipping provider must not be called when saving an address.'));
    DB::table('region_provinces')->insert(['code' => '31', 'name' => 'DKI Jakarta']);
    DB::table('region_regencies')->insert(['code' => '31.74', 'province_code' => '31', 'name' => 'Kota Jakarta Selatan']);
    DB::table('region_districts')->insert(['code' => '31.74.03', 'regency_code' => '31.74', 'name' => 'Kebayoran Lama']);
    DB::table('region_villages')->insert(['code' => '31.74.03.1001', 'district_code' => '31.74.03', 'name' => 'Kebayoran Lama Selatan', 'postal_code' => '12240']);

    $this->actingAs(User::factory()->create())->postJson('/api/v1/addresses', [
        'label' => 'Rumah', 'recipient_name' => 'Buyer', 'phone' => '08123456789', 'address_line' => 'Jalan Pengujian 1',
        'postal_code' => '12240', 'province_code' => '31', 'regency_code' => '31.74', 'district_code' => '31.74.03', 'village_code' => '31.74.03.1001',
    ])->assertCreated()->assertJsonPath('data.city', 'Kota Jakarta Selatan')->assertJsonPath('data.provider_area_id', null);

    Http::assertNothingSent();
});
