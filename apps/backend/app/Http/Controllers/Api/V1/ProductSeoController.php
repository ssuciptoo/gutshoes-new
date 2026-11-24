<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Potongan tag <head> untuk halaman produk (judul, deskripsi, canonical, Open Graph, JSON-LD).
 *
 * nginx toko menyisipkan potongan ini ke index.html lewat SSI saat /product/{slug} dibuka, agar
 * WhatsApp/Facebook/X (yang tidak menjalankan JavaScript) dan Google langsung melihat data
 * produk. Bila endpoint ini gagal atau body kosong, nginx memakai tag bawaan index.html.
 * Isinya harus sama dengan src/lib/seo.js (applySeo + productJsonLd) di storefront.
 */
class ProductSeoController extends Controller
{
    public function __invoke(string $slug): Response
    {
        $html = Cache::remember('seo.product.'.sha1($slug), now()->addMinutes(10), fn (): string => $this->render($slug));

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function render(string $slug): string
    {
        $product = Product::query()->where('status', 'PUBLISHED')->where('slug', $slug)
            ->with(['brand', 'images', 'categories', 'variants.inventories'])->first();
        if (! $product) {
            return $this->tags(['title' => 'Produk tidak ditemukan — GutShoes', 'description' => null, 'noindex' => true]);
        }

        $base = rtrim((string) config('services.storefront.url'), '/');
        $url = $base.'/product/'.rawurlencode($product->slug);
        $images = $product->images
            ->sortBy(fn (ProductImage $image) => [$image->is_primary ? 0 : 1, (int) ($image->position ?? 0)])
            ->values();
        $imageUrls = $images->map(fn (ProductImage $image): string => $this->imageUrl((string) $image->path))->all();
        $variants = $product->variants;
        $prices = $variants->map(fn (ProductVariant $variant): float => (float) $variant->price)->filter(fn (float $price) => $price > 0);
        $low = $prices->min();
        $high = $prices->max();
        $inStock = $variants->contains(fn (ProductVariant $variant) => $variant->inventories->sum(fn ($inventory) => max(0, $inventory->on_hand - $inventory->reserved)) > 0);
        $brand = $product->brand?->name ?: 'GutShoes';
        $category = $product->categories->first();
        $description = trim((string) $product->description) !== ''
            ? (string) $product->description
            : $product->name.' dari '.$brand.($low ? ', mulai '.$this->rupiah($low) : '').'. Pilih ukuran, cek stok, dan pesan di GutShoes.';

        $availability = 'https://schema.org/'.($inStock ? 'InStock' : 'OutOfStock');
        $offers = $low === null ? null : ($low === $high
            ? ['@type' => 'Offer', 'url' => $url, 'priceCurrency' => 'IDR', 'price' => $this->number($low), 'availability' => $availability, 'itemCondition' => 'https://schema.org/NewCondition']
            : ['@type' => 'AggregateOffer', 'url' => $url, 'priceCurrency' => 'IDR', 'lowPrice' => $this->number($low), 'highPrice' => $this->number($high),
                'offerCount' => $variants->count(), 'availability' => $availability, 'itemCondition' => 'https://schema.org/NewCondition']);
        $productLd = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'url' => $url,
            'image' => $imageUrls ?: null,
            'description' => trim((string) $product->description) !== '' ? Str::limit(Str::squish((string) $product->description), 5000, '…', true) : null,
            'sku' => $variants->first()?->sku,
            'brand' => ['@type' => 'Brand', 'name' => $brand],
            'category' => $category?->name,
            'offers' => $offers,
        ], fn ($value) => $value !== null);
        $crumbs = [['Beranda', $base.'/'], ['Semua sepatu', $base.'/catalog']];
        if ($category) {
            $crumbs[] = [$category->name, $base.'/catalog?filter='.rawurlencode($category->slug)];
        }
        $crumbs[] = [$product->name, $url];
        $breadcrumbLd = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(
            fn (array $crumb, int $index): array => ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $crumb[0], 'item' => $crumb[1]],
            $crumbs, array_keys($crumbs),
        )];

        return $this->tags([
            'title' => $product->name.' — GutShoes',
            'description' => $description,
            'canonical' => $url,
            'image' => $imageUrls[0] ?? $base.'/og-image.jpg',
            'image_alt' => $images->first()?->alt_text ?: $product->name,
            'json_ld' => [$productLd, $breadcrumbLd],
        ]);
    }

    /** @param array<string, mixed> $seo */
    private function tags(array $seo): string
    {
        $e = fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = $seo['description'] ? Str::limit(Str::squish((string) $seo['description']), 159, '…', true) : null;
        $lines = ['<title>'.$e($seo['title']).'</title>'];
        if ($description) {
            $lines[] = '<meta name="description" content="'.$e($description).'" />';
        }
        if (! empty($seo['noindex'])) {
            $lines[] = '<meta name="robots" content="noindex, nofollow" />';

            return implode("\n    ", $lines)."\n";
        }
        array_push($lines,
            '<link rel="canonical" href="'.$e($seo['canonical']).'" />',
            '<meta property="og:type" content="product" />',
            '<meta property="og:site_name" content="GutShoes" />',
            '<meta property="og:locale" content="id_ID" />',
            '<meta property="og:url" content="'.$e($seo['canonical']).'" />',
            '<meta property="og:title" content="'.$e($seo['title']).'" />',
            '<meta property="og:description" content="'.$e($description).'" />',
            '<meta property="og:image" content="'.$e($seo['image']).'" />',
            '<meta property="og:image:alt" content="'.$e($seo['image_alt']).'" />',
            '<meta name="twitter:card" content="summary_large_image" />',
            // JSON_HEX_TAG mengubah < dan > agar teks produk tidak bisa menutup tag <script>.
            '<script type="application/ld+json" id="seo-json-ld">'
                .json_encode($seo['json_ld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP).'</script>',
        );

        return implode("\n    ", $lines)."\n";
    }

    private function imageUrl(string $path): string
    {
        return preg_match('#^https?://#i', $path) ? $path : Storage::disk('public')->url(ltrim($path, '/'));
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function rupiah(float $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }
}
