import React from "react";
import {api} from "../../api";
import usePagedResource from "../hooks/usePagedResource";
import DataToolbar from "../components/DataToolbar";
import Empty from "../components/Empty";
import PageHead from "../components/PageHead";
import Pagination from "../components/Pagination";
import ResourceState from "../components/ResourceState";

function AuditPage() {
  const resource = usePagedResource(api.adminAuditLogs, {perPage: 50});
  const rows = resource.rows;
  return (
    <>
      <PageHead section="audit" />
      <DataToolbar
        query={resource.query}
        setQuery={resource.setQuery}
        filter={resource.filter}
        setFilter={resource.setFilter}
        options={[["", "Semua tindakan"]]}
        placeholder="Cari tindakan atau objek…"
        searchLabel="Cari audit log"
      />
      <ResourceState {...resource} />
      {!resource.loading && !resource.error && (
        <section className="adm-card adm-table-card">
          {rows.length ? (
            <div className="adm-table-wrap">
              <table className="adm-responsive-table">
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
                  {rows.map((item) => (
                    <tr key={item.id}>
                      <td data-label="Waktu">
                        {new Date(item.created_at).toLocaleString("id-ID")}
                      </td>
                      <td data-label="Admin">{item.admin_id || "Sistem"}</td>
                      <td data-label="Tindakan">
                        <strong>{item.action}</strong>
                      </td>
                      <td data-label="Objek">
                        {item.entity_type
                          ? item.entity_type + " #" + (item.entity_id || "—")
                          : "—"}
                      </td>
                      <td data-label="IP">
                        <code>{item.ip_address || "—"}</code>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <Empty text="Belum ada mutasi admin yang tercatat." />
          )}
          <Pagination meta={resource.meta} page={resource.page} setPage={resource.setPage} unit="catatan" />
        </section>
      )}
    </>
  );
}

export default AuditPage;
