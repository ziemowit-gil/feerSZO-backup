# feer_poczta — „Sprawdź pocztę” na pulpicie Nextclouda

Widżet pulpitu „Poczta” z jednym przyciskiem prowadzącym do https://poczta.feer.org.pl/.
Pulpit NC 35 pokazuje przycisk typu `setup`, gdy widżet API nie ma elementów.

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
