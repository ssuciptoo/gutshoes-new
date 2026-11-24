FROM node:22-bookworm-slim AS build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

COPY . .
ARG VITE_API_URL
# Nilai publik yang ikut masuk bundle (bukan secret). .env.* tidak ikut ke image (.dockerignore),
# jadi wajib dikirim lewat --build-arg; vite.config.js menolak build bila kosong atau salah format.
ARG VITE_CLERK_PUBLISHABLE_KEY
ARG VITE_SITE_URL
# production (default) or staging; vite.config.js rejects a missing, non-HTTPS or example API URL.
ARG VITE_BUILD_MODE=production
ENV VITE_API_URL=${VITE_API_URL} \
    VITE_CLERK_PUBLISHABLE_KEY=${VITE_CLERK_PUBLISHABLE_KEY} \
    VITE_SITE_URL=${VITE_SITE_URL}
RUN npm run build -- --mode "${VITE_BUILD_MODE}"

FROM nginx:1.27-alpine

ARG VITE_API_URL
COPY nginx.conf /etc/nginx/conf.d/default.conf
# Isi alamat backend untuk proxy /sitemap.xml, mis. https://api.gutshoes.id dari https://api.gutshoes.id/api/v1.
RUN api_origin="${VITE_API_URL%/}" && api_origin="${api_origin%/api/v1}" && api_host="${api_origin#*://}"     && test -n "$api_host"     && sed -i -e "s#__API_ORIGIN__#${api_origin}#g" -e "s#__API_HOST__#${api_host}#g" /etc/nginx/conf.d/default.conf
COPY security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
