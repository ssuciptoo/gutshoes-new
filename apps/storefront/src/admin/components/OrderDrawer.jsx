import React, {useEffect, useState} from "react";
import {api} from "../../api";
import {rupiah} from "../../data";
import {apiErrorMessage, formatDateTime, orderStatusView, statusLabel, statusTone} from "../lib/helpers";
import useDialogFocus from "../hooks/useDialogFocus";
import Badge from "./Badge";
import Button from "./Button";
import Icon from "./Icon";

// Catatan yang ditulis sistem (bukan admin) diterjemahkan untuk riwayat status.
const SYSTEM_NOTES = {
  "Order created": "Pesanan dibuat",
  "Midtrans payment confirmed": "Pembayaran Midtrans terkonfirmasi",
  "Payment window expired": "Batas waktu pembayaran habis",
  "Payment received without a complete reservation; refund requires review":
    "Pembayaran diterima tetapi stok tidak lagi tersedia; refund perlu ditinjau",
};
const translateNote = (note) =>
  SYSTEM_NOTES[note] ||
  (note?.startsWith("Midtrans payment failed: ")
    ? `Pembayaran Midtrans gagal: ${note.slice("Midtrans payment failed: ".length)}`
    : note);

function OrderDrawer({order, onClose, onUpdated}) {
  const dialogRef = useDialogFocus(onClose);
  const [detail, setDetail] = useState(null);
  const [loading, setLoading] = useState(true);
  const [action, setAction] = useState("");
  const [notice, setNotice] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const response = await api.adminOrder(order.raw.id);
      setDetail(response.data);
    } catch (err) {
      setError(apiErrorMessage(err, "Detail pesanan belum dapat dimuat."));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    load();
  }, [order.raw.id]);
  const apply = async (event) => {
    event.preventDefault();
    if (saving || !detail) return;
    setSaving(true);
    setError("");
    setNotice("");
    const data = new FormData(event.currentTarget);
    try {
      if (action === "cancel") {
        await api.cancel(
          detail.order_number,
          {
            email: detail.customer_email,
            reason: String(data.get("reason") || "Permintaan administrator"),
          },
          crypto.randomUUID(),
        );
        await load();
      } else {
        const next = {
          process: "PROCESSING",
          shipment: "SHIPPED",
          delivered: "DELIVERED",
        }[action];
        const response = await api.fulfillOrder(detail.id, {
          status: next,
          tracking_number:
            action === "shipment"
              ? String(data.get("tracking_number") || "").trim()
              : null,
          note: String(data.get("note") || "").trim() || null,
        });
        setDetail(response.data);
      }
      setNotice(
        action === "shipment"
          ? "Pesanan ditandai dikirim dan nomor resi sudah tersimpan."
          : "Status pesanan berhasil diperbarui.",
      );
      setAction("");
      onUpdated();
    } catch (err) {
      setError(
        apiErrorMessage(
          err,
          "Perubahan pesanan belum dapat disimpan. Periksa urutan status dan data yang dimasukkan.",
        ),
      );
    } finally {
      setSaving(false);
    }
  };
  const status = detail?.status || order.status;
  const statusView = orderStatusView(status, detail?.expires_at || order.raw.expires_at);
  const payment = detail?.payment;
  const refunds = detail?.refunds || [];
  const history = detail?.status_histories || [];
  const shipment = detail?.shipment;
  const address = detail?.addresses?.find((item) => item.type === "SHIPPING");
  const items = detail?.items || [];
  const courier = [shipment?.courier?.toUpperCase(), shipment?.service]
    .filter(Boolean)
    .join(" · ");
  const nextAction =
    status === "PAID"
      ? "process"
      : status === "PROCESSING"
        ? "shipment"
        : status === "SHIPPED"
          ? "delivered"
          : "";
  return (
    <div
      className="adm-overlay"
      role="presentation"
      onMouseDown={(event) => event.target === event.currentTarget && onClose()}
    >
      <aside
        ref={dialogRef}
        className="adm-drawer adm-order-drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="order-detail-title"
      >
        <header>
          <div>
            <small>DETAIL PESANAN</small>
            <h2 id="order-detail-title">{order.id}</h2>
          </div>
          <button onClick={onClose} aria-label="Tutup">
            <Icon name="close" />
          </button>
        </header>
        {notice && (
          <div className="adm-success" role="status">
            <Icon name="check" />
            {notice}
          </div>
        )}
        {error && (
          <div className="adm-feedback error adm-order-feedback" role="alert">
            {error}
            {!detail && (
              <button type="button" onClick={load}>
                Coba lagi
              </button>
            )}
          </div>
        )}
        {loading ? (
          <div className="adm-resource-state" role="status">
            Memuat detail pesanan…
          </div>
        ) : (
          detail && (
            <>
              <div className="adm-drawer-status">
                <Badge tone={statusTone(detail.payment?.status)}>
                  Pembayaran: {detail.payment?.status ? statusLabel(detail.payment.status) : "belum ada"}
                </Badge>
                <Badge tone={statusView.tone}>{statusView.label}</Badge>
              </div>
              <section>
                <h3>Pelanggan</h3>
                <p>
                  <strong>{detail.customer_name}</strong>
                  <br />
                  {detail.customer_email} · {detail.customer_phone}
                </p>
              </section>
              <section>
                <h3>Produk & snapshot</h3>
                {items.length ? (
                  items.map((item) => (
                    <div className="adm-line-item adm-order-item" key={item.id}>
                      <span>
                        <strong>{item.product_name}</strong>
                        <small>
                          {item.brand_name} · SKU {item.sku} · Ukuran{" "}
                          {item.size_label} × {item.quantity}
                        </small>
                      </span>
                      <strong>{rupiah(Number(item.line_total))}</strong>
                    </div>
                  ))
                ) : (
                  <p>Item pesanan tidak tersedia.</p>
                )}
              </section>
              <section>
                <h3>Alamat pengiriman</h3>
                {address ? (
                  <p>
                    <strong>{address.recipient_name}</strong> · {address.phone}
                    <br />
                    {address.address_line}
                    <br />
                    {address.district}, {address.city}, {address.province}{" "}
                    {address.postal_code}
                  </p>
                ) : (
                  <p>Snapshot alamat pengiriman tidak tersedia.</p>
                )}
              </section>
              <section>
                <h3>Pengiriman</h3>
                <p>
                  <strong>{courier || "Kurir belum ditentukan"}</strong>
                  <br />
                  Biaya{" "}
                  {rupiah(Number(shipment?.fee || detail.shipping_fee || 0))}
                  <br />
                  <small>
                    Nomor resi:{" "}
                    <b className="adm-tracking-number">
                      {shipment?.tracking_number || "Belum tersedia"}
                    </b>
                  </small>
                </p>
              </section>
              <section>
                <h3>Pembayaran</h3>
                {payment ? (
                  <dl className="adm-detail-list">
                    <div><dt>Status</dt><dd><Badge tone={statusTone(payment.status)}>{statusLabel(payment.status)}</Badge></dd></div>
                    <div><dt>Penyedia</dt><dd>{payment.provider === "MIDTRANS" ? "Midtrans" : payment.provider}{payment.provider_status ? ` · ${payment.provider_status}` : ""}</dd></div>
                    <div><dt>Jumlah</dt><dd>{rupiah(Number(payment.amount || 0))}</dd></div>
                    {payment.paid_at ? (
                      <div><dt>Dibayar</dt><dd>{formatDateTime(payment.paid_at)}</dd></div>
                    ) : (
                      <div><dt>Batas bayar</dt><dd className={statusView.tone === "danger" ? "adm-text-danger" : ""}>{formatDateTime(payment.expires_at || detail.expires_at)}</dd></div>
                    )}
                    {payment.provider_transaction_id && (
                      <div><dt>ID transaksi</dt><dd><code>{payment.provider_transaction_id}</code></dd></div>
                    )}
                  </dl>
                ) : (
                  <p>Belum ada pembayaran dibuat.</p>
                )}
                {statusView.label === "Lewat batas bayar" && (
                  <p className="adm-muted-copy">
                    Batas bayar sudah lewat. Sistem akan membatalkan pesanan ini secara otomatis dan mengembalikan stoknya.
                  </p>
                )}
              </section>
              {refunds.length > 0 && (
                <section>
                  <h3>Refund</h3>
                  {refunds.map((refund) => (
                    <div className="adm-line-item" key={refund.id}>
                      <span>
                        <Badge tone={statusTone(refund.status)}>{statusLabel(refund.status)}</Badge>
                        <small>{refund.reason} · {formatDateTime(refund.completed_at || refund.created_at)}</small>
                      </span>
                      <strong>{rupiah(Number(refund.amount || 0))}</strong>
                    </div>
                  ))}
                </section>
              )}
              {history.length > 0 && (
                <section>
                  <h3>Riwayat status</h3>
                  <ol className="adm-timeline">
                    {history.map((entry) => (
                      <li key={entry.id}>
                        <strong>{statusLabel(entry.to_status)}</strong>
                        <small>
                          {formatDateTime(entry.created_at)} · {entry.actor?.name || (entry.actor_id ? `Admin #${entry.actor_id}` : "Sistem")}
                        </small>
                        {entry.note && <span>{translateNote(entry.note)}</span>}
                      </li>
                    ))}
                  </ol>
                </section>
              )}
              <section>
                <h3>Aksi operasional</h3>
                <div className="adm-action-grid">
                  {nextAction === "process" && (
                    <button onClick={() => setAction("process")}>
                      Mulai proses pesanan
                    </button>
                  )}
                  {nextAction === "shipment" && (
                    <button onClick={() => setAction("shipment")}>
                      Masukkan resi & kirim
                    </button>
                  )}
                  {nextAction === "delivered" && (
                    <button onClick={() => setAction("delivered")}>
                      Tandai sudah diterima
                    </button>
                  )}
                  {["PENDING_PAYMENT", "PAID"].includes(status) && (
                    <button onClick={() => setAction("cancel")}>
                      Batalkan pesanan
                    </button>
                  )}
                  {!nextAction &&
                    !["PENDING_PAYMENT", "PAID"].includes(status) && (
                      <p className="adm-muted-copy">
                        Tidak ada transisi operasional berikutnya untuk status
                        ini.
                      </p>
                    )}
                </div>
              </section>
              {action && (
                <form
                  className="adm-inline-action"
                  onSubmit={apply}
                  aria-busy={saving}
                >
                  <strong>
                    {action === "shipment"
                      ? "Data pengiriman"
                      : action === "cancel"
                        ? "Konfirmasi pembatalan"
                        : action === "delivered"
                          ? "Konfirmasi pesanan diterima"
                          : "Mulai proses pesanan"}
                  </strong>
                  {action === "shipment" && (
                    <label>
                      Nomor resi
                      <input
                        name="tracking_number"
                        required
                        maxLength="255"
                        autoComplete="off"
                        placeholder="Masukkan nomor resi dari kurir"
                      />
                    </label>
                  )}
                  {action === "cancel" && status === "PAID" && (
                    <p className="adm-muted-copy">
                      Pesanan ini sudah dibayar. Pembatalan akan membuat refund
                      yang perlu diproses di halaman Refund.
                    </p>
                  )}
                  {action === "cancel" ? (
                    <label>
                      Alasan pembatalan
                      <textarea
                        name="reason"
                        required
                        maxLength="255"
                        defaultValue="Permintaan administrator"
                      />
                    </label>
                  ) : (
                    <label>
                      Catatan operasional (opsional)
                      <input
                        name="note"
                        maxLength="255"
                        placeholder="Catatan yang boleh tampil di riwayat pesanan"
                      />
                    </label>
                  )}
                  <div>
                    <Button
                      kind="secondary"
                      type="button"
                      onClick={() => setAction("")}
                      disabled={saving}
                    >
                      Batal
                    </Button>
                    <Button type="submit" disabled={saving}>
                      {saving ? "Menyimpan…" : "Konfirmasi"}
                    </Button>
                  </div>
                </form>
              )}
              <div className="adm-total">
                <span>Total pembayaran</span>
                <strong>{rupiah(Number(detail.grand_total))}</strong>
              </div>
            </>
          )
        )}
        <footer>
          <Button kind="secondary" onClick={onClose}>
            Tutup
          </Button>
        </footer>
      </aside>
    </div>
  );
}

export default OrderDrawer;
