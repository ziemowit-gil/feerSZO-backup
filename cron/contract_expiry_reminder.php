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

$today = date('Y-m-d');

// Dni przed wygaśnięciem, dla których wysyłamy przypomnienia
$notify_days = [30, 14, 7, 1];

// Tabele i ich krótkie kody do numeru / logu
$contract_tables = [
    'wolontariat' => 'umowy_wolontariat',
    'zlecenie'    => 'umowy_zlecenie',
    'dzielo'      => 'umowy_dzielo',
    'uslugi'      => 'umowy_uslugi',
    'inne'        => 'umowy_inne',
];

$sent  = 0;
$skip  = 0;
$errs  = 0;

echo "[" . date('Y-m-d H:i:s') . "] Start: contract_expiry_reminder\n";

// Tabele z kolumną m365_nie_wylaczaj (dodaną przez cpc_migrate) — pomijamy
// przypomnienia dla umów z utrzymanym dostępem po wygaśnięciu. uslugi/inne
// nie mają tej kolumny, więc filtr nakładamy warunkowo.
$tables_with_keep_flag = ['wolontariat', 'zlecenie', 'dzielo'];

foreach ($contract_tables as $type => $table) {
    $keep_filter = in_array($type, $tables_with_keep_flag, true)
        ? ' AND m365_nie_wylaczaj = 0'
        : '';
    // Pobierz wszystkie aktywne, terminowe umowy
    $contracts = db_all(
        "SELECT id, numer_umowy, imie_nazwisko, data_zakonczenia, opiekun, email, guardian_editor_id
         FROM {$table}
         WHERE bezterminowa = 0
           AND data_zakonczenia IS NOT NULL
           AND status NOT IN ('zakończona', 'anulowana', 'rozwiązana')
           {$keep_filter}",
        []
    );

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

        // Zbuduj treść e-mail
        $type_label = CONTRACT_TYPES[$type] ?? ucfirst($type);
        $subject    = "Przypomnienie: {$type_label} {$numer} wygasa za {$days_left} " .
                      _dni_label($days_left);

        $data_pl    = date('d.m.Y', strtotime($end_date));
        $urgency_color = match (true) {
            $days_left <= 7  => '#dc3545',
            $days_left <= 14 => '#fd7e14',
            default          => '#0d6efd',
        };

        $html_body = _build_email_html(
            $type_label,
            $numer,
            $osoba,
            $opiekun_name,
            $data_pl,
            $days_left,
            $urgency_color
        );

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

function _build_email_html(
    string $type_label,
    string $numer,
    string $osoba,
    string $opiekun,
    string $data_zakonczenia,
    int    $days_left,
    string $accent_color
): string {
    $urgency_text = match (true) {
        $days_left === 1 => 'Umowa wygasa <strong>jutro</strong>!',
        $days_left <= 7  => "Umowa wygasa za <strong>{$days_left} dni</strong>.",
        default          => "Umowa wygasa za <strong>{$days_left} dni</strong>.",
    };

    $days_label = _dni_label($days_left);

    return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Przypomnienie o wygaśnięciu umowy</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:8px;overflow:hidden;
                    box-shadow:0 2px 8px rgba(0,0,0,.08);max-width:600px;">

        <!-- Nagłówek -->
        <tr>
          <td style="background:{$accent_color};padding:24px 32px;">
            <p style="margin:0;font-size:13px;color:rgba(255,255,255,.8);text-transform:uppercase;
                      letter-spacing:.05em;">System zarządzania umowami</p>
            <h1 style="margin:6px 0 0;font-size:22px;color:#ffffff;font-weight:700;">
              Przypomnienie o wygasającej umowie
            </h1>
          </td>
        </tr>

        <!-- Treść -->
        <tr>
          <td style="padding:32px;">
            <p style="margin:0 0 16px;font-size:15px;color:#333333;">
              Dzień dobry<?= $opiekun ? ', <strong>' . htmlspecialchars($opiekun) . '</strong>' : '' ?>,
            </p>
            <p style="margin:0 0 24px;font-size:15px;color:#333333;">
              {$urgency_text}
              Prosimy o podjęcie stosownych działań.
            </p>

            <!-- Karta umowy -->
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="background:#f8f9fa;border-radius:6px;border-left:4px solid {$accent_color};
                          padding:0;margin-bottom:24px;">
              <tr>
                <td style="padding:20px 24px;">
                  <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                      <td style="padding:5px 0;font-size:13px;color:#6c757d;width:160px;">Typ umowy</td>
                      <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{$type_label}</td>
                    </tr>
                    <tr>
                      <td style="padding:5px 0;font-size:13px;color:#6c757d;">Numer umowy</td>
                      <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{$numer}</td>
                    </tr>
                    <tr>
                      <td style="padding:5px 0;font-size:13px;color:#6c757d;">Osoba</td>
                      <td style="padding:5px 0;font-size:14px;color:#212529;">{$osoba}</td>
                    </tr>
                    <tr>
                      <td style="padding:5px 0;font-size:13px;color:#6c757d;">Data zakończenia</td>
                      <td style="padding:5px 0;font-size:14px;color:{$accent_color};font-weight:700;">{$data_zakonczenia}</td>
                    </tr>
                    <tr>
                      <td style="padding:5px 0;font-size:13px;color:#6c757d;">Pozostało</td>
                      <td style="padding:5px 0;font-size:14px;color:{$accent_color};font-weight:700;">{$days_left} {$days_label}</td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 8px;font-size:14px;color:#495057;">
              Zaloguj się do systemu, aby sprawdzić szczegóły umowy i podjąć działania
              (przedłużenie, zakończenie lub anulowanie).
            </p>
          </td>
        </tr>

        <!-- Stopka -->
        <tr>
          <td style="background:#f8f9fa;padding:16px 32px;border-top:1px solid #e9ecef;">
            <p style="margin:0;font-size:12px;color:#adb5bd;text-align:center;">
              Wiadomość wygenerowana automatycznie przez system zarządzania umowami NGO.<br>
              Prosimy nie odpowiadać na tę wiadomość.
            </p>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
