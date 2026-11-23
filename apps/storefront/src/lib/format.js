// Tanggal akun, mis. "23 Nov 2026, 13.58". Kosong bila data belum tersedia.
export function formatAccountDate(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleString("id-ID", {dateStyle: "medium", timeStyle: "short"});
}
