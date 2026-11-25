<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at Batas bayar; kolom NOT NULL dengan cast datetime.
 */
class Order extends Model
{
    protected $guarded = [];

    protected $hidden = ['guest_access_token', 'guest_access_token_hash', 'payload_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'subtotal' => 'decimal:2', 'grand_total' => 'decimal:2', 'expires_at' => 'datetime', 'guest_access_token' => 'encrypted'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    /** @return HasOne<Payment, $this> */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /** order_id yang dipakai di Midtrans untuk percobaan pembayaran aktif; selalu dibaca ulang dari database. */
    public function midtransOrderId(): string
    {
        $id = Payment::where('order_id', $this->id)->where('provider', 'MIDTRANS')->value('provider_order_id');

        return is_string($id) && $id !== '' ? $id : $this->order_number;
    }

    /** @return HasOne<Shipment, $this> */
    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }
}
