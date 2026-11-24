# GutShoes

Monorepo aplikasi e-commerce GutShoes.

## Struktur

```text
apps/
  storefront/   React + Vite
  backend/      Laravel REST API
```

Dokumen PRD, konteks produk, desain UI, dan rancangan database disimpan di root sebagai sumber kebenaran bersama.

## Storefront

```powershell
cd apps/storefront
npm install
npm run dev
```

Build produksi membutuhkan `VITE_API_URL` di `apps/storefront/.env.production` (HTTPS, berakhiran `/api/v1`, bukan domain contoh). Build gagal bila nilainya kosong agar bundle tidak diam-diam memakai `http://localhost:8000`.

```powershell
cd apps/storefront
npm run build
```

Image Docker storefront memakai mode `production` secara default; gunakan `--build-arg VITE_BUILD_MODE=staging` untuk staging.

## Backend

Backend Laravel di `apps/backend` berkomunikasi dengan storefront melalui REST API yang terversi.

## Persiapan staging storefront

> Panduan deploy staging lengkap (backend, worker, scheduler, storefront, dan dashboard pihak ketiga) ada di
> [docs/STAGING_DEPLOYMENT.md](docs/STAGING_DEPLOYMENT.md). Langkah di bawah hanya untuk build bundle storefront;
> image Docker storefront lebih disarankan karena membawa header keamanan, proxy sitemap, dan tag SEO produk.

1. Salin `apps/storefront/.env.staging.example` menjadi `apps/storefront/.env.staging`.
2. Ganti `VITE_API_URL` dengan URL HTTPS API staging yang berakhiran `/api/v1`. Nilai `VITE_*` dimasukkan ke bundle browser; jangan simpan secret di dalamnya.
3. Dari `apps/storefront`, jalankan `npm run build:staging`. Vite memuat `.env.staging` untuk mode ini dan menghasilkan bundle di `apps/storefront/dist`. Build akan gagal jika URL API belum diisi, bukan HTTPS, atau masih memakai domain contoh.
4. Di backend staging, selaraskan `APP_ENV=staging`, `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, dan `SANCTUM_STATEFUL_DOMAINS` dengan domain staging yang dipilih. Gunakan HTTPS, serta database, Redis, mail, dan kredensial provider khusus staging.
5. Deploy isi `apps/storefront/dist` ke hosting statis dan arahkan API serta callback provider ke layanan staging. Verifikasi login/cookie, CORS, checkout sandbox, dan webhook sebelum UAT.

URL hosting belum dipilih; `example.com` pada template adalah placeholder dan harus diganti sebelum build.

## Akun Admin

Email: gutshoes@email.com
Password: Gutshoes@123
