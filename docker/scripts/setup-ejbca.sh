#!/usr/bin/env bash
# setup-ejbca.sh — Wdrożenie EJBCA CE (wewnętrzny CA) OBOK już działającego
# środowiska FEER SZO (feer-app + feer-traefik) na tym samym serwerze/hoście
# Docker.
#
# Uruchom z katalogu docker/ na serwerze, tam gdzie leży .env.prod:
#   cd /opt/feer-szo/docker && bash setup-ejbca.sh [domena]
#
# Domyślna domena: ca.feer.org.pl (można nadpisać argumentem).
#
# Co robi:
#   1. Sprawdza, że główny stack (feer-traefik) już działa — NIE startuje go
#      od zera, dokłada się do niego.
#   2. Dopisuje brakujące zmienne EJBCA_* do istniejącego .env.prod
#      (generuje sekrety, jeśli jeszcze ich nie ma — nie nadpisuje istniejących).
#   3. Otwiera port 8443/tcp w ufw, jeśli ufw jest aktywny.
#   4. `docker compose up -d` na docker-compose.yml + prod.yml + ejbca.yml —
#      to JEDEN projekt compose z już działającym stackiem, więc:
#        - Traefik zostanie zrestartowany (dostanie nowy entrypoint :8443),
#          co oznacza kilka sekund przerwy w dostępności głównej aplikacji.
#        - app/mysql/redis NIE zostaną tknięte (ich config się nie zmienił).
#   5. Czeka aż baza EJBCA i sam EJBCA (WildFly) wstaną.
#   6. Pokazuje ogon logów EJBCA — tam są instrukcje enrollmentu SuperAdmina.
#
# Bezpieczne do wielokrotnego uruchamiania (idempotentne, nie resetuje CA).

set -euo pipefail

# ── Konfiguracja ───────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env.prod"
CA_DOMAIN="${1:-ca.feer.org.pl}"
APP_CONTAINER="feer-ejbca"
DB_CONTAINER="feer-ejbca-db"

COMPOSE_FILES=(-f "${SCRIPT_DIR}/docker-compose.yml" -f "${SCRIPT_DIR}/docker-compose.prod.yml" -f "${SCRIPT_DIR}/docker-compose.ejbca.yml")

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
echo -e "${BOLD}║   FEER — EJBCA CE (wewnętrzny CA)                        ║${RESET}"
echo -e "${BOLD}║   Domena: ${CA_DOMAIN}                          ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

# ── 1. Walidacja ───────────────────────────────────────────────────────────────
section "1. Walidacja środowiska"

command -v docker &>/dev/null || die "Docker nie jest zainstalowany."
[[ -f "${SCRIPT_DIR}/docker-compose.ejbca.yml" ]] || die "Brak pliku: docker-compose.ejbca.yml"
[[ -f "${ENV_FILE}" ]] || die "Brak ${ENV_FILE} — najpierw uruchom główny stack (zob. DEPLOY.md)."

if ! docker inspect feer-traefik &>/dev/null; then
    die "Kontener feer-traefik nie istnieje. Ten skrypt dokłada EJBCA do już\n      działającego stacku — uruchom go najpierw (DEPLOY.md), a potem wróć tutaj."
fi
[[ "$(docker inspect feer-traefik --format '{{.State.Status}}')" == "running" ]] \
    || die "Kontener feer-traefik nie działa. Sprawdź: docker logs feer-traefik"
ok "feer-traefik działa — EJBCA dołączy do tej samej sieci/Traefika"

# ── 2. Sekrety EJBCA w .env.prod ─────────────────────────────────────────────
section "2. Konfiguracja .env.prod"

add_if_missing() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "${ENV_FILE}"; then
        info "${key} już ustawione — bez zmian"
    else
        echo "${key}=${value}" >> "${ENV_FILE}"
        ok "${key} wygenerowane i dopisane"
    fi
}

if ! grep -q "^# ── EJBCA" "${ENV_FILE}"; then
    echo "" >> "${ENV_FILE}"
    echo "# ── EJBCA (wygenerowane przez setup-ejbca.sh) ──────────────────────────────" >> "${ENV_FILE}"
fi

add_if_missing "CA_DOMAIN"                      "${CA_DOMAIN}"
add_if_missing "EJBCA_PASSWORD_ENCRYPTION_KEY"  "$(openssl rand -hex 32)"
add_if_missing "EJBCA_CA_KEYSTOREPASS"          "$(openssl rand -hex 32)"
add_if_missing "EJBCA_DB_PASSWORD"              "$(openssl rand -hex 24)"
add_if_missing "EJBCA_DB_ROOT_PASSWORD"         "$(openssl rand -hex 24)"

chmod 600 "${ENV_FILE}"

# ── 3. Firewall ────────────────────────────────────────────────────────────────
section "3. Firewall"

if command -v ufw &>/dev/null && ufw status | grep -q "Status: active"; then
    ufw allow 8443/tcp comment "EJBCA AdminWeb" &>/dev/null || true
    ok "Port 8443/tcp otwarty w ufw"
else
    warn "ufw nieaktywny lub niedostępny — sprawdź ręcznie, czy port 8443/tcp jest otwarty"
fi

# ── 4. DNS ─────────────────────────────────────────────────────────────────────
section "4. Sprawdzenie DNS"

RESOLVED_IP="$(getent ahosts "${CA_DOMAIN}" 2>/dev/null | awk '{print $1}' | head -1 || true)"
if [[ -z "${RESOLVED_IP}" ]]; then
    warn "${CA_DOMAIN} nie rozwiązuje się jeszcze na żaden adres IP — Let's Encrypt (HTTP-01) nie wystawi certyfikatu, dopóki DNS się nie rozpropaguje"
else
    ok "${CA_DOMAIN} → ${RESOLVED_IP}"
fi

# ── 5. Uruchamianie kontenerów ─────────────────────────────────────────────────
section "5. Uruchamianie kontenerów"

warn "Traefik zostanie zrestartowany (nowy entrypoint :8443) — kilka sekund przerwy dla głównej aplikacji."
info "Start za 5s (Ctrl+C aby przerwać)..."
sleep 5

COMPOSE=(docker compose "${COMPOSE_FILES[@]}" --env-file "${ENV_FILE}")

"${COMPOSE[@]}" up -d --remove-orphans
ok "Kontenery uruchomione/zaktualizowane"

# ── 6. Czekaj na bazę EJBCA ────────────────────────────────────────────────────
section "6. Oczekiwanie na bazę (${DB_CONTAINER})"

for i in $(seq 1 30); do
    STATUS="$(docker inspect "${DB_CONTAINER}" --format '{{.State.Health.Status}}' 2>/dev/null || echo starting)"
    [[ "${STATUS}" == "healthy" ]] && { ok "Baza gotowa po ${i}0s"; break; }
    sleep 10
    if [[ $i -eq 30 ]]; then
        docker logs "${DB_CONTAINER}" --tail=30
        die "Baza EJBCA nie osiągnęła stanu healthy po 5 minutach."
    fi
done

# ── 7. Czekaj na appserver EJBCA ───────────────────────────────────────────────
section "7. Oczekiwanie na EJBCA (WildFly — to może potrwać kilka minut)"

# Sprawdzamy przez `docker logs` (nie `docker exec ... curl`) — obraz EJBCA
# może nie mieć curl, a port TLS bywa otwarty na długo przed pełnym
# wdrożeniem ejbca.ear, więc sam "port odpowiada" to fałszywy sygnał gotowości.
READY=0
for i in $(seq 1 60); do
    if docker logs "${APP_CONTAINER}" 2>&1 | grep -q 'Deployed "ejbca.ear"'; then
        ok "EJBCA wdrożone (WildFly) po $((i * 10))s"
        READY=1
        break
    fi
    sleep 10
done
[[ "${READY}" -eq 1 ]] || warn "EJBCA jeszcze nie zgłosiło pełnego wdrożenia po 10 minutach — to się zdarza przy pierwszym starcie, sprawdź logi (krok niżej)."

# ── 8. Logi — instrukcje enrollmentu SuperAdmina ──────────────────────────────
section "8. Logi EJBCA (szukaj instrukcji SuperAdmin)"

if docker logs "${APP_CONTAINER}" 2>&1 | grep -qi "enrollment url"; then
    docker logs "${APP_CONTAINER}" 2>&1 | grep -i -B2 -A 10 "enrollment url"
elif docker logs "${APP_CONTAINER}" 2>&1 | grep -qi "superadmin"; then
    warn "Appserver jeszcze nie wypisał URL-a enrollmentu — może wciąż kończyć start. Fragment o SuperAdmin:"
    docker logs "${APP_CONTAINER}" 2>&1 | grep -i -B2 -A 40 "superadmin" | head -80
else
    warn "Nie znaleziono jeszcze instrukcji enrollmentu w logach — pokazuję ostatnie 80 linii:"
    docker logs "${APP_CONTAINER}" --tail=80
fi

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${GREEN}║   EJBCA wdrożony obok głównego stacku FEER               ║${RESET}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${BOLD}Public Web / RA / OCSP / CRL:${RESET}  https://${CA_DOMAIN}/"
echo -e "  ${BOLD}AdminWeb (wymaga cert. klienta):${RESET}  https://${CA_DOMAIN}:8443/ejbca/adminweb/"
echo ""
echo -e "  ${YELLOW}Instrukcje enrollmentu SuperAdmina (URL + jednorazowe hasło) są w logu powyżej.${RESET}"
echo -e "  Jeśli ich tam nie ma (appserver jeszcze kończy start), za chwilę:"
echo -e "  ${CYAN}docker logs ${APP_CONTAINER} 2>&1 | grep -i -A 10 'enrollment url'${RESET}"
echo ""
echo -e "  ${BOLD}Dalsze kroki i backup:${RESET}  docker/EJBCA.md"
echo -e "  ${BOLD}Ponowne uruchomienie tego skryptu jest bezpieczne (nie resetuje CA).${RESET}"
echo ""
