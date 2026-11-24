import {defineConfig, loadEnv} from "vite";
import react from "@vitejs/plugin-react";

// Deployed bundles must never fall back to the localhost API default in src/api.js.
const deployedModes = new Set(["staging", "production"]);

export default defineConfig(({command, mode}) => {
  const env = loadEnv(mode, process.cwd(), "VITE_");

  if (command === "build" && deployedModes.has(mode)) {
    const apiUrl = env.VITE_API_URL;
    let parsedApiUrl;
    try {
      parsedApiUrl = new URL(apiUrl);
    } catch {
      throw new Error(
        `Set VITE_API_URL in .env.${mode} to the HTTPS ${mode} API URL ending in /api/v1.`,
      );
    }

    if (
      parsedApiUrl.protocol !== "https:" ||
      parsedApiUrl.pathname.replace(/\/$/, "") !== "/api/v1" ||
      parsedApiUrl.hostname === "example.com" ||
      parsedApiUrl.hostname.endsWith(".example.com")
    ) {
      throw new Error(
        `VITE_API_URL must be the real HTTPS ${mode} API URL ending in /api/v1; replace the example host.`,
      );
    }

    // Login pelanggan memakai Clerk: production wajib kunci live, staging boleh kunci test.
    const clerkKey = env.VITE_CLERK_PUBLISHABLE_KEY || "";
    const expectedPrefix = mode === "production" ? "pk_live_" : "pk_";
    if (!clerkKey.startsWith(expectedPrefix)) {
      throw new Error(
        `Set VITE_CLERK_PUBLISHABLE_KEY in .env.${mode} to the Clerk ${mode === "production" ? "live (pk_live_…)" : ""} publishable key.`,
      );
    }

    // Alamat publik toko untuk tag Open Graph dan canonical di index.html.
    if (!/^https:\/\/[^/]+$/.test((env.VITE_SITE_URL || "").replace(/\/$/, "")) || /example\.com/.test(env.VITE_SITE_URL)) {
      throw new Error(`Set VITE_SITE_URL in .env.${mode} to the public HTTPS store URL, e.g. https://gutshoes.id.`);
    }
  }

  const siteUrl = (env.VITE_SITE_URL || "http://localhost:5173").replace(/\/$/, "");
  // robots.txt dibuat dari VITE_SITE_URL agar baris Sitemap menunjuk domain yang benar.
  // Halaman transaksi & akun tidak perlu dirayapi; halaman tersebut juga diberi noindex.
  const robots = [
    "User-agent: *",
    "Allow: /",
    ...["/cart", "/checkout", "/payment", "/guest-orders", "/login", "/profile", "/addresses", "/orders", "/admin"].map((path) => `Disallow: ${path}`),
    "",
    `Sitemap: ${siteUrl}/sitemap.xml`,
    "",
  ].join("\n");
  const siteUrlPlugin = {
    name: "gutshoes-site-url",
    transformIndexHtml: (html) => html.replaceAll("__SITE_URL__", siteUrl),
    configureServer(server) {
      server.middlewares.use("/robots.txt", (_request, response) => {
        response.setHeader("Content-Type", "text/plain; charset=utf-8");
        response.end(robots);
      });
    },
    generateBundle() {
      this.emitFile({type: "asset", fileName: "robots.txt", source: robots});
    },
  };
  // Dev: sitemap dibuat backend (GET /api/v1/sitemap.xml); production diteruskan oleh nginx.
  const apiOrigin = (env.VITE_API_URL || "http://localhost:8000/api/v1").replace(/\/api\/v1\/?$/, "");

  return {
    plugins: [react(), siteUrlPlugin],
    server: {proxy: {"/sitemap.xml": {target: apiOrigin, changeOrigin: true, rewrite: () => "/api/v1/sitemap.xml"}}},
  };
});
