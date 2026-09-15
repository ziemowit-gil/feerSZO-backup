#!/usr/bin/env bash
# bin/mydevil-dns-delegate.sh — deleguj DNS domen feerSZO (obie strefy:
# feer.org.pl i ngosystem.pl) z Cloudflare na strefy DNS hostowane przez
# MyDevil (devil dns), po jednej subdomenie.
#
# Dla każdej SUBDOMENY (nie apex strefy):
#   1. Tworzy (jeśli brak) strefę DNS na koncie MyDevil: devil dns add DOMENA
#   2. Dodaje w niej rekord A: DOMENA → MYDEVIL_IP
#   3. Weryfikuje odpowiedź BEZPOŚREDNIO z serwerów MyDevil (dig @dns1.mydevil.net)
#      — dopiero gdy to działa, rusza dalej
#   4. W Cloudflare: usuwa istniejący rekord (A/AAAA/CNAME, również PROXIED —
#      np. zadania/ti/crm/ezd.ngosystem.pl mają dziś pomarańczową chmurkę)
#      i dodaje 2 rekordy NS (dns1.mydevil.net, dns2.mydevil.net) — DELEGACJA
#      TEJ KONKRETNEJ SUBDOMENY, reszta strefy (MX, inne subdomeny) nietknięta.
#
# Dla APEKSU strefy (ngosystem.pl samo, bez subdomeny) NIE da się zrobić
# delegacji NS (nie można delegować NS na wierzchołku własnej strefy) — tu
# skrypt tylko podmienia rekord w Cloudflare na zwykłe A → MYDEVIL_IP, bez
# żadnej strefy devil dns.
#
# UWAGA — po delegacji NS Cloudflare już NIE obsługuje ruchu/proxy dla danej
# subdomeny (żadnego WAF/DDoS/ukrycia IP). Dotyczy dziś: zadania/ti.feer.org.pl
# oraz www/crm/ti/ezd.ngosystem.pl (aktywny proxy). Można wrócić (--revert),
# ale to odtwarza tylko zwykły rekord A (proxy trzeba włączyć ręcznie).
#
# WAŻNE — devil www: to NIE zakłada wirtualnych hostów na MyDevil dla
# domen ngosystem.pl (to osobny krok — patrz bin/deploy-mydevil.sh /
# ALIAS_DOMAINS). Zrób to PRZED delegacją NS, inaczej ruch dojdzie do
# MyDevil, ale trafi w nieskonfigurowaną domenę.
#
# Wymaga: curl, jq, dig, oraz tokenu API Cloudflare z uprawnieniem
# Zone → DNS → Edit na OBIE strefy: feer.org.pl i ngosystem.pl (NIE tego
# z docker/.env.cloudflare, który jest udokumentowany jako uprawniony
# tylko do ngosystem.pl — tu potrzeba szerszego).
#
# Użycie:
#   bash bin/mydevil-dns-delegate.sh --dry-run                 # podgląd, bez zmian
#   bash bin/mydevil-dns-delegate.sh                             # pełny bieg, z potwierdzeniem na każdą domenę
#   bash bin/mydevil-dns-delegate.sh --yes                       # bez potwierdzeń
#   bash bin/mydevil-dns-delegate.sh --only=zadania.feer.org.pl,ti.ngosystem.pl
#   bash bin/mydevil-dns-delegate.sh --revert --only=crm.feer.org.pl
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_CONF="${SCRIPT_DIR}/.deploy-mydevil.conf"
CF_CONF="${SCRIPT_DIR}/.dns-delegate-mydevil.conf"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

command -v curl >/dev/null || die "Brak 'curl'."
command -v jq   >/dev/null || die "Brak 'jq'. Zainstaluj: brew install jq"
command -v dig  >/dev/null || die "Brak 'dig' (dnsutils/bind-tools)."

[[ -f "$DEPLOY_CONF" ]] || die "Brak ${DEPLOY_CONF} — uruchom najpierw bin/deploy-mydevil-wizard.sh"
# shellcheck source=/dev/null
source "$DEPLOY_CONF"
[[ -n "${MYDEVIL_SSH:-}" ]]    || die "MYDEVIL_SSH nie ustawione w ${DEPLOY_CONF}"
[[ -n "${PRIMARY_DOMAIN:-}" ]] || die "PRIMARY_DOMAIN nie ustawione w ${DEPLOY_CONF}"
[[ -n "${MYDEVIL_IP:-}" ]]     || die "MYDEVIL_IP nie ustawione w ${DEPLOY_CONF}"

NS1="dns1.mydevil.net"
NS2="dns2.mydevil.net"

# ── Domeny per strefa ─────────────────────────────────────────────────────────
FEER_ZONE="feer.org.pl"
FEER_DOMAINS=("$PRIMARY_DOMAIN" "${ALIAS_DOMAINS[@]:-}")

NGO_ZONE="ngosystem.pl"
NGO_DOMAINS=(ngosystem.pl www.ngosystem.pl szo.ngosystem.pl crm.ngosystem.pl ti.ngosystem.pl ezd.ngosystem.pl zadania.ngosystem.pl)

DRY=false; ASSUME_YES=false; REVERT=false; ONLY=""
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY=true ;;
        --yes)     ASSUME_YES=true ;;
        --revert)  REVERT=true ;;
        --only=*)  ONLY="${arg#--only=}" ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Nieznana opcja: $arg" ;;
    esac
done

# ── Token Cloudflare (jeden, uprawniony na obie strefy) ──────────────────────
if [[ ! -f "$CF_CONF" ]]; then
    warn "Brak ${CF_CONF} (token Cloudflare dla stref ${FEER_ZONE} i ${NGO_ZONE})."
    [[ -t 0 ]] || die "Uruchom interaktywnie, żeby podać token, albo utwórz ${CF_CONF} ręcznie (CF_API_TOKEN=...)."
    read -rsp "$(echo -e "${CYAN}▸ Token API Cloudflare (Zone→DNS→Edit na ${FEER_ZONE} i ${NGO_ZONE})${RESET}: ")" _tok
    echo
    [[ -n "$_tok" ]] || die "Token jest wymagany."
    ( umask 077; printf 'CF_API_TOKEN=%q\n' "$_tok" > "$CF_CONF" )
    ok "Zapisano ${CF_CONF} (chmod 600, zawiera sekret — jest w .gitignore)."
fi
# shellcheck source=/dev/null
source "$CF_CONF"
[[ -n "${CF_API_TOKEN:-}" ]] || die "CF_API_TOKEN puste w ${CF_CONF}"

GITIGNORE="$(dirname "$SCRIPT_DIR")/.gitignore"
if [[ -f "$GITIGNORE" ]] && ! grep -qxF "bin/.dns-delegate-mydevil.conf" "$GITIGNORE"; then
    echo "bin/.dns-delegate-mydevil.conf" >> "$GITIGNORE"
fi

# ── Filtr --only (działa na obu strefach naraz) ─────────────────────────────
filter_only() {
    # drukuje na stdout tylko te domeny z podanej listy, które są w $ONLY
    local d
    for d in "$@"; do
        [[ -z "$d" ]] && continue
        if [[ -z "$ONLY" ]]; then
            echo "$d"
        else
            case ",${ONLY}," in *",${d},"*) echo "$d" ;; esac
        fi
    done
}

# ── SSH (hasło, sesja ControlMaster reużywana z deploy-mydevil.sh) ──────────
SSH_CTL_DIR="${HOME}/.ssh/feerszo-cm"
mkdir -p "$SSH_CTL_DIR" 2>/dev/null || true
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CTL_DIR}/%r@%h:%p" -o "ControlPersist=600")
run_remote() {
    local cmd="$1"
    if $DRY; then
        warn "[dry] ssh ${MYDEVIL_SSH} \"${cmd}\""
        echo ""
    else
        ssh "${SSH_OPTS[@]}" "$MYDEVIL_SSH" "$cmd"
    fi
}

# ── Cloudflare API ────────────────────────────────────────────────────────────
CF_API="https://api.cloudflare.com/client/v4"
cf() {
    local method="$1" path="$2" body="${3:-}"
    local args=(-sS --max-time 30 -X "$method" "${CF_API}${path}"
        -H "Authorization: Bearer ${CF_API_TOKEN}" -H "Content-Type: application/json")
    [[ -n "$body" ]] && args+=(--data "$body")
    curl "${args[@]}"
}
cf_check() {
    local resp="$1" ctx="$2"
    if [[ "$(jq -r '.success' <<<"$resp" 2>/dev/null)" != "true" ]]; then
        local msg; msg="$(jq -r '[.errors[]? | "\(.code): \(.message)"] | join("; ")' <<<"$resp" 2>/dev/null)"
        die "Cloudflare API (${ctx}): ${msg:-nieznany błąd (${resp})}"
    fi
}

# Rozwiązuje ID strefy Cloudflare po nazwie — bez tablic asocjacyjnych
# (kompatybilność z bash 3.2 na macOS).
resolve_cf_zone() {
    local zone="$1"
    local r; r="$(cf GET "/zones?name=${zone}&status=active")"
    cf_check "$r" "zones?name=${zone}"
    local id; id="$(jq -r '.result[0].id // empty' <<<"$r")"
    [[ -n "$id" ]] || die "Nie znaleziono strefy '${zone}' na koncie tego tokenu — sprawdź uprawnienia tokenu."
    echo "$id"
}

# Usuwa WSZYSTKIE istniejące rekordy (dowolnego typu: A/AAAA/CNAME) o tej
# nazwie w podanej strefie — obejmuje też rekordy PROXIED (proxied to tylko
# flaga na rekordzie A, nie osobny typ).
cf_delete_existing() {
    local zone_id="$1" host="$2"
    local existing; existing="$(cf GET "/zones/${zone_id}/dns_records?name=${host}")"
    cf_check "$existing" "list ${host}"
    local id
    while IFS= read -r id; do
        [[ -z "$id" ]] && continue
        if $DRY; then
            warn "[dry] DELETE rekord ${id} (${host}) w Cloudflare"
            continue
        fi
        local resp; resp="$(cf DELETE "/zones/${zone_id}/dns_records/${id}")"
        cf_check "$resp" "usunięcie ${host} (${id})"
        ok "Usunięto istniejący rekord ${host} w Cloudflare (id ${id})"
    done < <(jq -r '.result[].id' <<<"$existing")
}

cf_add_ns() {
    local zone_id="$1" host="$2" ns="$3"
    local existing; existing="$(cf GET "/zones/${zone_id}/dns_records?type=NS&name=${host}&content=${ns}")"
    cf_check "$existing" "list NS ${host} ${ns}"
    if [[ "$(jq -r '.result[0].id // empty' <<<"$existing")" != "" ]]; then
        info "Rekord NS ${host} → ${ns} już istnieje — pomijam."
        return
    fi
    local payload; payload="$(jq -nc --arg name "$host" --arg content "$ns" '{type:"NS", name:$name, content:$content, ttl:3600}')"
    if $DRY; then
        warn "[dry] POST NS ${host} → ${ns}"
        return
    fi
    local resp; resp="$(cf POST "/zones/${zone_id}/dns_records" "$payload")"
    cf_check "$resp" "dodanie NS ${host} → ${ns}"
    ok "Dodano NS: ${host} → ${ns}"
}

cf_add_a() {
    local zone_id="$1" host="$2" ip="$3"
    local payload; payload="$(jq -nc --arg name "$host" --arg content "$ip" '{type:"A", name:$name, content:$content, ttl:300, proxied:false}')"
    if $DRY; then
        warn "[dry] POST A ${host} → ${ip} (dns-only)"
        return
    fi
    local resp; resp="$(cf POST "/zones/${zone_id}/dns_records" "$payload")"
    cf_check "$resp" "dodanie A ${host} → ${ip}"
    ok "Dodano A: ${host} → ${ip} (dns-only)"
}

confirm_domain() {
    local host="$1"
    $ASSUME_YES && return 0
    $DRY && return 0
    read -r -p "$(echo -e "${BOLD}Przełączyć ${host} na DNS MyDevil teraz? [t/N]${RESET}: ")" reply
    [[ "$reply" =~ ^[tT]$ ]]
}

# ── Przetwarzanie jednej strefy ───────────────────────────────────────────────
process_zone() {
    local zone="$1"; shift
    local domains=("$@")
    local zone_id; zone_id="$(resolve_cf_zone "$zone")"

    local host
    for host in "${domains[@]}"; do
        [[ -z "$host" ]] && continue

        if [[ "$host" == "$zone" ]]; then
            section "Apeks strefy: ${host} (zwykły rekord A, bez NS)"
            confirm_domain "$host" || { info "Pominięto ${host}."; continue; }
            cf_delete_existing "$zone_id" "$host"
            cf_add_a "$zone_id" "$host" "$MYDEVIL_IP"
            continue
        fi

        section "Domena: ${host}"

        info "Strefa DNS na MyDevil…"
        _list="$(run_remote "devil dns list" || true)"
        if ! $DRY && grep -qF "$host" <<<"$_list"; then
            info "Strefa ${host} już istnieje na MyDevil — pomijam devil dns add."
        else
            run_remote "devil dns add ${host}" || warn "devil dns add ${host} — sprawdź ręcznie (może już istnieć)."
        fi

        info "Rekord A w strefie (MyDevil)…"
        _rec_list="$(run_remote "devil dns list ${host}" || true)"
        if ! $DRY && grep -qF "$MYDEVIL_IP" <<<"$_rec_list"; then
            info "Rekord A ${host} → ${MYDEVIL_IP} już jest — pomijam."
        else
            run_remote "devil dns add ${host} ${host} A ${MYDEVIL_IP}" \
                || warn "devil dns add rekordu A dla ${host} — sprawdź ręcznie: ssh ${MYDEVIL_SSH} devil dns list ${host}"
        fi

        if ! $DRY; then
            info "Weryfikuję bezpośrednio z ${NS1}…"
            _answer="$(dig +short "@${NS1}" "$host" A || true)"
            if [[ "$_answer" != "$MYDEVIL_IP" ]]; then
                warn "dig @${NS1} ${host} zwrócił '${_answer:-brak odpowiedzi}', oczekiwano ${MYDEVIL_IP}."
                warn "Zanim przełączysz NS w Cloudflare, popraw to ręcznie: ssh ${MYDEVIL_SSH} devil dns list ${host}"
                confirm_domain "mimo to kontynuować z ${host}" || { info "Pominięto ${host}."; continue; }
            else
                ok "MyDevil odpowiada poprawnie dla ${host}."
            fi
        fi

        confirm_domain "$host" || { info "Pominięto delegację NS dla ${host} w Cloudflare."; continue; }

        info "Cloudflare: usuwam istniejący rekord i deleguję NS…"
        cf_delete_existing "$zone_id" "$host"
        cf_add_ns "$zone_id" "$host" "$NS1"
        cf_add_ns "$zone_id" "$host" "$NS2"
    done
}

process_zone_revert() {
    local zone="$1"; shift
    local domains=("$@")
    local zone_id; zone_id="$(resolve_cf_zone "$zone")"
    local host
    for host in "${domains[@]}"; do
        [[ -z "$host" ]] && continue
        section "Cofam delegację: ${host}"
        confirm_domain "$host" || { info "Pominięto ${host}."; continue; }
        cf_delete_existing "$zone_id" "$host"
        cf_add_a "$zone_id" "$host" "$MYDEVIL_IP"
    done
}

# ── Zastosuj filtr --only ────────────────────────────────────────────────────
FEER_SELECTED=()
while IFS= read -r d; do FEER_SELECTED+=("$d"); done < <(filter_only "${FEER_DOMAINS[@]}")
NGO_SELECTED=()
while IFS= read -r d; do NGO_SELECTED+=("$d"); done < <(filter_only "${NGO_DOMAINS[@]}")

if $REVERT; then
    [[ ${#FEER_SELECTED[@]} -gt 0 ]] && process_zone_revert "$FEER_ZONE" "${FEER_SELECTED[@]}"
    [[ ${#NGO_SELECTED[@]} -gt 0 ]]  && process_zone_revert "$NGO_ZONE" "${NGO_SELECTED[@]}"
    ok "Revert zakończony. Pamiętaj: proxy Cloudflare (jeśli było) trzeba włączyć ręcznie."
    exit 0
fi

[[ ${#FEER_SELECTED[@]} -gt 0 ]] && process_zone "$FEER_ZONE" "${FEER_SELECTED[@]}"
[[ ${#NGO_SELECTED[@]} -gt 0 ]]  && process_zone "$NGO_ZONE" "${NGO_SELECTED[@]}"

echo ""
ok "Gotowe. Propagacja NS może potrwać do kilku godzin (TTL poprzednich rekordów)."
info "Weryfikacja: dig NS ${PRIMARY_DOMAIN} +trace   albo   dig @1.1.1.1 crm.feer.org.pl"
warn "Cofnięcie pojedynczej domeny: bash $0 --revert --only=DOMENA (odtwarza zwykły rekord A, BEZ przywracania proxy)."
warn "Pamiętaj o devil www (vhosty) dla domen ngosystem.pl PRZED delegacją NS — patrz bin/deploy-mydevil.sh."
