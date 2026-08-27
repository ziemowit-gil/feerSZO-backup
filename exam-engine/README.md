# Equi Exams — silnik testów wiedzy i umiejętności

Usługa w Javie obsługująca moduł **Equi Exams** w TI.
W panelu prowadzącego moduł nazywa się **Egzaminy**, w panelu kursanta — **Testy**.

Silnik jest **bezstanowy**: nie dotyka bazy danych. Dostaje w JSON komplet definicji
pytań i odpowiedzi, oddaje punktację. Trwałość, uprawnienia i interfejs zostają
po stronie aplikacji PHP — dzięki temu do pliku SQLite pisze dalej tylko jeden proces.

Za co odpowiada Java:

| Obszar | Zasób |
|---|---|
| model pytań i budowa definicji z formularza prowadzącego | `POST /api/authoring/question` |
| walidacja i normalizacja ustawień egzaminu | `POST /api/authoring/exam` |
| słownik typów pytań, formuł, języków | `GET /api/authoring/catalogue` |
| składanie wariantu testu dla podejścia (losowanie, tasowanie) | `POST /api/exam/generate` |
| weryfikacja odpowiedzi i ocenianie | `POST /api/exam/grade` |
| przeliczenie po ocenie ręcznej prowadzącego | `POST /api/exam/manual-grade` |
| statystyka zestawu (łatwość, moc różnicująca) | `POST /api/exam/stats` |
| uruchamianie kodu kursanta w piaskownicy | `POST /api/code/run` |
| stan usługi i dostępne języki | `GET /health` |

Wywołania wymagają nagłówka `X-Exam-Token` zgodnego ze zmienną `EXAM_ENGINE_TOKEN`
(gdy zmiennej nie ma, silnik startuje w trybie otwartym i wypisuje ostrzeżenie).

## Obsługiwane rodzaje pytań

| Klucz | Opis |
|---|---|
| `single` | jednokrotny wybór (ABC) |
| `multi` | wielokrotny wybór z punktacją karzącą zgadywanie |
| `truefalse` | zestaw twierdzeń prawda/fałsz |
| `fill_blank` | pytanie z luką |
| `short_answer` | krótka odpowiedź — kryteria słów kluczowych albo ocena prowadzącego |
| `code_fix` | analiza kodu: wskazanie błędnej linii albo przepisanie kodu |
| `code_completion` | luki w kodzie, opcjonalnie sprawdzane uruchomieniowo |
| `code_run` | zadanie programistyczne sprawdzane na danych wejście/wyjście |

Formuły testów: `exam` (kolokwium), `quiz` (kartkówka), `training` (tryb treningowy).

## Piaskownica

Kod kursanta jest uruchamiany w kontenerze silnika, warstwami:

1. **proces** — `ulimit -t` (czas CPU), `ulimit -f` (rozmiar pliku), `ulimit -v`
   (pamięć wirtualna, poza JVM i Node), `umask 077`, twardy limit czasu ściennego
   pilnowany przez silnik, obcinanie strumieni wyjścia;
2. **katalog** — świeży katalog roboczy per uruchomienie, kasowany po wszystkim;
   kontener silnika nie montuje repozytorium aplikacji;
3. **konto** — proces silnika działa jako użytkownik `exam` bez uprawnień;
4. **kontener** — `read_only`, `cap_drop: ALL`, `no-new-privileges`, `pids_limit`,
   limit RAM i CPU, oraz **sieć wewnętrzna bez wyjścia na świat** (`internal: true`).

Świadome kompromisy:

* `/tmp` **nie** jest montowane z `noexec`, bo języki kompilowane (C/C++) uruchamiają
  stamtąd program wynikowy. Zamiast tego działają limity rozmiaru, CPU, RAM i PID-ów.
* W sieci `exam` widoczny jest też kontener aplikacji. Pełne odcięcie sieci procesowi
  kursanta włącza `EXAM_SANDBOX_UNSHARE=1`, ale `unshare -n` wymaga `CAP_SYS_ADMIN`,
  którego domyślnie nie nadajemy. Jeśli w Twoim wdrożeniu zadania z kodem są udostępniane
  szeroko, rozważ nadanie tej zdolności i włączenie zmiennej.

Języki: Python 3, PHP, JavaScript (Node.js), Java, C, C++, powłoka `sh`.
Zasób `GET /health` pokazuje, które z nich są faktycznie dostępne w obrazie.

## Budowanie i uruchamianie

### Razem z całym stosem (zalecane)

```bash
cd docker && docker compose up -d --build exam-engine
```

Usługa `exam-engine` jest częścią `docker/docker-compose.yml`, a kontener aplikacji
dostaje adres silnika zmienną `EXAM_ENGINE_URL` (domyślnie `http://exam-engine:8090`).

### Lokalnie, bez Dockera

Wymaga wyłącznie JDK 17+ — projekt nie ma zależności zewnętrznych i nie potrzebuje
Mavena ani dostępu do repozytoriów przy budowaniu.

```bash
cd exam-engine && ./build.sh run
```

Potem w `config.local.php`:

```php
define('EXAM_ENGINE_URL', 'http://127.0.0.1:8090');
```

Uwaga: uruchomiony tak silnik korzysta z interpreterów zainstalowanych na Twojej
maszynie i **nie ma izolacji kontenerowej** — nadaje się do pracy nad kodem,
nie do przyjmowania rozwiązań od kursantów.

## Zmienne środowiskowe

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `EXAM_PORT` | `8090` | port nasłuchu |
| `EXAM_ENGINE_TOKEN` | — | wspólny sekret wywołań z PHP |
| `EXAM_SANDBOX_DIR` | `/tmp` | katalog roboczy piaskownicy |
| `EXAM_SANDBOX_UNSHARE` | `0` | `1` = `unshare -n` (wymaga `CAP_SYS_ADMIN`) |
| `EXAM_MAX_OUTPUT` | `65536` | limit znaków przechwytywanego wyjścia |
| `EXAM_MAX_SOURCE` | `204800` | limit długości kodu kursanta |
| `EXAM_MAX_TIMEOUT_MS` | `15000` | górny limit czasu jednego uruchomienia |
| `EXAM_WORKERS` | liczba rdzeni × 2 | wątki obsługi HTTP |

## Co się dzieje, gdy silnik nie odpowiada

* **Prowadzący** nie zapisze pytania ani ustawień — komunikat mówi wprost, że usługa
  nie odpowiada. Świadomie nie ma tu drugiej implementacji reguł w PHP.
* **Kursant** rozwiąże test normalnie. Wariant zestawu składa wtedy awaryjnie PHP
  (`ti_exam_compose_fallback`), a oddana praca zostaje zapisana ze znacznikiem
  `engine_status = 'pending'` i trafia na listę „do oceny". Po powrocie usługi
  prowadzący klika **Oceń ponownie automatem** — punktów nie liczy nikt inny niż silnik.

## Układ katalogów

```
src/pl/feer/exam/
├── Main.java                 punkt wejścia
├── Json.java                 parser i serializator JSON (bez zależności)
├── ExamGenerator.java        składanie wariantu testu
├── Grader.java               ocena całego podejścia
├── model/                    modele pytań, odpowiedzi i wyników
├── authoring/                logika panelu prowadzącego (walidacja, statystyki)
├── sandbox/                  uruchamianie kodu kursanta
└── http/                     warstwa HTTP
```
