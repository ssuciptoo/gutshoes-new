import {api} from "../../api";

// Topbar (badge pesanan) dan halaman Ikhtisar sama-sama butuh data dashboard.
// Permintaan yang berdekatan memakai satu request yang sama.
const REUSE_MS = 5000;
let pending = null;
let settledAt = 0;

export function loadDashboard() {
  if (pending && Date.now() - settledAt < REUSE_MS) return pending;
  settledAt = Number.POSITIVE_INFINITY;
  pending = api.dashboard().finally(() => {
    settledAt = Date.now();
  });
  pending.catch(() => {
    pending = null;
  });
  return pending;
}
