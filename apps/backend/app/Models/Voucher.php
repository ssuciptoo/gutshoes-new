<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean', 'value' => 'decimal:2', 'minimum_amount' => 'decimal:2', 'maximum_discount' => 'decimal:2'];
    }

    /** @return BelongsToMany<Product,$this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'voucher_products');
    }

    /** @return HasMany<VoucherUsage,$this> */
    public function usages(): HasMany
    {
        return $this->hasMany(VoucherUsage::class);
    }
}
