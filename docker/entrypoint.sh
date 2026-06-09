#!/bin/bash
set -euo pipefail

APP_DIR="/var/www/html"

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
if [ -f "${APP_DIR}/umowy.db" ]; then
    chown www-data:www-data "${APP_DIR}/umowy.db"
    chmod 664 "${APP_DIR}/umowy.db"
    # WAL i SHM też
    for f in "${APP_DIR}/umowy.db-wal" "${APP_DIR}/umowy.db-shm"; do
        [ -f "$f" ] && chown www-data:www-data "$f" && chmod 664 "$f" || true
    done
fi

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
