import React, {useState} from "react";
import {api} from "../../api";
import {apiErrorMessage} from "../lib/helpers";
import Button from "./Button";
import Icon from "./Icon";

// Pengaturan yang dikenal aplikasi, dikelompokkan per bagian. Kunci lain dari database
// tetap tampil di "Lainnya" sebagai teks. Kolom "optional" boleh dikosongkan (disembunyikan di toko).
const GROUPS = [
  {
    title: "Operasional toko",
    description: "Pengaturan ini langsung memengaruhi perilaku toko dan panel admin.",
    fields: {
      low_stock_threshold: {
        label: "Ambang stok rendah",
        help: "Varian dengan stok tersedia sama atau di bawah angka ini ditandai stok rendah di dashboard dan halaman stok.",
        type: "number", min: 0, suffix: "unit", fallback: 5,
      },
    },
  },
  {
    title: "Informasi toko",
    description: "Tampil di footer serta halaman Hubungi kami, Kebijakan privasi, dan Pengembalian. Kolom yang kosong tidak ditampilkan.",
    fields: {
      store_legal_name: {label: "Nama badan usaha", placeholder: "Contoh: PT GutShoes Indonesia", optional: true, wide: true},
      store_email: {label: "Email layanan pelanggan", type: "email", placeholder: "Contoh: halo@gutshoes.id", optional: true},
      store_whatsapp: {label: "Nomor WhatsApp", type: "tel", placeholder: "Contoh: 0812-3456-7890", optional: true},
      store_address: {label: "Alamat toko", type: "textarea", optional: true, wide: true},
      store_hours: {label: "Jam layanan", placeholder: "Contoh: Senin–Jumat, 09.00–17.00 WIB", optional: true},
      store_return_days: {
        label: "Batas pengajuan retur", type: "number", min: 1, max: 90, suffix: "hari", optional: true,
        help: "Dipakai di halaman Pengembalian. Kosong = 7 hari.",
      },
      store_policy_updated: {label: "Tanggal kebijakan diperbarui", type: "date", optional: true},
    },
  },
  {
    title: "Footer & versi",
    description: "Teks hak cipta dan versi aplikasi di bagian bawah setiap halaman toko.",
    fields: {
      store_copyright: {
        label: "Teks hak cipta", placeholder: "Contoh: © {tahun} PT GutShoes Indonesia. Hak cipta dilindungi.", optional: true, wide: true,
        help: "Tulis {tahun} untuk tahun berjalan. Kosong = © {tahun} + nama badan usaha.",
      },
      store_version: {label: "Versi aplikasi", placeholder: "Contoh: v1.0.0", optional: true, help: "Kosong = versi tidak ditampilkan."},
    },
  },
];

const KNOWN = Object.assign({}, ...GROUPS.map((group) => group.fields));

const humanize = (key) => {
  const text = key.replaceAll("_", " ");
  return text.charAt(0).toUpperCase() + text.slice(1);
};

function Field({name, meta, value, onChange}) {
  const common = {
    value,
    required: !meta.optional,
    placeholder: meta.placeholder,
    onChange: (event) => onChange(name, event.target.value),
  };
  let input;
  if (meta.type === "textarea") input = <textarea rows="3" maxLength="300" {...common} />;
  else if (meta.type === "number")
    input = (
      <span className="adm-stock-number">
        <input type="number" inputMode="numeric" min={meta.min} max={meta.max} step="1" {...common} />
        {meta.suffix && <b>{meta.suffix}</b>}
      </span>
    );
  else input = <input type={meta.type || "text"} maxLength="200" {...common} />;
  return (
    <label className={meta.wide ? "wide" : undefined}>
      {meta.label || humanize(name)}
      {input}
      {meta.help && <small className="adm-field-help">{meta.help}</small>}
    </label>
  );
}

function SettingsForm({items, reload}) {
  const stored = Object.fromEntries(items.map((item) => [item.key, item]));
  const others = items.map((item) => item.key).filter((key) => !KNOWN[key]);
  const keys = [...Object.keys(KNOWN), ...others];
  const initialValue = (key) => {
    const value = stored[key]?.value;
    if (value !== undefined && value !== null) return String(value);
    return KNOWN[key]?.fallback !== undefined ? String(KNOWN[key].fallback) : "";
  };
  const initial = Object.fromEntries(keys.map((key) => [key, initialValue(key)]));
  const [values, setValues] = useState(initial);
  const [saving, setSaving] = useState(false);
  const [feedback, setFeedback] = useState(null);
  // Kolom wajib yang belum pernah disimpan ikut dikirim agar nilai bawaannya tersimpan.
  const changed = keys.filter((key) => values[key] !== initial[key] || (!stored[key] && !KNOWN[key]?.optional));
  const update = (key, value) => setValues((previous) => ({...previous, [key]: value}));

  const submit = async (event) => {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    setFeedback(null);
    // Satu permintaan untuk semua perubahan; server menyimpan semuanya atau tidak sama sekali.
    const payload = Object.fromEntries(
      changed.map((key) => {
        const meta = KNOWN[key];
        const raw = values[key].trim();
        return [key, meta?.optional && raw === "" ? null : meta?.type === "number" ? Number(raw) : raw];
      }),
    );
    try {
      await api.updateConfigurations(payload);
      setFeedback({tone: "success", text: "Pengaturan tersimpan."});
      reload();
    } catch (error) {
      const fieldErrors = Object.entries(error?.errors || {})
        .filter(([field]) => field.startsWith("values."))
        .map(([field, messages]) => {
          const key = field.slice("values.".length);
          return `${KNOWN[key]?.label || humanize(key)}: ${[].concat(messages)[0]}`;
        });
      setFeedback({
        tone: "error",
        text: fieldErrors.length
          ? `Belum tersimpan. ${fieldErrors.join(" · ")}`
          : apiErrorMessage(error, "Pengaturan belum dapat disimpan."),
      });
    } finally {
      setSaving(false);
    }
  };

  return (
    <form className="adm-settings" onSubmit={submit}>
      {GROUPS.map((group) => (
        <section className="adm-card" key={group.title}>
          <h2>{group.title}</h2>
          <p>{group.description}</p>
          <div className="adm-form-grid">
            {Object.entries(group.fields).map(([key, meta]) => (
              <Field key={key} name={key} meta={meta} value={values[key]} onChange={update} />
            ))}
          </div>
        </section>
      ))}
      {others.length > 0 && (
        <section className="adm-card">
          <h2>Lainnya</h2>
          <p>Pengaturan tambahan yang tersimpan di database.</p>
          <div className="adm-form-grid">
            {others.map((key) => (
              <Field key={key} name={key} meta={{}} value={values[key]} onChange={update} />
            ))}
          </div>
        </section>
      )}
      <div className="adm-sticky-save">
        {feedback && (
          <span role={feedback.tone === "error" ? "alert" : "status"} className={`adm-save-${feedback.tone}`}>
            {feedback.tone === "success" && <Icon name="check" />}
            {feedback.text}
          </span>
        )}
        <Button type="submit" disabled={saving || changed.length === 0} aria-busy={saving}>
          {saving ? "Menyimpan…" : "Simpan pengaturan"}
        </Button>
      </div>
    </form>
  );
}

export default SettingsForm;
