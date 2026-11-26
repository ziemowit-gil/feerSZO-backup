# feer_poczta — kafel „SZO” na pulpicie Nextclouda

Jeden widżet `feer_szo` (od 1.2.0, zastąpił kafle „Poczta” i „Komunikaty z SZO”):

1. „Sprawdź pocztę” → https://poczta.feer.org.pl/ (zawsze),
2. do 3 komunikatów z SZO (przypięte i najnowsze, z dopiskiem „Nowy komunikat”),
3. moje zadania z SZO (przypisane, nieukończone; najbliższy termin pierwszy, po terminie na czerwono),
   razem najwyżej 7 wierszy; na dole „Otwórz SZO”.

Dane: `{szo_url}/api/internal/nc_pulpit.php?uid=…&email=…&limit=7` z nagłówkiem `X-Internal-Key`
(ustawienie SZO `roundcube_signature_key`). Wyniki w cache 5 min. Bez połączenia z SZO kafel
pokazuje pocztę i wiersz „Nie udało się pobrać danych z SZO”.

```
php occ config:app:set feer_poczta szo_key --sensitive --value "$(cat ~/roundcube/secrets/szo-key)"
php occ config:app:set feer_poczta szo_url --value https://szo.feer.org.pl   # domyślnie
```

## Wdrożenie (nc.feer.org.pl na mydevil)

```
scp -r docker/nextcloud/apps/feer_poczta mydevil:domains/nc.feer.org.pl/public_html/apps/
ssh mydevil 'cd ~/domains/nc.feer.org.pl/public_html && php occ upgrade'   # albo app:enable przy pierwszej instalacji
```

Przy aktualizacji usuń z serwera pliki, których nie ma w repo (scp ich nie kasuje).

Domyślny układ: `php occ config:app:set dashboard layout --value "feer_szo,recommendations,spreed,mail,calendar"`.
Zapisane układy osób zmieniać skryptem PHP przez `IUserConfig::setValueString` (`occ user:setting`
myli się przy istniejących wpisach).

## Cofnięcie

```
php occ app:disable feer_poczta && rm -rf apps/feer_poczta
php occ config:app:delete dashboard layout
```
