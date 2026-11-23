import React, {useState} from "react";
import {api} from "../../api";
import {apiErrorMessage} from "../lib/helpers";
import Button from "./Button";
import Icon from "./Icon";

const FIELDS = [
  ["name", "Nama gudang", {required: true, wide: true}],
  ["phone", "Telepon", {type: "tel", placeholder: "Opsional"}],
  ["postal_code", "Kode pos", {required: true, inputMode: "numeric", maxLength: 10}],
  ["address_line", "Alamat lengkap", {required: true, wide: true}],
  ["subdistrict", "Kelurahan / desa", {required: true}],
  ["district", "Kecamatan", {required: true}],
  ["city", "Kota / kabupaten", {required: true}],
  ["province", "Provinsi", {required: true}],
  [
    "provider_area_id",
    "ID area kurir",
    {
      required: true,
      wide: true,
      help: "Kode lokasi gudang di sistem penyedia ongkir (RajaOngkir/Biteship). Dipakai sebagai asal saat menghitung tarif.",
    },
  ],
];

export default function WarehouseForm({warehouse, reload}) {
  const [feedback, setFeedback] = useState(null);
  const [saving, setSaving] = useState(false);
  const submit = async (event) => {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    setFeedback(null);
    const values = Object.fromEntries(new FormData(event.currentTarget));
    try {
      await api.updateAdminWarehouse(values);
      setFeedback({tone: "success", text: "Alamat asal pengiriman tersimpan."});
      reload();
    } catch (error) {
      setFeedback({tone: "error", text: apiErrorMessage(error, "Alamat gudang gagal disimpan.")});
    } finally {
      setSaving(false);
    }
  };
  return (
    <form className="adm-settings" onSubmit={submit}>
      <section className="adm-card">
        <h2>Asal pengiriman</h2>
        <p>Alamat gudang ini dipakai untuk menghitung ongkir dan sebagai alamat pengirim.</p>
        <div className="adm-form-grid">
          {FIELDS.map(([key, label, options]) => (
            <label key={key} className={options.wide ? "wide" : undefined}>
              {label}
              <input
                name={key}
                defaultValue={warehouse?.[key] || ""}
                required={Boolean(options.required)}
                type={options.type || "text"}
                inputMode={options.inputMode}
                maxLength={options.maxLength}
                placeholder={options.placeholder}
              />
              {options.help && <small className="adm-field-help">{options.help}</small>}
            </label>
          ))}
        </div>
      </section>
      <div className="adm-sticky-save">
        {feedback && (
          <span role={feedback.tone === "error" ? "alert" : "status"} className={`adm-save-${feedback.tone}`}>
            {feedback.tone === "success" && <Icon name="check" />}
            {feedback.text}
          </span>
        )}
        <Button type="submit" disabled={saving} aria-busy={saving}>
          {saving ? "Menyimpan…" : "Simpan asal pengiriman"}
        </Button>
      </div>
    </form>
  );
}
