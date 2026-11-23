import React, {useState} from "react";
import {api} from "../../api";
import usePagedResource from "../hooks/usePagedResource";
import Badge from "../components/Badge";
import CustomerDrawer from "../components/CustomerDrawer";
import DataToolbar from "../components/DataToolbar";
import Empty from "../components/Empty";
import Icon from "../components/Icon";
import PageHead from "../components/PageHead";
import Pagination from "../components/Pagination";
import ResourceState from "../components/ResourceState";

const FILTERS = [
  ["", "Semua pelanggan"],
  ["1", "Email terverifikasi"],
  ["0", "Belum verifikasi"],
];

function CustomersPage({navigate}) {
  const [selected, setSelected] = useState(null);
  const resource = usePagedResource(api.adminCustomers, {filterParam: "verified"});
  const rows = resource.rows;
  return (
    <>
      <PageHead section="customers" />
      <DataToolbar
        query={resource.query}
        setQuery={resource.setQuery}
        filter={resource.filter}
        setFilter={resource.setFilter}
        options={FILTERS}
        placeholder="Cari nama atau email…"
        searchLabel="Cari pelanggan"
        filterLabel="Filter verifikasi email"
      />
      <ResourceState {...resource} />
      {!resource.loading && !resource.error && (
        <section className="adm-card adm-table-card">
          {rows.length ? (
            <div className="adm-table-wrap">
              <table className="adm-responsive-table adm-card-table">
                <thead>
                  <tr>
                    <th>Pelanggan</th>
                    <th>Email</th>
                    <th>Bergabung</th>
                    <th className="num">Pesanan</th>
                    <th>
                      <span className="sr-only">Aksi</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((item) => (
                    <tr key={item.id}>
                      <td data-label="Pelanggan">
                        <strong>{item.name || "Pelanggan"}</strong>
                        <small>{item.email}</small>
                      </td>
                      <td data-label="Email">
                        <Badge tone={item.email_verified_at ? "success" : "warning"}>
                          {item.email_verified_at ? "Terverifikasi" : "Belum verifikasi"}
                        </Badge>
                      </td>
                      <td data-label="Bergabung">
                        {new Date(item.created_at).toLocaleDateString("id-ID")}
                      </td>
                      <td data-label="Pesanan" className="num">
                        {Number(item.orders_count || 0)}
                      </td>
                      <td data-label="Aksi">
                        <button
                          className="adm-row-action"
                          onClick={() => setSelected(item.id)}
                          aria-label={`Lihat detail ${item.name || item.email}`}
                        >
                          <Icon name="arrow" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <Empty
              title="Pelanggan tidak ditemukan"
              text={resource.query || resource.filter ? "Coba ubah pencarian atau filter." : "Belum ada pelanggan tersimpan."}
            />
          )}
          <Pagination meta={resource.meta} page={resource.page} setPage={resource.setPage} unit="pelanggan" />
        </section>
      )}
      {selected && <CustomerDrawer
          customerId={selected}
          onClose={() => setSelected(null)}
          onOpenOrder={(orderNumber) => navigate?.("orders", {order: orderNumber})}
        />}
    </>
  );
}

export default CustomersPage;
