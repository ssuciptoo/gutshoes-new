import React, {useEffect, useRef, useState} from "react";
import logoGutShoes from "../../assets/logo-gutshoes.webp";
import Icon from "../ui/Icon";
import {RETURNS_TITLE} from "../../lib/features";

const HELP_ITEMS = [
  {label: "Lacak pesanan", page: "tracking"},
  {label: "Panduan ukuran", page: "size-guide"},
  {label: RETURNS_TITLE, page: "returns"},
  {label: "Hubungi kami", page: "contact"},
  {label: "Pesanan tanpa akun", page: "guest-orders"},
];

function Header({cartCount, navigate, query, setQuery, user, page, pageData = {}, categories, brands = []}) {
  const [open, setOpen] = useState(null);
  const [panelPos, setPanelPos] = useState({});
  const navRef = useRef(null);
  const onCatalog = page === "catalog";
  const isBestSelling = onCatalog && pageData.sort === "best_selling";
  const isCatalogAll = onCatalog && !pageData.filter && !pageData.brand && !isBestSelling;
  const menus = [
    {
      id: "kategori",
      label: "Kategori",
      active: onCatalog && Boolean(pageData.filter),
      items: categories.map((c) => ({label: c.name, page: "catalog", data: {filter: c.slug}, resetQuery: true, current: onCatalog && pageData.filter === c.slug})),
    },
    {
      id: "merek",
      label: "Merek",
      active: onCatalog && Boolean(pageData.brand),
      items: brands.map((b) => ({label: b.name, page: "catalog", data: {brand: b.slug}, resetQuery: true, current: onCatalog && pageData.brand === b.slug})),
    },
    {
      id: "bantuan",
      label: "Bantuan",
      active: HELP_ITEMS.some((item) => item.page === page),
      items: HELP_ITEMS.map((item) => ({...item, current: item.page === page})),
    },
  ];
  // Panel memakai position: fixed agar tidak terpotong oleh navbar yang bisa digeser di HP.
  const toggle = (id, trigger) => {
    if (open === id) return setOpen(null);
    const nav = navRef.current.getBoundingClientRect();
    const rect = trigger.getBoundingClientRect();
    setPanelPos({top: nav.bottom, left: Math.max(12, Math.min(rect.left - 12, window.innerWidth - 252))});
    setOpen(id);
  };
  const go = (next, data, resetQuery) => {
    setOpen(null);
    if (resetQuery) setQuery("");
    navigate(next, data);
  };
  useEffect(() => {
    if (!open) return undefined;
    const close = () => setOpen(null);
    const outside = (e) => !navRef.current?.contains(e.target) && close();
    const escape = (e) => e.key === "Escape" && close();
    document.addEventListener("pointerdown", outside);
    document.addEventListener("keydown", escape);
    window.addEventListener("resize", close);
    window.addEventListener("scroll", close, {passive: true});
    navRef.current?.addEventListener("scroll", close, {passive: true});
    const nav = navRef.current;
    return () => {
      document.removeEventListener("pointerdown", outside);
      document.removeEventListener("keydown", escape);
      window.removeEventListener("resize", close);
      window.removeEventListener("scroll", close);
      nav?.removeEventListener("scroll", close);
    };
  }, [open]);
  return (
    <>
      <header className="header">
        <button
          className="brand"
          onClick={() => navigate("home")}
          aria-label="Ke beranda GutShoes"
        >
          <img src={logoGutShoes} alt="GutShoes" width="600" height="152" decoding="async" fetchPriority="high" />
        </button>
        <label className="search">
          <Icon name="search" />
          <input
            maxLength={100}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => e.key === "Enter" && navigate("catalog")}
            placeholder="Cari nama sepatu, merek, atau SKU"
            aria-label="Cari produk"
          />
        </label>
        <nav className="utility" aria-label="Menu pengguna">
          {user?.role === "ADMIN" && (
            <button
              className="admin-access"
              onClick={() => navigate("admin", {section: "dashboard"})}
              aria-label="Buka admin panel"
            >
              <Icon name="shield" />
              <span>Admin panel</span>
            </button>
          )}
          <button
            className="orders-button"
            onClick={() => navigate(user && user.role !== "ADMIN" ? "orders" : "guest-orders")}
            aria-label="Buka pesanan saya"
          >
            <Icon name="package" />
            <span>Pesanan saya</span>
          </button>
          <button
            className="account-button"
            onClick={() => navigate(user ? "profile" : "login")}
            aria-label={user ? `Buka profil ${user.name}` : "Masuk ke akun"}
          >
            {user ? (
              <span className="account-avatar" aria-hidden="true">
                {user.name.charAt(0)}
              </span>
            ) : (
              <Icon name="user" />
            )}
            <span>{user ? user.name.split(" ")[0] : "Masuk"}</span>
          </button>
          <button
            className="cart-button"
            onClick={() => navigate("cart")}
            aria-label={`Keranjang, ${cartCount} barang`}
          >
            <Icon name="cart" />
            <span>Keranjang</span>
            {cartCount > 0 && <b aria-hidden="true">{cartCount}</b>}
          </button>
        </nav>
      </header>
      <nav className="nav" aria-label="Navigasi utama" ref={navRef}>
        <button
          className={page === "home" ? "active" : ""}
          aria-current={page === "home" ? "page" : undefined}
          onClick={() => go("home")}
        >
          Beranda
        </button>
        <button
          className={isCatalogAll ? "active" : ""}
          aria-current={isCatalogAll ? "page" : undefined}
          onClick={() => go("catalog")}
        >
          Katalog
        </button>
        {menus.map((menu) =>
          menu.items.length ? (
            <div className="nav-menu" key={menu.id}>
              <button
                className={`nav-menu__trigger${menu.active ? " active" : ""}`}
                aria-expanded={open === menu.id}
                aria-controls={`nav-panel-${menu.id}`}
                onClick={(e) => toggle(menu.id, e.currentTarget)}
              >
                {menu.label}
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
              </button>
              {open === menu.id && (
                <div className="nav-panel" id={`nav-panel-${menu.id}`} style={panelPos}>
                  {menu.items.map((item) => (
                    <button
                      key={item.label}
                      className={item.current ? "current" : ""}
                      aria-current={item.current ? "page" : undefined}
                      onClick={() => go(item.page, item.data, item.resetQuery)}
                    >
                      {item.label}
                    </button>
                  ))}
                </div>
              )}
            </div>
          ) : null,
        )}
        <button
          className={isBestSelling ? "active" : ""}
          aria-current={isBestSelling ? "page" : undefined}
          onClick={() => go("catalog", {sort: "best_selling"}, true)}
        >
          Terlaris
        </button>
      </nav>
    </>
  );
}

export default Header;


