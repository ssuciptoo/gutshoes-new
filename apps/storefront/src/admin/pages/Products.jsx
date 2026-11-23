import React, {useState} from "react";
import {api} from "../../api";
import {rupiah} from "../../data";
import useAdminResource from "../hooks/useAdminResource";
import usePagedResource from "../hooks/usePagedResource";
import {productDraft, serverList, statusLabel, statusOptions, statusTone} from "../lib/helpers";
import Badge from "../components/Badge";
import DataToolbar from "../components/DataToolbar";
import Empty from "../components/Empty";
import Icon from "../components/Icon";
import PageHead from "../components/PageHead";
import Pagination from "../components/Pagination";
import ProductEditor from "../components/ProductEditor";
import ResourceState from "../components/ResourceState";

const FILTERS = statusOptions("Semua status", ["DRAFT", "PUBLISHED", "ARCHIVED"]);

function ProductsPage() {
  const [editing, setEditing] = useState(null);
  const products = usePagedResource(api.adminProducts);
  const lookups = useAdminResource(() =>
    Promise.all([api.adminBrands(), api.adminCategories(), api.adminSizes()]).then(
      ([brandsResponse, categoriesResponse, sizesResponse]) => ({
        data: {
          brands: serverList(brandsResponse.data),
          categories: serverList(categoriesResponse.data),
          sizes: serverList(sizesResponse.data),
        },
      }),
    ),
  );
  const rows = products.rows;
  const filtered = Boolean(products.query || products.filter);
  const close = () => setEditing(null);
  const refresh = () => products.retry();
  const loading = products.loading || lookups.loading;
  const error = products.error || lookups.error;
  return (
    <>
      <PageHead
        section="products"
        action="Tambah produk"
        onAction={() => setEditing(productDraft())}
      />
      <DataToolbar
        query={products.query}
        setQuery={products.setQuery}
        filter={products.filter}
        setFilter={products.setFilter}
        options={FILTERS}
        placeholder="Cari nama, slug, merek, atau SKU…"
        searchLabel="Cari produk"
      />
      <ResourceState
        loading={loading}
        error={error}
        retry={() => {
          products.retry();
          lookups.retry();
        }}
      />
      {!loading && !error && (
        <section className="adm-card adm-table-card">
          {rows.length ? (
            <div className="adm-table-wrap">
              <table className="adm-responsive-table adm-card-table">
                <thead>
                  <tr>
                    <th>Produk</th>
                    <th>Status</th>
                    <th>Varian</th>
                    <th>Kategori</th>
                    <th className="num">Harga mulai</th>
                    <th>
                      <span className="sr-only">Aksi</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((item) => (
                    <tr key={item.id}>
                      <td data-label="Produk">
                        <strong>{item.name}</strong>
                        <small>
                          {item.brand?.name || "Tanpa merek"} · {item.slug}
                        </small>
                      </td>
                      <td data-label="Status">
                        <Badge tone={statusTone(item.status)}>{statusLabel(item.status)}</Badge>
                      </td>
                      <td data-label="Varian">
                        {item.variants?.filter((variant) => variant.is_active)
                          .length || 0}{" "}
                        aktif
                      </td>
                      <td data-label="Kategori">
                        {item.categories
                          ?.map((category) => category.name)
                          .join(", ") || "—"}
                      </td>
                      <td data-label="Harga mulai" className="num">
                        <strong>
                          {item.variants?.length
                            ? rupiah(
                                Math.min(
                                  ...item.variants.map((variant) =>
                                    Number(variant.price),
                                  ),
                                ),
                              )
                            : "—"}
                        </strong>
                      </td>
                      <td data-label="Aksi">
                        <button
                          className="adm-row-action"
                          onClick={() => setEditing(item)}
                          aria-label={`Edit ${item.name}`}
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
              title="Produk tidak ditemukan"
              text={
                filtered
                  ? "Coba ubah pencarian atau filter."
                  : "Tambahkan produk pertama untuk mulai mengelola katalog."
              }
            />
          )}
          <Pagination meta={products.meta} page={products.page} setPage={products.setPage} unit="produk" />
        </section>
      )}
      {editing && lookups.data && (
        <ProductEditor
          product={editing.id ? editing : null}
          brands={lookups.data.brands}
          categories={lookups.data.categories}
          sizes={lookups.data.sizes}
          onClose={() => {
            close();
            refresh();
          }}
          onSaved={refresh}
          onDeleted={() => {
            close();
            refresh();
          }}
        />
      )}
    </>
  );
}

export default ProductsPage;
