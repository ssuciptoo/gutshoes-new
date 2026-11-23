import React from "react";
import logoGutShoes from "../../assets/logo-gutshoes.webp";

function Footer() {
  return (
    <footer>
      <div>
        <img src={logoGutShoes} alt="GutShoes" width="600" height="152" loading="lazy" decoding="async" />
        <p>
          Sepatu olahraga dengan informasi harga dan ukuran yang mudah dipahami.
        </p>
      </div>
      <div>
        <strong>Belanja</strong>
        <a href="#catalog">Semua sepatu</a>
        <a href="#catalog?filter=lari">Lari</a>
        <a href="#catalog?filter=training">Training</a>
      </div>
      <div>
        <strong>Bantuan</strong>
        <a href="#guest-orders">Pesanan terakhir saya</a>
        <a href="#tracking">Lacak pesanan</a>
      </div>
      <div>
        <strong>Akun</strong>
        <a href="#orders">Riwayat & pembatalan pesanan</a>
        <a href="#addresses">Alamat saya</a>
        <a href="#profile">Profil</a>
      </div>
    </footer>
  );
}

export default Footer;
