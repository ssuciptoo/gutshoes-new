import React, {useState} from "react";
import {api} from "../../api";
import AccountShell from "../../components/layout/AccountShell";
import Button from "../../components/ui/Button";
import Feedback from "../../components/ui/Feedback";
import Icon from "../../components/ui/Icon";
import {formatAccountDate} from "../../lib/format";

// Nama bawaan untuk akun yang mendaftar lewat email tanpa mengisi nama.
const DEFAULT_NAME = "Pelanggan GutShoes";

function Profile({user, setUser, navigate, onLogout}) {
  const [name, setName] = useState(user.name === DEFAULT_NAME ? "" : user.name);
  const [phone, setPhone] = useState(user.phone);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState("");
  const [error, setError] = useState("");
  const needsName = user.name === DEFAULT_NAME;

  const submit = async (e) => {
    e.preventDefault();
    if (saving) return;
    setSaving(true);
    setSaved("");
    setError("");
    try {
      const response = await api.updateProfile({name: name.trim(), phone: phone.trim()});
      setUser({
        ...user,
        name: name.trim(),
        phone: response.data?.phone ?? phone.trim(),
        updated_at: response.data?.updated_at || new Date().toISOString(),
      });
      setSaved("Perubahan profil tersimpan.");
    } catch (requestError) {
      setError(requestError.message || "Profil belum dapat disimpan. Coba lagi.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <AccountShell
      active="profile"
      navigate={navigate}
      user={user}
      onLogout={onLogout}
    >
      <div className="account-title">
        <div>
          <h2>Profil</h2>
          <p>Informasi kontak untuk pembaruan dan pemenuhan pesanan.</p>
        </div>
        <span className="account-status">
          <Icon name="check" /> Email terverifikasi
        </span>
      </div>
      {needsName && (
        <Feedback type="info">
          Lengkapi nama lengkapmu agar kurir dan tim kami mudah mengenali pesananmu.
        </Feedback>
      )}
      <form className="profile-form" onSubmit={submit} aria-busy={saving}>
        <label>
          Nama lengkap
          <input
            required
            maxLength="100"
            autoComplete="name"
            value={name}
            onChange={(e) => setName(e.target.value)}
          />
        </label>
        <label>
          Email
          <input disabled value={user.email} />
          <small>Email dipakai untuk masuk dan menerima info pesanan.</small>
        </label>
        <label>
          Nomor telepon
          <input
            required
            inputMode="tel"
            autoComplete="tel"
            pattern="[0-9+ -]{8,20}"
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
          />
        </label>
        <div className="profile-meta">
          <span>
            Akun dibuat<strong>{formatAccountDate(user.created_at)}</strong>
          </span>
          <span>
            Terakhir diperbarui<strong>{formatAccountDate(user.updated_at)}</strong>
          </span>
        </div>
        <Feedback type="success">{saved}</Feedback>
        <Feedback>{error}</Feedback>
        <Button type="submit" disabled={saving} aria-busy={saving}>
          {saving ? "Menyimpan…" : "Simpan perubahan"}
        </Button>
      </form>
    </AccountShell>
  );
}

export default Profile;
