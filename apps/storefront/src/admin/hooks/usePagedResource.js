import {useEffect, useRef, useState} from "react";
import {serverList, serverMeta} from "../lib/helpers";

// Listing admin dengan pencarian, filter, dan halaman di sisi server.
// loader menerima params ({page, per_page, search, ...}) dan mengembalikan respons API.
function usePagedResource(loader, {perPage = 25, filterParam = "status", extraParams = {}} = {}) {
  const [query, setQueryState] = useState("");
  const [search, setSearch] = useState("");
  const [filter, setFilterState] = useState("");
  const [page, setPage] = useState(1);
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState({
    loading: true,
    error: "",
    rows: [],
    meta: {page: 1, lastPage: 1, total: 0},
    loaded: false,
  });
  // Filter tambahan (mis. rentang tanggal); kunci string agar efek hanya jalan saat isinya berubah.
  const extraKey = JSON.stringify(extraParams);
  const loaderRef = useRef(loader);
  loaderRef.current = loader;

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(query.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [query]);

  useEffect(() => {
    let active = true;
    // Data lama tetap tampil saat memuat ulang agar tabel tidak berkedip.
    setState((previous) => ({...previous, loading: !previous.loaded, error: ""}));
    const params = {page, per_page: perPage};
    if (search) params.search = search;
    if (filter) params[filterParam] = filter;
    Object.entries(JSON.parse(extraKey)).forEach(([key, value]) => {
      if (value) params[key] = value;
    });
    loaderRef.current(params)
      .then((response) => {
        if (!active) return;
        const meta = serverMeta(response);
        if (page > meta.lastPage && meta.lastPage >= 1) {
          setPage(meta.lastPage);
          return;
        }
        setState({loading: false, error: "", rows: serverList(response.data), meta, loaded: true});
      })
      .catch((error) => {
        if (active)
          setState((previous) => ({
            ...previous,
            loading: false,
            error: error.message || "Server admin tidak dapat dijangkau.",
          }));
      });
    return () => {
      active = false;
    };
  }, [page, search, filter, attempt, perPage, filterParam, extraKey]);

  // Filter tambahan berubah → kembali ke halaman pertama.
  useEffect(() => {
    setPage(1);
  }, [extraKey]);

  return {
    ...state,
    query,
    setQuery: setQueryState,
    filter,
    setFilter: (value) => {
      setFilterState(value);
      setPage(1);
    },
    page,
    setPage,
    retry: () => setAttempt((value) => value + 1),
  };
}

export default usePagedResource;
