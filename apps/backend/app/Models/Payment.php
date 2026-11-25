<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at Batas sesi pembayaran; kolom NOT NULL dengan cast datetime.
 */
class Payment extends Model
{
    protected $guarded = [];

    protected $table = 'payments';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expires_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return HasMany<PaymentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function midtransOrderId(): string
    {
        return $this->provider_order_id ?: $this->order()->value('order_number');
    }
}
