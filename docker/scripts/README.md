# docker/scripts/ — skrypty administracyjne

Tu fizycznie leżą wszystkie skrypty `.sh` do zarządzania wdrożeniem (poza
trzema wyjątkami niżej). W `docker/` istnieją symlinki o tych samych
nazwach, więc **wszystkie dotychczasowe komendy działają bez zmian**:

```bash
cd /opt/feer-szo/docker
bash rebuild.sh --no-cache
bash update.sh
bash run.sh prod shell
```

Uruchamiaj je zawsze przez symlink w `docker/` (czyli z katalogu `docker/`,
jak wyżej) — skrypty liczą swój `SCRIPT_DIR` na podstawie ścieżki, którą je
wywołano, więc odwołania do plików `docker-compose*.yml` i `.env*` zakładają,
że `SCRIPT_DIR` to `docker/`. Uruchomienie bezpośrednio z
`docker/scripts/nazwa.sh` (z pominięciem symlinku) zepsuje te odwołania.

## Wyjątki — te trzy skrypty NIE są tu przeniesione, zostały w `docker/`

- **`entrypoint.sh`** — kopiowany do obrazu przez `Dockerfile` (`COPY entrypoint.sh /entrypoint.sh`).
  Kontekst budowania to katalog `docker/`; symlink wskazujący poza kontekst
  (`../scripts/...`) mógłby zostać odrzucony przez Docker przy `COPY`.
- **`setup.sh`** — publiczny bootstrap pobierany przez `curl` z Codeberg
  *zanim* repozytorium jest sklonowane (`docker/README.md`). Surowe
  pobieranie pliku po HTTP (`raw/branch/main/docker/setup.sh`) zwraca
  zawartość git blobu — dla symlinku byłby to sam tekst ścieżki docelowej,
  nie treść skryptu. Musi być prawdziwym plikiem pod tą ścieżką.
- **`clean.sh`** — z tego samego powodu: `setup.sh` pobiera go przez `curl`
  jako fallback, zanim repo istnieje na dysku.

Wszystkie pozostałe skrypty są uruchamiane wyłącznie z już sklonowanego
repozytorium (lokalna ścieżka), więc symlink im nie przeszkadza.
