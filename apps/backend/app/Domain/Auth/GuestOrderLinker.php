<?php

namespace App\Domain\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menautkan pesanan guest ke akun pelanggan dengan email yang sama (tanpa membedakan huruf
 * besar/kecil). Dipanggil saat akun dibuat dan setiap kali sesi pelanggan diperiksa, sehingga
 * pesanan guest yang dibuat setelah akun terdaftar juga ikut tertaut.
 */
class GuestOrderLinker
{
    public function link(User $user): int
    {
        if ($user->role !== UserRole::CUSTOMER || blank($user->email) || $user->email_verified_at === null) {
            return 0;
        }

        return DB::table('orders')->whereNull('user_id')
            ->whereRaw('LOWER(customer_email) = ?', [mb_strtolower($user->email)])
            ->update(['user_id' => $user->id, 'updated_at' => now()]);
    }
}
