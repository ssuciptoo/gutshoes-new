import React, {createContext, useContext, useEffect, useState} from "react";
import {api} from "../api";
import {catalogImageUrl} from "./catalog";

// Template latar foto produk transparan (diatur admin di Pengaturan). Dimuat sekali dari
// /image-backdrops. Selama belum termuat atau gagal, gambar tampil dengan latar bawaan.
const BackdropContext = createContext([]);

export function BackdropProvider({children}) {
  const [backdrops, setBackdrops] = useState([]);
  useEffect(() => {
    let active = true;
    api
      .imageBackdrops()
      .then((response) => active && setBackdrops(Array.isArray(response.data) ? response.data : []))
      .catch(() => {
        /* Tanpa template, latar bawaan CSS tetap dipakai. */
      });
    return () => {
      active = false;
    };
  }, []);
  return <BackdropContext.Provider value={backdrops}>{children}</BackdropContext.Provider>;
}

export const useBackdrops = () => useContext(BackdropContext);

/** Template untuk id tertentu; jatuh ke template default bila id tidak dikenal. */
export function useBackdrop(id) {
  const backdrops = useBackdrops();
  return backdrops.find((item) => item.id === id) || backdrops.find((item) => item.is_default) || null;
}

/**
 * Props untuk bingkai gambar yang memakai template: class, CSS custom properties, dan
 * data-shadow. Dipakai kartu produk, galeri detail, dan pratinjau admin agar hasilnya sama.
 */
export function backdropFrameProps(backdrop, className = "") {
  if (!backdrop) return {className};
  const style = {"--backdrop-color": backdrop.color_from || "transparent"};
  if (backdrop.type === "gradient" && backdrop.color_to) {
    style["--backdrop-image"] = `linear-gradient(${Number(backdrop.angle ?? 180)}deg, ${backdrop.color_from}, ${backdrop.color_to})`;
  } else if (backdrop.type === "image" && (backdrop.image_url || backdrop.image_path)) {
    style["--backdrop-image"] = `url("${backdrop.image_url || catalogImageUrl(backdrop.image_path)}")`;
    style["--backdrop-size"] = backdrop.image_fit === "contain" ? "contain" : "cover";
  }
  return {
    className: `${className} has-backdrop`.trim(),
    style,
    "data-shadow": backdrop.shadow ? "" : undefined,
  };
}
