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
- Firewall: porty **80, 443, 8443** otwarte — **na OBU warstwach**, jeśli serwer
  jest w chmurze:
  1. `ufw` na samej maszynie (to robi `setup-ejbca.sh` automatycznie)
  2. **Firewall chmury** (np. Azure Network Security Group, AWS Security Group,
     GCP Firewall Rules) — `ufw` o tym nic nie wie i go nie otwiera. Jeśli
     maszyna jest na Azure, sprawdź/dodaj regułę:
     ```bash
     az network nic list-effective-nsg --ids <NIC_ID>   # sprawdź czy 8443 jest ALLOW
     az network nsg rule create --resource-group <RG> --nsg-name <NSG_NAME> \
       --name Allow-EJBCA-AdminWeb --priority 330 --direction Inbound \
       --access Allow --protocol Tcp --destination-port-ranges 8443 \
       --source-address-prefixes Internet --destination-address-prefixes '*'
     ```
     **To jest najczęstsza przyczyna „działało chwilę, potem przestało"/
     „przeglądarka wisi w nieskończoność"** — `ufw` może zezwalać na port,
     a i tak nic nie przejdzie, bo NSG blokuje go wcześniej, na poziomie sieci
     wirtualnej. Objaw jest mylący: nie dostajesz błędu „connection refused",
     tylko wieczne ładowanie (pakiety są po cichu odrzucane).
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
2–3 minuty) wypisuje w logu kontenera URL do samodzielnego enrollmentu i
jednorazowe hasło:

```
* Initial SuperAdmin client certificate enrollment URL (adapt port to your mapping): *
*   URL:      https://ca.feer.org.pl:443/ejbca/ra/enrollwithusername.xhtml?username=superadmin *
*   Password: <losowe-haslo>                                                        *
```

```bash
docker logs feer-ejbca 2>&1 | grep -i -A 10 "enrollment url"
```

**⚠ W praktyce ten URL nie zadziałał** (test 2026-07-03) — strona zwracała
„You are not authorized to perform enrollment" niezależnie od poprawności
hasła, mimo że konto `superadmin` pozostawało nietknięte w bazie (status
NEW, brak jakiegokolwiek wpisu audytowego dla tej próby — odrzucenie
następowało wcześniej, na poziomie autoryzacji roli RA). Przyczyny nie
ustalono (prawdopodobnie domyślna rola „Public Access" w tym obrazie nie ma
kompletu uprawnień RA potrzebnych do self-enrollmentu przez przeglądarkę).
**Nie trać na to czasu — użyj od razu metody CLI niżej.**

### Metoda, która działa: generowanie `.p12` przez CLI w kontenerze

Nie wymaga żadnej autoryzacji webowej — CLI wewnątrz kontenera ma pełne
uprawnienia lokalne. Kolejność ma znaczenie (zmiana statusu może wyzerować
wcześniej ustawione hasło jawne, więc rób to w tej kolejności, jedną
komendą):

```bash
docker exec feer-ejbca /opt/keyfactor/bin/ejbca.sh ra setendentitystatus superadmin 10 && \
docker exec feer-ejbca /opt/keyfactor/bin/ejbca.sh ra setclearpwd superadmin 'WYBIERZ_WLASNE_HASLO' && \
docker exec feer-ejbca /opt/keyfactor/bin/ejbca.sh batch
```

Ostatnia komenda powinna pokazać `Batch generating 1 users` i
`New user generated successfully - superadmin`. Plik ląduje w kontenerze
pod `/opt/keyfactor/p12/superadmin.p12`. Wyciągnij go:

```bash
docker cp feer-ejbca:/opt/keyfactor/p12/superadmin.p12 /home/<TWOJ_USER>/superadmin.p12
```

**Pułapka:** użyj ścieżki **absolutnej**, nie `~/superadmin.p12` — jeśli
`docker exec`/`docker cp` uruchamiasz jako `root` a plik ściągasz przez
`scp` jako inny użytkownik (np. `azureuser`), `~` rozwinie się inaczej dla
każdego z nich i dostaniesz dwa różne pliki (stary/nieaktualny pobrany, nowy
zostawiony tam gdzie go nie szukasz). Sprawdzaj sumę kontrolną
(`sha256sum`) po obu stronach transferu, jeśli coś nie gra z hasłem.

Import do przeglądarki:
1. `scp` plik na swój komputer.
2. **Firefox** ma własny magazyn certyfikatów: `about:preferences#privacy` →
   Certyfikaty → Wyświetl certyfikaty → Twoje certyfikaty → Importuj.
3. **Safari/Chrome (macOS)** korzystają z systemowego Keychain — dwuklik na
   plik `.p12` w Finderze otwiera Keychain Access.
4. Otwórz AdminWeb w **nowym oknie prywatnym** i wybierz certyfikat przy
   monicie TLS.

**⚠ Problem kompatybilności macOS Keychain:** EJBCA (BouncyCastle) generuje
`.p12` szyfrowany nowoczesnym `PBES2/AES` (`Shrouded Keybag: PBES2, PBKDF2,
AES-256-CBC`). Keychain Access na macOS **nie potrafi tego zaimportować** —
błąd „Nie można odkodować przekazanych danych", mimo że plik jest w 100%
poprawny (np. `openssl pkcs12 -info` go czyta bez problemu). Napraw
konwertując na starszy standard (RC2-40/3DES), który Keychain rozumie:

```bash
PASS='haslo_z_setclearpwd'
openssl pkcs12 -in superadmin.p12 -out /tmp/superadmin.pem -nodes -passin pass:"$PASS" -legacy
openssl pkcs12 -export -legacy -in /tmp/superadmin.pem -out superadmin_macos.p12 \
  -name "SuperAdmin" -passout pass:"$PASS"
rm /tmp/superadmin.pem   # zawiera klucz prywatny bez szyfrowania — usuń od razu
```

Wymaga OpenSSL 3.x z dostępnym legacy providerem (sprawdź: `openssl list
-providers -provider legacy -provider default` — musi pokazać `legacy` jako
`active`). Zaimportuj `superadmin_macos.p12` zamiast oryginału.

Hasło do `.p12` (to, które podałeś w `setclearpwd`) — **zmień je na coś
unikalnego, jeśli w trakcie ustalania procedury używałeś placeholdera z
dokumentacji lub czata z asystentem** — takie hasło nie jest już tajne.

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
- Self-enrollment przez RA web (`enrollwithusername.xhtml`) nie działa w tym
  obrazie — użyj metody CLI z sekcji 5.

## 8. Diagnostyka — checklist gdy coś nie działa

Sprawdzaj w tej kolejności (każdy kolejny krok zakłada, że poprzedni jest OK):

1. **Kontener żyje?** `docker logs feer-ejbca --tail=50` — świeże wpisy,
   appserver odpowiada.
2. **Passthrough działa lokalnie?** Test z właściwym SNI (kluczowe — samo
   `curl https://localhost:8443/...` NIE zadziała, bo trafi z SNI=`localhost`,
   nie dopasuje się do reguły `HostSNI` i Traefik zwróci własny domyślny
   certyfikat + 404):
   ```bash
   curl -vk --resolve ${CA_DOMAIN}:8443:127.0.0.1 https://${CA_DOMAIN}:8443/ejbca/adminweb/
   ```
   Sukces = widzisz certyfikat `CN=${CA_DOMAIN}` (wystawiony przez
   `ManagementCA`), nie `TRAEFIK DEFAULT CERT`, i odpowiedź EJBCA (np.
   „Authorization Denied" — to poprawne, bo ten test nie podaje certyfikatu klienta).
3. **Dostępne z zewnątrz?** To samo bez `--resolve`, z innej maszyny/Maca.
   Jeśli krok 2 działa, a to wisi w nieskończoność (nie timeout z błędem,
   tylko wieczne ładowanie) — to firewall chmury (NSG/Security Group), zobacz
   sekcję 2.
4. **Import `.p12` do przeglądarki nie działa?** Zobacz problem kompatybilności
   macOS Keychain w sekcji 5 (konwersja `-legacy`).
5. **Zmieniłeś hasło `.p12`, ale stary plik go nie akceptuje?** `setclearpwd`
   zmienia hasło **na przyszłość** (dla następnego `batch`), nie modyfikuje
   już wygenerowanego pliku. Trzeba: `setendentitystatus <user> 10` →
   `setclearpwd` → `batch` → pobrać plik **na nowo** (i nadpisać starą kopię
   lokalną — sprawdź `sha256sum` po obu stronach transferu, żeby mieć pewność
   że to ten sam plik).
