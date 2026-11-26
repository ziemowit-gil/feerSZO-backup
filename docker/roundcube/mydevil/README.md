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

## Wygląd

`feer_theme` = nakładka CSS na skin Elastic (barwy SZO, karta logowania z logo, tryb ciemny).
Arkusz dołączany jako `feer_theme.css` BEZ `?v=`: Roundcube 1.7 sprawdza `is_file()` na tej nazwie
i z dopiskiem po cichu go pomija; wersję `?s=mtime` dopisuje sam.

## Aktualizacja

1. `bin/backup.sh`
2. pobrać `roundcubemail-X-complete.tar.gz` do `dist/`, sprawdzić sha256 z GitHuba, rozpakować do `~/roundcube`
3. w nowym katalogu: symlinki `config/config.inc.php`, `plugins/carddav` (rozpakować nową carddav),
   `plugins/feer_login`, `plugins/feer_theme`; usunąć `logs`, `temp`
4. `php84 bin/update.sh` w nowym katalogu, potem `ln -sfn roundcubemail-X app`
5. obejrzeć logowanie, listę, okno pisania, Ustawienia (Elastic bywa zmieniany)

Cofnięcie: `ln -sfn roundcubemail-1.7.7 app` i przywrócenie bazy
(`gunzip -c backup/plik.sql.gz | mysql --defaults-extra-file=secrets/my.cnf m1045_roundcube`).

Usunięcie całości: blok crona, `devil www del poczta.feer.org.pl --remove`,
`devil ssl www del 212.91.26.67 poczta.feer.org.pl`, `devil mysql db del m1045_roundcube`,
`devil mysql user del m1045_roundcube`, `rm -rf ~/roundcube`, rekord DNS.
