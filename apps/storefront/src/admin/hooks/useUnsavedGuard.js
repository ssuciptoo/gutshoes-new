import {useCallback, useEffect, useRef} from "react";

const MESSAGE = "Perubahan belum disimpan. Tutup tanpa menyimpan?";

// Mencegah editor tertutup (Esc, klik luar, tombol Tutup, muat ulang tab) saat ada perubahan.
// Mengembalikan fungsi penutup yang meminta konfirmasi bila isDirty bernilai true.
function useUnsavedGuard(isDirty, onClose) {
  const dirtyRef = useRef(isDirty);
  dirtyRef.current = isDirty;
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  useEffect(() => {
    if (!isDirty) return undefined;
    const warn = (event) => {
      event.preventDefault();
      event.returnValue = "";
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [isDirty]);

  return useCallback(() => {
    if (dirtyRef.current && !window.confirm(MESSAGE)) return;
    closeRef.current?.();
  }, []);
}

export default useUnsavedGuard;
