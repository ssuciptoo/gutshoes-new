<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\GuestOrderLinker;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    /** Percobaan gagal per email sebelum dikunci, dan lama kuncinya (detik). */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    public function adminLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:1024']]);

        // Dikunci per email (bukan hanya per IP) agar tebakan dari banyak IP tetap berhenti.
        // Email yang tidak terdaftar ikut dikunci supaya respons tidak membedakan akun yang ada.
        $key = 'admin-login:'.hash('sha256', mb_strtolower($credentials['email']));
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ThrottleRequestsException('Too many login attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        $user = User::where('email', $credentials['email'])->where('role', UserRole::ADMIN)->first();
        // Hash::check selalu dijalankan (dengan hash tiruan bila akun tidak ada) agar waktu respons
        // tidak membocorkan email mana yang terdaftar sebagai admin.
        $valid = Hash::check($credentials['password'], $user?->password ?: $this->dummyHash());
        if (! $user || ! $user->password || ! $valid) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }
        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['data' => $this->userData($user)]);
    }

    public function me(Request $request, GuestOrderLinker $orders): JsonResponse
    {
        // Pesanan guest yang dibuat setelah akun terdaftar ikut tertaut saat pelanggan masuk lagi.
        $orders->link($request->user());

        return response()->json(['data' => $this->userData($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['logged_out' => true]]);
    }

    /** Hash dengan biaya yang sama seperti hash asli, dibuat sekali per proses. */
    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }

    /** @return array<string, string|null> */
    private function userData(User $user): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value,
            'created_at' => $user->created_at?->toIso8601String(), 'updated_at' => $user->updated_at?->toIso8601String()];
    }
}
