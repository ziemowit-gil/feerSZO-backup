# Roundcube "rc" — webmail modułu Poczta

> **Obraz:** `roundcube/roundcubemail` · **Domena:** `${RC_DOMAIN}` (np. `poczta.feer.org.pl`)
> **Logowanie:** OAuth2 do Microsoft 365 (bez haseł do skrzynek w Roundcube)

## 1. Co to jest

Webmail dla userów w ramach modułu „Poczta" (`poczta/` w głównej aplikacji).
Osobny kontener Docker (`rc`), niezależny od głównej apki — nie dzieli z nią
bazy danych. Skrzynki to Microsoft 365 (IMAP `outlook.office365.com`, SMTP
`smtp.office365.com`).

Plugin `onedrive_picker` (montowany z `plugins/onedrive_picker/`) dodaje przycisk
„OneDrive" w oknie tworzenia maila — reużywa token OAuth z logowania (patrz
punkt 3) do wywołań Microsoft Graph, więc user loguje się **raz**.

**Ograniczenie:** nie ma aktywnie wspieranego, gotowego pluginu „OneDrive" dla
Roundcube na rynku — `onedrive_picker` jest własnym, napisanym pod ten projekt
kodem (wg dokumentowanego Roundcube Plugin API), **nieprzetestowanym na żywym
Roundcube** (nie było dostępu do Azure AD/M365 podczas pisania). Może wymagać
poprawek po pierwszym realnym uruchomieniu — zob. punkt 5.

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
     `Files.Read`
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
cd docker && bash setup-rc.sh [domena]   # domyślnie poczta.feer.org.pl
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

- **Logowanie kończy się błędem "redirect_uri_mismatch"** — sprawdź w logach
  kontenera (`docker logs feer-rc`) lub w komunikacie błędu Azure, jaki redirect
  URI faktycznie wysłał Roundcube, i dodaj GO dokładnie (ze schematem i bez
  końcowego slasha) w Azure App registration → Authentication.
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
