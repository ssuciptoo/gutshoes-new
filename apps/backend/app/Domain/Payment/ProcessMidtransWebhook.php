<?php

namespace App\Domain\Payment;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentWebhook;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMidtransWebhook
{
    public function __construct(
        private readonly MidtransProvider $provider,
        private readonly ApplyMidtransStatus $statuses,
        private readonly MidtransStatusMapper $mapper,
    ) {}

    /** @param array<string,mixed> $payload */
    public function handle(array $payload): PaymentWebhook
    {
        if (! $this->provider->verifyWebhook($payload)) {
            throw new InvalidWebhookSignature;
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $eventId = hash('sha256', implode('|', [$payload['order_id'] ?? '', $payload['transaction_id'] ?? '', $payload['transaction_status'] ?? '', $payload['fraud_status'] ?? '', $payload['status_code'] ?? '', $payload['settlement_time'] ?? $payload['transaction_time'] ?? '']));
        $event = PaymentWebhook::firstOrCreate(['event_id' => $eventId], [
            'provider' => 'MIDTRANS',
            'transaction_id' => $payload['transaction_id'] ?? null,
            'payload_hash' => $hash,
            'payload_redacted' => $this->redact($payload),
        ]);
        if ($event->processed_at !== null) {
            return $event;
        }

        try {
            return DB::transaction(function () use ($payload, $event): PaymentWebhook {
                $event = PaymentWebhook::whereKey($event->id)->lockForUpdate()->firstOrFail();
                if ($event->processed_at !== null) {
                    return $event;
                }
                $providerOrderId = (string) $payload['order_id'];
                $order = Order::whereKey($this->orderIdFor($providerOrderId))->lockForUpdate()->first();
                if (! $order) {
                    $event->update(['processing_error' => 'Order not found', 'processed_at' => now()]);

                    return $event->refresh();
                }
                $payment = Payment::where('order_id', $order->id)->where('provider', 'MIDTRANS')->lockForUpdate()->first();
                if (! $payment) {
                    throw new DomainException('Payment record not found.');
                }
                if ($payment->midtransOrderId() !== $providerOrderId) {
                    return $this->recordSupersededAttempt($event, $payment, $payload);
                }
                $this->statuses->apply($order, $payment, $payload);
                $event->update(['processing_error' => null, 'processed_at' => now()]);

                return $event->refresh();
            }, 3);
        } catch (Throwable $error) {
            // Audit is intentionally committed before business processing so failed
            // amount/order/transaction validation remains observable. Only a business
            // rejection is final; infrastructure errors (deadlock, lock timeout) stay
            // unprocessed so the Midtrans retry of the same event is applied.
            $event->update([
                'processing_error' => mb_substr(class_basename($error).': '.$error->getMessage(), 0, 255),
                'processed_at' => $error instanceof DomainException ? now() : null,
            ]);

            throw $error;
        }
    }

    /** Percobaan aktif, percobaan yang sudah diganti, lalu nomor pesanan (data sebelum per-percobaan). */
    private function orderIdFor(string $providerOrderId): ?int
    {
        $id = Payment::where('provider', 'MIDTRANS')->where('provider_order_id', $providerOrderId)->value('order_id')
            ?? PaymentAttempt::where('payment_attempts.provider_order_id', $providerOrderId)
                ->join('payments', 'payments.id', '=', 'payment_attempts.payment_id')->value('payments.order_id')
            ?? Order::where('order_number', $providerOrderId)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Notifikasi untuk percobaan yang sudah ditutup karena pembeli mengganti metode (biasanya
     * "cancel" dari penutupan itu sendiri). Tidak boleh mengubah pesanan atau stok, karena
     * pembayaran berjalan di percobaan baru.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordSupersededAttempt(PaymentWebhook $event, Payment $payment, array $payload): PaymentWebhook
    {
        $attempt = PaymentAttempt::where('payment_id', $payment->id)
            ->where('provider_order_id', (string) $payload['order_id'])->lockForUpdate()->first();
        if (! $attempt) {
            throw new DomainException('Payment attempt not found.');
        }
        $attempt->update([
            'provider_status' => mb_substr((string) ($payload['transaction_status'] ?? ''), 0, 32),
            'provider_transaction_id' => $payload['transaction_id'] ?? $attempt->provider_transaction_id,
        ]);
        $note = 'Superseded payment attempt; order unchanged';
        if ($this->mapper->internalStatus($payload) === PaymentStatus::SUCCESS) {
            // Seharusnya mustahil: percobaan baru hanya dibuat setelah cancel terkonfirmasi.
            // Uang tetap masuk, jadi tandai untuk ditinjau manual alih-alih diabaikan diam-diam.
            $note = 'Superseded payment attempt reported success; manual review required';
            Log::critical('Midtrans success on a superseded payment attempt', [
                'payment_id' => $payment->id, 'provider_order_id' => $attempt->provider_order_id,
                'transaction_id' => $payload['transaction_id'] ?? null,
            ]);
        }
        $event->update(['processing_error' => $note, 'processed_at' => now()]);

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        foreach (['signature_key', 'card_number', 'masked_card', 'token_id'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[REDACTED]';
            }
        }

        return $payload;
    }
}
