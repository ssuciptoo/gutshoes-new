#!/usr/bin/env bash
# Menyiapkan VPS Ubuntu 24.04 untuk staging GutShoes. Aman dijalankan ulang (idempoten).
#
# Pemakaian (dari laptop, di root repo):
#   scp -r deploy/staging root@IP_VPS:/opt/gutshoes
#   ssh root@IP_VPS 'bash /opt/gutshoes/bootstrap.sh'
#
# Yang dilakukan: update sistem + update keamanan otomatis, Docker Engine + Compose, swap 2 GB,
# firewall (SSH/80/443 saja), rotasi log Docker, file .env/backend.env dengan password acak,
# dan backup database harian.
set -euo pipefail

APP_DIR=/opt/gutshoes
[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root (atau dengan sudo)." >&2; exit 1; }
[ -f "$APP_DIR/compose.yaml" ] || { echo "Salin dulu deploy/staging ke $APP_DIR." >&2; exit 1; }
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || echo "Peringatan: skrip ini diuji untuk Ubuntu; terdeteksi ${PRETTY_NAME:-tidak diketahui}." >&2

step() { printf '\n==> %s\n' "$1"; }

step "Update sistem dan update keamanan otomatis"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get upgrade -yq
apt-get install -yq ca-certificates curl openssl ufw unattended-upgrades
dpkg-reconfigure -f noninteractive unattended-upgrades

step "Docker Engine + Compose plugin (repo resmi Docker)"
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -q
  apt-get install -yq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
# Batasi ukuran log container agar disk VPS kecil tidak penuh.
if [ ! -f /etc/docker/daemon.json ]; then
  cat > /etc/docker/daemon.json <<'JSON'
{ "log-driver": "json-file", "log-opts": { "max-size": "10m", "max-file": "3" } }
JSON
  systemctl restart docker
fi
systemctl enable --now docker

step "Swap 2 GB (pengaman lonjakan memori)"
if ! swapon --show | grep -q '/swapfile'; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
sysctl -q vm.swappiness=10
echo 'vm.swappiness=10' > /etc/sysctl.d/99-gutshoes.conf

step "Firewall: hanya SSH, HTTP, HTTPS"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp
ufw --force enable

step "File konfigurasi"
umask 077
rand() { openssl rand -hex 24; }
if [ ! -f "$APP_DIR/.env" ]; then
  sed -e "s/^MYSQL_PASSWORD=.*/MYSQL_PASSWORD=$(rand)/" \
      -e "s/^MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=$(rand)/" \
      -e "s/^REDIS_PASSWORD=.*/REDIS_PASSWORD=$(rand)/" \
      "$APP_DIR/.env.example" > "$APP_DIR/.env"
  echo "Dibuat: $APP_DIR/.env (password MySQL/Redis acak)."
fi
if [ ! -f "$APP_DIR/backend.env" ]; then
  sed -e "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" \
      "$APP_DIR/backend.env.example" > "$APP_DIR/backend.env"
  echo "Dibuat: $APP_DIR/backend.env (APP_KEY acak)."
fi
chmod 600 "$APP_DIR/.env" "$APP_DIR/backend.env"
chmod 700 "$APP_DIR/deploy.sh"
mkdir -p "$APP_DIR/backups"

step "Backup database harian (02:30, simpan 14 hari)"
cat > /etc/cron.d/gutshoes-backup <<CRON
30 2 * * * root $APP_DIR/deploy.sh backup >> /var/log/gutshoes-backup.log 2>&1
CRON
chmod 644 /etc/cron.d/gutshoes-backup

cat <<DONE

Selesai. Langkah berikutnya:
  1. Isi $APP_DIR/.env          (STAGING_DOMAIN, ACME_EMAIL)
  2. Isi $APP_DIR/backend.env   (SMTP, Midtrans sandbox, RajaOngkir, Clerk)
  3. Login ke GHCR bila package private:
       docker login ghcr.io -u <username-github>   (password: token dengan izin read:packages)
  4. $APP_DIR/deploy.sh deploy
DONE
