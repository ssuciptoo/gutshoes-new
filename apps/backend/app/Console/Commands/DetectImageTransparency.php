<?php

namespace App\Console\Commands;

use App\Domain\Catalog\ImageSanitizer;
use App\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Mengisi has_transparency untuk gambar produk yang diunggah sebelum template latar ada.
 * Tanpa --apply, use_template tidak diubah sehingga tampilan toko tetap sama; dengan --apply,
 * gambar transparan langsung memakai template latar.
 */
class DetectImageTransparency extends Command
{
    protected $signature = 'gutshoes:detect-image-transparency {--apply : Aktifkan template latar untuk gambar yang transparan}';

    protected $description = 'Deteksi gambar produk berlatar transparan untuk template latar';

    public function handle(ImageSanitizer $sanitizer): int
    {
        $disk = Storage::disk('public');
        $apply = (bool) $this->option('apply');
        $transparent = $opaque = $missing = $failed = 0;

        ProductImage::query()->orderBy('id')->each(function (ProductImage $image) use ($disk, $apply, $sanitizer, &$transparent, &$opaque, &$missing, &$failed): void {
            if (! $disk->exists($image->path)) {
                $this->warn("#{$image->id} dilewati: file {$image->path} tidak ditemukan.");
                $missing++;

                return;
            }
            try {
                $isTransparent = $sanitizer->transparency((string) $disk->get($image->path))['transparent'];
            } catch (Throwable $error) {
                $this->error("#{$image->id} gagal: ".$error->getMessage());
                $failed++;

                return;
            }
            $changes = ['has_transparency' => $isTransparent];
            if ($apply && $isTransparent) {
                $changes['use_template'] = true;
            }
            $image->update($changes);
            $isTransparent ? $transparent++ : $opaque++;
            $this->line("#{$image->id} ".($isTransparent ? 'transparan' : 'berlatar penuh')." {$image->path}");
        });

        $this->info("Transparan: {$transparent}, berlatar penuh: {$opaque}, file hilang: {$missing}, gagal: {$failed}."
            .($apply ? '' : ' Jalankan dengan --apply untuk memakai template pada gambar transparan.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
