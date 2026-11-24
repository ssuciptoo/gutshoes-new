// V1: fitur refund ditunda. Samakan dengan GUTSHOES_REFUNDS_ENABLED di backend.
export const REFUNDS_ENABLED = import.meta.env.VITE_REFUNDS_ENABLED === "true";

// Judul halaman /returns: tanpa refund di aplikasi, halaman ini membahas pengembalian & penukaran.
export const RETURNS_TITLE = REFUNDS_ENABLED ? "Pengembalian & refund" : "Pengembalian & penukaran";
export const RETURNS_DESCRIPTION = REFUNDS_ENABLED
  ? "Ketentuan pengembalian barang dan refund di GutShoes: syarat, batas waktu, dan cara mengajukan."
  : "Ketentuan pengembalian dan penukaran barang di GutShoes: syarat, batas waktu, dan cara mengajukan.";
