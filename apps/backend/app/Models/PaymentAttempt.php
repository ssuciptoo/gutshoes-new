<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Percobaan Midtrans yang sudah ditutup karena pembeli mengganti metode pembayaran. */
class PaymentAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['superseded_at' => 'datetime'];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
