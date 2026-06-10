#!/usr/bin/env bash
# FEER SZO — skrypt wdrożeniowy (Ubuntu 22.04/24.04)
# Użycie: bash setup.sh [--mysql] [--mode=1|2|3|4|5|6]
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
INSTALL_MODE=""
for arg in "$@"; do
    [[ "$arg" == "--mysql"   ]] && USE_MYSQL=1
    [[ "$arg" == --mode=*    ]] && INSTALL_MODE="${arg#--mode=}"
done

# ── Stałe ─────────────────────────────────────────────────────────────────────
REPO_URL="https://codeberg.org/ziemowitgil/feerSZO"
INSTALL_DIR="/opt/feer-szo"
DOCKER_DIR="${INSTALL_DIR}/docker"
ENV_FILE="${DOCKER_DIR}/.env.prod"
DEFAULT_DOMAIN="szo.feer.org.pl"
DEFAULT_ACME_EMAIL="admin@feer.org.pl"
DEFAULT_ORG="Fundacja Edukacji Empatii Rozwoju FEER"

# ── Menu wyboru trybu instalacji ──────────────────────────────────────────────
if [[ -z "$INSTALL_MODE" ]]; then
    echo
    echo -e "${BOLD}━━ Tryb instalacji ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
    echo -e "  ${BOLD}1)${RESET} Pełna instalacja produkcyjna (domyślne)"
    echo -e "  ${BOLD}2)${RESET} Instalacja testowa PHP 8.3"
    echo -e "  ${BOLD}3)${RESET} Instalacja testowa PHP 8.5"
    echo -e "  ${BOLD}4)${RESET} Tylko konfiguracja (bez Docker — edytuj .env.prod)"
    echo -e "  ${BOLD}5)${RESET} Wyczyść Docker (kontenery + obrazy FEER)"
    echo -e "  ${BOLD}6)${RESET} ${RED}Pełny reset${RESET} — wyczyść Docker + usuń pliki + reinstalacja"
    echo -e "  ${BOLD}Q)${RESET} Wyjdź"
    echo
    read -rp "Wybór [1]: " INSTALL_MODE
    INSTALL_MODE="${INSTALL_MODE:-1}"
fi

case "${INSTALL_MODE^^}" in
    Q|q) echo -e "${CYAN}Anulowano.${RESET}"; exit 0 ;;
    1|2|3|4|5|6) ;;
    *) die "Nieznany tryb: '${INSTALL_MODE}'. Dozwolone: 1, 2, 3, 4, 5, 6, Q" ;;
esac

# ── Tryb 6 — pełny reset: Docker + pliki + reinstalacja ──────────────────────
if [[ "$INSTALL_MODE" == "6" ]]; then
    [[ $EUID -ne 0 ]] && die "Pełny reset wymaga roota: sudo bash setup.sh --mode=6"

    section "Pełny reset FEER SZO"
    warn "Spowoduje usunięcie:"
    warn "  • Wszystkich kontenerów i obrazów Docker FEER"
    warn "  • Katalogu instalacji: ${INSTALL_DIR}"
    warn "  • Pliku .env.prod (konfiguracja — zrób backup!)"
    echo
    read -rp "Na pewno chcesz kontynuować? Wpisz 'RESET' aby potwierdzić: " _confirm
    [[ "$_confirm" != "RESET" ]] && { warn "Anulowano."; exit 0; }

    # Kopia setup.sh do /tmp — katalog instalacji zaraz zostanie usunięty
    TMP_SETUP="$(mktemp /tmp/feer_setup_XXXXXX.sh)"
    curl -fsSL "${REPO_URL}/raw/branch/main/docker/setup.sh" -o "$TMP_SETUP"
    chmod +x "$TMP_SETUP"
    ok "Pobrano świeży setup.sh do ${TMP_SETUP}"

    # Krok 1: wyczyść Docker
    section "Krok 1/3 — Czyszczenie Docker"
    TMP_CLEAN="$(mktemp)"
    if [[ -f "${DOCKER_DIR}/clean.sh" ]]; then
        bash "${DOCKER_DIR}/clean.sh" --full --yes
    else
        curl -fsSL "${REPO_URL}/raw/branch/main/docker/clean.sh" -o "$TMP_CLEAN"
        bash "$TMP_CLEAN" --full --yes
    fi
    rm -f "$TMP_CLEAN"
    ok "Docker wyczyszczony"

    # Krok 2: usuń pliki instalacji
    section "Krok 2/3 — Usuwanie plików ${INSTALL_DIR}"
    rm -rf "${INSTALL_DIR}"
    ok "Katalog ${INSTALL_DIR} usunięty"

    # Krok 3: reinstalacja
    section "Krok 3/3 — Reinstalacja"
    info "Uruchamiam świeżą instalację..."
    exec bash "$TMP_SETUP" --mode=1 "$@"
fi

# ── Tryb 5 — czyszczenie Docker ───────────────────────────────────────────────
if [[ "$INSTALL_MODE" == "5" ]]; then
    section "Czyszczenie Docker"

    CLEAN_SCRIPT="${DOCKER_DIR}/clean.sh"

    # Jeśli repo już sklonowane — użyj lokalnego clean.sh
    if [[ -f "$CLEAN_SCRIPT" ]]; then
        info "Uruchamiam clean.sh..."
        bash "$CLEAN_SCRIPT" "$@"
    else
        # Repo nie istnieje — pobierz clean.sh bezpośrednio
        info "Pobieram clean.sh z repozytorium..."
        TMP_CLEAN="$(mktemp)"
        curl -fsSL "${REPO_URL}/raw/branch/main/docker/clean.sh" -o "$TMP_CLEAN"
        bash "$TMP_CLEAN" "$@"
        rm -f "$TMP_CLEAN"
    fi

    echo
    echo -e "  ${BOLD}Co dalej?${RESET}"
    echo -e "  Pełna reinstalacja:  ${CYAN}bash setup.sh --mode=1${RESET}"
    echo -e "  Tylko rebuild:       ${CYAN}bash rebuild.sh --no-cache${RESET}"
    exit 0
fi

# Tryby testowe (2,3) nie wymagają roota ani pełnej instalacji systemowej
if [[ "$INSTALL_MODE" == "2" || "$INSTALL_MODE" == "3" ]]; then
    # ── Root check (opcjonalny dla testów) ────────────────────────────────────
    [[ $EUID -ne 0 ]] && warn "Tryb testowy — zalecane uruchomienie jako root (sudo bash setup.sh)"

    PHP_VER="8.3"; DOCKERFILE="Dockerfile.php83"; TEST_PORT=8083
    [[ "$INSTALL_MODE" == "3" ]] && { PHP_VER="8.5"; DOCKERFILE="Dockerfile.php85"; TEST_PORT=8085; }

    section "Instalacja testowa PHP ${PHP_VER}"

    if ! docker compose version &>/dev/null; then
        die "docker compose (v2) niedostępny — zainstaluj Docker Desktop lub docker-compose-plugin"
    fi
    ok "Docker Compose: $(docker compose version --short)"

    # Klonuj/aktualizuj repo jeśli nie istnieje lokalnie
    if [[ ! -d "${INSTALL_DIR}/.git" ]]; then
        section "Repozytorium"
        info "Klonuję ${REPO_URL} → ${INSTALL_DIR}"
        git clone "${REPO_URL}" "${INSTALL_DIR}"
        ok "Sklonowano"
    fi

    section "Uruchomienie PHP ${PHP_VER} na porcie ${TEST_PORT}"
    cd "${DOCKER_DIR}"

    SERVICE="app83"; [[ "$INSTALL_MODE" == "3" ]] && SERVICE="app85"
    info "Buduję i uruchamiam kontener PHP ${PHP_VER} (pierwsze uruchomienie: ~5–8 min)..."
    docker compose -f docker-compose.test.yml up -d --build "${SERVICE}"

    ok "Kontener PHP ${PHP_VER} uruchomiony"
    echo
    echo -e "  ${BOLD}URL testowy:${RESET}  http://localhost:${TEST_PORT}"
    echo -e "  ${BOLD}Logi:${RESET}         docker logs feer-test-${PHP_VER/./}"
    echo -e "  ${BOLD}Stop:${RESET}         docker compose -f ${DOCKER_DIR}/docker-compose.test.yml stop ${SERVICE}"
    echo
    ok "Instalacja testowa PHP ${PHP_VER} zakończona"
    exit 0
fi

# Tryb 4 — tylko konfiguracja .env.prod
if [[ "$INSTALL_MODE" == "4" ]]; then
    section "Konfiguracja .env.prod (bez Docker)"

    # Klonuj/aktualizuj repo jeśli nie istnieje lokalnie
    if [[ ! -d "${INSTALL_DIR}/.git" ]]; then
        [[ $EUID -ne 0 ]] && die "Uruchom jako root do klonowania repozytorium: sudo bash setup.sh"
        info "Klonuję ${REPO_URL} → ${INSTALL_DIR}"
        git clone "${REPO_URL}" "${INSTALL_DIR}"
        ok "Sklonowano"
    fi

    if [[ -f "${ENV_FILE}" ]]; then
        warn ".env.prod już istnieje — otwieram do edycji"
        ${EDITOR:-nano} "${ENV_FILE}"
        ok "Konfiguracja zakończona"
    else
        bash "${DOCKER_DIR}/make-env.sh"
    fi

    echo
    ok "Uruchom pełną instalację gdy gotowe: sudo bash ${DOCKER_DIR}/setup.sh --mode=1"
    exit 0
fi

# ── TRYB 1 — pełna instalacja produkcyjna ────────────────────────────────────

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
    # Uruchom interaktywny kreator (make-env.sh)
    bash "${DOCKER_DIR}/make-env.sh"
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

# ── Alias feer → .bashrc użytkownika, który uruchomił sudo ───────────────────
ALIAS_LINE="alias feer='docker compose -f ${DOCKER_DIR}/docker-compose.yml -f ${DOCKER_DIR}/docker-compose.prod.yml --env-file ${ENV_FILE}'"
ALIAS_MARKER="# feer-szo alias"

# Ustal plik .bashrc — preferuj użytkownika sprzed sudo, fallback root
TARGET_USER="${SUDO_USER:-${USER:-root}}"
TARGET_HOME=$(getent passwd "$TARGET_USER" 2>/dev/null | cut -d: -f6 || echo "/root")
BASHRC="${TARGET_HOME}/.bashrc"

if grep -qF "feer-szo alias" "${BASHRC}" 2>/dev/null; then
    # Zaktualizuj istniejący alias (na wypadek zmiany ścieżki)
    sed -i "/${ALIAS_MARKER}/,+1d" "${BASHRC}"
fi
{
    echo ""
    echo "${ALIAS_MARKER}"
    echo "${ALIAS_LINE}"
} >> "${BASHRC}"
ok "Alias 'feer' dodany do ${BASHRC}"
echo -e "  ${CYAN}source ${BASHRC}${RESET}  ← załaduj od razu w tej sesji"
echo
ok "Wdrożenie zakończone"
