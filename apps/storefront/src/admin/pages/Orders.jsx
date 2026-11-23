import React, {useState} from "react";
import {api} from "../../api";
import usePagedResource from "../hooks/usePagedResource";
import {statusOptions} from "../lib/helpers";
import DataToolbar from "../components/DataToolbar";
import Empty from "../components/Empty";
import OrderDrawer from "../components/OrderDrawer";
import OrdersTable from "../components/OrdersTable";
import PageHead from "../components/PageHead";
import Pagination from "../components/Pagination";
import ResourceState from "../components/ResourceState";

const FILTERS = statusOptions("Semua pesanan", [
  "PENDING_PAYMENT",
  "PAID",
  "PROCESSING",
  "SHIPPED",
  "DELIVERED",
  "CANCELLED",
]);

function OrdersPage() {
  const [selected, setSelected] = useState(null);
  const resource = usePagedResource(api.adminOrders);
  const rows = resource.rows.map((order) => ({
    raw: order,
    id: order.order_number,
    customer: order.customer_name || order.customer_email || "Guest",
    time: new Date(order.created_at).toLocaleString("id-ID"),
    total: Number(order.grand_total || 0),
    payment: order.payment?.status || "",
    status: order.status,
  }));
  return (
    <>
      <PageHead section="orders" />
      <DataToolbar
        query={resource.query}
        setQuery={resource.setQuery}
        filter={resource.filter}
        setFilter={resource.setFilter}
        options={FILTERS}
        placeholder="Cari nomor pesanan, nama, atau email…"
        searchLabel="Cari pesanan"
      />
      <ResourceState {...resource} />
      {!resource.loading && !resource.error && (
        <section className="adm-card adm-table-card">
          {rows.length ? (
            <OrdersTable rows={rows} onOpen={setSelected} />
          ) : (
            <Empty title="Pesanan tidak ditemukan" />
          )}
          <Pagination meta={resource.meta} page={resource.page} setPage={resource.setPage} unit="pesanan" />
        </section>
      )}
      {selected && (
        <OrderDrawer
          order={selected}
          onClose={() => setSelected(null)}
          onUpdated={resource.retry}
        />
      )}
    </>
  );
}

export default OrdersPage;
