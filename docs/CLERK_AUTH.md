# Login Pelanggan dengan Clerk

Status 23 November 2026: login pelanggan memakai Clerk dan sudah diuji di development (instance `pk_test_`). Admin tetap login dengan email + kata sandi (sesi Sanctum) dan tidak memakai Clerk. Login Google lama (Socialite) sudah dihapus.

## Cara kerja

1. Halaman `#login` membuka jendela masuk/daftar Clerk (mode modal, karena komponen `<SignIn />` Clerk mengubah hash URL dan bentrok dengan router toko).
2. Setelah masuk, frontend mengirim session token Clerk sebagai `Authorization: Bearer <token>` di setiap request pelanggan. Endpoint `/api/v1/admin/*` tidak pernah menerima token Clerk.
3. Middleware `AuthenticateClerkToken` memverifikasi token (RS256, `exp`/`nbf`, `iss` = Frontend API URL, `azp` = origin yang diizinkan) lalu mencari pelanggan lewat `users.clerk_user_id`.
4. Saat pertama masuk, backend mengambil email utama dari Clerk Backend API. Hanya email terverifikasi yang diterima. Akun yang dinonaktifkan admin dan email milik admin ditolak. Akun lama dengan email yang sama ditautkan.
5. Setiap kali sesi diperiksa (`/auth/me`), pesanan guest dengan email yang sama ditautkan ke akun.
6. Webhook `user.updated` menyinkronkan perubahan email utama (dan mengisi nama bila masih nama bawaan). Webhook `user.deleted` melepas akun toko dari identitas Clerk tanpa menghapus riwayat pesanan.

## Konfigurasi development

`apps/storefront/.env`

~~~dotenv
VITE_CLERK_PUBLISHABLE_KEY=pk_test_...
~~~

`apps/backend/.env`

~~~dotenv
CLERK_SECRET_KEY=sk_test_...
CLERK_FRONTEND_API_URL=https://<nama-instance>.clerk.accounts.dev
CLERK_AUTHORIZED_PARTIES=http://localhost:5173
CLERK_JWT_KEY=
CLERK_WEBHOOK_SECRET=
~~~

Jalankan `php artisan migrate` (kolom `users.clerk_user_id`), lalu restart `npm run dev` dan `composer dev`.

Di Windows dengan Avast, tambahkan pengecualian HTTPS untuk `https://<nama-instance>.clerk.accounts.dev`, `https://api.clerk.com`, dan bila verifikasi Cloudflare gagal, `https://challenges.cloudflare.com`. Panel browser bawaan Claude tidak bisa melewati verifikasi anti-bot Cloudflare saat mendaftar; uji pendaftaran di browser biasa.

## Checklist production

Dikerjakan di Clerk Dashboard dan server, bukan di kode:

- [ ] Buat instance **production** di Clerk dan hubungkan domain toko; tambahkan record DNS yang diminta Clerk sampai statusnya terverifikasi.
- [ ] Aktifkan metode masuk Google dan Email. Untuk Google, isi **Client ID/Secret OAuth milik sendiri** dari Google Cloud Console (instance development memakai kredensial bersama milik Clerk).
- [ ] Ganti nama aplikasi di Clerk menjadi "GutShoes" (tampil di judul jendela login).
- [ ] Pertimbangkan mewajibkan nama depan/belakang saat daftar; tanpa itu nama pelanggan tercatat "Pelanggan GutShoes" sampai ia mengisi profil.
- [ ] Biarkan perlindungan bot (Cloudflare) **aktif** di instance production.
- [ ] Buat webhook ke `https://<api>/api/v1/webhooks/clerk` dengan event `user.updated` dan `user.deleted`; salin Signing Secret ke `CLERK_WEBHOOK_SECRET`.
- [ ] Isi `.env` production backend: `CLERK_SECRET_KEY=sk_live_...`, `CLERK_FRONTEND_API_URL=https://clerk.<domain-toko>`, `CLERK_AUTHORIZED_PARTIES=https://<domain-toko>`. Opsional `CLERK_JWT_KEY` (JWKS Public Key, baris baru ditulis `\n`) agar verifikasi token tidak memanggil jaringan.
- [ ] Isi `VITE_CLERK_PUBLISHABLE_KEY=pk_live_...` di `apps/storefront/.env.production`. Build production gagal bila kunci kosong atau bukan `pk_live_`.
- [ ] Setelah deploy: daftar akun baru, masuk, ubah profil, buat pesanan, keluar, lalu masuk lagi.

## Batasan yang diketahui

- `firebase/php-jwt` dipakai langsung tetapi masih terpasang sebagai dependensi `laravel/socialite`. Bila Socialite dihapus, tambahkan `firebase/php-jwt` ke `composer.json` lebih dulu.
- Teks "Development mode" di jendela Clerk hanya muncul dengan kunci `pk_test_`.
