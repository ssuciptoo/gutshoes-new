import React from "react";
import Button from "../components/ui/Button";
import Icon from "../components/ui/Icon";
import {formatPolicyDate, returnDays, useStoreInfo, whatsappUrl} from "../lib/storeInfo";

// Halaman informasi toko: kontak, kebijakan privasi, syarat & ketentuan, dan pengembalian.
// Data toko dikelola admin (Pengaturan). Isi kebijakan adalah draf umum; lihat lib/storeInfo.jsx.

function ContactLines({info}) {
  const wa = whatsappUrl(info);
  if (!info.email && !wa && !info.address) {
    return <p>Kontak layanan pelanggan sedang disiapkan. Untuk pertanyaan pesanan, gunakan halaman Lacak pesanan.</p>;
  }
  return (
    <ul className="info-contact">
      {info.email && (
        <li>
          <strong>Email</strong>
          <a href={`mailto:${info.email}`}>{info.email}</a>
        </li>
      )}
      {wa && (
        <li>
          <strong>WhatsApp</strong>
          <a href={wa} target="_blank" rel="noreferrer">{info.whatsapp}</a>
        </li>
      )}
      {info.hours && (
        <li>
          <strong>Jam layanan</strong>
          <span>{info.hours}</span>
        </li>
      )}
      {info.address && (
        <li>
          <strong>Alamat</strong>
          <span>{info.address}</span>
        </li>
      )}
    </ul>
  );
}

const operator = (info) => info.legal_name || "GutShoes";
const contactPhrase = (info) =>
  info.email ? `email ${info.email}` : info.whatsapp ? `WhatsApp ${info.whatsapp}` : "halaman Kontak";

const PAGES = {
  contact: {
    title: "Hubungi kami",
    intro: "Ada pertanyaan soal ukuran, pesanan, atau pengembalian? Tim kami siap membantu.",
    body: (info) => (
      <>
        <ContactLines info={info} />
        <h2>Sebelum menghubungi</h2>
        <p>Siapkan nomor pesanan (contoh: GS-20261123-XXXXXXXX) agar kami bisa membantu lebih cepat. Status pesanan juga bisa kamu cek sendiri di halaman Lacak pesanan.</p>
      </>
    ),
  },
  privacy: {
    title: "Kebijakan privasi",
    intro: (info) => `Halaman ini menjelaskan data apa yang dikumpulkan ${operator(info)} dan bagaimana data itu digunakan.`,
    body: (info) => (
      <>
        <h2>Data yang kami kumpulkan</h2>
        <ul>
          <li>Data akun: nama dan email saat kamu masuk atau mendaftar.</li>
          <li>Data pesanan: nama penerima, nomor telepon, alamat pengiriman, produk, dan nilai transaksi.</li>
          <li>Data teknis seperlunya untuk menjaga keamanan, seperti alamat IP dan catatan aktivitas.</li>
        </ul>
        <p>Kami tidak menyimpan data kartu atau rekening. Pembayaran diproses sepenuhnya oleh Midtrans.</p>
        <h2>Untuk apa data digunakan</h2>
        <ul>
          <li>Memproses pesanan, pembayaran, dan pengiriman.</li>
          <li>Mengirim konfirmasi dan status pesanan lewat email.</li>
          <li>Membantu pertanyaan, pembatalan, dan pengembalian.</li>
          <li>Mencegah penipuan dan menjaga keamanan toko.</li>
        </ul>
        <h2>Pihak yang menerima data</h2>
        <p>Data dibagikan hanya sejauh diperlukan untuk menjalankan layanan: penyedia pembayaran (Midtrans), penyedia layanan login (Clerk), penyedia tarif dan jasa pengiriman, serta penyedia pengiriman email. Kami tidak menjual data pribadi.</p>
        <h2>Penyimpanan di perangkat</h2>
        <p>Browser menyimpan isi keranjang dan referensi pesanan tanpa akun agar kamu bisa melanjutkan belanja. Hapus data situs bila perangkat dipakai bersama orang lain.</p>
        <h2>Hak kamu</h2>
        <p>Kamu dapat meminta salinan, perbaikan, atau penghapusan data pribadi dengan menghubungi kami melalui {contactPhrase(info)}. Data pesanan tertentu tetap kami simpan selama diwajibkan untuk pembukuan.</p>
      </>
    ),
  },
  terms: {
    title: "Syarat & ketentuan",
    intro: "Dengan berbelanja di GutShoes, kamu menyetujui ketentuan berikut.",
    body: () => (
      <>
        <h2>Harga dan stok</h2>
        <p>Harga, stok, ongkir, dan diskon dihitung ulang saat pesanan dibuat. Bila terjadi perbedaan, angka di ringkasan pembayaran yang berlaku.</p>
        <h2>Pembayaran</h2>
        <p>Pesanan harus dibayar sebelum batas waktu yang tertera (umumnya 24 jam). Pesanan yang belum dibayar sampai batas waktu dibatalkan otomatis dan stoknya dikembalikan.</p>
        <h2>Pembatalan</h2>
        <p>Pesanan bisa dibatalkan dari halaman pesanan sebelum mulai diproses. Bila sudah dibayar, dana dikembalikan sesuai kebijakan pengembalian.</p>
        <h2>Pengiriman</h2>
        <p>Estimasi waktu kirim mengikuti layanan kurir yang kamu pilih dan dapat berubah karena kondisi di luar kendali kami. Pastikan alamat dan nomor telepon penerima sudah benar.</p>
        <h2>Perubahan ketentuan</h2>
        <p>Ketentuan ini dapat diperbarui sewaktu-waktu. Versi terbaru selalu tersedia di halaman ini.</p>
      </>
    ),
  },
  returns: {
    title: "Pengembalian & refund",
    intro: "Ukuran kurang pas atau produk bermasalah? Begini cara mengajukannya.",
    body: (info) => (
      <>
        <h2>Syarat pengembalian</h2>
        <ul>
          <li>Diajukan paling lambat {returnDays(info)} hari setelah pesanan diterima.</li>
          <li>Sepatu belum dipakai di luar ruangan, bersih, dan masih lengkap dengan kotak serta label.</li>
          <li>Sertakan nomor pesanan serta foto produk dan kotaknya.</li>
        </ul>
        <h2>Cara mengajukan</h2>
        <p>Hubungi kami melalui {contactPhrase(info)} dengan nomor pesanan dan alasan pengembalian (tukar ukuran, cacat produk, atau salah kirim). Kami akan membalas dengan langkah pengiriman balik.</p>
        <h2>Ongkos kirim pengembalian</h2>
        <p>Produk cacat atau salah kirim: ongkos kirim ditanggung toko. Tukar ukuran atau berubah pikiran: ongkos kirim ditanggung pembeli.</p>
        <h2>Pengembalian dana</h2>
        <p>Setelah produk kami terima dan periksa, dana dikembalikan ke metode pembayaran asal melalui Midtrans. Pesanan yang dibatalkan setelah dibayar juga diproses dengan cara yang sama.</p>
      </>
    ),
  },
};

export const STORE_INFO_PAGES = Object.keys(PAGES);

function StoreInfo({page, navigate}) {
  const info = useStoreInfo();
  const content = PAGES[page] || PAGES.contact;
  const intro = typeof content.intro === "function" ? content.intro(info) : content.intro;
  return (
    <main className="info-page">
      <button className="back-link" onClick={() => navigate("home")}>
        <Icon name="back" /> Kembali ke toko
      </button>
      <h1>{content.title}</h1>
      <p className="info-intro">{intro}</p>
      <article className="info-body">{content.body(info)}</article>
      {info.policy_updated && page !== "contact" && (
        <p className="info-updated">Terakhir diperbarui: {formatPolicyDate(info.policy_updated)}</p>
      )}
      {page !== "contact" && (
        <aside className="info-help">
          <p>Masih ada pertanyaan?</p>
          <Button variant="secondary" onClick={() => navigate("contact")}>Hubungi kami</Button>
        </aside>
      )}
    </main>
  );
}

export default StoreInfo;
