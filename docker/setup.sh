#!/usr/bin/env bash
# FEER SZO — skrypt wdrożeniowy (Ubuntu 22.04/24.04)
# Użycie: bash setup.sh [--mysql]
set -euo pipefail

# ── Kolory ────────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

info()    { echo -e "${CYAN}▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}⚠ $*${RESET}"; }
die()     { echo -e "${RED}✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

# ── Argumenty ─────────────────────────────────────────────────────────────────
USE_MYSQL=0
for arg in "$@"; do [[ "$arg" == "--mysql" ]] && USE_MYSQL=1; done

# ── Stałe ─────────────────────────────────────────────────────────────────────
REPO_URL="https://codeberg.org/ziemowitgil/feerSZO"
INSTALL_DIR="/opt/feer-szo"
DOCKER_DIR="${INSTALL_DIR}/docker"
ENV_FILE="${DOCKER_DIR}/.env.prod"
DEFAULT_DOMAIN="szo.feer.org.pl"
DEFAULT_ACME_EMAIL="admin@feer.org.pl"
DEFAULT_ORG="Fundacja Edukacji Empatii Rozwoju FEER"

# ── Root check ────────────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && die "Uruchom jako root: sudo bash setup.sh"

# ── 1. Aktualizacja systemu ───────────────────────────────────────────────────
section "1. Aktualizacja systemu"
apt-get update -qq
apt-get install -y -qq \
    ca-certificates curl gnupg lsb-release git ufw dnsutils openssl < /dev/null
ok "Zależności systemowe zainstalowane"

# ── 2. Docker ────────────────────────────────────────────────────────────────
section "2. Docker Engine"
if command -v docker &>/dev/null; then
    ok "Docker już zainstalowany: $(docker --version)"
else
    info "Instaluję Docker Engine..."
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
        https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-compose-plugin
    systemctl enable --now docker
    ok "Docker zainstalowany: $(docker --version)"
fi

if ! docker compose version &>/dev/null; then
    die "docker compose (v2) niedostępny — zainstaluj docker-compose-plugin"
fi
ok "Docker Compose: $(docker compose version --short)"

# ── 3. Firewall (UFW) ─────────────────────────────────────────────────────────
section "3. Firewall UFW"
ufw --force reset > /dev/null
ufw default deny incoming  > /dev/null
ufw default allow outgoing > /dev/null
ufw allow 22/tcp   comment "SSH"   > /dev/null
ufw allow 80/tcp   comment "HTTP"  > /dev/null
ufw allow 443/tcp  comment "HTTPS" > /dev/null
ufw --force enable > /dev/null
ok "UFW aktywny — porty 22, 80, 443 otwarte"

# ── 4. Repozytorium ───────────────────────────────────────────────────────────
section "4. Repozytorium"
if [[ -d "${INSTALL_DIR}/.git" ]]; then
    info "Aktualizuję istniejące repo..."
    git -C "${INSTALL_DIR}" pull --ff-only
    ok "Zaktualizowano do HEAD"
else
    info "Klonuję ${REPO_URL} → ${INSTALL_DIR}"
    git clone "${REPO_URL}" "${INSTALL_DIR}"
    ok "Sklonowano"
fi
chmod 755 "${INSTALL_DIR}"

# ── 5. Konfiguracja .env.prod ────────────────────────────────────────────────
section "5. Konfiguracja .env.prod"

if [[ -f "${ENV_FILE}" ]]; then
    warn ".env.prod już istnieje — pomijam tworzenie (usuń plik ręcznie, by skonfigurować od nowa)"
else
    # Generuj APP_KEY
    APP_KEY=$(openssl rand -hex 32)

    echo
    echo -e "${BOLD}Podaj ustawienia (Enter = wartość domyślna):${RESET}"
    echo

    read -rp "Domena [${DEFAULT_DOMAIN}]: " DOMAIN
    DOMAIN="${DOMAIN:-$DEFAULT_DOMAIN}"

    read -rp "E-mail Let's Encrypt [${DEFAULT_ACME_EMAIL}]: " ACME_EMAIL
    ACME_EMAIL="${ACME_EMAIL:-$DEFAULT_ACME_EMAIL}"

    read -rp "Nazwa organizacji [${DEFAULT_ORG}]: " ORG_NAME
    ORG_NAME="${ORG_NAME:-$DEFAULT_ORG}"

    if [[ $USE_MYSQL -eq 1 ]]; then
        echo
        warn "Tryb MySQL — podaj hasła bazy danych:"
        read -rsp "  Hasło DB_PASS (użytkownik feer): " DB_PASS; echo
        read -rsp "  Hasło MYSQL_ROOT_PASSWORD:       " MYSQL_ROOT_PASS; echo
    fi

    cat > "${ENV_FILE}" <<EOF
# FEER SZO — produkcja — wygenerowano $(date '+%Y-%m-%d %H:%M')
DOMAIN=${DOMAIN}
ACME_EMAIL=${ACME_EMAIL}
APP_ENV=production
APP_URL=https://${DOMAIN}
APP_KEY=${APP_KEY}
ORG_NAME=${ORG_NAME}

# Baza danych
EOF

    if [[ $USE_MYSQL -eq 1 ]]; then
        cat >> "${ENV_FILE}" <<EOF
DB_TYPE=mysql
DB_HOST=mysql
DB_PORT=3306
DB_NAME=feer
DB_USER=feer
DB_PASS=${DB_PASS}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASS}
EOF
    else
        echo "DB_TYPE=sqlite" >> "${ENV_FILE}"
    fi

    cat >> "${ENV_FILE}" <<EOF

# Microsoft 365 (skonfiguruj przez /admin/m365_settings.php po wdrożeniu)
MS_ENABLED=0
MS_TENANT_ID=
MS_CLIENT_ID=
MS_CLIENT_SECRET=
MS_REDIRECT_URI=https://${DOMAIN}/auth/microsoft.php
EOF

    chmod 600 "${ENV_FILE}"
    ok ".env.prod zapisany (APP_KEY wygenerowany)"
fi

# Wczytaj zmienne do weryfikacji DNS
source "${ENV_FILE}" 2>/dev/null || true
DOMAIN="${DOMAIN:-$DEFAULT_DOMAIN}"

# ── 6. Weryfikacja DNS ────────────────────────────────────────────────────────
section "6. Weryfikacja DNS"
SERVER_IP=$(curl -fsSL https://ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')
info "IP serwera: ${SERVER_IP}"

DNS_IP=$(dig +short "${DOMAIN}" A 2>/dev/null | tail -1 || true)
if [[ -z "$DNS_IP" ]]; then
    warn "Nie można rozwiązać DNS dla ${DOMAIN}"
    warn "Dodaj rekord A: ${DOMAIN} → ${SERVER_IP}"
    warn "Let's Encrypt NIE pobierze certyfikatu bez poprawnego DNS!"
elif [[ "$DNS_IP" == "$SERVER_IP" ]]; then
    ok "DNS OK: ${DOMAIN} → ${DNS_IP}"
else
    warn "DNS rozbieżność: ${DOMAIN} → ${DNS_IP}, serwer → ${SERVER_IP}"
    warn "Let's Encrypt może nie zadziałać — sprawdź panel DNS"
fi

# ── 7. Uruchomienie stacku ────────────────────────────────────────────────────
section "7. Uruchomienie Docker Compose"

cd "${DOCKER_DIR}"

COMPOSE_CMD="docker compose -f docker-compose.yml -f docker-compose.prod.yml --env-file .env.prod"
[[ $USE_MYSQL -eq 1 ]] && COMPOSE_CMD="${COMPOSE_CMD} -f docker-compose.mysql.yml"

info "Buduję i uruchamiam kontenery (pierwsze uruchomienie: ~3–5 min)..."
$COMPOSE_CMD up -d --build

ok "Stack uruchomiony"

# ── 8. Weryfikacja ────────────────────────────────────────────────────────────
section "8. Weryfikacja"

echo
info "Czekam aż kontenery będą gotowe (15 s)..."
sleep 15

echo
$COMPOSE_CMD ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"

echo
if curl -fsS --max-time 10 "http://${DOMAIN}" -o /dev/null 2>/dev/null; then
    ok "HTTP odpowiada"
else
    warn "HTTP nie odpowiada — sprawdź logi: docker logs feer-traefik"
fi

if curl -fsSk --max-time 15 "https://${DOMAIN}" -o /dev/null 2>/dev/null; then
    ok "HTTPS działa — certyfikat SSL aktywny"
else
    warn "HTTPS niedostępny (certyfikat może być jeszcze pobierany — odczekaj 30 s)"
fi

# ── 9. Instrukcje po wdrożeniu ────────────────────────────────────────────────
section "9. Co dalej"
echo
echo -e "  ${BOLD}URL aplikacji:${RESET}   https://${DOMAIN}"
echo -e "  ${BOLD}Panel admina:${RESET}    https://${DOMAIN}/admin/"
echo -e "  ${BOLD}Pierwszy admin:${RESET}  docker exec -it feer-app php /var/www/html/cli/CreateServiceUser.php"
echo -e "  ${BOLD}Lista kontrolna:${RESET} docker exec feer-app php /var/www/html/cli/prod_check.php"
echo
echo -e "  ${BOLD}Skrót (dodaj do ~/.bashrc):${RESET}"
echo -e "  ${CYAN}alias feer='docker compose -f ${DOCKER_DIR}/docker-compose.yml \\"
echo -e "    -f ${DOCKER_DIR}/docker-compose.prod.yml --env-file ${ENV_FILE}'${RESET}"
echo
ok "Wdrożenie zakończone"
