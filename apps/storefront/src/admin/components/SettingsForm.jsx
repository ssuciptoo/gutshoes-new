import React, {useState} from "react";
import {api} from "../../api";
import {apiErrorMessage} from "../lib/helpers";
import Button from "./Button";
import Icon from "./Icon";

// Pengaturan yang dipakai aplikasi. Kunci lain dari database tetap tampil sebagai teks.
const KNOWN = {
  low_stock_threshold: {
    label: "Ambang stok rendah",
    help: "Varian dengan stok tersedia sama atau di bawah angka ini ditandai stok rendah di dashboard dan halaman stok.",
    type: "number",
    min: 0,
    suffix: "unit",
    fallback: 5,
  },
};

const humanize = (key) => {
  const text = key.replaceAll("_", " ");
  return text.charAt(0).toUpperCase() + text.slice(1);
};

function SettingsForm({items, reload}) {
  const stored = Object.fromEntries(items.map((item) => [item.key, item]));
  const keys = [...new Set([...Object.keys(KNOWN), ...items.map((item) => item.key)])];
  const initial = Object.fromEntries(
    keys.map((key) => [key, String(stored[key]?.value ?? KNOWN[key]?.fallback ?? "")]),
  );
  const [values, setValues] = useState(initial);
  const [saving, setSaving] = useState(false);
  const [feedback, setFeedback] = useState(null);
  const changed = keys.filter((key) => values[key] !== initial[key] || !stored[key]);

  const submit = async (event) => {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    setFeedback(null);
    const failures = [];
    // Simpan satu per satu supaya kegagalan bisa dilaporkan per pengaturan.
    for (const key of changed) {
      const meta = KNOWN[key];
      try {
        await api.updateConfiguration(key, {
          value: meta?.type === "number" ? Number(values[key]) : values[key],
          is_public: Boolean(stored[key]?.is_public),
        });
      } catch (error) {
        failures.push(`${meta?.label || humanize(key)}: ${apiErrorMessage(error, "gagal disimpan")}`);
      }
    }
    setSaving(false);
    if (failures.length) {
      setFeedback({tone: "error", text: `Sebagian pengaturan belum tersimpan. ${failures.join(" · ")}`});
    } else {
      setFeedback({tone: "success", text: "Pengaturan tersimpan."});
    }
    reload();
  };

  return (
    <form className="adm-settings" onSubmit={submit}>
      <section className="adm-card">
        <h2>Operasional toko</h2>
        <p>Pengaturan ini langsung memengaruhi perilaku toko dan panel admin.</p>
        <div className="adm-form-grid">
          {keys.map((key) => {
            const meta = KNOWN[key];
            return (
              <label key={key}>
                {meta?.label || humanize(key)}
                {meta?.type === "number" ? (
                  <span className="adm-stock-number">
                    <input
                      type="number"
                      inputMode="numeric"
                      min={meta.min}
                      step="1"
                      required
                      value={values[key]}
                      onChange={(event) => setValues({...values, [key]: event.target.value})}
                    />
                    {meta.suffix && <b>{meta.suffix}</b>}
                  </span>
                ) : (
                  <input
                    required
                    value={values[key]}
                    onChange={(event) => setValues({...values, [key]: event.target.value})}
                  />
                )}
                {meta?.help && <small className="adm-field-help">{meta.help}</small>}
              </label>
            );
          })}
        </div>
      </section>
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
