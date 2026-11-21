<?php

namespace App\Jobs;

use App\Domain\Payment\ReconcileMidtransPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Safety net for missed Midtrans notifications (server down, wrong notification URL,
 * exhausted retries). Without it a paid order stays "awaiting payment" until the
 * 24-hour expiry job asks Midtrans. Webhooks remain the primary path; this only
 * re-reads Midtrans status and applies it through the same idempotent code.
 */
class ReconcilePendingPayments implements ShouldQueue
{
    use Queueable;

    public function handle(ReconcileMidtransPayment $reconciler): void
    {
        $settings = config('gutshoes.payment_reconcile');
        $checked = 0;

        $payments = Payment::query()
            ->where('provider', 'MIDTRANS')
            ->where('status', PaymentStatus::PENDING->value)
            ->whereNull('paid_at')
            ->where('created_at', '<=', now()->subMinutes((int) $settings['after_minutes']))
            // Expired orders belong to ExpirePendingOrders, which closes them at Midtrans.
            ->whereHas('order', fn ($query) => $query
                ->where('status', OrderStatus::PENDING_PAYMENT->value)
                ->where('expires_at', '>', now()))
            ->with('order')
            ->lazyById(100);

        foreach ($payments as $payment) {
            if ($checked >= (int) $settings['batch_size']) {
                break;
            }
            // Space out checks per payment so long-pending ones don't use up the provider quota.
            if (! Cache::add('payment-reconcile:'.$payment->id, true, now()->addMinutes((int) $settings['interval_minutes']))) {
                continue;
            }
            $checked++;

            try {
                $reconciler->reconcile($payment->order);
            } catch (Throwable $error) {
                // One bad provider response must not stop the rest of the batch.
                report($error);
            }
        }
    }
}
