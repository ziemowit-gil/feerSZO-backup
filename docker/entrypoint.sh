#!/bin/bash
set -euo pipefail

APP_DIR="/var/www/html"

# ── Uprawnienia plików aplikacji ──────────────────────────────────────────────
# Apache (www-data) musi móc czytać .htaccess i PHP-ki.
# Katalog może zostać sklonowany z chmod 750 lub restrictive umask.
find "${APP_DIR}" -type d -exec chmod o+rx {} + 2>/dev/null || true
find "${APP_DIR}" -type f -not -path "${APP_DIR}/uploads/*" -exec chmod o+r {} + 2>/dev/null || true
echo "[entrypoint] Uprawnienia plików naprawione."

# ── Uprawnienia katalogów ─────────────────────────────────────────────────────
mkdir -p "${APP_DIR}/uploads"
chown -R www-data:www-data "${APP_DIR}/uploads"
chmod 775 "${APP_DIR}/uploads"

# Ochrona uploads przed wykonaniem PHP (idempotentne)
HTACCESS="${APP_DIR}/uploads/.htaccess"
if [ ! -f "${HTACCESS}" ]; then
    cat > "${HTACCESS}" <<'EOF'
Options -Indexes
php_flag engine off
RemoveHandler .php .php3 .phtml .php5
RemoveType application/x-httpd-php
EOF
fi

# ── SQLite — uprawnienia ──────────────────────────────────────────────────────
# www-data musi mieć write na katalog aplikacji — SQLite tworzy w nim
# pliki journal/WAL (-journal, -wal, -shm) nawet przy zwykłym SELECT (WAL mode).
chown www-data:www-data "${APP_DIR}"
chmod 775 "${APP_DIR}"

if [ -f "${APP_DIR}/umowy.db" ]; then
    chown www-data:www-data "${APP_DIR}/umowy.db"
    chmod 664 "${APP_DIR}/umowy.db"
    # WAL, SHM i journal też
    for f in "${APP_DIR}/umowy.db-wal" "${APP_DIR}/umowy.db-shm" "${APP_DIR}/umowy.db-journal"; do
        [ -f "$f" ] && chown www-data:www-data "$f" && chmod 664 "$f" || true
    done
fi

# ── msmtp — dynamiczna konfiguracja ze zmiennych środowiskowych ───────────────
_smtp_host="${SMTP_HOST:-mailpit}"
_smtp_port="${SMTP_PORT:-1025}"
_smtp_from="${SMTP_FROM:-no-reply@feer.local}"
_smtp_user="${SMTP_USER:-}"
_smtp_pass="${SMTP_PASS:-}"
_smtp_tls="${SMTP_TLS:-off}"

case "$_smtp_tls" in
    tls)      _tls=on;  _starttls=off ;;
    starttls) _tls=on;  _starttls=on  ;;
    *)        _tls=off; _starttls=off ;;
esac

{
    echo "defaults"
    echo "logfile        /var/log/msmtp.log"
    echo ""
    echo "account        default"
    echo "host           ${_smtp_host}"
    echo "port           ${_smtp_port}"
    echo "from           ${_smtp_from}"
    echo "auth           $( [ -n "$_smtp_user" ] && echo on || echo off )"
    echo "tls            ${_tls}"
    echo "tls_starttls   ${_starttls}"
    [ -n "$_smtp_user" ] && echo "user           ${_smtp_user}"
    [ -n "$_smtp_pass" ] && echo "password       ${_smtp_pass}"
} > /etc/msmtprc
chmod 600 /etc/msmtprc
echo "[entrypoint] SMTP: ${_smtp_host}:${_smtp_port}"

# ── Logi ──────────────────────────────────────────────────────────────────────
touch /var/log/feer_cron.log
chown www-data:www-data /var/log/feer_cron.log

# ── Opcache — reset przy starcie (produkcja) ──────────────────────────────────
if [ "${APP_ENV:-development}" = "production" ]; then
    find "${APP_DIR}" -name "*.php" -newer "${APP_DIR}/config.php" \
         -exec touch {} \; 2>/dev/null || true
fi

# ── Cron ──────────────────────────────────────────────────────────────────────
service cron start
echo "[entrypoint] Cron uruchomiony."

# ── Przekaż do Apache ─────────────────────────────────────────────────────────
echo "[entrypoint] Środowisko: ${APP_ENV:-development}, DB: ${DB_TYPE:-sqlite}"
exec apache2-foreground
