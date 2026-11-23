import React, {useEffect, useState} from "react";
import {api} from "../../api";
import {apiErrorMessage, serverList} from "../lib/helpers";
import useDialogFocus from "../hooks/useDialogFocus";
import Button from "./Button";
import Icon from "./Icon";

// "2026-11-23T10:00:00.000000Z" → "2026-11-23T17:00" (zona waktu lokal untuk input datetime-local)
const toLocalInput = (value) => {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  const pad = (n) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};
const fromLocalInput = (value) => (value ? new Date(value).toISOString() : null);
const numberOrNull = (value) => (value === "" || value === null || value === undefined ? null : Number(value));

function initialForm(kind, item) {
  const now = new Date();
  const nextMonth = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000);
  return {
    id: item?.id || null,
    code: item?.code || "",
    name: item?.name || "",
    type: item?.type || "PERCENTAGE",
    value: item ? String(Number(item.value)) : "",
    priority: item ? String(item.priority ?? 0) : "0",
    minimum_amount: item ? String(Number(item.minimum_amount || 0)) : "0",
    maximum_discount: item?.maximum_discount != null ? String(Number(item.maximum_discount)) : "",
    usage_limit: item?.usage_limit != null ? String(item.usage_limit) : "",
    usage_limit_per_user: item?.usage_limit_per_user != null ? String(item.usage_limit_per_user) : "",
    starts_at: toLocalInput(item?.starts_at || now),
    ends_at: toLocalInput(item?.ends_at || nextMonth),
    is_active: item ? Boolean(item.is_active) : true,
    products: (item?.products || []).map((product) => ({id: product.id, name: product.name})),
    kind,
  };
}

function PromotionEditor({kind, item, onClose, onSaved}) {
  const isVoucher = kind === "voucher";
  const label = isVoucher ? "voucher" : "promosi";
  const dialogRef = useDialogFocus(onClose);
  const [form, setForm] = useState(() => initialForm(kind, item));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [productQuery, setProductQuery] = useState("");
  const [productResults, setProductResults] = useState([]);
  const update = (key, value) => setForm((previous) => ({...previous, [key]: value}));

  useEffect(() => {
    const term = productQuery.trim();
    if (term.length < 2) {
      setProductResults([]);
      return undefined;
    }
    let active = true;
    const timer = window.setTimeout(() => {
      api
        .adminProducts({search: term, per_page: 8})
        .then((response) => active && setProductResults(serverList(response.data)))
        .catch(() => active && setProductResults([]));
    }, 300);
    return () => {
      active = false;
      window.clearTimeout(timer);
    };
  }, [productQuery]);

  const save = async (event) => {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    setError("");
    const payload = {
      name: form.name.trim(),
      type: form.type,
      value: form.type === "FREE_SHIPPING" ? 0 : Number(form.value),
      starts_at: fromLocalInput(form.starts_at),
      ends_at: fromLocalInput(form.ends_at),
      is_active: form.is_active,
      product_ids: form.products.map((product) => product.id),
    };
    if (isVoucher) {
      Object.assign(payload, {
        code: form.code.trim().toUpperCase(),
        minimum_amount: Number(form.minimum_amount || 0),
        maximum_discount: numberOrNull(form.maximum_discount),
        usage_limit: numberOrNull(form.usage_limit),
        usage_limit_per_user: numberOrNull(form.usage_limit_per_user),
      });
    } else {
      payload.priority = Number(form.priority || 0);
    }
    try {
      await (isVoucher ? api.saveVoucher(form.id, payload) : api.savePromotion(form.id, payload));
      onSaved(`${isVoucher ? "Voucher" : "Promosi"} ${payload.code || payload.name} berhasil disimpan.`);
    } catch (requestError) {
      setError(apiErrorMessage(requestError, `${label} belum dapat disimpan.`));
    } finally {
      setSaving(false);
    }
  };

  const destroy = async () => {
    if (saving) return;
    setSaving(true);
    setError("");
    try {
      await (isVoucher ? api.deleteVoucher(form.id) : api.deletePromotion(form.id));
      onSaved(`${isVoucher ? "Voucher" : "Promosi"} dihapus.`);
    } catch (requestError) {
      setError(apiErrorMessage(requestError, `${label} belum dapat dihapus.`));
      setSaving(false);
    }
  };

  const types = isVoucher
    ? [["PERCENTAGE", "Persentase (%)"], ["FIXED_AMOUNT", "Potongan nominal (Rp)"], ["FREE_SHIPPING", "Gratis ongkir"]]
    : [["PERCENTAGE", "Persentase (%)"], ["FIXED_AMOUNT", "Potongan nominal (Rp)"]];

  return (
    <div
      className="adm-overlay"
      role="presentation"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <aside
        ref={dialogRef}
        className="adm-drawer adm-editor adm-catalog-editor"
        role="dialog"
        aria-modal="true"
        aria-labelledby="promotion-editor-title"
      >
        <header>
          <div>
            <small>{form.id ? "EDIT" : "TAMBAH"} {label.toUpperCase()}</small>
            <h2 id="promotion-editor-title">
              {form.id ? form.code || form.name : `Tambah ${label}`}
            </h2>
          </div>
          <button onClick={onClose} aria-label="Tutup editor">
            <Icon name="close" />
          </button>
        </header>
        <form onSubmit={save} aria-busy={saving}>
          {error && <div className="adm-feedback error" role="alert">{error}</div>}
          <div className="adm-form-grid">
            {isVoucher && (
              <label>
                Kode voucher
                <input
                  autoFocus
                  required
                  maxLength="64"
                  pattern="[A-Za-z0-9_-]+"
                  value={form.code}
                  onChange={(event) => update("code", event.target.value.toUpperCase().replace(/\s/g, ""))}
                  placeholder="CONTOH10"
                />
              </label>
            )}
            <label className={isVoucher ? "" : "wide"}>
              Nama {label}
              <input
                autoFocus={!isVoucher}
                required
                maxLength="255"
                value={form.name}
                onChange={(event) => update("name", event.target.value)}
              />
            </label>
            <label>
              Jenis potongan
              <select value={form.type} onChange={(event) => update("type", event.target.value)}>
                {types.map(([value, text]) => (
                  <option key={value} value={value}>{text}</option>
                ))}
              </select>
            </label>
            {form.type !== "FREE_SHIPPING" && (
              <label>
                {form.type === "PERCENTAGE" ? "Besar potongan (%)" : "Besar potongan (Rp)"}
                <input
                  required
                  type="number"
                  min="0"
                  max={form.type === "PERCENTAGE" ? "100" : undefined}
                  step="any"
                  inputMode="decimal"
                  value={form.value}
                  onChange={(event) => update("value", event.target.value)}
                />
              </label>
            )}
            {isVoucher ? (
              <>
                <label>
                  Minimum belanja (Rp)
                  <input type="number" min="0" step="any" required value={form.minimum_amount} onChange={(event) => update("minimum_amount", event.target.value)} />
                </label>
                <label>
                  Maksimum potongan (Rp)
                  <input type="number" min="0" step="any" value={form.maximum_discount} onChange={(event) => update("maximum_discount", event.target.value)} placeholder="Tanpa batas" />
                </label>
                <label>
                  Kuota total
                  <input type="number" min="1" step="1" value={form.usage_limit} onChange={(event) => update("usage_limit", event.target.value)} placeholder="Tanpa batas" />
                </label>
                <label>
                  Kuota per pelanggan
                  <input type="number" min="1" step="1" value={form.usage_limit_per_user} onChange={(event) => update("usage_limit_per_user", event.target.value)} placeholder="Tanpa batas" />
                </label>
              </>
            ) : (
              <label>
                Prioritas
                <input type="number" min="0" step="1" required value={form.priority} onChange={(event) => update("priority", event.target.value)} />
                <small>Angka lebih besar diterapkan lebih dulu.</small>
              </label>
            )}
            <label>
              Mulai berlaku
              <input type="datetime-local" required value={form.starts_at} onChange={(event) => update("starts_at", event.target.value)} />
            </label>
            <label>
              Berakhir
              <input type="datetime-local" required min={form.starts_at} value={form.ends_at} onChange={(event) => update("ends_at", event.target.value)} />
            </label>
            <label className="wide adm-check-inline">
              <span>
                <input type="checkbox" checked={form.is_active} onChange={(event) => update("is_active", event.target.checked)} />
                Aktif
              </span>
              <small>Nonaktifkan untuk menghentikan sementara tanpa menghapus.</small>
            </label>
            <div className="wide adm-product-picker">
              <label>
                Berlaku untuk produk
                <input
                  type="search"
                  value={productQuery}
                  onChange={(event) => setProductQuery(event.target.value)}
                  placeholder="Ketik minimal 2 huruf untuk mencari produk"
                  autoComplete="off"
                />
                <small>Kosongkan untuk berlaku di semua produk.</small>
              </label>
              {productResults.length > 0 && (
                <ul className="adm-product-results">
                  {productResults
                    .filter((product) => !form.products.some((selected) => selected.id === product.id))
                    .map((product) => (
                      <li key={product.id}>
                        <button
                          type="button"
                          onClick={() => {
                            update("products", [...form.products, {id: product.id, name: product.name}]);
                            setProductQuery("");
                          }}
                        >
                          <Icon name="plus" size={15} /> {product.name}
                        </button>
                      </li>
                    ))}
                </ul>
              )}
              {form.products.length > 0 && (
                <div className="adm-chip-list">
                  {form.products.map((product) => (
                    <span key={product.id} className="adm-chip">
                      {product.name}
                      <button
                        type="button"
                        aria-label={`Hapus ${product.name}`}
                        onClick={() => update("products", form.products.filter((selected) => selected.id !== product.id))}
                      >
                        <Icon name="close" size={13} />
                      </button>
                    </span>
                  ))}
                </div>
              )}
            </div>
          </div>
          {confirmDelete && (
            <div className="adm-delete-confirm" role="alert">
              <div>
                <strong>Hapus {label} ini?</strong>
                <p>{isVoucher ? "Pelanggan tidak bisa lagi memakai kode ini." : "Harga produk kembali normal."}</p>
              </div>
              <Button kind="secondary" type="button" onClick={() => setConfirmDelete(false)}>
                Batal
              </Button>
              <button className="adm-danger-button" type="button" onClick={destroy} disabled={saving}>
                Ya, hapus
              </button>
            </div>
          )}
          <footer>
            {form.id && !confirmDelete && (
              <button className="adm-delete-link" type="button" onClick={() => setConfirmDelete(true)}>
                Hapus {label}
              </button>
            )}
            <Button kind="secondary" type="button" onClick={onClose}>
              Tutup
            </Button>
            <Button type="submit" disabled={saving}>
              {saving ? "Menyimpan…" : `Simpan ${label}`}
            </Button>
          </footer>
        </form>
      </aside>
    </div>
  );
}

export default PromotionEditor;
