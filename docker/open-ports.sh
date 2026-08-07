#!/usr/bin/env bash
# open-ports.sh — otwórz porty HTTP/HTTPS w UFW (Ubuntu firewall)
#
# Użycie:
#   bash docker/open-ports.sh           # dodaj reguły + reload UFW
#   bash docker/open-ports.sh --status  # tylko pokaż stan
#   bash docker/open-ports.sh --ssh user@serwer  # przez SSH

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info() { echo -e "${CYAN}▸ $*${RESET}"; }
ok()   { echo -e "${GREEN}✔ $*${RESET}"; }
warn() { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()  { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }

if [[ "${1:-}" == "--ssh" ]]; then
    SSH_TARGET="${2:?--ssh wymaga user@host}"
    shift 2
    REMOTE_REPO="${REMOTE_REPO:-/opt/feer-szo}"
    info "Łączę z ${SSH_TARGET}…"
    ssh -t "$SSH_TARGET" "bash ${REMOTE_REPO}/docker/open-ports.sh $*"
    exit $?
fi

if [[ "${1:-}" == "--status" ]]; then
    echo -e "${BOLD}── UFW ──────────────────────────────────────────${RESET}"
    ufw status verbose 2>/dev/null || warn "ufw nie zainstalowany"
    echo ""
    echo -e "${BOLD}── Porty nasłuchujące ───────────────────────────${RESET}"
    ss -tlnp | grep -E ':80|:443|:8080|:8443' || echo "(brak)"
    exit 0
fi

# ── Sprawdź czy UFW jest dostępny ─────────────────────────────────────────
command -v ufw >/dev/null || die "ufw nie zainstalowany: apt install ufw"

# ── Stan przed ────────────────────────────────────────────────────────────
echo -e "${BOLD}━━ Otwieranie portów Web ━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
info "Obecny stan UFW:"
ufw status | head -5 || true
echo ""

# ── Dodaj reguły ──────────────────────────────────────────────────────────
PORTS=(22 80 443 8443)
for port in "${PORTS[@]}"; do
    result=$(ufw allow "${port}/tcp" 2>&1) || true
    if echo "$result" | grep -q "already exist\|Rules updated\|Skipping"; then
        ok "Port ${port}/tcp: OK"
    else
        ok "Port ${port}/tcp: dodano"
    fi
done

# Jeśli UFW jest wyłączony — włącz (wymaga potwierdzenia)
UFW_STATUS=$(ufw status | head -1)
if echo "$UFW_STATUS" | grep -qi "inactive"; then
    warn "UFW jest wyłączony — włączam…"
    echo "y" | ufw enable
    ok "UFW włączony"
else
    info "Przeładowanie reguł UFW…"
    ufw reload
    ok "UFW przeładowany"
fi

# ── Stan po ───────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}── Stan po zmianach ─────────────────────────────${RESET}"
ufw status verbose

echo ""
echo -e "${BOLD}── Porty nasłuchujące ───────────────────────────${RESET}"
ss -tlnp | grep -E ':80|:443|:8080|:8443' || echo "(brak — Traefik może jeszcze startować)"

echo ""
ok "Gotowe. Przetestuj: curl -I https://szo.feer.org.pl/"
