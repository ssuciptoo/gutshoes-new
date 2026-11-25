<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $guarded = [];

    protected $table = 'product_images';

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'has_transparency' => 'boolean', 'use_template' => 'boolean'];
    }
}
