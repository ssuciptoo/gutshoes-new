import React, {useEffect, useRef, useState} from "react";
import {rupiah} from "../lib/currency";
import Button from "../components/ui/Button";
import Feedback from "../components/ui/Feedback";
import Icon from "../components/ui/Icon";
import ProductCard from "../components/catalog/ProductCard";
import {formatFootLength} from "../lib/catalog";
import {useImageFit} from "../lib/useImageFit";

import {useCatalog, useProduct} from "../lib/useCatalog";
import {applySeo, productJsonLd} from "../lib/seo";

// Slide galeri: foto berlatar (bukan cutout 4:3) tampil penuh dengan latar blur dari foto itu sendiri.
function GallerySlide({image, active}) {
  const fit = useImageFit();
  const photo = fit.fit === "photo";
  return (
    <div className={`gallery-slide${photo ? " is-photo" : ""}`} style={photo ? {"--photo": `url("${image.src}")`} : undefined} aria-hidden={!active}>
      <img ref={fit.ref} onLoad={fit.onLoad} src={image.src} alt={active ? image.alt : ""} draggable="false" />
    </div>
  );
}

function ProductDetail({slug, products, addToCart, navigate}) {
  const {product, loading, error: requestError, retry} = useProduct(slug);
  const error = requestError?.status === 404 ? "" : requestError?.message;
  const [size, setSize] = useState(null);
  const [notice, setNotice] = useState("");
  const [activeImage, setActiveImage] = useState(0);
  const [adding, setAdding] = useState(false);
  const [added, setAdded] = useState(false);
  // Produk serupa dari aktivitas yang sama; bila kurang, dilengkapi produk terbaru.
  const categorySlug = product?.categories?.[0]?.slug || "";
  const {products: sameCategory} = useCatalog({per_page: 5, sort: "best_selling", ...(categorySlug ? {category: categorySlug} : {})});
  const pointerStart = useRef(null);
  // 404 dari API = produk tidak ada/belum dipublikasikan (bukan gangguan sementara).
  const notFound = !loading && !product && (!requestError || requestError.status === 404);
  useEffect(() => {
    if (product) {
      const lowest = Math.min(...(product.variants?.map((variant) => variant.price).filter((price) => price > 0) || []), product.price || Infinity);
      const fallback = `${product.name} dari ${product.brand}${Number.isFinite(lowest) ? `, mulai ${rupiah(lowest)}` : ""}. Pilih ukuran, cek stok, dan pesan di GutShoes.`;
      applySeo({
        title: `${product.name} — GutShoes`,
        description: product.description || fallback,
        path: `/product/${encodeURIComponent(product.slug)}`,
        image: product.image || undefined,
        imageAlt: product.imageAlt,
        type: "product",
        jsonLd: productJsonLd(product),
      });
    } else if (notFound) {
      applySeo({title: "Produk tidak ditemukan — GutShoes", noindex: true});
    } else {
      document.title = "Detail produk — GutShoes";
    }
  }, [product, notFound]);
  useEffect(() => {
    setActiveImage(0);
    setSize(null);
    setNotice("");
    setAdded(false);
  }, [product?.id]);
  if (loading) return <main className="detail-page"><div className="catalog-status" role="status"><span className="catalog-spinner" /><p>Memuat detail produk…</p></div></main>;
  if (error) return <main className="detail-page"><div className="empty" role="alert"><h1>Produk belum dapat dimuat</h1><p>{error}</p><Button onClick={retry}>Coba muat lagi</Button></div></main>;
  if (!product) return <main className="detail-page"><div className="empty"><h1>Produk tidak ditemukan</h1><p>Produk ini tidak tersedia atau belum dipublikasikan.</p><Button onClick={() => navigate("catalog")}>Kembali ke katalog</Button></div></main>;

  const selectedVariant = product.variants?.find((variant) => variant.size === size);
  const displayPrice = selectedVariant?.price || product.price;
  const images = product.images?.length ? product.images : product.image ? [{id: "primary", src: product.image, alt: product.imageAlt || product.name}] : [];
  const showNavigation = images.length > 1;
  const showImage = (index) => setActiveImage((index + images.length) % images.length);
  const handleGalleryKey = (event) => {
    if (!showNavigation) return;
    if (event.key === "ArrowLeft") { event.preventDefault(); showImage(activeImage - 1); }
    if (event.key === "ArrowRight") { event.preventDefault(); showImage(activeImage + 1); }
  };
  const add = async () => {
    if (!size) { setNotice("Pilih ukuran yang tersedia sebelum menambahkan produk."); return; }
    if (adding) return;
    setAdding(true);
    setNotice("");
    setAdded(false);
    try {
      await addToCart({...product, price: displayPrice}, size);
      setAdded(true);
    } catch (err) {
      setNotice(err?.message || "Produk belum dapat ditambahkan. Coba lagi.");
    } finally {
      setAdding(false);
    }
  };
  const related = [...sameCategory, ...products]
    .filter((item, index, list) => item.id !== product.id && list.findIndex((other) => other.id === item.id) === index)
    .slice(0, 4);
  // Judul mengikuti isi: hanya "serupa" bila memang ada produk lain dari aktivitas yang sama.
  const hasSimilar = sameCategory.some((item) => item.id !== product.id);
  return (
    <main className="detail-page">
      <button className="back-link" onClick={() => navigate("catalog")}><Icon name="back" /> Kembali ke katalog</button>
      <div className="detail-layout">
        <div className="product-gallery" tabIndex={showNavigation ? 0 : undefined} role={showNavigation ? "region" : undefined} aria-roledescription={showNavigation ? "carousel" : undefined} aria-label={showNavigation ? `Galeri foto ${product.name}` : undefined} onKeyDown={handleGalleryKey} onPointerDown={(event) => { pointerStart.current = event.clientX; }} onPointerUp={(event) => { if (!showNavigation || pointerStart.current === null) return; const distance = event.clientX - pointerStart.current; pointerStart.current = null; if (Math.abs(distance) > 45) showImage(activeImage + (distance < 0 ? 1 : -1)); }} onPointerCancel={() => { pointerStart.current = null; }}>
          {images.length ? <div className="gallery-track" style={{transform: `translateX(-${activeImage * 100}%)`}}>{images.map((image, index) => <GallerySlide key={image.id || image.src} image={image} active={index === activeImage} />)}</div> : <span className="product-image-placeholder">Gambar belum tersedia</span>}
          {showNavigation && <><button className="gallery-arrow previous" type="button" onClick={() => showImage(activeImage - 1)} aria-label="Foto sebelumnya"><Icon name="back" /></button><button className="gallery-arrow next" type="button" onClick={() => showImage(activeImage + 1)} aria-label="Foto berikutnya"><Icon name="back" /></button><div className="gallery-dots" aria-label="Pilih foto produk">{images.map((image, index) => <button key={image.id || image.src} type="button" className={index === activeImage ? "active" : ""} onClick={() => showImage(index)} aria-label={`Tampilkan foto ${index + 1} dari ${images.length}`} aria-current={index === activeImage ? "true" : undefined} />)}</div></>}
        </div>
        <section className="purchase-panel">
          <p className="product-brand">{product.brand} · {product.category}</p>
          <h1>{product.name}</h1>
          <p className="price">{rupiah(displayPrice)}</p>
          {selectedVariant && <p className="variant-meta">SKU {selectedVariant.sku} · {selectedVariant.weight} gram</p>}
          {product.description && <p className="description">{product.description}</p>}
          <div className="size-heading"><strong id="size-picker-label">Pilih ukuran</strong><button type="button" onClick={() => navigate("size-guide")}>Panduan ukuran</button></div>
          <div className="size-picker" role="group" aria-labelledby="size-picker-label">{product.sizes.map((itemSize) => <button key={itemSize} aria-pressed={size === itemSize} className={size === itemSize ? "active" : ""} disabled={!product.stock[itemSize]} onClick={() => { setSize(itemSize); setNotice(""); setAdded(false); }}>{itemSize}{product.footLength?.[itemSize] ? <em className="size-cm">{formatFootLength(product.footLength[itemSize])}</em> : null}<small>{!product.stock[itemSize] ? "Habis" : `${product.stock[itemSize]} stok`}</small></button>)}</div>
          <Feedback>{notice}</Feedback>
          {added ? (
            <div className="added-to-cart" role="status">
              <p><Icon name="check" /> {product.name} ukuran {size} masuk ke keranjang.</p>
              <div>
                <Button onClick={() => navigate("checkout")}>Checkout sekarang</Button>
                <Button variant="secondary" onClick={() => navigate("cart")}>Lihat keranjang</Button>
              </div>
            </div>
          ) : (
            <Button className="add-button" onClick={add} disabled={adding} aria-busy={adding}>
              {adding ? "Menambahkan…" : <>Tambah ke keranjang <Icon name="cart" /></>}
            </Button>
          )}
          <div className="product-facts"><div><Icon name="truck" /><span><strong>Ongkir dihitung otomatis</strong>Pilih kurir dan lihat biayanya sebelum membayar</span></div><div><Icon name="shield" /><span><strong>Pembayaran aman lewat Midtrans</strong>Pilih metode bayar yang tersedia saat checkout</span></div></div>
        </section>
      </div>
      <section className="section related"><div className="section__heading"><div><h2>{hasSimilar ? "Produk serupa" : "Produk lainnya"}</h2><p>{hasSimilar ? `Pilihan lain untuk aktivitas ${product.category.toLowerCase()}.` : "Pilihan lain yang mungkin kamu suka."}</p></div></div><div className="product-grid">{related.map((item) => <ProductCard key={item.id} product={item} onOpen={(selected) => navigate("product", {product: selected})} />)}</div></section>
    </main>
  );
}

export default ProductDetail;
