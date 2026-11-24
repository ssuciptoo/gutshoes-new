<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * sitemap.xml untuk mesin pencari: halaman publik toko, halaman kategori, dan semua produk
 * yang sudah dipublikasikan. Alamat memakai domain toko (STOREFRONT_URL), bukan domain API;
 * di production nginx toko meneruskan /sitemap.xml ke endpoint ini.
 */
class SitemapController extends Controller
{
    /** Halaman statis yang boleh diindeks (lihat INDEXABLE_PAGES di storefront). */
    private const PAGES = ['/', '/catalog', '/tracking', '/contact', '/returns', '/terms', '/privacy'];

    public function __invoke(): Response
    {
        // Disimpan sebentar: mesin pencari bisa sering meminta, produk baru tetap muncul dalam 10 menit.
        $xml = Cache::remember('sitemap.xml', now()->addMinutes(10), fn (): string => $this->build());

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=600',
        ]);
    }

    private function build(): string
    {
        $base = rtrim((string) config('services.storefront.url'), '/');
        $entries = array_map(fn (string $path): array => ['loc' => $base.$path, 'lastmod' => null], self::PAGES);

        $published = Product::query()->where('status', 'PUBLISHED');
        $categories = Category::query()
            ->whereIn('id', DB::table('category_product')->select('category_id')->whereIn('product_id', (clone $published)->select('id')))
            ->orderBy('slug')
            ->get(['slug']);
        foreach ($categories as $category) {
            $entries[] = ['loc' => $base.'/catalog?filter='.rawurlencode($category->slug), 'lastmod' => null];
        }

        foreach ($published->orderBy('id')->get(['slug', 'updated_at']) as $product) {
            $entries[] = ['loc' => $base.'/product/'.rawurlencode($product->slug), 'lastmod' => $product->updated_at?->toAtomString()];
        }

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        foreach ($entries as $entry) {
            $lines[] = '  <url><loc>'.htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>'
                .($entry['lastmod'] ? '<lastmod>'.$entry['lastmod'].'</lastmod>' : '').'</url>';
        }
        $lines[] = '</urlset>';

        return implode("\n", $lines)."\n";
    }
}
