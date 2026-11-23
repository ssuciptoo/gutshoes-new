import React from "react";
import {rupiah} from "../../data";
import useAdminResource from "../hooks/useAdminResource";
import {loadDashboard} from "../lib/dashboard";
import {statusLabel, statusTone} from "../lib/helpers";
import Badge from "../components/Badge";

import Metric from "../components/Metric";
import Icon from "../components/Icon";
import PageHead from "../components/PageHead";
import ResourceState from "../components/ResourceState";

function Dashboard({navigate}) {
  const resource = useAdminResource(loadDashboard);
  const data = resource.data || {};
  const orders = data.orders || {};
  return (
    <>
      <PageHead section="dashboard" />
      <ResourceState {...resource} />
      {!resource.loading && !resource.error && (
        <>
          <section className="adm-dashboard-section" aria-labelledby="business-summary">
            <div className="adm-section-heading">
              <div>
                <h2 id="business-summary">Ringkasan bisnis</h2>
                <p>Angka utama penjualan dan ketersediaan hari ini.</p>
              </div>
            </div>
            <div className="adm-metrics">
              <Metric
                label="Pendapatan 30 hari"
                value={rupiah(Number(data.revenue?.last_30_days || 0))}
                delta={"Sepanjang waktu " + rupiah(Number(data.revenue?.paid_total || 0))}
              />
              <Metric
                label="Pesanan hari ini"
                value={orders.today || 0}
                delta={rupiah(Number(data.revenue?.today || 0))}
              />
              <Metric
                label="Menunggu pembayaran"
                value={orders.pending_payment || 0}
                delta="Belum dibayar"
                attention={Number(orders.pending_payment || 0) > 0}
              />
              <Metric
                label="Stok rendah"
                value={data.low_stock_count || 0}
                delta={"Ambang " + (data.low_stock_threshold || 0) + " unit"}
                attention={Number(data.low_stock_count || 0) > 0}
              />
            </div>
          </section>
          <section className="adm-dashboard-section" aria-labelledby="operations-queue">
            <div className="adm-section-heading">
              <div>
                <h2 id="operations-queue">Antrean operasional</h2>
                <p>Pekerjaan yang masih menunggu tindakan tim.</p>
              </div>
            </div>
            <div className="adm-metrics">
              <Metric
                label="Pesanan perlu diproses"
                value={(orders.paid || 0) + (orders.processing || 0)}
                delta="Lunas atau sedang diproses"
                attention={Number(orders.paid || 0) + Number(orders.processing || 0) > 0}
              />
              <Metric
                label="Pembayaran tertunda"
                value={data.operations?.payments_pending || 0}
                delta="Perlu rekonsiliasi"
                attention={Number(data.operations?.payments_pending || 0) > 0}
              />
              <Metric
                label="Pengiriman tertunda"
                value={data.operations?.shipments_pending || 0}
                delta="Perlu diproses"
                attention={Number(data.operations?.shipments_pending || 0) > 0}
              />
              <Metric
                label="Refund tertunda"
                value={data.operations?.refunds_pending || 0}
                delta="Perlu tindak lanjut"
                attention={Number(data.operations?.refunds_pending || 0) > 0}
              />
            </div>
          </section>
          <section className="adm-card adm-priority">
            <div className="adm-card-head">
              <div>
                <h2>Fokus operasional</h2>
                <p>Data langsung dari server.</p>
              </div>
            </div>
            <button onClick={() => navigate("orders")}>
              <span className="adm-priority-icon info">
                <Icon name="orders" />
              </span>
              <span>
                <strong>Kelola pesanan aktif</strong>
                <small>
                  {orders.paid || 0} pesanan lunas menunggu tindak lanjut
                </small>
              </span>
              <b>{orders.paid || 0}</b>
              <Icon name="arrow" />
            </button>
            <button onClick={() => navigate("inventory")}>
              <span className="adm-priority-icon warning">
                <Icon name="warehouse" />
              </span>
              <span>
                <strong>Periksa stok rendah</strong>
                <small>
                  Ambang peringatan {data.low_stock_threshold || 0} unit
                </small>
              </span>
              <b>{data.low_stock_count || 0}</b>
              <Icon name="arrow" />
            </button>
            {Number(data.operations?.refunds_pending || 0) > 0 && (
              <button onClick={() => navigate("refunds")}>
                <span className="adm-priority-icon danger">
                  <Icon name="refund" />
                </span>
                <span>
                  <strong>Proses refund tertunda</strong>
                  <small>Dana pelanggan belum dikembalikan</small>
                </span>
                <b>{data.operations.refunds_pending}</b>
                <Icon name="arrow" />
              </button>
            )}
          </section>
          <section className="adm-card adm-recent-orders" aria-labelledby="recent-orders-title">
            <div className="adm-card-head">
              <div>
                <h2 id="recent-orders-title">Pesanan terbaru</h2>
                <p>Lima pesanan terakhir yang masuk.</p>
              </div>
              <button onClick={() => navigate("orders")}>
                Lihat semua <Icon name="arrow" />
              </button>
            </div>
            {data.recent_orders?.length ? (
              <ul>
                {data.recent_orders.map((order) => (
                  <li key={order.id}>
                    <span>
                      <strong>{order.order_number}</strong>
                      <small>
                        {order.customer_name || order.customer_email || "Guest"} ·{" "}
                        {new Date(order.created_at).toLocaleString("id-ID", {dateStyle: "medium", timeStyle: "short"})}
                      </small>
                    </span>
                    <Badge tone={statusTone(order.status)}>{statusLabel(order.status)}</Badge>
                    <b>{rupiah(Number(order.grand_total || 0))}</b>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="adm-muted-copy adm-recent-empty">Belum ada pesanan.</p>
            )}
          </section>
        </>
      )}
    </>
  );
}

export default Dashboard;
