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

Zalecane — skrypt dokłada EJBCA do już działającego stacku FEER (nie startuje
niczego od zera, generuje brakujące sekrety w `.env.prod`, otwiera port
w ufw jeśli aktywny, czeka na gotowość i pokazuje logi z instrukcją enrollmentu):

```bash
cd /opt/feer-szo/docker   # katalog z .env.prod głównego stacku
bash setup-ejbca.sh ca.feer.org.pl
```

Uwaga: skrypt restartuje `feer-traefik` (dostaje nowy entrypoint `:8443`) —
to kilka sekund przerwy w dostępności głównej aplikacji. Reszta usług
(app/mysql/redis) nie jest ruszana.

Ręcznie (bez skryptu), jeśli wolisz pełną kontrolę:

```bash
cd /opt/feer-szo/docker   # lub gdziekolwiek leży repo na serwerze
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  -f docker-compose.ejbca.yml --env-file .env.prod up -d
```

## 5. Pierwsze uruchomienie — enrollment SuperAdmina

Przy pierwszym starcie (`TLS_SETUP_ENABLED=true`) EJBCA tworzy Management CA
i konto `superadmin`, a pod koniec startu (appserver WildFly potrzebuje na to
2–3 minuty) wypisuje w logu kontenera dokładny URL do samodzielnego
enrollmentu i jednorazowe hasło, w takiej postaci:

```
* Initial SuperAdmin client certificate enrollment URL (adapt port to your mapping): *
*   URL:      https://ca.feer.org.pl:443/ejbca/ra/enrollwithusername.xhtml?username=superadmin *
*   Password: <losowe-haslo>                                                        *
```

Znajdź ten fragment (zamiast przewijać cały log):

```bash
docker logs feer-ejbca 2>&1 | grep -i -A 10 "enrollment url"
```

Uwaga — to jest URL do **RA web** (część publiczna, port 8080 w kontenerze,
idzie przez zwykły Traefik na 443), **nie** do AdminWeb na 8443. Nie trzeba
niczego kopiować `docker cp` z kontenera. Kroki:

1. Wejdź w przeglądarce na ten URL (domena bez `:443` w pasku, to domyślny port HTTPS).
2. Podaj wypisane hasło — strona sama wygeneruje i pobierze plik `.p12`.
3. Zaimportuj `.p12` do przeglądarki (magazyn certyfikatów osobistych/klienckich),
   używając tego samego hasła.
4. Wejdź na `https://${CA_DOMAIN}:8443/ejbca/adminweb/` w **nowym oknie
   prywatnym** (przeglądarka musi użyć świeżo zaimportowanego certyfikatu,
   a nie starej sesji bez certyfikatu) i wybierz go przy monicie TLS.

Hasło enrollmentu jest jednorazowe (EJBCA je unieważnia po użyciu), ale
dopóki enrollment nie jest dokończony, ktokolwiek je pozna może przejąć
konto SuperAdmina — dokończ ten krok możliwie od razu po starcie, nie
zostawiaj logów z hasłem w miejscach dostępnych dla innych.

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
