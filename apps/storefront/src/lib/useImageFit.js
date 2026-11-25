import {useCallback, useState} from "react";

// Bingkai foto produk berasio 1:1. Foto cutout tanpa latar rasio 4:3 (900×675, data demo) tampil
// "mengambang" di atas latar abu-abu. Foto dengan rasio lain, misalnya foto marketplace 1:1
// yang sudah punya latar sendiri, ditampilkan penuh di bingkai dengan latar blur dari foto
// itu sendiri, agar tidak terlihat seperti kotak kecil di tengah bingkai abu-abu.
const CUTOUT_RATIO = 4 / 3;
const TOLERANCE = 0.04;

export function imageFitFor(width, height) {
  if (!width || !height) return "cutout";
  return Math.abs(width / height / CUTOUT_RATIO - 1) <= TOLERANCE ? "cutout" : "photo";
}

/** Mengembalikan jenis tampilan gambar ("cutout" atau "photo") dan handler untuk <img>. */
export function useImageFit() {
  const [fit, setFit] = useState("cutout");
  const measure = useCallback((image) => {
    if (image?.complete && image.naturalWidth) setFit(imageFitFor(image.naturalWidth, image.naturalHeight));
  }, []);
  // ref menangani gambar yang sudah ada di cache sebelum onLoad sempat terpasang.
  return {fit, ref: measure, onLoad: (event) => measure(event.currentTarget)};
}
