<?php

namespace App\Domain\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Menghubungkan pengguna Clerk dengan baris users lokal.
 * Aturan yang sama dengan login Google sebelumnya: hanya email terverifikasi, akun admin dan
 * akun yang dinonaktifkan ditolak, dan pesanan guest dengan email yang sama ditautkan.
 */
class ClerkUserResolver
{
    public function resolve(string $clerkUserId): ?User
    {
        $user = User::where('clerk_user_id', $clerkUserId)->first();
        if ($user) {
            return $user->role === UserRole::CUSTOMER ? $user : null;
        }
        if (User::onlyTrashed()->where('clerk_user_id', $clerkUserId)->exists()) {
            return null;
        }

        $profile = $this->fetchProfile($clerkUserId);
        if (! $profile) {
            return null;
        }

        return DB::transaction(function () use ($clerkUserId, $profile): ?User {
            $user = User::withTrashed()->where('clerk_user_id', $clerkUserId)->lockForUpdate()->first()
                ?? User::withTrashed()->where('email', $profile['email'])->lockForUpdate()->first();
            if ($user && ($user->trashed() || $user->role === UserRole::ADMIN)) {
                return null;
            }
            if ($user && filled($user->clerk_user_id) && $user->clerk_user_id !== $clerkUserId) {
                return null;
            }
            $user ??= new User(['email' => $profile['email']]);
            $user->fill([
                'name' => $user->name ?: ($profile['name'] ?: 'Pelanggan GutShoes'),
                'clerk_user_id' => $clerkUserId,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'role' => UserRole::CUSTOMER,
            ]);
            $user->save();
            app(GuestOrderLinker::class)->link($user);

            return $user;
        });
    }

    /** @return array{email: string, name: string}|null Email utama yang sudah terverifikasi. */
    private function fetchProfile(string $clerkUserId): ?array
    {
        $secret = (string) config('services.clerk.secret_key');
        if ($secret === '') {
            throw new RuntimeException('CLERK_SECRET_KEY belum diatur.');
        }
        $response = Http::withToken($secret)->acceptJson()->timeout(10)
            ->get(rtrim((string) config('services.clerk.api_url'), '/').'/users/'.rawurlencode($clerkUserId));
        if ($response->status() === 404) {
            return null;
        }
        $data = $response->throw()->json();
        $primaryId = $data['primary_email_address_id'] ?? null;
        foreach ((array) ($data['email_addresses'] ?? []) as $address) {
            if (($address['id'] ?? null) === $primaryId && ($address['verification']['status'] ?? null) === 'verified') {
                $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));

                return ['email' => mb_strtolower((string) $address['email_address']), 'name' => $name];
            }
        }

        return null;
    }
}
