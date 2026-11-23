<?php

namespace App\Domain\Store;

use App\Models\StoreConfiguration;
use Illuminate\Support\Facades\Cache;

/**
 * Informasi toko yang dikelola admin (halaman Pengaturan) dan ditampilkan publik di footer,
 * halaman Kontak, dan halaman Kebijakan. Disimpan di store_configurations dengan is_public=true.
 */
class StoreInfo
{
    private const CACHE_KEY = 'store-info:v1';

    /** Kunci yang boleh diisi admin beserta aturan validasinya. Nilai kosong = disembunyikan. */
    public const FIELDS = [
        'store_legal_name' => ['nullable', 'string', 'max:120'],
        'store_email' => ['nullable', 'email', 'max:120'],
        'store_whatsapp' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]{8,20}$/'],
        'store_address' => ['nullable', 'string', 'max:300'],
        'store_hours' => ['nullable', 'string', 'max:120'],
        'store_return_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        'store_policy_updated' => ['nullable', 'date'],
        'store_copyright' => ['nullable', 'string', 'max:200'],
        'store_version' => ['nullable', 'string', 'max:40'],
    ];

    public static function isField(string $key): bool
    {
        return array_key_exists($key, self::FIELDS);
    }

    /** @return array<string, string|int|null> nama tanpa awalan "store_", mis. ['email' => '...'] */
    public function current(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = StoreConfiguration::whereIn('key', array_keys(self::FIELDS))->pluck('value', 'key');
            $info = [];
            foreach (array_keys(self::FIELDS) as $key) {
                $value = $stored[$key] ?? null;
                $info[substr($key, 6)] = $value === '' ? null : $value;
            }

            return $info;
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
