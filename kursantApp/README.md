# Panel Kursanta — nowa wersja (Angular)

Nowa wersja panelu kursanta TI, docelowo zastępująca klasyczny panel PHP
(`karty30/ti/kursant/`). Zbudowana na Angular 19 (standalone components,
Angular Material), kolorystyka spójna z resztą systemu (paleta „tz").

## Backend

Osobnego backendu nie trzeba pisać — gotowy i w pełni zgodny z tą aplikacją:
`api/v1/kursant_student.php` (Bearer token, tabela `k30_ti_api_tokens`,
logowanie przez `k30_ti_student_accounts`).

Base URL API jest zaszyty jako ścieżka względna (`/api/v1/kursant_student.php`)
w `kursant-api.service.ts` i `auth.service.ts` — zakłada wspólny origin
z backendem PHP w produkcji.

## Uruchomienie lokalne (dev)

```bash
npm install
npm start   # ng serve --port 4202 --proxy-config proxy.conf.js
```

`proxy.conf.json` przekierowuje `/api` i `/karty30/ti/kursant` na
`http://localhost:3000` (lokalny serwer PHP) — dostosuj port do własnego
środowiska.

## Wdrożenie produkcyjne (Docker + Traefik)

Cały pipeline jest gotowy w `docker/`:

- `Dockerfile` (w tym katalogu) — multi-stage: `ng build --configuration
  production --base-href=/newUI/` → statyczne pliki serwowane przez nginx
  (`nginx.conf`).
- `docker/docker-compose.kursant.yml` — serwis `kursant-ui` + reguły Traefik
  (`Host(ti.feer.org.pl) && PathPrefix(/newUI/)`, HTTPS, strip prefiksu).
- `docker/scripts/setup-kursant.sh` — jednorazowe wdrożenie: ustawia
  `KURSANT_NEW_UI_URL`/`KURSANT_API_TARGET` w `.env.prod`, buduje obraz,
  odpala stack.
- `docker/scripts/rebuild.sh --kursant` — przebudowa/redeploy po zmianach
  w kodzie.

**Uruchamiać na serwerze produkcyjnym** (wymaga Dockera i istniejącego
`docker/.env.prod` — patrz `docker/scripts/make-env.sh`):

```bash
bash docker/scripts/setup-kursant.sh          # pełne wdrożenie
bash docker/scripts/setup-kursant.sh --dry-run # podgląd bez zmian
bash docker/scripts/rebuild.sh --kursant       # redeploy po zmianach
```

Po wdrożeniu:

- Chooser (wybór starego/nowego interfejsu): `https://ti.feer.org.pl/`
- Nowy panel: `https://ti.feer.org.pl/newUI/`
- Panel klasyczny: `https://ti.feer.org.pl/karty30/ti/kursant/login.php`

## Struktura

Standalone components pod `src/app/features/` — po jednym katalogu na
funkcję panelu (dashboard, lekcje, plan, oceny, testy, zadania, wiadomosci,
komunikaty, rozliczenia, licencje, pfron, vlab, online, dysk, aktywnosc,
regulaminy, upowaznieni, ustawienia, problem, login). Layout i nawigacja:
`features/shell/shell.component.ts`.
