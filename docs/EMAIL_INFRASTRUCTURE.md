# Infrastruktur Email GutShoes

## Keputusan environment

| Environment | Provider | Transport |
| --- | --- | --- |
| Development | Brevo | Laravel SMTP |
| Staging | Brevo | Laravel SMTP |
| Production | Ditentukan sebelum go-live | Laravel SMTP/API sesuai provider |

Seluruh email dikirim melalui Laravel Mail dari SendOrderEmail. Domain order, payment, shipping, dan recovery guest tidak mengetahui provider aktif. Pergantian provider cukup melalui environment variable.

## Brevo SMTP development dan staging

Gunakan SMTP key dari Brevo, bukan password login akun Brevo. Sender email harus sudah ditambahkan dan diverifikasi di dashboard Brevo.

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=alamat-email-akun-brevo
MAIL_PASSWORD=brevo-smtp-key
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@domain-anda.com
MAIL_FROM_NAME="GutShoes Staging"
```

SMTP key disimpan sebagai secret environment di komputer lokal atau hosting. Jangan commit key ke repository dan jangan mencatatnya ke log.

Jika belum memiliki domain sendiri, sender email Brevo dapat diverifikasi untuk smoke test. Untuk staging online dan production, lebih baik gunakan domain sendiri agar SPF, DKIM, DMARC, dan reputasi pengiriman dapat dikontrol.

Autentikasi domain dilakukan di Brevo melalui menu domain. Gunakan domain atau subdomain khusus email, misalnya `mail.gutshoes.id`, lalu pasang record DNS SPF/DKIM/DMARC yang diberikan Brevo.

Domain yang dipakai pada `MAIL_FROM_ADDRESS` harus sama dengan domain sender yang telah diverifikasi.

## Queue dan pengiriman

Job `SendOrderEmail` menggunakan queue. Untuk development dan staging awal, queue database dapat digunakan:

```dotenv
QUEUE_CONNECTION=database
```

Pastikan worker berjalan di server staging:

```bash
php artisan queue:work database --tries=3
```

Redis dan Horizon dapat digunakan ketika volume meningkat atau ketika diperlukan monitoring queue yang lebih lengkap. Worker harus dijalankan sebagai service yang otomatis restart, bukan hanya melalui terminal interaktif.

## Aturan operasional

1. SMTP key, `APP_KEY`, dan credential provider hanya disimpan di secret environment.
2. Worker queue dan scheduler dijalankan sebagai service di staging dan produksi.
3. Checkout tidak boleh gagal karena email gagal; job email memakai retry dan failed-job monitoring.
4. Pantau email_deliveries, queue gagal, bounce, complaint, dan delivery rate.
5. Jangan mencatat SMTP key, API key, password, atau token recovery guest.
6. Smoke test ke Gmail dan provider lain sebelum deployment dinyatakan sehat.
7. Sebelum production, aktifkan autentikasi domain dan evaluasi webhook/status delivery dari provider yang dipilih.

## Checklist aktivasi

### Development/staging

- akun Brevo tersedia;
- sender email sudah diverifikasi;
- SMTP key masuk secret environment;
- queue worker berjalan;
- email order created, payment success, payment expired, dan recovery guest diterima;
- status `email_deliveries` berubah menjadi `SENT`;
- job gagal tercatat di `failed_jobs` dan dapat di-retry.

### Production

- provider email production sudah dipilih;
- domain pengirim terverifikasi;
- SPF dan DKIM valid, DMARC minimal mode observasi;
- credential provider hanya memiliki izin yang diperlukan;
- sender memakai domain terverifikasi;
- monitoring delivery/bounce/complaint aktif jika disediakan provider;
- alert queue gagal dan email FAILED aktif;
- recovery guest diuji setelah local storage browser dihapus.
