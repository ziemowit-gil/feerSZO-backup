#!/usr/bin/env bash
# =============================================================================
# bin/backup-server.sh — Kompletny backup serwera feerSZO
#
# Archiwizuje:
#   git bundle         — cały stan gita (historia + working tree)
#   SQLite DB(s)       — bezpieczna kopia .backup (API online, WAL-safe)
#   tenants/           — bazy i dane per-tenant
#   certs/             — klucze prywatne app + SAML IdP
#   config.local.php   — sekrety konfiguracyjne
#   uploads/           — pliki użytkowników
#   EJBCA DB           — mysqldump z kontenera Docker (opcjonalnie)
#   Docker MySQL/MariaDB — mysqldump z dowolnych kontenerów (DOCKER_MYSQL)
#   Docker named volumes — tar przez obraz alpine (DOCKER_VOLUMES)
#   ścieżki dodatkowe  — patrz EXTRA_PATHS
#
# Wynik: feerszo_YYYYMMDD_HHMMSS.tar.gz  (opcjonalnie .gpg)
#        → lokalnie w BACKUP_STORE
#        → opcjonalnie rsync na SSH lub aws s3 cp
#
# Użycie:
#   bash bin/backup-server.sh [--dry-run] [--no-remote] [--no-git]
#
# Wymagania: bash 4+, tar, git, gzip; opcjonalnie: sqlite3, gpg, rsync, aws
#
# Wpis do crontaba HOSTA (crontab -e — NIE docker/crontab, ten drugi działa
# wewnątrz kontenera app i nie ma dostępu do docker.sock/wolumenów):
#   0 2 * * 0 /ścieżka/do/repo/bin/backup-server.sh >> /var/log/feerszo_backup.log 2>&1
# (raz w tygodniu, w nocy z soboty na niedzielę — pełny backup jest cięższy
# niż przyrostowy cron/agents/backup.php, który dalej działa co 4h bez zmian)
# =============================================================================
set -euo pipefail

# ─── KONFIGURACJA ─────────────────────────────────────────────────────────────

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Gdzie odkładać gotowe paczki
BACKUP_STORE="/var/backups/feerszo"

# Ile lokalnych paczek zachować (starsze są usuwane)
KEEP_LOCAL=14

# Zdalne składowanie — ustaw TYLKO JEDNO (lub żadne)
REMOTE_SSH=""            # np. backup@nas.example.com:/mnt/backups/feerszo
REMOTE_S3=""             # np. s3://moj-bucket/feerszo
KEEP_REMOTE=30           # ile paczek zachować na S3 (rsync zarządza sam)

# EJBCA Docker (opcjonalnie — jeśli nie używasz DOCKER_MYSQL poniżej)
# UWAGA: integracja EJBCA została usunięta z aplikacji (2026-09). Jeśli
# kontenery EJBCA nadal działają na serwerze produkcyjnym, wypełnij to PRZED
# ich wygaszeniem — tam żyje klucz prywatny CA (zob. [[project_ejbca_ca]]) —
# potem to pole można zostawić puste.
EJBCA_CONTAINER=""       # np. "feer-ejbca-db"; puste = pomijaj
EJBCA_DB="ejbca"
EJBCA_USER="root"
EJBCA_PASS=""            # puste = bez hasła

# Docker — MySQL/MariaDB: "container:db:user:password"  (hasło puste = bez -p)
# Kontenery nieaktywne są automatycznie pomijane bez błędu.
DOCKER_MYSQL=(
  # "feer-ejbca-db:ejbca:root:"       # EJBCA MariaDB (alternatywa do EJBCA_CONTAINER)
  # "feer-mysql:feerszo:root:"         # Główna MySQL
)

# Docker — named volumes do zarchiwizowania (tar przez obraz alpine)
# Wolumin musi istnieć; kontener może być zatrzymany.
DOCKER_VOLUMES=(
  "ldap_data"         # OpenLDAP dane
  "ldap_config"       # OpenLDAP konfiguracja
  "owncloud_files"    # ownCloud pliki użytkowników
  "letsencrypt_data"  # Certyfikaty Let's Encrypt
  "rc_db"             # Roundcube SQLite
  # "rabbitmq_data"     # RabbitMQ kolejki (tylko dev — docker-compose.override.yml)
  # "ejbca_data"        # EJBCA certyfikaty — integracja usunięta (zob. usunięty
  #                       docker/docker-compose.ejbca.yml); jeśli kontenery EJBCA
  #                       nadal działają na serwerze, odkomentuj TO i EJBCA_CONTAINER
  #                       wyżej na czas jednorazowego backupu przed ich wygaszeniem
)

# Szyfrowanie GPG (opcjonalnie)
GPG_RECIPIENT=""         # np. backup@feer.org.pl; puste = bez szyfrowania

# Dodatkowe ścieżki (bezwzględne lub względne wobec APP_DIR)
EXTRA_PATHS=()
# EXTRA_PATHS=("docker/nginx.conf" "/etc/letsencrypt/live/feer.org.pl")

# ──────────────────────────────────────────────────────────────────────────────

DRY=false; NO_REMOTE=false; NO_GIT=false
for _arg in "$@"; do
  case "$_arg" in
    --dry-run)   DRY=true ;;
    --no-remote) NO_REMOTE=true ;;
    --no-git)    NO_GIT=true ;;
    --help|-h)
      grep '^#' "$0" | head -30 | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) printf '[WARN] Nieznana opcja: %s\n' "$_arg" >&2 ;;
  esac
done

# ─── Helpers ──────────────────────────────────────────────────────────────────
_ts()   { date +'%H:%M:%S'; }
log()   { printf '\e[36m[%s]\e[0m %s\n'   "$(_ts)" "$*"; }
ok()    { printf '\e[32m[ OK]\e[0m %s\n'  "$*"; }
warn()  { printf '\e[33m[WARN]\e[0m %s\n' "$*" >&2; }
err()   { printf '\e[31m[ERR ]\e[0m %s\n' "$*" >&2; }
die()   { err "$*"; exit 1; }

human_sz() {
  local s="$1"
  awk -v s="$s" 'BEGIN {
    if      (s >= 1073741824) printf "%.1f GB", s/1073741824
    else if (s >= 1048576)    printf "%.1f MB", s/1048576
    else if (s >= 1024)       printf "%d KB",   s/1024
    else                      printf "%d B",    s
  }'
}

file_sz() { stat -c%s "$1" 2>/dev/null || stat -f%z "$1" 2>/dev/null || echo 0; }

ERRORS=0
inc_err() { (( ERRORS++ )) || true; }

TMP_DIR=""
cleanup() { [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]] && rm -rf "$TMP_DIR"; }
trap cleanup EXIT INT TERM

# ─── Pre-flight ───────────────────────────────────────────────────────────────
log "=== feerSZO backup-server === $(date '+%Y-%m-%d %H:%M:%S') ==="
log "Aplikacja: $APP_DIR"
$DRY && warn "Tryb DRY-RUN — nic nie zostanie zapisane."

[[ -d "$APP_DIR" ]] || die "APP_DIR nie istnieje: $APP_DIR"
command -v tar  &>/dev/null || die "Brak polecenia: tar"
command -v git  &>/dev/null || die "Brak polecenia: git"
[[ -n "$GPG_RECIPIENT" ]] && { command -v gpg &>/dev/null || die "Brak gpg (wymagane dla szyfrowania)"; }
[[ -n "$REMOTE_SSH" ]] && ! $NO_REMOTE && { command -v rsync &>/dev/null || warn "Brak rsync — SSH transfer pomijany."; }
[[ -n "$REMOTE_S3" ]] && ! $NO_REMOTE && { command -v aws   &>/dev/null || warn "Brak aws-cli — S3 transfer pomijany."; }

TMP_DIR="$(mktemp -d /tmp/feerszo-backup-XXXXXX)"
STAGE="$TMP_DIR/stage"
mkdir -p "$STAGE"

STAMP="$(date +'%Y%m%d_%H%M%S')"
ARCHIVE_NAME="feerszo_${STAMP}.tar.gz"

$DRY || mkdir -p "$BACKUP_STORE"

# ─── 1. Git bundle ────────────────────────────────────────────────────────────
if ! $NO_GIT; then
  log "1. Git bundle (cała historia + working tree)…"
  BUNDLE="$STAGE/repo.bundle"
  if $DRY; then
    warn "  [dry] git bundle create repo.bundle --all"
  elif git -C "$APP_DIR" bundle create "$BUNDLE" --all 2>/dev/null; then
    ok "  repo.bundle → $(human_sz "$(file_sz "$BUNDLE")")"
  else
    warn "  git bundle nie powiódł się — pomijam"
    inc_err
  fi
fi

# ─── 2. Bazy SQLite ───────────────────────────────────────────────────────────
log "2. Bazy SQLite…"
mkdir -p "$STAGE/db"
DB_COUNT=0

while IFS= read -r -d '' db_src; do
  db_rel="${db_src#"$APP_DIR/"}"
  db_dst="$STAGE/db/$(printf '%s' "$db_rel" | tr '/' '__')"
  if $DRY; then
    warn "  [dry] backup $db_rel"
  else
    if command -v sqlite3 &>/dev/null; then
      if sqlite3 "$db_src" ".backup '${db_dst//\'/\'\'\'}'" 2>/dev/null; then
        ok "  $db_rel → $(human_sz "$(file_sz "$db_dst")")"
        (( DB_COUNT++ )) || true
      else
        warn "  .backup nie powiodło się dla $db_rel — VACUUM INTO"
        if sqlite3 "$db_src" "VACUUM INTO '${db_dst//\'/\'\'\'}';" 2>/dev/null; then
          ok "  $db_rel (VACUUM) → $(human_sz "$(file_sz "$db_dst")")"
          (( DB_COUNT++ )) || true
        else
          warn "  VACUUM też nie powiodło się — kopiuję plik bezpośrednio"
          cp "$db_src" "$db_dst" && ok "  $db_rel → skopiowano (niespójny WAL możliwy!)"
          (( DB_COUNT++ )) || true
          inc_err
        fi
      fi
    else
      warn "  Brak sqlite3 — kopiuję $db_rel bezpośrednio"
      cp "$db_src" "$db_dst" && ok "  $db_rel → skopiowano (niespójny WAL możliwy!)"
      (( DB_COUNT++ )) || true
      inc_err
    fi
  fi
done < <(find "$APP_DIR" \
  -not -path "$APP_DIR/backups/*" \
  -not -path "$APP_DIR/vendor/*" \
  -not -path "$APP_DIR/.git/*" \
  -not -path "$APP_DIR/.claude/*" \
  -not -path "$APP_DIR/output/*" \
  -not -path "*/.angular/*" \
  -not -path "*/node_modules/*" \
  \( -name "*.db" -o -name "*.sqlite" -o -name "*.sqlite3" \) \
  -type f -size +0c -print0 2>/dev/null)

$DRY || ok "  Razem baz: $DB_COUNT"

# ─── 3. Certyfikaty i klucze ──────────────────────────────────────────────────
log "3. Certyfikaty i klucze…"
CERT_ITEMS=("$APP_DIR/certs" "$APP_DIR/cert-salt.php" "$APP_DIR/saml")
for item in "${CERT_ITEMS[@]}"; do
  [[ -e "$item" ]] || continue
  rel="${item#"$APP_DIR/"}"
  if $DRY; then
    warn "  [dry] backup $rel"
  elif [[ -d "$item" ]]; then
    mkdir -p "$STAGE/certs/$(basename "$item")"
    cp -rp "$item/." "$STAGE/certs/$(basename "$item")/"
    ok "  $rel/ ($(find "$STAGE/certs/$(basename "$item")" -type f | wc -l | tr -d ' ') pliki)"
  else
    mkdir -p "$STAGE/certs"
    cp "$item" "$STAGE/certs/"
    ok "  $rel"
  fi
done

# ─── 4. Konfiguracja (sekrety) ────────────────────────────────────────────────
log "4. Pliki konfiguracyjne…"
mkdir -p "$STAGE/config"
for cfg_name in config.local.php .env .env.local .env.production config.production.php; do
  src="$APP_DIR/$cfg_name"
  [[ -f "$src" ]] || continue
  if $DRY; then
    warn "  [dry] backup $cfg_name"
  else
    cp "$src" "$STAGE/config/"
    ok "  $cfg_name"
  fi
done

# ─── 5. Uploads ───────────────────────────────────────────────────────────────
log "5. Uploads…"
UPL_SRC="$APP_DIR/uploads"
if [[ -d "$UPL_SRC" ]]; then
  UPL_COUNT=$(find "$UPL_SRC" -type f 2>/dev/null | wc -l | tr -d ' ')
  if $DRY; then
    warn "  [dry] tar uploads/ ($UPL_COUNT pliki)"
  else
    UPL_ARC="$STAGE/uploads.tar.gz"
    if tar -czf "$UPL_ARC" -C "$APP_DIR" uploads/ 2>/dev/null; then
      ok "  uploads/ ($UPL_COUNT pliki) → $(human_sz "$(file_sz "$UPL_ARC")")"
    else
      warn "  tar uploads/ nie powiódł się"
      inc_err
    fi
  fi
else
  warn "  Brak katalogu uploads/ — pomijam."
fi

# ─── 6. Ścieżki dodatkowe ─────────────────────────────────────────────────────
if (( ${#EXTRA_PATHS[@]} > 0 )); then
  log "6. Dodatkowe ścieżki…"
  mkdir -p "$STAGE/extra"
  for extra in "${EXTRA_PATHS[@]}"; do
    [[ "$extra" = /* ]] || extra="$APP_DIR/$extra"
    if [[ ! -e "$extra" ]]; then warn "  Nie istnieje: $extra"; continue; fi
    if $DRY; then
      warn "  [dry] backup $extra"
    else
      cp -rp "$extra" "$STAGE/extra/"
      ok "  $extra"
    fi
  done
fi

# ─── 7. EJBCA mysqldump ───────────────────────────────────────────────────────
if [[ -n "$EJBCA_CONTAINER" ]]; then
  log "7. EJBCA mysqldump…"
  if ! command -v docker &>/dev/null; then
    warn "  Brak docker — pomijam EJBCA."
    inc_err
  elif ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$EJBCA_CONTAINER"; then
    warn "  Kontener '$EJBCA_CONTAINER' nie jest uruchomiony — pomijam."
    inc_err
  elif $DRY; then
    warn "  [dry] docker exec $EJBCA_CONTAINER mysqldump $EJBCA_DB | gzip"
  else
    EJBCA_DUMP="$STAGE/ejbca.sql.gz"
    _pass_opt=""
    [[ -n "$EJBCA_PASS" ]] && _pass_opt="-p${EJBCA_PASS}"
    if docker exec "$EJBCA_CONTAINER" \
         mysqldump -u"$EJBCA_USER" ${_pass_opt} \
         --single-transaction --routines --triggers "$EJBCA_DB" 2>/dev/null \
         | gzip > "$EJBCA_DUMP"; then
      ok "  EJBCA → $(human_sz "$(file_sz "$EJBCA_DUMP")")"
    else
      warn "  mysqldump nie powiódł się"
      inc_err
    fi
  fi
fi

# ─── 8. Docker MySQL + named volumes ─────────────────────────────────────────
_has_docker_work=false
(( ${#DOCKER_MYSQL[@]} > 0 )) && _has_docker_work=true
(( ${#DOCKER_VOLUMES[@]} > 0 )) && _has_docker_work=true

if $_has_docker_work; then
  log "8. Docker (MySQL/MariaDB + wolumeny)…"
  mkdir -p "$STAGE/docker"

  _docker_ok() {
    command -v docker &>/dev/null || { warn "  Brak polecenia docker."; return 1; }
    docker info &>/dev/null       || { warn "  Demon Docker nie odpowiada."; return 1; }
    return 0
  }

  # ── 8a. MySQL/MariaDB dumps ────────────────────────────────────────────────
  for _spec in "${DOCKER_MYSQL[@]}"; do
    IFS=: read -r _cname _db _user _pass <<< "$_spec"
    [[ -z "$_cname" || -z "$_db" ]] && continue

    if $DRY; then
      warn "  [dry] docker exec $_cname mysqldump $_db"
      continue
    fi

    if ! _docker_ok; then inc_err; continue; fi

    if ! docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$_cname"; then
      warn "  Kontener '$_cname' nie jest uruchomiony — pomijam."
      continue
    fi

    _dump="$STAGE/docker/${_cname}__${_db}.sql.gz"
    _pass_opt=""
    [[ -n "$_pass" ]] && _pass_opt="-p${_pass}"

    if docker exec "$_cname" \
         mysqldump -u"${_user:-root}" ${_pass_opt} \
         --single-transaction --routines --triggers "$_db" 2>/dev/null \
         | gzip > "$_dump"; then
      ok "  $_cname:$_db → $(human_sz "$(file_sz "$_dump")")"
    else
      warn "  mysqldump z $_cname:$_db nie powiódł się"
      rm -f "$_dump"
      inc_err
    fi
  done

  # ── 8b. Named volumes (tar przez alpine) ──────────────────────────────────
  for _vol in "${DOCKER_VOLUMES[@]}"; do
    [[ -z "$_vol" ]] && continue

    if $DRY; then
      warn "  [dry] docker run alpine tar vol:$_vol → vol_${_vol}.tar.gz"
      continue
    fi

    if ! _docker_ok; then inc_err; continue; fi

    if ! docker volume inspect "$_vol" &>/dev/null 2>&1; then
      warn "  Wolumin '$_vol' nie istnieje — pomijam."
      inc_err; continue
    fi

    _vol_arc="$STAGE/docker/vol_${_vol}.tar.gz"

    # alpine tar; :ro żeby nie przypadkowo zmodyfikować wolumenu
    if docker run --rm \
         -v "${_vol}:/vol:ro" \
         alpine \
         tar -czf - -C /vol . 2>/dev/null > "$_vol_arc" \
       && [[ -s "$_vol_arc" ]]; then
      ok "  vol:$_vol → $(human_sz "$(file_sz "$_vol_arc")")"
    else
      warn "  tar woluminu '$_vol' nie powiódł się lub wolumin jest pusty"
      rm -f "$_vol_arc"
      inc_err
    fi
  done
fi

# ─── Metadane archiwum ────────────────────────────────────────────────────────
if ! $DRY; then
  {
    echo "feerszo backup — $(date '+%Y-%m-%d %H:%M:%S %Z')"
    echo "Wersja git:    $(git -C "$APP_DIR" describe --always --tags 2>/dev/null || echo '?')"
    echo "Hostname:      $(hostname -f 2>/dev/null || hostname)"
    echo "PHP:           $(php --version 2>/dev/null | head -1 || echo '?')"
    echo "Błędy/warningi: $ERRORS"
  } > "$STAGE/MANIFEST.txt"
fi

# ─── Pakietuj stage ───────────────────────────────────────────────────────────
if ! $DRY; then
  log "Pakietuję archiwum…"
  TMP_ARC="$TMP_DIR/$ARCHIVE_NAME"
  tar -czf "$TMP_ARC" -C "$STAGE" . 2>/dev/null
  ok "Rozmiar: $(human_sz "$(file_sz "$TMP_ARC")")"

  if [[ -n "$GPG_RECIPIENT" ]]; then
    log "Szyfruję GPG (odbiorca: $GPG_RECIPIENT)…"
    gpg --batch --yes --recipient "$GPG_RECIPIENT" \
        --output "${TMP_ARC}.gpg" --encrypt "$TMP_ARC"
    rm -f "$TMP_ARC"
    TMP_ARC="${TMP_ARC}.gpg"
    ARCHIVE_NAME="${ARCHIVE_NAME}.gpg"
    ok "Zaszyfrowano → $ARCHIVE_NAME"
  fi

  ARCHIVE_FINAL="$BACKUP_STORE/$ARCHIVE_NAME"
  mv "$TMP_ARC" "$ARCHIVE_FINAL"
  ok "Lokalnie: $ARCHIVE_FINAL"
fi

# ─── Transfer zdalny ──────────────────────────────────────────────────────────
if ! $DRY && ! $NO_REMOTE; then
  if [[ -n "$REMOTE_SSH" ]] && command -v rsync &>/dev/null; then
    log "Wysyłam SSH → $REMOTE_SSH…"
    if rsync -az --protect-args "$ARCHIVE_FINAL" "$REMOTE_SSH/"; then
      ok "SSH: OK"
    else
      warn "rsync nie powiódł się — backup lokalny zachowany"
      inc_err
    fi
  fi

  if [[ -n "$REMOTE_S3" ]] && command -v aws &>/dev/null; then
    log "Wysyłam S3 → $REMOTE_S3…"
    if aws s3 cp "$ARCHIVE_FINAL" "${REMOTE_S3}/${ARCHIVE_NAME}" \
         --storage-class STANDARD_IA --no-progress; then
      ok "S3: OK"
      # Rotacja S3
      log "Rotacja S3 (zachowuję $KEEP_REMOTE ostatnich)…"
      aws s3 ls "${REMOTE_S3}/" 2>/dev/null \
        | awk '{print $4}' | grep -E '^feerszo_.*\.tar\.gz(\.gpg)?$' | sort \
        | head -n -"$KEEP_REMOTE" \
        | while IFS= read -r key; do
            aws s3 rm "${REMOTE_S3}/${key}" --quiet 2>/dev/null \
              && ok "  S3: usunięto $key"
          done
    else
      warn "aws s3 cp nie powiódł się — backup lokalny zachowany"
      inc_err
    fi
  fi
fi

# ─── Rotacja lokalna ──────────────────────────────────────────────────────────
if ! $DRY; then
  log "Rotacja lokalna (zachowuję $KEEP_LOCAL ostatnich)…"
  ROTATED=0
  while IFS= read -r old; do
    rm -f "$old"
    (( ROTATED++ )) || true
    ok "  Usunięto: $(basename "$old")"
  done < <(ls -1t "${BACKUP_STORE}/feerszo_"*.tar.gz* 2>/dev/null | tail -n +"$(( KEEP_LOCAL + 1 ))")
  (( ROTATED == 0 )) && ok "  Nic do usunięcia."
fi

# ─── Podsumowanie ─────────────────────────────────────────────────────────────
echo ""
if $DRY; then
  log "=== DRY-RUN zakończony. ==="
elif (( ERRORS == 0 )); then
  ok "=== Backup zakończony sukcesem ($(date '+%Y-%m-%d %H:%M:%S')) ==="
else
  warn "=== Backup zakończony z $ERRORS ostrzeżeniami ($(date '+%Y-%m-%d %H:%M:%S')) ==="
fi

exit $(( ERRORS > 0 ? 1 : 0 ))
