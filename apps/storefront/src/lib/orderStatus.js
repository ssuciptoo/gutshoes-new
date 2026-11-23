// Label status pesanan & pembayaran untuk pembeli (API memakai kode bahasa Inggris).
export const ORDER_STATUS = {
  PENDING_PAYMENT: "Menunggu pembayaran",
  PAID: "Pembayaran diterima",
  PROCESSING: "Sedang diproses",
  SHIPPED: "Sedang dikirim",
  DELIVERED: "Sudah diterima",
  CANCELLED: "Dibatalkan",
  EXPIRED: "Kedaluwarsa",
};

export const PAYMENT_STATUS = {
  PENDING: "Menunggu pembayaran",
  SUCCESS: "Berhasil",
  FAILED: "Gagal / kedaluwarsa",
};

export const REFUND_STATUS = {
  PENDING: "Sedang diproses",
  SUCCESS: "Dana dikembalikan",
  FAILED: "Perlu ditinjau",
};

export const orderStatusLabel = (status) => ORDER_STATUS[status] || status || "—";
export const paymentStatusLabel = (status) => PAYMENT_STATUS[status] || status || "Belum dimulai";

// Pesanan masih bisa dibayar bila menunggu pembayaran dan batas bayarnya belum lewat.
export const canContinuePayment = (order) =>
  order?.status === "PENDING_PAYMENT" &&
  (!order.expires_at || new Date(order.expires_at).getTime() > Date.now());

export const formatDeadline = (value) =>
  value
    ? new Date(value).toLocaleString("id-ID", {dateStyle: "medium", timeStyle: "short"})
    : "";

const COURIERS = {jne: "JNE", jnt: "J&T", tiki: "TIKI", pos: "POS", sicepat: "SiCepat", ninja: "Ninja Xpress", anteraja: "AnterAja"};
// "sicepat" + "REG" → "SiCepat REG"
export const courierLabel = (courier, service) =>
  [COURIERS[String(courier || "").toLowerCase()] || String(courier || "").toUpperCase(), service].filter(Boolean).join(" ");
