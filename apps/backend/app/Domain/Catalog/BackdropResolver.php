<?php

namespace App\Domain\Catalog;

use App\Models\Category;
use App\Models\ImageBackdrop;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Template latar foto produk yang dipakai storefront. Daftar template di-cache dan dibersihkan
 * setiap kali admin mengubah template atau override kategori.
 */
class BackdropResolver
{
    private const CACHE_KEY = 'image-backdrops:v1';

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn (): array => ImageBackdrop::orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (ImageBackdrop $b) => [
                'id' => $b->id, 'type' => $b->type, 'color_from' => $b->color_from, 'color_to' => $b->color_to,
                'angle' => $b->angle, 'image_path' => $b->image_path, 'image_fit' => $b->image_fit,
                'shadow' => $b->shadow, 'is_default' => $b->is_default,
            ])->values()->all());
    }

    public function defaultId(): ?int
    {
        foreach ($this->all() as $backdrop) {
            if ($backdrop['is_default']) {
                return $backdrop['id'];
            }
        }

        return null;
    }

    /** Override dari kategori produk (urut id kategori) bila ada; selain itu template default. */
    public function idForProduct(Product $product): ?int
    {
        $category = $product->categories->sortBy('id')->first(fn (Category $c) => $c->image_backdrop_id !== null);

        return $category instanceof Category ? (int) $category->image_backdrop_id : $this->defaultId();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
