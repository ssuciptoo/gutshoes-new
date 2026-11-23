import React from "react";

function Pagination({meta, page, setPage, unit = "data"}) {
  if (!meta || meta.total === 0) return null;
  const {lastPage, total} = meta;
  return (
    <nav className="adm-pagination" aria-label="Halaman data">
      <span>
        {total.toLocaleString("id-ID")} {unit} · halaman {page} dari {lastPage}
      </span>
      {lastPage > 1 && (
        <div>
          <button type="button" onClick={() => setPage(page - 1)} disabled={page <= 1}>
            ← Sebelumnya
          </button>
          <button type="button" onClick={() => setPage(page + 1)} disabled={page >= lastPage}>
            Berikutnya →
          </button>
        </div>
      )}
    </nav>
  );
}

export default Pagination;
