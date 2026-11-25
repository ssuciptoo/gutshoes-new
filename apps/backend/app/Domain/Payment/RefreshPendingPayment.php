<?php

namespace App\Domain\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Saat pembeli kembali dari Midtrans dan membuka pesanannya, status pembayaran dibaca langsung
 * dari Midtrans agar pesanan yang sudah dibayar tidak tetap tampil "menunggu pembayaran".
 * Webhook tetap jalur utama; ini hanya mempercepat untuk pesanan yang sedang dilihat pembeli
 * (dan menjadi satu-satunya jalur cepat saat webhook tidak bisa menjangkau server, mis. di lokal).
 */
class RefreshPendingPayment
{
    /** Jeda minimum antar-pengecekan per pembayaran, agar membuka ulang halaman tidak menghabiskan kuota. */
    public const COOLDOWN_SECONDS = 15;

    public function __construct(private readonly ReconcileMidtransPayment $reconciler) {}

    public function refresh(Order $order): void
    {
        $payment = $order->payment;
        if ($order->getRawOriginal('status') !== OrderStatus::PENDING_PAYMENT->value
            || ! $order->expires_at || Carbon::parse($order->expires_at)->isPast()
            || ! $payment || $payment->provider !== 'MIDTRANS'
            || $payment->status !== PaymentStatus::PENDING->value || $payment->paid_at
            // Tanpa sesi Snap belum ada yang bisa dibayar, jadi tidak ada yang perlu ditanyakan.
            || ! $payment->snap_token) {
            return;
        }
        if (! Cache::add('payment-refresh:'.$payment->id, true, now()->addSeconds(self::COOLDOWN_SECONDS))) {
            return;
        }

        try {
            $this->reconciler->reconcile($order);
        } catch (Throwable $error) {
            // Gangguan Midtrans tidak boleh membuat halaman pesanan gagal dimuat.
            report($error);
        }
        $order->refresh();
        $order->unsetRelation('payment');
    }
}
