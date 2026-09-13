#!/usr/bin/env bash
# bin/set-kursant-new-ui-url-vps.sh — Ustawia KURSANT_NEW_UI_URL/ENABLED w
# config.local.php NA PRODUKCYJNYM VPS (Docker, kontener feer-app), żeby
# przekierowania po logowaniu Microsoft 365 (auth/microsoft.php:
# __kursant_ms365__/__prowadzacy_ms365__) i chooser kursanta/prowadzącego
# trafiały na realny adres nowego panelu (kursantApp), zamiast cicho spadać
# na domyślne http://localhost:4202 (patrz config.php:112).
#
# Odpowiednik bin/set-kursant-new-ui-url.sh, ale dla STAREGO VPS — obecnej
# produkcji (bin/set-kursant-new-ui-url.sh celuje w konto MyDevil, gdzie
# migracja jeszcze nie jest żywa). Używa tych samych danych połączenia co
# bin/pull-db-from-docker.sh (bin/.pull-db-from-docker.conf) — ten sam VPS,
# ten sam katalog repo.
#
# Idempotentny: jeśli KURSANT_NEW_UI_URL już jest w config.local.php na
# serwerze, nic nie zmienia. W przeciwnym razie dopisuje obie definicje zaraz
# po pierwszej linii (<?php) — bezpieczne niezależnie od tego, czy plik ma
# na końcu domykający ?>. Na końcu odświeża OPcache w kontenerze (docker/
# php.prod.ini ma opcache.validate_timestamps=0 — bez tego zmiana w pliku nie
# zadziała od razu, patrz docker/DEPLOY.md sekcja "Aktualizacje").
#
# Użycie:
#   bash bin/set-kursant-new-ui-url-vps.sh
#   bash bin/set-kursant-new-ui-url-vps.sh https://ti.feer.org.pl/newUI   # inny adres
#
# Wymaga bin/.pull-db-from-docker.conf (utworzony przez
# bin/pull-db-from-docker-wizard.sh) z ustawionym VPS_SSH/REMOTE_APP_DIR —
# albo ustaw je ręcznie w środowisku przed uruchomieniem.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${SCRIPT_DIR}/.pull-db-from-docker.conf"
# shellcheck source=/dev/null
[[ -f "$CONFIG_FILE" ]] && source "$CONFIG_FILE"

VPS_SSH="${VPS_SSH:?Brak VPS_SSH — uruchom najpierw bin/pull-db-from-docker-wizard.sh albo ustaw VPS_SSH=user@twoj-vps.feer.org.pl}"
REMOTE_APP_DIR="${REMOTE_APP_DIR:-/opt/feer-szo}"
CONTAINER_NAME="${CONTAINER_NAME:-feer-app}"
NEW_UI_URL="${1:-https://ti.feer.org.pl/newUI}"

echo "▸ Ustawiam KURSANT_NEW_UI_URL na ${VPS_SSH}:${REMOTE_APP_DIR}/config.local.php"
echo "  URL: ${NEW_UI_URL}"

ssh "$VPS_SSH" bash -s -- "$REMOTE_APP_DIR" "$CONTAINER_NAME" "$NEW_UI_URL" <<'REMOTE_SCRIPT'
set -euo pipefail
APP_DIR="$1"
CONTAINER="$2"
NEW_UI_URL="$3"
FILE="$APP_DIR/config.local.php"

if [[ ! -f "$FILE" ]]; then
    printf '<?php\n' > "$FILE"
    echo "  (nie było config.local.php na serwerze — utworzono nowy)"
fi

if grep -q "KURSANT_NEW_UI_URL" "$FILE"; then
    echo "  KURSANT_NEW_UI_URL już jest w $FILE — nic nie zmieniam."
else
    awk -v url="$NEW_UI_URL" '
        NR==1 {
            print
            print ""
            print "// Nowy panel kursanta/prowadzącego (kursantApp) — dodane przez bin/set-kursant-new-ui-url-vps.sh"
            print "define(" "\x27" "KURSANT_NEW_UI_URL" "\x27" ", " "\x27" url "\x27" ");"
            print "define(" "\x27" "KURSANT_NEW_UI_ENABLED" "\x27" ", true);"
            next
        }
        { print }
    ' "$FILE" > "$FILE.tmp"
    mv "$FILE.tmp" "$FILE"
    echo "  Dodano KURSANT_NEW_UI_URL/ENABLED do $FILE"
fi

php -l "$FILE"

echo "  Odświeżam OPcache w kontenerze ${CONTAINER} (opcache.validate_timestamps=0 na produkcji)…"
if docker exec "$CONTAINER" kill -USR2 1 2>/dev/null; then
    echo "  OPcache odświeżone (graceful reload)."
else
    echo "  kill -USR2 się nie powiodło — restartuję kontener zamiast tego."
    docker restart "$CONTAINER" > /dev/null
    echo "  Kontener ${CONTAINER} zrestartowany."
fi
REMOTE_SCRIPT

echo "✔ Gotowe."
