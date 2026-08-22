# SnappyMail „sm" — trzeci klient poczty

> **Obraz:** `djmaze/snappymail` · **Domena:** `${SM_DOMAIN}` (np. `sm.feer.org.pl`)
> **Compose:** `docker/docker-compose.snappy.yml` · **Port w kontenerze:** 8888

## 1. Co to jest i po co

Alternatywny webmail obok Roundcube — najszybszy interfejs z całej trójki
(jednostronicowa aplikacja, instalowalna z przeglądarki jako PWA), rozwijany
fork RainLoopa. Sama poczta: **bez kalendarza, Teams i skrzynek
współdzielonych** — tak jak Roundcube.

Wybór klienta należy do użytkownika (strona `poczta.feer.org.pl`, kafel „Poczta
organizacji" w panelu wolontariusza). Katalog klientów i ich cechy:
`includes/webmail_clients.php` — **jedno źródło prawdy**; ten kontener pojawia
się w interfejsach tylko wtedy, gdy w Admin → Poczta → Webmail wpiszesz jego
adres.

## 2. Uruchomienie

```bash
cd docker
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  -f docker-compose.snappy.yml --env-file .env.prod up -d sm
```

W `.env.prod` ustaw `SM_DOMAIN=sm.feer.org.pl` (i rekord A tej nazwy na IP
serwera — Let's Encrypt wystawi cert przy pierwszym żądaniu).

## 3. Konfiguracja — kolejność ma znaczenie

Wszystko robi się w panelu admina SnappyMaila (`https://${SM_DOMAIN}/?admin`,
login `admin`, hasło pokazywane/ustawiane przy pierwszym wejściu):

1. **Domains** → dodaj domenę `feer.org.pl`:
   - IMAP: `outlook.office365.com`, port `993`, `SSL/TLS`
   - SMTP: `smtp.office365.com`, port `587`, `STARTTLS`, „Use auth" = tak
2. **Login / OAuth2** → włącz logowanie przez Microsoft. **To jest warunek
   działania:** Microsoft trwale wyłączył logowanie hasłem (Basic Auth) do
   IMAP/SMTP w Exchange Online, więc bez OAuth2 nikt się nie zaloguje —
   SnappyMail pokaże „Authentication failed", a w logu kontenera zobaczysz
   odrzucone `LOGIN`.
3. **Osobna rejestracja aplikacji w Azure AD** (tak jak dla Roundcube — redirect
   URI musi się dokładnie zgadzać, więc nie da się użyć tej samej rejestracji):
   - Redirect URI: `https://${SM_DOMAIN}/?OAuth2` (dokładny format potwierdź
     w panelu SnappyMaila — pole samo pokazuje oczekiwany adres)
   - API permissions (Delegated): `openid`, `email`, `profile`, `offline_access`
     oraz **Office 365 Exchange Online**: `IMAP.AccessAsUser.All`, `SMTP.Send`
   - **Grant admin consent** — bez tego logowanie userów kończy się `AADSTS65001`
   - Client ID + secret wklejasz w panelu admina SnappyMaila
4. **Zamknij panel admina** — po skonfigurowaniu ustaw `SM_SECURE_ADMIN=1`
   w `.env.prod` i przeładuj kontener (`up -d --force-recreate sm`). Panel admina
   przestaje być dostępny z internetu.
5. **Dopiero teraz** wpisz adres w głównej aplikacji: Admin → Poczta → Webmail →
   SnappyMail. Wcześniej użytkownicy zobaczyliby przycisk do klienta, w którym
   nie da się zalogować.

## 4. Pułapki

- **`up -d sm` pisze „Container feer-sm Running"** — Compose nie przeładował
  kontenera po zmianie konfiguracji. Wymuś: `up -d --force-recreate sm`
  (ta sama pułapka co przy Roundcube, zob. `../roundcube/README.md` §5).
- **Konfiguracja żyje w woluminie `sm_data`**, nie w repo — po `docker compose
  down -v` trzeba ją zrobić od nowa. Kopię można zrobić przez
  `docker run --rm -v feer_sm_data:/d -v $PWD:/b alpine tar czf /b/sm_data.tgz /d`.
- **Obraz nie jest przypięty do wersji** (`:latest`) — przed produkcją wpisz
  konkretny tag, żeby aktualizacja nie zaskoczyła zmianą interfejsu.
- **To nie jest zamiennik Outlooka** — użytkownikom, którzy potrzebują
  kalendarza, spotkań albo skrzynki `fundacja@feer.org.pl`, zestawienie na
  `poczta.feer.org.pl` wskazuje Outlooka. Nie „naprawiaj" tego w SnappyMailu.
