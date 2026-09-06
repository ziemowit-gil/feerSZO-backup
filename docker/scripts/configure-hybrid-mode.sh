#!/usr/bin/env bash
# configure-hybrid-mode.sh — ustawia warstwę hybrydową (hybrid/) na tryb
# "1 serwer" (Monolit Modularny): SZO, TI i Dydaktyka działają w tym samym
# procesie/kontenerze, bez żadnych wywołań HTTP między sobą.
#
# Użycie (z katalogu docker/ na serwerze, tam gdzie leży .env.prod):
#   cd /opt/feer-szo/docker && bash configure-hybrid-mode.sh
#
# Co robi:
#   - Ustawia SZO_DRIVER=local, TI_DRIVER=local, DYDAKTYKA_DRIVER=local
#     w .env.prod (dopisuje brakujące linie, podmienia istniejące —
#     bezpieczne do wielokrotnego uruchamiania).
#   - Komentuje *_API_BASE_URL / *_API_KEY, jeśli były wcześniej ustawione
#     pod tryb http — w trybie 1 serwer są zbędne i tylko mylące.
#   - Kopia zapasowa .env.prod przed zapisem: .env.prod.bak-RRRRMMDD_GGMMSS.
#   - Na końcu przypomina, że zmiana zmiennych środowiskowych wymaga
#     ODTWORZENIA kontenera aplikacji (nie samego restartu Apache).
#
# Zobacz też:
#   hybrid/README.md      — opis całej architektury Adapter/Driver
#   fix-env-prod.sh        — naprawa uszkodzonego .env.prod
#   make-env.sh             — tworzenie .env.prod od zera

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# .env.prod może być obok skryptu (uruchomiony przez symlink docker/*.sh)
# albo jeden poziom wyżej (docker/scripts/*.sh) — sprawdzamy, gdzie faktycznie
# jest ([[project_docker_scripts_reorg]]).
if [[ -f "${SCRIPT_DIR}/docker-compose.yml" ]]; then
    COMPOSE_DIR="$SCRIPT_DIR"
else
    COMPOSE_DIR="$(dirname "$SCRIPT_DIR")"
fi
ENV_FILE="${COMPOSE_DIR}/.env.prod"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
die()     { echo -e "${RED}  ✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

echo
echo -e "${BOLD}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║   FEER — warstwa hybrydowa: tryb 1 serwer (Monolit)      ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

[[ -f "${ENV_FILE}" ]] || die "Brak pliku: ${ENV_FILE}. Utwórz go najpierw: bash make-env.sh"

section "1. Kopia zapasowa"
BACKUP="${ENV_FILE}.bak-$(date +%Y%m%d_%H%M%S)"
cp "${ENV_FILE}" "${BACKUP}"
ok "Zapisano kopię: $(basename "${BACKUP}")"

section "2. Ustawianie SZO_DRIVER / TI_DRIVER / DYDAKTYKA_DRIVER = local"

# Podmienia linię KLUCZ=... jeśli istnieje, w przeciwnym razie dopisuje ją
# na końcu. Działa na całych liniach (nie dzieli po "="), więc nie psuje
# wartości zawierających "=" (np. sekrety base64).
set_kv() {
    local key="$1" val="$2" tmp
    tmp="$(mktemp)"
    if grep -qE "^${key}=" "${ENV_FILE}"; then
        awk -v k="${key}" -v v="${val}" '
            BEGIN { pat = "^" k "=" }
            $0 ~ pat { print k "=" v; next }
            { print }
        ' "${ENV_FILE}" > "${tmp}"
    else
        cp "${ENV_FILE}" "${tmp}"
        printf '%s=%s\n' "${key}" "${val}" >> "${tmp}"
    fi
    mv "${tmp}" "${ENV_FILE}"
}

# Komentuje istniejącą linię KLUCZ=... (nie usuwa — zostaje jako ślad, gdyby
# trzeba było wrócić do trybu http). Nic nie robi, gdy klucza nie ma.
comment_kv() {
    local key="$1" tmp
    if grep -qE "^${key}=" "${ENV_FILE}"; then
        tmp="$(mktemp)"
        awk -v k="${key}" '
            BEGIN { pat = "^" k "=" }
            $0 ~ pat { print "# " $0 "  # wyłączone przez configure-hybrid-mode.sh (tryb 1 serwer)"; next }
            { print }
        ' "${ENV_FILE}" > "${tmp}"
        mv "${tmp}" "${ENV_FILE}"
        warn "Zakomentowano istniejące: ${key}=... (niepotrzebne w trybie 1 serwer)"
    fi
}

for domain in SZO TI DYDAKTYKA; do
    set_kv "${domain}_DRIVER" "local"
    ok "${domain}_DRIVER=local"
    comment_kv "${domain}_API_BASE_URL"
    comment_kv "${domain}_API_KEY"
done

section "3. Gotowe"
echo "  Plik zaktualizowany: ${ENV_FILE}"
echo
warn "Zmiana zmiennych środowiskowych wymaga ODTWORZENIA kontenera aplikacji"
warn "— sam restart Apache / dotknięcie plików PHP NIE wystarczy (env jest"
warn "wstrzykiwane przy starcie kontenera):"
echo
echo -e "  ${CYAN}docker compose -f docker-compose.yml -f docker-compose.prod.yml \\
    --env-file .env.prod up -d --no-deps app${RESET}"
echo
info "Sprawdź po restarcie:"
echo "    docker exec feer-app php -r \"echo getenv('TI_DRIVER') ?: 'local', PHP_EOL;\""
info "Albo uruchom pełne demo (patrz hybrid/README.md):"
echo "    docker exec feer-app php hybrid/demo.php"
echo
