#!/usr/bin/env bash
#
# azure_vlab_port.sh — rejestruje (otwiera), usuwa i listuje reguły portów w NSG
# Microsoft Azure. Odpowiednik logiki z includes/vlab.php do użytku ręcznego/testów
# oraz na hostach bez ścieżki PHP. Reguła: Allow / Inbound, dla wskazanego portu.
#
# Wymaga zalogowanego Azure CLI (az login) z dostępem do NSG (np. konto z setupu).
#
# Konfigurację można podać argumentami lub zmiennymi środowiskowymi:
#   AZ_SUBSCRIPTION, AZ_RESOURCE_GROUP, AZ_NSG
#
# Użycie:
#   ./azure_vlab_port.sh open  <port> [tcp|udp] [-s SUB -g RG -n NSG] [--name NAZWA]
#   ./azure_vlab_port.sh close <port> [tcp|udp] [-s SUB -g RG -n NSG] [--name NAZWA]
#   ./azure_vlab_port.sh list  [-s SUB -g RG -n NSG]
#
# Przykłady:
#   AZ_SUBSCRIPTION=... AZ_RESOURCE_GROUP=lab-rg AZ_NSG=lab-nsg ./azure_vlab_port.sh open 8080 tcp
#   ./azure_vlab_port.sh close 8080 tcp -s <SUB> -g lab-rg -n lab-nsg
#
set -euo pipefail

SUB="${AZ_SUBSCRIPTION:-}"
RG="${AZ_RESOURCE_GROUP:-}"
NSG="${AZ_NSG:-}"
RULE_NAME=""

die() { echo "BŁĄD: $*" >&2; exit 1; }

[[ $# -ge 1 ]] || die "Podaj akcję: open | close | list (zobacz --help)."
ACTION="$1"; shift || true
[[ "$ACTION" == "--help" || "$ACTION" == "-h" ]] && { sed -n '2,30p' "$0"; exit 0; }

PORT=""; PROTO="tcp"
# pozycyjne: port [proto] — tylko dla open/close
if [[ "$ACTION" == "open" || "$ACTION" == "close" ]]; then
  [[ $# -ge 1 ]] || die "Podaj numer portu."
  PORT="$1"; shift
  if [[ $# -ge 1 && ( "$1" == "tcp" || "$1" == "udp" ) ]]; then PROTO="$1"; shift; fi
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    -s|--subscription)   SUB="$2"; shift 2;;
    -g|--resource-group) RG="$2"; shift 2;;
    -n|--nsg)            NSG="$2"; shift 2;;
    --name)              RULE_NAME="$2"; shift 2;;
    *) die "Nieznany argument: $1";;
  esac
done

command -v az >/dev/null 2>&1 || die "Nie znaleziono Azure CLI (az)."
[[ -n "$SUB" ]] || die "Brak subskrypcji (AZ_SUBSCRIPTION lub -s)."
[[ -n "$RG"  ]] || die "Brak grupy zasobów (AZ_RESOURCE_GROUP lub -g)."
[[ -n "$NSG" ]] || die "Brak nazwy NSG (AZ_NSG lub -n)."
az account set --subscription "$SUB"

# Pierwszy wolny priorytet 2000–4000 (zgodnie z logiką PHP)
next_priority() {
  local used
  used="$(az network nsg rule list -g "$RG" --nsg-name "$NSG" --query '[].priority' -o tsv 2>/dev/null || true)"
  local p
  for ((p=2000; p<=4000; p++)); do
    if ! grep -qx "$p" <<<"$used"; then echo "$p"; return; fi
  done
  echo 4096
}

case "$ACTION" in
  open)
    [[ "$PORT" =~ ^[0-9]+$ ]] || die "Port musi być liczbą."
    [[ "$PORT" -ge 1 && "$PORT" -le 65535 ]] || die "Port poza zakresem 1–65535."
    [[ -n "$RULE_NAME" ]] || RULE_NAME="vlab-${PORT}-${PROTO}"
    PRIO="$(next_priority)"
    PROTO_AZ="Tcp"; [[ "$PROTO" == "udp" ]] && PROTO_AZ="Udp"
    echo "==> Otwieram port ${PORT}/${PROTO} (reguła ${RULE_NAME}, prio ${PRIO})"
    az network nsg rule create \
      -g "$RG" --nsg-name "$NSG" -n "$RULE_NAME" \
      --priority "$PRIO" --direction Inbound --access Allow \
      --protocol "$PROTO_AZ" --destination-port-ranges "$PORT" \
      --source-address-prefixes '*' --source-port-ranges '*' \
      --destination-address-prefixes '*' \
      --description "VLab port ${PORT}/${PROTO}" -o table
    ;;
  close)
    [[ -n "$RULE_NAME" ]] || RULE_NAME="vlab-${PORT}-${PROTO}"
    echo "==> Usuwam regułę ${RULE_NAME}"
    az network nsg rule delete -g "$RG" --nsg-name "$NSG" -n "$RULE_NAME"
    echo "    usunięto (lub nie istniała)."
    ;;
  list)
    echo "==> Reguły NSG ${NSG}:"
    az network nsg rule list -g "$RG" --nsg-name "$NSG" \
      --query "[].{Nazwa:name,Kier:direction,Dostep:access,Proto:protocol,Port:destinationPortRange,Prio:priority}" -o table
    ;;
  *)
    die "Nieznana akcja „$ACTION”. Użyj: open | close | list."
    ;;
esac
