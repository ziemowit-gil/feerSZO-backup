#!/usr/bin/env bash
# setup-owncloud.sh — Wdrożenie ownCloud (magazyn plików lekcji TI) OBOK już
# działającego środowiska FEER SZO (feer-app + feer-traefik) na tym samym
# serwerze/hoście Docker.
#
# Uruchom z katalogu docker/ na serwerze, tam gdzie leży .env.prod:
#   cd /opt/feer-szo/docker && bash setup-owncloud.sh [domena] [opcje]
#
# Domyślna domena: owncloud.feer.org.pl (można nadpisać argumentem).
#
# Opcje:
#   --reset-admin-password   Wygeneruj NOWE hasło administratora ownCloud
#                             (nadpisuje istniejące w .env.prod i na żywym
#                             koncie przez occ user:resetpassword) — jedyny
#                             krok, który celowo NIE jest idempotentny.
#   --app-container=NAZWA    Kontener aplikacji FEER SZO do automatycznej
#                             konfiguracji (domyślnie: feer-app). Ustaw np.
#                             feer-testy-app, żeby skonfigurować środowisko
#                             testowe zamiast produkcji.
#   --reconfigure             NIEODWRACALNE: usuwa kontener ownCloud I jego
#                             wolumin (WSZYSTKIE pliki, konta, ustawienia)
#                             i instaluje od zera. Wymaga interaktywnego
#                             potwierdzenia (wpisanie hasła kontrolnego) —
#                             odmawia działania bez terminala. Tworzy kopię
#                             zapasową woluminu przed usunięciem (docker/backups/),
#                             ale to i tak ostatnia deska ratunku, nie substytut
#                             realnego backupu. Aplikacji FEER SZO (domyślnie
#                             feer-app) nie dotyka.
#
# Co robi:
#   1. Sprawdza, że główny stack (feer-traefik) już działa — NIE startuje go
#      od zera, dokłada się do niego.
#   2. Dopisuje brakujące zmienne OWNCLOUD_* do istniejącego .env.prod
#      (generuje hasło admina, jeśli jeszcze go nie ma — nie nadpisuje istniejących,
#      chyba że podano --reset-admin-password).
#   3. Sprawdza DNS domeny.
#   4. `docker compose up -d` na docker-compose.yml + prod.yml + owncloud.yml.
#   5. Czeka aż kontener ownCloud wstanie.
#   6. Tworzy dedykowane konto integracyjne (occ user:add) — TYM kontem/hasłem
#      (nie danymi admina) aplikacja łączy się przez WebDAV.
#   7. Konfiguruje WSZYSTKO automatycznie w aplikacji FEER SZO (kontener
#      $APP_CONTAINER, cli/owncloud_reconfigure.php) — URL, konto administratora
#      (do kont „Mój dysk” kursantów/prowadzących) i — gdy świeżo utworzone —
#      konto integracyjne. Bez tego kroku trzeba było wklejać dane ręcznie
#      w admin/owncloud_settings.php.
#
# Bezpieczne do wielokrotnego uruchamiania (idempotentne — nie nadpisuje
# istniejących sekretów, nie tworzy konta integracyjnego drugi raz) — z
# wyjątkiem jawnie podanego --reset-admin-password.

set -euo pipefail

# ── Konfiguracja ───────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# docker-compose.yml może być obok skryptu (uruchomiony przez symlink
# docker/*.sh) albo jeden poziom wyżej (docker/scripts/*.sh) —
# sprawdzamy, gdzie faktycznie jest ([[project_docker_scripts_reorg]]).
if [[ -f "${SCRIPT_DIR}/docker-compose.yml" ]]; then
    COMPOSE_DIR="$SCRIPT_DIR"
else
    COMPOSE_DIR="$(dirname "$SCRIPT_DIR")"
fi
ENV_FILE="${COMPOSE_DIR}/.env.prod"
OC_CONTAINER="feer-owncloud"
INTEGRATION_USER="feerszo-integracja"
APP_CONTAINER="feer-app"
RESET_ADMIN_PASSWORD=0
REINSTALL_FROM_SCRATCH=0
OC_DOMAIN=""

for arg in "$@"; do
    case "${arg}" in
        --reset-admin-password) RESET_ADMIN_PASSWORD=1 ;;
        --reconfigure)          REINSTALL_FROM_SCRATCH=1 ;;
        --app-container=*)      APP_CONTAINER="${arg#*=}" ;;
        --*)                    echo "Nieznana opcja: ${arg}" >&2; exit 1 ;;
        *)                      OC_DOMAIN="${arg}" ;;
    esac
done
OC_DOMAIN="${OC_DOMAIN:-owncloud.feer.org.pl}"

COMPOSE_FILES=(-f "${COMPOSE_DIR}/docker-compose.yml" -f "${COMPOSE_DIR}/docker-compose.prod.yml" -f "${COMPOSE_DIR}/docker-compose.owncloud.yml")

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
[[ -f "${COMPOSE_DIR}/docker-compose.owncloud.yml" ]] || die "Brak pliku: docker-compose.owncloud.yml"
[[ -f "${ENV_FILE}" ]] || die "Brak ${ENV_FILE} — najpierw uruchom główny stack (zob. DEPLOY.md)."

if ! docker inspect feer-traefik &>/dev/null; then
    die "Kontener feer-traefik nie istnieje. Ten skrypt dokłada ownCloud do już\n      działającego stacku — uruchom go najpierw (DEPLOY.md), a potem wróć tutaj."
fi
[[ "$(docker inspect feer-traefik --format '{{.State.Status}}')" == "running" ]] \
    || die "Kontener feer-traefik nie działa. Sprawdź: docker logs feer-traefik"
ok "feer-traefik działa — ownCloud dołączy do tej samej sieci/Traefika"

# ── 1b. Reinstalacja od zera (--reconfigure) ─────────────────────────────────
if [[ "${REINSTALL_FROM_SCRATCH}" -eq 1 ]]; then
    section "1b. Reinstalacja od zera (--reconfigure)"

    VOL_NAMES=""
    if docker inspect "${OC_CONTAINER}" &>/dev/null; then
        VOL_NAMES="$(docker inspect "${OC_CONTAINER}" --format '{{ range .Mounts }}{{ if eq .Type "volume" }}{{ .Name }} {{ end }}{{ end }}')"
    fi

    echo -e "${RED}${BOLD}  UWAGA — TRWAŁE USUNIĘCIE DANYCH${RESET}"
    if [[ -n "${VOL_NAMES}" ]]; then
        echo -e "${RED}  Zostanie usunięty kontener „${OC_CONTAINER}” oraz wolumin(y):${RESET}"
        for v in ${VOL_NAMES}; do echo -e "${RED}    - ${v}${RESET}"; done
        echo -e "${RED}  To znaczy WSZYSTKIE pliki, konta i ustawienia wewnątrz ownCloud —${RESET}"
        echo -e "${RED}  nieodwracalnie (poza kopią zapasową tworzoną poniżej).${RESET}"
    else
        echo -e "${YELLOW}  Kontener „${OC_CONTAINER}” jeszcze nie istnieje — nie ma czego usuwać,${RESET}"
        echo -e "${YELLOW}  ten krok od razu przejdzie do świeżej instalacji.${RESET}"
    fi
    echo -e "${YELLOW}  Kontener i dane aplikacji FEER SZO (${APP_CONTAINER}) NIE są ruszane.${RESET}"
    echo ""

    if [[ -n "${VOL_NAMES}" ]]; then
        if [[ ! -t 0 ]]; then
            die "Brak terminala interaktywnego — --reconfigure wymaga ręcznego potwierdzenia. Uruchom skrypt bezpośrednio w terminalu (nie przez potok/cron)."
        fi
        read -r -p "  Wpisz dokładnie „usuń dane ownCloud” aby kontynuować: " CONFIRM
        [[ "${CONFIRM}" == "usuń dane ownCloud" ]] || die "Potwierdzenie się nie zgadza — przerwano bez żadnych zmian."

        BACKUP_DIR="${COMPOSE_DIR}/backups"
        mkdir -p "${BACKUP_DIR}"
        for v in ${VOL_NAMES}; do
            BACKUP_FILE="${BACKUP_DIR}/${v}_$(date +%Y%m%d_%H%M%S).tar.gz"
            info "Kopia zapasowa woluminu „${v}” → ${BACKUP_FILE}"
            if docker run --rm -v "${v}:/data:ro" -v "${BACKUP_DIR}:/backup" alpine \
                sh -c "tar czf /backup/$(basename "${BACKUP_FILE}") -C /data ." 2>/dev/null; then
                ok "Zapisano ($(du -h "${BACKUP_FILE}" 2>/dev/null | cut -f1 || echo '?'))"
            else
                warn "Kopia zapasowa woluminu „${v}” nie powiodła się — kontynuuję mimo to (jawnie zażądano --reconfigure)."
            fi
        done

        info "Usuwanie kontenera „${OC_CONTAINER}”..."
        docker stop "${OC_CONTAINER}" &>/dev/null || true
        docker rm "${OC_CONTAINER}" &>/dev/null || true
        for v in ${VOL_NAMES}; do
            if docker volume rm "${v}" &>/dev/null; then
                ok "Wolumin „${v}” usunięty"
            else
                warn "Nie udało się usunąć woluminu „${v}” (może być w użyciu — sprawdź ręcznie: docker volume rm ${v})"
            fi
        done
    fi
    ok "Gotowe do reinstalacji — kolejne kroki utworzą ownCloud od zera."
fi

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

# Odczyt konkretnych wartości z .env.prod BEZ source'owania całego pliku —
# .env.prod to format docker compose (dopuszcza niecytowane wartości ze
# spacjami, np. ORG_NAME=Fundacja Edukacji...), a `source` wykonałby taką
# linię jako polecenie powłoki (błąd: "Edukacji: command not found").
read_env_var() {
    grep -m1 "^${1}=" "${ENV_FILE}" | cut -d'=' -f2-
}
set_env_var() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "${ENV_FILE}"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "${ENV_FILE}"
    else
        echo "${key}=${value}" >> "${ENV_FILE}"
    fi
    chmod 600 "${ENV_FILE}"
}
OWNCLOUD_DOMAIN="$(read_env_var OWNCLOUD_DOMAIN)"
OWNCLOUD_ADMIN_USERNAME="$(read_env_var OWNCLOUD_ADMIN_USERNAME)"
OWNCLOUD_ADMIN_PASSWORD="$(read_env_var OWNCLOUD_ADMIN_PASSWORD)"

if [[ "${RESET_ADMIN_PASSWORD}" -eq 1 ]]; then
    OWNCLOUD_ADMIN_PASSWORD="$(openssl rand -hex 16)"
    set_env_var "OWNCLOUD_ADMIN_PASSWORD" "${OWNCLOUD_ADMIN_PASSWORD}"
    ok "Wygenerowano nowe hasło administratora (zapisane w ${ENV_FILE})"
    # Jeśli ownCloud już działa i jest zainstalowany, zastosuj hasło na żywym
    # koncie od razu — inaczej wpis w .env.prod ma znaczenie tylko przy
    # pierwszej instalacji kontenera (świeży wolumin), nie przy istniejącej.
    if docker inspect "${OC_CONTAINER}" &>/dev/null \
       && [[ "$(docker inspect "${OC_CONTAINER}" --format '{{.State.Status}}')" == "running" ]] \
       && docker exec -u www-data "${OC_CONTAINER}" occ user:list --output=json 2>/dev/null | grep -q "\"${OWNCLOUD_ADMIN_USERNAME}\""; then
        if docker exec -u www-data -e OC_PASS="${OWNCLOUD_ADMIN_PASSWORD}" "${OC_CONTAINER}" \
            occ user:resetpassword --password-from-env "${OWNCLOUD_ADMIN_USERNAME}" 2>/dev/null; then
            ok "Hasło administratora zastosowane na żywym koncie „${OWNCLOUD_ADMIN_USERNAME}”"
        else
            warn "Nie udało się zastosować hasła przez occ — zresetuj ręcznie: docker exec -it -u www-data ${OC_CONTAINER} occ user:resetpassword ${OWNCLOUD_ADMIN_USERNAME}"
        fi
    else
        info "Kontener ownCloud jeszcze nie działa — nowe hasło zostanie użyte przy pierwszej instalacji (krok 4)."
    fi
fi

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

# ── 7. Konfiguracja w aplikacji FEER SZO ─────────────────────────────────────
section "7. Konfiguracja automatyczna w aplikacji FEER SZO (${APP_CONTAINER})"

APP_CONFIGURED=0
if ! docker inspect "${APP_CONTAINER}" &>/dev/null; then
    warn "Kontener „${APP_CONTAINER}” nie istnieje — pomijam automatyczną konfigurację."
    info "Podaj właściwy kontener przez --app-container=NAZWA, jeśli aplikacja nazywa się inaczej."
elif [[ "$(docker inspect "${APP_CONTAINER}" --format '{{.State.Status}}')" != "running" ]]; then
    warn "Kontener „${APP_CONTAINER}” nie działa — pomijam automatyczną konfigurację."
else
    OC_RECONFIGURE_ARGS=(--url="https://${OWNCLOUD_DOMAIN}" --enabled=1
        --admin-username="${OWNCLOUD_ADMIN_USERNAME}" --admin-password="${OWNCLOUD_ADMIN_PASSWORD}")

    # Konto integracyjne (główne, WebDAV) da się wpisać automatycznie tylko
    # gdy hasło zostało świeżo wygenerowane w tym uruchomieniu (krok 6) —
    # ownCloud nie pozwala odczytać hasła istniejącego konta.
    if [[ "${INTEGRATION_PASS}" != "(bez zmian"* && "${INTEGRATION_PASS}" != "(utwórz ręcznie)" ]]; then
        OC_RECONFIGURE_ARGS+=(--username="${INTEGRATION_USER}" --password="${INTEGRATION_PASS}")
    fi

    if docker exec "${APP_CONTAINER}" php cli/owncloud_reconfigure.php "${OC_RECONFIGURE_ARGS[@]}" &>/dev/null; then
        ok "Ustawienia zapisane w aplikacji (settings: owncloud_*)"
        APP_CONFIGURED=1
        docker exec "${APP_CONTAINER}" php cli/owncloud_reconfigure.php --test || true
    else
        warn "Nie udało się zapisać ustawień w aplikacji — sprawdź: docker exec -it ${APP_CONTAINER} php cli/owncloud_reconfigure.php --status"
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
if [[ "${APP_CONFIGURED}" -eq 1 ]]; then
    echo -e "  ${GREEN}${BOLD}Aplikacja FEER SZO skonfigurowana automatycznie${RESET} (kontener ${APP_CONTAINER})."
    echo -e "  Sprawdź wynik testu połączenia powyżej. W razie potrzeby: Admin → Integracje →"
    echo -e "  Magazyn plików / ownCloud (${BOLD}admin/owncloud_settings.php${RESET})."
else
    echo -e "  ${YELLOW}Automatyczna konfiguracja pominięta — uzupełnij ręcznie:${RESET}"
    echo -e "  Admin → Integracje → Magazyn plików / ownCloud (${BOLD}admin/owncloud_settings.php${RESET}):"
    echo -e "    URL:              ${CYAN}https://${OWNCLOUD_DOMAIN}${RESET}"
    echo -e "    Login:            ${CYAN}${INTEGRATION_USER}${RESET}"
    echo -e "    Hasło:            ${CYAN}${INTEGRATION_PASS}${RESET}"
    echo -e "    Login admina:     ${CYAN}${OWNCLOUD_ADMIN_USERNAME}${RESET}"
    echo -e "    Hasło admina:     ${CYAN}(zob. ${ENV_FILE})${RESET}"
    echo -e "  a następnie kliknij „Testuj połączenie” i zapisz."
fi
echo ""
echo -e "  ${BOLD}Ponowne uruchomienie tego skryptu jest bezpieczne${RESET} (nie nadpisuje sekretów,"
echo -e "  nie tworzy konta integracyjnego drugi raz) — chyba że podasz --reset-admin-password."
echo ""
