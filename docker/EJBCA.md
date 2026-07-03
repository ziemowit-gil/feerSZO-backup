# EJBCA CE — wewnętrzny urząd certyfikacji (CA)

> **Obraz:** `keyfactor/ejbca-ce` · **Domena:** `${CA_DOMAIN}` (np. `ca.feer.org.pl`)
> **Status:** Community Edition — wg producenta (Keyfactor) nieprzeznaczona do
> produkcji bez wsparcia komercyjnego. Traktuj jako świadome ryzyko.

## 1. Architektura — dlaczego dwa wejścia

EJBCA ma dwa oblicza pod tą samą domeną:

| Adres | Port kontenera | TLS | Cel |
|---|---|---|---|
| `https://${CA_DOMAIN}/` | 8080 | terminowany przez Traefik (Let's Encrypt) | Public Web / RA — wnioski o certyfikat, OCSP, CRL |
| `https://${CA_DOMAIN}:8443/ejbca/adminweb/` | 8443 | **passthrough** — EJBCA sam robi handshake | Panel administracyjny (wymaga certyfikatu klienckiego) |

AdminWeb autoryzuje po certyfikacie klienckim TLS na poziomie handshake'u. Gdyby
Traefik terminował TLS tak jak dla reszty aplikacji, EJBCA nigdy nie zobaczyłby
certyfikatu klienta i admina nie dałoby się zalogować. Dlatego port 8443 ma
własny entrypoint Traefika (`ejbca-admin`, zdefiniowany w `docker-compose.prod.yml`)
działający w trybie `tls.passthrough=true` — Traefik tylko kieruje ruch po SNI,
nie rozszyfrowuje go.

## 2. Wymagania

- DNS: `${CA_DOMAIN}` → IP serwera (rekord A)
- Firewall: porty **80, 443, 8443** otwarte
- Zasoby: EJBCA to aplikacja Java (WildFly) — licz min. 2 vCPU / 2 GB RAM
  *dodatkowo* do tego, co już zużywa stack `feer-app` + MySQL

## 3. Konfiguracja

Uzupełnij w `.env.prod` (sekcja EJBCA, patrz `.env.prod.example`):

```bash
CA_DOMAIN=ca.feer.org.pl
EJBCA_PASSWORD_ENCRYPTION_KEY=$(openssl rand -hex 32)
EJBCA_CA_KEYSTOREPASS=$(openssl rand -hex 32)
EJBCA_DB_PASSWORD=$(openssl rand -hex 24)
EJBCA_DB_ROOT_PASSWORD=$(openssl rand -hex 24)
```

`PASSWORD_ENCRYPTION_KEY` i `CA_KEYSTOREPASS` **nie zmieniaj po pierwszym starcie** —
szyfrują dane w bazie / chronią klucz prywatny CA.

## 4. Uruchomienie

```bash
cd /opt/feer-szo/docker   # lub gdziekolwiek leży repo na serwerze
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  -f docker-compose.ejbca.yml --env-file .env.prod up -d ejbca-db ejbca
```

## 5. Pierwsze uruchomienie — enrollment SuperAdmina

Przy pierwszym starcie (`TLS_SETUP_ENABLED=true`) EJBCA generuje własne
Management CA oraz certyfikat klucza SuperAdmin. **Dokładne instrukcje
(jak pobrać plik .p12 i jakie jest hasło) EJBCA wypisuje w logach kontenera**
przy starcie — to jest oficjalny, wspierany sposób ich odczytania:

```bash
docker compose logs -f ejbca
```

Poczekaj, aż appserver (WildFly) w pełni wystartuje (może to potrwać kilka minut
przy pierwszym uruchomieniu) i przeczytaj instrukcje w logu. Zazwyczaj sprowadza
się to do:

1. Skopiowania wygenerowanego `superadmin.p12` z kontenera (`docker cp`) na swój
   komputer.
2. Zaimportowania go do przeglądarki (magazyn certyfikatów osobistych).
3. Wejścia na `https://${CA_DOMAIN}:8443/ejbca/adminweb/` w **nowym oknie
   prywatnym** (przeglądarka musi użyć certyfikatu klienta, a nie starej sesji)
   i wybrania zaimportowanego certyfikatu przy monicie TLS.

Jeśli logi „przewiną się" zanim zdążysz je przeczytać, użyj
`docker compose logs ejbca | less` albo `docker compose logs --since 1h ejbca`.

## 6. Backup

Dwa miejsca trzymają dane krytyczne — **oba muszą być w backupie**:

- wolumen `ejbca_data` (`/mnt/persistent` w kontenerze) — konfiguracja, klucze lokalne
- wolumen `ejbca_db_data` (baza MariaDB) — tu żyje klucz prywatny CA (w softwarowym
  keystore, chroniony `CA_KEYSTOREPASS`) oraz cały rejestr wydanych/odwołanych certyfikatów

Utrata bazy = utrata CA. Backupuj tak samo rygorystycznie jak bazę główną aplikacji.

## 7. Znane ograniczenia tego wdrożenia

- Community Edition — brak SLA/wsparcia producenta, brak HSM (klucz CA w
  softwarowym keystore na dysku serwera)
- Port 8443 jest wystawiony publicznie (TLS passthrough) — jedyną barierą jest
  wymóg certyfikatu klienckiego po stronie EJBCA. Rozważ dodatkowo ograniczenie
  źródłowych IP na porcie 8443 w firewallu serwera (np. do stałych IP biura/VPN),
  jeśli to możliwe.
