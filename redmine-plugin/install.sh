#!/usr/bin/env bash
#
# install.sh — instalacja wtyczki redmine_szo_sync w Redmine (np. MyDevil).
#
# Wykrywa katalog Redmine, podłącza wtyczkę (symlink lub kopia), uruchamia
# migrację (tworzy pole „Komentarze") i restartuje aplikację.
#
# Użycie:
#   bash install.sh [ścieżka-do-Redmine] [--copy]
#
#   [ścieżka-do-Redmine]  katalog Redmine (z Gemfile i plugins/). Można też podać
#                         zmienną REDMINE=... Gdy pominięte — autowykrywanie.
#   --copy                skopiuj wtyczkę zamiast symlinka (domyślnie: symlink).
#
# Przykłady:
#   bash install.sh
#   bash install.sh ~/domains/feer.usermd.net/public_html
#   REDMINE=~/redmine bash install.sh --copy

set -eu

# ── Ustalenie źródła wtyczki (obok tego skryptu) ─────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SRC="$SCRIPT_DIR/redmine_szo_sync"
PLUGIN_NAME="redmine_szo_sync"

if [ ! -f "$PLUGIN_SRC/init.rb" ]; then
  echo "BŁĄD: nie znaleziono wtyczki w $PLUGIN_SRC" >&2
  exit 1
fi

# ── Parsowanie argumentów ────────────────────────────────────────────────────
MODE="symlink"
REDMINE="${REDMINE:-}"
for arg in "$@"; do
  case "$arg" in
    --copy) MODE="copy" ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) REDMINE="$arg" ;;
  esac
done

# ── Wykrycie katalogu Redmine ────────────────────────────────────────────────
if [ -z "$REDMINE" ]; then
  echo "Szukam katalogu Redmine…"
  REDMINE="$(find "$HOME" -maxdepth 5 -name Gemfile -path '*redmine*' 2>/dev/null \
            | head -1 | xargs -r dirname || true)"
fi
if [ -z "$REDMINE" ] || [ ! -d "$REDMINE/plugins" ]; then
  echo "BŁĄD: nie wykryto katalogu Redmine (brak plugins/)." >&2
  echo "Podaj go ręcznie: bash install.sh /ścieżka/do/redmine" >&2
  exit 1
fi
echo "Redmine: $REDMINE"

DEST="$REDMINE/plugins/$PLUGIN_NAME"

# ── Podłączenie wtyczki ──────────────────────────────────────────────────────
if [ -e "$DEST" ] || [ -L "$DEST" ]; then
  echo "Usuwam poprzednią instalację: $DEST"
  rm -rf "$DEST"
fi
if [ "$MODE" = "copy" ]; then
  echo "Kopiuję wtyczkę…"
  cp -r "$PLUGIN_SRC" "$DEST"
else
  echo "Tworzę symlink…"
  ln -s "$PLUGIN_SRC" "$DEST"
fi

# ── Migracja (tworzy pole „Komentarze") ──────────────────────────────────────
echo "Uruchamiam migrację wtyczki…"
cd "$REDMINE"
if command -v bundle >/dev/null 2>&1; then
  RAKE="bundle exec rake"
else
  RAKE="rake"
fi
MIGRATED=1
if ! $RAKE redmine:plugins:migrate NAME="$PLUGIN_NAME" RAILS_ENV=production; then
  MIGRATED=0
fi

# ── Restart (Passenger) ──────────────────────────────────────────────────────
mkdir -p "$REDMINE/tmp"
touch "$REDMINE/tmp/restart.txt"
echo "Restart zlecony (tmp/restart.txt)."

echo
if [ "$MIGRATED" -eq 1 ]; then
  echo "GOTOWE. Teraz w Redmine: Administracja → Wtyczki → SZO Sync → Konfiguruj"
  echo "  URL webhooka SZO: <SZO>/api/redmine_webhook.php"
  echo "  Sekret (HMAC):    ten sam co w SZO (Admin → Redmine)"
else
  echo "UWAGA: migracja NIE przeszła — wtyczka podłączona, ale pole „Komentarze” nie powstało." >&2
  echo "Najczęstsza przyczyna: błędny config/configuration.yml (Redmine nie startuje)." >&2
  echo "Napraw configuration.yml, potem uruchom ręcznie:" >&2
  echo "  cd \"$REDMINE\" && $RAKE redmine:plugins:migrate NAME=$PLUGIN_NAME RAILS_ENV=production && touch tmp/restart.txt" >&2
  exit 1
fi
