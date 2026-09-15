# Migracja DNS: Cloudflare → MyDevil

Ta notatka opisuje, jak feerSZO przełącza domeny z Cloudflare (stary VPS) na
DNS hostowany przez MyDevil, i **co zrobić, jeśli coś nie działa**.

## Skrót — jeśli coś nie działa

**Nie edytuj DNS ręcznie w panelu Cloudflare ani MyDevil.** Wszystko idzie
przez skrypty w [`bin/`](../bin) — są idempotentne (bezpiecznie uruchomić
ponownie) i mają `--dry-run` do podglądu przed realną zmianą.

| Problem | Skrypt |
|---|---|
| Domena nie odpowiada / zła strona | [`bin/deploy-mydevil.sh`](../bin/deploy-mydevil.sh) — dodaje vhost (`devil www`) + SSL na MyDevil |
| Chcę przetestować lokalnie przed przełączeniem DNS | [`bin/hosts-test-mydevil.sh`](../bin/hosts-test-mydevil.sh) (albo `MD_Wlacz`/`MD_Wylacz` — programy do double-clicku dla kogoś bez terminala) |
| Coś poszło źle po delegacji DNS na MyDevil | `bash bin/mydevil-dns-delegate.sh --revert --only=DOMENA` — przywraca zwykły rekord A w Cloudflare (proxy trzeba włączyć ręcznie, jeśli był) |
| PHP 8.4 wymagane, konto ma 8.3 | [`bin/set-php-version-mydevil.sh`](../bin/set-php-version-mydevil.sh) |
| Sekrety (config.local.php) brakują na MyDevil | [`bin/copy-config-local-to-mydevil.sh`](../bin/copy-config-local-to-mydevil.sh) |
| Baza danych | [`bin/pull-db-from-docker.sh`](../bin/pull-db-from-docker.sh) + `bin/deploy-mydevil.sh --sync-data` |
| Wszystko naraz, od zera | [`bin/migrate-to-mydevil.sh`](../bin/migrate-to-mydevil.sh) |

Każdy skrypt ma `--help` i nagłówek z pełnym opisem. Konfiguracja kont/domen
jest w `bin/.deploy-mydevil.conf` (gitignored — dane konkretnego konta,
nie sekret aplikacji).

## Stan strefy — co się zmienia i co NIE

Dwie strefy Cloudflare są zaangażowane:

- **`feer.org.pl`** — GŁÓWNA domena organizacji (poczta, LDAP, VPN, SAML,
  wiele innych subdomen niezwiązanych z tym projektem). Delegacji NS
  **NIE robimy na całej strefie** — to złamałoby pocztę i inne usługi.
  Zamiast tego: delegacja NS **tylko dla 5 konkretnych subdomen** tego
  systemu (`szo`, `crm`, `ezd`, `zadania`, `ti`), rekord po rekordzie,
  dokładnym dopasowaniem nazwy (`name=host` w zapytaniach do API
  Cloudflare) — reszta strefy `feer.org.pl` zostaje nietknięta.
- **`ngosystem.pl`** — osobna, dedykowana domena tylko dla transparentnych
  aliasów tego systemu (bez poczty/innych usług). Apeks (`ngosystem.pl`
  samo, bez subdomeny) dostaje zwykły rekord A → MyDevil (nie da się
  delegować NS na wierzchołku własnej strefy). Subdomeny (`www`, `szo`,
  `crm`, `ezd`, `zadania`, `ti`) — tak samo jak w `feer.org.pl`, delegacja
  NS per subdomena.

Delegacja NS oznacza: dla danej subdomeny Cloudflare przestaje być
autorytatywne (i przestaje proxy'ować ruch — WAF/DDoS/ukryty origin) —
odpowiada za nią odtąd MyDevil (`dns1.mydevil.net`, `dns2.mydevil.net`).
Dotyczy to dziś aktywnego proxy Cloudflare na: `zadania`/`ti.feer.org.pl`
oraz `www`/`crm`/`ti`/`ezd.ngosystem.pl`.

## Kolejność wykonania (ważne!)

1. **Kod + vhosty + SSL na MyDevil najpierw** — `bin/deploy-mydevil.sh`
   (dodaje `devil www` dla WSZYSTKICH domen z `ALIAS_DOMAINS`, w tym
   ngosystem.pl). Bez tego DNS wskaże na MyDevil, ale trafi w
   nieskonfigurowaną domenę.
2. **Test przez `/etc/hosts`** (bez ruszania prawdziwego DNS) —
   `bin/hosts-test-mydevil.sh` albo `MD_Wlacz`.
3. **Dopiero teraz delegacja DNS** — `bin/mydevil-dns-delegate.sh`
   (per-domenowe potwierdzenie, `--dry-run` domyślnie zalecany jako
   pierwszy krok).

## Dlaczego ngosystem.pl w ogóle działa jako alias

Aplikacja rozpoznaje hosta (`ngosystem.pl`, `feer.org.pl` — bez znaczenia)
przez dopasowanie **prefiksu** `HTTP_HOST` w [`.htaccess`](../.htaccess)
(np. `RewriteCond %{HTTP_HOST} ^crm\.`) oraz w kodzie PHP
([`includes/auth.php`](../includes/auth.php), [`index.php`](../index.php)) —
to działa identycznie pod Apache na MyDevil, jak wcześniej pod Traefik na
starym VPS-ie. Nie jest to więc zależne od konkretnego serwera/proxy.
