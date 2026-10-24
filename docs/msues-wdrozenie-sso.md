# Wdrożenie MSUES — logowanie kontem SZO (SSO)

Aplikacja zewnętrzna „Wdrożenie MSUES” (repo feer-rbs, FastAPI) loguje się przez istniejący w SZO przepływ
OAuth 2.0 Authorization Code + PKCE (S256) — ten sam, z którego korzysta EZD Client
(`includes/desktop_auth.php`).

## Adresy (baza: APP_URL, produkcyjnie https://szo.feer.org.pl)
- **authorize:** `GET /auth/desktop_login.php?redirect_uri=…&state=…&code_challenge=…&device=…`
  (`state` 16–128 znaków `[A-Za-z0-9_-]`, `code_challenge` = BASE64URL(SHA256(verifier)), 43 znaki; `code_challenge_method` = S256 zawsze).
  Użytkownik loguje się jak zwykle (M365) i zatwierdza dostęp; powrót: `redirect_uri?code=…&state=…` (lub `error=access_denied`).
- **token:** `POST /api/v1/desktop_token.php` JSON `{"grant_type":"authorization_code","code":"…","code_verifier":"…"}`
  → `access_token` (Bearer, 30 dni), `scopes`, `user{id,name,email}`. Kod jednorazowy, ważny 2 min.
- **odnowienie:** `POST /api/v1/desktop_token.php?action=renew` (Bearer) — przedłuża o 30 dni i odświeża scope’y.
- **wylogowanie:** `POST /api/v1/desktop_token.php?action=logout` (Bearer) — unieważnia token.

## Uprawnienie i scope’y
- Uprawnienie **„Wdrożenie MSUES”** (moduł `msues_wdrozenie`) nadaje się w `admin/user_modules.php` (per konto) lub w roli (`admin/roles.php`).
  Domyślnie mają je tylko admin i konta nadane ręcznie; żadna rola systemowa go nie dostaje.
- Scope’y: **`msues:read`** (can_read) — odczyt; **`msues:write`** (can_write) — zapis. Admin ma oba.
  Konto bez żadnego scope’a (ezd/crm/msues) nie dostaje tokenu („Konto nie ma uprawnień do żadnej aplikacji zewnętrznej.”).
- Aplikacja powinna sprawdzać `scopes` z odpowiedzi tokenu i cyklicznie robić `renew` (cofnięcie uprawnienia działa po odnowieniu).

## Adres powrotu (redirect_uri)
Domyślnie tylko `http://127.0.0.1:PORT/callback` (PORT 1024–65535). Hostowana aplikacja https:
dopisz dokładny URI w **Admin → Ustawienia organizacji → Bezpieczeństwo → „Aplikacje zewnętrzne (SSO) — dozwolone adresy powrotu”**
(ustawienie `desktop_allowed_redirects`, jeden URI na linię, porównanie dokładne, tylko `https://` lub loopback).
