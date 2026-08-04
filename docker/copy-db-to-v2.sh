#!/bin/bash
# Kopiuje umowy.db ze starego SZO do nowego (feerSZO-v2)
# Uruchom PRZED startem nowego SZO lub podczas okna serwisowego.
set -euo pipefail

SRC="/opt/feer-szo/umowy.db"
DST="/opt/feerSZO-v2/umowy.db"

if [ ! -f "$SRC" ]; then
    echo "[ERROR] Brak źródłowej bazy: ${SRC}"
    exit 1
fi

# Ostrzeżenie gdy stare SZO pisze do bazy
if docker ps --filter "name=feer-app" --filter "status=running" --format '{{.Names}}' | grep -q 'feer-app'; then
    echo "[WARN] Kontener feer-app działa. Kopiowanie na żywo — możliwa niespójność WAL."
fi

# Checkpoint WAL (bezpieczne nawet przy otwartej bazie)
if command -v sqlite3 &>/dev/null; then
    echo "[*] Checkpoint WAL..."
    sqlite3 "$SRC" "PRAGMA wal_checkpoint(TRUNCATE);" 2>/dev/null || true
fi

echo "[*] Kopiuję: ${SRC} → ${DST}"
cp "$SRC" "$DST"
chown 33:33 "$DST"
chmod 664 "$DST"
echo "[OK] Gotowe. Rozmiar: $(du -sh "$DST" | cut -f1)"

echo ""
echo "Następny krok — uruchom migracje w nowym SZO:"
echo "  docker exec nowe-szo php artisan migrate --force"
