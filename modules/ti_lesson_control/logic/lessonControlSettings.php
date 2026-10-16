<?php
/**
 * modules/ti_lesson_control/logic/lessonControlSettings.php — statusy i ustawienia Kontroli Lekcji i Wpisów.
 *
 * Ustawienia wysyłek leżą w tabeli settings (klucze ti_lc_*), czytane wprost z bazy — bez
 * pamięci podręcznej org_setting() (agent CRON i panel w jednym procesie widzą świeże wartości).
 * WSZYSTKIE wysyłki są domyślnie WYŁĄCZONE; sama kontrola (oznaczanie statusów) jest włączona.
 * Reguły kompletności/terminu: tabela k30_ti_lc_rules (LessonControlPolicy).
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

/** Statusy kontroli automatycznej i weryfikacji ręcznej + etykiety i kolory (Bootstrap). */
final class TiLcStatus
{
    // ── automat ──
    public const NIE_DOTYCZY   = 'NIE_DOTYCZY';
    public const OCZEKUJE      = 'OCZEKUJE';
    public const PRAWIDLOWY    = 'PRAWIDLOWY';
    public const BRAK_WPISU    = 'BRAK_WPISU';
    public const NIEPELNY_WPIS = 'NIEPELNY_WPIS';
    public const OPOZNIONY     = 'OPOZNIONY';
    // ── weryfikacja ręczna ──
    public const NIEZWERYFIKOWANY = 'NIEZWERYFIKOWANY';
    public const ZATWIERDZONY     = 'ZATWIERDZONY';
    public const DO_POPRAWY       = 'DO_POPRAWY';
    public const ZAKWESTIONOWANY  = 'ZAKWESTIONOWANY';
    public const POPRAWIONY       = 'POPRAWIONY';

    public const AUTO = [
        self::PRAWIDLOWY    => ['Prawidłowy',    'success'],
        self::OPOZNIONY     => ['Opóźniony',     'warning'],
        self::NIEPELNY_WPIS => ['Niepełny wpis', 'warning'],
        self::BRAK_WPISU    => ['Brak wpisu',    'danger'],
        self::OCZEKUJE      => ['Oczekuje',      'secondary'],
        self::NIE_DOTYCZY   => ['Nie dotyczy',   'light'],
    ];
    public const REVIEW = [
        self::NIEZWERYFIKOWANY => ['Niezweryfikowany', 'secondary'],
        self::POPRAWIONY       => ['Poprawiony — do weryfikacji', 'info'],
        self::DO_POPRAWY       => ['Do poprawy',       'warning'],
        self::ZAKWESTIONOWANY  => ['Zakwestionowany',  'danger'],
        self::ZATWIERDZONY     => ['Zatwierdzony',     'success'],
    ];
    /** Statusy automatu, które wymagają działania prowadzącego. */
    public const NEEDS_ACTION = [self::BRAK_WPISU, self::NIEPELNY_WPIS];

    public static function label(string $s): string { return (self::AUTO[$s] ?? self::REVIEW[$s] ?? [$s])[0]; }
    public static function color(string $s): string { return (self::AUTO[$s] ?? self::REVIEW[$s] ?? [$s, 'secondary'])[1]; }
}

/** Elementy wpisu (kod reguły → etykieta) — wspólne dla walidatora, panelu i e-maili. */
const TI_LC_ELEMENTS = [
    'topic'      => 'temat zajęć',
    'attendance' => 'obecność',
    'curriculum' => 'zrealizowany materiał (sylabus)',
    'materials'  => 'materiały do lekcji',
    'homework'   => 'zadanie domowe',
];

final class LessonControlSettings
{
    public const DEFAULTS = [
        'ti_lc_enabled'             => '1',        // automatyczne oznaczanie statusów
        'ti_lc_lookback_days'       => '30',       // ile dni wstecz kontroluje agent
        'ti_lc_start_date'          => '2026-09-01', // lekcje sprzed tej daty pomijane (Y-m-d) — też w Audycie dzienników
        'ti_lc_remind_enabled'      => '0',        // e-mail do prowadzącego
        'ti_lc_sms_enabled'         => '0',        // dodatkowo SMS do prowadzącego
        'ti_lc_remind_hour'         => '8',        // od tej godziny wychodzą przypomnienia
        'ti_lc_remind_max'          => '3',        // max przypomnień o jednej lekcji
        'ti_lc_remind_max_age_days' => '14',       // starszych zaległości nie przypominamy
        'ti_lc_escalate_after'      => '2',        // po tylu przypomnieniach → eskalacja w raporcie
        'ti_lc_notify_on_return'    => '0',        // e-mail do prowadzącego po „do poprawy”
        'ti_lc_report_enabled'      => '0',        // raport do nadzorującego
        'ti_lc_report_frequency'    => 'daily',    // daily | weekly
        'ti_lc_report_weekday'      => '1',        // 1 = poniedziałek (dla weekly)
        'ti_lc_report_hour'         => '7',
        'ti_lc_report_recipients'   => '',         // e-maile po przecinku; puste = kierownicy TI
        'ti_lc_report_last_period'  => '',         // znacznik ostatnio wysłanego okresu
    ];

    public static function get(string $key): string {
        try {
            $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
            if ($r !== null) return (string)$r['value'];
        } catch (\Throwable $e) {}
        return self::DEFAULTS[$key] ?? '';
    }

    public static function int(string $key): int { return (int)self::get($key); }

    /** Początek kontroli (Y-m-d) albo '' = bez ograniczenia. Wspólny dla Kontroli wpisów i Audytu dzienników. */
    public static function startDate(): string {
        $d = self::get('ti_lc_start_date');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
    }
    public static function on(string $key): bool { return self::get($key) === '1'; }

    public static function set(string $key, string $value): void {
        if (!array_key_exists($key, self::DEFAULTS)) throw new \InvalidArgumentException('Nieznane ustawienie: ' . $key);
        if (db_one("SELECT 1 FROM settings WHERE key_=?", [$key])) db_exec("UPDATE settings SET value=? WHERE key_=?", [$value, $key]);
        else db_exec("INSERT INTO settings (key_, value) VALUES (?,?)", [$key, $value]);
    }

    /** @return array<string,string> wszystkie ustawienia z wartościami domyślnymi */
    public static function all(): array {
        $out = [];
        foreach (array_keys(self::DEFAULTS) as $k) $out[$k] = self::get($k);
        return $out;
    }
}
