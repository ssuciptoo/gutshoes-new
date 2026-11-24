import React from "react";
import logoGutShoes from "../../assets/logo-gutshoes.webp";
import {copyrightText, useStoreInfo, whatsappUrl} from "../../lib/storeInfo";
import {RETURNS_TITLE} from "../../lib/features";

function Footer() {
  const info = useStoreInfo();
  const wa = whatsappUrl(info);
  const copyright = copyrightText(info);
  return (
    <footer>
      <div>
        <img src={logoGutShoes} alt="GutShoes" width="600" height="152" loading="lazy" decoding="async" />
        <p>
          Sepatu olahraga dengan informasi harga dan ukuran yang mudah dipahami.
        </p>
        {(info.email || wa) && (
          <p className="footer-contact">
            {info.email && <a href={`mailto:${info.email}`}>{info.email}</a>}
            {wa && <a href={wa} target="_blank" rel="noreferrer">WhatsApp {info.whatsapp}</a>}
          </p>
        )}
      </div>
      <div>
        <strong>Belanja</strong>
        <a href="/catalog">Semua sepatu</a>
        <a href="/catalog?filter=lari">Lari</a>
        <a href="/catalog?filter=training">Training</a>
      </div>
      <div>
        <strong>Bantuan</strong>
        <a href="/tracking">Lacak pesanan</a>
        <a href="/size-guide">Panduan ukuran</a>
        <a href="/returns">{RETURNS_TITLE}</a>
        <a href="/contact">Hubungi kami</a>
        <a href="/guest-orders">Pesanan tanpa akun</a>
      </div>
      <div>
        <strong>Akun & kebijakan</strong>
        <a href="/orders">Riwayat pesanan</a>
        <a href="/profile">Profil</a>
        <a href="/terms">Syarat & ketentuan</a>
        <a href="/privacy">Kebijakan privasi</a>
      </div>
      {(copyright || info.version) && (
        <p className="footer-legal">
          {copyright && <span>{copyright}</span>}
          {info.version && <span className="footer-version">Versi {info.version}</span>}
        </p>
      )}
    </footer>
  );
}

export default Footer;
