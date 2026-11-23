<?php

namespace App\Domain\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Memverifikasi session token Clerk (RS256) sesuai panduan "Manual JWT verification":
 * tanda tangan, exp/nbf, iss (Frontend API), azp (origin yang diizinkan), dan sts.
 */
class ClerkTokenVerifier
{
    private const JWKS_CACHE_KEY = 'clerk:jwks';

    /** Mengembalikan ID pengguna Clerk (klaim "sub") atau null bila token tidak sah. */
    public function verify(string $token): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $claims = JWT::decode($token, $this->keys());
        } catch (Throwable) {
            // Kunci bisa berganti (rotasi): muat ulang JWKS sekali lalu coba lagi.
            Cache::forget(self::JWKS_CACHE_KEY);
            try {
                $claims = JWT::decode($token, $this->keys());
            } catch (Throwable) {
                return null;
            }
        }

        $issuer = rtrim((string) config('services.clerk.frontend_api_url'), '/');
        if (rtrim((string) ($claims->iss ?? ''), '/') !== $issuer) {
            return null;
        }
        $parties = (array) config('services.clerk.authorized_parties');
        if (isset($claims->azp) && ! in_array($claims->azp, $parties, true)) {
            return null;
        }
        if (($claims->sts ?? null) === 'pending') {
            return null;
        }
        $subject = (string) ($claims->sub ?? '');

        return $subject !== '' ? $subject : null;
    }

    public function configured(): bool
    {
        return filled(config('services.clerk.frontend_api_url'))
            && (filled(config('services.clerk.jwt_key')) || filled(config('services.clerk.secret_key')));
    }

    /** @return array<string, Key>|Key */
    private function keys(): array|Key
    {
        $pem = (string) config('services.clerk.jwt_key');
        if ($pem !== '') {
            return new Key(str_replace('\n', "\n", $pem), 'RS256');
        }

        $jwks = Cache::remember(self::JWKS_CACHE_KEY, now()->addHour(), function (): array {
            $url = rtrim((string) config('services.clerk.frontend_api_url'), '/').'/.well-known/jwks.json';

            return Http::acceptJson()->timeout(10)->get($url)->throw()->json();
        });

        return JWK::parseKeySet($jwks, 'RS256');
    }
}
