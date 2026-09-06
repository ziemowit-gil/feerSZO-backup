#!/usr/bin/env bash
# fix-env-prod.sh — wykrywa i (opcjonalnie) naprawia uszkodzone linie w .env.prod
#
# Typowa przyczyna uszkodzenia: ręczne wklejenie wartości (hasła, sekretu)
# zawierającej osadzony znak nowej linii do edytora/terminala — rozbija to
# jedną linię KLUCZ=WARTOŚĆ na kilka linii-śmieci, a docker compose potem
# odrzuca CAŁY plik błędem w stylu:
#   "unexpected character '~' in variable name ..."
#
# Uruchom z katalogu docker/ na serwerze, tam gdzie leży .env.prod:
#   cd /opt/feer-szo/docker && bash fix-env-prod.sh          # tylko raport
#   cd /opt/feer-szo/docker && bash fix-env-prod.sh --fix    # naprawia
#
# Co robi tryb raportu (domyślny, bez żadnych zmian w pliku):
#   - Wypisuje każdą linię, która NIE jest: pusta, komentarzem (#...),
#     poprawnym KLUCZ=WARTOŚĆ, ani kontynuacją wartości otwartej cudzysłowem
#     na wcześniejszej linii ("linia-śmieć" — najpewniej fragment rozbitego
#     sekretu).
#   - Ostrzega o duplikatach klucza (ten sam KLUCZ= występuje >1 raz) —
#     tego NIE naprawia automatycznie, bo wybór który duplikat zachować
#     wymaga wiedzy, która wartość jest aktualna.
#
# Co robi tryb --fix (dodatkowo):
#   - Kopia zapasowa: .env.prod.bak-RRRRMMDD_GGMMSS (zawsze, przed zapisem).
#   - Skleja wartości rozbite przez niezamknięty cudzysłów (KLUCZ='... bez
#     zamykającego ' w tej samej linii) w jedną linię.
#   - Usuwa linie-śmieci (te z raportu powyżej) — NIE dotyka duplikatów
#     klucza, tylko je nadal zgłasza do ręcznej decyzji.
#   - Na końcu waliduje wynik przez `docker compose ... config --quiet`
#     (jeśli docker jest dostępny) i mówi, czy plik jest teraz poprawny.
#
# Bezpieczne do wielokrotnego uruchamiania — bez --fix nic nie zmienia.

set -euo pipefail

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
DO_FIX=0
[[ "${1:-}" == "--fix" ]] && DO_FIX=1

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}  ▸ $*${RESET}"; }
ok()      { echo -e "${GREEN}  ✔ $*${RESET}"; }
warn()    { echo -e "${YELLOW}  ⚠ $*${RESET}"; }
die()     { echo -e "${RED}  ✖ $*${RESET}" >&2; exit 1; }
section() { echo -e "\n${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

[[ -f "${ENV_FILE}" ]] || die "Brak pliku: ${ENV_FILE}"

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}║   FEER — kontrola .env.prod                              ║${RESET}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════╝${RESET}"

section "1. Analiza pliku"

# Przetwarza .env.prod jednym przejściem AWK: skleja wartości rozbite przez
# niezamknięty cudzysłów, oznacza linie-śmieci znacznikiem __ORPHAN__ (żeby
# powłoka mogła je policzyć/wypisać osobno), liczy duplikaty klucza.
AWK_OUT="$(awk '
    function is_kv(l) { return (l ~ /^[A-Za-z_][A-Za-z0-9_]*=/) }
    function key_of(l,   k) { k = l; sub(/=.*/, "", k); return k }
    BEGIN { pending = "" }
    {
        line = $0
        if (pending != "") {
            # Sklej BEZ separatora — chcemy odtworzyć jedną fizyczną linię,
            # nie wstawić z powrotem tego samego znaku nowej linii, który
            # rozbił wartość (print pending z "\n" w środku dalej wyglądałby
            # jak dwie linie w pliku wynikowym).
            pending = pending line
            if (line ~ /'"'"'$/) { print pending; keys[key_of(pending)]++; pending = "" }
            next
        }
        if (line ~ /^[[:space:]]*$/ || line ~ /^[[:space:]]*#/) { print line; next }
        if (is_kv(line)) {
            val = line; sub(/^[A-Za-z_][A-Za-z0-9_]*=/, "", val)
            # Otwierający cudzysłów bez zamykającego — uwaga: linia z SAMYM
            # znakiem otwierającego cudzysłowu ma długość 1, więc ten znak
            # jest jednocześnie pierwszym i ostatnim — trzeba to rozstrzygnąć
            # długością, inaczej wygląda jak już zamknięta.
            if (val ~ /^'"'"'/ && (length(val) < 2 || substr(val, length(val), 1) != "'"'"'")) { pending = line; next }
            print line; keys[key_of(line)]++
            next
        }
        print "__ORPHAN__" line
    }
    END {
        for (k in keys) if (keys[k] > 1) print "__DUP__" k "=" keys[k] > "/dev/stderr"
    }
' "${ENV_FILE}" 2>"${SCRIPT_DIR}/.fix-env-dup.tmp")"

ORPHANS="$(echo "${AWK_OUT}" | grep '^__ORPHAN__' | sed 's/^__ORPHAN__//' || true)"
CLEAN="$(echo "${AWK_OUT}" | grep -v '^__ORPHAN__' || true)"
DUPS="$(cat "${SCRIPT_DIR}/.fix-env-dup.tmp" 2>/dev/null | sed 's/^__DUP__//' || true)"
rm -f "${SCRIPT_DIR}/.fix-env-dup.tmp"

ORPHAN_COUNT=0
if [[ -n "${ORPHANS}" ]]; then
    ORPHAN_COUNT="$(echo "${ORPHANS}" | grep -c '.' || true)"
    warn "Znaleziono ${ORPHAN_COUNT} linii-śmieci (nie pasują do KLUCZ=WARTOŚĆ):"
    echo "${ORPHANS}" | sed 's/^/      /'
else
    ok "Brak linii-śmieci."
fi

if [[ -n "${DUPS}" ]]; then
    warn "Zduplikowane klucze (występują więcej niż raz) — NIE naprawiane automatycznie:"
    echo "${DUPS}" | sed 's/^/      /'
    warn "Sprawdź ręcznie, która wartość jest aktualna, i usuń starszą."
else
    ok "Brak zduplikowanych kluczy."
fi

if [[ "${ORPHAN_COUNT}" -eq 0 && -z "${DUPS}" ]]; then
    echo ""
    ok "Plik wygląda poprawnie — nic do naprawienia."
    exit 0
fi

if [[ "${DO_FIX}" -eq 0 ]]; then
    echo ""
    info "To był tryb tylko-raport. Żeby naprawić linie-śmieci, uruchom:"
    echo -e "  ${CYAN}bash fix-env-prod.sh --fix${RESET}"
    exit 0
fi

section "2. Naprawa (--fix)"

BACKUP="${ENV_FILE}.bak-$(date '+%Y%m%d_%H%M%S')"
cp "${ENV_FILE}" "${BACKUP}"
ok "Kopia zapasowa: ${BACKUP}"

echo "${CLEAN}" > "${ENV_FILE}"
chmod 600 "${ENV_FILE}"
ok "Usunięto ${ORPHAN_COUNT} linii-śmieci, sklejono rozbite wartości."

if [[ -n "${DUPS}" ]]; then
    warn "Duplikaty kluczy NADAL w pliku — popraw je ręcznie (patrz lista wyżej)."
fi

section "3. Walidacja"

if command -v docker &>/dev/null; then
    COMPOSE_ARGS=(-f "${COMPOSE_DIR}/docker-compose.yml")
    [[ -f "${COMPOSE_DIR}/docker-compose.prod.yml" ]]     && COMPOSE_ARGS+=(-f "${COMPOSE_DIR}/docker-compose.prod.yml")
    [[ -f "${COMPOSE_DIR}/docker-compose.owncloud.yml" ]] && COMPOSE_ARGS+=(-f "${COMPOSE_DIR}/docker-compose.owncloud.yml")
    if docker compose "${COMPOSE_ARGS[@]}" --env-file "${ENV_FILE}" config --quiet 2>/tmp/fix-env-validate.log; then
        ok "docker compose config — plik jest teraz poprawny."
    else
        warn "docker compose config nadal zgłasza błąd:"
        cat /tmp/fix-env-validate.log | sed 's/^/      /'
        warn "Oryginał jest bezpieczny w: ${BACKUP}"
    fi
    rm -f /tmp/fix-env-validate.log
else
    warn "Docker niedostępny w tej sesji — pomiń walidację, sprawdź ręcznie na serwerze."
fi

echo ""
ok "Gotowe. W razie problemów przywróć kopię: cp ${BACKUP} ${ENV_FILE}"
