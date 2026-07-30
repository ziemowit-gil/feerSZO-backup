#!/usr/bin/env bash
# setup-ldap.sh — Instalator lokalnego katalogu OpenLDAP dla FEER SZO.
#
# Alternatywa dla zewnętrznego ldap-prod.feer.org.pl: stawia kontener OpenLDAP
# (+ phpLDAPadmin) nasłuchujący TYLKO na 127.0.0.1, tworzy gałąź ou=users i
# weryfikuje połączenie. Bezpieczny do wielokrotnego uruchamiania (idempotentny).
#
# Uruchom z katalogu docker/:
#   cd docker && bash setup-ldap.sh
#
# Zmienne (opcjonalne — pytane/generowane, jeśli puste):
#   LDAP_DOMAIN            (domyślnie feer.org.pl  → dc=feer,dc=org,dc=pl)
#   LDAP_ORGANISATION      (domyślnie "Fundacja FEER")
#   LDAP_ADMIN_PASSWORD    (generowane losowo, jeśli brak w .env)
#
# Efekt: dopisane LDAP_* do .env oraz gotowy wpis do config.local.php aplikacji.

set -euo pipefail

# ── Konfiguracja ───────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"
LDAP_CONTAINER="feer-ldap"
COMPOSE_FILES=(-f "${SCRIPT_DIR}/docker-compose.yml" -f "${SCRIPT_DIR}/docker-compose.ldap.yml")

# Domena i wyprowadzone z niej DN.
LDAP_DOMAIN="${LDAP_DOMAIN:-feer.org.pl}"
LDAP_ORGANISATION="${LDAP_ORGANISATION:-Fundacja FEER}"
BASE_DN="dc=$(echo "${LDAP_DOMAIN}" | sed 's/\./,dc=/g')"
ADMIN_DN="cn=admin,${BASE_DN}"
USERS_OU="ou=users,${BASE_DN}"

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
echo -e "${BOLD}║   FEER — lokalny katalog OpenLDAP (nasłuch 127.0.0.1)     ║${RESET}"
echo -e "${BOLD}║   Base DN: ${BASE_DN}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

# ── 1. Walidacja ───────────────────────────────────────────────────────────────
section "1. Walidacja środowiska"
command -v docker &>/dev/null || die "Docker nie jest zainstalowany."
docker compose version &>/dev/null || die "Wtyczka 'docker compose' niedostępna."
[[ -f "${SCRIPT_DIR}/docker-compose.ldap.yml" ]] || die "Brak docker-compose.ldap.yml"
ok "Docker + compose dostępne"

# ── 2. Hasło admina w .env ───────────────────────────────────────────────────
section "2. Konfiguracja .env (LDAP_*)"
touch "${ENV_FILE}"

get_env() { grep -m1 "^$1=" "${ENV_FILE}" 2>/dev/null | cut -d= -f2- || true; }
set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "${ENV_FILE}"; then
        info "${key} już ustawione — bez zmian"
    else
        [[ -s "${ENV_FILE}" && "$(tail -c1 "${ENV_FILE}")" != $'\n' ]] && echo >> "${ENV_FILE}"
        echo "${key}=${value}" >> "${ENV_FILE}"
        ok "${key} dopisane do .env"
    fi
}

ADMIN_PW="$(get_env LDAP_ADMIN_PASSWORD)"
if [[ -z "${ADMIN_PW}" ]]; then
    ADMIN_PW="${LDAP_ADMIN_PASSWORD:-$(openssl rand -base64 18 2>/dev/null | tr -d '/+=' | cut -c1-20)}"
    [[ -n "${ADMIN_PW}" ]] || ADMIN_PW="feer-$(date +%s)"
fi

if ! grep -q "^# ── LDAP" "${ENV_FILE}"; then
    [[ -s "${ENV_FILE}" && "$(tail -c1 "${ENV_FILE}")" != $'\n' ]] && echo >> "${ENV_FILE}"
    echo "" >> "${ENV_FILE}"
    echo "# ── LDAP (wygenerowane przez setup-ldap.sh) ─────────────────────────────" >> "${ENV_FILE}"
fi
set_env "LDAP_ORGANISATION"   "${LDAP_ORGANISATION}"
set_env "LDAP_DOMAIN"         "${LDAP_DOMAIN}"
set_env "LDAP_ADMIN_PASSWORD" "${ADMIN_PW}"
chmod 600 "${ENV_FILE}"
ADMIN_PW="$(get_env LDAP_ADMIN_PASSWORD)"   # ponownie z pliku (gdyby już było)

# ── 3. Uruchomienie kontenera ────────────────────────────────────────────────
section "3. Uruchamianie kontenerów LDAP"
COMPOSE=(docker compose "${COMPOSE_FILES[@]}" --env-file "${ENV_FILE}")
"${COMPOSE[@]}" up -d --remove-orphans ldap phpldapadmin
ok "Kontenery uruchomione/zaktualizowane"

# ── 4. Oczekiwanie na gotowość (bind) ────────────────────────────────────────
section "4. Oczekiwanie na serwer LDAP"
READY=0
for i in $(seq 1 24); do
    if docker exec "${LDAP_CONTAINER}" \
        ldapwhoami -x -H ldap://localhost -D "${ADMIN_DN}" -w "${ADMIN_PW}" &>/dev/null; then
        ok "LDAP odpowiada (bind OK) po $((i*5))s"; READY=1; break
    fi
    sleep 5
done
[[ "${READY}" -eq 1 ]] || die "LDAP nie odpowiada po 2 min — sprawdź: docker logs ${LDAP_CONTAINER}"

# ── 5. Zapewnienie ou=users (idempotentnie) ──────────────────────────────────
section "5. Gałąź ${USERS_OU}"
if docker exec "${LDAP_CONTAINER}" \
    ldapsearch -x -H ldap://localhost -D "${ADMIN_DN}" -w "${ADMIN_PW}" \
    -b "${USERS_OU}" -s base "(objectClass=*)" &>/dev/null; then
    ok "OU już istnieje (bootstrap LDIF zadziałał)"
else
    info "OU nie istnieje — tworzę przez ldapadd…"
    if printf 'dn: %s\nobjectClass: organizationalUnit\nou: users\n' "${USERS_OU}" \
        | docker exec -i "${LDAP_CONTAINER}" \
          ldapadd -x -H ldap://localhost -D "${ADMIN_DN}" -w "${ADMIN_PW}" &>/dev/null; then
        ok "Utworzono ${USERS_OU}"
    else
        warn "Nie udało się utworzyć OU automatycznie — utwórz ręcznie w phpLDAPadmin."
    fi
fi

# ── 6. Weryfikacja ─────────────────────────────────────────────────────────────
section "6. Weryfikacja"
COUNT="$(docker exec "${LDAP_CONTAINER}" \
    ldapsearch -x -LLL -H ldap://localhost -D "${ADMIN_DN}" -w "${ADMIN_PW}" \
    -b "${BASE_DN}" "(objectClass=*)" dn 2>/dev/null | grep -c '^dn:' || true)"
ok "Wpisów w katalogu: ${COUNT}"

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${GREEN}║   Lokalny katalog LDAP gotowy                            ║${RESET}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${BOLD}phpLDAPadmin:${RESET}   http://127.0.0.1:8389/"
echo -e "  ${BOLD}Login:${RESET}          ${ADMIN_DN}"
echo -e "  ${BOLD}Hasło:${RESET}          (w ${ENV_FILE} → LDAP_ADMIN_PASSWORD)"
echo ""
echo -e "  ${BOLD}Wklej do config.local.php aplikacji SZO:${RESET}"
echo -e "    ${CYAN}define('LDAP_ENABLED',  true);${RESET}"
echo -e "    ${CYAN}define('LDAP_HOST',     '127.0.0.1');   // z sieci Docker użyj 'ldap'${RESET}"
echo -e "    ${CYAN}define('LDAP_PORT',     389);${RESET}"
echo -e "    ${CYAN}define('LDAP_BIND_DN',  '${ADMIN_DN}');${RESET}"
echo -e "    ${CYAN}define('LDAP_BIND_PW',  '<LDAP_ADMIN_PASSWORD z .env>');${RESET}"
echo -e "    ${CYAN}define('LDAP_BASE_DN',  '${BASE_DN}');${RESET}"
echo -e "    ${CYAN}define('LDAP_USERS_OU', '${USERS_OU}');${RESET}"
echo ""
echo -e "  ${BOLD}Sprawdzenie z poziomu aplikacji:${RESET}  php cli/ldap_install.php"
echo -e "  ${BOLD}Pełny eksport kont:${RESET}              php cron/sync_ldap.php --dry-run"
echo ""
