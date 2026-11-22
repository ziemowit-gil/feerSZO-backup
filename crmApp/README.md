# SZO CRM — aplikacja Nuxt

Nowy interfejs modułu CRM SZO: Nuxt 4 (SPA, bez SSR) + Nuxt UI 4 (Reka UI, Tailwind 4).
Cała logika i dane zostają w SZO — aplikacja rozmawia wyłącznie z **API SZO**:

| Co | Gdzie w SZO |
|---|---|
| logowanie kontem SZO (OAuth 2.0 code + PKCE) | `auth/desktop_login.php` → `api/v1/desktop_token.php` (`includes/desktop_auth.php`) |
| dane CRM w imieniu zalogowanej osoby | `api/v1/crmApp.php?_route=…` (`modules/crmApi/`) |

Aplikacja prosi wyłącznie o `crm:read crm:write`, więc SZO:
- przed zgodą przepuszcza przez **bramę IKA** (jak klasyczny CRM),
- wydaje **krótki token** (do końca ważności IKA albo 8 h), bez odnawiania — po wygaśnięciu aplikacja sama wraca do logowania,
- stosuje uprawnienia osoby: obszary `crm_can`, maski pól, ograniczenie do grup kont tylko-CRM.

Token jest trzymany w `sessionStorage` (znika po zamknięciu karty), nie w ciasteczkach.

## Zakres (etap 1)

Pulpit · Kontakty (wyszukiwanie, filtry w adresie, akcje masowe) · Kartoteka (aktywność, sprawy, notatki, tagi, dane, usuwanie z powodem)
· Nowy kontakt / edycja · Sprawy (rejestr, karta, notatki, zmiana statusu z powodem zamknięcia, nowa sprawa).
Reszta modułu (skrzynka, kolejka, kalendarz, komunikacja, oferty, faktury, ustawienia…) jest podlinkowana do klasycznego CRM.

## Dostępność (WCAG 2.1 AA)

- `lang="pl"`, link „Przejdź do treści”, landmarki (`header`, `nav`, `main`, `aside`), jeden `h1` na stronę, tytuł karty per strona,
  ogłaszanie zmiany strony (`NuxtRouteAnnouncer`) i przeniesienie fokusu na treść po nawigacji.
- Kontrast: tokeny Nuxt UI nadpisane w `app/assets/css/main.css` (tekst ≥ 4,5:1, obrysy pól ≥ 3:1, jasny i ciemny motyw).
- Fokus: 3 px, `#c50f1f` (jak w całym SZO). Cele dotyku ≥ 44 px dla głównych kontrolek.
- Formularze: etykiety, `aria-invalid` + `aria-describedby` przy błędach, podsumowanie błędów z fokusem (`FormErrorSummary`).
- Kolory statusów z bazy są tylko kropką przy tekście (`StatusChip`) — znaczenie nie zależy od koloru.
- Tabele z `caption` i `th scope`, liczba wyników w `aria-live`, `prefers-reduced-motion`.

## Praca lokalna

```bash
npm install
npm run dev          # http://localhost:3000/crm2/
```

Lokalny SZO (np. `http://localhost:8012`) wpisz w `public/config.json` jako `szoUrl`, a w SZO
(`admin/org_settings.php` → karta „Aplikacje zewnętrzne (SSO) — dozwolone adresy powrotu”, ustawienie `desktop_allowed_redirects`) dopisz
`http://localhost:3000/crm2/auth/callback` — to też otwiera CORS dla `http://localhost:3000`.

## Wdrożenie w SZO (/crm2/)

```bash
npm run build:szo    # nuxt generate + kopia do ../crm2/
```

Katalog `crm2/` w korzeniu repo jest commitowany (serwer SZO robi tylko `git pull`). `crm2/config.json` zostaje z pustym
`szoUrl` (= ta sama domena). Adres powrotu `APP_URL/crm2/auth/callback` SZO akceptuje z automatu.

## Wdrożenie osobno (inna domena)

```bash
docker build -t szo-crm .
docker run -p 8080:80 -e SZO_URL=https://szo.feer.org.pl szo-crm
```

Albo bez Dockera: `NUXT_APP_BASE_URL=/ npx nuxt generate`, zawartość `.output/public` na dowolny serwer WWW
(z przekierowaniem nieznanych ścieżek na `index.html`, jak w `nginx.conf`) i `config.json` z `szoUrl`.
W SZO dopisz adres `https://<domena>/auth/callback` do `desktop_allowed_redirects` — bez tego SZO odrzuci logowanie,
a przeglądarka zablokuje wywołania API (CORS).
