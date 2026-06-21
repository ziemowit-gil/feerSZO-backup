#!/usr/bin/env bash
#
# azure_vlab_setup.sh — tworzy aplikację (Entra ID app + service principal) dla
# modułu VLAB i nadaje jej prawo zarządzania regułami NSG (Network Contributor),
# a na końcu wypisuje dane do wklejenia w „Karty 30 → Zajęcia TI → VLAB / Docker".
#
# Wymaga zalogowanego Azure CLI:  az login
# Idempotentny: ponowne uruchomienie odświeża sekret i utrzymuje przypisanie roli.
#
# Użycie:
#   ./azure_vlab_setup.sh \
#       --subscription <SUB_ID> \
#       --resource-group <RG> \
#       --nsg <NSG_NAME> \
#       [--app-name feerSZO-vlab] [--years 2] [--scope nsg|rg]
#
# Przykład:
#   ./azure_vlab_setup.sh -s 00000000-0000-0000-0000-000000000000 -g lab-rg -n lab-nsg
#
set -euo pipefail

APP_NAME="feerSZO-vlab"
YEARS=2
SCOPE_KIND="nsg"   # nsg = tylko ta grupa zabezpieczeń; rg = cała grupa zasobów
SUBSCRIPTION=""
RESOURCE_GROUP=""
NSG=""

die() { echo "BŁĄD: $*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    -s|--subscription)   SUBSCRIPTION="$2"; shift 2;;
    -g|--resource-group) RESOURCE_GROUP="$2"; shift 2;;
    -n|--nsg)            NSG="$2"; shift 2;;
    --app-name)          APP_NAME="$2"; shift 2;;
    --years)             YEARS="$2"; shift 2;;
    --scope)             SCOPE_KIND="$2"; shift 2;;
    -h|--help)           sed -n '2,30p' "$0"; exit 0;;
    *) die "Nieznany argument: $1";;
  esac
done

command -v az >/dev/null 2>&1 || die "Nie znaleziono Azure CLI (az). Zainstaluj: https://aka.ms/azure-cli"
[[ -n "$SUBSCRIPTION"   ]] || die "Brakuje --subscription"
[[ -n "$RESOURCE_GROUP" ]] || die "Brakuje --resource-group"
[[ -n "$NSG"            ]] || die "Brakuje --nsg"

echo "==> Ustawiam subskrypcję: $SUBSCRIPTION"
az account set --subscription "$SUBSCRIPTION"
TENANT="$(az account show --query tenantId -o tsv)"

echo "==> Sprawdzam NSG: $NSG (grupa: $RESOURCE_GROUP)"
NSG_ID="$(az network nsg show -g "$RESOURCE_GROUP" -n "$NSG" --query id -o tsv)" \
  || die "Nie znaleziono NSG „$NSG” w grupie „$RESOURCE_GROUP”."
if [[ "$SCOPE_KIND" == "rg" ]]; then
  SCOPE="$(az group show -n "$RESOURCE_GROUP" --query id -o tsv)"
else
  SCOPE="$NSG_ID"
fi

echo "==> Aplikacja (Entra ID): $APP_NAME"
APP_ID="$(az ad app list --display-name "$APP_NAME" --query '[0].appId' -o tsv)"
if [[ -z "$APP_ID" || "$APP_ID" == "null" ]]; then
  APP_ID="$(az ad app create --display-name "$APP_NAME" --query appId -o tsv)"
  echo "    utworzono nową aplikację: $APP_ID"
else
  echo "    aplikacja już istnieje: $APP_ID"
fi

# Service principal (potrzebny do przypisania roli)
if ! az ad sp show --id "$APP_ID" >/dev/null 2>&1; then
  echo "==> Tworzę service principal"
  az ad sp create --id "$APP_ID" >/dev/null
fi
SP_ID="$(az ad sp show --id "$APP_ID" --query id -o tsv)"

echo "==> Generuję sekret (ważny $YEARS lata)"
SECRET="$(az ad app credential reset --id "$APP_ID" --years "$YEARS" --query password -o tsv)"

echo "==> Nadaję rolę „Network Contributor” na: $SCOPE_KIND"
# powtórzenie jest bezpieczne — istniejące przypisanie zwróci błąd, który ignorujemy
az role assignment create --assignee-object-id "$SP_ID" --assignee-principal-type ServicePrincipal \
  --role "Network Contributor" --scope "$SCOPE" >/dev/null 2>&1 || true

cat <<EOF

============================================================
 GOTOWE. Wklej poniższe dane w panelu:
 Karty 30 → Zajęcia TI → VLAB / Docker → „Zarządzanie portami"
============================================================
 Azure Tenant ID      : $TENANT
 Client ID (app)      : $APP_ID
 Client secret        : $SECRET
 Subscription ID      : $SUBSCRIPTION
 Resource group       : $RESOURCE_GROUP
 Nazwa NSG            : $NSG
------------------------------------------------------------
 Zaznacz „Otwieraj porty też w Microsoft Azure (NSG)".
 Sekret pokazywany jest TYLKO TERAZ — zapisz go.
============================================================
EOF
