#!/usr/bin/env bash
# setup-testy.sh — Wdrożenie testowej instalacji FEER SZO
#
# Uruchom z katalogu docker/ na serwerze:
#   cd /opt/feer-szo/docker && bash setup-testy.sh
#
# Co robi:
#   1. Wykrywa sieć prod Traefika (feer-traefik musi działać)
#   2. Klonuje kod do /opt/feer-testy (lub aktualizuje przez git pull)
#   3. Generuje /opt/feer-szo/docker/.env.testy (APP_KEY, sieć, domena)
#   4. Uruchamia kontenery feer-testy-app + feer-testy-redis
#   5. Inicjalizuje schemat bazy danych (setup/setup.php)
#   6. Czyści dane (cleanAll --yes) i seeduje konta testowe
#   7. Generuje certyfikat aplikacji
#   8. Drukuje podsumowanie z URL i danymi logowania
#
# Bezpieczne do wielokrotnego uruchamiania (idempotentne).
# Przy każdym uruchomieniu dane testowe są świeże (reset + seed).

set -euo pipefail

# ── Konfiguracja ───────────────────────────────────────────────────────────────
PROD_DIR="/opt/feer-szo"
TESTY_DIR="/opt/feer-testy"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env.testy"
COMPOSE_FILE="${SCRIPT_DIR}/docker-compose.testy-srv.yml"
DOMAIN="testy-szo.feer.org.pl"
APP_CONTAINER="feer-testy-app"
ADMIN_EMAIL="serwis@local"
ADMIN_PASS="Admin@Testy2025!"

# ── Kolory ANSI ────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
die()     { echo -e "${RED}  ✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║   FEER SZO — setup środowiska testowego                  ║${RESET}"
echo -e "${BOLD}║   Domena: ${DOMAIN}               ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

# ── 1. Walidacja ───────────────────────────────────────────────────────────────
section "1. Walidacja środowiska"

command -v docker &>/dev/null || die "Docker nie jest zainstalowany."
command -v git    &>/dev/null || die "Git nie jest zainstalowany."
command -v php    &>/dev/null || die "PHP CLI nie jest zainstalowane."

[[ -f "${COMPOSE_FILE}" ]] || die "Brak pliku: ${COMPOSE_FILE}"

# Sprawdź że prod Traefik działa
if ! docker inspect feer-traefik &>/dev/null; then
    die "Kontener feer-traefik nie istnieje. Uruchom najpierw prod stack:\n  cd ${PROD_DIR}/docker && bash rebuild.sh"
fi
if [[ "$(docker inspect feer-traefik --format '{{.State.Status}}')" != "running" ]]; then
    die "Kontener feer-traefik nie działa. Sprawdź: docker logs feer-traefik"
fi
ok "feer-traefik działa"

# Sprawdź że obraz aplikacji istnieje
if ! docker image inspect feer-szo-app:latest &>/dev/null; then
    warn "Obraz feer-szo-app:latest nie istnieje — budowanie z prod Dockerfile..."
    docker compose \
        -f "${SCRIPT_DIR}/docker-compose.yml" \
        -f "${SCRIPT_DIR}/docker-compose.prod.yml" \
        --env-file "${SCRIPT_DIR}/.env.prod" \
        build app \
    || die "Budowanie obrazu nie powiodło się. Sprawdź docker/.env.prod."
fi
ok "Obraz feer-szo-app:latest gotowy"

# ── 2. Wykryj sieć Traefika ───────────────────────────────────────────────────
section "2. Wykrywanie sieci Traefik"

TRAEFIK_NETWORK=$(docker inspect feer-traefik \
    --format '{{range $net, $_ := .NetworkSettings.Networks}}{{$net}} {{end}}' \
    | awk '{print $1}')

[[ -n "${TRAEFIK_NETWORK}" ]] || die "Nie można wykryć sieci Traefika."
ok "Sieć Traefika: ${TRAEFIK_NETWORK}"

# ── 3. Kod testowy ─────────────────────────────────────────────────────────────
section "3. Kod testowy — ${TESTY_DIR}"

if [[ ! -d "${TESTY_DIR}/.git" ]]; then
    info "Klonowanie repozytorium (lokalnie)..."
    git clone "${PROD_DIR}" "${TESTY_DIR}"
    ok "Sklonowano z ${PROD_DIR}"
else
    info "Aktualizacja kodu..."
    git -C "${TESTY_DIR}" pull --ff-only origin main \
        || warn "git pull nie powiódł się — kod może być nieaktualny"
    ok "Kod zaktualizowany ($(git -C "${TESTY_DIR}" log -1 --format='%h %s'))"
fi

# ── 4. Plik środowiskowy .env.testy ───────────────────────────────────────────
section "4. Konfiguracja .env.testy"

if [[ ! -f "${ENV_FILE}" ]]; then
    APP_KEY=$(php -r "echo bin2hex(random_bytes(32));")
    cat > "${ENV_FILE}" <<EOF
# Wygenerowano przez setup-testy.sh — nie edytuj ręcznie kluczy
DOMAIN=${DOMAIN}
TESTY_DIR=${TESTY_DIR}
TRAEFIK_NETWORK=${TRAEFIK_NETWORK}
APP_KEY=${APP_KEY}
EOF
    ok ".env.testy wygenerowany (nowy APP_KEY)"
else
    # Zaktualizuj wartości dynamiczne, zachowaj APP_KEY
    sed -i "s|^TRAEFIK_NETWORK=.*|TRAEFIK_NETWORK=${TRAEFIK_NETWORK}|" "${ENV_FILE}"
    sed -i "s|^TESTY_DIR=.*|TESTY_DIR=${TESTY_DIR}|"                   "${ENV_FILE}"
    ok ".env.testy już istnieje — APP_KEY zachowany, sieć zaktualizowana"
fi

# ── 5. Uruchamianie kontenerów ─────────────────────────────────────────────────
section "5. Uruchamianie kontenerów"

COMPOSE="docker compose -f ${COMPOSE_FILE} --env-file ${ENV_FILE} -p feer-testy"

info "Uruchamianie..."
${COMPOSE} up -d --remove-orphans
ok "Kontenery uruchomione"

# ── 6. Czekaj na gotowość PHP ─────────────────────────────────────────────────
section "6. Oczekiwanie na gotowość PHP"

info "Czekam na Apache (max 40s)..."
for i in $(seq 1 40); do
    if docker exec "${APP_CONTAINER}" php -r "echo 'ok';" 2>/dev/null | grep -q "ok"; then
        ok "PHP gotowy po ${i}s"
        break
    fi
    sleep 1
    if [[ $i -eq 40 ]]; then
        echo ""
        warn "PHP nie odpowiedział po 40s — sprawdź logi:"
        docker logs "${APP_CONTAINER}" --tail=20
        die "Kontenery nie gotowe."
    fi
done

# ── 7. Inicjalizacja bazy danych ──────────────────────────────────────────────
section "7. Inicjalizacja schematu bazy"

info "Tworzenie schematu (setup/setup.php)..."
docker exec "${APP_CONTAINER}" \
    php /var/www/html/setup/setup.php \
    2>&1 | grep -E "✓|✗|BŁĄD|ERR|OK" | head -20 || true
ok "Schemat gotowy"

# ── 8. Czyszczenie danych ─────────────────────────────────────────────────────
section "8. Czyszczenie danych testowych"

info "Usuwanie poprzednich danych (cleanAll --yes)..."
docker exec "${APP_CONTAINER}" \
    php /var/www/html/cli/cleanAll.php --yes \
    2>&1 | grep -E "✓|✗|Łącznie|Wyczyszczono|Usunięto" || true
ok "Baza wyczyszczona"

# ── 9. Tworzenie konta admin ──────────────────────────────────────────────────
section "9. Konto administratora"

# Usuń blokadę .INSTALL_COMPLETE (żeby CreateServiceUser mógł działać)
docker exec "${APP_CONTAINER}" rm -f /var/www/html/.INSTALL_COMPLETE 2>/dev/null || true

info "Tworzenie konta serwisowego..."
docker exec "${APP_CONTAINER}" \
    php /var/www/html/cli/CreateServiceUser.php 2>&1 || true

# Ustaw znane hasło testowe (CreateServiceUser generuje losowe)
info "Ustawianie hasła testowego dla ${ADMIN_EMAIL}..."
docker exec "${APP_CONTAINER}" php -r "
require '/var/www/html/config.php';
require '/var/www/html/includes/db.php';
\$hash = password_hash('${ADMIN_PASS}', PASSWORD_BCRYPT);
\$ok = db()->prepare('UPDATE users SET password=?, is_active=1 WHERE email=?')
           ->execute([\$hash, '${ADMIN_EMAIL}']);
echo \$ok ? 'Haslo ustawione.' . PHP_EOL : 'BLAD: nie znaleziono uzytkownika.' . PHP_EOL;
"

# Usuń blokadę jeszcze raz — żeby seeder mógł działać bez przeszkód
docker exec "${APP_CONTAINER}" rm -f /var/www/html/.INSTALL_COMPLETE 2>/dev/null || true
ok "Konto ${ADMIN_EMAIL} gotowe"

# ── 10. Seedowanie danych testowych ──────────────────────────────────────────
section "10. Seedowanie danych testowych"

info "Ładowanie kont i danych testowych (seed_tasks.php)..."
docker exec "${APP_CONTAINER}" \
    php /var/www/html/cli/seed_tasks.php --clean \
    2>&1 | grep -E "✓|✗|→|Użytkownik|obszar|zadań" || true
ok "Dane testowe załadowane"

# ── 11. Certyfikat aplikacji ──────────────────────────────────────────────────
section "11. Certyfikat aplikacji"

CERT_STATUS=$(docker exec "${APP_CONTAINER}" \
    php /var/www/html/cli/generatorCertyfikatu.php --status 2>&1 || true)

if echo "${CERT_STATUS}" | grep -q "Brak certyfikatu\|nieprawidłowy"; then
    info "Generowanie certyfikatu..."
    docker exec "${APP_CONTAINER}" \
        php /var/www/html/cli/generatorCertyfikatu.php \
        2>&1 | grep -E "INFO|OK|SUKCES|BLAD" || true
    ok "Certyfikat wygenerowany"
else
    ok "Certyfikat istnieje — pominięto"
fi

# ── 12. Status kontenerów ─────────────────────────────────────────────────────
section "12. Status"
${COMPOSE} ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${GREEN}║   Środowisko testowe gotowe!                             ║${RESET}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${BOLD}URL:${RESET}  https://${DOMAIN}"
echo -e "         (certyfikat SSL z Let's Encrypt — do 60s przy pierwszym uruchomieniu)"
echo ""
echo -e "  ${BOLD}Konta testowe:${RESET}"
echo -e "  ┌─────────────────────────────────────────────────────────┐"
echo -e "  │ ${CYAN}Admin${RESET}          ${ADMIN_EMAIL}          ${BOLD}${ADMIN_PASS}${RESET}"
echo -e "  │ ${CYAN}Wolontariusz${RESET}   vol.test@feer.test          Test1234!"
echo -e "  │ ${CYAN}Lider${RESET}          leader.test@feer.test        Leader99!"
echo -e "  │ ${CYAN}Obserwator${RESET}     viewer.test@feer.test        View5678!"
echo -e "  └─────────────────────────────────────────────────────────┘"
echo ""
echo -e "  ${BOLD}Przydatne komendy:${RESET}"
echo -e "  ${CYAN}docker logs -f ${APP_CONTAINER}${RESET}                  # logi na żywo"
echo -e "  ${CYAN}${COMPOSE} down${RESET}  # zatrzymaj"
echo -e "  ${CYAN}bash ${SCRIPT_DIR}/setup-testy.sh${RESET}              # reset + seed (idempotentne)"
echo ""
