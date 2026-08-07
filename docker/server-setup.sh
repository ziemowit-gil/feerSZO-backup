#!/usr/bin/env bash
# server-setup.sh — jednorazowe przygotowanie serwera Ubuntu do pracy z feerSZO
#
# Naprawia typowe problemy po reboot/upgrade:
#   - Apache2 zajmuje port 80
#   - systemd-resolved nie działa (DNS w kontenerach broken)
#   - UFW blokuje 80/443
#   - Docker nie startuje po resolved
#
# Użycie:
#   sudo bash docker/server-setup.sh           # pełne ustawienie
#   sudo bash docker/server-setup.sh --check   # tylko diagnostyka bez zmian
#   bash docker/server-setup.sh --ssh user@host [opcje]

set -euo pipefail

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

# ── SSH pass-through ──────────────────────────────────────────────────────────
if [[ "${1:-}" == "--ssh" ]]; then
    SSH_TARGET="${2:?--ssh wymaga user@host}"
    shift 2
    REMOTE_REPO="${REMOTE_REPO:-/opt/feer-szo}"
    info "Łączę z ${SSH_TARGET}…"
    ssh -t "$SSH_TARGET" "sudo bash ${REMOTE_REPO}/docker/server-setup.sh $*"
    exit $?
fi

CHECK_ONLY=0
[[ "${1:-}" == "--check" ]] && CHECK_ONLY=1

[[ $EUID -ne 0 ]] && die "Wymagane uprawnienia root (sudo bash $0)"

# ══════════════════════════════════════════════════════════════════════════════
section "1. Apache2 — wyłącz trwale (zwalnia port 80)"

if systemctl list-unit-files apache2.service &>/dev/null 2>&1; then
    status=$(systemctl is-enabled apache2 2>/dev/null || echo "disabled")
    active=$(systemctl is-active  apache2 2>/dev/null || echo "inactive")

    if [[ "$active" == "active" ]]; then
        warn "Apache2 działa na porcie 80"
        if [[ $CHECK_ONLY -eq 0 ]]; then
            systemctl stop    apache2
            ok "Apache2 zatrzymany"
        fi
    else
        ok "Apache2 nie działa"
    fi

    if [[ "$status" != "disabled" ]]; then
        warn "Apache2 będzie startować po reboot (enabled)"
        if [[ $CHECK_ONLY -eq 0 ]]; then
            systemctl disable apache2
            ok "Apache2 wyłączony na stałe (disabled)"
        fi
    else
        ok "Apache2 disabled — nie wróci po reboot"
    fi
else
    ok "Apache2 nie zainstalowany"
fi

# ══════════════════════════════════════════════════════════════════════════════
section "2. systemd-resolved — włącz i ustaw jako domyślny resolver"

resolved_enabled=$(systemctl is-enabled systemd-resolved 2>/dev/null || echo "disabled")
resolved_active=$(systemctl is-active  systemd-resolved 2>/dev/null || echo "inactive")

if [[ "$resolved_active" != "active" ]]; then
    warn "systemd-resolved nie działa"
    if [[ $CHECK_ONLY -eq 0 ]]; then
        systemctl enable --now systemd-resolved
        sleep 1
        ok "systemd-resolved uruchomiony i enabled"
    fi
else
    ok "systemd-resolved działa"
fi

if [[ "$resolved_enabled" != "enabled" ]]; then
    warn "systemd-resolved nie jest enabled (nie wróci po reboot)"
    if [[ $CHECK_ONLY -eq 0 ]]; then
        systemctl enable systemd-resolved
        ok "systemd-resolved ustawiony jako enabled"
    fi
else
    ok "systemd-resolved enabled — przeżyje reboot"
fi

# Upewnij się że /etc/resolv.conf to symlink do stub resolvera
RESOLV=/etc/resolv.conf
if [[ $CHECK_ONLY -eq 0 ]]; then
    if [[ ! -L "$RESOLV" ]] || [[ "$(readlink "$RESOLV")" != "../run/systemd/resolve/stub-resolv.conf" ]]; then
        warn "Naprawiam /etc/resolv.conf (brak symlinka do stub resolvera)…"
        rm -f "$RESOLV"
        ln -s /run/systemd/resolve/stub-resolv.conf "$RESOLV"
        ok "/etc/resolv.conf → stub-resolv.conf"
    else
        ok "/etc/resolv.conf prawidłowo symlinked"
    fi
fi

info "Bieżący DNS: $(resolvectl status 2>/dev/null | grep 'DNS Servers' | head -1 || echo '?')"

# ══════════════════════════════════════════════════════════════════════════════
section "3. Docker — włącz i ustaw kolejność startu (po resolved)"

docker_enabled=$(systemctl is-enabled docker 2>/dev/null || echo "disabled")
docker_active=$(systemctl is-active  docker 2>/dev/null || echo "inactive")

if [[ "$docker_enabled" != "enabled" ]]; then
    warn "Docker nie jest enabled"
    if [[ $CHECK_ONLY -eq 0 ]]; then
        systemctl enable docker
        ok "Docker enabled"
    fi
else
    ok "Docker enabled"
fi

if [[ "$docker_active" != "active" ]]; then
    warn "Docker nie działa"
    if [[ $CHECK_ONLY -eq 0 ]]; then
        systemctl start docker
        ok "Docker uruchomiony"
    fi
else
    ok "Docker działa"
fi

# Drop-in: Docker startuje dopiero gdy resolved jest gotowy
DOCKER_DROPIN_DIR=/etc/systemd/system/docker.service.d
DOCKER_DROPIN="${DOCKER_DROPIN_DIR}/after-resolved.conf"
if [[ $CHECK_ONLY -eq 0 ]]; then
    if [[ ! -f "$DOCKER_DROPIN" ]]; then
        info "Tworzę drop-in: Docker czeka na systemd-resolved przy starcie…"
        mkdir -p "$DOCKER_DROPIN_DIR"
        cat > "$DOCKER_DROPIN" <<'EOF'
[Unit]
After=systemd-resolved.service
Wants=systemd-resolved.service
EOF
        systemctl daemon-reload
        ok "Drop-in zapisany: ${DOCKER_DROPIN}"
    else
        ok "Drop-in już istnieje: ${DOCKER_DROPIN}"
    fi
fi

# ══════════════════════════════════════════════════════════════════════════════
section "4. UFW — otwórz porty HTTP/HTTPS"

if ! command -v ufw &>/dev/null; then
    warn "UFW nie zainstalowany — pomijam"
else
    UFW_STATUS=$(ufw status | head -1)

    if echo "$UFW_STATUS" | grep -qi "inactive"; then
        warn "UFW wyłączony — włączam i dodaję reguły…"
        if [[ $CHECK_ONLY -eq 0 ]]; then
            ufw allow 22/tcp   comment 'SSH'
            ufw allow 80/tcp   comment 'HTTP'
            ufw allow 443/tcp  comment 'HTTPS'
            ufw allow 8443/tcp comment 'EJBCA AdminWeb'
            echo "y" | ufw enable
            ok "UFW włączony z regułami 22/80/443/8443"
        fi
    else
        for port in 22 80 443 8443; do
            if ufw status | grep -q "^${port}"; then
                ok "UFW port ${port}: otwarty"
            else
                warn "UFW port ${port}: zamknięty"
                if [[ $CHECK_ONLY -eq 0 ]]; then
                    ufw allow "${port}/tcp"
                    ok "UFW port ${port}: otwarto"
                fi
            fi
        done
        if [[ $CHECK_ONLY -eq 0 ]]; then
            ufw reload
        fi
    fi
fi

# ══════════════════════════════════════════════════════════════════════════════
section "5. Port 80 — sprawdź czy wolny"

if ss -tlnp | grep -q ':80 '; then
    proc=$(ss -tlnp | grep ':80 ' | grep -oP 'users:\(\("\K[^"]+' || echo '?')
    if [[ "$proc" == "docker-proxy" ]] || echo "$proc" | grep -q "docker"; then
        ok "Port 80: Docker (traefik) — OK"
    else
        warn "Port 80 zajęty przez: ${proc}"
        info "Zatrzymaj ten proces przed uruchomieniem Dockera"
    fi
else
    ok "Port 80: wolny"
fi

# ══════════════════════════════════════════════════════════════════════════════
section "Podsumowanie"

echo ""
echo -e "${BOLD}Usługi:${RESET}"
for svc in apache2 systemd-resolved docker; do
    enabled=$(systemctl is-enabled "$svc" 2>/dev/null || echo "—")
    active=$(systemctl is-active "$svc" 2>/dev/null || echo "—")
    printf "  %-25s enabled=%-10s active=%s\n" "$svc" "$enabled" "$active"
done

echo ""
echo -e "${BOLD}DNS:${RESET}"
resolvectl status 2>/dev/null | grep -E 'DNS Servers|Fallback' | head -3 || cat /etc/resolv.conf | head -4

echo ""
echo -e "${BOLD}Porty 80/443:${RESET}"
ss -tlnp | grep -E ':80 |:443 ' || echo "  (brak — Traefik nie uruchomiony)"

echo ""
if [[ $CHECK_ONLY -eq 1 ]]; then
    info "Tryb --check: żadnych zmian nie wprowadzono"
    info "Uruchom bez --check żeby zastosować poprawki"
else
    ok "Serwer gotowy. Uruchom stack: bash docker/apply-prod.sh"
    info "Jeśli certyfikat SSL wymaga odbudowy: bash docker/cert-rebuild.sh"
fi
