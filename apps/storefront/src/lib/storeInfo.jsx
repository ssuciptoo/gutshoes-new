import React, {createContext, useContext, useEffect, useState} from "react";
import {api} from "../api";

// Informasi toko (kontak, badan usaha, hak cipta, versi) dikelola admin di Pengaturan dan
// dimuat sekali dari /store-info. Nilai kosong = tidak ditampilkan.
// Teks kebijakan di pages/StoreInfo.jsx adalah draf umum: tinjau bersama penasihat hukum
// sebelum toko live, terutama aturan retur, masa refund, dan identitas badan usaha.
const EMPTY = {
  legal_name: null,
  email: null,
  whatsapp: null,
  address: null,
  hours: null,
  return_days: null,
  policy_updated: null,
  copyright: null,
  version: null,
};

const StoreInfoContext = createContext(EMPTY);

export function StoreInfoProvider({children}) {
  const [info, setInfo] = useState(EMPTY);
  useEffect(() => {
    let active = true;
    api
      .storeInfo()
      .then((response) => active && setInfo({...EMPTY, ...response.data}))
      .catch(() => {
        /* Tanpa data toko, footer & halaman info tetap tampil tanpa kontak. */
      });
    return () => {
      active = false;
    };
  }, []);
  return <StoreInfoContext.Provider value={info}>{children}</StoreInfoContext.Provider>;
}

export const useStoreInfo = () => useContext(StoreInfoContext);

const digits = (value) => String(value || "").replace(/[^\d]/g, "");

// "0812-3456-7890" / "+62 812…" → tautan wa.me internasional.
export const whatsappUrl = (info) => {
  const number = digits(info.whatsapp);
  if (!number) return "";
  return `https://wa.me/${number.startsWith("0") ? `62${number.slice(1)}` : number}`;
};

export const returnDays = (info) => Number(info.return_days) || 7;

// "{tahun}" diganti tahun berjalan; kosong = "© <tahun> <nama badan usaha>".
export const copyrightText = (info) => {
  const year = String(new Date().getFullYear());
  if (info.copyright) return info.copyright.replaceAll("{tahun}", year);
  return info.legal_name ? `© ${year} ${info.legal_name}` : "";
};

export const formatPolicyDate = (value) => {
  if (!value) return "";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString("id-ID", {dateStyle: "long"});
};
