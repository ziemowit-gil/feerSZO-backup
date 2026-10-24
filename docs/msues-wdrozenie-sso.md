# Wdrożenie MSUES — logowanie kontem SZO (SSO)

Aplikacja zewnętrzna „Wdrożenie MSUES” (repo feer-rbs, FastAPI) loguje się przez istniejący w SZO przepływ
OAuth 2.0 Authorization Code + PKCE (S256) — ten sam, z którego korzysta EZD Client
(`includes/desktop_auth.php`).

## Adresy (baza: APP_URL, produkcyjnie https://szo.feer.org.pl)
- **authorize:** `GET /auth/desktop_login.php?redirect_uri=…&state=…&code_challenge=…&device=…&scope=msues:read msues:write`
  (`state` 16–128 znaków `[A-Za-z0-9_-]`, `code_challenge` = BASE64URL(SHA256(verifier)), 43 znaki; `code_challenge_method` = S256 zawsze).
  Użytkownik loguje się jak zwykle (M365) i zatwierdza dostęp; powrót: `redirect_uri?code=…&state=…` (lub `error=access_denied`).
- **token:** `POST /api/v1/desktop_token.php` JSON `{"grant_type":"authorization_code","code":"…","code_verifier":"…"}`
  → `access_token` (Bearer, 30 dni), `scopes`, `user{id,name,email}`. Kod jednorazowy, ważny 2 min.
- **odnowienie:** `POST /api/v1/desktop_token.php?action=renew` (Bearer) — przedłuża o 30 dni i odświeża scope’y.
- **wylogowanie:** `POST /api/v1/desktop_token.php?action=logout` (Bearer) — unieważnia token.

## Parametr `scope` (zasada najmniejszych uprawnień)
- Nazwa: **`scope`** (opcjonalny), wartość: lista scope’ów **rozdzielona spacjami** (w URL zakodowana: `scope=msues%3Aread%20msues%3Awrite`
  lub `msues:read+msues:write`). Dozwolone: `ezd:read`, `ezd:upload`, `ezd:write`, `crm:read`, `crm:write`, `msues:read`, `msues:write`.
  Nieznany scope → błąd 400 na ekranie zgody. Wdrożenie MSUES powinno wysyłać: `scope=msues:read msues:write`.
- Token dostaje **przecięcie** żądanych scope’ów z uprawnieniami konta; ekran zgody pokazuje tylko te faktycznie przyznawane.
  Konto bez żadnego z żądanych scope’ów nie dostaje tokenu (komunikat „Twoje konto nie ma uprawnień wymaganych przez tę aplikację.”).
- Zawężenie jest zapamiętane: `renew` odświeża uprawnienia tylko w granicach zawężenia (nigdy do pełnych uprawnień konta).
- **Aplikacja z rejestru** (`desktop_known_apps()` w `includes/desktop_auth.php`, rozpoznawana po hoście `redirect_uri`, np. `msues.feer.org.pl`)
  dostaje najwyżej własne scope’y (`msues:read`, `msues:write`) — także gdy poprosi o więcej albo pominie `scope`; nazwa na ekranie pochodzi z rejestru, nie z `device`.
- **Bez parametru** (aplikacja spoza rejestru, np. EZD Client) zachowanie jak dotąd: wszystkie uprawnienia konta (zgodność wstecz, EZD Client).
- Ekran zgody pokazuje nazwę aplikacji z `device` (np. `device=Wdrożenie MSUES`; domyślnie „EZD Client”) oraz host z `redirect_uri`.

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

## Dokumenty i oświadczenia w aplikacji MSUES
Aplikacja MSUES przyjmuje dokumenty i oświadczenia pod `/dokumenty` (pliki w `data/dokumenty/` na serwerze aplikacji, zatwierdzanie przez osoby z `msues:write`).
W SZO: **Admin → Ustawienia organizacji → Bezpieczeństwo → „Oświadczenia i dokumenty — przeniesienie do aplikacji MSUES”**
(`ld_declarations_in_msues`, domyślnie wyłączone) — po włączeniu linki `/oswiadczenie/{token}` w SZO pokazują adres MSUES zamiast formularza.
Dane już złożone w SZO zostają w bazie. Powiadomienia do koordynatorów: `POST /api/v1/msues_mail.php` (scope `msues:write`).
