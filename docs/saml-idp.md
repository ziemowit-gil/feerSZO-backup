# SAML 2.0 Identity Provider (IdP) w SZO

SZO działa jako **dostawca tożsamości SAML 2.0**. Zewnętrzne aplikacje
(*Service Providers*, SP) — np. Moodle, Nextcloud, Grafana, Canva — mogą
logować użytkowników na kontach z tabeli `users` w SZO (Single Sign-On).

---

## Spis treści

1. [Architektura i pliki](#architektura-i-pliki)
2. [Wymagania](#wymagania)
3. [Uruchomienie](#uruchomienie)
4. [Endpointy i metadata IdP](#endpointy-i-metadata-idp)
5. [Rejestracja aplikacji (Service Provider)](#rejestracja-aplikacji-service-provider)
6. [Konfiguracja konkretnych aplikacji](#konfiguracja-konkretnych-aplikacji)
   - [Canva](#canva)
   - [Moodle](#moodle)
   - [Nextcloud](#nextcloud)
   - [Grafana](#grafana)
7. [Mapowanie atrybutów i NameID](#mapowanie-atrybutów-i-nameid)
8. [Polityka dostępu (role)](#polityka-dostępu-role)
9. [Single Logout (SLO)](#single-logout-slo)
10. [Bezpieczeństwo](#bezpieczeństwo)
11. [Rotacja certyfikatu](#rotacja-certyfikatu)
12. [Rozwiązywanie problemów](#rozwiązywanie-problemów)

---

## Architektura i pliki

| Plik | Rola |
|------|------|
| `includes/saml_idp.php` | Rdzeń: metadata, parsowanie żądań, podpisy XML, asercje, atrybuty, presety, SLO |
| `includes/lib/xmlseclibs/` | Biblioteka podpisów XML-DSig (`robrichards/xmlseclibs` 3.1.3, wzendurowana bez composera) |
| `saml/metadata.php` | Publiczne metadata IdP (pobiera SP) |
| `saml/sso.php` | Single Sign-On — SP-initiated i IdP-initiated |
| `saml/slo.php` | Single Logout |
| `admin/saml.php` | Panel administratora: rejestr SP, import metadata, presety, log |
| `cli/saml_gen_cert.php` | Generator certyfikatu podpisującego |
| tabele `saml_sp`, `saml_sso_log`, `saml_active_sessions` | Rejestr SP, audyt SSO, mapowanie sesji dla SLO |

Profil protokołu: **SAML 2.0 Web Browser SSO**, wiązania **HTTP-Redirect** i
**HTTP-POST**, podpis **RSA-SHA256** z kanonizacją **Exclusive C14N**.

---

## Wymagania

- PHP z rozszerzeniami: `openssl`, `dom`, `libxml`, `zlib`, `mbstring`.
- HTTPS na produkcji (asercje bearer wymagają bezpiecznego kanału).
- Poprawnie ustawiony `APP_URL` (z niego wyprowadzane są EntityID i endpointy).

---

## Uruchomienie

1. **Wygeneruj certyfikat podpisujący** (dedykowany, RSA-2048, ważny 5 lat):

   ```bash
   php cli/saml_gen_cert.php
   php cli/saml_gen_cert.php --status   # podgląd stanu
   ```

   Powstaną `certs/saml-idp.crt` (publiczny, trafia do metadata) i
   `certs/saml-idp.key` (prywatny, `chmod 600`, **nie** w repo — `certs/` jest
   w `.gitignore`). Jeśli brak dedykowanego certu, IdP użyje awaryjnie
   `certs/app.*`. Cert można też wygenerować z panelu (**Admin → SAML IdP**).

2. **Włącz IdP**: *Admin → SAML Identity Provider → przełącznik „Udostępniaj
   logowanie SSO"*.

3. **Zarejestruj aplikację** (patrz niżej) i skonfiguruj ją po stronie SP,
   wskazując metadata IdP.

---

## Endpointy i metadata IdP

Przy `APP_URL = https://app.feer.org.pl`:

| Element | Wartość |
|---------|---------|
| **Entity ID** | `https://app.feer.org.pl/saml/metadata.php` |
| **SSO URL** (Redirect + POST) | `https://app.feer.org.pl/saml/sso.php` |
| **SLO URL** (Redirect) | `https://app.feer.org.pl/saml/slo.php` |
| **Metadata** | `https://app.feer.org.pl/saml/metadata.php` |
| **Certyfikat** | osadzony w metadata (`<ds:X509Certificate>`) |

Większość SP wystarczy „nakarmić" adresem metadata — pobiorą z niego EntityID,
endpoint SSO i certyfikat automatycznie.

---

## Rejestracja aplikacji (Service Provider)

**Admin → SAML Identity Provider → Dodaj Service Providera.** Pola:

- **Nazwa** — etykieta w panelu.
- **Entity ID (SP)** — identyfikator aplikacji (z jej metadata).
- **ACS URL** — *AssertionConsumerService* aplikacji (tam trafia asercja).
- **SLO URL** — *SingleLogoutService* (opcjonalnie).
- **Preset** — gotowy zestaw atrybutów (Moodle/Nextcloud/Grafana/Canva/generyczny).
- **Format / pole NameID** — patrz [niżej](#mapowanie-atrybutów-i-nameid).
- **Dozwolone role** — puste = wszyscy; inaczej tylko wskazane role.
- **Podpisy** — podpis asercji (domyślnie tak), całej odpowiedzi, wymóg
  podpisanego żądania (wymaga certyfikatu SP).
- **Certyfikat SP (PEM)** — do weryfikacji podpisu żądań logowania/wylogowania.
- **Mapa atrybutów (JSON)** — opcjonalne nadpisanie presetu.

Można też **zaimportować metadata SP** (wklejając XML) — system wyciągnie
EntityID, ACS, SLO i certyfikat, a resztę uzupełnisz ręcznie.

**Test:** przycisk „otwórz w nowej karcie" przy SP uruchamia logowanie
*IdP-initiated* (`/saml/sso.php?sp=<ID>`).

---

## Konfiguracja konkretnych aplikacji

### Canva

Po stronie Canva (SSO → SAML) podaj dane IdP z [metadata](#endpointy-i-metadata-idp).
Po stronie SZO zarejestruj SP z presetem **Canva** (pola ACS/EntityID
uzupełnią się automatycznie):

| Pole (SZO) | Wartość |
|------------|---------|
| Preset | **Canva** |
| Entity ID (SP) | `https://www.canva.com` |
| ACS URL | `https://www.canva.com/login/saml` |
| Format NameID | E-mail (`emailAddress`) |
| Pole NameID | E-mail |

**Atrybuty wysyłane do Canva:**

| Atrybut SAML | Źródło w SZO |
|--------------|--------------|
| `NameID` | e-mail użytkownika |
| `Email` | `users.email` |
| `FirstName` | `users.first_name` |
| `LastName` | `users.last_name` |

> Canva wymaga, by konta miały wypełnione imię i nazwisko (`first_name`,
> `last_name`). Konta bez tych pól wyślą puste atrybuty.

### Moodle

Wtyczka **Auth: SAML2** (`auth_saml2`). W Moodle wskaż metadata IdP; w SZO
wybierz preset **Moodle**. Atrybuty: `email`, `firstname`, `lastname`,
`username`. NameID = e-mail. W Moodle zmapuj `email → email`,
`firstname → firstname`, `lastname → lastname`.

### Nextcloud

Aplikacja **user_saml** (tryb SAML). Preset **Nextcloud**. Atrybuty: `email`,
`displayname`, `uid`. W Nextcloud ustaw „UID attribute" = `uid` (lub `email`),
„Display name" = `displayname`, „Email" = `email`.

### Grafana

`auth.saml` w `grafana.ini`/env. Preset **Grafana**. Atrybuty: `email`,
`displayName`, `login`, `role`. Zmapuj `assertion_attribute_login = login`,
`assertion_attribute_email = email`, `assertion_attribute_name = displayName`,
opcjonalnie `assertion_attribute_role = role`.

---

## Mapowanie atrybutów i NameID

**Format NameID:**

| Format | Zastosowanie |
|--------|--------------|
| `emailAddress` | e-mail jako identyfikator (najczęstsze) |
| `persistent` | stały, nieodwracalny identyfikator per-SP (hash z `id`+EntityID+APP_KEY) |
| `transient` | jednorazowy identyfikator sesji |
| `unspecified` | dowolny — wg pola NameID |

**Pole NameID** (dla `emailAddress`/`unspecified`): `email`, `username`
(część przed `@`), `id`, `name`.

**Źródła atrybutów** (kolumna `source` w mapie JSON):
`email`, `username`, `name`/`display_name`, `first_name`, `last_name`,
`role`, `id`, `phone`. Puste wartości są pomijane (atrybut nie jest wysyłany).

Przykład własnej mapy (nadpisuje preset):

```json
[
  {"name": "Email",     "friendly": "Email",     "nameformat": "urn:oasis:names:tc:SAML:2.0:attrname-format:basic", "source": "email"},
  {"name": "FirstName", "friendly": "FirstName", "nameformat": "urn:oasis:names:tc:SAML:2.0:attrname-format:basic", "source": "first_name"},
  {"name": "LastName",  "friendly": "LastName",  "nameformat": "urn:oasis:names:tc:SAML:2.0:attrname-format:basic", "source": "last_name"}
]
```

---

## Polityka dostępu (role)

Pole **Dozwolone role** ogranicza logowanie do wskazanych ról SZO (`admin`,
`editor`, `viewer`, `crm_user`, …). Puste = wszyscy aktywni użytkownicy.
Odrzucenie jest zapisywane w logu (`result = denied`).

---

## Single Logout (SLO)

- **SP-initiated:** aplikacja wysyła `LogoutRequest` na `/saml/slo.php` →
  SZO kończy lokalną sesję i odsyła podpisany `LogoutResponse` do SP.
- **IdP-initiated:** `/saml/slo.php?action=init` kończy sesję SZO.

> **Ograniczenie:** kaskadowe wylogowanie ze *wszystkich* SP jednocześnie
> (front-channel do wielu aplikacji) nie jest realizowane. Terminowana jest
> sesja SZO oraz odsyłana odpowiedź do SP, który zainicjował wylogowanie.

---

## Bezpieczeństwo

- **Podpis asercji** RSA-SHA256 + Exclusive C14N (domyślnie włączony).
- **ACS zawsze z rejestru SP** — adres z `AuthnRequest` jest ignorowany
  (ochrona przed *open redirect* / przekierowaniem asercji do atakującego).
- **Weryfikacja podpisu żądań** (opcjonalna per-SP): na wiązaniu Redirect —
  podpis nad query stringiem; na POST — wbudowany XML-DSig; wymaga certyfikatu SP.
- **Krótka ważność asercji** (5 min) i `AudienceRestriction` na EntityID SP.
- **Klucz prywatny** poza repo (`certs/` w `.gitignore`, `chmod 600`).
- **Audyt** każdego zdarzenia w `saml_sso_log` (sukces/odmowa/błąd + IP).

---

## Rotacja certyfikatu

```bash
php cli/saml_gen_cert.php --force
```

Po rotacji **wszystkie SP muszą pobrać zaktualizowane metadata IdP** (nowy
certyfikat). Można też wygenerować ponownie z panelu (przycisk „Wygeneruj
ponownie (rotacja)").

---

## Rozwiązywanie problemów

| Objaw | Przyczyna / rozwiązanie |
|-------|--------------------------|
| `Nieznana aplikacja` | EntityID z `AuthnRequest` nie pasuje do żadnego SP — sprawdź rejestr. |
| `Błąd podpisu` | SP ma włączony „wymagaj podpisanego żądania", a podpis jest zły/brak — sprawdź certyfikat SP. |
| Po stronie SP „invalid signature" | SP używa nieaktualnego certyfikatu IdP — niech pobierze metadata ponownie. |
| Puste imię/nazwisko w SP | Konto SZO nie ma `first_name`/`last_name`. |
| `Brak dostępu (rola …)` | Konto ma rolę spoza listy „Dozwolone role" tego SP. |
| `SAML IdP wyłączony` | Włącz przełącznik w Admin → SAML IdP. |
| Brak certyfikatu | `php cli/saml_gen_cert.php` lub przycisk w panelu. |

Wszystkie zdarzenia (z `detail`) widać w **Admin → SAML IdP → Ostatnie
zdarzenia** oraz w tabeli `saml_sso_log`.
