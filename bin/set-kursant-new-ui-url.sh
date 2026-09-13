#!/usr/bin/env bash
# bin/set-kursant-new-ui-url.sh — Ustawia KURSANT_NEW_UI_URL/ENABLED w
# config.local.php NA KONCIE MyDevil (przez SSH), żeby przekierowania po
# logowaniu Microsoft 365 (auth/microsoft.php: __kursant_ms365__/
# __prowadzacy_ms365__) i chooser (karty30/ti/kursant/chooser.php) trafiały
# na realny adres nowego panelu (kursantApp), zamiast cicho spadać na domyślne
# http://localhost:4202 (patrz config.php:112).
#
# Idempotentny: jeśli KURSANT_NEW_UI_URL już jest w config.local.php na
# koncie, nic nie zmienia. W przeciwnym razie dopisuje obie definicje zaraz
# po pierwszej linii (<?php) — bezpieczne niezależnie od tego, czy plik ma
# na końcu domykający ?> (nie polega na dopisywaniu na końcu pliku).
#
# Użycie:
#   bash bin/set-kursant-new-ui-url.sh
#   bash bin/set-kursant-new-ui-url.sh https://ti.feer.org.pl/newUI   # inny adres
#
# Wymaga bin/.deploy-mydevil.conf (utworzony przez deploy-mydevil-wizard.sh)
# z ustawionym MYDEVIL_SSH — albo ustaw MYDEVIL_SSH/REMOTE_REPO_DIR ręcznie
# w środowisku przed uruchomieniem.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${SCRIPT_DIR}/.deploy-mydevil.conf"
# shellcheck source=/dev/null
[[ -f "$CONFIG_FILE" ]] && source "$CONFIG_FILE"

MYDEVIL_SSH="${MYDEVIL_SSH:?Brak MYDEVIL_SSH — uruchom najpierw bin/deploy-mydevil-wizard.sh albo ustaw MYDEVIL_SSH=user@serwerX.mydevil.net}"
REMOTE_REPO_DIR="${REMOTE_REPO_DIR:-feerSZO}"
NEW_UI_URL="${1:-https://ti.feer.org.pl/newUI}"

echo "▸ Ustawiam KURSANT_NEW_UI_URL na ${MYDEVIL_SSH}:~/${REMOTE_REPO_DIR}/config.local.php"
echo "  URL: ${NEW_UI_URL}"

ssh "$MYDEVIL_SSH" bash -s -- "$REMOTE_REPO_DIR" "$NEW_UI_URL" <<'REMOTE_SCRIPT'
set -euo pipefail
REPO_DIR="$1"
NEW_UI_URL="$2"
FILE="$HOME/$REPO_DIR/config.local.php"

if [[ ! -f "$FILE" ]]; then
    printf '<?php\n' > "$FILE"
    echo "  (nie było config.local.php na koncie — utworzono nowy)"
fi

if grep -q "KURSANT_NEW_UI_URL" "$FILE"; then
    echo "  KURSANT_NEW_UI_URL już jest w $FILE — nic nie zmieniam."
else
    awk -v url="$NEW_UI_URL" '
        NR==1 {
            print
            print ""
            print "// Nowy panel kursanta/prowadzącego (kursantApp) — dodane przez bin/set-kursant-new-ui-url.sh"
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
REMOTE_SCRIPT

echo "✔ Gotowe."
