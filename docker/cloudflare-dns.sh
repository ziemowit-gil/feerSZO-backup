#!/usr/bin/env bash
# cloudflare-dns.sh — konfigurator DNS w Cloudflare dla subdomen przekierowań
#
# Tworzy / aktualizuje rekordy A (lub AAAA) dla subdomen ngosystem.pl, które
# Traefik 301-przekierowuje na główną aplikację (szo.feer.org.pl):
#
#     crm.ngosystem.pl       → https://szo.feer.org.pl/crm
#     zadania.ngosystem.pl   → https://szo.feer.org.pl/tasks
#     ti.ngosystem.pl        → https://szo.feer.org.pl/karty30/ti/kursant/
#
# Skrypt jest IDEMPOTENTNY: istniejące rekordy aktualizuje, brakujące tworzy.
#
# ── Wymagania ─────────────────────────────────────────────────────────────────
#   - curl, jq
#   - Token API Cloudflare z uprawnieniem  Zone → DNS → Edit  dla strefy
#     ngosystem.pl  (https://dash.cloudflare.com/profile/api-tokens)
#
# ── Konfiguracja ────────────────────────────────────────────────────────────
# Wartości pobierane w kolejności: flagi CLI > zmienne środowiskowe >
# plik docker/.env.cloudflare (jeśli istnieje).
#
#   CF_API_TOKEN   Token API Cloudflare              (wymagane)
#   SERVER_IP      IP serwera, na który celują rekordy (wymagane)
#   CF_ZONE        Nazwa strefy (domyślnie wykrywana z hostów, np. ngosystem.pl)
#   HOSTS          Lista hostów oddzielona spacją
#                  (domyślnie: crm.ngosystem.pl zadania.ngosystem.pl ti.ngosystem.pl)
#   PROXIED        true|false — pomarańczowa chmurka (domyślnie: true)
#   TTL            TTL w sekundach; 1 = auto (wymagane przy PROXIED=true)
#
# ── Użycie ──────────────────────────────────────────────────────────────────
#   bash docker/cloudflare-dns.sh --ip 203.0.113.10
#   bash docker/cloudflare-dns.sh --ip 203.0.113.10 --dns-only
#   bash docker/cloudflare-dns.sh --ip 203.0.113.10 crm.ngosystem.pl ti.ngosystem.pl
#   bash docker/cloudflare-dns.sh --dry-run
#   bash docker/cloudflare-dns.sh --delete crm.ngosystem.pl
#
# Flagi:
#   --ip <addr>      IP serwera (nadpisuje SERVER_IP)
#   --zone <name>    Wymuś nazwę strefy
#   --token <tok>    Token API (nadpisuje CF_API_TOKEN)
#   --proxied        Wymuś rekordy proxied (pomarańczowa chmurka)
#   --dns-only       Wymuś rekordy DNS-only (szara chmurka)
#   --ttl <sec>      TTL (domyślnie 1 = auto)
#   --delete         Usuń podane rekordy zamiast tworzyć
#   --dry-run        Pokaż co zostanie zrobione, bez zmian w Cloudflare
#   -h, --help       Ta pomoc

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env.cloudflare"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info() { echo -e "${CYAN}▸ $*${RESET}"; }
ok()   { echo -e "${GREEN}✔ $*${RESET}"; }
warn() { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()  { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

usage() { grep '^#' "$0" | sed 's/^# \{0,1\}//' | sed '1d'; exit 0; }

# ── Domyślne wartości ─────────────────────────────────────────────────────────
DEFAULT_HOSTS="crm.ngosystem.pl zadania.ngosystem.pl ti.ngosystem.pl"
PROXIED="${PROXIED:-true}"
TTL="${TTL:-1}"
DRY_RUN=0
DO_DELETE=0
CLI_HOSTS=()

# ── Wczytaj plik konfiguracyjny (opcjonalny) ──────────────────────────────────
if [[ -f "$ENV_FILE" ]]; then
    # shellcheck disable=SC1090
    set -a; source "$ENV_FILE"; set +a
    info "Wczytano konfigurację z ${ENV_FILE}"
fi

# ── Parsowanie argumentów ─────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)   usage ;;
        --ip)        SERVER_IP="${2:?--ip wymaga adresu}"; shift 2 ;;
        --zone)      CF_ZONE="${2:?--zone wymaga nazwy}"; shift 2 ;;
        --token)     CF_API_TOKEN="${2:?--token wymaga wartości}"; shift 2 ;;
        --proxied)   PROXIED="true";  shift ;;
        --dns-only)  PROXIED="false"; shift ;;
        --ttl)       TTL="${2:?--ttl wymaga liczby}"; shift 2 ;;
        --delete)    DO_DELETE=1; shift ;;
        --dry-run)   DRY_RUN=1; shift ;;
        -*)          die "Nieznana flaga: $1 (użyj --help)" ;;
        *)           CLI_HOSTS+=("$1"); shift ;;
    esac
done

# ── Walidacja zależności ──────────────────────────────────────────────────────
command -v curl >/dev/null || die "Brak 'curl'. Zainstaluj: apt-get install -y curl"
command -v jq   >/dev/null || die "Brak 'jq'. Zainstaluj: apt-get install -y jq"

# ── Walidacja konfiguracji ────────────────────────────────────────────────────
: "${CF_API_TOKEN:?Brak CF_API_TOKEN (flaga --token, zmienna env lub ${ENV_FILE})}"
if [[ $DO_DELETE -eq 0 ]]; then
    : "${SERVER_IP:?Brak SERVER_IP (flaga --ip, zmienna env lub ${ENV_FILE})}"
fi

# Hosty: z CLI, inaczej z HOSTS, inaczej domyślne
if [[ ${#CLI_HOSTS[@]} -gt 0 ]]; then
    HOSTS="${CLI_HOSTS[*]}"
else
    HOSTS="${HOSTS:-$DEFAULT_HOSTS}"
fi

# Typ rekordu: AAAA gdy IP wygląda na IPv6, inaczej A
RECORD_TYPE="A"
if [[ "${SERVER_IP:-}" == *:* ]]; then
    RECORD_TYPE="AAAA"
fi

# Walidacja TTL przy proxied
if [[ "$PROXIED" == "true" && "$TTL" != "1" ]]; then
    warn "PROXIED=true wymaga TTL=auto — wymuszam TTL=1."
    TTL=1
fi

CF_API="https://api.cloudflare.com/client/v4"

# ── Wrapper API Cloudflare ────────────────────────────────────────────────────
# cf <method> <path> [json-body]
cf() {
    local method="$1" path="$2" body="${3:-}"
    local args=(-sS -X "$method" "${CF_API}${path}"
        -H "Authorization: Bearer ${CF_API_TOKEN}"
        -H "Content-Type: application/json")
    [[ -n "$body" ]] && args+=(--data "$body")
    curl "${args[@]}"
}

# Sprawdza .success i przy błędzie wypisuje komunikaty
cf_check() {
    local resp="$1" ctx="$2"
    if [[ "$(jq -r '.success' <<<"$resp")" != "true" ]]; then
        local msg
        msg="$(jq -r '[.errors[]? | "\(.code): \(.message)"] | join("; ")' <<<"$resp")"
        die "Cloudflare API (${ctx}): ${msg:-nieznany błąd}"
    fi
}

# ── Wykrywanie strefy dla hosta ───────────────────────────────────────────────
# Zwraca zone_id dla danego hosta. Wynik cache'owany w ZONE_CACHE.
declare -A ZONE_CACHE
resolve_zone_id() {
    local host="$1"

    # Jeśli wymuszono CF_ZONE — użyj go bezpośrednio
    if [[ -n "${CF_ZONE:-}" ]]; then
        if [[ -n "${ZONE_CACHE[$CF_ZONE]:-}" ]]; then
            echo "${ZONE_CACHE[$CF_ZONE]}"; return
        fi
        local r id
        r="$(cf GET "/zones?name=${CF_ZONE}&status=active")"
        cf_check "$r" "zones?name=${CF_ZONE}"
        id="$(jq -r '.result[0].id // empty' <<<"$r")"
        [[ -n "$id" ]] || die "Nie znaleziono strefy '${CF_ZONE}' na koncie tego tokenu."
        ZONE_CACHE[$CF_ZONE]="$id"
        echo "$id"; return
    fi

    # Inaczej: próbuj sufiksów hosta od najdłuższego (obsługa też com.pl itp.)
    local IFS='.'; read -ra parts <<<"$host"; unset IFS
    local n=${#parts[@]} i candidate r id
    for ((i=0; i<=n-2; i++)); do
        candidate="$(IFS='.'; echo "${parts[*]:i}")"
        if [[ -n "${ZONE_CACHE[$candidate]:-}" ]]; then
            echo "${ZONE_CACHE[$candidate]}"; return
        fi
        r="$(cf GET "/zones?name=${candidate}&status=active")"
        cf_check "$r" "zones?name=${candidate}"
        id="$(jq -r '.result[0].id // empty' <<<"$r")"
        if [[ -n "$id" ]]; then
            ZONE_CACHE[$candidate]="$id"
            info "Strefa dla ${host}: ${candidate} (${id})"
            echo "$id"; return
        fi
    done
    die "Nie udało się dopasować strefy Cloudflare dla hosta '${host}'. Użyj --zone."
}

# ── Upsert pojedynczego rekordu ───────────────────────────────────────────────
upsert_record() {
    local host="$1" zone_id="$2"
    local payload existing rec_id resp action

    payload="$(jq -nc \
        --arg type "$RECORD_TYPE" --arg name "$host" --arg content "$SERVER_IP" \
        --argjson ttl "$TTL" --argjson proxied "$PROXIED" \
        '{type:$type, name:$name, content:$content, ttl:$ttl, proxied:$proxied}')"

    existing="$(cf GET "/zones/${zone_id}/dns_records?type=${RECORD_TYPE}&name=${host}")"
    cf_check "$existing" "list ${host}"
    rec_id="$(jq -r '.result[0].id // empty' <<<"$existing")"

    if [[ -n "$rec_id" ]]; then
        action="aktualizacja"
        if [[ $DRY_RUN -eq 1 ]]; then
            info "[dry-run] ${action}: ${RECORD_TYPE} ${host} → ${SERVER_IP} (proxied=${PROXIED}, ttl=${TTL})"
            return
        fi
        resp="$(cf PUT "/zones/${zone_id}/dns_records/${rec_id}" "$payload")"
    else
        action="utworzenie"
        if [[ $DRY_RUN -eq 1 ]]; then
            info "[dry-run] ${action}: ${RECORD_TYPE} ${host} → ${SERVER_IP} (proxied=${PROXIED}, ttl=${TTL})"
            return
        fi
        resp="$(cf POST "/zones/${zone_id}/dns_records" "$payload")"
    fi
    cf_check "$resp" "${action} ${host}"
    ok "${action^}: ${RECORD_TYPE} ${host} → ${SERVER_IP} (proxied=${PROXIED}, ttl=${TTL})"
}

# ── Usunięcie pojedynczego rekordu ────────────────────────────────────────────
delete_record() {
    local host="$1" zone_id="$2"
    local existing rec_id resp

    existing="$(cf GET "/zones/${zone_id}/dns_records?type=${RECORD_TYPE}&name=${host}")"
    cf_check "$existing" "list ${host}"
    rec_id="$(jq -r '.result[0].id // empty' <<<"$existing")"

    if [[ -z "$rec_id" ]]; then
        warn "Brak rekordu ${RECORD_TYPE} ${host} — nic do usunięcia."
        return
    fi
    if [[ $DRY_RUN -eq 1 ]]; then
        info "[dry-run] usunięcie: ${RECORD_TYPE} ${host}"
        return
    fi
    resp="$(cf DELETE "/zones/${zone_id}/dns_records/${rec_id}")"
    cf_check "$resp" "usunięcie ${host}"
    ok "Usunięto: ${RECORD_TYPE} ${host}"
}

# ── Weryfikacja tokenu ────────────────────────────────────────────────────────
verify="$(cf GET "/user/tokens/verify")"
cf_check "$verify" "verify token"
ok "Token API Cloudflare zweryfikowany."

# ── Podsumowanie planu ────────────────────────────────────────────────────────
echo -e "${BOLD}━━ Plan ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
if [[ $DO_DELETE -eq 1 ]]; then
    info "Operacja: USUWANIE rekordów"
else
    info "Operacja: utworzenie/aktualizacja rekordów ${RECORD_TYPE} → ${SERVER_IP}"
    info "Proxied: ${PROXIED}  |  TTL: ${TTL}"
fi
info "Hosty: ${HOSTS}"
[[ $DRY_RUN -eq 1 ]] && warn "Tryb --dry-run: bez realnych zmian."
echo ""

# ── Wykonanie ─────────────────────────────────────────────────────────────────
for host in $HOSTS; do
    zid="$(resolve_zone_id "$host")"
    if [[ $DO_DELETE -eq 1 ]]; then
        delete_record "$host" "$zid"
    else
        upsert_record "$host" "$zid"
    fi
done

echo ""
ok "Gotowe."
if [[ $DO_DELETE -eq 0 && "$PROXIED" == "true" ]]; then
    warn "Rekordy są PROXIED (pomarańczowa chmurka). W Cloudflare ustaw SSL/TLS = Full (strict),"
    warn "aby uniknąć pętli przekierowań. Jeśli Traefik nie może pobrać certyfikatu Let's Encrypt"
    warn "(challenge HTTP-01), wyłącz na czas wydania cert. opcję 'Always Use HTTPS' lub użyj --dns-only."
fi
