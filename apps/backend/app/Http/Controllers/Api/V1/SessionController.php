<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\GuestOrderLinker;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function adminLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:1024']]);
        $user = User::where('email', $credentials['email'])->where('role', UserRole::ADMIN)->first();
        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are invalid.']]);
        }
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

    /** @return array<string, string|null> */
    private function userData(User $user): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value,
            'created_at' => $user->created_at?->toIso8601String(), 'updated_at' => $user->updated_at?->toIso8601String()];
    }
}
