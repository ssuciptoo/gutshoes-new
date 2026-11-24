<?php

namespace App\Jobs;

use App\Domain\Order\GuestOrderAccess;
use App\Models\EmailDelivery;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $orderId, public readonly string $template)
    {
        $this->afterCommit();
    }

    public function handle(?GuestOrderAccess $guestAccess = null): void
    {
        $guestAccess ??= app(GuestOrderAccess::class);
        $order = Order::with(['items', 'payment'])->findOrFail($this->orderId);
        $delivery = EmailDelivery::firstOrCreate(
            ['order_id' => $order->id, 'recipient' => $order->customer_email, 'template' => $this->template],
            ['status' => 'QUEUED']
        );
        if ($delivery->status === 'SENT') {
            return;
        }

        $delivery->increment('attempts');
        // Versi HTML + teks biasa: email tanpa versi teks lebih mudah dianggap spam.
        Mail::send(['html' => 'emails.order-status', 'text' => 'emails.order-status-text'], [
            'order' => $order,
            'content' => $this->content($order, $guestAccess),
        ], fn ($mail) => $mail->to($order->customer_email)->subject($this->subject($order)));

        $delivery->update(['status' => 'SENT', 'sent_at' => now(), 'last_error' => null]);
    }

    public function failed(Throwable $exception): void
    {
        EmailDelivery::where('order_id', $this->orderId)
            ->where('template', $this->template)
            ->update(['status' => 'FAILED', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);
    }

    private function subject(Order $order): string
    {
        return match ($this->template) {
            'order_created' => "Pesanan {$order->order_number} menunggu pembayaran",
            'payment_success' => "Pembayaran {$order->order_number} berhasil",
            'payment_expired' => "Pembayaran {$order->order_number} kedaluwarsa",
            'order_cancelled' => "Pesanan {$order->order_number} dibatalkan",
            default => "Pembaruan pesanan {$order->order_number}",
        };
    }

    /**
     * Isi email per jenis. Pelanggan berakun diarahkan ke detail pesanan di akunnya; guest memakai
     * tautan pemulihan rahasia (berisi token akses pesanan).
     *
     * @return array{headline: string, intro: string, deadline: ?string, cta_label: ?string, cta_url: ?string, private_link: bool}
     */
    private function content(Order $order, GuestOrderAccess $guestAccess): array
    {
        $store = rtrim((string) config('services.storefront.url'), '/').'/';
        $accountUrl = $order->user_id ? $store.'orders/'.rawurlencode($order->order_number) : null;
        $recoveryUrl = $order->user_id ? null : $guestAccess->recoveryUrl($order);
        $orderUrl = $accountUrl ?? $recoveryUrl;
        $deadline = $order->expires_at ? $order->expires_at->timezone('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H.i').' WIB' : null;

        return match ($this->template) {
            'order_created' => ['headline' => 'Pesananmu sudah kami terima',
                'intro' => 'Terima kasih sudah berbelanja di GutShoes. Selesaikan pembayaran agar pesananmu segera kami proses.',
                'deadline' => $deadline, 'cta_label' => 'Lihat pesanan & bayar', 'cta_url' => $orderUrl, 'private_link' => $recoveryUrl !== null],
            'payment_success' => ['headline' => 'Pembayaran berhasil',
                'intro' => 'Pembayaranmu sudah kami terima. Pesanan sedang kami siapkan dan nomor resi akan dikirim setelah paket diserahkan ke kurir.',
                'deadline' => null, 'cta_label' => 'Lihat pesanan', 'cta_url' => $orderUrl, 'private_link' => $recoveryUrl !== null],
            'payment_expired' => ['headline' => 'Batas waktu pembayaran habis',
                'intro' => 'Pembayaran tidak kami terima sampai batas waktu, jadi pesanan ini dibatalkan otomatis. Kamu bisa membuat pesanan baru kapan saja.',
                'deadline' => null, 'cta_label' => 'Belanja lagi', 'cta_url' => $store, 'private_link' => false],
            'order_cancelled' => ['headline' => 'Pesanan dibatalkan',
                'intro' => 'Pesananmu sudah dibatalkan. Bila pesanan sudah dibayar, dana akan dikembalikan sesuai metode pembayaran.',
                'deadline' => null, 'cta_label' => 'Lihat pesanan', 'cta_url' => $orderUrl, 'private_link' => $recoveryUrl !== null],
            default => ['headline' => 'Ada pembaruan untuk pesananmu', 'intro' => 'Lihat detail pesanan untuk status terbaru.',
                'deadline' => null, 'cta_label' => 'Lihat pesanan', 'cta_url' => $orderUrl, 'private_link' => $recoveryUrl !== null],
        };
    }
}
