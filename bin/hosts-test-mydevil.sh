#!/usr/bin/env bash
# bin/hosts-test-mydevil.sh — tymczasowy test wdrożenia na MyDevil przez
# /etc/hosts, BEZ dotykania prawdziwego DNS.
#
# Dopisuje (albo usuwa) wpisy w lokalnym /etc/hosts, kierujące domeny
# feerSZO na IP konta MyDevil — dzięki temu przeglądarka na tej maszynie
# łączy się z MyDevil, a reszta świata dalej widzi stary VPS. Domeny i IP
# czyta z bin/.deploy-mydevil.conf (ten sam plik, którego używa
# deploy-mydevil.sh), więc nie trzeba niczego wpisywać ręcznie.
#
# Wymaga uprawnień do zapisu /etc/hosts — uruchom przez sudo.
#
# Użycie:
#   sudo bash bin/hosts-test-mydevil.sh              # dodaj wpisy testowe
#   sudo bash bin/hosts-test-mydevil.sh --remove      # usuń wpisy testowe
#   bash bin/hosts-test-mydevil.sh --dry-run          # podgląd (bez sudo)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${SCRIPT_DIR}/.deploy-mydevil.conf"
HOSTS_FILE="/etc/hosts"
MARKER_START="# >>> feerszo-test-mydevil (tymczasowe — usuń: bin/hosts-test-mydevil.sh --remove) >>>"
MARKER_END="# <<< feerszo-test-mydevil <<<"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

[[ -f "$CONFIG_FILE" ]] || die "Brak ${CONFIG_FILE} — najpierw uruchom bin/deploy-mydevil-wizard.sh (albo wypełnij ręcznie)."
# shellcheck source=/dev/null
source "$CONFIG_FILE"
[[ -n "${PRIMARY_DOMAIN:-}" ]] || die "PRIMARY_DOMAIN nie ustawione w ${CONFIG_FILE}"
[[ -n "${MYDEVIL_IP:-}" ]]     || die "MYDEVIL_IP nie ustawione w ${CONFIG_FILE} (potrzebne też do testu, nie tylko SSL)"

DRY=false; REMOVE=false
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY=true ;;
        --remove)  REMOVE=true ;;
        --help|-h) grep '^#' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Nieznana opcja: $arg" ;;
    esac
done

if ! $DRY && [[ "$(id -u)" -ne 0 ]]; then
    die "Modyfikacja ${HOSTS_FILE} wymaga uprawnień — uruchom przez: sudo bash $0 $*"
fi

ALL_DOMAINS=("$PRIMARY_DOMAIN" "${ALIAS_DOMAINS[@]:-}")

strip_existing() {
    # Usuwa poprzedni blok (jeśli istnieje), zwraca wynik na stdout.
    awk -v s="$MARKER_START" -v e="$MARKER_END" '
        $0 == s { skip=1; next }
        $0 == e { skip=0; next }
        !skip { print }
    ' "$HOSTS_FILE"
}

if $REMOVE; then
    info "Usuwam wpisy testowe z ${HOSTS_FILE}…"
    if $DRY; then
        warn "[dry] usunąłbym blok między markerami feerszo-test-mydevil"
    else
        TMP="$(mktemp)"
        strip_existing > "$TMP"
        cp "$HOSTS_FILE" "${HOSTS_FILE}.bak.$(date +%s)"
        cat "$TMP" > "$HOSTS_FILE"
        rm -f "$TMP"
        dscacheutil -flushcache 2>/dev/null || true
        killall -HUP mDNSResponder 2>/dev/null || true
        ok "Usunięto (kopia zapasowa: ${HOSTS_FILE}.bak.*)"
    fi
    exit 0
fi

info "Domeny → ${MYDEVIL_IP}: ${ALL_DOMAINS[*]}"
BLOCK="$MARKER_START"$'\n'
for d in "${ALL_DOMAINS[@]}"; do
    [[ -n "$d" ]] && BLOCK+="${MYDEVIL_IP} ${d}"$'\n'
done
BLOCK+="$MARKER_END"

if $DRY; then
    warn "[dry] dopisałbym do ${HOSTS_FILE} (po usunięciu ewentualnego starego bloku):"
    echo "$BLOCK"
    exit 0
fi

TMP="$(mktemp)"
strip_existing > "$TMP"
printf '\n%s\n' "$BLOCK" >> "$TMP"
cp "$HOSTS_FILE" "${HOSTS_FILE}.bak.$(date +%s)"
cat "$TMP" > "$HOSTS_FILE"
rm -f "$TMP"

dscacheutil -flushcache 2>/dev/null || true
killall -HUP mDNSResponder 2>/dev/null || true

ok "Dopisano (kopia zapasowa: ${HOSTS_FILE}.bak.*)"
info "Otwórz https://${PRIMARY_DOMAIN} w przeglądarce — powinieneś trafić na MyDevil."
warn "Certyfikat SSL prawdopodobnie jeszcze nie jest wydany dla realnego DNS — przeglądarka może ostrzec o certyfikacie; to oczekiwane na tym etapie."
info "Po testach: sudo bash $0 --remove"
