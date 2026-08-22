# Roundcube "rc" — webmail modułu Poczta

> **Obraz:** `roundcube/roundcubemail` · **Domena:** `${RC_DOMAIN}` (np. `rc.feer.org.pl`)
> **Logowanie:** OAuth2 do Microsoft 365 (bez haseł do skrzynek w Roundcube)

## 1. Co to jest

Webmail dla userów w ramach modułu „Poczta" (`poczta/` w głównej aplikacji).
**Jeden z kilku klientów do wyboru** — użytkownik wybiera na stronie
`poczta.feer.org.pl` (= `szo.feer.org.pl/poczta`, plik `webmail/index.php`).
Katalog klientów i ich cech: `includes/webmail_clients.php`; rodzeństwo:
`../snappymail/README.md`, `../squirrelmail/README.md`. Roundcube pojawia się
użytkownikom tylko wtedy, gdy jego adres jest wpisany w Admin → Poczta → Webmail.
Osobny kontener Docker (`rc`), niezależny od głównej apki — nie dzieli z nią
bazy danych. Skrzynki to Microsoft 365 (IMAP `outlook.office365.com`, SMTP
`smtp.office365.com`).

Dodatkowo włączone wbudowane, oficjalnie wspierane pluginy Roundcube (bez
własnego kodu — same nazwy w `$config['plugins']`, obraz je już zawiera):
`markasjunk` (oznacz jako spam), `newmail_notifier` (powiadomienie o nowej
wiadomości — użytkownik włącza sposób w swoich Ustawieniach), `attachment_reminder`
(ostrzeżenie o zapomnianym załączniku), `emoticons`, `hide_blockquote` (zwija
cytowaną treść), `subscriptions_option` (przełącznik subskrypcji IMAP w
Ustawieniach), `show_additional_headers`, `identicon` (awatar nadawcy).

Plugin `login_notice` (montowany z `plugins/login_notice/`) dodaje na stronie
logowania komunikat (info Fundacji, bezpieczeństwo, ogłoszenia typu migracja
adresu). **Treść zarządzana centralnie** — nie w kodzie pluginu: admin edytuje
ją w głównej aplikacji, `admin/poczta_settings.php` → karta „Webmail
(Roundcube)" → pole „Komunikat na stronie logowania Roundcube". Plugin
pobiera ją przez wewnętrzne API (`api/internal/rc_login_notice.php`) po sieci
Docker `feer` (`http://app/...`, NIE przez Traefik/publiczny internet),
autoryzacja nagłówkiem `X-Internal-Key` = `APP_KEY` (ten sam sekret co
kontener „app" — przekazany do „rc" w `docker-compose.rc.yml`). Cache ~5 min
(`rcube_cache` Roundcube) — zmiana w adminie widoczna z niewielkim
opóźnieniem, bez restartu kontenera. Jeśli główna aplikacja jest nieosiągalna
(sieć, appka nie działa, brak `APP_KEY`), plugin pokazuje krótki tekst
zapasowy zaszyty w kodzie (`fallback_html()`) — strona logowania nigdy nie
zostaje bez komunikatu, ale też nigdy nie blokuje logowania, gdy API akurat
nie odpowiada. Wymaga `oauth_login_redirect = false` — inaczej strona
logowania nigdy się nie renderuje (od razu przekierowanie do Microsoft). Ten
plugin też ukrywa zwykły formularz login/hasło Roundcube (skrzynki są tylko
przez OAuth), bo z `oauth_login_redirect=false` rdzeń sam go nie chowa.

Logo Fundacji na stronie logowania — `$config['skin_logo']` wskazuje na
`plugins/login_notice/logo.png` (kopia aktualnego logo z `assets/logo/`
głównej aplikacji; kontenery są osobne, więc plik trzeba fizycznie
skopiować — nie jest to link do głównej apki). **Jeśli logo w
`admin/org_settings.php` się zmieni, trzeba ręcznie podmienić też ten plik.**

Plugin `onedrive_picker` (montowany z `plugins/onedrive_picker/`) dodaje przycisk
„OneDrive" w oknie tworzenia maila — user loguje się **raz** (Microsoft 365),
ale plugin pobiera **własny, osobny token Microsoft Graph** przez
`grant_type=refresh_token` (ten sam refresh_token co Roundcube, inny `scope`).

**Ważne — dlaczego osobny token:** Microsoft identity platform (v2.0) wydaje
access token ważny dla JEDNEGO „resource"/audience na żądanie. Token z
logowania (scope `outlook.office365.com/*`, do IMAP/SMTP) **nie jest ważny**
dla `graph.microsoft.com` i na odwrót — nie da się dostać jednego tokenu na
oba naraz w tym samym żądaniu (dlatego `oauth_scope` w `config.inc.php`
celowo NIE zawiera `Files.Read`). Zob.
[dokumentację Microsoft](https://learn.microsoft.com/entra/identity-platform/v2-oauth2-auth-code-flow).

**Ograniczenie:** nie ma aktywnie wspieranego, gotowego pluginu „OneDrive" dla
Roundcube na rynku — `onedrive_picker` jest własnym, napisanym pod ten projekt
kodem (wg dokumentowanego Roundcube Plugin API), **nieprzetestowanym na żywym
Roundcube** (nie było dostępu do Azure AD/M365 podczas pisania). Może wymagać
poprawek po pierwszym realnym uruchomieniu — zob. punkt 5.

Plugin `owncloud_picker` (montowany z `plugins/owncloud_picker/`) dodaje
analogiczny przycisk „ownCloud" w oknie tworzenia maila, ale przez WebDAV
(Basic Auth) na **wspólne konto integracyjne** ownCloud Fundacji — to samo,
którego główna aplikacja używa jako magazynu plików lekcji
(`admin/owncloud_settings.php`, `includes/owncloud.php`), tylko podane tutaj
osobno jako zmienne `RC_OWNCLOUD_*` (kontener „rc" nie ma dostępu do bazy
głównej apki). To NIE jest osobiste konto każdego użytkownika poczty —
pracownicy logują się do Roundcube przez Microsoft 365, a ownCloud w tym
projekcie jest kontem serwisowym/magazynem zespołowym. Przycisk pokazuje
zawartość katalogu `RC_OWNCLOUD_BASE_FOLDER` (domyślnie `poczta-udostepnione`
— trzeba go utworzyć na koncie integracyjnym, jeśli jeszcze nie istnieje).
Bez `RC_OWNCLOUD_URL`/`RC_OWNCLOUD_USERNAME`/`RC_OWNCLOUD_PASSWORD` przycisk po
prostu się nie pojawia (plugin sprawdza `configured()` przed wyrenderowaniem).

Plugin `outlook_contacts_sync` (montowany z `plugins/outlook_contacts_sync/`)
synchronizuje kontakty z Outlooka (Microsoft Graph `/me/contacts`) do
lokalnego adresownika Roundcube — **jednostronnie** (Outlook → Roundcube, bez
zapisu z powrotem), przy każdym logowaniu, z throttlingiem raz na 6h per user
(zapisany w prefs Roundcube, nie wymaga crona). Dopasowanie istniejący/nowy
kontakt po adresie e-mail — kontakt zmieniony ręcznie w Roundcube pod tym samym
adresem zostanie przy kolejnym sync nadpisany danymi z Outlooka. Podobnie jak
`onedrive_picker`, pobiera **własny token Graph** (scope `Contacts.Read`) tym
samym mechanizmem `grant_type=refresh_token` — wymaga dodania tego uprawnienia
w Azure AD, zob. punkt 3.

### Wygląd (skin + plugin `feer_theme`)

Skin: **tylko wbudowany `elastic`** (`ROUNDCUBEMAIL_SKIN` w
`docker-compose.rc.yml` + `$config['skins_allowed'] = ['elastic']`) — jedyny
aktywnie utrzymywany skin Roundcube: responsywny, z trybem ciemnym, wspierany
przez upstream.

Plugin `feer_theme` (montowany z `plugins/feer_theme/`) to **warstwa wizualna
nałożona na Elastic** — nie fork skina. Odwzorowuje paletę i typografię modułu
Poczta głównej aplikacji (`poczta/includes/header_poczta.php`, zmienne
`--pc-*`): granat `#1e3a8a` (pasek zadań, tło logowania), niebieski `#1d4ed8`
(akcent, przyciski akcji), żółty pierścień focusu `#facc15` (WCAG), font
`system-ui`, narożniki 8 px. Webmail jest osadzany w iframe
(`crm/webmail.php`), więc ma wyglądać jak przedłużenie modułu, a nie obca
aplikacja. Obejmuje: szynę zadań, listy folderów/wiadomości/kontaktów
(nieprzeczytane, zaznaczenie, liczniki), toolbar i przyciski, podgląd
wiadomości i załączniki, formularze i Ustawienia, menu/dialogi/komunikaty,
**stronę logowania** (karta na granatowym gradiencie, duży przycisk Microsoft
365, komunikat z `login_notice` jako panel informacyjny), **tryb ciemny**
(`html.dark-mode`) oraz osobne reguły dla mobile i wydruku.

Dlaczego nakładka, a nie własny skin: arkusz pluginu ładuje się **po** arkuszu
skina, więc wygrywa przy równej specyficzności; skin `extends: elastic` z
własnym `styles/styles.css` **zastępuje** arkusz rodzica (trzeba by utrzymywać
kopię całego CSS Elastica), a fork wymagałby mergowania przy każdej
aktualizacji obrazu.

Zmiana barw = edycja bloku `:root` (i `html.dark-mode`) w
`plugins/feer_theme/feer_theme.css`. **Po każdej zmianie CSS bumpnij
`ASSET_VERSION` w `feer_theme.php`** — inaczej przeglądarki użytkowników
zostaną na starym arkuszu. Restart kontenera nie jest potrzebny do samego CSS
(plugin montowany, PHP odczytuje plik na żądanie), ale przy zmianie
`feer_theme.php` tak: `docker compose ... up -d rc`.

> **Historia:** wcześniej aktywny był zwendorowany skin `chameleon-blue`
> (rodzina Larry, Kolab Chameleon), montowany z `roundcube/skins/`. Porzucony z
> dwóch powodów: (1) skiny rodziny Larry zostały **wycięte z rdzenia w
> Roundcube 1.6**, więc `"extends": "larry"` nie miał po czym dziedziczyć;
> (2) katalog skina zniknął z repo (commit `277432b1`), a `docker-compose.rc.yml`
> wciąż ustawiał `ROUNDCUBEMAIL_SKIN: chameleon-blue` i montował nieistniejącą
> ścieżkę — Docker tworzy wtedy **pusty** katalog bind-mounta, więc Roundcube
> dostawał skin bez `meta.json` i sypał błędem na każdym żądaniu. Kod skina
> jest w historii gita, jeśli kiedyś potrzebny
> (`git log --diff-filter=D -- docker/roundcube/skins`).

## 2. Wymagania

- DNS: `${RC_DOMAIN}` → IP serwera (rekord A, jak `szo.feer.org.pl`)
- Firewall: porty 80/443 (już otwarte dla głównej apki — Traefik jest wspólny)

## 3. Rejestracja aplikacji Azure AD (osobna od głównej app „FEER SZO")

Redirect URI głównej aplikacji (`MS_REDIRECT_URI`) jest inny niż ten, którego
użyje Roundcube — Azure AD wymaga dokładnego dopasowania URI, więc **potrzebna
jest druga, osobna rejestracja aplikacji** w tym samym tenancie.

1. Azure Portal → Entra ID → App registrations → **New registration**
   - Nazwa: np. „FEER SZO — Poczta (Roundcube)"
   - Supported account types: **single tenant** (ten sam tenant co `MS_TENANT_ID`)
   - Redirect URI (Web): dodaj **obie** poniższe wartości — dokładny format
     zależy od wersji Roundcube i nie da się tego zweryfikować bez żywego
     wdrożenia, więc bezpieczniej dodać obie:
     - `https://${RC_DOMAIN}/index.php/login/oauth`
     - `https://${RC_DOMAIN}/index.php?_task=login&_action=oauth`
2. **Certificates & secrets** → New client secret → skopiuj wartość do
   `RC_OAUTH_CLIENT_SECRET` w `.env.prod`.
3. **API permissions** → Add a permission:
   - Microsoft Graph (Delegated): `openid`, `email`, `profile`, `offline_access`,
     `Files.Read` (plugin OneDrive), `Contacts.Read` (plugin outlook_contacts_sync)
   - APIs my organization uses → **Office 365 Exchange Online** (Delegated):
     `IMAP.AccessAsUser.All`, `SMTP.Send`
   - **Grant admin consent** dla tenanta (te uprawnienia zwykle wymagają zgody
     administratora — bez tego logowanie userów zwróci błąd `AADSTS65001`).
4. Skopiuj **Application (client) ID** → `RC_OAUTH_CLIENT_ID`.
5. `RC_TENANT_ID` = ta sama wartość co `MS_TENANT_ID` głównej aplikacji.

## 4. Uruchomienie

Najprościej przez konfigurator (idempotentny — bezpieczny do wielokrotnego
uruchamiania, pyta tylko o brakujące wartości):

```bash
cd docker && bash setup-rc.sh [domena]   # domyślnie rc.feer.org.pl
```

Robi to samo co ręcznie: dopisuje `RC_*` do `.env.prod` (pyta o
`RC_OAUTH_CLIENT_ID`/`RC_OAUTH_CLIENT_SECRET` z punktu 3, jeśli jeszcze ich nie
ma), sprawdza DNS, i uruchamia kontener:

```bash
cp .env.prod.example .env.prod   # jeśli jeszcze nie istnieje — wypełnij sekcję Roundcube
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  -f docker-compose.rc.yml --env-file .env.prod up -d rc
```

Sprawdź logi przy pierwszym starcie:
```bash
docker logs -f feer-rc
```

## 5. Jeśli logowanie OAuth lub plugin OneDrive nie zagra od razu

- **"Oops... something went wrong! An internal error has occurred"** — to
  ogólna strona błędu Roundcube (nieobsłużony wyjątek/błąd PHP), zwracana z
  kodem HTTP **200** (nie 500!), więc łatwo ją przeoczyć patrząc tylko na kody
  odpowiedzi. Roundcube loguje własne błędy do **`logs/errors.log` wewnątrz
  kontenera** — NIE do stdout/stderr, więc `docker logs feer-rc` bywa czysty
  mimo błędu na każdym żądaniu. Zawsze sprawdź najpierw:
  ```bash
  docker exec feer-rc cat /var/www/html/logs/errors.log
  ```
  Najczęstsza przyczyna (potwierdzona na produkcji): **`DB Error: SQLSTATE[HY000]
  [14] unable to open database file`** — świeży nazwany wolumin `rc_db` Docker
  tworzy jako `root:root`, a Apache/PHP w obrazie działają jako `www-data`, więc
  SQLite nie może otworzyć/utworzyć pliku bazy. Naprawa (natychmiastowa, bez
  restartu kontenera):
  ```bash
  docker exec -u root feer-rc chown -R www-data:www-data /var/roundcube/db
  ```
  `setup-rc.sh` robi to automatycznie od teraz po każdym `up -d`. Inne możliwe
  przyczyny: (b) **brakujący/niepoprawny skin** — w `logs/errors.log` widać
  wtedy wpis o skinie (np. `Skin directory not found` / `Error loading skin`).
  Tak działo się z usuniętym już `chameleon-blue`: dziedziczył po skinie
  `larry`, którego Roundcube 1.6 nie zawiera. Sprawdź, co obraz naprawdę ma:
  ```bash
  docker exec feer-rc ls /var/www/html/skins       # powinno być: elastic
  docker exec feer-rc grep -n "^\$config\['skin'\]" /var/roundcube/config/config.inc.php
  ```
  Jeśli użytkownik ma w preferencjach zapisany nieistniejący skin, ratuje go
  `$config['skins_allowed'] = ['elastic']` (jest w `config.inc.php`) — wraca do
  domyślnego. (c) literówka/brak wartości w
  `RC_OAUTH_CLIENT_ID`/`RC_OAUTH_CLIENT_SECRET`/`RC_TENANT_ID` w `.env.prod`,
  (d) błąd w niestandardowym pluginie `onedrive_picker`, `owncloud_picker`,
  `outlook_contacts_sync` lub `feer_theme` — spróbuj chwilowo usunąć podejrzany
  wpis z `$config['plugins']` w `config.inc.php` i zrestartować kontener, żeby
  sprawdzić, czy błąd zniknie.
- **Zmiany w `config.inc.php`/pluginach nie działają, a `docker compose up -d rc`
  pisze „Container feer-rc Running"** (potwierdzone na produkcji) — Compose uznał
  konfigurację za niezmienioną i NIE przeładował kontenera; działa nadal ten
  utworzony kiedyś, ze starym zestawem montowań. Objaw wtedy: nowy plugin nie
  ładuje się w ogóle (np. brak `plugins/feer_theme/feer_theme.css` w `<head>`
  strony, choć jest w `$config['plugins']`), a `logs/errors.log` ma wpisy
  o nieudanym ładowaniu pluginu. Naprawa:
  ```bash
  docker compose -f docker-compose.yml -f docker-compose.prod.yml \
    -f docker-compose.rc.yml --env-file .env.prod up -d --force-recreate rc
  docker exec feer-rc ls /var/www/html/plugins   # muszą być wszystkie własne pluginy
  ```
- **`managesieve` na Microsoft 365 nie ma jak działać** — plugin wymaga usługi
  ManageSieve (Dovecot/Cyrus), której Exchange Online **nie udostępnia**, więc
  zakładka „Filtry" tylko generuje wpisy w `logs/errors.log`. Reguły ustawia się
  w Outlooku (działają po stronie serwera, obowiązują też w Roundcube). Jeśli te
  błędy przeszkadzają, usuń `'managesieve'` z `$config['plugins']` i przeładuj
  kontener — celowo zostawione, bo nie blokuje logowania ani poczty.
- **Przycisk „ownCloud" w compose się nie pojawia** — to zamierzone zachowanie
  pluginu, gdy `RC_OWNCLOUD_URL`/`RC_OWNCLOUD_USERNAME`/`RC_OWNCLOUD_PASSWORD`
  nie są ustawione (patrz `configured()` w `owncloud_picker.php`) — uzupełnij
  je w `.env.prod` i zrestartuj kontener `rc`.
- **Przycisk „ownCloud" dołącza błąd HTTP 404/401** — sprawdź, czy katalog
  `RC_OWNCLOUD_BASE_FOLDER` (domyślnie `poczta-udostepnione`) istnieje na
  koncie integracyjnym ownCloud (trzeba go utworzyć ręcznie — plugin, w
  odróżnieniu od `includes/owncloud.php` głównej aplikacji, nie robi
  automatycznego `MKCOL`) i czy dane logowania są aktualne (to samo konto co
  w Admin → Integracje → Magazyn plików / ownCloud głównej aplikacji).
- **Kontakty z Outlooka nie pojawiają się w adresowniku Roundcube** — sync
  działa tylko przy logowaniu (hook `login_after`) i jest throttlowany na 6h
  per user (`outlook_contacts_last_sync` w prefs) — wyloguj się i zaloguj
  ponownie, jeśli testujesz od razu po zmianie konfiguracji. Sprawdź
  `logs/errors.log` (wpis `outlook_contacts_sync`) i czy `Contacts.Read` ma
  **grant admin consent** w Azure Portal.
- **Logowanie kończy się błędem "AADSTS50011 redirect_uri_mismatch", a Azure
  pokazuje redirect URI z `http://` mimo że strona jest pod `https://`**
  (potwierdzone na produkcji) — Traefik terminuje TLS i przekazuje ruch do
  kontenera zwykłym HTTP, więc bez podpowiedzi Roundcube "myśli", że żądanie
  przyszło po http i buduje redirect_uri z tym schematem. Naprawione w
  `config.inc.php` przez `$config['use_https']` ustawiane na podstawie
  nagłówka `X-Forwarded-Proto` od Traefika — jeśli mimo to problem wraca,
  sprawdź, czy Traefik faktycznie wysyła ten nagłówek (domyślnie tak) i czy
  redirect URI w Azure App registration jest dokładnie
  `https://${RC_DOMAIN}/index.php/login/oauth` (ze schematem, bez końcowego
  slasha).
- **Logowanie kończy się błędem w `logs/errors.log` typu `OAuth token request
  failed: ... graph.microsoft.com/v1.0/me ... 401 Unauthorized ... Invalid
  audience`** (potwierdzone na produkcji) — token logowania jest wystawiony
  tylko dla audience `outlook.office365.com` (celowo, patrz komentarz przy
  `oauth_scope`), więc Microsoft Graph zawsze go odrzuci. Przyczyna: pole
  `oauth_identity_fields` wskazywało na nazwy pól z Microsoft Graph
  (`mail`/`userPrincipalName`), których nigdy nie ma w `id_token` (JWT) — więc
  Roundcube zawsze robił zapasowe (i skazane na porażkę) zapytanie do Graph.
  Naprawione: `oauth_identity_fields` używa teraz prawdziwych claimów OIDC
  (`preferred_username`, `email`) obecnych w JWT, a `oauth_identity_uri` (Graph
  `/me`) jest całkiem usunięty z konfiguracji, bo z tym scope nie może
  zadziałać.
- **IMAP/SMTP XOAUTH2 nie działa mimo udanego logowania** — sprawdź czy
  `IMAP.AccessAsUser.All`/`SMTP.Send` mają **grant admin consent** (nie tylko
  "requested") w Azure Portal → widoczne jako zielony ptaszek, nie żółty wykrzyknik.
- **Plugin OneDrive nie dołącza pliku (błąd w konsoli / alert w oknie)** —
  otwórz DevTools → Network przy zwykłym uploadzie pliku (przeciągnij plik do
  compose) i porównaj kształt odpowiedzi z tym, co robi
  `docker/roundcube/plugins/onedrive_picker/onedrive_picker.php` (hook
  `attachment_save`) — jeśli backend załączników w tej wersji Roundcube różni
  się od założeń, trzeba dopasować `action_attach()`.
- **Graph zwraca 403 dla `/me/drive`** — user może nie mieć licencji OneDrive
  for Business w tym tenancie, albo `Files.Read` nie ma zgody administratora.

## 6. Nazewnictwo

Kontener/serwis nazywa się `rc` (nie `roundcube`) — decyzja projektowa, patrz
`docker-compose.rc.yml`.
