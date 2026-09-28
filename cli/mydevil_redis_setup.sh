#!/bin/sh
# Konfiguracja serwera Redis na hostingu MyDevil (FreeBSD) wg https://pomoc.mydevil.net/Redis/
# Wariant zalecany przez MyDevil: unixsocket (port 0) + hasło (requirepass).
#
# Użycie:  sh cli/mydevil_redis_setup.sh
# Skrypt pyta tylko o login i domenę (domyślnie: bieżący użytkownik / szo.feer.org.pl).
# Wszystko inne (katalog, redis.conf, hasło, socket, uruchomienie w screen, @reboot w crontab) robi sam.
# Można go uruchamiać wielokrotnie — istniejący redis.conf i hasło nie są nadpisywane.

set -eu

REDIS_CONF_URL="https://raw.githubusercontent.com/redis/redis/8.0.3/redis.conf"

# ---------- 1. pytania ----------
DEF_LOGIN="$(id -un)"
printf 'Login MyDevil [%s]: ' "$DEF_LOGIN"
read -r LOGIN
[ -n "${LOGIN:-}" ] || LOGIN="$DEF_LOGIN"

DEF_DOMAIN="szo.feer.org.pl"
printf 'Domena [%s]: ' "$DEF_DOMAIN"
read -r DOMAIN
[ -n "${DOMAIN:-}" ] || DOMAIN="$DEF_DOMAIN"

HOME_DIR="/usr/home/$LOGIN"
DOMAIN_DIR="$HOME_DIR/domains/$DOMAIN"
WORK_DIR="$HOME_DIR/redis/$DOMAIN"        # tu: redis.conf, dump.rdb, log
SOCK="$DOMAIN_DIR/redis.sock"             # ścieżka wg dokumentacji MyDevil
CONF="$WORK_DIR/redis.conf"
PASS_FILE="$WORK_DIR/password"
LOG_FILE="$WORK_DIR/redis.log"
SCREEN_NAME="redis-$DOMAIN"

echo
echo "Login:   $LOGIN"
echo "Domena:  $DOMAIN"
echo "Katalog: $WORK_DIR"
echo "Socket:  $SOCK"
echo

if [ ! -d "$DOMAIN_DIR" ]; then
  echo "BŁĄD: nie ma katalogu domeny $DOMAIN_DIR (sprawdź login/domenę)." >&2
  exit 1
fi
command -v redis-server >/dev/null 2>&1 || { echo "BŁĄD: brak redis-server w PATH." >&2; exit 1; }
command -v screen       >/dev/null 2>&1 || { echo "BŁĄD: brak screen w PATH." >&2; exit 1; }

mkdir -p "$WORK_DIR"
chmod 700 "$WORK_DIR"

# ---------- 2. hasło ----------
if [ -s "$PASS_FILE" ]; then
  PASS="$(cat "$PASS_FILE")"
  echo "Hasło: użyto istniejącego z $PASS_FILE"
else
  if command -v openssl >/dev/null 2>&1; then
    PASS="$(openssl rand -hex 24)"
  else
    PASS="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
  fi
  umask 077
  printf '%s\n' "$PASS" > "$PASS_FILE"
  echo "Hasło: wygenerowano i zapisano w $PASS_FILE"
fi

# ---------- 3. redis.conf ----------
if [ -s "$CONF" ]; then
  echo "redis.conf: istnieje, pomijam pobieranie ($CONF)"
else
  echo "redis.conf: pobieram $REDIS_CONF_URL"
  if command -v fetch >/dev/null 2>&1; then
    fetch -q -o "$CONF" "$REDIS_CONF_URL"
  else
    curl -fsSL -o "$CONF" "$REDIS_CONF_URL"
  fi

  # ustawienia wg pomoc.mydevil.net/Redis: port 0 + unixsocket + requirepass
  # (bez GNU sed -i; FreeBSD wymaga argumentu po -i, więc piszemy do pliku tymczasowego)
  TMP="$CONF.tmp"
  sed \
    -e "s|^port .*|port 0|" \
    -e "s|^# *unixsocket .*|unixsocket $SOCK|" \
    -e "s|^# *unixsocketperm .*|unixsocketperm 700|" \
    -e "s|^# *requirepass .*|requirepass $PASS|" \
    -e "s|^dir .*|dir $WORK_DIR|" \
    -e "s|^logfile .*|logfile $LOG_FILE|" \
    -e "s|^daemonize .*|daemonize no|" \
    -e "s|^protected-mode .*|protected-mode yes|" \
    "$CONF" > "$TMP"
  # jeśli w szablonie nie było danej linii (np. zakomentowanej) — dopisz jawnie
  for kv in "unixsocket $SOCK" "unixsocketperm 700" "requirepass $PASS" "dir $WORK_DIR" "logfile $LOG_FILE"; do
    key="${kv%% *}"
    grep -q "^$key " "$TMP" || printf '%s\n' "$kv" >> "$TMP"
  done
  mv "$TMP" "$CONF"
  chmod 600 "$CONF"
  echo "redis.conf: skonfigurowano (port 0, unixsocket, requirepass, dir, logfile)"
fi

# ---------- 4. uruchomienie w screen ----------
if [ -S "$SOCK" ] && redis-cli -s "$SOCK" -a "$PASS" --no-auth-warning ping 2>/dev/null | grep -q PONG; then
  echo "Redis: już działa na $SOCK"
else
  [ -e "$SOCK" ] && rm -f "$SOCK"   # martwy socket po poprzedniej sesji
  cd "$WORK_DIR"
  screen -dmS "$SCREEN_NAME" redis-server "$CONF"
  sleep 2
  if redis-cli -s "$SOCK" -a "$PASS" --no-auth-warning ping 2>/dev/null | grep -q PONG; then
    echo "Redis: uruchomiono w screen '$SCREEN_NAME' (screen -r $SCREEN_NAME)"
  else
    echo "BŁĄD: Redis nie odpowiada. Sprawdź: screen -r $SCREEN_NAME  oraz  $LOG_FILE" >&2
    exit 1
  fi
fi

# ---------- 5. autostart po restarcie serwera (@reboot w crontab) ----------
CRON_LINE="@reboot cd $WORK_DIR && /usr/bin/env screen -dmS $SCREEN_NAME redis-server $CONF"
if crontab -l 2>/dev/null | grep -Fq "redis-server $CONF"; then
  echo "crontab: wpis @reboot już istnieje"
else
  { crontab -l 2>/dev/null || true; printf '%s\n' "$CRON_LINE"; } | crontab -
  echo "crontab: dodano wpis @reboot"
fi

# ---------- 6. podsumowanie ----------
cat <<SUMMARY

Gotowe.

  Socket:      $SOCK
  Hasło:       (w pliku) $PASS_FILE
  Konfig:      $CONF
  Log:         $LOG_FILE
  Screen:      screen -r $SCREEN_NAME
  Test:        redis-cli -s $SOCK -a "\$(cat $PASS_FILE)" ping

W SZO: Panel admina → „Redis (cache)” (admin/redis.php):
  • Gniazdo unix:  $SOCK
  • Hasło:         wklej wynik:  cat $PASS_FILE
  • zaznacz „Włączony”, kliknij „Testuj”, potem „Zapisz”.

Persystencja: domyślnie RDB (save 900 1 / 300 10 / 60 10000) do $WORK_DIR/dump.rdb.
Aby włączyć AOF: w $CONF zmień 'appendonly no' na 'appendonly yes' i zrestartuj
(redis-cli -s $SOCK -a HASŁO shutdown; potem ponownie ten skrypt).
SUMMARY
