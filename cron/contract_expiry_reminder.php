<?php
/**
 * Skrypt cron: Przypomnienia o wygasających umowach.
 * Uruchamiaj raz dziennie, np. o 8:00:
 *   0 8 * * * php /var/www/html/cron/contract_expiry_reminder.php
 *
 * Wysyła email do opiekuna umowy gdy umowa wygasa za 30, 14, 7 lub 1 dzień.
 * Pomija duplikaty: wysłane przypomnienia są odnotowywane w tabeli settings.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/includes/email_templates.php';

$today = date('Y-m-d');

// Dni przed wygaśnięciem, dla których wysyłamy przypomnienia
$notify_days = [30, 14, 7, 1];

// Tabele i ich krótkie kody do numeru / logu, wraz z kolumnami specyficznymi
// dla danego typu umowy:
//   table        - nazwa tabeli
//   date_col     - kolumna terminu końcowego (dla dzieła: termin_oddania)
//   flag_col     - kolumna "bezterminowa"/"czas_nieokreslony" (lub null gdy brak)
//   name_col     - kolumna z nazwą/imieniem strony umowy (uslugi/inne nie mają imie_nazwisko)
//   has_guardian - czy tabela ma kolumny guardian_editor_id / m365_nie_wylaczaj
//                  (dodane migracją cpc_migrate tylko dla wolontariat/zlecenie/dzielo)
$contract_tables = [
    'wolontariat' => ['table' => 'umowy_wolontariat', 'date_col' => 'data_zakonczenia', 'flag_col' => 'bezterminowa',       'name_col' => 'imie_nazwisko',   'has_guardian' => true],
    'zlecenie'    => ['table' => 'umowy_zlecenie',    'date_col' => 'data_zakonczenia', 'flag_col' => null,                 'name_col' => 'imie_nazwisko',   'has_guardian' => true],
    'dzielo'      => ['table' => 'umowy_dzielo',      'date_col' => 'termin_oddania',   'flag_col' => null,                 'name_col' => 'imie_nazwisko',   'has_guardian' => true],
    'uslugi'      => ['table' => 'umowy_uslugi',      'date_col' => 'data_zakonczenia', 'flag_col' => 'czas_nieokreslony',  'name_col' => 'nazwa_wykonawcy', 'has_guardian' => false],
    'inne'        => ['table' => 'umowy_inne',        'date_col' => 'data_zakonczenia', 'flag_col' => 'czas_nieokreslony',  'name_col' => 'strona_umowy',    'has_guardian' => false],
];

$sent  = 0;
$skip  = 0;
$errs  = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: contract_expiry_reminder\n";

foreach ($contract_tables as $type => $cfg) {
    $table    = $cfg['table'];
    $date_col = $cfg['date_col'];

    $flag_filter = $cfg['flag_col'] ? " AND {$cfg['flag_col']} = 0" : '';
    // m365_nie_wylaczaj: pomijamy przypomnienia dla umów z utrzymanym dostępem
    // po wygaśnięciu — kolumna istnieje tylko tam, gdzie jest guardian_editor_id.
    $keep_filter     = $cfg['has_guardian'] ? ' AND m365_nie_wylaczaj = 0' : '';
    $guardian_select = $cfg['has_guardian'] ? 'guardian_editor_id' : 'NULL AS guardian_editor_id';

    try {
        // Pobierz wszystkie aktywne, terminowe umowy
        $contracts = db_all(
            "SELECT id, numer_umowy, {$cfg['name_col']} AS imie_nazwisko, {$date_col} AS data_zakonczenia, opiekun, email, {$guardian_select}
             FROM {$table}
             WHERE {$date_col} IS NOT NULL
               AND status NOT IN ('zakończona', 'anulowana', 'rozwiązana')
               {$flag_filter}
               {$keep_filter}",
            []
        );
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] BLAD zapytania dla {$type}: " . $e->getMessage() . "\n";
        $errs++;
        continue;
    }

    foreach ($contracts as $c) {
        $end_date = $c['data_zakonczenia'];
        if (!$end_date) continue;

        $days_left = (int) round(
            (strtotime($end_date) - strtotime($today)) / 86400
        );

        if (!in_array($days_left, $notify_days, true)) continue;

        $contract_id   = (int) $c['id'];
        $numer         = $c['numer_umowy'] ?? "#{$contract_id}";
        $osoba         = $c['imie_nazwisko'] ?? '';
        $opiekun_name  = $c['opiekun'] ?? '';

        // Ustal adres e-mail opiekuna: najpierw guardian_editor_id -> users.email
        $to_email = '';
        if (!empty($c['guardian_editor_id'])) {
            $user = db_one(
                "SELECT email, imie_nazwisko FROM users WHERE id = ?",
                [(int) $c['guardian_editor_id']]
            );
            if ($user && !empty($user['email'])) {
                $to_email = $user['email'];
                if (empty($opiekun_name) && !empty($user['imie_nazwisko'])) {
                    $opiekun_name = $user['imie_nazwisko'];
                }
            }
        }

        // Fallback: pole email bezpośrednio w rekordzie umowy
        if (!$to_email && !empty($c['email'])) {
            $to_email = $c['email'];
        }

        if (!$to_email) {
            echo "  [SKIP] {$type} #{$numer} ({$osoba}) — brak adresu e-mail opiekuna\n";
            $skip++;
            continue;
        }

        // Sprawdź czy przypomnienie nie było już wysłane
        $settings_key = "expiry_reminder_sent_{$type}_{$contract_id}_{$days_left}";
        $already_sent = db_one(
            "SELECT value FROM settings WHERE key_=?",
            [$settings_key]
        );
        if ($already_sent) {
            $skip++;
            continue;
        }

        // Zbuduj treść e-mail (szablon konfigurowalny w panelu admina)
        $type_label = CONTRACT_TYPES[$type] ?? ucfirst($type);
        $data_pl    = date('d.m.Y', strtotime($end_date));
        $urgency_color = match (true) {
            $days_left <= 7  => '#dc3545',
            $days_left <= 14 => '#fd7e14',
            default          => '#0d6efd',
        };
        $urgency_text = $days_left === 1
            ? 'Umowa wygasa <strong>jutro</strong>!'
            : "Umowa wygasa za <strong>{$days_left} dni</strong>.";

        $rendered = email_tpl_render('contract_expiry', [
            'accent'           => $urgency_color,
            'greeting'         => $opiekun_name ? ', <strong>' . htmlspecialchars($opiekun_name) . '</strong>' : '',
            'urgency_text'     => $urgency_text,
            'type_label'       => $type_label,
            'numer'            => $numer,
            'osoba'            => $osoba,
            'data_zakonczenia' => $data_pl,
            'days_left'        => (string)$days_left,
            'days_label'       => _dni_label($days_left),
        ]);
        if (!$rendered['enabled']) { $skip++; continue; } // wyłączony przez admina
        $subject   = $rendered['subject'];
        $html_body = $rendered['html'];

        try {
            mail_queue_add($to_email, $opiekun_name, $subject, $html_body);

            // Oznacz jako wysłane
            db()->prepare(
                "INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)"
            )->execute([$settings_key, $today]);

            echo "[" . date('Y-m-d H:i:s') . "] {$type} #{$numer} ({$osoba}) — wysłano przypomnienie {$days_left} " . _dni_label($days_left) . "\n";
            $sent++;
        } catch (\Throwable $e) {
            echo "[" . date('Y-m-d H:i:s') . "] BLAD {$type} #{$numer}: " . $e->getMessage() . "\n";
            $errs++;
        }
    }
}

// Przetwórz kolejkę pocztową
try {
    $result = mail_queue_process();
    echo "[" . date('Y-m-d H:i:s') . "] Kolejka pocztowa: wysłano={$result['sent']}, błędy={$result['failed']}\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BLAD kolejki pocztowej: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Wysłano: {$sent}, pominięto: {$skip}, błędy: {$errs}\n";

// ── Pomocnicze ─────────────────────────────────────────────────────────────────

function _dni_label(int $days): string
{
    if ($days === 1) return 'dzień';
    if (in_array($days, [2, 3, 4], true)) return 'dni';
    return 'dni';
}

