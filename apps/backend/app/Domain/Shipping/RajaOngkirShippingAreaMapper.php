<?php

namespace App\Domain\Shipping;

use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RajaOngkirShippingAreaMapper implements ShippingAreaMapper
{
    public function map(array $address, string $fallback): string
    {
        $query = implode(', ', array_filter([$address['village'] ?? null, $address['district'] ?? null, $address['city'] ?? null, $address['postal_code'] ?? null]));

        return Cache::remember('rajaongkir-area:'.sha1($query), now()->addDays(30), function () use ($query, $address): string {
            $response = Http::baseUrl((string) config('services.rajaongkir.url'))
                ->withHeaders(['key' => (string) config('services.rajaongkir.api_key')])
                ->timeout(10)->retry(2, 200)
                ->get('/destination/domestic-destination', ['search' => $query, 'limit' => 20, 'offset' => 0]);

            if ($response->failed() || ! is_array($response->json('data'))) {
                throw new DomainException('Area pengiriman tidak dapat dipetakan. Coba lagi atau hubungi admin.');
            }

            $same = fn (mixed $a, mixed $b): bool => strcasecmp(trim((string) $a), trim((string) $b)) === 0;
            $matches = collect($response->json('data'))->filter(fn ($candidate): bool => is_array($candidate)
                && (string) ($candidate['zip_code'] ?? '') === (string) ($address['postal_code'] ?? '')
                && $same($candidate['district_name'] ?? '', $address['district'] ?? ''));
            // Beberapa kelurahan bisa berbagi kecamatan dan kode pos (mis. Kebayoran Lama Selatan & Utara,
            // 12240). Pilih yang nama kelurahannya cocok; bila tidak ada, kembali ke kecocokan kecamatan + kode pos.
            if ($matches->count() > 1 && filled($address['village'] ?? null)) {
                $byVillage = $matches->filter(fn (array $candidate): bool => $same($candidate['subdistrict_name'] ?? '', $address['village']));
                if ($byVillage->count() === 1) {
                    $matches = $byVillage;
                }
            }
            if ($matches->count() !== 1 || ! filled($matches->first()['id'] ?? null)) {
                throw new DomainException('Area pengiriman RajaOngkir tidak ditemukan secara unik. Periksa kecamatan dan kode pos.');
            }

            return (string) $matches->first()['id'];
        });
    }
}
