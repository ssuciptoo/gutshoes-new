# Manual Admin GutShoes

Panduan memakai panel admin GutShoes untuk tim operasional toko: mengelola produk, stok, pesanan, promosi, dan pengaturan toko.

> Berlaku untuk GutShoes versi 1 (V1). Fitur refund di aplikasi belum aktif; lihat [Refund](#13-refund).

## Daftar isi

1. [Masuk dan keluar](#1-masuk-dan-keluar)
2. [Mengenal tampilan panel](#2-mengenal-tampilan-panel)
3. [Rutinitas harian](#3-rutinitas-harian)
4. [Ikhtisar](#4-ikhtisar)
5. [Menyiapkan produk baru (urutan kerja)](#5-menyiapkan-produk-baru-urutan-kerja)
6. [Merek & kategori](#6-merek--kategori)
7. [Ukuran](#7-ukuran)
8. [Produk](#8-produk)
9. [Stok & gudang](#9-stok--gudang)
10. [Pesanan](#10-pesanan)
11. [Pelanggan](#11-pelanggan)
12. [Promosi](#12-promosi)
13. [Refund](#13-refund)
14. [Pengaturan](#14-pengaturan)
15. [Audit log](#15-audit-log)
16. [Pertanyaan umum dan pemecahan masalah](#16-pertanyaan-umum-dan-pemecahan-masalah)
17. [Istilah](#17-istilah)

---

## 1. Masuk dan keluar

**Alamat panel:** `https://<domain-toko>/admin`

1. Buka alamat panel admin.
2. Isi **Email admin** dan **Kata sandi**, lalu pilih **Masuk ke admin panel**.
3. Setelah berhasil, halaman **Ikhtisar** terbuka.

Catatan penting:

- Akun admin **terpisah** dari akun pelanggan. Pelanggan masuk lewat tombol login di toko; admin masuk hanya lewat `/admin` dengan email dan kata sandi.
- Akun admin tidak bisa didaftarkan dari halaman web. Akun baru dibuat oleh tim teknis lewat server (perintah `php artisan gutshoes:create-admin`). Minta ke tim teknis bila perlu akun baru atau lupa kata sandi.
- Jika sesi habis atau dicabut, panel otomatis kembali ke form login. Masuk lagi untuk melanjutkan; perubahan yang belum disimpan pada form yang sedang terbuka perlu diisi ulang.
- Selalu keluar (logout) bila memakai komputer bersama.

## 2. Mengenal tampilan panel

Menu di sisi kiri dikelompokkan sebagai berikut:

| Kelompok | Menu | Kegunaan |
|---|---|---|
| Ruang kerja | **Ikhtisar** | Ringkasan penjualan dan pekerjaan yang menunggu tindakan |
| Katalog | **Produk** | Produk, varian (ukuran, SKU, harga, berat), gambar, dan status publikasi |
| | **Merek & kategori** | Merek dan kategori yang membentuk navigasi toko |
| | **Ukuran** | Daftar ukuran yang bisa dipilih saat membuat varian |
| | **Stok & gudang** | Jumlah stok per varian dan riwayat pergerakannya |
| Penjualan | **Pesanan** | Memproses pesanan sampai diterima pelanggan |
| | **Pelanggan** | Data pelanggan, alamat, dan riwayat belanja |
| | **Promosi** | Voucher dan promosi produk |
| Sistem | **Pengaturan** | Informasi toko, ambang stok rendah, alamat gudang |
| | **Audit log** | Jejak semua perubahan yang dilakukan admin |

Angka di samping menu **Pesanan** menunjukkan jumlah pesanan yang perlu ditangani.

Di layar ponsel, menu kiri dibuka lewat tombol **Buka menu** di bagian atas.

## 3. Rutinitas harian

Urutan kerja yang disarankan setiap hari:

1. **Buka Ikhtisar.** Lihat kartu *Antrean operasional*: pesanan lunas yang belum dikirim, pesanan lewat batas bayar, dan stok rendah.
2. **Proses pesanan lunas.** Buka **Pesanan**, pilih filter **Lunas**, lalu proses satu per satu (lihat [Memproses pesanan](#104-memproses-pesanan)).
3. **Kirim pesanan yang sudah dikemas.** Filter **Diproses**, masukkan nomor resi dari kurir.
4. **Tandai pesanan yang sudah sampai.** Filter **Dikirim**, cek status di situs kurir, lalu tandai *sudah diterima*.
5. **Periksa stok rendah.** Buka **Stok & gudang**, filter **Stok rendah**, dan catat barang yang perlu dipesan ulang.

## 4. Ikhtisar

Halaman pertama setelah login.

- **Ringkasan bisnis**: pesanan hari ini, pendapatan 30 hari, jumlah pesanan yang menunggu pembayaran, dan jumlah varian stok rendah.
- **Antrean operasional**: pekerjaan yang menunggu tindakan tim, misalnya *Pesanan lunas belum dikirim* dan *Lewat batas bayar*. Pilih tombol di kartu untuk langsung membuka daftar yang relevan.
- **Pesanan terbaru**: lima pesanan terakhir yang masuk.
- Jika muncul pengingat **Lengkapi informasi toko**, buka **Pengaturan** dan isi email layanan pelanggan serta nama badan usaha. Data ini tampil di footer dan halaman kebijakan toko.

## 5. Menyiapkan produk baru (urutan kerja)

Produk baru membutuhkan data dari beberapa menu. Kerjakan berurutan:

1. **Merek & kategori** — pastikan merek dan kategori (aktivitas, mis. Lari, Basket) sudah ada.
2. **Ukuran** — pastikan ukuran yang dijual sudah terdaftar.
3. **Produk** — buat produk, isi varian per ukuran (SKU, harga, berat), unggah gambar. Simpan sebagai **Draf**.
4. **Stok & gudang** — daftarkan stok setiap varian di gudang beserta jumlah stok awal.
5. **Produk** — buka lagi produknya, pilih **Publikasikan**. Produk kini tampil di toko.

> Produk yang dipublikasikan tanpa stok tetap tampil, tetapi semua ukurannya berlabel *Habis* dan tidak bisa dibeli.

## 6. Merek & kategori

Halaman **Merek & kategori** berisi dua bagian: **Kategori** dan **Merek**.

**Menambah atau mengubah:**

1. Di bagian yang sesuai, pilih tombol tambah, atau pilih baris yang ingin diubah.
2. Isi **Nama**. **Slug** (bagian alamat web) terisi otomatis; ubah hanya bila perlu.
3. Untuk kategori, **Kategori induk** boleh dikosongkan (*Tanpa kategori induk*).
4. Atur **Aktif**, lalu simpan.

Kategori dipakai sebagai menu aktivitas di toko dan filter katalog, mis. `/catalog?filter=lari`.

> Hindari mengganti slug kategori yang sudah dipakai: tautan lama dan hasil pencarian Google yang mengarah ke kategori itu akan berubah.

## 7. Ukuran

Daftar ukuran yang bisa dipilih saat membuat varian produk.

| Kolom | Isi |
|---|---|
| **Sistem ukuran** | mis. `EU` |
| **Nilai** | Ikuti label di kotak sepatu, mis. `42`, `42½`, atau `41⅓` |
| **Label tampilan** | Teks pada tombol ukuran di toko dan halaman Panduan ukuran, mis. `EU 42` |
| **Panjang kaki** | Opsional, dalam cm, mis. `26.5` |
| **Nilai urut** | Kosongkan agar dihitung otomatis dari nilai (41 1/3 → 41.33) |

- Ukuran yang **sedang dipakai** oleh varian produk tidak bisa dihapus. Kolom *Penggunaan* menunjukkan apakah ukuran sudah dipakai.

## 8. Produk

### 8.1 Daftar produk

- Cari berdasarkan nama, slug, merek, atau SKU.
- Filter status: **Draf**, **Dipublikasikan**, **Diarsipkan**.

| Status | Arti |
|---|---|
| **Draf** | Belum tampil di toko. Status awal produk baru. |
| **Dipublikasikan** | Tampil dan bisa dibeli di toko. |
| **Diarsipkan** | Disembunyikan dari toko, data tetap tersimpan. Pakai ini untuk produk yang berhenti dijual. |

### 8.2 Membuat atau mengubah produk

1. Pilih **Tambah produk** (atau pilih produk di daftar).
2. Isi data utama:
   - **Nama produk** — slug terisi otomatis.
   - **Merek** — wajib dipilih.
   - **Kategori** — centang satu atau lebih; kategori baru bisa ditambah langsung dari sini.
   - **Deskripsi** — informasi bahan, penggunaan, dan detail yang terverifikasi. Deskripsi juga dipakai sebagai cuplikan di Google dan pratinjau link WhatsApp/Facebook.
3. Isi **Varian produk** — satu baris per ukuran. Setiap ukuran harus memiliki:
   - **Ukuran**, **SKU** (kode unik), **Harga** (Rp), **Berat (gram)** — berat dipakai untuk menghitung ongkir.
   - **Varian aktif** — matikan untuk berhenti menjual satu ukuran tanpa menghapusnya.
   - Pilih **Tambah varian** untuk ukuran berikutnya.
4. Unggah **Gambar produk**:
   - Format JPEG, PNG, atau WebP; 300–4000 px; maksimal 5 MB per gambar.
   - Disarankan foto persegi (1:1), 1080 × 1080 px. Rasio lain tetap tampil utuh dengan latar blur.
   - Gambar bertanda **Utama** tampil di daftar produk dan pratinjau link.
5. Pilih **Simpan produk**. Produk baru tersimpan sebagai **Draf**.

### 8.3 Mempublikasikan produk

Pilih **Publikasikan** di editor produk. Syarat:

- minimal **satu varian aktif**, dan
- minimal **satu gambar**.

Jika syarat belum terpenuhi, muncul pesan *Produk belum dapat dipublikasikan*.

### 8.4 Menghapus atau mengarsipkan

- **Arsipkan** produk yang berhenti dijual (ubah **Status** menjadi *Diarsipkan*). Ini cara yang disarankan.
- **Hapus produk** hanya untuk produk yang dibuat keliru. Sistem meminta konfirmasi *Ya, hapus*.
- Pesanan lama tidak terpengaruh: detail pesanan menyimpan salinan (snapshot) nama, ukuran, dan harga produk saat dibeli.

> **Jangan mengganti slug produk yang sudah dipublikasikan.** Tautan yang sudah dibagikan atau terindeks Google akan mengarah ke halaman "tidak ditemukan".

## 9. Stok & gudang

### 9.1 Membaca angka stok

Setiap baris adalah satu varian (produk + ukuran) di satu gudang.

| Kolom | Arti |
|---|---|
| **Fisik** | Jumlah barang yang benar-benar ada di gudang |
| **Dicadangkan** | Ditahan untuk pesanan yang **belum dibayar** |
| **Terjual** | Jumlah yang sudah terjual (pesanan lunas) |
| **Tersedia** | Yang masih bisa dibeli pelanggan = Fisik − Dicadangkan |

Filter cepat: **Stok rendah**, **Habis**, **Tersedia**. Batas "stok rendah" diatur di [Pengaturan](#14-pengaturan) (bawaan 5 unit).

### 9.2 Bagaimana stok berubah otomatis

Stok bergerak sendiri mengikuti pesanan; admin **tidak perlu** mengubahnya manual untuk pesanan:

| Kejadian | Fisik | Dicadangkan | Terjual |
|---|---|---|---|
| Pelanggan membuat pesanan | tetap | **+ jumlah** | tetap |
| Pembayaran Midtrans terkonfirmasi (pesanan **Lunas**) | **− jumlah** | **− jumlah** | **+ jumlah** |
| Pesanan dibatalkan / lewat batas bayar sebelum dibayar | tetap | **− jumlah** (kembali tersedia) | tetap |

Contoh: stok fisik 8. Pelanggan memesan 1 → *Dicadangkan 1, Tersedia 7*. Pembayaran lunas → *Fisik 7, Dicadangkan 0, Terjual 1*.

> Stok berubah menjadi **terjual** hanya saat pembayaran dikonfirmasi oleh Midtrans. Mengubah status pesanan langsung di database **tidak** memotong stok dan membuat angka tidak sinkron. Lihat [FAQ](#16-pertanyaan-umum-dan-pemecahan-masalah).

### 9.3 Mendaftarkan stok varian baru

Dilakukan sekali untuk setiap varian di setiap gudang.

1. Pilih **Daftarkan stok**.
2. **Lokasi dan varian**: pilih **Gudang** dan **Produk dan ukuran**.
3. **Jumlah dan pencatatan**: isi **Stok awal** (gunakan 0 bila hanya mendaftarkan lokasi) dan **Alasan pencatatan**, mis. *penerimaan awal dari supplier*.
4. Pilih **Daftarkan stok varian**.

Setiap varian hanya dapat didaftarkan **satu kali per gudang**.

### 9.4 Menambah atau mengurangi stok

Untuk barang masuk dari supplier, hasil stock opname, atau barang rusak:

1. Pilih baris stok, lalu **Tambah stok** / ubah stok.
2. Isi **Perubahan jumlah**: angka positif untuk menambah (`+10`), negatif untuk mengurangi (`-3`).
3. Isi **Alasan perubahan**, mis. *penerimaan supplier* atau *stock opname*. Alasan wajib dan tercatat untuk audit.
4. Pilih **Simpan perubahan**.

Stok tidak bisa dikurangi sampai di bawah jumlah yang sedang dicadangkan untuk pesanan.

### 9.5 Riwayat stok (movement)

Setiap perubahan tercatat dan tidak bisa diubah. Jenis yang muncul:

| Jenis | Arti |
|---|---|
| **Stok masuk** | Stok awal atau penambahan |
| **Penyesuaian** | Perubahan manual oleh admin (plus/minus) |
| **Dipesan** | Dicadangkan untuk pesanan baru |
| **Reservasi dilepas** | Pesanan batal / lewat batas bayar, stok kembali tersedia |
| **Terjual** | Pesanan dibayar |
| **Dikembalikan** | Pesanan lunas dibatalkan, stok kembali |

### 9.6 Menghapus record stok

**Hapus record stok** hanya bisa dilakukan bila stok fisik, dicadangkan, dan terjual masih 0 **dan** belum ada riwayat pergerakan. Record yang sudah punya riwayat tidak bisa dihapus; nolkan stoknya dan biarkan tersimpan untuk keperluan audit.

### 9.7 Alamat gudang (asal pengiriman)

Diatur di **Pengaturan → Asal pengiriman**. Alamat ini dipakai untuk menghitung ongkir dan sebagai alamat pengirim. Lihat [Pengaturan](#14-pengaturan).

## 10. Pesanan

### 10.1 Status pesanan

| Status | Arti | Siapa yang mengubah |
|---|---|---|
| **Menunggu pembayaran** | Pesanan dibuat, pelanggan belum membayar. Stok sudah dicadangkan. | Sistem |
| **Lunas** | Pembayaran dikonfirmasi Midtrans. Stok sudah terjual. Siap diproses. | Sistem (Midtrans) |
| **Diproses** | Sedang dikemas tim gudang. | Admin |
| **Dikirim** | Sudah diserahkan ke kurir; nomor resi tersimpan. | Admin |
| **Diterima** | Paket sudah sampai di pelanggan. | Admin |
| **Dibatalkan** | Dibatalkan oleh pelanggan atau admin. | Admin / pelanggan / sistem |
| **Kedaluwarsa** | Tidak dibayar sampai batas waktu (24 jam). Stok dilepas otomatis. | Sistem |

Alur normal:

```
Menunggu pembayaran → Lunas → Diproses → Dikirim → Diterima
        │
        └─ (tidak dibayar 24 jam) → Kedaluwarsa
```

> **Admin tidak bisa menandai pesanan "Lunas".** Status Lunas hanya datang dari konfirmasi Midtrans. Ini mencegah pesanan diproses tanpa uang yang benar-benar diterima.

### 10.2 Daftar pesanan

- Cari berdasarkan nomor pesanan, nama, atau email.
- Filter status: **Lewat batas bayar**, **Lunas**, **Diproses**, **Dikirim**, **Diterima**, **Dibatalkan**, **Kedaluwarsa**.
- Pilih baris untuk membuka **Detail pesanan**.

### 10.3 Detail pesanan

Panel detail berisi:

- **Pelanggan** — nama, email, telepon.
- **Produk & snapshot** — item, ukuran, jumlah, harga saat dibeli.
- **Alamat pengiriman** — salinan alamat saat checkout (tidak berubah bila pelanggan mengedit alamatnya kemudian).
- **Pengiriman** — kurir, layanan, dan **nomor resi**.
- **Pembayaran** — penyedia (Midtrans), status, waktu dibayar, ID transaksi.
- **Riwayat status** — setiap perubahan status, waktu, dan pelakunya (Sistem atau Admin).
- **Aksi operasional** — tombol untuk langkah berikutnya.

### 10.4 Memproses pesanan

Tombol di **Aksi operasional** hanya menampilkan langkah yang sah untuk status saat ini.

**a. Lunas → Diproses**

1. Buka pesanan berstatus **Lunas**.
2. Pilih **Mulai proses pesanan**.
3. Isi **Catatan operasional** bila perlu (opsional; boleh tampil di riwayat pesanan pelanggan).
4. Pilih **Konfirmasi**.

**b. Diproses → Dikirim**

1. Setelah paket diserahkan ke kurir, buka pesanan berstatus **Diproses**.
2. Pilih **Masukkan resi & kirim**.
3. Isi **Nomor resi** persis seperti dari kurir.
4. Pilih **Konfirmasi**. Pelanggan menerima email pembaruan pesanan, dan nomor resi tampil di detail pesanan serta halaman **Lacak pesanan** di toko.

**c. Dikirim → Diterima**

1. Pastikan paket sudah sampai (cek di situs kurir).
2. Buka pesanan berstatus **Dikirim**, pilih **Tandai sudah diterima**, lalu **Konfirmasi**.

> Periksa nomor resi sebelum konfirmasi. Status tidak bisa dimundurkan dari panel.

### 10.5 Membatalkan pesanan

- Pesanan **Menunggu pembayaran** bisa dibatalkan: pilih **Batalkan pesanan**, isi **Alasan pembatalan**, lalu **Konfirmasi**. Stok yang dicadangkan langsung kembali tersedia dan pelanggan menerima email pembatalan.
- Pesanan **Lunas** tidak bisa dibatalkan dari panel di V1 karena fitur refund belum aktif. Tangani di luar aplikasi bersama tim keuangan (lihat [Refund](#13-refund)).
- Pesanan yang tidak dibayar akan **kedaluwarsa otomatis** setelah 24 jam; tidak perlu dibatalkan manual.

### 10.6 Lewat batas bayar

Filter **Lewat batas bayar** menampilkan pesanan yang belum dibayar dan sudah melewati batas waktu, tetapi belum ditutup sistem. Biasanya sistem menutupnya dalam beberapa menit. Bila daftar ini tidak berkurang, hubungi tim teknis (kemungkinan proses latar belakang/scheduler berhenti).

### 10.7 Email otomatis ke pelanggan

| Kejadian | Email |
|---|---|
| Pesanan dibuat | Pesanan menunggu pembayaran |
| Pembayaran berhasil | Pembayaran berhasil |
| Tidak dibayar sampai batas waktu | Pembayaran kedaluwarsa |
| Pesanan dibatalkan | Pesanan dibatalkan |
| Admin menandai **Dikirim** | Pembaruan pesanan (nomor resi dilihat pelanggan di detail pesanan / Lacak pesanan) |
| Admin menandai **Diterima** | Pembaruan pesanan |

Admin tidak perlu mengirim email ini manual.

## 11. Pelanggan

- Cari berdasarkan nama atau email; filter **Email terverifikasi** / **Belum verifikasi**.
- Pilih pelanggan untuk melihat **Ringkasan** (bergabung, jumlah pesanan, total belanja terbayar), **Kontak**, **Alamat**, dan **Pesanan terakhir**.
- Halaman ini hanya untuk melihat data. Akun pelanggan dikelola oleh pelanggan sendiri (login lewat Clerk); admin tidak mengubah email atau kata sandi pelanggan.
- Pelanggan yang belanja **tanpa akun** tidak muncul di daftar ini; pesanannya tetap ada di menu **Pesanan**.

> Data pelanggan bersifat pribadi. Jangan menyalin atau membagikannya di luar keperluan melayani pesanan.

## 12. Promosi

Menu **Promosi** punya dua tab.

### 12.1 Voucher

Kode yang diketik pelanggan saat checkout.

| Kolom | Keterangan |
|---|---|
| **Kode voucher** | Huruf kapital tanpa spasi, mis. `CONTOH10` |
| **Jenis potongan** | **Persentase (%)**, **Potongan nominal (Rp)**, atau **Gratis ongkir** |
| **Besar potongan** | Persen atau rupiah, sesuai jenis |
| **Minimum belanja (Rp)** | Total belanja minimal agar voucher berlaku |
| **Maksimum potongan (Rp)** | Batas atas potongan, terutama untuk jenis persentase |
| **Kuota total** | Berapa kali voucher bisa dipakai seluruh pelanggan; kosong = tanpa batas |
| **Kuota per pelanggan** | Batas pemakaian per pelanggan |
| **Mulai berlaku / Berakhir** | Periode aktif |
| **Aktif** | Matikan untuk menghentikan sementara tanpa menghapus |

### 12.2 Promosi produk

Potongan harga otomatis pada produk tertentu, tanpa kode.

| Kolom | Keterangan |
|---|---|
| **Nama promosi** | Nama internal |
| **Jenis diskon** | Persentase atau potongan per pasang (Rp) |
| **Berlaku untuk produk** | Ketik minimal 2 huruf untuk mencari produk. **Kosongkan untuk berlaku di semua produk.** |
| **Prioritas** | Bila beberapa promosi berlaku, angka lebih besar diterapkan lebih dulu |
| **Mulai berlaku / Berakhir**, **Aktif** | Sama seperti voucher |

### 12.3 Status promosi

| Status | Arti |
|---|---|
| **Aktif** | Sedang berlaku |
| **Terjadwal** | Aktif, tetapi tanggal mulai belum tiba |
| **Berakhir** | Lewat tanggal berakhir |
| **Nonaktif** | Dimatikan admin |

Menghapus voucher membuat pelanggan tidak bisa lagi memakai kodenya. Menghapus promosi produk membuat harga produk kembali normal. Untuk penghentian sementara, cukup matikan **Aktif**.

> Harga, ongkir, dan diskon selalu dihitung ulang oleh server saat pesanan dibuat, jadi perubahan promosi langsung berlaku untuk checkout berikutnya.

## 13. Refund

Di V1, fitur refund **belum aktif** dan menu **Refund** tidak tampil.

- Pesanan yang sudah **Lunas** tidak bisa dibatalkan dari panel.
- Bila pelanggan meminta pembatalan atau pengembalian dana, tangani **di luar aplikasi** (koordinasi dengan tim keuangan dan dashboard Midtrans), lalu catat keputusannya di tempat yang disepakati tim.
- Pada Ikhtisar mungkin muncul kartu **Refund tertunda** dengan label *Tangani manual di luar aplikasi* — itu pengingat bahwa ada dana pelanggan yang perlu ditindaklanjuti manual.

Ketentuan pengembalian barang untuk pelanggan tampil di halaman **Pengembalian & penukaran** di toko.

## 14. Pengaturan

Simpan semua perubahan dengan tombol **Simpan pengaturan** di bagian bawah. Perubahan disimpan sekaligus; bila ada kolom yang salah, tidak ada yang tersimpan dan pesan kesalahan menyebut kolomnya.

### 14.1 Operasional toko

- **Ambang stok rendah** — varian dengan stok tersedia sama atau di bawah angka ini ditandai *stok rendah* di Ikhtisar dan halaman stok. Bawaan: 5 unit.

### 14.2 Informasi toko

Tampil di footer serta halaman **Hubungi kami**, **Kebijakan privasi**, dan **Pengembalian**. Kolom yang kosong tidak ditampilkan.

| Kolom | Contoh |
|---|---|
| Nama badan usaha | PT GutShoes Indonesia |
| Email layanan pelanggan | halo@gutshoes.id |
| Nomor WhatsApp | 0812-3456-7890 (otomatis jadi tautan WhatsApp) |
| Alamat toko | Alamat lengkap |
| Jam layanan | Senin–Jumat, 09.00–17.00 WIB |
| Batas pengajuan retur | 7 hari (kosong = 7 hari) |
| Tanggal kebijakan diperbarui | Tanggal terakhir kebijakan direvisi |

Email dan WhatsApp juga dipakai sebagai kontak toko di data mesin pencari (Google).

### 14.3 Footer & versi

- **Teks hak cipta** — tulis `{tahun}` untuk tahun berjalan, mis. `© {tahun} PT GutShoes Indonesia. Hak cipta dilindungi.` Kosong = © tahun + nama badan usaha.
- **Versi aplikasi** — mis. `v1.0.0`. Kosong = versi tidak ditampilkan.

### 14.4 Asal pengiriman (gudang)

Alamat gudang dipakai untuk menghitung ongkir dan sebagai alamat pengirim.

1. Isi **Nama gudang**, **Telepon**, **Alamat lengkap**.
2. Pilih **Provinsi**, **Kota / kabupaten**, **Kecamatan**, **Kelurahan / desa**; **Kode pos** terisi.
3. **ID area kurir** — kode lokasi gudang di penyedia ongkir. Isi sesuai arahan tim teknis.
4. Pilih **Simpan asal pengiriman**.

> Mengubah alamat gudang langsung memengaruhi ongkir semua checkout berikutnya. Uji satu checkout setelah mengubahnya.

## 15. Audit log

Mencatat setiap tindakan admin yang mengubah data: siapa, kapan, apa yang diubah.

- Filter **Tindakan**: *Tambah / proses*, *Ubah*, *Ubah sebagian*, *Hapus*, *Batalkan pesanan*.
- Cari berdasarkan objek, mis. `products` atau `orders`.
- Atur **Rentang tanggal** untuk mempersempit hasil.

Gunakan audit log untuk menelusuri perubahan yang tidak terduga, mis. harga yang berubah atau pesanan yang dibatalkan. Catatan audit tidak bisa diubah atau dihapus dari panel.

## 16. Pertanyaan umum dan pemecahan masalah

**Kapan stok berubah menjadi "terjual"?**
Saat pembayaran dikonfirmasi Midtrans dan pesanan menjadi **Lunas**. Sebelum itu stok hanya *dicadangkan*. Lihat [9.2](#92-bagaimana-stok-berubah-otomatis).

**Pelanggan bilang sudah bayar, tetapi pesanan masih "Menunggu pembayaran".**
Konfirmasi Midtrans biasanya instan. Sistem juga mengecek ulang pembayaran yang tertunda ke Midtrans setiap beberapa menit, jadi tunggu sekitar 10 menit. Jika masih belum berubah, cek transaksinya di dashboard Midtrans dengan nomor pesanan, lalu hubungi tim teknis. **Jangan** mengubah status di database: stok tidak akan terpotong dan data menjadi tidak sinkron.

**Bolehkah mengubah status pesanan langsung di database?**
Tidak. Selalu gunakan panel admin atau alur Midtrans. Perubahan langsung melewati pencatatan stok, email ke pelanggan, dan audit log.

**Produk tidak muncul di toko.**
Periksa: status **Dipublikasikan**, ada varian **aktif**, ada **gambar**, merek dan kategori aktif. Jika produk tampil tetapi semua ukuran *Habis*, daftarkan atau tambah stok di **Stok & gudang**.

**Tombol Publikasikan gagal.**
Pastikan ada minimal satu varian aktif dan satu gambar tersimpan.

**Ukuran tidak bisa dihapus.**
Ukuran itu masih dipakai oleh varian produk. Ubah atau nonaktifkan variannya terlebih dahulu.

**Record stok tidak bisa dihapus.**
Record yang sudah pernah punya stok atau riwayat pergerakan disimpan untuk audit. Nolkan stoknya lewat penyesuaian.

**Pelanggan tidak menerima email.**
Status pesanan tetap benar walau email gagal terkirim. Minta pelanggan mengecek folder spam, lalu laporkan ke tim teknis bila banyak email yang gagal.

**Tiba-tiba kembali ke halaman login.**
Sesi admin habis. Masuk lagi; isi ulang form yang belum tersimpan.

**Ingin membatalkan pesanan yang sudah Lunas.**
Belum bisa dari panel di V1. Lihat [Refund](#13-refund).

## 17. Istilah

| Istilah | Arti |
|---|---|
| **SKU** | Kode unik per varian (produk + ukuran), mis. `GS-STRIDE-39` |
| **Varian** | Satu ukuran dari sebuah produk, dengan harga, berat, dan stok sendiri |
| **Slug** | Bagian alamat web produk/kategori, mis. `stride-flow` pada `/product/stride-flow` |
| **Dicadangkan (reserved)** | Stok yang ditahan untuk pesanan yang belum dibayar |
| **Snapshot** | Salinan data produk dan alamat saat pesanan dibuat; tidak ikut berubah bila data asli diubah |
| **Movement** | Catatan setiap perubahan stok |
| **Resi** | Nomor pelacakan paket dari kurir |
| **Midtrans** | Penyedia pembayaran online yang mengonfirmasi pembayaran pelanggan |
| **Audit log** | Catatan permanen tindakan admin |
