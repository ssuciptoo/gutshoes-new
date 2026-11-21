FROM node:22-bookworm-slim AS build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

COPY . .
ARG VITE_API_URL
# production (default) or staging; vite.config.js rejects a missing, non-HTTPS or example API URL.
ARG VITE_BUILD_MODE=production
ENV VITE_API_URL=${VITE_API_URL}
RUN npm run build -- --mode "${VITE_BUILD_MODE}"

FROM nginx:1.27-alpine

COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY security-headers.conf /etc/nginx/snippets/security-headers.conf
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
