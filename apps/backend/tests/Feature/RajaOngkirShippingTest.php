<?php

use App\Domain\Shipping\RajaOngkirShipmentTracker;
use App\Domain\Shipping\RajaOngkirShippingAreaMapper;
use App\Domain\Shipping\RajaOngkirShippingProvider;
use Illuminate\Support\Facades\Http;

it('maps an internal address to exactly one RajaOngkir destination', function () {
    config(['services.rajaongkir.url' => 'https://rajaongkir.test/api/v1', 'services.rajaongkir.api_key' => 'secret']);
    Http::fake(['rajaongkir.test/*' => Http::response(['data' => [[
        'id' => 68513,
        'province_name' => 'Jawa Tengah',
        'city_name' => 'Purbalingga',
        'district_name' => 'Karangmoncol',
        'subdistrict_name' => 'Baleraksa',
        'zip_code' => '53355',
    ]]])]);

    $id = (new RajaOngkirShippingAreaMapper)->map([
        'province' => 'Jawa Tengah', 'city' => 'Purbalingga', 'district' => 'Karangmoncol',
        'village' => 'Baleraksa', 'postal_code' => '53355',
    ], 'internal-code');

    expect($id)->toBe('68513');
    Http::assertSent(fn ($request) => $request->hasHeader('key', 'secret')
        && str_contains($request->url(), '/destination/domestic-destination'));
});

it('rejects ambiguous RajaOngkir destination mapping', function () {
    config(['services.rajaongkir.url' => 'https://rajaongkir.test/api/v1']);
    $candidate = ['id' => 1, 'district_name' => 'Kecamatan', 'zip_code' => '12345'];
    Http::fake(['rajaongkir.test/*' => Http::response(['data' => [$candidate, $candidate + ['id' => 2]]])]);

    expect(fn () => (new RajaOngkirShippingAreaMapper)->map(['district' => 'Kecamatan', 'postal_code' => '12345'], 'internal'))
        ->toThrow(DomainException::class);
});

it('picks the matching village when villages share a district and postal code', function () {
    config(['services.rajaongkir.url' => 'https://rajaongkir.test/api/v1']);
    $base = ['district_name' => 'KEBAYORAN LAMA', 'zip_code' => '12240'];
    Http::fake(['rajaongkir.test/*' => Http::response(['data' => [
        $base + ['id' => 17549, 'subdistrict_name' => 'KEBAYORAN LAMA SELATAN'],
        $base + ['id' => 17550, 'subdistrict_name' => 'KEBAYORAN LAMA UTARA'],
    ]])]);

    $id = (new RajaOngkirShippingAreaMapper)->map([
        'village' => 'Kebayoran Lama Selatan', 'district' => 'Kebayoran Lama', 'city' => 'Kota Administrasi Jakarta Selatan', 'postal_code' => '12240',
    ], 'internal');

    expect($id)->toBe('17549');
});

it('normalizes RajaOngkir rates behind the shipping contract', function () {
    config([
        'services.rajaongkir.url' => 'https://rajaongkir.test/api/v1',
        'services.rajaongkir.api_key' => 'secret',
        'services.rajaongkir.couriers' => 'jne:sicepat',
    ]);
    Http::fake(['rajaongkir.test/*' => Http::response(['data' => [[
        'name' => 'Jalur Nugraha Ekakurir', 'code' => 'jne', 'service' => 'REG',
        'description' => 'Layanan Reguler', 'cost' => 18000, 'etd' => '2-3 day',
    ]]])]);

    $quotes = (new RajaOngkirShippingProvider)->quote(
        ['provider_area_id' => '10'], ['provider_area_id' => '20'], 1800, 2,
    );

    expect($quotes)->toHaveCount(1)
        ->and($quotes[0]->provider)->toBe('RAJAONGKIR')
        ->and($quotes[0]->courier)->toBe('jne')
        ->and($quotes[0]->fee)->toBe('18000.00')
        ->and($quotes[0]->estimatedDays)->toBe(3);
    Http::assertSent(fn ($request) => $request->data()['origin'] === '10'
        && $request->data()['destination'] === '20'
        && $request->data()['weight'] === 1800
        && $request->data()['courier'] === 'jne:sicepat');
});

it('normalizes RajaOngkir AWB tracking without exposing the raw provider payload', function () {
    config(['services.rajaongkir.url' => 'https://rajaongkir.test/api/v1', 'services.rajaongkir.api_key' => 'secret']);
    Http::fake(['rajaongkir.test/*' => Http::response(['data' => [
        'delivered' => false,
        'summary' => ['status' => 'IN TRANSIT', 'waybill_number' => 'AWB-1'],
        'manifest' => [['manifest_description' => 'Paket diterima kurir']],
        'delivery_status' => ['status' => 'ON PROCESS'],
    ]])]);

    $tracking = (new RajaOngkirShipmentTracker)->track('AWB-1', 'jne');

    expect($tracking['status'])->toBe('IN TRANSIT')
        ->and($tracking['history'])->toHaveCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'awb=AWB-1')
        && str_contains($request->url(), 'courier=jne'));
});

it('hides non-parcel services and gives every rate a clear courier and service name', function () {
    config(['services.rajaongkir.url' => 'https://rajaongkir.test/api/v1']);
    $rate = fn (string $code, string $service, string $description, int $cost, string $etd = '2 day') => compact('code', 'service', 'description', 'cost', 'etd');
    Http::fake(['rajaongkir.test/*' => Http::response(['data' => [
        $rate('tiki', 'T15', 'Motor Di Bawah 150cc/1500watt', 250000),
        $rate('tiki', 'TRC', 'Trucking', 35000),
        $rate('tiki', 'FRZ', 'Froozy', 17000),
        $rate('jne', 'JTR<130', 'JNE Trucking', 300000),
        $rate('sicepat', 'GOKIL', 'Kargo', 18300),
        $rate('pos', 'POS KARGO', 'Pjb', 35000),
        $rate('pos', 'PAKETPOS DANGEROUS GOODS', 'Pdg', 16500),
        $rate('pos', 'Pos Reguler', '240', 8000),
        $rate('jne', 'CTCYES', 'JNE City Courier', 18000, '1 day'),
        $rate('tiki', 'SRP', 'Tiki Sirip', 9000),
        $rate('tiki', 'REG', 'Reguler Service', 9000),
        $rate('sicepat', 'REG', 'Reguler', 8000, '1-2 day'),
        $rate('sicepat', 'HALU', 'Ekonomis', 7500, '2-3 day'),
    ]])]);

    $quotes = (new RajaOngkirShippingProvider)->quote(['provider_area_id' => '1'], ['provider_area_id' => '2'], 900, 1);

    expect(array_map(fn ($q) => $q->description, $quotes))->toBe([
        'SiCepat Ekonomis', 'POS Reguler', 'SiCepat Reguler', 'TIKI Sirip', 'TIKI Reguler Service', 'JNE YES Dalam Kota',
    ])->and(array_map(fn ($q) => $q->courier.':'.$q->service, $quotes))->toContain('sicepat:REG', 'tiki:REG');
});
