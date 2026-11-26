# Roundcube na MyDevil: poczta.feer.org.pl

Własny webmail Fundacji od 2026-11-26. Roundcube 1.7.7 na koncie `feer` (s89.mydevil.net),
PHP 8.4, baza MySQL `m1045_roundcube` (mysql89, login `m1045_roundcube`).
Ten katalog to kopia plików z serwera (bez sekretów). Stara wersja dockerowa z logowaniem
Microsoft 365 jest piętro wyżej i nigdzie nie działa.

## Skrzynki

| Login | Serwer IMAP | SMTP |
|---|---|---|
| `jan.kowalski` albo `jan.kowalski@feer.org.pl` | ssl://imap.dpoczta.pl:993 (dhosting) | tls://smtp.dpoczta.pl:587 |
| `x@stud.feer.org.pl` (poczta kursanta) | ssl://titan.sdata.net.pl:993 (sdata) | ssl://titan.sdata.net.pl:465 |

Serwer wybiera plugin `feer_login` po domenie loginu (lista „Serwer” jest usunięta z formularza),
inne domeny (np. @edu) dostają odmowę. Nowa domena: dopisać ją w `feer_login_domains`,
host w `imap_host`, SMTP w `smtp_host` i domenę w `mail_domain` (config.inc.php).

## Układ na serwerze

```
~/roundcube/
  roundcubemail-1.7.7/      paczka (complete), app -> roundcubemail-1.7.7
  config/                   config.inc.php, carddav.config.inc.php (symlinki z app/)
  plugins-feer/             feer_login, feer_theme (symlinki z app/plugins/)
  secrets/                  db-password, des-key, my.cnf (NIE wypisywać)
  bin/backup.sh             mysqldump, 14 dni, do backup/
  logs/                     errors.log, cron.log; tmp/
  dist/                     pobrane paczki
~/domains/poczta.feer.org.pl/public_html -> ~/roundcube/app/public_html
~/domains/poczta.feer.org.pl/.htaccess   PHP 8.4
```

Ustawienia strony: `php_openbasedir` poszerzony o `~/domains/poczta.feer.org.pl` i `~/roundcube`
(domyślnie tylko public_html, wtedy index.php nie widzi `../program`), `sslonly on`,
certyfikat Let's Encrypt z `devil ssl www add 212.91.26.67 le le poczta.feer.org.pl`.
DNS: rekord A `poczta` → 212.91.26.67 w feer-dns.

Cron (blok `# roundcube-poczta`): 3:40 `bin/cleandb.sh`, 3:50 `bin/backup.sh`.

## Kontakty z chmury (CardDAV)

Wtyczka `carddav` 5.1.5 (mstilkerich/rcmcarddav, paczka z zależnościami) w `app/plugins/carddav`.
Preset „Chmura FEER” (`https://nc.feer.org.pl/remote.php/dav/`, adres zablokowany). Hasło do chmury
jest inne niż do poczty, więc login i hasło (najlepiej hasło aplikacji z
https://szo.feer.org.pl/kalendarz-kontakty) użytkownik wpisuje sam: Ustawienia → Kontakty CardDAV.
Tabele wtyczki tworzą się same przy pierwszym logowaniu.

## Stopka z SZO

Plugin `feer_signature` przy każdym logowaniu pobiera stopkę z
`https://szo.feer.org.pl/api/internal/rc_signature.php?email=…` (ta sama co w module Stopka e-mail:
dane organizacji, wygląd, stanowisko i telefon) i wstawia ją jako podpis HTML tożsamości o adresie
zalogowanego. Nadpisuje tylko pusty podpis albo poprzednią stopkę z SZO (skrót w preferencji
`feer_signature_hash`), więc własny podpis użytkownika zostaje. Adresy nieznane SZO (np. @stud) bez zmian.
Klucz: ustawienie SZO `roundcube_signature_key` = `secrets/szo-key`. Nowy klucz: wygenerować, zapisać
w obu miejscach. `htmleditor = 1`, żeby stopka z logo nie zamieniała się w tekst.

## Wtyczki

Własne (`plugins/`, na serwerze `~/roundcube/plugins-feer`): feer_login, feer_theme, feer_signature.
Z paczki: carddav 5.1.5 (rozpakowana ręcznie). Composerem w `app/` (2026-11-26, composer.json/lock
w app): kolab/calendar 3.6.1 (+libcalendaring, libkolab), bennet0496/nextcloud_attachments 2.1,
johndoh/contextmenu 3.3.1, weird-birds/thunderbird_labels 1.6, texxasrulez/persistent_login 1.0.3
(porzucona przez autora; tryb tokenów). Wbudowane: managesieve, identity_select, archive, zipdownload,
markasjunk, newmail_notifier, attachment_reminder, emoticons, hide_blockquote, identicon,
show_additional_headers. Konfiguracje wtyczek: `plugin-config/` = `~/roundcube/config/plugins/*.inc.php`
(dowiązane jako `app/plugins/X/config.inc.php`).

- Kalendarz: sterownik `database` (w bazie Roundcube). CalDAV do chmury odpada, bo sterownik loguje się
  hasłem poczty, a hasło chmury jest inne.
- nextcloud_attachments: od razu Login Flow chmury (jednorazowa zgoda), pliki w „Załączniki z poczty/RRRR/MM”.
- managesieve: `tls://%h:4190` — działa na dPoczcie (Dovecot Pigeonhole), sdata nie ma ManageSieve.
- Zamiast wtyczek threading_as_default i automatic_addressbook: `default_list_mode = threads`
  i wbudowane „Zebrani odbiorcy/nadawcy”.
- `composer audit`: pear/http_request2 < 2.7 (CVE-2025-43717, XSS), przypięty przez libkolab (~2.5);
  `vendor/` jest poza katalogiem strony, więc nie jest wystawiony.
- Znak wodny pustego podglądu: `app/skins/elastic/watermark.html` → `plugins-feer/feer_theme/watermark.html`
  (oryginał `watermark.html.orig`). Przeglądarki trzymają ten plik 30 dni.

## Chmura: połączenie i „Zapisz w chmurze” (feer_cloud_save)

- Połączenie: Nextcloud Login Flow v2 (w chmurze logowanie kontem FEER przez Logto, nie hasłem
  poczty). Wynik w preferencji `nextcloud_login` (loginName, appPassword) — tej samej, której używa
  nextcloud_attachments. Po zalogowaniu (tylko `feer_cloud_prompt_hosts`, raz na sesję) okienko:
  Połącz / Przypomnij za tydzień / Nie pokazuj więcej (`feer_cloud_prompt`). Urządzenie w chmurze:
  „Poczta FEER (poczta.feer.org.pl)” — tam też się je usuwa.
- Menu załącznika ▾: „Zapisz w chmurze” / „Zapisz wszystkie w chmurze” → WebDAV do
  `Załączniki z poczty/Odebrane/RRRR-MM/`, zajęta nazwa → „ (2)”. Bez połączenia — okienko połączenia.
- Polskie tłumaczenie nextcloud_attachments: `l10n/nextcloud_attachments/pl_PL.inc` =
  `~/roundcube/l10n/…` dowiązane do `app/plugins/nextcloud_attachments/l10n/pl_PL.inc`.
- UWAGA: nextcloud_attachments trzyma hasło aplikacji chmury jawnym tekstem w preferencjach (baza).

## Kalendarz i kontakty z chmury (feer_cloud_dav)

Po połączeniu z chmurą (Login Flow, konto FEER/Logto) kalendarz przechodzi na `caldav`
(nc.feer.org.pl, hasło aplikacji w adresie — kolab_dav_client czyta user:pass z URL-a), bez połączenia
`database`. `calendar_driver` NIE może być w configu wtyczki calendar (jej load_config nadpisałby
ustawienie). Kontakty: preset carddav „Chmura FEER” liczony z tego samego połączenia
(`carddav.config.inc.php`), odświeżany raz na sesję i zaraz po połączeniu. Ustawienie własnych wtyczek:
`feer_cloud_server` (nie `nextcloud_attachment_server`, które w task=calendar nie jest ładowane).

## Książka „Fundacja FEER” (feer_directory)

Osoby jak w /katalog SZO (`api/internal/rc_directory.php`, telefon tylko publiczny), tylko do odczytu,
też w autouzupełnianiu; tylko skrzynki `feer_directory_hosts`. Kopia: `tmp/feer_directory.json` (1 h).
Wymaga wdrożenia SZO z endpointem. LDAP (ldap-prod.feer.org.pl) nie istnieje — nie używać.

## Zabezpieczenia

- 2FA: twofactor_gauthenticator (GitHub alexandregz, commit b6afa8f44b, nie ma na Packagist) w
  `~/roundcube/plugins-ext/`, dobrowolne, sekret szyfrowany des_key, „zaufaj na 30 dni”; zachęta po
  logowaniu (feer_cloud_save, „Przypomnij za tydzień”, bez „nigdy”).
- S/MIME: takenek/mimeshield 1.0.3 (composer). Klucz główny `secrets/mimeshield.key` (0400) —
  KOPIA OSOBNO od kopii bazy. Tabele `mimeshield_*` założone ręcznie (`SQL/mysql.initial.sql`).
  Zaufane CA: `config/smime-roots.pem` = repo `ca/smime-roots.pem` — 83 główne CA z zaufaniem do
  poczty (`CKA_TRUST_EMAIL_PROTECTION = TRUSTED_DELEGATOR`) z Mozilla NSS `certdata.txt`, bez wygasłych
  i bez „email distrust-after” (m.in. Certum, SZAFIR, Actalis, Sectigo, DigiCert, GlobalSign, HARICA).
  Odświeżanie (raz na kwartał): pobrać
  https://hg.mozilla.org/mozilla-central/raw-file/tip/security/nss/lib/ckfw/builtins/certdata.txt,
  `python3 -I tools/smime_roots.py certdata.txt ca/smime-roots.pem`, wgrać do `~/roundcube/config/`.
  OpenSSL w PHP 3.0.20 < 3.0.23 (CVE-2026-35189) — zależy od łatki MyDevil (`diag` ostrzega).
- Foldery specjalne stałe (`dont_override`), archiwum dPoczty = `Archives`.
- `ip_check = true`: zmiana IP kończy sesję („Błąd sesji bądź sesja wygasła”).
- Link „Odzyskaj dostęp” → szo.feer.org.pl/nowehaslo (`feer_login_recovery_url`).

## Composer — PUŁAPKA

Instalator wtyczek (roundcube/plugin-installer) przepisuje `$config['plugins']` w
`app/config/config.inc.php` (= nasz config przez dowiązanie) i gubi się na komentarzach z nawiasami —
2026-11-26 dał HTTP 500 na ~2 min. Przed `composer require|install`: `mv config/config.inc.php
config/config.inc.php.link; cp ~/roundcube/config/config.inc.php config/`, po: przywrócić dowiązanie,
wtyczkę dopisać ręcznie, tabele z `SQL/mysql.initial.sql` wtyczki.

## Wygląd

`feer_theme` = nakładka CSS na skin Elastic (barwy SZO, karta logowania z logo, tryb ciemny).
Arkusz dołączany jako `feer_theme.css` BEZ `?v=`: Roundcube 1.7 sprawdza `is_file()` na tej nazwie
i z dopiskiem po cichu go pomija; wersję `?s=mtime` dopisuje sam.

## Aktualizacja

1. `bin/backup.sh`
2. pobrać `roundcubemail-X-complete.tar.gz` do `dist/`, sprawdzić sha256 z GitHuba, rozpakować do `~/roundcube`
3. w nowym katalogu: symlinki `config/config.inc.php`, `plugins/carddav` (rozpakować nową carddav),
   `plugins/feer_login`, `plugins/feer_theme`, `plugins/feer_signature`, konfiguracje wtyczek z
   `config/plugins/*.inc.php`, `skins/elastic/watermark.html` → `plugins/feer_theme/watermark.html`;
   wtyczki composerowe: skopiować composer.json/lock i `composer install`; usunąć `logs`, `temp`
4. `php84 bin/update.sh` w nowym katalogu, potem `ln -sfn roundcubemail-X app`
5. obejrzeć logowanie, listę, okno pisania, Ustawienia (Elastic bywa zmieniany)

Cofnięcie: `ln -sfn roundcubemail-1.7.7 app` i przywrócenie bazy
(`gunzip -c backup/plik.sql.gz | mysql --defaults-extra-file=secrets/my.cnf m1045_roundcube`).

Usunięcie całości: blok crona, `devil www del poczta.feer.org.pl --remove`,
`devil ssl www del 212.91.26.67 poczta.feer.org.pl`, `devil mysql db del m1045_roundcube`,
`devil mysql user del m1045_roundcube`, `rm -rf ~/roundcube`, rekord DNS.
