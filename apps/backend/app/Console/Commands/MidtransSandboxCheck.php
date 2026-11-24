<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class MidtransSandboxCheck extends Command
{
    protected $signature = 'midtrans:sandbox-check
        {--verify : Kirim satu cek status untuk order fiktif ke API sandbox untuk memastikan server key diterima (tidak membuat transaksi)}';

    protected $description = 'Periksa konfigurasi sandbox tanpa menampilkan kunci atau membuat transaksi';

    public function handle(): int
    {
        $errors = [];
        $serverKey = (string) config('services.midtrans.server_key');
        if (config('services.midtrans.driver') !== 'http') {
            $errors[] = 'MIDTRANS_DRIVER harus http.';
        }
        // Akun lama memakai awalan "SB-Mid-server-" untuk sandbox; akun baru memakai "Mid-server-" untuk
        // sandbox maupun production. Awalan saja tidak cukup membedakan, jadi pakai --verify.
        if (! str_starts_with($serverKey, 'SB-Mid-server-') && ! str_starts_with($serverKey, 'Mid-server-')) {
            $errors[] = 'Server Key belum tersedia atau formatnya tidak dikenal (cek spasi/tanda kutip).';
        }
        foreach (['snap_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions', 'api_url' => 'https://api.sandbox.midtrans.com/v2'] as $key => $url) {
            if (rtrim((string) config('services.midtrans.'.$key), '/') !== $url) {
                $errors[] = $key.' harus memakai endpoint sandbox resmi.';
            }
        }

        if ($errors === [] && $this->option('verify')) {
            $result = $this->verifyKey($serverKey);
            $result === null ? $this->info('Server Key diterima oleh API sandbox Midtrans.') : $errors[] = $result;
        } elseif ($errors === [] && ! str_starts_with($serverKey, 'SB-')) {
            $this->warn('Server Key tanpa awalan SB-: pastikan disalin dari dashboard mode Sandbox, atau jalankan dengan --verify.');
        }

        foreach ($errors as $error) {
            $this->error($error);
        }
        $this->line('Callback: '.rtrim((string) config('app.url'), '/').'/api/v1/payments/midtrans/webhook');
        $this->line('Callback harus dapat diakses Midtrans melalui HTTPS publik.'
            .($this->option('verify') ? '' : ' Tidak ada request provider dikirim.'));

        return $errors ? self::FAILURE : self::SUCCESS;
    }

    /** @return string|null Pesan error, atau null bila kunci diterima sandbox. */
    private function verifyKey(string $serverKey): ?string
    {
        // Order fiktif: sandbox membalas 404 bila kuncinya sah, 401 bila kunci bukan milik sandbox.
        $url = rtrim((string) config('services.midtrans.api_url'), '/').'/GS-SANDBOX-CHECK-'.Str::upper(Str::random(10)).'/status';
        try {
            $response = Http::withBasicAuth($serverKey, '')->acceptJson()->timeout(10)->get($url);
        } catch (Throwable) {
            return 'API sandbox Midtrans tidak dapat dihubungi dari server ini.';
        }
        $code = (string) ($response->json('status_code') ?? $response->status());

        return match ($code) {
            '404' => null,
            '401' => 'Server Key ditolak API sandbox (401). Kemungkinan kunci production; salin ulang dari dashboard mode Sandbox.',
            default => 'Respons tak terduga dari API sandbox (status '.$code.').',
        };
    }
}
