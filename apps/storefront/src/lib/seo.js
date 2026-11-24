// Tag SEO per halaman: judul, deskripsi, canonical, Open Graph, robots, dan JSON-LD.
// index.html hanya berisi nilai beranda; setiap pindah halaman, tag di <head> diperbarui di sini.

export const SITE_NAME = "GutShoes";
export const SITE_URL = (import.meta.env.VITE_SITE_URL || window.location.origin).replace(/\/$/, "");
export const DEFAULT_DESCRIPTION =
  "Temukan sepatu olahraga sesuai aktivitas, ukuran, dan anggaranmu. Ongkir dihitung otomatis sebelum membayar.";
const DEFAULT_IMAGE = `${SITE_URL}/og-image.jpg`;

export const absoluteUrl = (path = "/") => (/^https?:\/\//i.test(path) ? path : `${SITE_URL}${path.startsWith("/") ? path : `/${path}`}`);

// Deskripsi dibatasi ±160 karakter (batas tampilan cuplikan Google), dipotong di batas kata.
export function shortDescription(text, limit = 160) {
  const clean = String(text || "").replace(/\s+/g, " ").trim();
  if (clean.length <= limit) return clean;
  const cut = clean.slice(0, limit - 1);
  return `${cut.slice(0, Math.max(cut.lastIndexOf(" "), limit * 0.6)).replace(/[\s,.;:–-]+$/, "")}…`;
}

function setMeta(attribute, key, content) {
  let element = document.head.querySelector(`meta[${attribute}="${key}"]`);
  if (content === null || content === undefined || content === "") {
    element?.remove();
    return;
  }
  if (!element) {
    element = document.createElement("meta");
    element.setAttribute(attribute, key);
    document.head.appendChild(element);
  }
  element.setAttribute("content", content);
}

function setCanonical(url) {
  let element = document.head.querySelector('link[rel="canonical"]');
  if (!url) {
    element?.remove();
    return;
  }
  if (!element) {
    element = document.createElement("link");
    element.rel = "canonical";
    document.head.appendChild(element);
  }
  element.href = url;
}

function setJsonLd(data) {
  const id = "seo-json-ld";
  let element = document.getElementById(id);
  const items = [].concat(data || []).filter(Boolean);
  if (!items.length) {
    element?.remove();
    return;
  }
  if (!element) {
    element = document.createElement("script");
    element.type = "application/ld+json";
    element.id = id;
    document.head.appendChild(element);
  }
  // "<" di-escape agar teks produk tidak bisa menutup tag <script>.
  element.textContent = JSON.stringify(items.length === 1 ? items[0] : items).replace(/</g, "\\u003c");
}

/**
 * Terapkan tag SEO halaman.
 * - path: alamat canonical (tanpa parameter pelacakan); null untuk halaman noindex.
 * - noindex: halaman pribadi/transaksi (keranjang, checkout, akun, admin) tidak diindeks.
 */
export function applySeo({title, description = DEFAULT_DESCRIPTION, path = null, image = DEFAULT_IMAGE, imageAlt = "", type = "website", noindex = false, jsonLd = null}) {
  const desc = shortDescription(description);
  const canonical = !noindex && path ? absoluteUrl(path) : null;
  document.title = title;
  setMeta("name", "description", desc);
  setMeta("name", "robots", noindex ? "noindex, nofollow" : null);
  setCanonical(canonical);
  setMeta("property", "og:type", type);
  setMeta("property", "og:url", canonical);
  setMeta("property", "og:title", title);
  setMeta("property", "og:description", desc);
  setMeta("property", "og:image", image || DEFAULT_IMAGE);
  setMeta("property", "og:image:alt", imageAlt || title);
  // Ukuran 1200×630 hanya berlaku untuk gambar bawaan; foto produk ukurannya beragam.
  setMeta("property", "og:image:width", image === DEFAULT_IMAGE ? "1200" : null);
  setMeta("property", "og:image:height", image === DEFAULT_IMAGE ? "630" : null);
  setJsonLd(noindex ? null : jsonLd);
}

// Data terstruktur toko (beranda): nama, logo, kontak — dari data toko di Pengaturan admin.
export function organizationJsonLd(info = {}) {
  const contactPoint = info.email || info.whatsapp
    ? [{"@type": "ContactPoint", contactType: "customer service", areaServed: "ID", availableLanguage: "id",
        ...(info.email ? {email: info.email} : {}), ...(info.whatsapp ? {telephone: info.whatsapp} : {})}]
    : undefined;
  return [
    {
      "@context": "https://schema.org",
      "@type": "OnlineStore",
      name: SITE_NAME,
      ...(info.legal_name ? {legalName: info.legal_name} : {}),
      url: `${SITE_URL}/`,
      logo: `${SITE_URL}/favicon.png`,
      ...(info.email ? {email: info.email} : {}),
      ...(contactPoint ? {contactPoint} : {}),
    },
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      name: SITE_NAME,
      url: `${SITE_URL}/`,
      inLanguage: "id-ID",
    },
  ];
}

// Data terstruktur produk: harga, stok, merek, foto — agar bisa tampil sebagai hasil kaya di Google.
export function productJsonLd(product) {
  const url = absoluteUrl(`/product/${encodeURIComponent(product.slug)}`);
  const variants = product.variants || [];
  const prices = variants.map((variant) => variant.price).filter((price) => Number.isFinite(price) && price > 0);
  const inStock = variants.some((variant) => variant.stock > 0);
  const availability = `https://schema.org/${inStock ? "InStock" : "OutOfStock"}`;
  const low = prices.length ? Math.min(...prices) : product.price;
  const high = prices.length ? Math.max(...prices) : product.price;
  const offers = low === high
    ? {"@type": "Offer", url, priceCurrency: "IDR", price: String(low), availability, itemCondition: "https://schema.org/NewCondition"}
    : {"@type": "AggregateOffer", url, priceCurrency: "IDR", lowPrice: String(low), highPrice: String(high), offerCount: variants.length, availability, itemCondition: "https://schema.org/NewCondition"};
  const category = product.categories?.[0];
  return [
    {
      "@context": "https://schema.org",
      "@type": "Product",
      name: product.name,
      url,
      ...(product.images?.length ? {image: product.images.map((image) => image.src)} : {}),
      ...(product.description ? {description: shortDescription(product.description, 5000)} : {}),
      ...(variants[0]?.sku ? {sku: variants[0].sku} : {}),
      brand: {"@type": "Brand", name: product.brand},
      ...(category ? {category: category.name} : {}),
      ...(low > 0 ? {offers} : {}),
    },
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      itemListElement: [
        {"@type": "ListItem", position: 1, name: "Beranda", item: `${SITE_URL}/`},
        {"@type": "ListItem", position: 2, name: "Semua sepatu", item: absoluteUrl("/catalog")},
        ...(category ? [{"@type": "ListItem", position: 3, name: category.name, item: absoluteUrl(`/catalog?filter=${encodeURIComponent(category.slug)}`)}] : []),
        {"@type": "ListItem", position: category ? 4 : 3, name: product.name, item: url},
      ],
    },
  ];
}
