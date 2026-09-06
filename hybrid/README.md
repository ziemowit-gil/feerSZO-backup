# Warstwa hybrydowa SZO / TI / Dydaktyka

Architektura Adapter/Driver dla komunikacji między trzema domenami:

- **SZO** — System Wspomagania Zarządzania Organizacją (audyty dostępności, raportowanie jakościowe).
- **TI** — Tyfloinformatyka (profile dostępności uczestników: narzędzia asystujące, preferowane formaty, technologie tyflo).
- **Dydaktyka** — kursy, zapisy, materiały, zgłaszanie zapotrzebowania na adaptację materiałów.

Każda z trzech domen może działać w tym samym procesie co pozostałe (**Monolit
Modularny**) albo na osobnym serwerze (**Niezależny Serwis**) — bez zmiany
logiki biznesowej. O tym, gdzie faktycznie działa dana domena, decyduje
wyłącznie zmienna środowiskowa; kod wołający zna tylko interfejs.

## Struktura katalogów

```
hybrid/
  src/
    Config/DriverConfig.php          — czyta *_DRIVER / *_API_BASE_URL / *_API_KEY z env
    Dto/                             — obiekty przesyłane między domenami
    Contracts/                       — interfejsy (granice komunikacji)
      TyfloProfileClientInterface.php
      SzoQualityReportClientInterface.php
    Ti/
      Service/AccessibilityProfileService.php   — logika domenowa TI (SQLite)
      InternalTyfloProfileClient.php            — Local Driver
      HttpTyfloProfileClient.php                — Remote Driver (Guzzle)
    Szo/
      Service/QualityReportService.php          — logika domenowa SZO (SQLite)
      Service/QualityRequirementService.php
      InternalSzoQualityReportClient.php         — Local Driver
      HttpSzoQualityReportClient.php             — Remote Driver (Guzzle)
    Dydaktyka/
      Service/CourseService.php                 — czyta k30_ti_courses (dane już istniejące)
      Service/EnrollmentService.php              — czyta k30_ti_enrollments
      Service/MaterialAdaptationService.php      — nowa tabela zgłoszeń adaptacji
      MaterialAdaptationController.php           — PRZYKŁAD: spina TI + SZO
    ClientFactory.php                 — jedyne miejsce, które wybiera Local/Http
  demo.php                            — uruchamialny przykład (php hybrid/demo.php)

api/v1/
  hybrid_ti.php          — strona serwerowa TI (woła ją HttpTyfloProfileClient)
  hybrid_szo.php         — strona serwerowa SZO (woła ją HttpSzoQualityReportClient)
  hybrid_dydaktyka.php   — strona serwerowa Dydaktyki (kursy/zapisy/zgłoszenia adaptacji)
```

Namespace `FeerSzo\Hybrid\` jest zarejestrowany w `composer.json` (PSR-4,
`hybrid/src/`) i ładowany automatycznie przez `vendor/autoload.php`, który
`config.php` i tak już wczytuje na starcie każdego pliku wejściowego.

## Przełącznik driverów (zmienne środowiskowe)

Każda domena ma WŁASNY, niezależny przełącznik — SZO i TI mają tak samo
zagwarantowaną niezależność jak Dydaktyka:

```bash
# Wszystko w jednym procesie (domyślnie, gdy zmienne nie ustawione):
TI_DRIVER=local
SZO_DRIVER=local
DYDAKTYKA_DRIVER=local

# Gdy TI jest osobnym serwisem:
TI_DRIVER=http
TI_API_BASE_URL=https://ti.przyklad.pl
TI_API_KEY=<klucz z admin/api_keys.php, uprawnienie hybrid:ti>

# Gdy SZO jest osobnym serwisem:
SZO_DRIVER=http
SZO_API_BASE_URL=https://szo.przyklad.pl
SZO_API_KEY=<klucz, uprawnienie hybrid:szo>

# Gdy Dydaktyka jest osobnym serwisem (inne moduły wołają JĄ przez HTTP —
# patrz api/v1/hybrid_dydaktyka.php):
DYDAKTYKA_DRIVER=http
DYDAKTYKA_API_BASE_URL=https://dydaktyka.przyklad.pl
DYDAKTYKA_API_KEY=<klucz, uprawnienie hybrid:dydaktyka>
```

Klucze API nadaje się w istniejącym panelu `admin/api_keys.php` — trzeba
tylko dopisać uprawnienie (`hybrid:ti` / `hybrid:szo` / `hybrid:dydaktyka`)
do klucza używanego przez wołający serwer.

## Przykład: MaterialAdaptationController

`hybrid/src/Dydaktyka/MaterialAdaptationController::requestAdaptation()`:

1. Sprawdza zapis uczestnika na kurs (Dydaktyka, dane lokalne).
2. Pobiera profil dostępności z TI — przez `TyfloProfileClientInterface`.
3. Zapisuje zgłoszenie adaptacji (Dydaktyka).
4. Zgłasza fakt do SZO jako `QualityReportDto` — przez `SzoQualityReportClientInterface`.

Ten sam kod (zero zmian) działa i gdy wszystko jest jednym procesem, i gdy
TI oraz SZO są osobnymi serwisami — bo kontroler zna wyłącznie interfejsy,
a `ClientFactory` dobiera implementację na podstawie `DriverConfig`.

Uruchomienie:

```bash
php hybrid/demo.php
# albo z konkretnym kursem/kursantem:
php hybrid/demo.php --course=5 --client=42
```

## Nowe tabele SQLite

Warstwa hybrydowa dodaje trzy nowe tabele (migrowane leniwie, przy pierwszym
użyciu odpowiedniego serwisu — bez ingerencji w istniejące tabele TI):

- `k30_ti_accessibility_profiles` (TI) — profile dostępności per kursant.
- `k30_szo_quality_reports` (SZO) — zgłoszenia jakościowe z innych modułów.
- `k30_szo_accessibility_requirements` (SZO) — rejestr wymagań (zasiewany domyślnym zestawem).
- `k30_dyd_material_adaptation_requests` (Dydaktyka) — zgłoszenia adaptacji materiałów.

Kursy i zapisy (`CourseDto`, `EnrollmentDto`) NIE mają własnych tabel —
czytają z istniejących `k30_ti_courses` / `k30_ti_enrollments`, bo te dane
już są częścią tego samego systemu.
