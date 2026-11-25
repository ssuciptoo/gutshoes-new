<?php

namespace App\Domain\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CreatePaymentService
{
    public function __construct(private readonly MidtransProvider $provider) {}

    /**
     * @param  bool  $changeMethod  Tutup sesi Midtrans yang sedang berjalan lalu buat sesi baru,
     *                              agar pembeli bisa memilih metode pembayaran lain.
     * @return array{payment:Payment,redirect_url:string}
     */
    public function create(Order $order, bool $changeMethod = false): array
    {
        // Commit intent before network I/O. A concurrent request cannot create a second token.
        [$payment, $initialize] = $changeMethod ? $this->startNewAttempt($order) : DB::transaction(function () use ($order): array {
            $locked = $this->lockPayableOrder($order);
            $existing = Payment::where('order_id', $locked->id)->where('provider', 'MIDTRANS')->lockForUpdate()->first();
            if ($existing) {
                $this->assertReusable($existing);

                return [$existing, false];
            }

            return [Payment::create(['order_id' => $locked->id, 'provider' => 'MIDTRANS',
                'provider_order_id' => $locked->order_number, 'attempt' => 1,
                'status' => 'PENDING', 'amount' => $locked->grand_total, 'currency' => $locked->currency,
                'expires_at' => $locked->expires_at, 'initialization_state' => 'creating']), true];
        }, 3);
        if ($initialize) {
            try {
                $snap = $this->provider->createSnapTransaction($order->refresh());
                $payment->update(['snap_token' => $snap['token'], 'redirect_url' => $snap['redirect_url'],
                    'initialization_state' => 'ready']);
            } catch (Throwable $error) {
                $payment->update(['initialization_state' => 'uncertain']);
                throw $error;
            }
        }
        $payment->refresh();
        $order->refresh();
        if ($order->getRawOriginal('status') !== 'PENDING_PAYMENT' || Carbon::parse($order->expires_at)->isPast()) {
            throw new DomainException('Pesanan sudah tidak dapat dibayar.');
        }

        return ['payment' => $payment, 'redirect_url' => $payment->redirect_url
            ?: rtrim((string) config('services.midtrans.redirect_base_url'), '/').'/'.$payment->snap_token];
    }

    /**
     * Midtrans mengunci order_id ke metode yang pertama kali dipilih. Status Midtrans dibaca dulu:
     * tanpa charge, token baru dibuat untuk order_id yang sama; charge pending ditutup (cancel
     * terkonfirmasi) dan charge gagal dibiarkan, lalu percobaan baru memakai order_id lain. Kunci order dipegang
     * selama penutupan sehingga webhook untuk percobaan lama menunggu dan lalu dikenali sebagai
     * percobaan yang sudah diganti.
     *
     * @return array{0:Payment,1:bool}
     */
    private function startNewAttempt(Order $order): array
    {
        try {
            return DB::transaction(function () use ($order): array {
                $locked = $this->lockPayableOrder($order);
                $payment = Payment::where('order_id', $locked->id)->where('provider', 'MIDTRANS')->lockForUpdate()->first();
                if (! $payment) {
                    // Belum ada sesi: tidak ada yang perlu ditutup, buat sesi pertama seperti biasa.
                    return [Payment::create(['order_id' => $locked->id, 'provider' => 'MIDTRANS',
                        'provider_order_id' => $locked->order_number, 'attempt' => 1,
                        'status' => 'PENDING', 'amount' => $locked->grand_total, 'currency' => $locked->currency,
                        'expires_at' => $locked->expires_at, 'initialization_state' => 'creating']), true];
                }
                // Percobaan yang gagal (mis. QRIS ditolak) justru alasan utama untuk ganti metode.
                if ($payment->paid_at || ! in_array($payment->status, ['PENDING', 'FAILED'], true)) {
                    throw new DomainException('Pembayaran pesanan ini sudah tidak menunggu.');
                }
                if (! $payment->snap_token) {
                    $this->assertReusable($payment);
                }
                $previousOrderId = $payment->midtransOrderId();
                $status = $this->provider->getStatus($locked);
                if ($status === null) {
                    // Belum ada charge (mis. QRIS gagal sebelum transaksi terbentuk). Token baru untuk
                    // order_id yang sama menggantikan token lama yang belum dipakai; tidak ada yang ditutup.
                    $payment->update(['snap_token' => null, 'redirect_url' => null, 'initialization_state' => 'creating']);

                    return [$payment, true];
                }
                if (($status['order_id'] ?? null) !== $previousOrderId) {
                    throw new RuntimeException('Provider returned another order.');
                }
                $internal = app(MidtransStatusMapper::class)->internalStatus($status);
                if ($internal === PaymentStatus::SUCCESS) {
                    // Sudah dibayar: catat lewat jalur yang sama dengan webhook, jangan buat sesi baru.
                    app(ApplyMidtransStatus::class)->apply($locked, $payment, $status);

                    return [$payment->refresh(), false];
                }
                if ($internal === PaymentStatus::PENDING) {
                    $closer = new ClosePendingPayment($this->provider, app(ConfirmPayment::class), app(MidtransStatusMapper::class));
                    if (! $closer->close($locked, 'cancel')) {
                        // Pembayaran ternyata masuk saat sesi ditutup dan sudah direkonsiliasi. Jangan lempar
                        // exception di sini: rollback akan membatalkan pencatatan pembayaran itu.
                        return [$payment->refresh(), false];
                    }
                    $payment->refresh();
                } else {
                    // deny/failure/cancel/expire: charge lama sudah tidak bisa dibayar, tidak perlu ditutup.
                    $payment->update([
                        'provider_status' => mb_substr((string) $status['transaction_status'], 0, 32),
                        'provider_transaction_id' => $status['transaction_id'] ?? $payment->provider_transaction_id,
                    ]);
                }
                $payment->attempts()->create([
                    'provider_order_id' => $previousOrderId,
                    'provider_transaction_id' => $payment->provider_transaction_id,
                    'provider_status' => $payment->provider_status,
                    'superseded_at' => now(),
                ]);
                $attempt = $payment->attempt + 1;
                $payment->update([
                    'provider_order_id' => $locked->order_number.'-'.$attempt,
                    'attempt' => $attempt,
                    'status' => 'PENDING',
                    'provider_status' => null,
                    'provider_transaction_id' => null,
                    'snap_token' => null,
                    'redirect_url' => null,
                    'initialization_state' => 'creating',
                ]);

                return [$payment, true];
            }, 1);
        } catch (DomainException|ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['payment' => ['Metode pembayaran belum dapat diganti. Muat ulang status pesanan lalu coba lagi.']]);
        }
    }

    private function lockPayableOrder(Order $order): Order
    {
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
        if ($locked->getRawOriginal('status') !== 'PENDING_PAYMENT' || Carbon::parse($locked->expires_at)->isPast()) {
            throw new DomainException('Pesanan sudah tidak dapat dibayar.');
        }

        return $locked;
    }

    private function assertReusable(Payment $payment): void
    {
        if ($payment->paid_at || $payment->status !== 'PENDING') {
            throw new DomainException('Pembayaran pesanan ini sudah tidak menunggu.');
        }
        if (! $payment->snap_token) {
            throw ValidationException::withMessages(['payment' => ['Status pembayaran masih diperiksa. Jangan membuat pesanan baru; periksa kembali status pesanan atau hubungi dukungan.']]);
        }
    }
}
