import React from "react";
import logoGutShoes from "../../assets/logo-gutshoes.webp";
import Button from "../../components/ui/Button";
import Icon from "../../components/ui/Icon";
import {useSignInLauncher} from "../../lib/clerk";

const BENEFITS = [
  ["locate", "Simpan alamat pengiriman untuk checkout berikutnya"],
  ["package", "Lihat riwayat dan status semua pesananmu"],
  ["truck", "Lacak pengiriman tanpa mengetik nomor pesanan"],
];

function LoginV2({navigate, returnTo}) {
  const openSignIn = useSignInLauncher();
  return (
    <main className="auth-login">
      <section className="login-panel" aria-labelledby="login-title">
        <button className="back-link" onClick={() => navigate("home")}>
          <Icon name="back" /> Kembali ke toko
        </button>
        <img src={logoGutShoes} alt="" width="600" height="152" decoding="async" />
        <h1 id="login-title">Masuk ke GutShoes</h1>
        <p>Masuk dengan akun Google atau email. Belum punya akun? Akun baru dibuat dalam satu langkah.</p>
        <Button
          className="google-button"
          onClick={() => openSignIn?.(returnTo)}
          disabled={!openSignIn}
        >
          <Icon name="user" />
          Masuk atau daftar
        </Button>
        <small>
          {openSignIn
            ? "Pilih cara masuk di jendela yang terbuka. Setelah berhasil, kamu langsung melanjutkan ke halaman yang dituju."
            : "Login akun sedang tidak tersedia. Kamu tetap bisa belanja tanpa akun."}
        </small>
        <div className="login-guest">
          <span>Belum ingin membuat akun?</span>
          <button className="text-link" onClick={() => navigate("catalog")}>
            Belanja tanpa akun
          </button>
        </div>
      </section>
      <section className="login-copy" aria-labelledby="login-benefits-title">
        <h2 id="login-benefits-title">Satu akun untuk semua pesananmu.</h2>
        <ul className="login-benefits">
          {BENEFITS.map(([icon, text]) => (
            <li key={icon}>
              <span aria-hidden="true">
                <Icon name={icon} />
              </span>
              {text}
            </li>
          ))}
        </ul>
      </section>
    </main>
  );
}

export default LoginV2;
