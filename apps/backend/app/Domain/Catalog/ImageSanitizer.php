<?php

namespace App\Domain\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menggambar ulang gambar menjadi WebP baru lewat GD. Hanya piksel yang disalin, jadi
 * metadata (EXIF/GPS), komentar, dan data sisipan (file polyglot, skrip) ikut terbuang.
 * File yang tidak bisa dibaca sebagai gambar ditolak.
 */
class ImageSanitizer
{
    public function store(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        return $this->put($this->sanitize((string) file_get_contents($file->getRealPath())), $directory, $disk);
    }

    /** Simpan hasil sanitasi dengan nama acak baru ber-ekstensi .webp; mengembalikan path-nya. */
    public function put(string $webp, string $directory, string $disk = 'public'): string
    {
        $path = trim($directory, '/').'/'.Str::random(40).'.webp';
        Storage::disk($disk)->put($path, $webp);

        return $path;
    }

    /** @return string Isi file WebP yang bersih. */
    public function sanitize(string $bytes): string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['image' => ['File gambar tidak dapat dibaca.']]);
        }

        try {
            $image = $this->orient($image, $bytes);
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);

            ob_start();
            $encoded = imagewebp($image, null, 85);
            $webp = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }
        if (! $encoded || $webp === '') {
            throw ValidationException::withMessages(['image' => ['File gambar tidak dapat diproses.']]);
        }

        return $webp;
    }

    /** Terapkan orientasi EXIF JPEG sebelum metadatanya dibuang, supaya foto tidak miring. */
    private function orient(\GdImage $image, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return $image;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $angle = match ((int) (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
