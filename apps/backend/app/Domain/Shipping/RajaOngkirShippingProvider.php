<?php

namespace App\Domain\Shipping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class RajaOngkirShippingProvider implements ShippingProvider
{
    /** Nama kurir yang ditampilkan ke pembeli. */
    private const COURIERS = [
        'jne' => 'JNE', 'jnt' => 'J&T', 'tiki' => 'TIKI', 'pos' => 'POS', 'sicepat' => 'SiCepat',
        'ninja' => 'Ninja Xpress', 'anteraja' => 'AnterAja', 'lion' => 'Lion Parcel', 'ide' => 'ID Express',
    ];

    /** Nama layanan yang lebih jelas bila nama dari kurir ambigu (mis. tiga layanan "JNE City Courier"). */
    private const SERVICE_NAMES = [
        'jne' => ['CTC' => 'Reguler Dalam Kota', 'CTCYES' => 'YES Dalam Kota', 'CTCSPS' => 'Super Speed Dalam Kota'],
    ];

    /**
     * Layanan yang bukan pengiriman paket biasa untuk sepatu: kirim motor, trucking/kargo,
     * makanan beku, barang berbahaya, dan barang berharga.
     */
    private const EXCLUDED_PATTERN = '/\b(motor|trucking|truck|kargo|cargo|froozy|frozen|dangerous|valuable)\b|^(t\d{2}|trc|jtr.*|gokil|frz)$/i';

    public function quote(array $origin, array $destination, int $weightGrams, int $quantity, ?string $courier = null): array
    {
        $response = Http::baseUrl((string) config('services.rajaongkir.url'))
            ->withHeaders(['key' => (string) config('services.rajaongkir.api_key')])
            ->asForm()->connectTimeout(10)->timeout((int) config('services.rajaongkir.timeout', 30))->retry(2, 500)
            ->post('/calculate/domestic-cost', [
                'origin' => $origin['provider_area_id'],
                'destination' => $destination['provider_area_id'],
                'weight' => max(1, $weightGrams),
                'courier' => $courier ?: config('services.rajaongkir.couriers'),
            ]);

        if ($response->failed() || ! is_array($response->json('data'))) {
            throw new RuntimeException('Shipping provider unavailable.');
        }

        return collect($response->json('data'))
            ->filter(fn ($rate): bool => is_array($rate) && filled($rate['code'] ?? null) && filled($rate['service'] ?? null) && ! $this->excluded($rate))
            ->map(fn (array $rate): ShippingQuote => new ShippingQuote(
                'RAJAONGKIR',
                (string) $rate['code'],
                (string) $rate['service'],
                $this->label($rate),
                number_format((float) ($rate['cost'] ?? 0), 2, '.', ''),
                $this->estimatedDays((string) ($rate['etd'] ?? '')),
            ))
            ->sortBy([fn (ShippingQuote $a, ShippingQuote $b): int => (float) $a->fee <=> (float) $b->fee, fn (ShippingQuote $a, ShippingQuote $b): int => $a->estimatedDays <=> $b->estimatedDays])
            ->values()->all();
    }

    /** @param array<string, mixed> $rate */
    private function excluded(array $rate): bool
    {
        return preg_match(self::EXCLUDED_PATTERN, trim((string) $rate['service'])) === 1
            || preg_match(self::EXCLUDED_PATTERN, trim((string) ($rate['description'] ?? ''))) === 1;
    }

    /**
     * "SiCepat Reguler", "POS Reguler", "JNE YES Dalam Kota". Untuk POS, RajaOngkir menaruh kode
     * di description ("240") dan nama di service ("Pos Reguler"), jadi kode seperti itu diabaikan.
     *
     * @param array<string, mixed> $rate
     */
    private function label(array $rate): string
    {
        $code = strtolower((string) $rate['code']);
        $brand = self::COURIERS[$code] ?? strtoupper($code);
        $service = (string) $rate['service'];
        $name = self::SERVICE_NAMES[$code][$service] ?? null;
        if ($name === null) {
            $description = trim((string) ($rate['description'] ?? ''));
            $looksLikeCode = $description === '' || mb_strlen($description) <= 4 || preg_match('/\d/', $description) === 1;
            $name = $looksLikeCode ? $service : $description;
        }
        // Hindari "POS Pos Reguler" / "JNE JNE ..." / "TIKI Tiki Sirip".
        $name = trim((string) preg_replace('/^'.preg_quote($brand, '/').'\s+/i', '', $name));
        // Rapikan hanya nama yang seluruhnya huruf besar/kecil; singkatan seperti "YES" tetap utuh.
        if ($name === mb_strtoupper($name) || $name === mb_strtolower($name)) {
            $name = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
        }

        return $brand.' '.$name;
    }

    private function estimatedDays(string $etd): int
    {
        preg_match_all('/\d+/', $etd, $matches);

        $days = $matches[0];

        return $days === [] ? 0 : (int) max($days);
    }
}
