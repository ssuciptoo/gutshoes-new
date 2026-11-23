<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Clerk (format Svix). Menjaga data pelanggan di toko tetap sama dengan Clerk:
 * - user.updated: email utama terverifikasi yang baru ikut diperbarui; nama hanya diisi bila
 *   masih nama bawaan (nama yang diubah pelanggan di profil toko tidak ditimpa).
 * - user.deleted: akun toko dilepas dari identitas Clerk. Riwayat pesanan tetap disimpan dan
 *   pelanggan bisa mendaftar lagi dengan email yang sama.
 */
class ClerkWebhookController extends Controller
{
    private const DEFAULT_NAME = 'Pelanggan GutShoes';

    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->signatureIsValid($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        // Baca isi mentah yang sama dengan yang ditandatangani, tanpa bergantung pada header Content-Type.
        $payload = json_decode($request->getContent(), true);
        $type = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $user = filled($data['id'] ?? null) ? User::where('clerk_user_id', $data['id'])->first() : null;

        if ($user && $type === 'user.updated') {
            $this->syncProfile($user, $data);
        } elseif ($user && $type === 'user.deleted') {
            $user->update(['clerk_user_id' => null]);
        }

        return response()->json(['data' => ['received' => true]]);
    }

    /** @param array<string, mixed> $data */
    private function syncProfile(User $user, array $data): void
    {
        $primaryId = $data['primary_email_address_id'] ?? null;
        foreach ((array) ($data['email_addresses'] ?? []) as $address) {
            if (($address['id'] ?? null) !== $primaryId || ($address['verification']['status'] ?? null) !== 'verified') {
                continue;
            }
            $email = mb_strtolower((string) $address['email_address']);
            if ($email !== $user->email) {
                if (User::withTrashed()->where('email', $email)->whereKeyNot($user->id)->exists()) {
                    Log::warning('Clerk email change skipped: email already used by another account.', ['user_id' => $user->id]);
                } else {
                    $user->email = $email;
                }
            }
        }
        $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
        if ($name !== '' && $user->name === self::DEFAULT_NAME) {
            $user->name = $name;
        }
        $user->save();
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('services.clerk.webhook_secret');
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signatures = (string) $request->header('svix-signature');
        if ($secret === '' || $id === '' || ! ctype_digit($timestamp) || $signatures === '') {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$request->getContent(), $key, true));
        foreach (explode(' ', $signatures) as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
