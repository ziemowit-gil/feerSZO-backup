#!/usr/bin/env bash
# update.sh — git pull kodu + restart Apache + migracje (bez rebuildu obrazu app)
#
# Używaj gdy zmieniły się tylko pliki PHP/HTML/JS/CSS.
# Dla zmian w Dockerfile lub php.prod.ini użyj rebuild.sh.
#
# UWAGA: php.prod.ini ma opcache.validate_timestamps=0 — graceful reload
# nie czyści OPcache (workers dziedziczą SHM z procesu-rodzica). Skrypt
# robi pełny restart Apache, który reinicjalizuje moduł PHP i niszczy stary SHM.
#
# Panel kursanta (kursantApp, Angular) jest inny: serwowany jako statyczny
# dist/ zapieczony w obrazie kursant-ui, więc dla niego "bez rebuildu obrazu"
# nie wystarczy — skrypt i tak przebudowuje ten jeden obraz (docker compose
# build kursant-ui), jeśli kontener feer-kursant-ui jest wdrożony na hoście.
#
# Użycie:
#   bash docker/update.sh           # prod + kursant-ui (jeśli wdrożony) + testy (jeśli działa)
#   bash docker/update.sh --no-testy  # bez środowiska testowego

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROD_DIR="$(dirname "$SCRIPT_DIR")"
TESTY_DIR="/opt/feer-testy"
PROD_CONTAINER="feer-app"
TEST_CONTAINER="feer-testy-app"

# docker-compose.yml może być obok skryptu (uruchomiony przez symlink
# docker/update.sh) albo jeden poziom wyżej (docker/scripts/update.sh) —
# ten sam wzorzec co w rebuild.sh ([[project_docker_scripts_reorg]]).
if [[ -f "${SCRIPT_DIR}/docker-compose.yml" ]]; then
    COMPOSE_DIR="$SCRIPT_DIR"
else
    COMPOSE_DIR="$(dirname "$SCRIPT_DIR")"
fi

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

SKIP_TESTY=0
[[ "${1:-}" == "--no-testy" ]] && SKIP_TESTY=1

# ── Funkcja: aktualizuj jedno repo ────────────────────────────────────────────
update_repo() {
    local dir="$1"
    local label="$2"

    if [[ ! -d "${dir}/.git" ]]; then
        warn "Brak repo git w ${dir} — pomijam ${label}"
        return 1
    fi

    local before after
    before=$(git -C "${dir}" rev-parse HEAD)

    # Próba fast-forward; przy rozbieżnych gałęziach — merge
    if ! git -C "${dir}" pull --ff-only origin main 2>&1 | sed 's/^/    /'; then
        warn "${label}: fast-forward niemożliwy — próbuję merge..."
        git -C "${dir}" pull --no-rebase origin main 2>&1 | sed 's/^/    /'
    fi

    after=$(git -C "${dir}" rev-parse HEAD)

    if [[ "$before" == "$after" ]]; then
        ok "${label}: już aktualny (${after:0:7})"
        return 0
    fi

    ok "${label}: zaktualizowany ${before:0:7} → ${after:0:7}"
    git -C "${dir}" log --oneline "${before}..${after}" | sed 's/^/    /'
    return 0
}

# ── Funkcja: sprawdź i odnów certyfikat w kontenerze ─────────────────────────
renew_cert() {
    local container="$1"
    local label="$2"

    if ! docker inspect "${container}" &>/dev/null || \
       [[ "$(docker inspect "${container}" --format '{{.State.Status}}')" != "running" ]]; then
        return
    fi

    local days
    days=$(docker exec "${container}" php -r "
        \$f = '/var/www/html/certs/app.crt';
        if (!file_exists(\$f)) { echo -999; exit; }
        \$p = openssl_x509_parse(file_get_contents(\$f));
        if (!\$p) { echo -999; exit; }
        echo (int)ceil((\$p['validTo_time_t'] - time()) / 86400);
    " 2>/dev/null) || days=-999

    if [[ "${days}" -eq -999 ]]; then
        warn "${label}: brak certyfikatu — generuję..."
        docker exec "${container}" php /var/www/html/cli/generatorCertyfikatu.php \
            2>&1 | grep -E "\[OK\]|\[INFO\]|\[BLAD\]" | sed 's/^/    /'
        ok "${label}: certyfikat wygenerowany"
    elif [[ "${days}" -le 0 ]]; then
        warn "${label}: certyfikat wygasł ${days#-} dni temu — regeneruję..."
        docker exec "${container}" php /var/www/html/cli/generatorCertyfikatu.php \
            2>&1 | grep -E "\[OK\]|\[INFO\]|\[BLAD\]" | sed 's/^/    /'
        ok "${label}: certyfikat odnowiony"
    elif [[ "${days}" -le 7 ]]; then
        warn "${label}: certyfikat wygasa za ${days} dni — odnawiam prewencyjnie..."
        docker exec "${container}" php /var/www/html/cli/generatorCertyfikatu.php \
            2>&1 | grep -E "\[OK\]|\[INFO\]|\[BLAD\]" | sed 's/^/    /'
        ok "${label}: certyfikat odnowiony"
    else
        ok "${label}: certyfikat OK (${days} dni)"
    fi
}

# ── Funkcja: uruchom migracje schematu bazy w kontenerze ─────────────────────
run_migrations() {
    local container="$1"
    local label="$2"

    if ! docker inspect "${container}" &>/dev/null || \
       [[ "$(docker inspect "${container}" --format '{{.State.Status}}' 2>/dev/null)" != "running" ]]; then
        return
    fi

    info "${label}: migracje schematu bazy..."
    if docker exec "${container}" php /var/www/html/cli/migrate.php 2>&1 | sed 's/^/    /'; then
        ok "${label}: migracje wykonane"
    else
        warn "${label}: migracje zgłosiły błędy — sprawdź wyjście wyżej"
    fi
}

# ── Funkcja: restart Apache + weryfikacja ─────────────────────────────────────
reload_container() {
    local container="$1"
    local label="$2"

    if ! docker inspect "${container}" &>/dev/null; then
        warn "${label}: kontener nie istnieje — pomijam"
        return
    fi
    if [[ "$(docker inspect "${container}" --format '{{.State.Status}}')" != "running" ]]; then
        warn "${label}: kontener nie działa — pomijam"
        return
    fi

    # apache2ctl restart = SIGHUP do głównego procesu Apache → reinicjalizuje
    # moduł PHP (php_module_shutdown + php_module_init) → nowy segment SHM OPcache.
    # NIE używamy: kill -HUP 1 (PID 1 w Docker to często entrypoint, nie Apache).
    info "${label}: restart Apache (czyści OPcache)..."
    if docker exec "${container}" apache2ctl restart 2>/dev/null; then
        : # ok
    elif docker exec "${container}" service apache2 restart 2>/dev/null; then
        : # ok (fallback dla starszych obrazów)
    else
        # Ostateczność: wyślij SIGHUP bezpośrednio do procesu apache2
        docker exec "${container}" bash -c \
            'kill -HUP $(cat /var/run/apache2/apache2.pid 2>/dev/null || pgrep -x apache2 | head -1) 2>/dev/null' \
            || true
    fi

    # Krótkie oczekiwanie na przejście Apache + weryfikacja procesu
    sleep 2
    if docker exec "${container}" pgrep -x apache2 &>/dev/null; then
        ok "${label}: Apache aktywny, OPcache wyczyszczony"
    else
        warn "${label}: Apache może nie odpowiadać — sprawdź ręcznie: docker exec ${container} apache2ctl status"
    fi
}

# ── Funkcja: przebuduj i zrestartuj kursant-ui (Angular, statyczny build) ────
# git pull (krok 1) tylko aktualizuje źródła kursantApp/ na hoście — w
# odróżnieniu od app (PHP interpretowany na żywo z bind-mounta), kursant-ui
# serwuje przez nginx prebuildowany dist/ zapieczony w obrazie przy `docker
# compose build`. Bez tego kroku zmiany w kursantApp/ nigdy nie trafią na
# serwer, mimo zaktualizowanego repo.
update_kursant_ui() {
    local container="feer-kursant-ui"

    if ! docker inspect "${container}" &>/dev/null; then
        info "kursant-ui: kontener nie istnieje — pomijam (pierwsze wdrożenie: docker/rebuild.sh --kursant)"
        return
    fi

    local env_file="${COMPOSE_DIR}/.env.prod"
    if [[ ! -f "${env_file}" ]]; then
        warn "kursant-ui: brak ${env_file} — pomijam rebuild"
        return
    fi

    local compose="docker compose \
        -f ${COMPOSE_DIR}/docker-compose.yml \
        -f ${COMPOSE_DIR}/docker-compose.prod.yml \
        -f ${COMPOSE_DIR}/docker-compose.kursant.yml \
        --env-file ${env_file}"

    info "kursant-ui: przebudowuję obraz (ng build --configuration production)..."
    if ${compose} build kursant-ui 2>&1 | sed 's/^/    /'; then
        ${compose} up -d --no-deps kursant-ui 2>&1 | sed 's/^/    /'
        ok "kursant-ui: zaktualizowany i zrestartowany"
    else
        warn "kursant-ui: build nie powiódł się — sprawdź wyjście wyżej"
    fi
}

# ══════════════════════════════════════════════════════════════════════════════

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║   FEER SZO — aktualizacja kodu                          ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

# ── 1. Produkcja ──────────────────────────────────────────────────────────────
section "1. Produkcja — git pull"
update_repo "${PROD_DIR}" "prod"

section "2. Produkcja — migracje + reload + certyfikat"
run_migrations   "${PROD_CONTAINER}" "feer-app"
reload_container "${PROD_CONTAINER}" "feer-app"
renew_cert       "${PROD_CONTAINER}" "feer-app"

section "3. Panel kursanta (Angular) — rebuild + restart"
update_kursant_ui

# ── 4. Środowisko testowe (opcjonalne) ────────────────────────────────────────
if [[ $SKIP_TESTY -eq 1 ]]; then
    warn "Środowisko testowe pominięte (--no-testy)"
elif ! docker inspect "${TEST_CONTAINER}" &>/dev/null || \
     [[ "$(docker inspect "${TEST_CONTAINER}" --format '{{.State.Status}}' 2>/dev/null)" != "running" ]]; then
    info "Środowisko testowe nie działa — pomijam"
else
    section "4. Środowisko testowe — git pull"
    if [[ -d "${TESTY_DIR}/.git" ]]; then
        update_repo "${TESTY_DIR}" "testy"
    else
        warn "${TESTY_DIR} nie istnieje — pomiń lub uruchom setup-testy.sh"
    fi

    section "5. Środowisko testowe — migracje + reload + certyfikat"
    run_migrations   "${TEST_CONTAINER}" "feer-testy-app"
    reload_container "${TEST_CONTAINER}" "feer-testy-app"
    renew_cert       "${TEST_CONTAINER}" "feer-testy-app"
fi

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
ok "Aktualizacja zakończona — $(date '+%H:%M:%S')"
echo ""
