import React, {useState} from "react";
import {api} from "../../api";
import {rupiah} from "../../data";
import usePagedResource from "../hooks/usePagedResource";
import {statusLabel} from "../lib/helpers";
import Badge from "../components/Badge";
import DataToolbar from "../components/DataToolbar";
import Empty from "../components/Empty";
import Icon from "../components/Icon";
import PageHead from "../components/PageHead";
import Pagination from "../components/Pagination";
import PromotionEditor from "../components/PromotionEditor";
import ResourceState from "../components/ResourceState";

const ACTIVE_FILTERS = [["", "Semua status"], ["1", "Aktif"], ["0", "Nonaktif"]];

const discountText = (item) =>
  item.type === "FREE_SHIPPING"
    ? "Gratis ongkir"
    : item.type === "PERCENTAGE"
      ? `${Number(item.value)}%`
      : rupiah(Number(item.value || 0));

function periodState(item) {
  const now = Date.now();
  if (!item.is_active) return ["Nonaktif", "neutral"];
  if (new Date(item.starts_at).getTime() > now) return ["Terjadwal", "info"];
  if (new Date(item.ends_at).getTime() < now) return ["Berakhir", "danger"];
  return ["Aktif", "success"];
}

function PromotionsPage() {
  const [tab, setTab] = useState("voucher");
  const [editing, setEditing] = useState(null);
  const [notice, setNotice] = useState("");
  const vouchers = usePagedResource(api.adminVouchers, {filterParam: "is_active"});
  const promotions = usePagedResource(api.adminPromotions, {filterParam: "is_active"});
  const resource = tab === "voucher" ? vouchers : promotions;
  const isVoucher = tab === "voucher";

  return (
    <>
      <PageHead
        section="promotions"
        action={isVoucher ? "Tambah voucher" : "Tambah promosi"}
        onAction={() => {
          setNotice("");
          setEditing({kind: tab, item: null});
        }}
      />
      {notice && (
        <div className="adm-feedback success" role="status">
          <Icon name="check" />
          {notice}
        </div>
      )}
      <div className="adm-tabs" role="tablist" aria-label="Jenis diskon">
        {[["voucher", "Voucher"], ["promotion", "Promosi produk"]].map(([id, text]) => (
          <button
            key={id}
            role="tab"
            aria-selected={tab === id}
            className={tab === id ? "active" : ""}
            onClick={() => setTab(id)}
          >
            {text}
          </button>
        ))}
      </div>
      <DataToolbar
        query={resource.query}
        setQuery={resource.setQuery}
        filter={resource.filter}
        setFilter={resource.setFilter}
        options={ACTIVE_FILTERS}
        placeholder={isVoucher ? "Cari kode atau nama voucher…" : "Cari nama promosi…"}
        searchLabel={isVoucher ? "Cari voucher" : "Cari promosi"}
      />
      <ResourceState {...resource} />
      {!resource.loading && !resource.error && (
        <section className="adm-card adm-table-card">
          {resource.rows.length ? (
            <div className="adm-table-wrap">
              <table className="adm-responsive-table">
                <thead>
                  <tr>
                    <th>{isVoucher ? "Kode / nama" : "Nama"}</th>
                    <th>Potongan</th>
                    <th>{isVoucher ? "Syarat" : "Prioritas"}</th>
                    <th>Periode</th>
                    <th>Status</th>
                    <th>
                      <span className="sr-only">Aksi</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {resource.rows.map((item) => {
                    const [stateText, stateTone] = periodState(item);
                    return (
                      <tr key={item.id}>
                        <td data-label={isVoucher ? "Kode / nama" : "Nama"}>
                          <strong>{item.code || item.name}</strong>
                          <small>
                            {isVoucher ? item.name + " · " : ""}
                            {item.products?.length ? `${item.products.length} produk` : "Semua produk"}
                          </small>
                        </td>
                        <td data-label="Potongan">
                          <strong>{discountText(item)}</strong>
                          <small>{statusLabel(item.type)}</small>
                        </td>
                        <td data-label={isVoucher ? "Syarat" : "Prioritas"}>
                          {isVoucher ? (
                            <>
                              Min. {rupiah(Number(item.minimum_amount || 0))}
                              <small>
                                {item.usage_limit ? `Kuota ${item.usage_limit}` : "Kuota tanpa batas"}
                              </small>
                            </>
                          ) : (
                            item.priority
                          )}
                        </td>
                        <td data-label="Periode">
                          {new Date(item.starts_at).toLocaleDateString("id-ID")} –{" "}
                          {new Date(item.ends_at).toLocaleDateString("id-ID")}
                        </td>
                        <td data-label="Status">
                          <Badge tone={stateTone}>{stateText}</Badge>
                        </td>
                        <td data-label="Aksi">
                          <button
                            className="adm-row-action"
                            onClick={() => {
                              setNotice("");
                              setEditing({kind: tab, item});
                            }}
                            aria-label={`Edit ${item.code || item.name}`}
                          >
                            <Icon name="arrow" />
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ) : (
            <Empty
              title={isVoucher ? "Voucher tidak ditemukan" : "Promosi tidak ditemukan"}
              text={resource.query || resource.filter ? "Coba ubah pencarian atau filter." : `Tambahkan ${isVoucher ? "voucher" : "promosi"} pertama.`}
            />
          )}
          <Pagination meta={resource.meta} page={resource.page} setPage={resource.setPage} unit={isVoucher ? "voucher" : "promosi"} />
        </section>
      )}
      {editing && (
        <PromotionEditor
          kind={editing.kind}
          item={editing.item}
          onClose={() => setEditing(null)}
          onSaved={(message) => {
            setEditing(null);
            setNotice(message);
            (editing.kind === "voucher" ? vouchers : promotions).retry();
          }}
        />
      )}
    </>
  );
}

export default PromotionsPage;
