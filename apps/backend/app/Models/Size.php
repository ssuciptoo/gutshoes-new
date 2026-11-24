<?php

namespace App\Models;

use Database\Factories\SizeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Size extends Model
{
    /** @use HasFactory<SizeFactory> */
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $table = 'sizes';

    protected $casts = ['foot_length_cm' => 'float', 'sort_value' => 'float'];

    /**
     * Ubah nilai ukuran seperti "42", "42.5", "42,5", "41 1/3", atau "41⅓" menjadi angka untuk pengurutan.
     * Mengembalikan null bila nilainya bukan angka (mis. "S", "M").
     */
    public static function sortValueFrom(string $value): ?float
    {
        $normalized = trim(strtr($value, ['⅓' => ' 1/3', '⅔' => ' 2/3', '½' => ' 1/2', '¼' => ' 1/4', '¾' => ' 3/4', ',' => '.']));
        if (! preg_match('/^(\d+(?:\.\d+)?)(?:\s+(\d+)\/(\d+))?$/', $normalized, $match)) {
            return null;
        }
        $number = (float) $match[1];
        if (isset($match[3]) && (int) $match[3] > 0) {
            $number += (int) $match[2] / (int) $match[3];
        }

        return round($number, 2);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
