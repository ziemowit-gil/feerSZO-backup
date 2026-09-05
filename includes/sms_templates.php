<?php
/**
 * includes/sms_templates.php — Rejestr edytowalnych szablonów SMS (moduł TI/Dydaktyka).
 *
 * Wzorowane 1:1 na includes/email_templates.php (rejestr + nadpisanie w bazie +
 * włącz/wyłącz), ale dla SMS: brak HTML, czysty tekst, liczy się długość
 * (limit segmentu SMS = 160 znaków bez polskich diakrytyków / 70 z nimi).
 *
 * Świadomie NIE obejmuje SMS-ów dostarczających dane logowania/hasła/kody
 * jednorazowe (np. hasła do panelu, kody weryfikacyjne telefonu, konta MS365)
 * — to komunikaty bezpieczeństwa, nie "powiadomienia", i nie powinny być
 * edytowalne przez panel (ryzyko przypadkowego usunięcia {{placeholdera}}
 * i wysłania hasła bez treści albo złamania formatu kodu).
 */

function sms_tpl_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS sms_templates (
            key_       TEXT PRIMARY KEY,
            message    TEXT NOT NULL DEFAULT '',
            enabled    INTEGER NOT NULL DEFAULT 1,
            updated_at DATETIME,
            updated_by INTEGER
        )");
    } catch (\Throwable $e) {}
}

/**
 * Rejestr znanych SMS-ów systemowych modułu TI.
 * Każdy wpis: label, group, icon, auto (czy wysyłka automatyczna — enabled=0
 * faktycznie wstrzymuje), description, message (domyślna treść z {{zmienną}}),
 * vars => [name => [label, sample]].
 */
function sms_tpl_registry(): array
{
    return [
        'ti_lesson_added' => [
            'label'       => 'Nowa lekcja dodana',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-calendar-plus',
            'auto'        => true,
            'description' => 'Wysyłane do wszystkich aktywnych uczestników kursu, gdy prowadzący/admin doda nową lekcję (course.php).',
            'message'     => 'Nowe zajecia: {{course}} - {{when}}. Szczegoly w panelu kursanta.',
            'vars'        => [
                'course' => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'when'   => ['label' => 'Data i godzina', 'sample' => '12.09.2026 o 10:00'],
            ],
        ],
        'ti_reschedule_notify' => [
            'label'       => 'Zmiana terminu pojedynczej lekcji',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-calendar2-range',
            'auto'        => true,
            'description' => 'Wysyłane do uczestników kursu, gdy prowadzący zmieni termin jednej lekcji i zaznaczy "powiadom SMS" (panel dydaktyka).',
            'message'     => 'Zmiana terminu zajec: {{course}} -> {{when}}. Szczegoly w panelu kursanta.',
            'vars'        => [
                'course' => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'when'   => ['label' => 'Nowy termin', 'sample' => '12.09.2026 o 10:00'],
            ],
        ],
        'ti_bulk_reschedule' => [
            'label'       => 'Zbiorcza zmiana terminu',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-arrows-move',
            'auto'        => true,
            'description' => 'Wysyłane do uczestników kursu po zbiorczym przesunięciu wielu lekcji naraz (zakładka Lekcje, panel dydaktyka).',
            'message'     => 'Zmiana terminow zajec: {{course}} - przesunieto {{count}} lekcji. Szczegoly w panelu kursanta.',
            'vars'        => [
                'course' => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'count'  => ['label' => 'Liczba przesuniętych lekcji', 'sample' => '4'],
            ],
        ],
        'ti_lesson_reminder' => [
            'label'       => 'Przypomnienie o jutrzejszej lekcji',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-alarm',
            'auto'        => true,
            'description' => 'Cron (cron/ti_lesson_reminders.php, raz dziennie rano) — przypomnienie dla kursantów o zajęciach zaplanowanych na jutro.',
            'message'     => 'Przypomnienie: masz zajecia jutro{{when}} - {{course}}.',
            'vars'        => [
                'course' => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'when'   => ['label' => 'Godzina (z wiodącym " o HH:MM" albo puste)', 'sample' => ' o 10:00'],
            ],
        ],
        'ti_low_attendance' => [
            'label'       => 'Niska frekwencja',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-graph-down',
            'auto'        => true,
            'description' => 'Wysyłane do kursanta (i opiekuna małoletniego), gdy frekwencja na kursie spadnie poniżej progu ustawionego w systemie.',
            'message'     => 'Niska frekwencja: {{course}} - {{pct}}% (prog {{threshold}}%). Prosimy o regularna obecnosc.',
            'vars'        => [
                'course'    => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'pct'       => ['label' => 'Aktualna frekwencja (%)', 'sample' => '55'],
                'threshold' => ['label' => 'Próg ostrzeżenia (%)', 'sample' => '70'],
            ],
        ],
        'ti_new_message' => [
            'label'       => 'Nowa wiadomość w panelu kursanta',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-envelope',
            'auto'        => true,
            'description' => 'Wysyłane do kursanta, gdy dostanie nową wiadomość w panelu (zakładka Wiadomości), jeśli ma włączone powiadomienia SMS.',
            'message'     => '{{org}}: nowa wiadomosc w panelu kursanta. Zaloguj sie, aby przeczytac.',
            'vars'        => [
                'org' => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        'rk_pending_notify' => [
            'label'       => 'Wpis na zajęcia czeka na zatwierdzenie',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-person-check',
            'auto'        => true,
            'description' => 'Wysyłane do prowadzącego (pierwszy stopień) lub kierownika (drugi stopień) po wpisie kursanta na zajęcia — zapisy TI (rekrutacja).',
            'message'     => 'Nowy wpis na zajecia: {{student}}, {{when}}. Wymaga zatwierdzenia w panelu. {{org}}',
            'vars'        => [
                'student' => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'when'    => ['label' => 'Termin (z ew. adnotacją serii)', 'sample' => '05.10.2026 16:00 (seria x3)'],
                'org'     => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        'rk_parent_confirm' => [
            'label'       => 'Prośba o zgodę rodzica (zapis małoletniego)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-person-hearts',
            'auto'        => true,
            'description' => 'Informacyjny SMS do rodzica/opiekuna po zapisie małoletniego kursanta na zajęcia — decyzja i tak zapada w mailu z linkiem.',
            'message'     => 'Kursant {{student}} zapisal sie na zajecia {{when}}. Prosimy zatwierdzic rezerwacje - link wyslalismy e-mailem na adres {{email}}. {{org}}',
            'vars'        => [
                'student' => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'when'    => ['label' => 'Termin', 'sample' => '05.10.2026 16:00'],
                'email'   => ['label' => 'E-mail rodzica', 'sample' => 'rodzic@example.com'],
                'org'     => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        'rk_waitlist_slot_free' => [
            'label'       => 'Zwolniło się miejsce (lista oczekujących)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-bell',
            'auto'        => true,
            'description' => 'Wysyłane do pierwszej osoby z listy oczekujących, gdy na wcześniej pełnym terminie zwolni się miejsce.',
            'message'     => 'Zwolnilo sie miejsce na termin {{when}} - bylas(es) na liscie oczekujacych. Zapisz sie w panelu, dopoki miejsce wolne.',
            'vars'        => [
                'when' => ['label' => 'Termin', 'sample' => '05.10.2026 16:00'],
            ],
        ],
    ];
}

/** Czy klucz istnieje w rejestrze. */
function sms_tpl_exists(string $key): bool
{
    return array_key_exists($key, sms_tpl_registry());
}

/** Czy szablon jest wysyłany automatycznie — enabled=0 faktycznie wstrzymuje wysyłkę. */
function sms_tpl_is_auto(string $key): bool
{
    $reg = sms_tpl_registry();
    return !empty($reg[$key]['auto']);
}

/**
 * Zwraca aktualny szablon (nadpisanie z bazy lub domyślny z rejestru).
 * @return array{message:string, enabled:bool, is_custom:bool}|null
 */
function sms_tpl_get(string $key): ?array
{
    $reg = sms_tpl_registry();
    if (!isset($reg[$key])) return null;
    sms_tpl_migrate();

    $def = $reg[$key];
    $row = null;
    try {
        $row = db_one("SELECT message, enabled FROM sms_templates WHERE key_=?", [$key]);
    } catch (\Throwable $e) {}

    if ($row) {
        return [
            'message'   => $row['message'] !== '' ? $row['message'] : $def['message'],
            'enabled'   => (int)$row['enabled'] === 1,
            'is_custom' => true,
        ];
    }
    return ['message' => $def['message'], 'enabled' => true, 'is_custom' => false];
}

/** Podstawia {{nazwa}} (spacje tolerowane) wartościami z $vars. */
function sms_tpl_substitute(string $tpl, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
    }, $tpl);
}

/**
 * Renderuje SMS systemowy: zwraca treść z podstawionymi zmiennymi i flagę enabled.
 * Wołający MUSI sam sprawdzić 'enabled' i pominąć wysyłkę, gdy false —
 * ta funkcja tylko renderuje tekst, nie wysyła.
 * @return array{message:string, enabled:bool}
 */
function sms_tpl_render(string $key, array $vars = []): array
{
    $t = sms_tpl_get($key);
    if ($t === null) return ['message' => '', 'enabled' => true];
    return ['message' => sms_tpl_substitute($t['message'], $vars), 'enabled' => $t['enabled']];
}

/** Zapisuje nadpisanie szablonu. */
function sms_tpl_save(string $key, string $message, bool $enabled, ?int $user_id = null): bool
{
    if (!sms_tpl_exists($key)) return false;
    sms_tpl_migrate();
    db()->prepare(
        "INSERT INTO sms_templates (key_, message, enabled, updated_at, updated_by)
         VALUES (?,?,?,datetime('now','localtime'),?)
         ON CONFLICT(key_) DO UPDATE SET
            message=excluded.message, enabled=excluded.enabled,
            updated_at=excluded.updated_at, updated_by=excluded.updated_by"
    )->execute([$key, $message, $enabled ? 1 : 0, $user_id]);
    return true;
}

/** Przywraca szablon do domyślnej treści (usuwa nadpisanie). */
function sms_tpl_reset(string $key): void
{
    sms_tpl_migrate();
    db()->prepare("DELETE FROM sms_templates WHERE key_=?")->execute([$key]);
}

/** Przykładowe wartości zmiennych — do podglądu/testu w edytorze. */
function sms_tpl_sample_vars(string $key): array
{
    $reg = sms_tpl_registry();
    if (!isset($reg[$key])) return [];
    $out = [];
    foreach ($reg[$key]['vars'] as $name => $meta) $out[$name] = (string)($meta['sample'] ?? '');
    return $out;
}
