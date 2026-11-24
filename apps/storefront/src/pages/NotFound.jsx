import React from "react";
import Button from "../components/ui/Button";
import Icon from "../components/ui/Icon";

// Alamat yang tidak dikenali router (salah ketik, tautan kedaluwarsa).
function NotFound({navigate}) {
  return (
    <main className="cart-page">
      <div className="empty">
        <Icon name="search" size={36} />
        <h2>Halaman tidak ditemukan</h2>
        <p>Alamat yang kamu buka tidak tersedia atau sudah dipindahkan.</p>
        <div className="empty-actions">
          <Button onClick={() => navigate("home")}>Ke beranda</Button>
          <Button variant="secondary" onClick={() => navigate("catalog")}>Lihat semua sepatu</Button>
        </div>
      </div>
    </main>
  );
}

export default NotFound;
