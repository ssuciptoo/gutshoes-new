import React, {useEffect, useMemo, useState} from "react";
import {api} from "../../api";
import {backdropFrameProps} from "../../lib/backdrops";
import {catalogImageUrl} from "../../lib/catalog";
import {apiErrorMessage, serverList} from "../lib/helpers";
import useAdminResource from "../hooks/useAdminResource";
import sampleShoe from "../assets/sample-shoe.svg";
import Badge from "./Badge";
import Button from "./Button";
import Icon from "./Icon";

const TYPES = [
  ["solid", "Warna solid"],
  ["gradient", "Gradien"],
  ["image", "Gambar latar"],
];

const blank = () => ({
  id: null, name: "", type: "solid", color_from: "#f7f8f8", color_to: "#dbe7ec",
  angle: 180, image_fit: "cover", shadow: true, is_default: false, image_path: null,
});

const draftOf = (item) => ({...blank(), ...item, color_to: item?.color_to || "#dbe7ec"});

/** Foto contoh untuk pratinjau: gambar produk transparan terbaru, atau siluet bawaan. */
function useSampleImage() {
  const [sample, setSample] = useState(sampleShoe);
  useEffect(() => {
    let active = true;
    api
      .adminProducts({per_page: 25})
      .then((response) => {
        const image = serverList(response.data).flatMap((product) => product.images || []).find((item) => item.use_template);
        if (active && image) setSample(catalogImageUrl(image.path));
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);
  return sample;
}

function BackdropPreview({backdrop, sample}) {
  return (
    <div className="adm-backdrop-preview" aria-label="Pratinjau template">
      <figure>
        <div {...backdropFrameProps(backdrop, "product-card__image")}>
          <img src={sample} alt="" />
        </div>
        <figcaption>Kartu produk</figcaption>
      </figure>
      <figure>
        <div {...backdropFrameProps(backdrop, "adm-backdrop-gallery")}>
          <img src={sample} alt="" />
        </div>
        <figcaption>Galeri detail</figcaption>
      </figure>
    </div>
  );
}

function BackdropSettings() {
  const resource = useAdminResource(api.adminImageBackdrops);
  const items = useMemo(() => serverList(resource.data), [resource.data]);
  const sample = useSampleImage();
  const [form, setForm] = useState(null);
  const [file, setFile] = useState(null);
  const [fileUrl, setFileUrl] = useState("");
  const [saving, setSaving] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [feedback, setFeedback] = useState(null);

  useEffect(() => {
    if (!form && items.length) setForm(draftOf(items.find((item) => item.is_default) || items[0]));
  }, [items, form]);
  useEffect(() => {
    if (!file) {
      setFileUrl("");
      return undefined;
    }
    const url = URL.createObjectURL(file);
    setFileUrl(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);

  const select = (item) => {
    setForm(item ? draftOf(item) : blank());
    setFile(null);
    setConfirmDelete(false);
    setFeedback(null);
  };
  const update = (key, value) => setForm((previous) => ({...previous, [key]: value}));

  const chooseFile = (next) => {
    setFeedback(null);
    if (next && !["image/jpeg", "image/png", "image/webp"].includes(next.type)) {
      setFeedback({tone: "error", text: "Gambar latar harus JPEG, PNG, atau WebP."});
      return;
    }
    if (next && next.size > 5 * 1024 * 1024) {
      setFeedback({tone: "error", text: "Ukuran gambar latar maksimal 5 MB."});
      return;
    }
    setFile(next || null);
  };

  const save = async (event) => {
    event.preventDefault();
    if (saving || !form) return;
    setSaving(true);
    setFeedback(null);
    const payload = {
      name: form.name.trim(),
      type: form.type,
      color_from: form.color_from,
      color_to: form.type === "gradient" ? form.color_to : null,
      angle: Number(form.angle) || 0,
      image_fit: form.image_fit,
      shadow: Boolean(form.shadow),
      ...(form.is_default ? {is_default: true} : {}),
    };
    try {
      let saved = (await api.saveImageBackdrop(form.id, payload)).data;
      if (file && form.type === "image") {
        const data = new FormData();
        data.append("image", file);
        saved = (await api.uploadImageBackdropImage(saved.id, data)).data;
        setFile(null);
      }
      setForm(draftOf(saved));
      setFeedback({tone: "success", text: "Template latar tersimpan. Toko memakai perubahan ini setelah halaman dimuat ulang."});
      resource.retry();
    } catch (error) {
      setFeedback({tone: "error", text: apiErrorMessage(error, "Template latar belum dapat disimpan.")});
    } finally {
      setSaving(false);
    }
  };

  const destroy = async () => {
    if (!form?.id || saving) return;
    setSaving(true);
    setFeedback(null);
    try {
      await api.deleteImageBackdrop(form.id);
      setForm(null);
      setConfirmDelete(false);
      setFeedback({tone: "success", text: "Template dihapus. Kategori yang memakainya kembali ke template default."});
      resource.retry();
    } catch (error) {
      setFeedback({tone: "error", text: apiErrorMessage(error, "Template belum dapat dihapus.")});
    } finally {
      setSaving(false);
    }
  };

  if (resource.loading) return <section className="adm-card"><p className="adm-muted-copy">Memuat template latar…</p></section>;
  if (resource.error) {
    return (
      <section className="adm-card" role="alert">
        <h2>Template latar gambar produk</h2>
        <p>{resource.error}</p>
        <Button kind="secondary" type="button" onClick={resource.retry}>Coba lagi</Button>
      </section>
    );
  }

  const previewBackdrop = form ? {...form, image_url: fileUrl || undefined} : null;

  return (
    <section className="adm-card adm-backdrops">
      <h2>Template latar gambar produk</h2>
      <p>
        Foto produk berlatar transparan (PNG/WebP) ditampilkan di atas template ini pada kartu katalog dan galeri detail.
        Foto berlatar penuh tetap tampil apa adanya. Kategori bisa memakai template sendiri lewat menu Katalog.
      </p>
      <div className="adm-backdrop-list" role="group" aria-label="Pilih template">
        {items.map((item) => (
          <button
            type="button"
            key={item.id}
            className={form?.id === item.id ? "active" : ""}
            onClick={() => select(item)}
            aria-pressed={form?.id === item.id}
          >
            <span {...backdropFrameProps(item, "adm-backdrop-swatch")} aria-hidden="true" />
            <span>
              <strong>{item.name}</strong>
              <small>{item.categories_count ? `${item.categories_count} kategori` : item.is_default ? "Semua produk" : "Belum dipakai"}</small>
            </span>
            {item.is_default && <Badge tone="success">Default</Badge>}
          </button>
        ))}
        <button type="button" className={form && !form.id ? "active" : ""} onClick={() => select(null)}>
          <Icon name="plus" /> Template baru
        </button>
      </div>
      {form && (
        <form className="adm-backdrop-editor" onSubmit={save} aria-busy={saving}>
          <div className="adm-form-grid">
            <label>
              Nama template
              <input required maxLength="80" value={form.name} onChange={(event) => update("name", event.target.value)} placeholder="Contoh: Abu terang" />
            </label>
            <label>
              Jenis latar
              <select value={form.type} onChange={(event) => update("type", event.target.value)}>
                {TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </label>
            <label>
              {form.type === "gradient" ? "Warna awal" : "Warna dasar"}
              <input type="color" value={form.color_from} onChange={(event) => update("color_from", event.target.value)} />
            </label>
            {form.type === "gradient" && (
              <>
                <label>
                  Warna akhir
                  <input type="color" value={form.color_to} onChange={(event) => update("color_to", event.target.value)} />
                </label>
                <label>
                  Arah gradien
                  <span className="adm-stock-number">
                    <input type="number" min="0" max="360" step="5" value={form.angle} onChange={(event) => update("angle", event.target.value)} />
                    <b>derajat</b>
                  </span>
                </label>
              </>
            )}
            {form.type === "image" && (
              <>
                <label className="wide">
                  Gambar latar
                  <input type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => chooseFile(event.target.files?.[0] || null)} />
                  <small className="adm-field-help">
                    JPEG, PNG, atau WebP, minimal 600×450 px, maksimal 5 MB. {form.image_path && !file ? "Kosongkan untuk tetap memakai gambar sekarang." : ""}
                    {!form.image_path && !file ? " Sebelum ada gambar, warna dasar yang dipakai." : ""}
                  </small>
                </label>
                <label>
                  Penempatan gambar
                  <select value={form.image_fit} onChange={(event) => update("image_fit", event.target.value)}>
                    <option value="cover">Penuhi bingkai</option>
                    <option value="contain">Tampilkan utuh</option>
                  </select>
                </label>
              </>
            )}
            <label className="adm-check">
              <input type="checkbox" checked={Boolean(form.shadow)} onChange={(event) => update("shadow", event.target.checked)} /> Bayangan di bawah sepatu
            </label>
            <label className="adm-check">
              <input
                type="checkbox"
                checked={Boolean(form.is_default)}
                disabled={Boolean(form.id && items.find((item) => item.id === form.id)?.is_default)}
                onChange={(event) => update("is_default", event.target.checked)}
              />{" "}
              Jadikan default untuk semua produk
            </label>
          </div>
          <BackdropPreview backdrop={previewBackdrop} sample={sample} />
          {feedback && (
            <div className={`adm-feedback ${feedback.tone}`} role={feedback.tone === "error" ? "alert" : "status"}>
              {feedback.tone === "success" && <Icon name="check" />}
              {feedback.text}
            </div>
          )}
          {confirmDelete && (
            <div className="adm-delete-confirm" role="alert">
              <div>
                <strong>Hapus template ini?</strong>
                <p>Kategori yang memakainya kembali ke template default.</p>
              </div>
              <Button kind="secondary" type="button" onClick={() => setConfirmDelete(false)}>Batal</Button>
              <button className="adm-danger-button" type="button" onClick={destroy} disabled={saving}>Ya, hapus</button>
            </div>
          )}
          <footer>
            {form.id && !form.is_default && !confirmDelete && (
              <button className="adm-delete-link" type="button" onClick={() => setConfirmDelete(true)}>Hapus template</button>
            )}
            <Button type="submit" disabled={saving}>{saving ? "Menyimpan…" : form.id ? "Simpan template" : "Buat template"}</Button>
          </footer>
        </form>
      )}
    </section>
  );
}

export default BackdropSettings;
