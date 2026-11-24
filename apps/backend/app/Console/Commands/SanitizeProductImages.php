<?php

namespace App\Console\Commands;

use App\Domain\Catalog\ImageSanitizer;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Membersihkan gambar produk yang diunggah sebelum sanitasi upload ada: setiap file digambar
 * ulang menjadi WebP baru, path di database diperbarui, lalu file lama dihapus.
 * Gambar yang sudah bersih ikut diproses ulang (sedikit menurunkan kualitas), jadi cukup
 * dijalankan sekali setelah deploy.
 */
class SanitizeProductImages extends Command
{
    protected $signature = 'gutshoes:sanitize-product-images {--dry-run : Tampilkan gambar yang akan diproses tanpa mengubah apa pun}';

    protected $description = 'Encode ulang gambar produk lama menjadi WebP bersih (tanpa EXIF dan payload sisipan)';

    public function handle(ImageSanitizer $sanitizer): int
    {
        $disk = Storage::disk('public');
        $dryRun = (bool) $this->option('dry-run');
        $done = $missing = $failed = 0;

        ProductImage::query()->orderBy('id')->each(function (ProductImage $image) use ($disk, $dryRun, $sanitizer, &$done, &$missing, &$failed): void {
            if (! $disk->exists($image->path)) {
                $this->warn("#{$image->id} dilewati: file {$image->path} tidak ditemukan.");
                $missing++;

                return;
            }
            if ($dryRun) {
                $this->line("#{$image->id} {$image->path}");
                $done++;

                return;
            }

            try {
                $webp = $sanitizer->sanitize((string) $disk->get($image->path));
                $old = $image->path;
                $image->update(['path' => $sanitizer->put($webp, dirname($old))]);
                $disk->delete($old);
                $done++;
            } catch (Throwable $error) {
                $this->error("#{$image->id} gagal: ".$error->getMessage());
                $failed++;
            }
        });

        $this->info(($dryRun ? 'Akan diproses' : 'Diproses').": {$done}, file hilang: {$missing}, gagal: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
