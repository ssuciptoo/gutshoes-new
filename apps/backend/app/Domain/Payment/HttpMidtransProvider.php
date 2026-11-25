<?php

namespace App\Domain\Payment;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class HttpMidtransProvider implements MidtransProvider
{
    public function createSnapTransaction(Order $order): array
    {
        $response = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->timeout(10)->post(config('services.midtrans.snap_url'), [
                'transaction_details' => ['order_id' => $order->midtransOrderId(), 'gross_amount' => (int) $order->grand_total],
                'customer_details' => ['first_name' => $order->customer_name, 'email' => $order->customer_email, 'phone' => $order->customer_phone],
                'expiry' => ['start_time' => Carbon::parse($order->expires_at)->subHours(24)->format('Y-m-d H:i:s O'), 'unit' => 'hours', 'duration' => 24],
                // Setelah bayar/menutup halaman Midtrans, pembeli kembali ke pesanannya di toko.
                'callbacks' => ['finish' => $this->finishUrl($order)],
            ])->throw()->json();
        if (! is_array($response) || ! isset($response['token'], $response['redirect_url'])) {
            throw new RuntimeException('Invalid Midtrans Snap response.');
        }

        return ['token' => (string) $response['token'], 'redirect_url' => (string) $response['redirect_url'], 'transaction_id' => null];
    }

    /**
     * Pelanggan berakun kembali ke detail pesanan; guest ke "Pesanan saya" di perangkatnya.
     * Token akses guest sengaja tidak dimasukkan ke URL agar tidak terkirim ke pihak ketiga.
     */
    private function finishUrl(Order $order): string
    {
        $base = rtrim((string) config('services.storefront.url'), '/').'/';

        return $order->user_id
            ? $base.'orders/'.rawurlencode($order->order_number)
            : $base.'guest-orders';
    }

    public function closeTransaction(Order $order, string $action): array
    {
        if (! in_array($action, ['cancel', 'expire'], true)) {
            throw new RuntimeException('Invalid payment closure action.');
        }
        $orderId = $order->midtransOrderId();
        $url = rtrim((string) config('services.midtrans.api_url'), '/').'/'.rawurlencode($orderId);
        $client = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')->acceptJson()->timeout(10);
        $response = $client->get($url.'/status');
        if ($response->status() === 404 || (string) $response->json('status_code') === '404') {
            $payment = $order->payment;
            $token = $payment?->snap_token;
            if (! $payment || ! $token) {
                throw new RuntimeException('Payment initialization is uncertain; keep the reservation for reconciliation.');
            }
            // Snap API memakai Basic auth (server key + ":"), sama seperti saat membuat transaksi.
            $snap = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
                ->acceptJson()->timeout(10)->post(rtrim((string) config('services.midtrans.snap_url'), '/').'/'.rawurlencode($token).'/cancel');
            $messages = (array) $snap->json('error_messages');
            $alreadyClosed = in_array('token already canceled', $messages, true);
            // Sesi Snap yang sudah lewat masa berlakunya tidak bisa dibatalkan, tetapi juga tidak bisa
            // dipakai membayar lagi. Hanya diterima bila batas bayar kita sendiri memang sudah lewat.
            $sessionExpired = in_array('expiry must be greater than the current time', $messages, true)
                && $payment->expires_at->isPast();
            if (! ($snap->successful() && $snap->json('canceled_at')) && ! $alreadyClosed && ! $sessionExpired) {
                throw new RuntimeException('Snap session closure is not confirmed.');
            }
            // A charge may have started while the Snap session was being closed.
            $response = $client->get($url.'/status');
            if ($response->status() === 404 || (string) $response->json('status_code') === '404') {
                return ['transaction_status' => 'cancel', 'order_id' => $orderId];
            }
        }
        $response->throw();
        if ($response->json('transaction_status') === 'pending') {
            // Re-read even after a rejected operation: payment may have won the race.
            $closed = $client->post($url.'/'.$action);
            $response = $client->get($url.'/status')->throw();
            // Midtrans menolak cancel untuk charge yang masih pending pada QRIS, GoPay, dan transfer
            // bank (VA); charge seperti itu hanya bisa ditutup dengan expire. Hasil akhirnya sama:
            // charge lama tidak bisa dibayar lagi, sehingga pembeli dapat memilih metode lain.
            if ($action === 'cancel' && $response->json('transaction_status') === 'pending') {
                Log::info('Midtrans cancel left the charge pending; expiring it instead.', [
                    'order_id' => $orderId,
                    'payment_type' => $response->json('payment_type'),
                    'cancel_status' => $closed->status(),
                    'cancel_status_code' => $closed->json('status_code'),
                ]);
                $client->post($url.'/expire');
                $response = $client->get($url.'/status')->throw();
            }
        }
        $payload = $response->json();
        if (! is_array($payload) || ! in_array($payload['transaction_status'] ?? '', ['cancel', 'expire', 'settlement', 'capture'], true)
            || ($payload['transaction_status'] === 'capture' && ($payload['fraud_status'] ?? '') !== 'accept')) {
            throw new RuntimeException('Provider payment state is not final; retry reconciliation.');
        }

        return $payload;
    }

    public function getStatus(Order $order): ?array
    {
        $response = Http::withBasicAuth((string) config('services.midtrans.server_key'), '')
            ->acceptJson()
            ->timeout(10)
            ->get(rtrim((string) config('services.midtrans.api_url'), '/').'/'.rawurlencode($order->midtransOrderId()).'/status');

        if ($response->status() === 404 || (string) $response->json('status_code') === '404') {
            return null;
        }

        $response->throw();
        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['transaction_status'])) {
            throw new RuntimeException('Invalid Midtrans status response.');
        }

        return $payload;
    }

    public function verifyWebhook(array $payload): bool
    {
        if (blank(config('services.midtrans.server_key'))) {
            return false;
        }
        $expected = hash('sha512', ($payload['order_id'] ?? '').($payload['status_code'] ?? '').($payload['gross_amount'] ?? '').config('services.midtrans.server_key'));

        return isset($payload['signature_key']) && is_string($payload['signature_key']) && hash_equals($expected, $payload['signature_key']);
    }
}
