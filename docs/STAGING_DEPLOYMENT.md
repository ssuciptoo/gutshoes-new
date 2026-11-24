# Panduan Deploy Staging GutShoes

Panduan ini untuk deploy pertama ke **staging**: lingkungan yang mirip production, memakai kunci
**sandbox/test**, dan dipakai untuk uji end-to-end sebelum production. Untuk production, lihat juga
[PRODUCTION_RUNBOOK.md](PRODUCTION_RUNBOOK.md).

Ganti `domain-anda.com` di seluruh panduan dengan domain Anda.

| Komponen | Contoh alamat | Image / proses |
|---|---|---|
| Toko (storefront) | `https://staging.domain-anda.com` | `apps/storefront/Dockerfile` (nginx, port 80) |
| API (backend) | `https://api-staging.domain-anda.com` | `apps/backend/Dockerfile` (FrankenPHP, port 8080) |
| Antrean (Horizon) | tanpa alamat publik | image backend, perintah `php artisan horizon` |
| Scheduler | tanpa alamat publik | image backend, perintah `php artisan schedule:work` |
| Database | privat | MySQL 8.4 |
| Cache, sesi, antrean | privat | Redis 7 |

---

## 0. Jalur cepat: satu VPS Ubuntu 24.04 (1 vCPU / 2 GB)

Semua file siap pakai ada di [`deploy/staging/`](../deploy/staging): `compose.yaml` (Caddy + HTTPS
otomatis, API, Horizon, scheduler, storefront, MySQL, Redis, semuanya dengan batas memori),
`bootstrap.sh` (menyiapkan server), dan `deploy.sh` (deploy, backup, status). Image dibangun oleh
GitHub Actions; VPS hanya menarik image jadi.

**A. Sekali di GitHub**

1. Push ke `main`. Setelah CI hijau, workflow **Publish staging images** mengirim image backend ke
   `ghcr.io/<owner>/gutshoes-backend:staging`.
2. Buat environment: *Settings → Environments → New environment* bernama `staging`, lalu tambahkan
   **Variables** (bukan Secrets, karena nilainya ikut ke bundle browser):
   - `STAGING_DOMAIN`: domain induk, mis. `gutshoes.id` (toko `staging.gutshoes.id`, API `api-staging.gutshoes.id`)
   - `STAGING_CLERK_PUBLISHABLE_KEY`: `pk_test_…` dari instance Clerk staging

   Setelah keduanya terisi, jalankan ulang workflow (*Actions → Publish staging images → Run workflow*)
   agar image storefront ikut dibangun.
3. Buat token untuk VPS: *Settings (akun) → Developer settings → Personal access tokens (classic)*,
   centang hanya `read:packages`. Token ini dipakai `docker login` di VPS.

**B. DNS** (di penyedia domain): dua record **A** `staging` dan `api-staging` ke IP VPS. Tunggu
sampai `nslookup api-staging.<domain>` menampilkan IP VPS sebelum langkah D, agar Caddy bisa
mendapat sertifikat HTTPS.

**C. Sekali di VPS** (dari laptop, di root repo):

```bash
scp -r deploy/staging root@IP_VPS:/opt/gutshoes
ssh root@IP_VPS 'bash /opt/gutshoes/bootstrap.sh'
```

`bootstrap.sh` memasang Docker, swap 2 GB, firewall (SSH/80/443), update keamanan otomatis, backup
database harian, dan membuat `/opt/gutshoes/.env` + `/opt/gutshoes/backend.env` dengan password
MySQL/Redis dan `APP_KEY` acak.

**D. Isi konfigurasi lalu deploy** (di VPS):

```bash
nano /opt/gutshoes/.env           # STAGING_DOMAIN, ACME_EMAIL
nano /opt/gutshoes/backend.env    # SMTP, Midtrans sandbox, RajaOngkir, Clerk (lihat bagian 2)
docker login ghcr.io -u <username-github>          # password = token dari langkah A.3
/opt/gutshoes/deploy.sh deploy
/opt/gutshoes/deploy.sh admin     # buat akun admin
```

Setelah image storefront tersedia, isi `COMPOSE_PROFILES=storefront` di `/opt/gutshoes/.env` lalu
jalankan `deploy.sh deploy` lagi. Lanjutkan ke bagian 5 (dashboard Midtrans/Clerk) dan 6 (smoke test).

**Operasi harian**

| Perintah | Fungsi |
|---|---|
| `deploy.sh deploy` | Tarik image `staging` terbaru, migrasi, jalankan ulang layanan |
| `deploy.sh deploy <hash-commit>` | Deploy/rollback ke commit tertentu (tag 12 karakter dari ringkasan workflow) |
| `deploy.sh status` | Status container, health API, pemakaian RAM |
| `deploy.sh logs api` | Log satu layanan (`api`, `horizon`, `scheduler`, `caddy`, `mysql`) |
| `deploy.sh backup` | Backup database manual ke `/opt/gutshoes/backups` |
| `deploy.sh check` | Cek konfigurasi Midtrans sandbox |

Bagian 1–4 di bawah menjelaskan detail dan alternatif (mis. platform selain VPS).

---

## 1. Yang perlu disiapkan

### 1.1 Infrastruktur

- [ ] **Server atau platform container** yang menjalankan Docker (VPS dengan Docker Compose, atau PaaS
      seperti Railway/Render/Fly.io). Minimal 2 vCPU / 4 GB RAM untuk semua proses staging.
- [ ] **MySQL 8.4**: buat database `gutshoes_staging` dan user khusus (bukan root) yang hanya punya hak
      ke database itu.
- [ ] **Redis 7** dengan password. Redis dipakai untuk sesi admin, cache, rate limit, dan antrean.
- [ ] **Penyimpanan persisten** untuk gambar produk (`/app/storage/app/public` di container backend).
      Tanpa volume, gambar hilang setiap redeploy.
- [ ] **Reverse proxy dengan TLS** (Caddy, Traefik, nginx, atau bawaan PaaS). Kedua image hanya
      melayani HTTP; HTTPS wajib karena cookie sesi memakai flag `Secure`.
- [ ] **Firewall**: hanya port 80/443 yang terbuka ke publik. MySQL dan Redis tidak boleh bisa diakses
      dari internet.
- [ ] **Backup database terjadwal** (lihat `scripts/backup-database.sh`), walaupun hanya staging.

### 1.2 DNS

- [ ] `staging.domain-anda.com` → reverse proxy (diteruskan ke container storefront port 80).
- [ ] `api-staging.domain-anda.com` → reverse proxy (diteruskan ke container backend port 8080).

Toko dan API **harus satu domain induk** (`*.domain-anda.com`). Login admin memakai cookie sesi
dengan `SESSION_DOMAIN=.domain-anda.com`; bila domainnya berbeda, login admin gagal dengan 419.

### 1.3 Akun layanan pihak ketiga (mode test/sandbox)

| Layanan | Yang diambil | Dipakai di |
|---|---|---|
| **Clerk** (login pelanggan) | Publishable key `pk_test_…`, Secret key `sk_test_…`, Frontend API URL, Webhook signing secret `whsec_…` | backend + build storefront |
| **Midtrans Sandbox** | Server key & Client key sandbox | backend |
| **RajaOngkir (Komerce)** | API key | backend |
| **SMTP** (mis. Brevo) | Host, username, SMTP key, alamat pengirim terverifikasi | backend |

Lihat juga [CLERK_AUTH.md](CLERK_AUTH.md), [MIDTRANS_SANDBOX.md](MIDTRANS_SANDBOX.md), dan
[EMAIL_INFRASTRUCTURE.md](EMAIL_INFRASTRUCTURE.md).

> Simpan semua kunci di secret manager atau variabel environment platform, **bukan** di Git.
> `.env.staging` sudah di-ignore; jangan pernah memaksanya masuk commit.

---

## 2. Konfigurasi backend

Salin `apps/backend/.env.staging.example` sebagai acuan, lalu isi nilainya di secret manager/platform.

### 2.1 Wajib diisi

| Variabel | Nilai / cara mengisi |
|---|---|
| `APP_ENV` | `staging` |
| `APP_DEBUG` | `false` (jangan `true`: pesan error internal akan terlihat) |
| `APP_KEY` | Buat sekali: `docker run --rm <image-backend> php artisan key:generate --show`. Simpan sebagai secret dan jangan diganti, karena data terenkripsi (sesi, token guest) bergantung padanya |
| `APP_URL` | `https://api-staging.domain-anda.com` (juga dipakai untuk URL gambar `/storage/...`) |
| `FRONTEND_URL`, `STOREFRONT_URL` | `https://staging.domain-anda.com` (tanpa `/` di akhir) |
| `CORS_ALLOWED_ORIGINS` | `https://staging.domain-anda.com` |
| `SANCTUM_STATEFUL_DOMAINS` | `staging.domain-anda.com,api-staging.domain-anda.com` |
| `SESSION_DOMAIN` | `.domain-anda.com` (pakai titik di depan) |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `redis` |
| `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` | `true` / `true` |
| `TRUSTED_PROXIES` | IP/CIDR reverse proxy (mis. `172.16.0.0/12` untuk jaringan Docker). Isi `*` hanya bila container API **tidak** bisa dijangkau selain lewat proxy. Kosong = semua pengunjung dianggap satu IP (IP proxy), sehingga rate limit login/checkout berlaku untuk semua orang sekaligus |
| `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | dari MySQL staging |
| `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` | dari Redis staging |
| `MAIL_*` | SMTP; `MAIL_FROM_ADDRESS` **wajib** terisi, kalau kosong semua email pesanan gagal |
| `MIDTRANS_DRIVER` | `http` (aplikasi menolak start di staging bila `fake` atau server key kosong) |
| `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY` | kunci **sandbox** |
| `MIDTRANS_IS_PRODUCTION` | `false` (URL sandbox sudah menjadi default) |
| `SHIPPING_DRIVER`, `TRACKING_DRIVER`, `RAJAONGKIR_API_KEY` | `rajaongkir` + API key |
| `CLERK_SECRET_KEY` | `sk_test_…` |
| `CLERK_FRONTEND_API_URL` | Frontend API URL instance Clerk, mis. `https://nama-app.clerk.accounts.dev` |
| `CLERK_WEBHOOK_SECRET` | `whsec_…` dari endpoint webhook (langkah 5.2) |
| `CLERK_AUTHORIZED_PARTIES` | `https://staging.domain-anda.com` |

### 2.2 Opsional

| Variabel | Kegunaan |
|---|---|
| `CLERK_JWT_KEY` | Public key JWKS (PEM, baris baru ditulis `\n`) agar verifikasi token tanpa memanggil jaringan |
| `SHIPPING_QUOTE_CACHE_MINUTES` | Lama cache ongkir (default 15) |
| `HORIZON_MAX_PROCESSES` | Jumlah maksimum worker antrean (default staging 2, production 10; ±50–100 MB per worker) |
| `GUTSHOES_ADMIN_EMAIL/NAME/PASSWORD` | Untuk membuat admin tanpa prompt (langkah 4.3). Hapus lagi setelah admin dibuat |

---

## 3. Build image

Build dari commit yang **sudah lulus CI**. Beri tag yang bisa dilacak (mis. hash commit), lalu push ke
registry (GitHub Container Registry, Docker Hub, dll.).

### 3.1 Backend

```bash
docker build --file apps/backend/Dockerfile --tag registry.example/gutshoes-backend:<commit> apps/backend
```

Build otomatis memeriksa ekstensi PHP (`composer check-platform-reqs`) dan membuat link
`public/storage` untuk gambar produk.

### 3.2 Storefront

Nilai `VITE_*` **dimasukkan ke bundle saat build** (bukan saat runtime), jadi image storefront
staging dan production harus dibuild terpisah.

```bash
docker build --file apps/storefront/Dockerfile \
  --build-arg VITE_BUILD_MODE=staging \
  --build-arg VITE_API_URL=https://api-staging.domain-anda.com/api/v1 \
  --build-arg VITE_SITE_URL=https://staging.domain-anda.com \
  --build-arg VITE_CLERK_PUBLISHABLE_KEY=pk_test_xxx \
  --tag registry.example/gutshoes-storefront:staging-<commit> apps/storefront
```

- Build gagal bila `VITE_API_URL` bukan HTTPS berakhiran `/api/v1`, `VITE_SITE_URL` kosong, atau
  publishable key salah format. Ini disengaja.
- Host Frontend API Clerk dibaca dari publishable key dan dimasukkan ke Content-Security-Policy
  nginx. Tanpa itu, skrip login Clerk diblokir browser.
- Publishable key bukan rahasia; aman sebagai build arg. **Jangan** memasukkan `CLERK_SECRET_KEY` ke
  build storefront.

---

## 4. Urutan deploy pertama

### 4.1 Contoh Docker Compose

Untuk VPS tunggal, pakai file lengkap di [`deploy/staging/compose.yaml`](../deploy/staging/compose.yaml)
(bagian 0). Contoh ringkas di bawah untuk platform lain, dengan asumsi MySQL, Redis, dan reverse
proxy TLS sudah tersedia terpisah.

```yaml
# Contoh ringkas; versi lengkap untuk VPS: deploy/staging/compose.yaml
x-backend: &backend
  image: registry.example/gutshoes-backend:<commit>
  env_file: /etc/gutshoes/staging.env   # file secret di server, permission 600
  restart: unless-stopped
  volumes:
    - product-images:/app/storage/app/public

services:
  api:
    <<: *backend
    # Cache config/route/view saat start; cache tidak ikut di image karena env baru ada saat runtime.
    command: sh -c "php artisan optimize && exec frankenphp php-server --listen :8080 --root public/"
    ports: ["127.0.0.1:8080:8080"]      # hanya untuk reverse proxy di host yang sama

  horizon:
    <<: *backend
    command: sh -c "php artisan optimize && exec php artisan horizon"
    stop_grace_period: 60s

  scheduler:
    <<: *backend
    command: sh -c "php artisan optimize && exec php artisan schedule:work"

  storefront:
    image: registry.example/gutshoes-storefront:staging-<commit>
    restart: unless-stopped
    ports: ["127.0.0.1:8081:80"]

volumes:
  product-images:
```

Hal penting:

- **`horizon` dan `scheduler` wajib jalan.** Tanpa Horizon email pesanan tidak terkirim. Tanpa
  scheduler, pesanan yang tidak dibayar tidak pernah kedaluwarsa (stok tertahan) dan rekonsiliasi
  pembayaran Midtrans tidak berjalan.
- Jalankan **satu** container `scheduler` saja agar job terjadwal tidak dobel.
- Volume `product-images` dipakai bersama oleh `api` dan worker.

### 4.2 Migrasi database dan data wilayah

Jalankan sekali sebelum traffic dibuka, dan setiap deploy berikutnya:

```bash
docker compose -f compose.staging.yaml run --rm api composer deploy:database
```

Perintah ini menjalankan `migrate --force` dan mengimpor master wilayah (±83 ribu kelurahan) secara
idempoten. Deploy harus dihentikan bila perintah ini gagal. **Jangan** memakai `regions:import --fresh`.

### 4.3 Akun admin

Tidak ada pendaftaran admin publik. Buat lewat command (password minimal 12 karakter berisi huruf
besar, huruf kecil, angka, dan simbol):

```bash
docker compose -f compose.staging.yaml run --rm api php artisan gutshoes:create-admin --email=admin@domain-anda.com --name="Admin Staging"
```

Password diminta lewat prompt. Jangan menaruhnya di `--password=` karena tersimpan di riwayat shell.

### 4.4 Jalankan semua layanan

```bash
docker compose -f compose.staging.yaml up -d
docker compose -f compose.staging.yaml ps
```

### 4.5 Cek konfigurasi Midtrans

```bash
docker compose -f compose.staging.yaml run --rm api php artisan midtrans:sandbox-check
```

---

## 5. Konfigurasi di dashboard pihak ketiga

### 5.1 Midtrans Sandbox (Settings → Configuration)

- [ ] **Payment Notification URL**: `https://api-staging.domain-anda.com/api/v1/payments/midtrans/webhook`
- [ ] Finish/Unfinish/Error redirect tidak perlu diisi; aplikasi mengirim `callbacks.finish` ke
      `STOREFRONT_URL` per transaksi.

### 5.2 Clerk (instance yang sama dengan `pk_test_…`)

- [ ] **Webhooks → Add endpoint**: `https://api-staging.domain-anda.com/api/v1/webhooks/clerk`, event
      `user.updated` dan `user.deleted`. Salin signing secret ke `CLERK_WEBHOOK_SECRET`.
- [ ] **Domains / Allowed origins**: tambahkan `https://staging.domain-anda.com`.
- [ ] Aktifkan metode login yang dipakai (email, Google, dll.) di instance ini.

### 5.3 SMTP

- [ ] Verifikasi domain/alamat pengirim (`MAIL_FROM_ADDRESS`) dan pasang SPF/DKIM agar email tidak masuk spam.

---

## 6. Smoke test setelah deploy

Jalankan berurutan; berhenti dan perbaiki bila ada yang gagal.

**API dan infrastruktur**

```bash
curl -fsS https://api-staging.domain-anda.com/api/v1/health
curl -fsS https://api-staging.domain-anda.com/api/v1/health/ready      # harus "ready", bukan "degraded"
curl -fsS "https://api-staging.domain-anda.com/api/v1/regions/villages?district_code=31.71.01"
curl -sI https://staging.domain-anda.com | grep -i -E "strict-transport|content-security"
curl -fsS https://staging.domain-anda.com/robots.txt
curl -fsS https://staging.domain-anda.com/sitemap.xml | head
```

**Lewat browser** (buka DevTools → Console; tidak boleh ada error CSP):

- [ ] Halaman beranda, katalog, dan detail produk tampil. Gambar produk termuat.
- [ ] Login pelanggan lewat Clerk berhasil, lalu profil dan alamat bisa disimpan.
- [ ] Login admin di `/admin`, tambah produk dan upload gambar, lalu publish. Gambar tampil di toko.
- [ ] Checkout sebagai guest dan sebagai pelanggan. Ongkir RajaOngkir muncul. Bayar memakai
      [simulator Midtrans sandbox](https://simulator.sandbox.midtrans.com/). Status pesanan menjadi
      **PAID** tanpa refresh manual (artinya webhook sampai).
- [ ] Email "pesanan diterima" dan "pembayaran berhasil" masuk ke inbox.
- [ ] Scheduler berjalan: `docker compose -f compose.staging.yaml logs scheduler` menampilkan
      `ExpirePendingOrders` tiap menit. Pesanan yang tidak dibayar menjadi **EXPIRED** setelah 24 jam
      dan stoknya kembali; cek keesokan harinya.
- [ ] Pembatalan pesanan PAID membuat refund PENDING di admin.
- [ ] `/horizon` di domain API hanya bisa dibuka setelah login sebagai admin.
- [ ] Login admin 5× dengan password salah: percobaan ke-6 ditolak (429) selama 15 menit.

---

## 6a. VPS kecil (1 vCPU / 2 GB RAM)

Cukup untuk staging dengan sedikit pengguna (uji internal/UAT), asalkan:

- **Jangan build image di VPS.** `npm ci` + Vite dan `composer install` bisa memakan >1 GB RAM dan
  menghentikan layanan lain. Build di CI/laptop, push ke registry, VPS hanya `docker pull`.
- **Tambahkan swap 2 GB** sebagai pengaman lonjakan memori (bukan pengganti RAM):

  ```bash
  sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
  echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
  ```

- **Batasi memori MySQL**; default MySQL 8.4 terlalu boros untuk 2 GB. Contoh `my.cnf`:

  ```ini
  [mysqld]
  innodb_buffer_pool_size = 256M
  max_connections = 40
  performance_schema = OFF
  ```

- **Batasi Redis**: `maxmemory 128mb` dan `maxmemory-policy noeviction` (sesi dan antrean tidak boleh
  dibuang diam-diam).
- **Worker antrean**: `HORIZON_MAX_PROCESSES=1` cukup untuk staging. FrankenPHP tidak perlu diatur:
  jumlah thread PHP bawaannya 2× jumlah CPU, jadi 2 thread di VPS 1 vCPU.
- **Pasang batas memori per container** agar satu proses bocor tidak menjatuhkan semuanya, misalnya
  `mem_limit: 512m` untuk `api`, `384m` untuk `horizon`, `192m` untuk `scheduler`, `64m` untuk
  `storefront`.
- Bila memungkinkan, pakai **MySQL managed** (atau VPS terpisah). Itu membebaskan ±500 MB dan
  backup jadi urusan penyedia.

Perkiraan pemakaian RAM saat idle:

| Proses | RAM |
|---|---:|
| MySQL 8.4 (sudah dibatasi) | 350–450 MB |
| API FrankenPHP (2 thread) | 150–250 MB |
| Horizon (1 worker) | 100–150 MB |
| Scheduler | 60–100 MB |
| Redis | 20–50 MB |
| nginx storefront + reverse proxy | 40–60 MB |
| Docker + sistem operasi | 250–350 MB |
| **Total** | **±1,0–1,4 GB** |

Yang terasa lambat di 1 vCPU: login (bcrypt), upload gambar besar (encode ulang ke WebP), dan impor
data wilayah pertama kali (beberapa menit). Untuk production, mulai dari **2 vCPU / 4 GB** dengan
database terpisah.

## 7. Deploy berikutnya

1. Pastikan commit sudah lulus CI, lalu build dan push image dengan tag baru.
2. `docker compose run --rm api composer deploy:database`
3. Ganti tag image di compose, lalu `docker compose up -d`. Horizon menyelesaikan job berjalan
   sebelum berhenti (`stop_grace_period`).
4. Ulangi smoke test singkat (health, login, satu checkout sandbox).

Satu kali setelah deploy yang membawa sanitasi gambar (hanya bila database staging berisi gambar
yang diunggah sebelum fitur itu ada):

```bash
docker compose -f compose.staging.yaml run --rm api php artisan gutshoes:sanitize-product-images --dry-run
docker compose -f compose.staging.yaml run --rm api php artisan gutshoes:sanitize-product-images
```

## 8. Rollback

- Kembalikan tag image ke versi sebelumnya, lalu `docker compose up -d`.
- Migrasi tidak di-rollback otomatis; perbaiki maju (forward fix). Backup database dulu bila harus
  membalik migrasi.
- Jangan mengubah status order langsung di database.

## 9. Masalah umum

| Gejala | Penyebab yang paling mungkin |
|---|---|
| Login admin gagal "CSRF token mismatch" (419) | `SESSION_DOMAIN` tidak berisi domain induk, `SANCTUM_STATEFUL_DOMAINS` tidak memuat domain toko, atau situs tidak lewat HTTPS |
| Login pelanggan tidak muncul, console berisi "Refused to load the script" | Image storefront dibuild dengan publishable key instance Clerk lain, atau tanpa build arg kunci |
| API merespons 401 untuk pelanggan yang sudah login | `CLERK_FRONTEND_API_URL` / `CLERK_AUTHORIZED_PARTIES` tidak sama dengan instance & domain toko |
| Gambar produk 404 | Volume `storage/app/public` tidak terpasang, atau `APP_URL` salah |
| Pesanan tetap PENDING setelah bayar | Notification URL Midtrans salah, atau container `horizon` mati |
| Pesanan tidak pernah EXPIRED | Container `scheduler` tidak berjalan |
| Semua pengguna kena "Too many requests" bersamaan | `TRUSTED_PROXIES` belum diisi, sehingga semua request terlihat dari IP proxy |
| Container API berhenti saat start dengan pesan Midtrans | `MIDTRANS_DRIVER` bukan `http` atau `MIDTRANS_SERVER_KEY` kosong |

## 10. Sebelum naik ke production

- Ganti semua kunci ke versi live: Clerk `pk_live_`/`sk_live_` (instance production), Midtrans
  production beserta `MIDTRANS_IS_PRODUCTION=true` dan URL production (lihat `.env.production.example`).
- Build ulang image storefront dengan `VITE_BUILD_MODE=production` (wajib `pk_live_`).
- Aktifkan GitHub secret scanning dan push protection di pengaturan repository.
- Pertimbangkan 2FA untuk akun admin.
