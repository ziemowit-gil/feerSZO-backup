#!/usr/bin/env bash
# grant-graph-permissions.sh — nadaje aplikacji SZO uprawnienia aplikacyjne
# Microsoft Graph i zatwierdza je zgodą administratora.
#
# Po co: integracje SZO (Skrzynka CRM, synchronizacja Outlooka, książka adresowa)
# działają na uprawnieniach APLIKACYJNYCH. Samo dodanie uprawnienia w portalu nie
# wystarcza — bez „Grant admin consent" Graph zwraca 403 „Access is denied",
# a uprawnienie nie pokazuje sie na liscie nadanych.
#
# Wymaga: Azure CLI (docker/scripts/install-azure-cli.sh) oraz konta z rolą
# Privileged Role Administrator / Global Administrator w tenancie.
#
# Użycie:
#   bash docker/scripts/grant-graph-permissions.sh                       # podgląd (nic nie zmienia)
#   bash docker/scripts/grant-graph-permissions.sh --apply               # wykonaj zmiany
#   bash docker/scripts/grant-graph-permissions.sh --client-id <appId> --perms Mail.Read,Contacts.ReadWrite --apply
#
# Client ID i Tenant ID znajdziesz w CRM → Ustawienia → Microsoft 365
# (sekcja „CRM → Outlook") — są tam pokazane wprost, razem ze stanem uprawnień.

set -euo pipefail

GRAPH_APP_ID="00000003-0000-0000-c000-000000000000"   # Microsoft Graph — stały identyfikator
CLIENT_ID=""
TENANT_ID=""
PERMS="Mail.Read,Contacts.ReadWrite"
APPLY=0

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'
CYAN=$'\033[0;36m'; BOLD=$'\033[1m'; RESET=$'\033[0m'
info() { echo "${CYAN}  ▸ $*${RESET}"; }
ok()   { echo "${GREEN}  ✔ $*${RESET}"; }
warn() { echo "${YELLOW}  ⚠ $*${RESET}"; }
err()  { echo "${RED}  ✘ $*${RESET}" >&2; }
head_() { echo; echo "${BOLD}━━ $* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${RESET}"; }

usage() { sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --client-id) CLIENT_ID="${2:-}"; shift 2 ;;
    --tenant)    TENANT_ID="${2:-}"; shift 2 ;;
    --perms)     PERMS="${2:-}";     shift 2 ;;
    --apply)     APPLY=1;            shift ;;
    -h|--help)   usage ;;
    *) err "Nieznany argument: $1"; exit 2 ;;
  esac
done

head_ "Wymagania"
if ! command -v az >/dev/null 2>&1; then
  err "Brak Azure CLI. Zainstaluj: bash docker/scripts/install-azure-cli.sh"
  exit 1
fi
ok "Azure CLI: $(az version --query '"azure-cli"' -o tsv 2>/dev/null || echo '?')"

if ! az account show >/dev/null 2>&1; then
  err "Nie jesteś zalogowany. Uruchom: az login --tenant <TENANT_ID>"
  exit 1
fi
CUR_TENANT="$(az account show --query tenantId -o tsv)"
CUR_USER="$(az account show --query user.name -o tsv)"
ok "Zalogowany: ${CUR_USER} (tenant ${CUR_TENANT})"

if [[ -n "$TENANT_ID" && "$TENANT_ID" != "$CUR_TENANT" ]]; then
  err "Zalogowany tenant (${CUR_TENANT}) różni się od podanego (${TENANT_ID})."
  err "Przeloguj się: az login --tenant ${TENANT_ID}"
  exit 1
fi

if [[ -z "$CLIENT_ID" ]]; then
  err "Podaj --client-id <appId> aplikacji SZO."
  err 'Znajdziesz go w CRM → Ustawienia → Microsoft 365 (pole Client ID).'
  exit 2
fi

head_ "Aplikacja"
APP_NAME="$(az ad app show --id "$CLIENT_ID" --query displayName -o tsv 2>/dev/null || true)"
if [[ -z "$APP_NAME" ]]; then
  err "Nie znaleziono rejestracji aplikacji o Client ID ${CLIENT_ID} w tym tenancie."
  exit 1
fi
ok "Rejestracja: ${APP_NAME} (${CLIENT_ID})"

# Service principal aplikacji — bez niego nie ma czego konsentować
SP_ID="$(az ad sp list --filter "appId eq '${CLIENT_ID}'" --query '[0].id' -o tsv 2>/dev/null || true)"
if [[ -z "$SP_ID" || "$SP_ID" == "None" ]]; then
  warn "Aplikacja nie ma jeszcze service principala w tenancie."
  if [[ "$APPLY" == "1" ]]; then
    info "Tworzę service principal…"
    az ad sp create --id "$CLIENT_ID" >/dev/null
    SP_ID="$(az ad sp list --filter "appId eq '${CLIENT_ID}'" --query '[0].id' -o tsv)"
    ok "Utworzony: ${SP_ID}"
  fi
fi

# Identyfikatory rólGraph czytamy z tenanta — żadnych zaszytych GUID-ów
GRAPH_SP_ID="$(az ad sp list --filter "appId eq '${GRAPH_APP_ID}'" --query '[0].id' -o tsv)"

head_ "Uprawnienia do nadania"
IFS=',' read -r -a WANTED <<< "$PERMS"
declare -a TO_ADD=()
for p in "${WANTED[@]}"; do
  p="$(echo "$p" | xargs)"   # trim
  [[ -z "$p" ]] && continue
  ROLE_ID="$(az ad sp show --id "$GRAPH_APP_ID" \
      --query "appRoles[?value=='${p}'].id | [0]" -o tsv 2>/dev/null || true)"
  if [[ -z "$ROLE_ID" || "$ROLE_ID" == "None" ]]; then
    err "Microsoft Graph nie zna uprawnienia aplikacyjnego ${p} — pomijam."
    continue
  fi

  ASSIGNED=""
  if [[ -n "${SP_ID:-}" && "$SP_ID" != "None" ]]; then
    ASSIGNED="$(az rest --method GET \
      --uri "https://graph.microsoft.com/v1.0/servicePrincipals/${SP_ID}/appRoleAssignments" \
      --query "value[?appRoleId=='${ROLE_ID}'] | [0].id" -o tsv 2>/dev/null || true)"
  fi

  if [[ -n "$ASSIGNED" && "$ASSIGNED" != "None" ]]; then
    ok "${p} — już nadane i zatwierdzone"
  else
    warn "${p} — brakuje (rola ${ROLE_ID})"
    TO_ADD+=("${ROLE_ID}=Role")
  fi
done

if [[ ${#TO_ADD[@]} -eq 0 ]]; then
  echo; ok "Nic do zrobienia — wszystkie żądane uprawnienia są nadane."
  exit 0
fi

if [[ "$APPLY" != "1" ]]; then
  head_ "Podgląd (nic nie zmieniono)"
  info "Do wykonania:"
  for r in "${TO_ADD[@]}"; do
    echo "      az ad app permission add --id ${CLIENT_ID} --api ${GRAPH_APP_ID} --api-permissions ${r}"
  done
  echo "      az ad app permission admin-consent --id ${CLIENT_ID}"
  echo
  warn "Uruchom ponownie z --apply, aby wykonać."
  exit 0
fi

head_ "Wykonanie"
for r in "${TO_ADD[@]}"; do
  info "Dodaję uprawnienie ${r%%=*}…"
  az ad app permission add --id "$CLIENT_ID" --api "$GRAPH_APP_ID" --api-permissions "$r" >/dev/null
done
ok "Uprawnienia dopisane do rejestracji"

info "Zatwierdzam zgodą administratora (może potrwać kilkanaście sekund)…"
if az ad app permission admin-consent --id "$CLIENT_ID" 2>/tmp/gp_consent.err; then
  ok "Zgoda administratora udzielona"
else
  err "Zgoda nieudana: $(tr -d '\n' < /tmp/gp_consent.err)"
  err "Najczęstsza przyczyna: konto bez roli Privileged Role Administrator / Global Administrator."
  exit 1
fi

head_ "Weryfikacja"
sleep 5
SP_ID="$(az ad sp list --filter "appId eq '${CLIENT_ID}'" --query '[0].id' -o tsv)"
GRANTED="$(az rest --method GET \
  --uri "https://graph.microsoft.com/v1.0/servicePrincipals/${SP_ID}/appRoleAssignments" \
  --query 'value[].appRoleId' -o tsv 2>/dev/null || true)"
for p in "${WANTED[@]}"; do
  p="$(echo "$p" | xargs)"; [[ -z "$p" ]] && continue
  ROLE_ID="$(az ad sp show --id "$GRAPH_APP_ID" --query "appRoles[?value=='${p}'].id | [0]" -o tsv)"
  if grep -q "$ROLE_ID" <<< "$GRANTED"; then ok "${p} — nadane"; else warn "${p} — jeszcze nie widoczne (propagacja do 5 min)"; fi
done

echo
ok "Gotowe. Sprawdź w CRM → Ustawienia → Microsoft 365 — sekcja uprawnień powinna być na zielono."
