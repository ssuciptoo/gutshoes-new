import React, {useState} from "react";
import {api} from "../../api";
import usePagedResource from "../hooks/usePagedResource";
import Badge from "../components/Badge";
import DataToolbar from "../components/DataToolbar";
import Empty from "../components/Empty";
import PageHead from "../components/PageHead";
import Pagination from "../components/Pagination";
import ResourceState from "../components/ResourceState";

const ACTIONS = {
  post: ["Tambah / proses", "success"],
  put: ["Ubah", "info"],
  patch: ["Ubah", "info"],
  delete: ["Hapus", "danger"],
  cancel: ["Batalkan", "danger"],
};

const ENTITIES = {
  products: "Produk",
  brands: "Merek",
  categories: "Kategori",
  sizes: "Ukuran",
  inventories: "Stok",
  orders: "Pesanan",
  refunds: "Refund",
  promotions: "Promosi",
  vouchers: "Voucher",
  configurations: "Pengaturan",
  warehouse: "Asal pengiriman",
  "App\\Models\\Order": "Pesanan",
};

// Detail tambahan dari path, mis. "PATCH api/v1/admin/orders/5/fulfillment" → "fulfillment".
const PATH_DETAIL = {
  fulfillment: "status pemenuhan",
  publish: "publikasi",
  images: "gambar",
  adjust: "penyesuaian stok",
  process: "proses refund",
};

function describeTarget(item) {
  const path = String(item.description || "").split(" ")[1] || "";
  const segments = path.replace(/^api\/v1\/admin\/?/, "").split("/").filter(Boolean);
  const type = item.entity_type || segments[0] || "";
  const name = ENTITIES[type] || (type ? type.split("\\").pop() : "—");
  const key = type === "configurations" ? segments[1]?.replaceAll("_", " ") : null;
  const detail = segments.slice(2).find((segment) => PATH_DETAIL[segment]);
  return [
    name,
    item.entity_id ? `#${item.entity_id}` : key ? `“${key}”` : "",
    detail ? `· ${PATH_DETAIL[detail]}` : "",
  ]
    .filter(Boolean)
    .join(" ");
}

function AuditPage() {
  const [range, setRange] = useState({from: "", to: ""});
  const resource = usePagedResource(api.adminAuditLogs, {
    perPage: 50,
    filterParam: "action",
    extraParams: range,
  });
  const rows = resource.rows;
  return (
    <>
      <PageHead section="audit" />
      <DataToolbar
        query={resource.query}
        setQuery={resource.setQuery}
        filter={resource.filter}
        setFilter={resource.setFilter}
        options={[
          ["", "Semua tindakan"],
          ["post", "Tambah / proses"],
          ["put", "Ubah"],
          ["patch", "Ubah sebagian"],
          ["delete", "Hapus"],
          ["cancel", "Batalkan pesanan"],
        ]}
        placeholder="Cari objek, mis. products atau orders…"
        searchLabel="Cari audit log"
        filterLabel="Filter tindakan"
      />
      <div className="adm-date-range" role="group" aria-label="Rentang tanggal">
        <label>
          Dari
          <input
            type="date"
            value={range.from}
            max={range.to || undefined}
            onChange={(event) => setRange((previous) => ({...previous, from: event.target.value}))}
          />
        </label>
        <label>
          Sampai
          <input
            type="date"
            value={range.to}
            min={range.from || undefined}
            onChange={(event) => setRange((previous) => ({...previous, to: event.target.value}))}
          />
        </label>
        {(range.from || range.to) && (
          <button type="button" className="adm-text-button" onClick={() => setRange({from: "", to: ""})}>
            Hapus tanggal
          </button>
        )}
      </div>
      <ResourceState {...resource} />
      {!resource.loading && !resource.error && (
        <section className="adm-card adm-table-card">
          {rows.length ? (
            <div className="adm-table-wrap">
              <table className="adm-responsive-table adm-card-table">
                <thead>
                  <tr>
                    <th>Waktu</th>
                    <th>Admin</th>
                    <th>Tindakan</th>
                    <th>Objek</th>
                    <th>IP</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((item) => {
                    const [actionText, actionTone] = ACTIONS[item.action] || [item.action, "info"];
                    return (
                      <tr key={item.id}>
                        <td data-label="Waktu">
                          {new Date(item.created_at).toLocaleString("id-ID")}
                        </td>
                        <td data-label="Admin">
                          <strong>{item.admin?.name || (item.admin_id ? `Admin #${item.admin_id}` : "Sistem")}</strong>
                          {item.admin?.email && <small>{item.admin.email}</small>}
                        </td>
                        <td data-label="Tindakan">
                          <Badge tone={actionTone}>{actionText}</Badge>
                        </td>
                        <td data-label="Objek">{describeTarget(item)}</td>
                        <td data-label="IP">
                          <code>{item.ip_address || "—"}</code>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ) : (
            <Empty
              title="Catatan tidak ditemukan"
              text={
                resource.query || resource.filter || range.from || range.to
                  ? "Coba ubah pencarian, tindakan, atau tanggal."
                  : "Belum ada mutasi admin yang tercatat."
              }
            />
          )}
          <Pagination meta={resource.meta} page={resource.page} setPage={resource.setPage} unit="catatan" />
        </section>
      )}
    </>
  );
}

export default AuditPage;
