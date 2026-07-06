#!/usr/bin/env bash
# setup-rc.sh — Wdrożenie Roundcube ("rc" — webmail modułu Poczta) OBOK już
# działającego środowiska FEER SZO (feer-app + feer-traefik) na tym samym
# serwerze/hoście Docker.
#
# Uruchom z katalogu docker/ na serwerze, tam gdzie leży .env.prod:
#   cd /opt/feer-szo/docker && bash setup-rc.sh [domena]
#
# Domyślna domena: rc.feer.org.pl (można nadpisać argumentem).
#
# Co robi:
#   1. Sprawdza, że główny stack (feer-traefik) już działa — NIE startuje go
#      od zera, dokłada się do niego.
#   2. Interaktywnie prosi o RC_OAUTH_CLIENT_ID / RC_OAUTH_CLIENT_SECRET
#      (z osobnej rejestracji aplikacji Azure AD — te dane NIE da się wygenerować
#      automatycznie, trzeba je najpierw założyć w Azure, zob. docker/roundcube/README.md)
#      i dopisuje je do istniejącego .env.prod (nie nadpisuje, jeśli już są ustawione).
#   3. Sprawdza DNS domeny.
#   4. `docker compose up -d` na docker-compose.yml + prod.yml + rc.yml.
#   5. Czeka aż kontener Roundcube wstanie.
#   6. Pokazuje dokładny redirect URI do zarejestrowania w Azure (jeśli jeszcze
#      nie zrobione) i co wkleić w Admin → moduł Poczty → Ustawienia
#      (admin/poczta_settings.php, pole "Adres webmaila").
#
# Bezpieczne do wielokrotnego uruchamiania (idempotentne — nie nadpisuje
# istniejących wartości w .env.prod, o resztę tylko pyta ponownie jeśli puste).

set -euo pipefail

# ── Konfiguracja ───────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env.prod"
RC_DOMAIN_ARG="${1:-rc.feer.org.pl}"
RC_CONTAINER="feer-rc"

COMPOSE_FILES=(-f "${SCRIPT_DIR}/docker-compose.yml" -f "${SCRIPT_DIR}/docker-compose.prod.yml" -f "${SCRIPT_DIR}/docker-compose.rc.yml")

# ── Kolory ANSI ────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
die()     { echo -e "${RED}  ✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }
ask()     { read -rp "$(echo -e "${CYAN}  ▸ $1${RESET} [${2}]: ")" _v; echo "${_v:-$2}"; }
askp()    { read -rsp "$(echo -e "${CYAN}  ▸ $1${RESET}: ")" _v; echo; echo "$_v"; }

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║   FEER — Roundcube \"rc\" (webmail modułu Poczta)          ║${RESET}"
echo -e "${BOLD}║   Domena: ${RC_DOMAIN_ARG}                    ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

# ── 1. Walidacja ───────────────────────────────────────────────────────────────
section "1. Walidacja środowiska"

command -v docker &>/dev/null || die "Docker nie jest zainstalowany."
[[ -f "${SCRIPT_DIR}/docker-compose.rc.yml" ]] || die "Brak pliku: docker-compose.rc.yml"
[[ -f "${ENV_FILE}" ]] || die "Brak ${ENV_FILE} — najpierw uruchom główny stack (zob. DEPLOY.md)."

if ! docker inspect feer-traefik &>/dev/null; then
    die "Kontener feer-traefik nie istnieje. Ten skrypt dokłada Roundcube do już\n      działającego stacku — uruchom go najpierw (DEPLOY.md), a potem wróć tutaj."
fi
[[ "$(docker inspect feer-traefik --format '{{.State.Status}}')" == "running" ]] \
    || die "Kontener feer-traefik nie działa. Sprawdź: docker logs feer-traefik"
ok "feer-traefik działa — Roundcube dołączy do tej samej sieci/Traefika"

# ── 2. Zmienne w .env.prod ───────────────────────────────────────────────────
section "2. Konfiguracja .env.prod"

add_if_missing() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "${ENV_FILE}"; then
        info "${key} już ustawione — bez zmian"
    else
        echo "${key}=${value}" >> "${ENV_FILE}"
        ok "${key} ustawione i dopisane"
    fi
}

if ! grep -q "^# ── Roundcube" "${ENV_FILE}"; then
    echo "" >> "${ENV_FILE}"
    echo "# ── Roundcube \"rc\" (wygenerowane przez setup-rc.sh) ─────────────────────" >> "${ENV_FILE}"
fi

# Ten sam tenant co MS_TENANT_ID głównej aplikacji, jeśli już go znamy.
DEFAULT_TENANT="$(grep -m1 '^MS_TENANT_ID=' "${ENV_FILE}" | cut -d= -f2- || true)"

add_if_missing "RC_DOMAIN"    "${RC_DOMAIN_ARG}"
add_if_missing "RC_TENANT_ID" "${DEFAULT_TENANT:-common}"

if grep -q "^RC_OAUTH_CLIENT_ID=" "${ENV_FILE}" && [[ -n "$(grep -m1 '^RC_OAUTH_CLIENT_ID=' "${ENV_FILE}" | cut -d= -f2-)" ]]; then
    info "RC_OAUTH_CLIENT_ID już ustawione — bez zmian"
else
    warn "Potrzebna OSOBNA rejestracja aplikacji Azure AD dla Roundcube (inny redirect URI"
    warn "niż główna aplikacja) — jeśli jeszcze jej nie ma, przerwij (Ctrl+C), wykonaj"
    warn "kroki z docker/roundcube/README.md (sekcja 3), i wróć."
    RC_CLIENT_ID="$(ask "Application (client) ID z Azure AD" "")"
    if [[ -n "${RC_CLIENT_ID}" ]]; then
        sed -i.bak "/^RC_OAUTH_CLIENT_ID=/d" "${ENV_FILE}" 2>/dev/null || true
        echo "RC_OAUTH_CLIENT_ID=${RC_CLIENT_ID}" >> "${ENV_FILE}"
        rm -f "${ENV_FILE}.bak"
        ok "RC_OAUTH_CLIENT_ID zapisane"
    else
        warn "Puste — RC_OAUTH_CLIENT_ID NIE zostało ustawione. Logowanie OAuth nie zadziała,"
        warn "dopóki nie uzupełnisz go ręcznie w ${ENV_FILE} i nie uruchomisz skryptu ponownie."
    fi
fi

if grep -q "^RC_OAUTH_CLIENT_SECRET=" "${ENV_FILE}" && [[ -n "$(grep -m1 '^RC_OAUTH_CLIENT_SECRET=' "${ENV_FILE}" | cut -d= -f2-)" ]]; then
    info "RC_OAUTH_CLIENT_SECRET już ustawione — bez zmian"
else
    RC_CLIENT_SECRET="$(askp "Client secret z Azure AD (Enter = pomiń na razie)")"
    if [[ -n "${RC_CLIENT_SECRET}" ]]; then
        sed -i.bak "/^RC_OAUTH_CLIENT_SECRET=/d" "${ENV_FILE}" 2>/dev/null || true
        echo "RC_OAUTH_CLIENT_SECRET=${RC_CLIENT_SECRET}" >> "${ENV_FILE}"
        rm -f "${ENV_FILE}.bak"
        ok "RC_OAUTH_CLIENT_SECRET zapisane"
    else
        warn "Puste — RC_OAUTH_CLIENT_SECRET NIE zostało ustawione."
    fi
fi

chmod 600 "${ENV_FILE}"

# Nie sourcujemy całego .env.prod jako bash — wartości typu ORG_NAME zawierają
# spacje bez cytowania ("Fundacja Edukacji Empatii Rozwoju FEER"), co przy
# `source` bash próbuje wykonać jako polecenia ("Edukacji: command not found").
# Docker compose parsuje --env-file inaczej (bez interpretacji shellowej) i nie
# ma tego problemu — tutaj wyciągamy tylko to, czego skrypt faktycznie potrzebuje.
RC_DOMAIN="$(grep -m1 '^RC_DOMAIN=' "${ENV_FILE}" | cut -d= -f2-)"

# ── 3. DNS ─────────────────────────────────────────────────────────────────────
section "3. Sprawdzenie DNS"

RESOLVED_IP="$(getent ahosts "${RC_DOMAIN}" 2>/dev/null | awk '{print $1}' | head -1 || true)"
if [[ -z "${RESOLVED_IP}" ]]; then
    warn "${RC_DOMAIN} nie rozwiązuje się jeszcze na żaden adres IP — Let's Encrypt (HTTP-01) nie wystawi certyfikatu, dopóki DNS się nie rozpropaguje"
else
    ok "${RC_DOMAIN} → ${RESOLVED_IP}"
fi

# ── 4. Uruchamianie kontenera ────────────────────────────────────────────────
section "4. Uruchamianie kontenera"

COMPOSE=(docker compose "${COMPOSE_FILES[@]}" --env-file "${ENV_FILE}")

"${COMPOSE[@]}" up -d --remove-orphans rc
ok "Kontener Roundcube uruchomiony/zaktualizowany"

# ── 5. Czekaj aż Roundcube wstanie ───────────────────────────────────────────
section "5. Oczekiwanie na Roundcube"

READY=0
for i in $(seq 1 18); do
    STATUS="$(docker inspect "${RC_CONTAINER}" --format '{{.State.Status}}' 2>/dev/null || echo none)"
    if [[ "${STATUS}" == "running" ]] \
        && docker exec "${RC_CONTAINER}" curl -sf -o /dev/null http://localhost/ 2>/dev/null; then
        ok "Kontener odpowiada po ${i}0s"; READY=1; break
    fi
    sleep 10
done
[[ "${READY}" -eq 1 ]] || warn "Roundcube jeszcze nie odpowiada po 3 minutach — sprawdź: docker logs ${RC_CONTAINER}"

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${GREEN}║   Roundcube wdrożony obok głównego stacku FEER            ║${RESET}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${BOLD}Adres Roundcube:${RESET}  https://${RC_DOMAIN}/"
echo ""
echo -e "  ${YELLOW}Jeśli logowanie jeszcze nie działa${RESET} — w Azure AD → App registrations →"
echo -e "  Twoja aplikacja „rc” → Authentication → Redirect URI dodaj OBIE wartości"
echo -e "  (dokładny format zależy od wersji Roundcube, nie da się tego przewidzieć"
echo -e "  bez żywego testu — zob. docker/roundcube/README.md sekcja 5):"
echo -e "    ${CYAN}https://${RC_DOMAIN}/index.php/login/oauth${RESET}"
echo -e "    ${CYAN}https://${RC_DOMAIN}/index.php?_task=login&_action=oauth${RESET}"
echo ""
echo -e "  ${YELLOW}Teraz w aplikacji FEER SZO:${RESET} Admin → moduł Poczty → Ustawienia"
echo -e "  ${BOLD}(admin/poczta_settings.php)${RESET} — w polu „Adres webmaila” wpisz:"
echo -e "    ${CYAN}https://${RC_DOMAIN}${RESET}"
echo -e "  żeby w panelu Poczty pojawił się przycisk „Otwórz Roundcube”."
echo ""
echo -e "  ${BOLD}Ponowne uruchomienie tego skryptu jest bezpieczne${RESET} (nie nadpisuje już"
echo -e "  ustawionych wartości w .env.prod)."
echo ""
