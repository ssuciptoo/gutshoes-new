import React, {useState} from "react";
import {api} from "../../api";
import {rupiah} from "../../data";
import usePagedResource from "../hooks/usePagedResource";
import {apiErrorMessage, statusLabel, statusOptions, statusTone} from "../lib/helpers";
import Badge from "../components/Badge";
import Button from "../components/Button";
import DataToolbar from "../components/DataToolbar";
import Empty from "../components/Empty";
import Icon from "../components/Icon";
import PageHead from "../components/PageHead";
import Pagination from "../components/Pagination";
import ResourceState from "../components/ResourceState";

const FILTERS = statusOptions("Semua refund", ["PENDING", "FAILED", "SUCCESS"]);

function RefundsPage() {
  const resource = usePagedResource(api.adminRefunds);
  const [confirming, setConfirming] = useState(null);
  const [processing, setProcessing] = useState(null);
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");

  const process = async (refund) => {
    if (processing) return;
    setProcessing(refund.id);
    setError("");
    setNotice("");
    try {
      const response = await api.processRefund(refund.id);
      const status = response.data?.status;
      if (status === "SUCCESS") {
        setNotice(`Refund ${refund.order?.order_number || ""} berhasil diproses.`);
      } else {
        setError(
          `Refund ${refund.order?.order_number || ""} berstatus ${statusLabel(status).toLowerCase()}. Periksa dashboard payment gateway lalu coba lagi.`,
        );
      }
      setConfirming(null);
      resource.retry();
    } catch (requestError) {
      setError(apiErrorMessage(requestError, "Refund belum dapat diproses. Coba lagi."));
    } finally {
      setProcessing(null);
    }
  };

  return (
    <>
      <PageHead section="refunds" />
      {notice && (
        <div className="adm-feedback success" role="status">
          <Icon name="check" />
          {notice}
        </div>
      )}
      {error && <div className="adm-feedback error" role="alert">{error}</div>}
      <DataToolbar
        query={resource.query}
        setQuery={resource.setQuery}
        filter={resource.filter}
        setFilter={resource.setFilter}
        options={FILTERS}
        placeholder="Cari nomor pesanan atau email…"
        searchLabel="Cari refund"
      />
      <ResourceState {...resource} />
      {!resource.loading && !resource.error && (
        <section className="adm-card adm-table-card">
          {resource.rows.length ? (
            <div className="adm-table-wrap">
              <table className="adm-responsive-table adm-card-table">
                <thead>
                  <tr>
                    <th>Pesanan</th>
                    <th>Alasan</th>
                    <th>Status</th>
                    <th className="num">Jumlah</th>
                    <th>
                      <span className="sr-only">Aksi</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {resource.rows.map((refund) => (
                    <tr key={refund.id}>
                      <td data-label="Pesanan">
                        <strong>{refund.order?.order_number || `Pesanan #${refund.order_id}`}</strong>
                        <small>
                          {refund.order?.customer_email || "—"} ·{" "}
                          {new Date(refund.created_at).toLocaleString("id-ID")}
                        </small>
                      </td>
                      <td data-label="Alasan">{refund.reason || "—"}</td>
                      <td data-label="Status">
                        <Badge tone={statusTone(refund.status)}>{statusLabel(refund.status)}</Badge>
                        {refund.completed_at && (
                          <small>Selesai {new Date(refund.completed_at).toLocaleString("id-ID")}</small>
                        )}
                      </td>
                      <td data-label="Jumlah" className="num">
                        <strong>{rupiah(Number(refund.amount || 0))}</strong>
                      </td>
                      <td data-label="Tindakan" className="adm-refund-action">
                        {refund.status === "SUCCESS" ? (
                          <span className="adm-muted-copy">Selesai</span>
                        ) : confirming === refund.id ? (
                          <span className="adm-refund-confirm">
                            <Button
                              kind="secondary"
                              type="button"
                              onClick={() => setConfirming(null)}
                              disabled={processing === refund.id}
                            >
                              Batal
                            </Button>
                            <Button
                              type="button"
                              onClick={() => process(refund)}
                              disabled={processing === refund.id}
                              aria-busy={processing === refund.id}
                            >
                              {processing === refund.id
                                ? "Memproses…"
                                : `Kembalikan ${rupiah(Number(refund.amount || 0))}`}
                            </Button>
                          </span>
                        ) : (
                          <Button
                            kind="secondary"
                            type="button"
                            onClick={() => {
                              setConfirming(refund.id);
                              setNotice("");
                              setError("");
                            }}
                          >
                            {refund.status === "FAILED" ? "Coba lagi" : "Proses refund"}
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <Empty
              title="Refund tidak ditemukan"
              text="Refund dibuat otomatis saat pesanan yang sudah dibayar dibatalkan."
            />
          )}
          <Pagination meta={resource.meta} page={resource.page} setPage={resource.setPage} unit="refund" />
        </section>
      )}
    </>
  );
}

export default RefundsPage;
