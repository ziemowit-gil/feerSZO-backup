# Chameleon Blue — skin Roundcube (moduł Poczta „rc")

Zwendorowany skin webmaila. Źródło: <https://github.com/Anisotropic/chameleon-blue>
(niebieski wariant skina **Chameleon** = *Kolab Enterprise Web Client Skin*,
autor wariantu: Pavel Kosko). Licencja: **AGPL-3.0** (jak oryginalny Chameleon
firmy Kolab Systems AG) — zob. plik `README` obok.

## Jak jest podpięty

- Montowany read-only do kontenera `rc` w `docker/docker-compose.rc.yml`:
  `./roundcube/skins/chameleon-blue → /var/www/html/skins/chameleon-blue`.
- Aktywowany przez `ROUNDCUBEMAIL_SKIN: chameleon-blue` (tamże). Powrót do
  domyślnego: ustaw z powrotem `elastic`.
- **Zależność:** skin dziedziczy po wbudowanym skinie `larry`
  (`"extends": "larry"` w `meta.json`). Larry jest częścią obrazu
  `roundcube/roundcubemail` (skiny „legacy"), więc nie trzeba go wgrywać —
  ale przy zmianie/przypięciu wersji obrazu warto potwierdzić, że nadal tam
  jest: `docker exec feer-rc ls /var/www/html/skins` powinno pokazać `larry`.

## `styles.css` jest artefaktem builda

Upstream **wysyła `styles.css` pusty (0 bajtów)** — trzeba go skompilować z
`styles.less`. Zrobiono to raz i wynik jest zacommitowany. `styles.less` jest
płaski (bez zagnieżdżeń/mixinów/guardów), używa tylko podstawień zmiennych z
`colors.less` oraz funkcji kolorów `lighten` / `darken` / `fade` / `screen`.

Rekompilacja po edycji `colors.less` / `styles.less` — dwie równoważne drogi:

```bash
# A) oryginalne narzędzie (Node less) — jeśli masz node/npm
lessc -x styles.less > styles.css

# B) bez Node — dołączony minimalny kompilator (odwzorowuje matmę kolorów less.js)
python3 compile_less.py colors.less styles.less styles.css
```

Po zmianie `styles.css` przeładuj skin w kontenerze (restart `rc` albo po
prostu ponowny `docker compose ... up -d rc`) i wyczyść cache przeglądarki.

## Uwagi / pułapki

- Skin jest „legacy" (era Roundcube 1.x, rodzina Larry). Na aktualnym
  Roundcube (1.6.x) Larry nadal działa, ale Chameleon nadpisuje kilka
  szablonów (`templates/login.html`, `contactprint.html`, `messageprint.html`,
  `includes/`) — po pierwszym realnym uruchomieniu sprawdź zwłaszcza **stronę
  logowania** (współgra z pluginem `login_notice`, który ukrywa formularz
  login/hasło i wstrzykuje komunikat) oraz widok listy i tworzenia wiadomości.
- Plugin `feer_theme` to nakładka kolorystyczna **skina Elastic** — przy
  aktywnym `chameleon-blue` jej selektory nie trafiają i nie ma efektu
  (nieszkodliwe). Kolory FEER bierze wtedy w całości ten skin.
- `logs/errors.log` w kontenerze (`docker exec feer-rc cat
  /var/www/html/logs/errors.log`) to pierwsze miejsce, gdy skin renderuje się
  źle — Roundcube i tak zwraca HTTP 200 przy błędach (zob. `../README.md` §5).
