#!/usr/bin/env bash
# setup-subdomains.sh — DNS + SSL + deploy subdomen modułów *.szo.feer.org.pl
#
# Konfiguruje kompletny routing subdomen dla modułów systemu:
#   1. Rekordy DNS w Cloudflare (wildcard lub per-host)
#   2. Certyfikat SSL — wildcard przez DNS-01 (zalecane) lub per-host HTTP-01
#   3. Deploy stacku Traefik z etykietami routerów
#   4. Weryfikacja: HTTP 200 + ważność certyfikatu każdej subdomeny
#
# Adresy modułów (obie domeny — ngosystem.pl przekierowuje 301 na szo.feer.org.pl):
#   crm.          ezd.           panel.         zadania.       helpdesk.
#   obiegi.       vpn.           workspaces.    poczta.        timesheets.
#   granty.       wsparcie.      wydarzenia.    raporty.       uchwaly.
#   korespondencja.  procedury.  komunikaty.    admin.         karty30.
#   ti.           tozsamosc.     strukturaorg.
#
# ── Tryby SSL ────────────────────────────────────────────────────────────────
#   --wildcard  (DOMYŚLNE, zalecane)
#       Jeden certyfikat *.szo.feer.org.pl przez DNS-01 Cloudflare.
#       Wymaga CF_DNS_API_TOKEN w .env.prod. Działa za proxy Cloudflare.
#       DNS: jeden rekord wildcard *.szo → IP.
#
#   --http01
#       Osobny certyfikat na subdomenę (HTTP-01). Wymaga otwartego portu 80
#       i DNS-only (szara chmurka). UWAGA: 23 subdomeny = 23 certyfikaty
#       z limitu Let's Encrypt (50/tydzień na domenę rejestrowaną).
#
# ── Wymagania ────────────────────────────────────────────────────────────────
#   - docker, curl, jq, openssl
#   - docker/.env.prod (ACME_EMAIL, DOMAIN; przy --wildcard też CF_DNS_API_TOKEN)
#   - Token Cloudflare z Zone → DNS → Edit (strefy feer.org.pl, ngosystem.pl)
#
# ── Użycie ───────────────────────────────────────────────────────────────────
#   bash docker/scripts/setup-subdomains.sh --dns-only --dry-run   # co się stanie
#   bash docker/scripts/setup-subdomains.sh --dns-only             # tylko DNS
#   bash docker/scripts/setup-subdomains.sh --deploy-only          # tylko deploy
#   bash docker/scripts/setup-subdomains.sh                        # wszystko
#   bash docker/scripts/setup-subdomains.sh --verify-only          # tylko test
#   bash docker/scripts/setup-subdomains.sh --http01 -y            # bez wildcardu
#
# Flagi:
#   --wildcard       SSL wildcard przez DNS-01 Cloudflare (domyślne)
#   --http01         SSL per-subdomena przez HTTP-01
#   --dns-only       Wykonaj tylko krok DNS
#   --deploy-only    Wykonaj tylko krok deploy
#   --verify-only    Wykonaj tylko weryfikację
#   --skip-dns       Pomiń krok DNS (gdy rekordy już istnieją)
#   --ip <addr>      IP serwera (domyślnie autodetekcja przez api.ipify.org)
#   --proxied        Rekordy DNS proxied (pomarańczowa chmurka) — tylko --wildcard
#   --mysql          Dołącz docker-compose.mysql.yml
#   --dry-run        Pokaż plan, nie wykonuj zmian
#   -y, --yes        Bez pytania o potwierdzenie
#   -h, --help       Ta pomoc

set -euo pipefail

# Tablice asocjacyjne (declare -A) wymagają bash 4+. macOS ma systemowo 3.2 —
# skrypt jest przeznaczony na serwer produkcyjny (Linux), lokalnie: brew install bash.
if (( BASH_VERSINFO[0] < 4 )); then
    echo "✖ Wymagany bash 4+ (masz ${BASH_VERSION}). Uruchom na serwerze lub: brew install bash" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Katalog z plikami docker-compose. Skrypt można uruchomić na dwa sposoby:
# bezpośrednio z docker/scripts/ albo przez symlink docker/setup-subdomains.sh —
# w drugim przypadku SCRIPT_DIR to już docker/, nie docker/scripts/.
if [[ -f "${SCRIPT_DIR}/docker-compose.yml" ]]; then
    DOCKER_DIR="$SCRIPT_DIR"
else
    DOCKER_DIR="$(dirname "$SCRIPT_DIR")"
fi
if [[ ! -f "${DOCKER_DIR}/docker-compose.yml" ]]; then
    echo "✖ Nie znalazłem docker-compose.yml (szukałem w ${DOCKER_DIR})" >&2
    exit 1
fi
ENV_PROD="${DOCKER_DIR}/.env.prod"
ENV_CF="${DOCKER_DIR}/.env.cloudflare"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info() { echo -e "${CYAN}▸ $*${RESET}"; }
ok()   { echo -e "${GREEN}✔ $*${RESET}"; }
warn() { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()  { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

usage() { grep '^#' "$0" | sed 's/^# \{0,1\}//' | sed '1d'; exit 0; }

# ── Subdomeny modułów (spójne z .htaccess i config.php) ──────────────────────
MODULES=(
    crm ezd panel zadania helpdesk obiegi vpn workspaces ws poczta
    timesheets czas granty wsparcie wydarzenia raporty uchwaly
    korespondencja procedury komunikaty admin karty30 ti tozsamosc strukturaorg
)
BASE_SZO="szo.feer.org.pl"
BASE_NGO="ngosystem.pl"

# ── Argumenty ────────────────────────────────────────────────────────────────
SSL_MODE="wildcard"
STEP_DNS=1; STEP_DEPLOY=1; STEP_VERIFY=1
SERVER_IP=""; PROXIED="false"; USE_MYSQL=0; DRY_RUN=0; ASSUME_YES=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)      usage ;;
        --wildcard)     SSL_MODE="wildcard"; shift ;;
        --http01)       SSL_MODE="http01";   shift ;;
        --dns-only)     STEP_DEPLOY=0; STEP_VERIFY=0; shift ;;
        --deploy-only)  STEP_DNS=0; STEP_VERIFY=0;    shift ;;
        --verify-only)  STEP_DNS=0; STEP_DEPLOY=0;    shift ;;
        --skip-dns)     STEP_DNS=0; shift ;;
        --ip)           SERVER_IP="${2:?--ip wymaga adresu}"; shift 2 ;;
        --proxied)      PROXIED="true";  shift ;;
        --mysql)        USE_MYSQL=1; shift ;;
        --dry-run)      DRY_RUN=1; shift ;;
        -y|--yes)       ASSUME_YES=1; shift ;;
        *)              die "Nieznana flaga: $1 (użyj --help)" ;;
    esac
done

# ── Walidacja zależności ─────────────────────────────────────────────────────
for cmd in curl jq openssl; do
    command -v "$cmd" >/dev/null || die "Brak '${cmd}'. Zainstaluj: apt-get install -y ${cmd}"
done
[[ $STEP_DEPLOY -eq 1 ]] && { command -v docker >/dev/null || die "Brak 'docker'."; }

# ── Wczytaj .env.prod ────────────────────────────────────────────────────────
if [[ -f "$ENV_PROD" ]]; then
    set -a; source "$ENV_PROD"; set +a
else
    [[ $STEP_DEPLOY -eq 1 ]] && die ".env.prod nie istnieje: ${ENV_PROD}"
    warn ".env.prod nie istnieje — pomijam."
fi

# Token Cloudflare: .env.prod (CF_DNS_API_TOKEN) lub .env.cloudflare (CF_API_TOKEN)
if [[ -z "${CF_API_TOKEN:-}" && -f "$ENV_CF" ]]; then
    set -a; source "$ENV_CF"; set +a
fi
CF_TOKEN="${CF_DNS_API_TOKEN:-${CF_API_TOKEN:-}}"

# ── Autodetekcja IP serwera ──────────────────────────────────────────────────
if [[ $STEP_DNS -eq 1 && -z "$SERVER_IP" ]]; then
    SERVER_IP="$(curl -s --max-time 8 https://api.ipify.org 2>/dev/null || true)"
    [[ -n "$SERVER_IP" ]] || die "Nie udało się wykryć IP serwera. Podaj --ip <addr>."
    info "Wykryte IP serwera: ${SERVER_IP}"
fi

RECORD_TYPE="A"
[[ "$SERVER_IP" == *:* ]] && RECORD_TYPE="AAAA"

# Przy DNS-only (szara chmurka) HTTP-01 może przejść; przy proxied nie.
if [[ "$SSL_MODE" == "http01" && "$PROXIED" == "true" ]]; then
    die "--http01 nie działa z --proxied (challenge nie dojdzie przez proxy Cloudflare). Użyj --wildcard."
fi

# ── Plan DNS ─────────────────────────────────────────────────────────────────
declare -a DNS_HOSTS
if [[ "$SSL_MODE" == "wildcard" ]]; then
    # Jeden rekord wildcard na domenę bazową + apex
    DNS_HOSTS=( "*.${BASE_SZO}" "${BASE_SZO}" "*.${BASE_NGO}" "${BASE_NGO}" )
else
    # Rekord per subdomena (HTTP-01 nie obsługuje wildcardów)
    DNS_HOSTS=()
    for m in "${MODULES[@]}"; do
        DNS_HOSTS+=( "${m}.${BASE_SZO}" )
    done
fi

# ── Podsumowanie planu ───────────────────────────────────────────────────────
echo -e "${BOLD}━━ setup-subdomains ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
info "Tryb SSL:     ${SSL_MODE}$([[ "$SSL_MODE" == wildcard ]] && echo ' (DNS-01 Cloudflare, 1 cert)' || echo " (HTTP-01, ${#MODULES[@]} certów)")"
info "Kroki:        DNS=$([[ $STEP_DNS -eq 1 ]] && echo tak || echo nie)  Deploy=$([[ $STEP_DEPLOY -eq 1 ]] && echo tak || echo nie)  Weryfikacja=$([[ $STEP_VERIFY -eq 1 ]] && echo tak || echo nie)"
[[ $STEP_DNS -eq 1 ]] && {
    info "IP serwera:   ${SERVER_IP} (${RECORD_TYPE})"
    info "Proxied:      ${PROXIED}"
    info "Rekordy DNS:  ${#DNS_HOSTS[@]} — ${DNS_HOSTS[*]}"
}
info "Modułów:      ${#MODULES[@]}"
[[ $DRY_RUN -eq 1 ]] && warn "Tryb --dry-run: bez realnych zmian."
echo ""

if [[ "$SSL_MODE" == "http01" ]]; then
    warn "HTTP-01 wyda ${#MODULES[@]} osobnych certyfikatów. Limit Let's Encrypt:"
    warn "50 certyfikatów / tydzień na domenę rejestrowaną (feer.org.pl)."
    warn "Zalecany tryb: --wildcard (1 certyfikat na wszystkie subdomeny)."
    echo ""
fi

if [[ $DRY_RUN -ne 1 && $ASSUME_YES -ne 1 ]]; then
    read -rp "$(echo -e "${CYAN}Kontynuować? [t/N]: ${RESET}")" _c
    [[ "$(printf '%s' "$_c" | tr '[:upper:]' '[:lower:]')" == t* ]] || { warn "Anulowano."; exit 0; }
fi

# ═════════════════════════════════════════════════════════════════════════════
#  KROK 1 — DNS w Cloudflare
# ═════════════════════════════════════════════════════════════════════════════
CF_API="https://api.cloudflare.com/client/v4"

cf() {
    local method="$1" path="$2" body="${3:-}"
    local args=(-sS --max-time 30 -X "$method" "${CF_API}${path}"
        -H "Authorization: Bearer ${CF_TOKEN}"
        -H "Content-Type: application/json")
    [[ -n "$body" ]] && args+=(--data "$body")
    curl "${args[@]}"
}

cf_check() {
    local resp="$1" ctx="$2"
    if [[ "$(jq -r '.success' <<<"$resp")" != "true" ]]; then
        local msg
        msg="$(jq -r '[.errors[]? | "\(.code): \(.message)"] | join("; ")' <<<"$resp")"
        die "Cloudflare API (${ctx}): ${msg:-nieznany błąd}"
    fi
}

declare -A ZONE_CACHE
resolve_zone_id() {
    local host="${1#\*.}"    # zdejmij prefiks wildcard przy wykrywaniu strefy
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
            echo "$id"; return
        fi
    done
    die "Nie znaleziono strefy Cloudflare dla '${host}'."
}

upsert_record() {
    local host="$1" zone_id="$2" payload existing rec_id resp action

    payload="$(jq -nc \
        --arg type "$RECORD_TYPE" --arg name "$host" --arg content "$SERVER_IP" \
        --argjson proxied "$PROXIED" \
        '{type:$type, name:$name, content:$content, ttl:1, proxied:$proxied}')"

    existing="$(cf GET "/zones/${zone_id}/dns_records?type=${RECORD_TYPE}&name=${host}")"
    cf_check "$existing" "list ${host}"
    rec_id="$(jq -r '.result[0].id // empty' <<<"$existing")"

    if [[ -n "$rec_id" ]]; then
        action="aktualizacja"
        [[ $DRY_RUN -eq 1 ]] && { info "[dry-run] ${action}: ${RECORD_TYPE} ${host} → ${SERVER_IP}"; return; }
        resp="$(cf PUT "/zones/${zone_id}/dns_records/${rec_id}" "$payload")"
    else
        action="utworzenie"
        [[ $DRY_RUN -eq 1 ]] && { info "[dry-run] ${action}: ${RECORD_TYPE} ${host} → ${SERVER_IP}"; return; }
        resp="$(cf POST "/zones/${zone_id}/dns_records" "$payload")"
    fi
    cf_check "$resp" "${action} ${host}"
    ok "${action^}: ${RECORD_TYPE} ${host} → ${SERVER_IP} (proxied=${PROXIED})"
}

if [[ $STEP_DNS -eq 1 ]]; then
    echo -e "${BOLD}━━ 1/3 DNS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    [[ -n "$CF_TOKEN" ]] || die "Brak tokenu Cloudflare. Ustaw CF_DNS_API_TOKEN w ${ENV_PROD} lub uruchom: bash ${SCRIPT_DIR}/cloudflare-dns.sh --init"

    verify="$(cf GET "/user/tokens/verify")"
    cf_check "$verify" "verify token"
    ok "Token Cloudflare zweryfikowany."

    for host in "${DNS_HOSTS[@]}"; do
        zid="$(resolve_zone_id "$host")"
        upsert_record "$host" "$zid"
    done
    echo ""
fi

# ═════════════════════════════════════════════════════════════════════════════
#  KROK 2 — Deploy stacku
# ═════════════════════════════════════════════════════════════════════════════
if [[ $STEP_DEPLOY -eq 1 ]]; then
    echo -e "${BOLD}━━ 2/3 Deploy ━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"

    COMPOSE="docker compose \
      -f ${DOCKER_DIR}/docker-compose.yml \
      -f ${DOCKER_DIR}/docker-compose.prod.yml \
      -f ${DOCKER_DIR}/docker-compose.szo-subdomains.yml"
    [[ "$SSL_MODE" == "wildcard" ]] && COMPOSE="${COMPOSE} -f ${DOCKER_DIR}/docker-compose.wildcard-ssl.yml"
    [[ $USE_MYSQL -eq 1 ]]          && COMPOSE="${COMPOSE} -f ${DOCKER_DIR}/docker-compose.mysql.yml"
    COMPOSE="${COMPOSE} --env-file ${ENV_PROD}"

    if [[ "$SSL_MODE" == "wildcard" && -z "${CF_DNS_API_TOKEN:-}" ]]; then
        die "Tryb --wildcard wymaga CF_DNS_API_TOKEN w ${ENV_PROD} (Traefik używa go do DNS-01)."
    fi

    info "Walidacja konfiguracji compose…"
    if [[ $DRY_RUN -eq 1 ]]; then
        info "[dry-run] ${COMPOSE} up -d --remove-orphans"
    else
        $COMPOSE config >/dev/null || die "Błąd konfiguracji compose — przerywam, stack nietknięty."
        ok "Konfiguracja poprawna."

        info "Przeładowanie stacku…"
        $COMPOSE up -d --remove-orphans
        ok "Stack zastosowany."

        echo ""
        info "Traefik czeka na wydanie certyfikatów (DNS-01 ma 30 s opóźnienia propagacji)…"
        sleep 45

        echo ""
        info "Logi Traefika — błędy ACME (ostatnie 40 linii):"
        docker logs feer-traefik --tail=40 2>&1 | grep -iE 'acme|certificate|error' || info "Brak wpisów ACME/error."
    fi
    echo ""
fi

# ═════════════════════════════════════════════════════════════════════════════
#  KROK 3 — Weryfikacja HTTP + SSL
# ═════════════════════════════════════════════════════════════════════════════
if [[ $STEP_VERIFY -eq 1 && $DRY_RUN -ne 1 ]]; then
    echo -e "${BOLD}━━ 3/3 Weryfikacja ━━━━━━━━━━━━━━━━━━━━━━━${RESET}"

    pass=0; fail=0
    printf "%-32s %-6s %-10s %s\n" "HOST" "HTTP" "CERT" "WYSTAWCA / BŁĄD"
    printf '%.0s─' {1..85}; echo

    for m in "${MODULES[@]}"; do
        host="${m}.${BASE_SZO}"

        # HTTP: kod odpowiedzi (2xx/3xx = OK; 401/403 też znaczy że routing działa)
        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
                 "https://${host}/" 2>/dev/null || echo "---")"

        # SSL: wystawca + data wygaśnięcia
        cert_info="$(echo | timeout 15 openssl s_client -servername "$host" \
                     -connect "${host}:443" 2>/dev/null \
                     | openssl x509 -noout -issuer -enddate 2>/dev/null || true)"

        if [[ -n "$cert_info" ]]; then
            issuer="$(sed -n 's/^issuer=.*O *= *\([^,]*\).*/\1/p' <<<"$cert_info" | head -1)"
            [[ -z "$issuer" ]] && issuer="$(sed -n 's/^issuer=//p' <<<"$cert_info" | head -1 | cut -c1-30)"
            enddate="$(sed -n 's/^notAfter=//p' <<<"$cert_info")"
            cert_status="OK"
            detail="${issuer} (do ${enddate})"
        else
            cert_status="BRAK"
            detail="nie udało się pobrać certyfikatu"
        fi

        # Uznajemy za sukces gdy jest cert i kod HTTP nie jest błędem połączenia
        if [[ "$cert_status" == "OK" && "$code" =~ ^[23] ]] || \
           [[ "$cert_status" == "OK" && "$code" =~ ^(401|403)$ ]]; then
            printf "${GREEN}%-32s %-6s %-10s %s${RESET}\n" "$host" "$code" "$cert_status" "$detail"
            pass=$((pass + 1))
        else
            printf "${RED}%-32s %-6s %-10s %s${RESET}\n" "$host" "$code" "$cert_status" "$detail"
            fail=$((fail + 1))
        fi
    done

    echo ""
    if [[ $fail -eq 0 ]]; then
        ok "Wszystkie ${pass} subdomen działają z ważnym certyfikatem."
    else
        warn "Działa: ${pass}  |  Problem: ${fail}"
        echo ""
        info "Diagnostyka:"
        info "  docker logs feer-traefik --tail=100 | grep -i acme"
        info "  dig +short crm.${BASE_SZO}"
        [[ "$SSL_MODE" == "wildcard" ]] && \
            info "  Sprawdź CF_DNS_API_TOKEN — uprawnienie Zone→DNS→Edit dla feer.org.pl"
    fi
    echo ""
fi

ok "Gotowe."
if [[ $DRY_RUN -ne 1 && $STEP_DEPLOY -eq 1 ]]; then
    info "Adresy modułów: https://<moduł>.${BASE_SZO}/"
    info "Lista modułów:  ${MODULES[*]}"
fi
