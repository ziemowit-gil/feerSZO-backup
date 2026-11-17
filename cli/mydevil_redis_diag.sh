#!/usr/bin/env bash
#
# cli/mydevil_redis_diag.sh — diagnostyka Redisa na hostingu współdzielonym MyDevil
# (FreeBSD, konto bez roota). Działa też na Linuksie i macOS.
#
# !!! UWAGA: SKRYPT WYŚWIETLA HASŁA JAWNYM TEKSTEM !!!
# (requirepass z konfiguracji, REDIS_PASSWORD z plików .env, hasło z bazy SZO
# i z pliku hasła). Nie uruchamiaj go przy udostępnionym ekranie, a wyniku
# nie wklejaj do zgłoszeń ani czatów bez zamazania haseł.
#
# Tylko odczyt: niczego nie zmienia, nie restartuje Redisa i nie usuwa kluczy.
# Do Redisa wysyła wyłącznie PING, INFO, CONFIG GET, DBSIZE, SCAN, ACL WHOAMI/LIST.
# Hasło trafia do redis-cli przez zmienną REDISCLI_AUTH (nie przez -a), więc
# nie widać go w liście procesów. Każde polecenie ma timeout 3 s.
#
# Użycie:
#   bash mydevil_redis_diag.sh [ŚCIEŻKA_GNIAZDA] [--no-color]
#   domyślne gniazdo: /usr/home/feer/domains/szo.feer.org.pl/redis.sock
#
# Sekcje: 1 proces · 2 plik konfiguracyjny i parametry · 3 port TCP i gniazdo
#         4 hasła · 5 zgodność haseł · 6 PING (gniazdo/TCP × hasła) · 7 INFO
#         8 ACL · podsumowanie
#
# Opcjonalnie: SZO_APP_DIR=/ścieżka — katalog SZO, z którego bazy czytane jest
# settings.redis_* (domyślnie ~/domains/szo.feer.org.pl/public_html albo ~/feerSZO).
#
# Kod wyjścia: 0 — któraś kombinacja zwróciła PONG, 1 — żadna, 2 — błędne wywołanie.

DEFAULT_SOCK=/usr/home/feer/domains/szo.feer.org.pl/redis.sock
PREFIX='szo:main:'
DBNUM=0
TMO=3

COLOR=1
SOCK=
for a in "$@"; do
    case "$a" in
        --no-color) COLOR=0 ;;
        -h|--help)  sed -n '2,/^$/p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*)         printf 'Nieznana opcja: %s (zobacz --help)\n' "$a" >&2; exit 2 ;;
        *)          if [ -z "$SOCK" ]; then SOCK=$a; else printf 'Za dużo argumentów: %s\n' "$a" >&2; exit 2; fi ;;
    esac
done
SOCK=${SOCK:-$DEFAULT_SOCK}
[ -t 1 ] || COLOR=0
unset REDISCLI_AUTH

if [ "$COLOR" = 1 ]; then
    C_OK=$'\033[32m'; C_NO=$'\033[31m'; C_I=$'\033[36m'; C_H=$'\033[1m'; C_0=$'\033[0m'
else
    C_OK=; C_NO=; C_I=; C_H=; C_0=
fi

PROBLEMS=()
ok()   { printf '  %s[OK]%s   %s\n' "$C_OK" "$C_0" "$*"; }
brak() { printf '  %s[BRAK]%s %s\n' "$C_NO" "$C_0" "$*"; PROBLEMS+=("$*"); }
inf()  { printf '  %s[i]%s    %s\n' "$C_I" "$C_0" "$*"; }
kv()   { printf '         %s: %s\n' "$1" "$2"; }
sect() { printf '\n%s== %s ==%s\n' "$C_H" "$*" "$C_0"; }
have() { command -v "$1" >/dev/null 2>&1; }

# Polecenie z timeoutem: timeout (FreeBSD, Linux) → gtimeout → strażnik w bashu.
if have timeout; then TO_CMD=timeout; elif have gtimeout; then TO_CMD=gtimeout; else TO_CMD=; fi
T() {
    if [ -n "$TO_CMD" ]; then "$TO_CMD" "$TMO" "$@"; return $?; fi
    "$@" &
    local p=$! w rc
    ( sleep "$TMO"; kill "$p" 2>/dev/null ) >/dev/null 2>&1 &   # bez stdout: $(…) nie czeka na sleep
    w=$!
    wait "$p"; rc=$?
    kill "$w" 2>/dev/null; wait "$w" 2>/dev/null
    return $rc
}
is_timeout() { [ "$1" -eq 124 ] || [ "$1" -eq 143 ]; }

# Ścieżka z rozwiązanymi symlinkami katalogu (/home → /usr/home, public_html → feerSZO).
rp() { local d; d=$(cd -P "$(dirname "$1")" 2>/dev/null && pwd -P) || { printf '%s\n' "$1"; return; }; printf '%s/%s\n' "$d" "$(basename "$1")"; }

unquote() {
    local v=$1
    case "$v" in
        \"*\") v=${v#\"}; v=${v%\"} ;;
        \'*\') v=${v#\'}; v=${v%\'} ;;
    esac
    printf '%s' "$v"
}

# ── Odczyt konfiguracji ──────────────────────────────────────────────────────
CONF= CONF_SRC= CONF_TEXT= ARGS_ALL=

# Ostatnie wystąpienie dyrektywy w redis.conf (+ include). Kod 1 = brak dyrektywy.
confval() {
    [ -n "$CONF_TEXT" ] || return 1
    printf '%s\n' "$CONF_TEXT" | awk -v k="$1" '
        { sub(/\r$/, "") }
        /^[[:space:]]*#/ { next }
        tolower($1) == k { l = $0; sub(/^[[:space:]]*[^[:space:]]+[[:space:]]*/, "", l); sub(/[[:space:]]+$/, "", l); v = l; f = 1 }
        END { if (f) print v; exit (f ? 0 : 1) }' | { IFS= read -r x; s=$?; unquote "$x"; [ $s -eq 0 ] || [ -n "$x" ]; }
}
confall() {
    [ -n "$CONF_TEXT" ] || return 0
    printf '%s\n' "$CONF_TEXT" | awk -v k="$1" '{ sub(/\r$/, "") } /^[[:space:]]*#/ { next } tolower($1) == k { print }'
}
# Parametr z linii poleceń (--port 6380 albo --port=6380). Kod 1 = brak.
argval() {
    [ -n "$ARGS_ALL" ] || return 1
    local v
    v=$(printf '%s\n' "$ARGS_ALL" | awk -v k="--$1" '
        { for (i = 1; i <= NF; i++) {
            if ($i == k && i < NF) { print $(i+1); f = 1; exit }
            if (index($i, k "=") == 1) { print substr($i, length(k) + 2); f = 1; exit } } }
        END { exit (f ? 0 : 1) }') || return 1
    unquote "$v"
}
# Wartość obowiązująca: linia poleceń > redis.conf. Wynik w EV, źródło w ES.
eff() {
    EV=; ES=
    if EV=$(argval "$1"); then ES="linia poleceń"; return 0; fi
    if EV=$(confval "$1"); then ES="redis.conf"; return 0; fi
    EV=; return 1
}

# ── Wspólne dla redis-cli ────────────────────────────────────────────────────
RCLI=$(command -v redis-cli 2>/dev/null)
TCP_HOST=127.0.0.1
PORT=
# rcli HASŁO sock|tcp POLECENIE... — hasło tylko w REDISCLI_AUTH, w podpowłoce.
rcli() {
    local pw=$1 mode=$2
    shift 2
    (
        if [ -n "$pw" ]; then export REDISCLI_AUTH="$pw"; else unset REDISCLI_AUTH; fi
        if [ "$mode" = tcp ]; then T "$RCLI" -h "$TCP_HOST" -p "$PORT" -n "$DBNUM" "$@"
        else T "$RCLI" -s "$SOCK" -n "$DBNUM" "$@"; fi
    ) 2>&1
}

# ── Źródła haseł spoza redis.conf (zbierane przed sekcjami, wypisywane w 4) ──
PW_L=(); PW_V=()   # etykieta / hasło
ENV_REPORT=()      # wiersze do wypisania w sekcji 4: "ok|tekst" albo "i|tekst"

ENV_FILES=$(T find -L "$HOME/domains" -maxdepth 3 -name .env -type f 2>/dev/null | sort)
SEEN='|'
while IFS= read -r f; do
    [ -n "$f" ] || continue
    r=$(rp "$f")
    case "$SEEN" in *"|$r|"*) continue ;; esac
    SEEN="$SEEN$r|"
    line=$(grep -E '^[[:space:]]*(export[[:space:]]+)?REDIS_PASSWORD[[:space:]]*=' "$f" 2>/dev/null | tail -n 1 | tr -d '\r')
    if [ -z "$line" ]; then ENV_REPORT+=("i|.env $r: brak REDIS_PASSWORD"); continue; fi
    v=$(printf '%s' "$line" | sed -E 's/^[^=]*=[[:space:]]*//')
    case "$v" in
        \"*) v=${v#\"}; v=${v%%\"*} ;;
        \'*) v=${v#\'}; v=${v%%\'*} ;;
        *)   v=$(printf '%s' "$v" | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//') ;;
    esac
    if [ -z "$v" ] || [ "$v" = null ]; then ENV_REPORT+=("i|.env $r: REDIS_PASSWORD puste"); continue; fi
    ENV_REPORT+=("ok|.env $r: REDIS_PASSWORD=\"$v\" (${#v} znaków)")
    PW_L+=(".env $r"); PW_V+=("$v")
done <<EOF
$ENV_FILES
EOF

# Baza SZO: settings.redis_* (tylko odczyt; SQLite otwierane w trybie read-only).
APP=
for d in "${SZO_APP_DIR:-}" "$HOME/domains/szo.feer.org.pl/public_html" "$HOME/feerSZO"; do
    if [ -n "$d" ] && [ -f "$d/config.php" ] && [ -f "$d/includes/db.php" ]; then APP=$(cd -P "$d" && pwd -P); break; fi
done
read -r -d '' PHP_READ <<'PHPEOF'
error_reporting(0);
chdir($argv[1]);
define('APP_CLI', true);
try {
    require $argv[1] . '/config.php';
    if (defined('DB_TYPE') && DB_TYPE === 'mysql') {
        $db = new PDO('mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                      DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
        echo "src\tMySQL ", DB_NAME, '@', DB_HOST, "\n";
    } else {
        $at = defined('Pdo\Sqlite::ATTR_OPEN_FLAGS') ? constant('Pdo\Sqlite::ATTR_OPEN_FLAGS') : PDO::SQLITE_ATTR_OPEN_FLAGS;
        $ro = defined('Pdo\Sqlite::OPEN_READONLY') ? constant('Pdo\Sqlite::OPEN_READONLY') : PDO::SQLITE_OPEN_READONLY;
        $db = new PDO('sqlite:' . DB_PATH, null, null, [$at => $ro, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "src\tSQLite ", DB_PATH, "\n";
    }
    $q = $db->query("SELECT key_, value FROM settings WHERE key_ IN ('redis_enabled','redis_socket','redis_host','redis_port','redis_password','redis_db','redis_prefix')");
    foreach ($q as $r) echo $r[0], "\t", str_replace(["\r", "\n"], ' ', (string)$r[1]), "\n";
} catch (Throwable $e) { echo "err\t", $e->getMessage(), "\n"; }
PHPEOF
DB_OUT=; DB_SRC=; DB_ERR=
if [ -n "$APP" ] && have php; then
    DB_OUT=$(T php -r "$PHP_READ" "$APP" 2>/dev/null)
    DB_SRC=$(printf '%s\n' "$DB_OUT" | awk -F'\t' '$1=="src" { print $2 }')
    DB_ERR=$(printf '%s\n' "$DB_OUT" | awk -F'\t' '$1=="err" { print $2 }')
fi
dbset() { printf '%s\n' "$DB_OUT" | awk -F'\t' -v k="$1" '$1==k { sub(/^[^\t]*\t/, ""); print; f=1 } END { exit (f ? 0 : 1) }'; }
DB_PW=$(dbset redis_password)
[ -n "$DB_PW" ] && { PW_L+=("baza SZO (settings.redis_password)"); PW_V+=("$DB_PW"); }

# Plik hasła z cli/mydevil_redis_setup.sh (~/redis/DOMENA/password).
PWFILES=
for f in "$HOME"/redis/*/password "$HOME"/redis/password; do
    [ -f "$f" ] || continue
    v=$(head -n 1 "$f" 2>/dev/null | tr -d '\r')
    PWFILES="$PWFILES$f
"
    [ -n "$v" ] && { PW_L+=("plik $f"); PW_V+=("$v"); }
done

# ═════════════════════════════════════════════════════════════════════════════
printf '%sDiagnostyka Redisa%s — %s, użytkownik %s, %s\n' "$C_H" "$C_0" "$(hostname 2>/dev/null)" "$(id -un)" "$(date '+%Y-%m-%d %H:%M:%S')"
printf '%sUWAGA: wynik zawiera hasła jawnym tekstem.%s\n' "$C_NO" "$C_0"

# ── 1. Proces ────────────────────────────────────────────────────────────────
sect "1. Proces redis-server"
ME=$(id -un)
PS_ALL=$(T ps -U "$ME" -ww -o pid= -o ppid= -o etime= -o command= 2>/dev/null)
# FreeBSD (setproctitle) pokazuje „redis-server: redis-server unixsocket:… (redis-server)”.
RS_LINES=$(printf '%s\n' "$PS_ALL" | awk '{ c = $4; sub(/.*\//, "", c); sub(/:$/, "", c) } c == "redis-server"')
RPID= RPPID= RCMD= PCMD= CWD=
if [ -z "$RS_LINES" ]; then
    brak "nie działa żaden proces redis-server użytkownika $ME"
else
    n=0
    while read -r pid ppid et rest; do
        n=$((n + 1))
        ok "PID $pid, działa od $et (dni-gg:mm:ss), rodzic $ppid"
        kv "linia poleceń" "$rest"
        if [ -z "$RPID" ]; then RPID=$pid; RPPID=$ppid; RCMD=$rest; fi
        case "$rest" in *"$SOCK"*) RPID=$pid; RPPID=$ppid; RCMD=$rest ;; esac
    done <<EOF
$RS_LINES
EOF
    [ "$n" -gt 1 ] && inf "procesów redis-server: $n; dalej opisuję PID $RPID"
    if [ -n "$RPPID" ] && [ "$RPPID" != 1 ]; then
        PCMD=$(T ps -p "$RPPID" -ww -o command= 2>/dev/null)
        [ -n "$PCMD" ] && kv "proces nadrzędny" "$PCMD"
    fi
    if have procstat; then
        CWD=$(T procstat -f "$RPID" 2>/dev/null | awk '$3 == "cwd" { print $NF; exit }')
        [ -n "$CWD" ] && kv "katalog roboczy" "$CWD"
    fi
    case "$RCMD" in
        *unixsocket:*|*:[0-9]*) inf "Redis zastępuje tytuł procesu adresem nasłuchu (proc-title-template), więc plik konfiguracyjny i parametry szukam też w procesie nadrzędnym i crontabie" ;;
    esac
fi
if have screen; then
    SCR=$(T screen -ls 2>/dev/null | grep -i redis)
    [ -n "$SCR" ] && kv "sesje screen" "$(printf '%s' "$SCR" | tr -s ' \t' ' ' | tr '\n' ';')"
fi
CRON=$(T crontab -l 2>/dev/null | grep -v '^[[:space:]]*#' | grep 'redis-server')
if [ -n "$CRON" ]; then
    while IFS= read -r l; do kv "crontab" "$l"; done <<EOF
$CRON
EOF
else
    inf "brak wpisu redis-server w crontabie (Redis nie wystartuje sam po restarcie serwera)"
fi

# ── 2. Konfiguracja ─────────────────────────────────────────────────────────
sect "2. Plik konfiguracyjny i parametry startowe"
ARGS_ALL=$(printf '%s\n%s\n%s\n' "$RCMD" "$PCMD" "$CRON")

try_conf() {
    [ -z "$CONF" ] || return 0
    local c
    c=$(printf '%s\n' "$2" | awk -v q="'" '{ for (i = 1; i <= NF; i++) { t = $i; gsub("^[\"" q "]|[\"" q "]$", "", t); if (t ~ /\.conf$/) { print t; exit } } }')
    [ -n "$c" ] || return 0
    case "$c" in
        "~/"*)     c="$HOME/${c#"~/"}" ;;
        '$HOME/'*) c="$HOME/${c#'$HOME/'}" ;;
        /*)        ;;
        *)         [ -n "$CWD" ] && c="$CWD/$c" ;;
    esac
    CONF=$c; CONF_SRC=$1
}
try_conf "linia poleceń redis-server" "$RCMD"
try_conf "proces nadrzędny (screen)" "$PCMD"
while IFS= read -r l; do try_conf "crontab" "$l"; done <<EOF
$CRON
EOF

# Nie ma w liniach poleceń → zapytaj działający Redis (INFO server: config_file).
if [ -z "$CONF" ] && [ -n "$RCLI" ] && [ -S "$SOCK" ]; then
    for pw in "${PW_V[@]}" ""; do
        out=$(rcli "$pw" sock INFO server | tr -d '\r')
        cf=$(printf '%s\n' "$out" | awk -F: '$1 == "config_file" { sub(/^[^:]*:/, ""); print; exit }')
        if [ -n "$cf" ]; then CONF=$cf; CONF_SRC="INFO server (config_file) działającego Redisa"; break; fi
        printf '%s' "$out" | grep -q redis_version && break   # odpowiedział, ale bez pliku konfiguracyjnego
    done
fi
# Ostatecznie: redis*.conf w katalogu domowym.
if [ -z "$CONF" ]; then
    FOUND=$(T find "$HOME" -maxdepth 3 -name 'redis*.conf' -type f 2>/dev/null | sort)
    if [ -n "$FOUND" ]; then
        CONF=$(printf '%s\n' "$FOUND" | while IFS= read -r f; do grep -q "unixsocket[[:space:]].*$(basename "$SOCK")" "$f" 2>/dev/null && { echo "$f"; break; }; done)
        [ -n "$CONF" ] || CONF=$(printf '%s\n' "$FOUND" | head -n 1)
        CONF_SRC="znaleziony w katalogu domowym (nie wiadomo, czy użyty przy starcie)"
        [ "$(printf '%s\n' "$FOUND" | wc -l | tr -d ' ')" -gt 1 ] && kv "znalezione pliki" "$(printf '%s' "$FOUND" | tr '\n' ' ')"
    fi
fi

if [ -z "$CONF" ]; then
    brak "nie ustalono pliku konfiguracyjnego (Redis mógł wystartować bez pliku, na samych parametrach)"
elif [ ! -r "$CONF" ]; then
    brak "plik konfiguracyjny $CONF ($CONF_SRC) nie istnieje albo nie da się go odczytać"
else
    ok "plik: $CONF"
    kv "skąd wiadomo" "$CONF_SRC"
    kv "zmieniony" "$(ls -l "$CONF" | awk '{ print $6, $7, $8 }')"
    CONF_TEXT=$(cat "$CONF")
    for pat in $(confall include | awk '{ print $2 }'); do
        for f in $pat; do
            [ -r "$f" ] || continue
            CONF_TEXT="$CONF_TEXT
$(cat "$f")"
            kv "include" "$f"
        done
    done
fi

ANY_ARG=
for k in port bind unixsocket unixsocketperm requirepass aclfile dir logfile; do
    if v=$(argval "$k"); then
        [ -n "$ANY_ARG" ] || inf "parametry z linii poleceń (mają pierwszeństwo przed plikiem):"
        ANY_ARG=1
        kv "--$k" "$v"
    fi
done
[ -n "$ANY_ARG" ] || inf "brak parametrów --port/--unixsocket/--requirepass… w liniach poleceń"
for k in bind protected-mode dir logfile maxmemory maxmemory-policy appendonly; do
    eff "$k" && kv "$k" "$EV ($ES)"
done

# ── 3. Port i gniazdo ────────────────────────────────────────────────────────
sect "3. Port TCP i gniazdo"
TITLE_PORT=$(printf '%s\n' "$RCMD" | awk '{ for (i = 2; i <= NF; i++) if ($i ~ /^[^\/]*:[0-9]+$/) { n = split($i, a, ":"); print a[n]; exit } }')
if eff port; then PORT=$EV; PORT_SRC=$ES
elif [ -n "$TITLE_PORT" ]; then PORT=$TITLE_PORT; PORT_SRC="tytuł procesu"
elif [ -n "$CONF_TEXT" ]; then PORT=6379; PORT_SRC="domyślny Redisa (brak dyrektywy port)"
else PORT=; PORT_SRC=; fi

if eff bind; then BIND=$EV; else BIND=; fi
for b in $BIND; do
    b=${b#-}
    case "$b" in '*'|0.0.0.0|'::*'|::) TCP_HOST=127.0.0.1 ;; *) TCP_HOST=$b ;; esac
    break
done

SS_TCP=; SS_UNIX=
if have sockstat && [ -n "$RPID" ]; then
    SS_TCP=$(T sockstat -4 -6 -l 2>/dev/null | awk -v p="$RPID" '$3 == p { print $5 " " $6 }')
    SS_UNIX=$(T sockstat -l -u 2>/dev/null | awk -v p="$RPID" '$3 == p { print $6 }')
fi

if [ -z "$PORT" ]; then
    inf "port TCP nieznany (brak konfiguracji)"
elif [ "$PORT" = 0 ]; then
    ok "TCP wyłączony: port 0 ($PORT_SRC)"
else
    inf "port TCP: $PORT ($PORT_SRC), bind: ${BIND:-domyślny (* -::*)}, test przez $TCP_HOST"
fi
if [ -n "$SS_TCP" ]; then
    while IFS= read -r l; do kv "sockstat: nasłuch TCP" "$l"; done <<EOF
$SS_TCP
EOF
    [ "$PORT" = 0 ] && brak "port 0 w konfiguracji, a proces nasłuchuje na TCP — działa ze starszą konfiguracją?"
elif have sockstat && [ -n "$RPID" ] && [ -n "$PORT" ] && [ "$PORT" != 0 ]; then
    brak "sockstat nie pokazuje nasłuchu TCP procesu $RPID na porcie $PORT"
fi
have sockstat || inf "brak sockstat — nie sprawdzam, na czym proces faktycznie nasłuchuje"

kv "gniazdo (argument/domyślne)" "$SOCK"
if eff unixsocket; then
    if [ "$(rp "$EV")" = "$(rp "$SOCK")" ]; then ok "zgodne z unixsocket ($ES)"
    else brak "unixsocket w konfiguracji ($ES) to $EV — inne niż sprawdzane $SOCK"; fi
elif [ -n "$CONF_TEXT" ]; then
    brak "konfiguracja nie ustawia unixsocket — Redis nie nasłuchuje na gnieździe"
fi
eff unixsocketperm && kv "unixsocketperm" "$EV ($ES)"
if [ -S "$SOCK" ]; then
    ok "gniazdo istnieje"
    kv "uprawnienia" "$(ls -ld "$SOCK" | awk '{ print $1 }')"
    kv "właściciel" "$(ls -ld "$SOCK" | awk '{ print $3 ":" $4 }')"
    kv "katalog" "$(ls -ld "$(dirname "$SOCK")" | awk '{ print $1, $3 ":" $4 }')"
elif [ -e "$SOCK" ]; then
    brak "$SOCK istnieje, ale to nie gniazdo ($(ls -ld "$SOCK" | awk '{ print $1 }'))"
else
    brak "brak gniazda $SOCK"
fi
if [ -n "$SS_UNIX" ]; then
    hit=
    while IFS= read -r l; do
        kv "sockstat: nasłuch unix" "$l"
        [ "$(rp "$l")" = "$(rp "$SOCK")" ] && hit=1
    done <<EOF
$SS_UNIX
EOF
    [ -n "$hit" ] && ok "proces $RPID nasłuchuje na tym gnieździe" || brak "proces $RPID nasłuchuje na innym gnieździe niż $SOCK"
fi

# ── 4. Hasła ─────────────────────────────────────────────────────────────────
sect "4. Hasła (JAWNYM TEKSTEM)"
CFG_PW=; CFG_PW_SRC=
if eff requirepass; then
    CFG_PW=$EV; CFG_PW_SRC=$ES
    if [ -n "$CFG_PW" ]; then ok "requirepass ($ES): \"$CFG_PW\" (${#CFG_PW} znaków)"
    else inf "requirepass ($ES) puste — Redis bez hasła"; fi
    [ "$ES" = "linia poleceń" ] && [ -n "$CFG_PW" ] && brak "hasło podane w linii poleceń widać w liście procesów — przenieś je do redis.conf"
elif [ -n "$CONF_TEXT" ]; then
    inf "requirepass nie ustawione — Redis bez hasła"
else
    inf "requirepass nieznane (nie odczytano konfiguracji)"
fi

for e in "${ENV_REPORT[@]}"; do
    case "$e" in ok\|*) ok "${e#ok|}" ;; *) inf "${e#i|}" ;; esac
done
[ -n "$ENV_FILES" ] || inf "brak plików .env w ~/domains (do 3 poziomów, z symlinkami)"

if [ -z "$APP" ]; then
    inf "nie znaleziono katalogu SZO (ustaw SZO_APP_DIR) — pomijam hasło z bazy"
elif ! have php; then
    inf "brak php — pomijam hasło z bazy SZO"
elif [ -n "$DB_ERR" ]; then
    brak "baza SZO ($APP): $DB_ERR"
elif [ -z "$DB_OUT" ]; then
    brak "baza SZO ($APP): brak odpowiedzi w ${TMO} s"
else
    label="baza SZO, $DB_SRC"
    if [ -n "$DB_PW" ]; then ok "$label: redis_password=\"$DB_PW\" (${#DB_PW} znaków)"
    else inf "$label: redis_password puste"; fi
    for k in redis_enabled redis_socket redis_host redis_port redis_db redis_prefix; do
        v=$(dbset "$k") && kv "$k" "${v:-(puste)}"
    done
    dbsock=$(dbset redis_socket)
    if [ -n "$dbsock" ] && [ "$(rp "${dbsock#unix://}")" != "$(rp "$SOCK")" ]; then
        brak "SZO łączy się z gniazdem $dbsock — innym niż sprawdzane $SOCK"
    fi
    [ "$(dbset redis_enabled)" = 1 ] || inf "cache Redis w SZO jest wyłączony (redis_enabled ≠ 1)"
fi

if [ -n "$PWFILES" ]; then
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        v=$(head -n 1 "$f" 2>/dev/null | tr -d '\r')
        if [ -n "$v" ]; then ok "plik $f: \"$v\" (${#v} znaków)"; else inf "plik $f pusty"; fi
    done <<EOF
$PWFILES
EOF
fi

# ── 5. Zgodność ──────────────────────────────────────────────────────────────
sect "5. Zgodność haseł z requirepass"
if [ ${#PW_L[@]} -eq 0 ]; then
    inf "brak haseł w .env, bazie SZO i pliku hasła — nie ma czego porównać"
elif [ -z "$CFG_PW" ]; then
    if [ -n "$CONF_TEXT" ] || [ -n "$CFG_PW_SRC" ]; then
        inf "Redis nie wymaga hasła — hasła z innych źródeł nie mają znaczenia (redis-cli zgłosi tylko błąd AUTH)"
    else
        inf "nie znam requirepass — porównanie pominięte, rozstrzygnie test PING"
    fi
else
    for i in "${!PW_L[@]}"; do
        if [ "${PW_V[$i]}" = "$CFG_PW" ]; then ok "${PW_L[$i]}: takie samo jak requirepass"
        else brak "${PW_L[$i]}: INNE niż requirepass"; fi
    done
fi

# ── 6. PING ──────────────────────────────────────────────────────────────────
sect "6. Test PING"
WORK_MODE= WORK_PW= WORK_DESC=
if [ -z "$RCLI" ]; then
    brak "brak redis-cli w PATH — pomijam testy połączenia"
else
    # Lista prób: konfiguracja, .env, baza, plik, bez hasła; to samo hasło testuję raz.
    TL=(); TV=()
    add_test() {
        local i
        for i in "${!TV[@]}"; do
            if [ "${TV[$i]}" = "$2" ]; then TL[$i]="${TL[$i]} = $1"; return; fi
        done
        TL+=("$1"); TV+=("$2")
    }
    [ -n "$CFG_PW" ] && add_test "hasło z konfiguracji" "$CFG_PW"
    for i in "${!PW_L[@]}"; do add_test "${PW_L[$i]}" "${PW_V[$i]}"; done
    add_test "bez hasła" ""

    MODES="sock"
    [ -n "$PORT" ] && [ "$PORT" != 0 ] && MODES="sock tcp"
    for mode in $MODES; do
        if [ "$mode" = sock ]; then
            mdesc="gniazdo"
            if [ ! -S "$SOCK" ]; then brak "gniazdo: pomijam, brak $SOCK"; continue; fi
        else
            mdesc="TCP $TCP_HOST:$PORT"
        fi
        for i in "${!TL[@]}"; do
            out=$(rcli "${TV[$i]}" "$mode" PING); rc=$?
            if printf '%s\n' "$out" | grep -q '^PONG'; then
                note=
                printf '%s' "$out" | grep -q 'without any password configured' && note=" (Redis nie ma hasła, AUTH zignorowany)"
                ok "$mdesc + ${TL[$i]}: PONG$note"
                if [ -z "$WORK_MODE" ]; then WORK_MODE=$mode; WORK_PW=${TV[$i]}; WORK_DESC="$mdesc + ${TL[$i]}"; fi
            else
                msg=$(printf '%s\n' "$out" | grep -v '^[[:space:]]*$' | tr '\n' ' ' | sed 's/ *$//' | cut -c1-200)
                is_timeout "$rc" && msg="brak odpowiedzi w ${TMO} s"
                # Brak połączenia nie zależy od hasła — zgłoś raz i przejdź do następnego kanału.
                if is_timeout "$rc" || printf '%s' "$out" | grep -q 'Could not connect'; then
                    brak "$mdesc: $msg"
                    break
                fi
                if [ -z "${TV[$i]}" ] && printf '%s' "$out" | grep -q NOAUTH; then
                    inf "$mdesc + bez hasła: NOAUTH — Redis wymaga hasła (tak ma być)"
                else
                    brak "$mdesc + ${TL[$i]}: ${msg:-brak odpowiedzi}"
                fi
            fi
        done
    done
    if [ -n "$PORT" ] && [ "$PORT" != 0 ] && [ -z "$CFG_PW" ] && [ -n "$CONF_TEXT" ]; then
        brak "otwarty port TCP $PORT bez hasła — na hostingu współdzielonym połączy się każdy użytkownik serwera"
    fi
    if [ -n "$WORK_MODE" ]; then ok "działa: $WORK_DESC"
    else brak "żadna kombinacja nie zwróciła PONG"; fi
fi

# ── 7. INFO ──────────────────────────────────────────────────────────────────
sect "7. INFO"
if [ -z "$WORK_MODE" ]; then
    inf "pomijam — brak działającego połączenia"
else
    INFO=$(rcli "$WORK_PW" "$WORK_MODE" INFO | tr -d '\r')
    iv() { printf '%s\n' "$INFO" | awk -F: -v k="$1" '$1 == k { sub(/^[^:]*:/, ""); print; exit }'; }
    ok "Redis $(iv redis_version), działa od $(iv uptime_in_days) dni, klientów: $(iv connected_clients)"
    cf=$(iv config_file)
    if [ -n "$cf" ]; then
        if [ -n "$CONF" ] && [ "$(rp "$cf")" = "$(rp "$CONF")" ]; then ok "config_file: $cf (zgodny z sekcją 2)"
        elif [ -n "$CONF" ]; then brak "config_file z INFO to $cf, a w sekcji 2 analizowano $CONF"
        else inf "config_file: $cf"; fi
    else
        inf "config_file: brak (Redis uruchomiony bez pliku konfiguracyjnego)"
    fi
    kv "tcp_port" "$(iv tcp_port)"
    kv "pamięć" "$(iv used_memory_human) (szczyt $(iv used_memory_peak_human)), limit $(iv maxmemory_human), polityka $(iv maxmemory_policy)"
    rdb=$(iv rdb_last_bgsave_status)
    if [ -n "$rdb" ] && [ "$rdb" != ok ]; then brak "ostatni zapis RDB: $rdb — sprawdź miejsce na dysku (quota) i log Redisa"
    else kv "zapis RDB" "${rdb:-?}, AOF: $( [ "$(iv aof_enabled)" = 1 ] && echo tak || echo nie )"; fi
    kv "db0 (keyspace)" "$(iv db0)"
    kv "DBSIZE (baza $DBNUM)" "$(rcli "$WORK_PW" "$WORK_MODE" DBSIZE | tail -n 1)"
    sc=$(rcli "$WORK_PW" "$WORK_MODE" --scan --pattern "${PREFIX}*"; echo "__rc=$?")
    src=${sc##*__rc=}
    cnt=$(printf '%s\n' "$sc" | awk -v p="$PREFIX" 'index($0, p) == 1 { n++ } END { print n + 0 }')
    if is_timeout "$src"; then kv "klucze ${PREFIX}* (SCAN)" "co najmniej $cnt (przerwano po ${TMO} s)"
    else kv "klucze ${PREFIX}* (SCAN)" "$cnt"; fi

    # Wartości w działającym procesie (mogą różnić się od pliku, jeśli nie restartowano).
    cg() { rcli "$WORK_PW" "$WORK_MODE" CONFIG GET "$1" | sed -n 2p; }
    live_port=$(cg port); live_sock=$(cg unixsocket); live_pw=$(cg requirepass)
    if [ -n "$live_port$live_sock" ]; then
        kv "CONFIG GET port / unixsocket" "${live_port:-?} / ${live_sock:-(brak)}"
        if [ -n "$CFG_PW" ] && [ "$live_pw" != "$CFG_PW" ]; then
            brak "requirepass w działającym Redisie (\"$live_pw\") różni się od pliku — plik zmieniono bez restartu?"
        elif [ -n "$live_pw" ]; then
            kv "CONFIG GET requirepass" "\"$live_pw\""
        fi
    else
        inf "CONFIG GET niedostępne (wyłączone albo brak uprawnień ACL)"
    fi
fi

# ── 8. ACL ───────────────────────────────────────────────────────────────────
sect "8. ACL"
acl_any=
if eff aclfile; then
    acl_any=1
    inf "aclfile ($ES): $EV"
    if [ -r "$EV" ]; then
        USERS=$(grep -E '^[[:space:]]*user[[:space:]]' "$EV")
        if [ -n "$USERS" ]; then while IFS= read -r l; do kv "aclfile" "$l"; done <<EOF
$USERS
EOF
        else inf "aclfile bez linii user"; fi
    else
        brak "nie można odczytać $EV"
    fi
fi
USERS=$(confall user)
if [ -n "$USERS" ]; then
    acl_any=1
    while IFS= read -r l; do kv "redis.conf" "$l"; done <<EOF
$USERS
EOF
fi
[ -n "$acl_any" ] || inf "brak aclfile i linii user — jest tylko użytkownik default (hasło = requirepass)"
if [ -n "$WORK_MODE" ]; then
    kv "ACL WHOAMI" "$(rcli "$WORK_PW" "$WORK_MODE" ACL WHOAMI | tail -n 1)"
    rcli "$WORK_PW" "$WORK_MODE" ACL LIST | while IFS= read -r l; do kv "ACL LIST" "$l"; done
fi

# ── Podsumowanie ─────────────────────────────────────────────────────────────
sect "Podsumowanie"
if [ -n "$WORK_MODE" ]; then ok "Redis odpowiada: $WORK_DESC"; else printf '  %s[BRAK]%s Redis nie odpowiada na żadną kombinację\n' "$C_NO" "$C_0"; fi
if [ ${#PROBLEMS[@]} -gt 0 ]; then
    printf '  Do sprawdzenia (%d):\n' "${#PROBLEMS[@]}"
    for p in "${PROBLEMS[@]}"; do printf '    - %s\n' "$p"; done
else
    ok "nie znaleziono problemów"
fi
[ -n "$WORK_MODE" ] && exit 0 || exit 1
