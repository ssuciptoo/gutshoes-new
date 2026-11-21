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
  }

  return {plugins: [react()]};
});
