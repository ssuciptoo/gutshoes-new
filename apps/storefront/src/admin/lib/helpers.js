// Kode status dari API → [label Indonesia, tone badge].
const STATUS = {
  // Pesanan
  PENDING_PAYMENT: ["Menunggu pembayaran", "warning"],
  PAID: ["Lunas", "success"],
  PROCESSING: ["Diproses", "info"],
  SHIPPED: ["Dikirim", "info"],
  DELIVERED: ["Diterima", "success"],
  CANCELLED: ["Dibatalkan", "danger"],
  EXPIRED: ["Kedaluwarsa", "danger"],
  REFUNDED: ["Dikembalikan", "danger"],
  // Pembayaran & refund
  PENDING: ["Menunggu", "warning"],
  REQUESTED: ["Diajukan", "warning"],
  SUCCESS: ["Berhasil", "success"],
  FAILED: ["Gagal", "danger"],
  // Produk
  DRAFT: ["Draf", "warning"],
  PUBLISHED: ["Dipublikasikan", "success"],
  ARCHIVED: ["Diarsipkan", "danger"],
  // Jenis diskon
  PERCENTAGE: ["Persentase", "info"],
  FIXED_AMOUNT: ["Potongan nominal", "info"],
  FREE_SHIPPING: ["Gratis ongkir", "info"],
};
const humanize = (code) =>
  code.charAt(0) + code.slice(1).toLowerCase().replaceAll("_", " ");
export const statusLabel = (code) =>
  code ? STATUS[code]?.[0] || humanize(String(code)) : "Belum ada";
export const statusTone = (code) => STATUS[code]?.[1] || "info";
// Opsi filter: [value, label]; value "" berarti semua.
export const statusOptions = (allLabel, codes) => [
  ["", allLabel],
  ...codes.map((code) => [code, statusLabel(code)]),
];
export const serverList = (data) => (Array.isArray(data) ? data : data?.data || []);
// Mendukung {data, meta} (listing admin) maupun paginator Laravel di dalam data.
export const serverMeta = (response) => {
  const source = response?.meta || response?.data || {};
  return {
    page: Number(source.current_page || 1),
    lastPage: Number(source.last_page || 1),
    total: Number(source.total ?? serverList(response?.data).length),
  };
};
export function productDraft(product) {
  return product
    ? {
        ...product,
        brand_id: String(product.brand_id || product.brand?.id || ""),
        category_ids: (product.categories || []).map((item) => Number(item.id)),
        variants: (product.variants || []).map((variant) => ({
          id: variant.id,
          size_id: String(variant.size_id || variant.size?.id || ""),
          sku: variant.sku,
          price: String(variant.price),
          weight_grams: String(variant.weight_grams),
          is_active: Boolean(variant.is_active),
        })),
      }
    : {
        id: null,
        name: "",
        slug: "",
        brand_id: "",
        description: "",
        status: "DRAFT",
        category_ids: [],
        variants: [
          {
            size_id: "",
            sku: "",
            price: "",
            weight_grams: "900",
            is_active: true,
          },
        ],
        images: [],
      };
}
export const slugify = (value) =>
  value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-|-$/g, "");
export const apiErrorMessage = (error, fallback) =>
  Object.values(error?.errors || {}).flat()[0] || error?.message || fallback;
