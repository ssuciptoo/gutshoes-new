const STOREFRONT_PAGES = new Set([
  "home",
  "catalog",
  "product",
  "cart",
  "checkout",
  "payment",
  "guest-orders",
  "tracking",
  "login",
  "profile",
  "addresses",
  "orders",
  "order-detail",
  "contact",
  "privacy",
  "terms",
  "returns",
  "size-guide",
]);

const ADMIN_SECTIONS = new Set([
  "dashboard",
  "products",
  "catalog",
  "sizes",
  "inventory",
  "orders",
  "refunds",
  "customers",
  "promotions",
  "settings",
  "audit",
]);

const STOREFRONT_TITLES = {
  home: "Sepatu olahraga untuk setiap langkah",
  catalog: "Katalog sepatu olahraga",
  product: "Detail produk",
  cart: "Keranjang belanja",
  checkout: "Checkout",
  payment: "Status pembayaran",
  "guest-orders": "Pesanan tanpa akun",
  tracking: "Lacak pesanan",
  contact: "Hubungi kami",
  privacy: "Kebijakan privasi",
  terms: "Syarat & ketentuan",
  returns: "Pengembalian & refund",
  "size-guide": "Panduan ukuran sepatu",
  login: "Masuk ke akun",
  profile: "Profil saya",
  addresses: "Alamat saya",
  orders: "Riwayat pesanan",
  "order-detail": "Detail pesanan",
  "not-found": "Halaman tidak ditemukan",
};

const ADMIN_TITLES = {
  dashboard: "Dashboard",
  products: "Produk",
  catalog: "Katalog",
  sizes: "Ukuran",
  inventory: "Inventaris",
  orders: "Pesanan",
  refunds: "Refund",
  customers: "Pelanggan",
  promotions: "Promosi",
  settings: "Pengaturan",
  audit: "Audit log",
};

// Toko memakai alamat biasa (/product/junior-dash), bukan hash (#product/junior-dash),
// agar setiap halaman bisa diindeks mesin pencari dan dibagikan dengan pratinjau sendiri.
const decode = (value) => {
  try {
    return decodeURIComponent(value || "");
  } catch {
    return "";
  }
};

function routeFromParts(segments, search) {
  const [first = "", second = ""] = segments;
  const parameter = decode(second);
  if (!first) return {page: "home", data: {}};
  if (first === "admin") {
    return {
      page: "admin",
      data: {
        section: ADMIN_SECTIONS.has(parameter) ? parameter : "dashboard",
        // Parameter halaman admin, mis. /admin/orders?order=GS-123 atau ?status=OVERDUE
        params: Object.fromEntries(new URLSearchParams(search)),
      },
    };
  }
  // Detail pesanan: /orders/GS-… (alamat lama #order-detail/GS-… tetap dikenali).
  if ((first === "orders" && parameter) || first === "order-detail") {
    return {page: "order-detail", data: {orderNumber: parameter}};
  }
  if (first === "product") return parameter ? {page: "product", data: {slug: parameter}} : {page: "catalog", data: {}};
  if (first === "catalog") {
    const params = new URLSearchParams(search);
    return {
      page: "catalog",
      data: {
        filter: params.get("filter") || undefined,
        brand: params.get("brand") || undefined,
        sort: params.get("sort") || undefined,
      },
    };
  }
  if (segments.length === 1 && STOREFRONT_PAGES.has(first)) return {page: first, data: {}};
  return {page: "not-found", data: {}};
}

export function readRoute(location = window.location) {
  const url = typeof location === "string" ? new URL(location, window.location.origin) : location;
  const segments = url.pathname.split("/").filter(Boolean);
  return routeFromParts(segments, url.search);
}

export function routeUrl(page, data = {}) {
  if (page === "admin") {
    const section = ADMIN_SECTIONS.has(data.section) ? data.section : "dashboard";
    const query = new URLSearchParams(
      Object.entries(data.params || {}).filter(([, value]) => value),
    ).toString();
    return `/admin/${section}${query ? `?${query}` : ""}`;
  }
  if (page === "home" || !STOREFRONT_PAGES.has(page)) return "/";
  if (page === "product") {
    const slug = data.product?.slug || data.product?.id || data.slug || "";
    return `/product/${encodeURIComponent(slug)}`;
  }
  if (page === "catalog") {
    // Kategori, merek, dan urutan dari navbar: /catalog?filter=lari, ?brand=aerorun, ?sort=best_selling
    const query = new URLSearchParams(
      ["filter", "brand", "sort"].filter((key) => data[key]).map((key) => [key, data[key]]),
    ).toString();
    return `/catalog${query ? `?${query}` : ""}`;
  }
  if (page === "order-detail") {
    return `/orders/${encodeURIComponent(data.orderNumber || "")}`;
  }
  return `/${page}`;
}

/**
 * Tautan lama berbentuk /#product/junior-dash (email, WhatsApp, bookmark) diubah ke alamat
 * baru tanpa memuat ulang. Hash biasa seperti #main (tautan lompat) dibiarkan.
 * @returns {string|null} alamat baru bila ada yang dialihkan.
 */
export function legacyHashUrl(location = window.location) {
  if (location.pathname !== "/" || !location.hash.startsWith("#")) return null;
  const raw = location.hash.slice(1);
  const [path, search = ""] = raw.split("?");
  const segments = path.split("/").filter(Boolean);
  const first = segments[0] || "";
  if (first !== "admin" && first !== "order-detail" && !STOREFRONT_PAGES.has(first)) return null;
  const route = routeFromParts(segments, search ? `?${search}` : "");
  if (route.page === "not-found") return null;
  // Query lama di depan hash (mis. ?guest_order=…&token=… dari email) tetap dibawa.
  return routeUrl(route.page, route.data) + (route.page === "catalog" || route.page === "admin" ? "" : location.search);
}

export function isAdminSection(section) {
  return ADMIN_SECTIONS.has(section);
}

export function routeTitle(route, productName = "") {
  if (route.page === "admin") {
    return `${ADMIN_TITLES[route.data.section] || "Admin"} — Admin GutShoes`;
  }
  if (route.page === "product" && productName) {
    return `${productName} — GutShoes`;
  }
  if (route.page === "order-detail" && route.data.orderNumber) {
    return `Pesanan ${route.data.orderNumber} — GutShoes`;
  }
  if (route.page === "home") return `GutShoes — ${STOREFRONT_TITLES.home}`;
  return `${STOREFRONT_TITLES[route.page] || STOREFRONT_TITLES.home} — GutShoes`;
}

// Halaman publik yang boleh diindeks mesin pencari. Halaman lain (keranjang, checkout,
// akun, pesanan, admin, halaman tidak ditemukan) diberi noindex.
const INDEXABLE_PAGES = new Set(["home", "catalog", "product", "tracking", "contact", "privacy", "terms", "returns", "size-guide"]);

const STOREFRONT_DESCRIPTIONS = {
  home: "Belanja sepatu olahraga untuk lari, training, basket, futsal, dan anak di GutShoes. Pilih ukuran, cek stok, ongkir dihitung otomatis sebelum membayar.",
  catalog: "Lihat semua sepatu olahraga GutShoes. Saring berdasarkan aktivitas, merek, ukuran, dan harga, lalu cek stok setiap ukuran.",
  tracking: "Lacak status pesanan GutShoes dengan nomor pesanan dan email yang dipakai saat checkout.",
  contact: "Hubungi layanan pelanggan GutShoes untuk pertanyaan produk, pesanan, pengiriman, dan pengembalian.",
  privacy: "Kebijakan privasi GutShoes: data apa yang kami kumpulkan, untuk apa dipakai, dan bagaimana kami melindunginya.",
  terms: "Syarat dan ketentuan berbelanja di GutShoes, termasuk pemesanan, pembayaran, dan pengiriman.",
  "size-guide": "Cara mengukur panjang kaki dan tabel konversi ukuran sepatu EU, UK, US, dan cm, plus tips memilih ukuran untuk lari, futsal, basket, dan anak.",
  returns: "Ketentuan pengembalian barang dan refund di GutShoes: syarat, batas waktu, dan cara mengajukan.",
};

export function isIndexable(page) {
  return INDEXABLE_PAGES.has(page);
}

// Tag SEO bawaan untuk sebuah route (halaman produk & kategori melengkapinya sendiri).
export function routeSeo(route) {
  const noindex = route.page === "admin" || !INDEXABLE_PAGES.has(route.page);
  return {
    title: routeTitle(route),
    ...(STOREFRONT_DESCRIPTIONS[route.page] ? {description: STOREFRONT_DESCRIPTIONS[route.page]} : {}),
    path: noindex ? null : routeUrl(route.page, route.data),
    noindex,
  };
}
