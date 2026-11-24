#!/usr/bin/env bash
# Operasi harian staging GutShoes. Jalankan di VPS dari mana saja: /opt/gutshoes/deploy.sh <perintah>
#
#   deploy [tag]   tarik image, migrasi + data wilayah, lalu jalankan ulang semua layanan
#                  (tanpa tag: IMAGE_TAG di .env; isi hash commit untuk rollback)
#   admin          buat/ubah akun admin (password diminta lewat prompt)
#   backup         dump database ke backups/ (dipanggil cron harian; simpan 14 hari)
#   status         status container, health API, dan pemakaian memori
#   check          cek konfigurasi Midtrans sandbox tanpa membuat transaksi
#   logs [layanan] ikuti log (mis. logs api, logs horizon)
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$APP_DIR"
compose() { docker compose --project-directory "$APP_DIR" -f "$APP_DIR/compose.yaml" "$@"; }
env_value() { grep -E "^$1=" "$APP_DIR/.env" | tail -1 | cut -d= -f2-; }

require_config() {
  local domain; domain="$(env_value STAGING_DOMAIN)"
  if [ -z "$domain" ] || [ "$domain" = "domain-anda.com" ]; then
    echo "Isi STAGING_DOMAIN di $APP_DIR/.env dengan domain sungguhan dulu." >&2; exit 1
  fi
  for key in MAIL_FROM_ADDRESS MIDTRANS_SERVER_KEY CLERK_SECRET_KEY CLERK_FRONTEND_API_URL; do
    grep -qE "^${key}=.+" "$APP_DIR/backend.env" || { echo "Isi $key di $APP_DIR/backend.env." >&2; exit 1; }
  done
}

wait_healthy() {
  local service=$1 id status
  for _ in $(seq 1 40); do
    id="$(compose ps -q "$service")"
    status="$( [ -n "$id" ] && docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null || echo starting)"
    [ "$status" = "healthy" ] && return 0
    sleep 3
  done
  echo "Layanan $service belum sehat. Periksa: $0 logs $service" >&2; return 1
}

cmd="${1:-}"; shift || true
case "$cmd" in
  deploy)
    require_config
    tag="${1:-$(env_value IMAGE_TAG)}"
    export IMAGE_TAG="${tag:-staging}"
    echo "==> Menarik image (tag $IMAGE_TAG)"
    compose pull --quiet
    echo "==> Database & Redis"
    compose up -d mysql redis
    wait_healthy mysql
    echo "==> Migrasi dan data wilayah (idempoten; deploy berhenti bila gagal)"
    compose run --rm --no-deps api sh -c "php artisan migrate --force && php artisan regions:import --no-interaction"
    echo "==> Menjalankan layanan"
    compose up -d --remove-orphans
    wait_healthy api
    docker image prune -f >/dev/null
    echo "Deploy selesai (tag $IMAGE_TAG). Cek: $0 status"
    ;;
  admin)
    compose run --rm --no-deps api php artisan gutshoes:create-admin
    ;;
  backup)
    mkdir -p "$APP_DIR/backups"
    out="$APP_DIR/backups/gutshoes-$(date +%Y%m%d-%H%M%S).sql.gz"
    umask 077
    # Root di dalam container: user aplikasi tidak punya hak SHOW_ROUTINE yang dibutuhkan --routines.
    compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump --single-transaction --routines --triggers --no-tablespaces -uroot "$MYSQL_DATABASE"' | gzip -9 > "$out"
    gzip -t "$out"
    find "$APP_DIR/backups" -name 'gutshoes-*.sql.gz' -mtime +14 -delete
    echo "Backup: $out ($(du -h "$out" | cut -f1))"
    ;;
  status)
    compose ps
    echo
    domain="$(env_value STAGING_DOMAIN)"
    curl -fsS --max-time 10 "https://api-staging.${domain}/api/v1/health/ready" && echo || echo "API belum bisa dijangkau lewat HTTPS."
    echo
    docker stats --no-stream --format 'table {{.Name}}\t{{.MemUsage}}\t{{.CPUPerc}}'
    free -h
    ;;
  check)
    compose run --rm --no-deps api php artisan midtrans:sandbox-check
    ;;
  logs)
    compose logs -f --tail=100 "$@"
    ;;
  *)
    sed -n '2,12p' "$0"; exit 1
    ;;
esac
