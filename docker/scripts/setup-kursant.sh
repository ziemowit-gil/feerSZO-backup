#!/usr/bin/env bash
# setup-kursant.sh — jednorazowe wdrożenie panelu kursanta Angular
#
# Użycie:
#   bash docker/scripts/setup-kursant.sh
#   bash docker/scripts/setup-kursant.sh --no-build   # pomiń budowanie obrazu
#   bash docker/scripts/setup-kursant.sh --dry-run    # tylko pokaż co zrobi
#
# Co robi:
#   1. Dodaje KURSANT_NEW_UI_URL + KURSANT_API_TARGET do .env.prod
#   2. Buduje obraz Docker kursant-ui
#   3. Uruchamia serwis kursant-ui przez docker-compose.kursant.yml
#   4. Drukuje URL i status

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env.prod"
KURSANT_NEW_UI_DEFAULT="https://ti.feer.org.pl/newUI"
KURSANT_API_TARGET_DEFAULT="https://szo.feer.org.pl"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
die()     { echo -e "${RED}  ✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

# ── Argumenty ──────────────────────────────────────────────────────────────────
DO_BUILD=1; DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --no-build) DO_BUILD=0 ;;
        --dry-run)  DRY_RUN=1 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^# \{0,1\}//' | sed '1d'
            exit 0 ;;
        *) die "Nieznana flaga: $arg" ;;
    esac
done

echo ""
echo -e "${BOLD}━━ setup-kursant — panel kursanta Angular ━━━━━━━━━━━━━━━━━━${RESET}"

# ── 1. Walidacja ──────────────────────────────────────────────────────────────
section "1. Walidacja"
[[ -f "$ENV_FILE" ]] || die ".env.prod nie istnieje: ${ENV_FILE}\nUruchom najpierw make-env.sh."
command -v docker >/dev/null || die "Brak 'docker'."
ok ".env.prod i Docker dostępne"

# ── 2. Ustaw zmienne konfiguracyjne w .env.prod ───────────────────────────────
section "2. Konfiguracja .env.prod"

# Helper: ustaw lub zaktualizuj zmienną w pliku env (idempotentny)
set_env_var() {
    local key="$1" value="$2" file="$3"
    if grep -qE "^${key}=" "$file" 2>/dev/null; then
        # Zmienna istnieje — zaktualizuj wartość
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
        ok "Zaktualizowano: ${key}=${value}"
    else
        # Dodaj na końcu (z sekcją jeśli brak)
        if ! grep -qF "# ── Panel kursanta Angular" "$file" 2>/dev/null; then
            echo "" >> "$file"
            echo "# ── Panel kursanta Angular (docker-compose.kursant.yml) ────────────────────" >> "$file"
        fi
        echo "${key}=${value}" >> "$file"
        ok "Dodano: ${key}=${value}"
    fi
}

# Odczytaj obecne wartości (jeśli są) lub użyj domyślnych
CURRENT_NEW_UI=$(grep -E "^KURSANT_NEW_UI_URL=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- || echo "")
CURRENT_API=$(grep -E "^KURSANT_API_TARGET=" "$ENV_FILE" 2>/dev/null | cut -d= -f2- || echo "")

NEW_UI="${CURRENT_NEW_UI:-$KURSANT_NEW_UI_DEFAULT}"
API_TARGET="${CURRENT_API:-$KURSANT_API_TARGET_DEFAULT}"

if [[ $DRY_RUN -eq 1 ]]; then
    warn "--dry-run: następujące zmienne zostałyby ustawione:"
    echo "  KURSANT_NEW_UI_URL=${NEW_UI}"
    echo "  KURSANT_API_TARGET=${API_TARGET}"
    echo ""
    warn "--dry-run: pominięto budowanie i deploy."
    exit 0
fi

set_env_var "KURSANT_NEW_UI_URL"  "$NEW_UI"    "$ENV_FILE"
set_env_var "KURSANT_API_TARGET"  "$API_TARGET" "$ENV_FILE"

# ── 3. Budowanie obrazu ───────────────────────────────────────────────────────
if [[ $DO_BUILD -eq 1 ]]; then
    section "3. Budowanie obrazu kursant-ui"
    info "ng build + docker build (może potrwać kilka minut)…"
    docker compose \
        -f "${SCRIPT_DIR}/docker-compose.yml" \
        -f "${SCRIPT_DIR}/docker-compose.prod.yml" \
        -f "${SCRIPT_DIR}/docker-compose.kursant.yml" \
        --env-file "${ENV_FILE}" \
        build kursant-ui
    ok "Obraz feer-kursant-ui:latest zbudowany"
else
    section "3. Budowanie obrazu"
    warn "--no-build: pomijam budowanie obrazu"
fi

# ── 4. Uruchomienie serwisu ───────────────────────────────────────────────────
section "4. Uruchamianie kursant-ui"

COMPOSE="docker compose \
  -f ${SCRIPT_DIR}/docker-compose.yml \
  -f ${SCRIPT_DIR}/docker-compose.prod.yml \
  -f ${SCRIPT_DIR}/docker-compose.kursant.yml \
  --env-file ${ENV_FILE}"

info "Uruchamianie stacku (kursant-ui + app env)…"
${COMPOSE} up -d --remove-orphans
ok "Stack uruchomiony"

# ── 5. Status ─────────────────────────────────────────────────────────────────
section "5. Status"
sleep 2
${COMPOSE} ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null || ${COMPOSE} ps

# ── 6. Włącz przycisk "Nowy panel" w zalogowanym panelu klasycznym ────────────
# Dopiero teraz, gdy kursant-ui faktycznie działa — nie chcemy pokazywać
# użytkownikom przycisku do usługi, która może jeszcze nie odpowiadać.
section "6. Włączanie przycisku „Nowy panel”"

if ${COMPOSE} ps kursant-ui 2>/dev/null | grep -qi "running\|Up"; then
    set_env_var "KURSANT_NEW_UI_ENABLED" "1" "$ENV_FILE"
    info "Restart serwisu app, żeby podjął nową flagę…"
    ${COMPOSE} up -d --no-deps app
    ok "Przycisk „Nowy panel” włączony w zalogowanym panelu klasycznym"
else
    warn "kursant-ui nie zgłasza się jako uruchomiony — pomijam włączenie przycisku."
    warn "Sprawdź logi (${CYAN}docker compose ... logs kursant-ui${RESET}) i uruchom ten skrypt ponownie."
fi

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}━━ Gotowe ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"
echo ""
echo -e "  ${BOLD}Chooser:${RESET}  https://ti.feer.org.pl/"
echo -e "  ${BOLD}Nowy UI:${RESET}  ${NEW_UI}/"
echo -e "  ${BOLD}Stary UI:${RESET} https://ti.feer.org.pl/karty30/ti/kursant/login.php"
echo ""
echo -e "  ${BOLD}Aktualizacja UI (po zmianie kodu):${RESET}"
echo -e "  ${CYAN}bash docker/scripts/rebuild.sh --kursant${RESET}"
echo ""
