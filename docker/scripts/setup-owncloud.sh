#!/usr/bin/env bash
# setup-owncloud.sh — Wdrożenie ownCloud (magazyn plików lekcji TI) OBOK już
# działającego środowiska FEER SZO (feer-app + feer-traefik) na tym samym
# serwerze/hoście Docker.
#
# Uruchom z katalogu docker/ na serwerze, tam gdzie leży .env.prod:
#   cd /opt/feer-szo/docker && bash setup-owncloud.sh [domena]
#
# Domyślna domena: owncloud.feer.org.pl (można nadpisać argumentem).
#
# Co robi:
#   1. Sprawdza, że główny stack (feer-traefik) już działa — NIE startuje go
#      od zera, dokłada się do niego.
#   2. Dopisuje brakujące zmienne OWNCLOUD_* do istniejącego .env.prod
#      (generuje hasło admina, jeśli jeszcze go nie ma — nie nadpisuje istniejących).
#   3. Sprawdza DNS domeny.
#   4. `docker compose up -d` na docker-compose.yml + prod.yml + owncloud.yml.
#   5. Czeka aż kontener ownCloud wstanie.
#   6. Tworzy dedykowane konto integracyjne (occ user:add) — TYM kontem/hasłem
#      (nie danymi admina) aplikacja łączy się przez WebDAV.
#   7. Pokazuje dokładnie co wkleić w Admin → Integracje → Magazyn plików /
#      ownCloud (admin/owncloud_settings.php).
#
# Bezpieczne do wielokrotnego uruchamiania (idempotentne — nie nadpisuje
# istniejących sekretów, nie tworzy konta integracyjnego drugi raz).

set -euo pipefail

# ── Konfiguracja ───────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env.prod"
OC_DOMAIN="${1:-owncloud.feer.org.pl}"
OC_CONTAINER="feer-owncloud"
INTEGRATION_USER="feerszo-integracja"

COMPOSE_FILES=(-f "${SCRIPT_DIR}/docker-compose.yml" -f "${SCRIPT_DIR}/docker-compose.prod.yml" -f "${SCRIPT_DIR}/docker-compose.owncloud.yml")

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
echo -e "${BOLD}║   FEER — ownCloud (magazyn plików lekcji TI)             ║${RESET}"
echo -e "${BOLD}║   Domena: ${OC_DOMAIN}                       ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

# ── 1. Walidacja ───────────────────────────────────────────────────────────────
section "1. Walidacja środowiska"

command -v docker &>/dev/null || die "Docker nie jest zainstalowany."
[[ -f "${SCRIPT_DIR}/docker-compose.owncloud.yml" ]] || die "Brak pliku: docker-compose.owncloud.yml"
[[ -f "${ENV_FILE}" ]] || die "Brak ${ENV_FILE} — najpierw uruchom główny stack (zob. DEPLOY.md)."

if ! docker inspect feer-traefik &>/dev/null; then
    die "Kontener feer-traefik nie istnieje. Ten skrypt dokłada ownCloud do już\n      działającego stacku — uruchom go najpierw (DEPLOY.md), a potem wróć tutaj."
fi
[[ "$(docker inspect feer-traefik --format '{{.State.Status}}')" == "running" ]] \
    || die "Kontener feer-traefik nie działa. Sprawdź: docker logs feer-traefik"
ok "feer-traefik działa — ownCloud dołączy do tej samej sieci/Traefika"

# ── 2. Zmienne w .env.prod ───────────────────────────────────────────────────
section "2. Konfiguracja .env.prod"

add_if_missing() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "${ENV_FILE}"; then
        info "${key} już ustawione — bez zmian"
    else
        echo "${key}=${value}" >> "${ENV_FILE}"
        ok "${key} ustawione/wygenerowane i dopisane"
    fi
}

if ! grep -q "^# ── ownCloud" "${ENV_FILE}"; then
    echo "" >> "${ENV_FILE}"
    echo "# ── ownCloud (wygenerowane przez setup-owncloud.sh) ────────────────────────" >> "${ENV_FILE}"
fi

add_if_missing "OWNCLOUD_DOMAIN"         "${OC_DOMAIN}"
add_if_missing "OWNCLOUD_ADMIN_USERNAME" "admin"
add_if_missing "OWNCLOUD_ADMIN_PASSWORD" "$(openssl rand -hex 16)"

chmod 600 "${ENV_FILE}"

# shellcheck disable=SC1090
set -a; source "${ENV_FILE}"; set +a

# ── 3. DNS ─────────────────────────────────────────────────────────────────────
section "3. Sprawdzenie DNS"

RESOLVED_IP="$(getent ahosts "${OWNCLOUD_DOMAIN}" 2>/dev/null | awk '{print $1}' | head -1 || true)"
if [[ -z "${RESOLVED_IP}" ]]; then
    warn "${OWNCLOUD_DOMAIN} nie rozwiązuje się jeszcze na żaden adres IP — Let's Encrypt (HTTP-01) nie wystawi certyfikatu, dopóki DNS się nie rozpropaguje"
else
    ok "${OWNCLOUD_DOMAIN} → ${RESOLVED_IP}"
fi

# ── 4. Uruchamianie kontenera ────────────────────────────────────────────────
section "4. Uruchamianie kontenera"

COMPOSE=(docker compose "${COMPOSE_FILES[@]}" --env-file "${ENV_FILE}")

"${COMPOSE[@]}" up -d --remove-orphans owncloud
ok "Kontener ownCloud uruchomiony/zaktualizowany"

# ── 5. Czekaj aż ownCloud wstanie ────────────────────────────────────────────
section "5. Oczekiwanie na ownCloud"

READY=0
for i in $(seq 1 30); do
    STATUS="$(docker inspect "${OC_CONTAINER}" --format '{{.State.Health.Status}}' 2>/dev/null || echo none)"
    if [[ "${STATUS}" == "healthy" ]]; then
        ok "Kontener zdrowy po ${i}0s"; READY=1; break
    fi
    if [[ "${STATUS}" == "none" ]]; then
        # Obraz nie definiuje healthcheck — sprawdź occ status zamiast tego.
        if docker exec -u www-data "${OC_CONTAINER}" occ status 2>/dev/null | grep -q "installed: true"; then
            ok "ownCloud zainstalowany i gotowy po ${i}0s"; READY=1; break
        fi
    fi
    sleep 10
done
[[ "${READY}" -eq 1 ]] || warn "ownCloud jeszcze się nie zgłosił jako gotowy po 5 minutach — to się zdarza przy pierwszym starcie. Sprawdź: docker logs ${OC_CONTAINER}"

# ── 6. Konto integracyjne (occ user:add) ─────────────────────────────────────
section "6. Konto integracyjne dla aplikacji"

if docker exec -u www-data "${OC_CONTAINER}" occ user:list --output=json 2>/dev/null | grep -q "\"${INTEGRATION_USER}\""; then
    ok "Konto „${INTEGRATION_USER}” już istnieje — bez zmian (hasło NIE jest regenerowane)"
    info "Jeśli nie pamiętasz hasła, zresetuj je: docker exec -it -u www-data ${OC_CONTAINER} occ user:resetpassword ${INTEGRATION_USER}"
    INTEGRATION_PASS="(bez zmian — zobacz wcześniejsze uruchomienie skryptu lub zresetuj ręcznie)"
else
    INTEGRATION_PASS="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)"
    if docker exec -u www-data -e OC_PASS="${INTEGRATION_PASS}" "${OC_CONTAINER}" \
        occ user:add --password-from-env --display-name="FEER SZO — integracja" "${INTEGRATION_USER}" 2>/dev/null; then
        ok "Utworzono konto integracyjne „${INTEGRATION_USER}”"
    else
        warn "Nie udało się automatycznie utworzyć konta occ user:add — utwórz je ręcznie w panelu ownCloud (Ustawienia → Użytkownicy)"
        INTEGRATION_PASS="(utwórz ręcznie)"
    fi
fi

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${GREEN}║   ownCloud wdrożony obok głównego stacku FEER            ║${RESET}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${BOLD}Adres ownCloud:${RESET}  https://${OWNCLOUD_DOMAIN}/"
echo -e "  ${BOLD}Admin (konto kontenera, panel ownCloud):${RESET}  ${OWNCLOUD_ADMIN_USERNAME} / (zob. ${ENV_FILE})"
echo ""
echo -e "  ${YELLOW}Teraz w aplikacji FEER SZO:${RESET} Admin → Integracje → Magazyn plików / ownCloud"
echo -e "  ${BOLD}(admin/owncloud_settings.php)${RESET} — uzupełnij:"
echo -e "    URL:    ${CYAN}https://${OWNCLOUD_DOMAIN}${RESET}"
echo -e "    Login:  ${CYAN}${INTEGRATION_USER}${RESET}"
echo -e "    Hasło:  ${CYAN}${INTEGRATION_PASS}${RESET}"
echo -e "  a następnie kliknij „Testuj połączenie” i zapisz."
echo ""
echo -e "  ${BOLD}Ponowne uruchomienie tego skryptu jest bezpieczne${RESET} (nie nadpisuje sekretów,"
echo -e "  nie tworzy konta integracyjnego drugi raz)."
echo ""
