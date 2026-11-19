#!/usr/bin/env bash
#
# One-shot provisioning of the full Task Manager stack on a single
# Ubuntu 24.04 EC2 instance:
#
#   Nginx :80/:443
#     ├── /                    -> React build (static files)
#     └── /api, /sanctum, /up  -> Laravel via PHP-FPM
#   Auth: Sanctum SPA sessions (HttpOnly cookie + CSRF), same origin
#   Django overdue service -> gunicorn on 127.0.0.1:8001 (private, not exposed)
#   MySQL 8                -> installed on this instance, 127.0.0.1:3306 (private)
#
# Safe to re-run: use it again to deploy new code (secrets are kept,
# demo data is seeded only once).
#
# Usage:  cp deploy.env.example deploy.env && nano deploy.env && sudo bash setup.sh

set -Eeuo pipefail
trap 'echo -e "\n\033[31m✖ Failed at line $LINENO: $BASH_COMMAND\033[0m" >&2' ERR

[[ $EUID -eq 0 ]] || exec sudo -E bash "$0" "$@"

step() { echo -e "\n\033[1;34m==> $*\033[0m"; }
ok()   { echo -e "\033[32m✔ $*\033[0m"; }
warn() { echo -e "\033[33m! $*\033[0m"; }
die()  { echo -e "\033[31m✖ $*\033[0m" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# The three repos must sit side by side; this script lives in
# <src>/task-manager-laravel/deploy/aws-ec2/
SRC_DIR="${SRC_DIR:-$(cd "$SCRIPT_DIR/../../.." && pwd)}"

[[ -f "$SCRIPT_DIR/deploy.env" ]] && source "$SCRIPT_DIR/deploy.env"

DOMAIN="${DOMAIN:-}"
LETSENCRYPT_EMAIL="${LETSENCRYPT_EMAIL:-}"
APP_TIMEZONE="${APP_TIMEZONE:-Asia/Kolkata}"
DB_DATABASE="${DB_DATABASE:-task_manager}"
DB_USERNAME="${DB_USERNAME:-task_manager}"
DB_PASSWORD="${DB_PASSWORD:-}"
SEED_DEMO_DATA="${SEED_DEMO_DATA:-true}"

APP_ROOT=/var/www
LARAVEL_DIR=$APP_ROOT/task-manager-laravel
DJANGO_DIR=$APP_ROOT/task-overdue-service
REACT_DIR=$APP_ROOT/task-manager-react
STATE_DIR=/etc/task-manager
SECRETS_FILE=$STATE_DIR/secrets.env
PHP_VERSION=8.3
PHP_SOCK=/run/php/php${PHP_VERSION}-fpm.sock

for repo in task-manager-laravel task-overdue-service task-manager-react; do
  [[ -d "$SRC_DIR/$repo" ]] || die "Missing $SRC_DIR/$repo - put the three repos side by side (see README)."
done

if ! grep -q 'VERSION_ID="24.04"' /etc/os-release; then
  warn "This script is tested on Ubuntu 24.04 LTS. Continuing anyway..."
fi

# ---------------------------------------------------------------------------
step "Secrets"
# ---------------------------------------------------------------------------
mkdir -p "$STATE_DIR" && chmod 700 "$STATE_DIR"
if [[ -f "$SECRETS_FILE" ]]; then
  source "$SECRETS_FILE"
  ok "Reusing secrets from $SECRETS_FILE"
fi
APP_KEY="${APP_KEY:-base64:$(openssl rand -base64 32)}"
SERVICE_TOKEN="${SERVICE_TOKEN:-$(openssl rand -hex 32)}"
DJANGO_SECRET_KEY="${DJANGO_SECRET_KEY:-$(openssl rand -hex 48)}"
# Empty DB_PASSWORD in deploy.env -> reuse the saved one, or generate a strong one.
DB_PASSWORD="${DB_PASSWORD:-${SAVED_DB_PASSWORD:-$(openssl rand -hex 20)}}"
cat > "$SECRETS_FILE" <<EOF
APP_KEY='$APP_KEY'
SERVICE_TOKEN='$SERVICE_TOKEN'
DJANGO_SECRET_KEY='$DJANGO_SECRET_KEY'
SAVED_DB_PASSWORD='$DB_PASSWORD'
EOF
chmod 600 "$SECRETS_FILE"
ok "Secrets stored in $SECRETS_FILE (root only)"

# Public address
IMDS_TOKEN=$(curl -s -m 2 -X PUT http://169.254.169.254/latest/api/token \
  -H "X-aws-ec2-metadata-token-ttl-seconds: 60" || true)
PUBLIC_IP=$(curl -s -m 2 -H "X-aws-ec2-metadata-token: $IMDS_TOKEN" \
  http://169.254.169.254/latest/meta-data/public-ipv4 || true)
[[ -n "$PUBLIC_IP" ]] || PUBLIC_IP=$(hostname -I | awk '{print $1}')
HOST="${DOMAIN:-$PUBLIC_IP}"
SCHEME=http
SECURE_COOKIE=false
if [[ -n "$DOMAIN" && -n "$LETSENCRYPT_EMAIL" ]]; then SCHEME=https; SECURE_COOKIE=true; fi
APP_URL="$SCHEME://$HOST"
ok "App URL will be $APP_URL"

# ---------------------------------------------------------------------------
step "Swap (protects small instances from running out of memory)"
# ---------------------------------------------------------------------------
if ! swapon --show | grep -q .; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  ok "2 GB swap enabled"
else
  ok "Swap already present"
fi

# ---------------------------------------------------------------------------
step "System packages"
# ---------------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update -y -q
apt-get install -y -q \
  nginx git unzip curl rsync cron ca-certificates gnupg openssl \
  php${PHP_VERSION}-fpm php${PHP_VERSION}-cli php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring \
  php${PHP_VERSION}-xml php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-bcmath \
  php${PHP_VERSION}-intl php${PHP_VERSION}-opcache \
  composer python3 python3-venv python3-pip

if ! command -v node >/dev/null || [[ "$(node -v | cut -d. -f1 | tr -d v)" -lt 20 ]]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
  apt-get install -y -q nodejs
fi
ok "PHP $(php -r 'echo PHP_VERSION;'), Composer $(composer --version 2>/dev/null | awk '{print $3}'), Node $(node -v), Python $(python3 -V | awk '{print $2}')"

# ---------------------------------------------------------------------------
step "MySQL 8 (on this instance)"
# ---------------------------------------------------------------------------
apt-get install -y -q mysql-server
systemctl enable --now mysql
# Ubuntu's MySQL only listens on 127.0.0.1 by default and port 3306 stays closed in
# the security group - connect from MySQL Workbench through an SSH tunnel instead.
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USERNAME}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
ok "MySQL ready: database '${DB_DATABASE}', user '${DB_USERNAME}' (localhost only)"

# Nightly backup, 7 days retention.
mkdir -p /var/backups/task-manager && chmod 700 /var/backups/task-manager
cat > /etc/cron.d/task-manager-backup <<EOF
30 2 * * * root mysqldump --single-transaction ${DB_DATABASE} | gzip > /var/backups/task-manager/db-\$(date +\%F).sql.gz && find /var/backups/task-manager -name '*.sql.gz' -mtime +7 -delete
EOF
ok "Nightly backups -> /var/backups/task-manager"

# ---------------------------------------------------------------------------
step "Copy application code to $APP_ROOT"
# ---------------------------------------------------------------------------
mkdir -p "$APP_ROOT"
RSYNC_EXCLUDES=(--exclude .git --exclude node_modules --exclude vendor --exclude .venv
  --exclude dist --exclude .env --exclude 'storage/' --exclude '__pycache__' --exclude 'deploy.env')
rsync -a --delete "${RSYNC_EXCLUDES[@]}" "$SRC_DIR/task-manager-laravel/" "$LARAVEL_DIR/"
rsync -a --delete "${RSYNC_EXCLUDES[@]}" "$SRC_DIR/task-overdue-service/" "$DJANGO_DIR/"
rsync -a --delete "${RSYNC_EXCLUDES[@]}" "$SRC_DIR/task-manager-react/" "$REACT_DIR/"
ok "Code synced"

# ---------------------------------------------------------------------------
step "Laravel API"
# ---------------------------------------------------------------------------
cd "$LARAVEL_DIR"
mkdir -p storage/app/public storage/framework/{cache/data,sessions,views,testing} storage/logs bootstrap/cache

cat > .env <<EOF
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=${APP_URL}
APP_TIMEZONE=${APP_TIMEZONE}
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}

# HttpOnly session cookie: Secure on HTTPS, sent for requests from this host
SESSION_SECURE_COOKIE=${SECURE_COOKIE}
SANCTUM_STATEFUL_DOMAINS=${HOST}

OVERDUE_SERVICE_TOKEN=${SERVICE_TOKEN}
EOF
chown root:www-data .env && chmod 640 .env

COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --no-progress
chown -R www-data:www-data storage bootstrap/cache

artisan() { sudo -u www-data php "$LARAVEL_DIR/artisan" "$@"; }
artisan config:clear >/dev/null
artisan migrate --force
if [[ "$SEED_DEMO_DATA" == true && ! -f "$STATE_DIR/.seeded" ]]; then
  artisan db:seed --force
  touch "$STATE_DIR/.seeded"
  ok "Demo data + test accounts seeded"
fi
artisan config:cache
artisan route:cache
artisan event:cache
systemctl restart php${PHP_VERSION}-fpm
ok "Laravel ready"

# ---------------------------------------------------------------------------
step "Django overdue service"
# ---------------------------------------------------------------------------
cd "$DJANGO_DIR"
[[ -d .venv ]] || python3 -m venv .venv
.venv/bin/pip install -q --upgrade pip
.venv/bin/pip install -q -r requirements.txt

cat > .env <<EOF
DJANGO_SECRET_KEY=${DJANGO_SECRET_KEY}
SERVICE_TOKEN=${SERVICE_TOKEN}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
APP_TIMEZONE=${APP_TIMEZONE}
EOF
chown root:www-data .env && chmod 640 .env
sudo -u www-data .venv/bin/python manage.py check >/dev/null

cat > /etc/systemd/system/task-overdue.service <<EOF
[Unit]
Description=Task Manager - Django overdue service (gunicorn)
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=${DJANGO_DIR}
ExecStart=${DJANGO_DIR}/.venv/bin/gunicorn config.wsgi:application --bind 127.0.0.1:8001 --workers 2 --timeout 30 --access-logfile -
Restart=always
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable task-overdue >/dev/null 2>&1
systemctl restart task-overdue
for _ in {1..20}; do curl -fsS -m 2 http://127.0.0.1:8001/health/ >/dev/null 2>&1 && break; sleep 1; done
curl -fsS http://127.0.0.1:8001/health/ >/dev/null || die "Django service did not start: journalctl -u task-overdue -n 50"
ok "Django service running on 127.0.0.1:8001 (private)"

# ---------------------------------------------------------------------------
step "React frontend"
# ---------------------------------------------------------------------------
cd "$REACT_DIR"
# React calls /api and /sanctum on its own origin, so the session cookie is first-party.
npm ci --no-audit --no-fund --loglevel=error
npm run build --silent
ok "React built -> $REACT_DIR/dist"

# ---------------------------------------------------------------------------
step "Nginx"
# ---------------------------------------------------------------------------
cat > /etc/nginx/sites-available/task-manager <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name __SERVER_NAME__;

    root __REACT_DIR__/dist;
    index index.html;

    server_tokens off;
    client_max_body_size 10m;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    # Laravel API, Sanctum CSRF cookie endpoint and health check -> PHP-FPM
    location ~ ^/(api|sanctum|up)(/|$) {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME __LARAVEL_DIR__/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT __LARAVEL_DIR__/public;
        fastcgi_pass unix:__PHP_SOCK__;
        fastcgi_read_timeout 60s;
    }

    # Hashed Vite assets can be cached forever
    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # React SPA: unknown paths fall back to index.html (client-side routing)
    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX
sed -i \
  -e "s#__SERVER_NAME__#${DOMAIN:-_}#" \
  -e "s#__REACT_DIR__#${REACT_DIR}#g" \
  -e "s#__LARAVEL_DIR__#${LARAVEL_DIR}#g" \
  -e "s#__PHP_SOCK__#${PHP_SOCK}#g" \
  /etc/nginx/sites-available/task-manager
ln -sf /etc/nginx/sites-available/task-manager /etc/nginx/sites-enabled/task-manager
rm -f /etc/nginx/sites-enabled/default
nginx -t -q
systemctl enable nginx >/dev/null 2>&1
systemctl reload nginx
ok "Nginx configured"

if [[ "$SCHEME" == https ]]; then
  step "HTTPS (Let's Encrypt)"
  apt-get install -y -q certbot python3-certbot-nginx
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LETSENCRYPT_EMAIL" \
    --redirect --keep-until-expiring
  ok "HTTPS enabled (auto-renews via certbot timer)"
fi

# ---------------------------------------------------------------------------
step "Scheduled jobs"
# ---------------------------------------------------------------------------
cat > /etc/cron.d/task-manager <<EOF
# Laravel scheduler: calls the Django overdue sync every 5 minutes
* * * * * www-data cd ${LARAVEL_DIR} && php artisan schedule:run >/dev/null 2>&1
# Safety net: Django marks overdue tasks directly just after midnight
5 0 * * * www-data cd ${DJANGO_DIR} && .venv/bin/python manage.py shell -c "from tasks.services import mark_overdue_tasks; r = mark_overdue_tasks(); print(r.updated, 'task(s) marked as OVERDUE')" 2>&1 | logger -t task-overdue
EOF
chmod 644 /etc/cron.d/task-manager
systemctl enable --now cron >/dev/null 2>&1
ok "Cron jobs installed"

# ---------------------------------------------------------------------------
step "Smoke tests"
# ---------------------------------------------------------------------------
# Talk to Nginx on this machine, but with the public host name, exactly like a browser.
smoke_curl() {
  if [[ "$SCHEME" == https ]]; then
    curl -sS -m 10 --resolve "${DOMAIN}:443:127.0.0.1" "$@"
  else
    curl -sS -m 10 "$@"
  fi
}
BASE_URL="$APP_URL"
[[ "$SCHEME" == https ]] || BASE_URL="http://127.0.0.1"

smoke_curl -f "$BASE_URL/up" >/dev/null && ok "Laravel health check (/up)"
smoke_curl -f "$BASE_URL/" | grep -q '<div id="root">' && ok "React app served (/)"

if [[ -f "$STATE_DIR/.seeded" ]]; then
  JAR=$(mktemp)
  # 1) CSRF cookie  2) login (sets HttpOnly session cookie)  3) authenticated request with the cookie
  smoke_curl -c "$JAR" -b "$JAR" -H "Referer: $APP_URL/" "$BASE_URL/sanctum/csrf-cookie" >/dev/null
  XSRF=$(awk '$6=="XSRF-TOKEN"{print $7}' "$JAR" | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
  LOGIN=$(smoke_curl -c "$JAR" -b "$JAR" -X POST "$BASE_URL/api/v1/auth/login" \
    -H "Referer: $APP_URL/" -H "X-XSRF-TOKEN: $XSRF" \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d '{"email":"admin@taskmanager.test","password":"Admin@123"}' || true)
  ME=$(smoke_curl -b "$JAR" -H "Referer: $APP_URL/" -H 'Accept: application/json' "$BASE_URL/api/v1/auth/me" || true)
  if grep -q '"success":true' <<<"$LOGIN" && grep -q '"role":"admin"' <<<"$ME"; then
    ok "Admin login with HttpOnly session cookie"
    grep -qi 'httponly' "$JAR" && ok "Session cookie is HttpOnly"
  else
    warn "Cookie login check failed. Login: $LOGIN  Me: $ME"
  fi
  rm -f "$JAR"
fi
sudo -u www-data php "$LARAVEL_DIR/artisan" tasks:sync-overdue && ok "Laravel -> Django overdue sync"

cat <<EOF

$(echo -e "\033[1;32m")Deployment complete$(echo -e "\033[0m")

  App:            ${APP_URL}
  API:            ${APP_URL}/api/v1
  Admin login:    admin@taskmanager.test / Admin@123
  User login:     user@taskmanager.test  / User@1234

  Database:       MySQL on this instance - ${DB_DATABASE} / user ${DB_USERNAME}
                  (password: sudo grep SAVED_DB_PASSWORD ${SECRETS_FILE})
  Secrets:        ${SECRETS_FILE}
  Laravel logs:   ${LARAVEL_DIR}/storage/logs/
  Django logs:    journalctl -u task-overdue -f
  Nginx logs:     /var/log/nginx/

  Redeploy:       update the code in ${SRC_DIR} and run: sudo bash $0
EOF
