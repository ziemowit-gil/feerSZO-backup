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

info "Ładowanie testowego wolontariusza niepełnoletniego (seed_test_minor_volunteer.php)..."
docker exec "${APP_CONTAINER}" \
    php /var/www/html/seed_test_minor_volunteer.php \
    2>&1 | grep -E "Utworzono|Zaktualizowano|BLAD|hasło|e-mail" || true
ok "Wolontariusz testowy załadowany"

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

# ── 12. Uprawnienia plików dla Apache (www-data) ──────────────────────────────
# Schemat/seed/certyfikat tworzone są przez `docker exec` jako root, więc
# umowy.db i pliki certów powstają jako root:root i www-data nie może w nie
# pisać → "attempt to write a readonly database" i błędy CSRF (sesje w bazie).
# Ten krok przywraca własność www-data po wszystkich operacjach root.
section "12. Uprawnienia plików (www-data)"

docker exec "${APP_CONTAINER}" sh -c '
    chown www-data:www-data /var/www/html 2>/dev/null || true
    chmod 775 /var/www/html 2>/dev/null || true
    for f in /var/www/html/umowy.db /var/www/html/umowy.db-wal \
             /var/www/html/umowy.db-shm /var/www/html/umowy.db-journal; do
        [ -e "$f" ] && chown www-data:www-data "$f" && chmod 664 "$f" || true
    done
    [ -d /var/www/html/certs ] && chown -R www-data:www-data /var/www/html/certs || true
' || true
ok "Własność umowy.db i certs przywrócona dla www-data"

# ── 13. Strona z danymi logowania ────────────────────────────────────────────
section "13. Generowanie testy-info.html"

GENERATED_AT=$(date '+%Y-%m-%d %H:%M')
INFO_FILE="${TESTY_DIR}/testy-info.html"

cat > "${INFO_FILE}" <<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>⚠ Środowisko testowe — FEER SZO</title>
  <style>
    :root {
      --bg: #0f1117; --surface: #1a1d27; --border: #2d3148;
      --text: #e2e8f0; --muted: #8892a4;
      --accent: #6366f1; --accent-h: #818cf8;
      --warn: #f59e0b; --warn-bg: #1c1508;
      --green: #22c55e;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, -apple-system, sans-serif;
      background: var(--bg); color: var(--text);
      min-height: 100vh; padding-bottom: 3rem;
    }
    .warn-banner {
      background: var(--warn-bg); border-bottom: 2px solid var(--warn);
      color: var(--warn); text-align: center; padding: .65rem 1rem;
      font-size: .8rem; font-weight: 700; letter-spacing: .08em;
      text-transform: uppercase;
    }
    .container { max-width: 680px; margin: 0 auto; padding: 2.5rem 1.5rem; }
    h1 { font-size: 1.9rem; font-weight: 700; }
    h1 span { display: block; font-size: .95rem; font-weight: 400; color: var(--muted); margin-top: .2rem; }
    .url-card {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: .75rem; padding: 1rem 1.25rem; margin: 1.5rem 0 2rem;
      display: flex; align-items: center; gap: 1rem;
    }
    .url-card a.link {
      color: var(--accent); text-decoration: none;
      font-size: 1.05rem; font-weight: 500; flex: 1;
    }
    .url-card a.link:hover { color: var(--accent-h); }
    .btn-open {
      background: var(--accent); color: #fff; border: none;
      border-radius: .5rem; padding: .45rem .9rem; cursor: pointer;
      font-size: .85rem; font-weight: 500; text-decoration: none;
      white-space: nowrap;
    }
    .btn-open:hover { background: var(--accent-h); }
    h2 {
      font-size: .78rem; font-weight: 700; color: var(--muted);
      text-transform: uppercase; letter-spacing: .1em; margin-bottom: .85rem;
    }
    table {
      width: 100%; border-collapse: collapse;
      background: var(--surface); border: 1px solid var(--border);
      border-radius: .75rem; overflow: hidden; margin-bottom: 1.75rem;
    }
    thead th {
      background: #12151f; padding: .55rem 1rem; text-align: left;
      font-size: .72rem; font-weight: 700; color: var(--muted);
      text-transform: uppercase; letter-spacing: .07em;
    }
    tbody tr { border-top: 1px solid var(--border); }
    tbody td { padding: .7rem 1rem; vertical-align: middle; }
    .badge {
      display: inline-block; padding: .2rem .55rem;
      border-radius: .35rem; font-size: .72rem; font-weight: 700;
    }
    .badge.admin  { background: rgba(99,102,241,.2);  color: #a5b4fc; }
    .badge.editor { background: rgba(14,165,233,.2);  color: #7dd3fc; }
    .badge.viewer { background: rgba(100,116,139,.2); color: #94a3b8; }
    code {
      font-family: 'SF Mono','Fira Code',monospace; font-size: .88rem;
      background: rgba(255,255,255,.06); padding: .15rem .4rem; border-radius: .3rem;
    }
    .cp {
      background: none; border: 1px solid var(--border); color: var(--muted);
      border-radius: .3rem; padding: .12rem .45rem; cursor: pointer;
      font-size: .72rem; margin-left: .35rem; transition: all .15s;
    }
    .cp:hover { border-color: var(--accent); color: var(--accent-h); }
    .cp.ok    { border-color: var(--green);  color: var(--green); }
    .note {
      background: var(--surface); border: 1px solid var(--border);
      border-left: 3px solid var(--accent); border-radius: .5rem;
      padding: .8rem 1rem; font-size: .83rem; color: var(--muted); margin-bottom: 2rem;
      line-height: 1.6;
    }
    .note code { background: rgba(99,102,241,.15); }
    .meta {
      color: var(--muted); font-size: .78rem; margin-top: 2rem;
      padding-top: 1rem; border-top: 1px solid var(--border);
      display: flex; justify-content: space-between; flex-wrap: wrap; gap: .5rem;
    }
  </style>
</head>
<body>
<div class="warn-banner">⚠ środowisko testowe &mdash; nie wprowadzaj danych produkcyjnych</div>
<div class="container">

  <header style="margin-bottom:2rem">
    <h1>FEER SZO <span>Środowisko testowe</span></h1>
  </header>

  <div class="url-card">
    <a class="link" href="https://${DOMAIN}" target="_blank">https://${DOMAIN}</a>
    <a class="btn-open" href="https://${DOMAIN}" target="_blank">Otwórz →</a>
  </div>

  <h2>Konta testowe</h2>
  <table>
    <thead>
      <tr><th>Rola</th><th>E-mail</th><th>Hasło</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><span class="badge admin">Admin</span></td>
        <td><code>${ADMIN_EMAIL}</code><button class="cp" onclick="cp(this,'${ADMIN_EMAIL}')">kopiuj</button></td>
        <td><code>${ADMIN_PASS}</code><button class="cp" onclick="cp(this,'${ADMIN_PASS}')">kopiuj</button></td>
      </tr>
      <tr>
        <td><span class="badge editor">Lider</span></td>
        <td><code>leader.test@feer.test</code><button class="cp" onclick="cp(this,'leader.test@feer.test')">kopiuj</button></td>
        <td><code>Leader99!</code><button class="cp" onclick="cp(this,'Leader99!')">kopiuj</button></td>
      </tr>
      <tr>
        <td><span class="badge viewer">Wolontariusz</span></td>
        <td><code>vol.test@feer.test</code><button class="cp" onclick="cp(this,'vol.test@feer.test')">kopiuj</button></td>
        <td><code>Test1234!</code><button class="cp" onclick="cp(this,'Test1234!')">kopiuj</button></td>
      </tr>
      <tr>
        <td><span class="badge viewer">Obserwator</span></td>
        <td><code>viewer.test@feer.test</code><button class="cp" onclick="cp(this,'viewer.test@feer.test')">kopiuj</button></td>
        <td><code>View5678!</code><button class="cp" onclick="cp(this,'View5678!')">kopiuj</button></td>
      </tr>
      <tr>
        <td><span class="badge viewer">Wolont. niepełnoletni</span></td>
        <td><code>test.wolontariusz.mlodociany@example.com</code><button class="cp" onclick="cp(this,'test.wolontariusz.mlodociany@example.com')">kopiuj</button></td>
        <td><code>Test1234!</code><button class="cp" onclick="cp(this,'Test1234!')">kopiuj</button></td>
      </tr>
      <tr>
        <td><span class="badge viewer">Opiekun (przedstawiciel)</span></td>
        <td><code>test.opiekun@example.com</code><button class="cp" onclick="cp(this,'test.opiekun@example.com')">kopiuj</button></td>
        <td><code>Test1234!</code><button class="cp" onclick="cp(this,'Test1234!')">kopiuj</button></td>
      </tr>
    </tbody>
  </table>

  <div class="note">
    Dane testowe są resetowane przy każdym uruchomieniu skryptu:<br>
    <code>bash /opt/feer-szo/docker/setup-testy.sh</code>
  </div>

  <div class="meta">
    <span>Wygenerowano: ${GENERATED_AT}</span>
    <span>FEER SZO &mdash; środowisko testowe</span>
  </div>

</div>
<script>
  function cp(btn, text) {
    navigator.clipboard.writeText(text).then(function() {
      btn.textContent = '✓ ok';
      btn.classList.add('ok');
      setTimeout(function() { btn.textContent = 'kopiuj'; btn.classList.remove('ok'); }, 2000);
    }).catch(function() {
      btn.textContent = 'błąd';
      setTimeout(function() { btn.textContent = 'kopiuj'; }, 2000);
    });
  }
</script>
</body>
</html>
HTML

# Uprawnienia — Apache (www-data) musi móc odczytać plik
chmod 644 "${INFO_FILE}"
ok "Strona wygenerowana: https://${DOMAIN}/testy-info.html"

# ── 14. Cron — automatyczne czyszczenie co 7 dni ─────────────────────────────
section "14. Cron (reset co 7 dni)"

CRON_LOG="/var/log/feer_testy_reset.log"
CRON_JOB="0 3 * * 0  bash ${SCRIPT_DIR}/setup-testy.sh >> ${CRON_LOG} 2>&1"
CRON_MARKER="# feer-testy-weekly-reset"

# Sprawdź czy wpis już istnieje
if crontab -l 2>/dev/null | grep -qF "setup-testy.sh"; then
    ok "Cron już skonfigurowany (bez zmian)"
else
    # Dodaj do crontaba roota
    ( crontab -l 2>/dev/null; echo "${CRON_MARKER}"; echo "${CRON_JOB}" ) | crontab -
    ok "Cron dodany: niedziela 03:00 → setup-testy.sh"
fi

info "Log cyklu: ${CRON_LOG}"

# ── 15. Status kontenerów ─────────────────────────────────────────────────────
section "15. Status"
${COMPOSE} ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"

# ── Podsumowanie ──────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${GREEN}║   Środowisko testowe gotowe!                             ║${RESET}"
echo -e "${BOLD}${GREEN}╚══════════════════════════════════════════════════════════╝${RESET}"
echo ""
echo -e "  ${BOLD}Aplikacja:${RESET}  https://${DOMAIN}"
echo -e "  ${BOLD}Dane:${RESET}       https://${DOMAIN}/testy-info.html"
echo -e "         ${CYAN}(certyfikat SSL z Let's Encrypt — do 60s przy 1. uruchomieniu)${RESET}"
echo ""
echo -e "  ${BOLD}Konta testowe:${RESET}"
echo -e "  ┌─────────────────────────────────────────────────────────┐"
echo -e "  │ ${CYAN}Admin${RESET}          ${ADMIN_EMAIL}          ${BOLD}${ADMIN_PASS}${RESET}"
echo -e "  │ ${CYAN}Lider${RESET}          leader.test@feer.test        Leader99!"
echo -e "  │ ${CYAN}Wolontariusz${RESET}   vol.test@feer.test          Test1234!"
echo -e "  │ ${CYAN}Obserwator${RESET}     viewer.test@feer.test        View5678!"
echo -e "  │ ${CYAN}Wolont. niepełn.${RESET} test.wolontariusz.mlodociany@example.com  Test1234!"
echo -e "  │ ${CYAN}Opiekun${RESET}        test.opiekun@example.com     Test1234!"
echo -e "  └─────────────────────────────────────────────────────────┘"
echo ""
echo -e "  ${BOLD}Reset cykliczny:${RESET}  co niedziela o 03:00"
echo -e "  ${BOLD}Log resetu:${RESET}       ${CRON_LOG}"
echo ""
echo -e "  ${BOLD}Komendy:${RESET}"
echo -e "  ${CYAN}docker logs -f ${APP_CONTAINER}${RESET}"
echo -e "  ${CYAN}${COMPOSE} down${RESET}"
echo -e "  ${CYAN}bash ${SCRIPT_DIR}/setup-testy.sh${RESET}  # ręczny reset + seed"
echo -e "  ${CYAN}crontab -l${RESET}                          # pokaż aktywne crony"
echo ""
