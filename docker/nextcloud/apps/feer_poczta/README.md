# feer_poczta — kafle „Poczta” i „Komunikaty z SZO” na pulpicie Nextclouda

Widżet pulpitu „Poczta” z jednym przyciskiem prowadzącym do https://poczta.feer.org.pl/.
Pulpit NC 35 pokazuje przycisk typu `setup`, gdy widżet API nie ma elementów.

## Komunikaty z SZO (od 1.1.0)

Widżet `feer_komunikaty` pobiera `{szo_url}/api/internal/nc_komunikaty.php?uid=…&email=…`
z nagłówkiem `X-Internal-Key` (ustawienie SZO `roundcube_signature_key`). Wyniki w cache 5 min.

```
php occ config:app:set feer_poczta szo_key --sensitive --value "$(cat ~/roundcube/secrets/szo-key)"
php occ config:app:set feer_poczta szo_url --value https://szo.feer.org.pl   # domyślnie
```

## Wdrożenie (nc.feer.org.pl na mydevil)

```
scp -r docker/nextcloud/apps/feer_poczta mydevil:domains/nc.feer.org.pl/public_html/apps/
ssh mydevil 'cd ~/domains/nc.feer.org.pl/public_html && php occ app:enable feer_poczta'
```

Domyślny układ dla nowych kont (pusty = domyślny NC):

```
php occ config:app:set dashboard layout --value "feer_poczta,recommendations,spreed,mail,calendar"
```

Konta, które już mają zapisany układ, widżet dodają przez „Dostosuj” albo
`php occ user:setting <uid> dashboard layout "feer_poczta,<dotychczasowy układ>"`.

## Cofnięcie

```
php occ app:disable feer_poczta && rm -rf apps/feer_poczta
php occ config:app:delete dashboard layout
```
