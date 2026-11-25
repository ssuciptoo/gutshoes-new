<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImageBackdrop extends Model
{
    protected $guarded = [];

    protected $table = 'image_backdrops';

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    protected function casts(): array
    {
        return ['angle' => 'integer', 'shadow' => 'boolean', 'is_default' => 'boolean'];
    }
}
