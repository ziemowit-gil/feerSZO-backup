<?php
/**
 * cron/agents/szkolenia_notifier.php — Raport brakujących szkoleń wolontariuszy.
 *
 * Raz w miesiącu (po 25. dniu) wysyła opiekunom zestawienie wolontariuszy
 * z aktywnymi umowami, którzy nie ukończyli wymaganych szkoleń.
 *
 * Konfiguracja (admin/tidycal_settings.php → „Wymagane szkolenia"):
 *   settings.tidycal_required_types  — JSON array booking_type_id (TidyCal)
 *   settings.tidycal_bhp_required    — '1'/'0' (domyślnie '1')
 *
 * Użycie:
 *   php cron/agents/szkolenia_notifier.php          # respektuje limit miesięczny
 *   php cron/agents/szkolenia_notifier.php --force  # wymuś wysyłkę
 */

if (php_sapi_name() !== 'cli' && !defined('APP_CLI')) {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__, 2);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/mail_queue.php';
require_once $base_dir . '/includes/email_templates.php';

$force = in_array('--force', $argv ?? [], true);
$today = date('Y-m-d');
$month = date('Y-m');

echo "[" . date('Y-m-d H:i:s') . "] Start: szkolenia_notifier\n";

// ── Miesięczny limit ──────────────────────────────────────────────────────────
$month_key    = "szkolenia_notifier_sent_{$month}";
$already_sent = db_one("SELECT value FROM settings WHERE key_=?", [$month_key]);

if ($already_sent && !$force) {
    echo "Raport za {$month} już wysłany ({$already_sent}). Użyj --force aby wymusić.\n";
    exit(0);
}

if ((int)date('d') < 25 && !$force) {
    echo "Zbyt wcześnie w miesiącu (dziś " . date('d') . ". dzień). Raport wysyłamy po 25.\n";
    exit(0);
}

// ── Konfiguracja wymaganych szkoleń ──────────────────────────────────────────
$required_types_json = org_setting('tidycal_required_types');
$required_type_ids   = $required_types_json ? (array) json_decode($required_types_json, true) : [];
$required_type_ids   = array_map('intval', array_filter($required_type_ids));

$bhp_required = org_setting('tidycal_bhp_required') !== '0'; // domyślnie włączone

if (empty($required_type_ids) && !$bhp_required) {
    echo "Brak skonfigurowanych wymaganych szkoleń. Pomijam.\n";
    exit(0);
}

// Tytuły typów szkoleń z cache TidyCal
$types_cache = [];
if ($required_type_ids) {
    $raw = org_setting('tidycal_types_cache');
    if ($raw) {
        foreach ((array) json_decode($raw, true) as $t) {
            $types_cache[(int) $t['id']] = $t['title'] ?? "Szkolenie #{$t['id']}";
        }
    }
}

// ── Aktywni wolontariusze ─────────────────────────────────────────────────────
$volunteers = db_all(
    "SELECT id, numer_umowy, imie_nazwisko, email,
            szkolenie_bhp, data_szkolenia_bhp, guardian_editor_id
     FROM umowy_wolontariat
     WHERE status NOT IN ('zakończona', 'anulowana', 'rozwiązana')
       AND imie_nazwisko != ''",
    []
);

echo "Wolontariuszy aktywnych: " . count($volunteers) . "\n";

// ── Sprawdzenie brakujących szkoleń ──────────────────────────────────────────
$missing_by_guardian = []; // [guardian_id => [['name', 'numer', 'missing' => []]]]

foreach ($volunteers as $v) {
    $missing = [];

    // 1. Szkolenie BHP (pole na umowie)
    if ($bhp_required && empty($v['szkolenie_bhp'])) {
        $missing[] = 'Szkolenie BHP';
    }

    // 2. Wymagane typy TidyCal
    if ($required_type_ids && !empty($v['email'])) {
        $placeholders = implode(',', array_fill(0, count($required_type_ids), '?'));
        $booked_rows  = db_all(
            "SELECT DISTINCT tb.booking_type_id
             FROM tidycal_bookings tb
             LEFT JOIN users u ON u.id = tb.user_id
             WHERE tb.status != 'cancelled'
               AND tb.booking_type_id IN ({$placeholders})
               AND (tb.email = ? OR (u.email IS NOT NULL AND u.email = ?))",
            array_merge($required_type_ids, [$v['email'], $v['email']])
        );
        $booked_ids = array_column($booked_rows, 'booking_type_id');

        foreach ($required_type_ids as $type_id) {
            if (!in_array($type_id, $booked_ids, false)) {
                $missing[] = $types_cache[$type_id] ?? "Szkolenie #{$type_id}";
            }
        }
    }

    if (empty($missing)) continue;

    $guardian_id = (int) ($v['guardian_editor_id'] ?? 0);
    $missing_by_guardian[$guardian_id][] = [
        'name'    => $v['imie_nazwisko'],
        'numer'   => $v['numer_umowy'] ?? "#{$v['id']}",
        'missing' => $missing,
    ];
}

echo "Opiekunów z brakującymi szkoleniami: " . count($missing_by_guardian) . "\n";

if (empty($missing_by_guardian)) {
    echo "Wszyscy wolontariusze mają wymagane szkolenia. Oznaczam miesiąc.\n";
    db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
        ->execute([$month_key, $today]);
    exit(0);
}

// ── E-mail admina (fallback dla wolontariuszy bez opiekuna) ───────────────────
$admin_user  = db_one("SELECT email, imie_nazwisko FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1");
$admin_email = $admin_user ? ($admin_user['email'] ?? '') : '';

$sent = 0;
$errs = 0;
$month_pl = _szn_month_pl($month);

// ── Wysyłka do każdego opiekuna ───────────────────────────────────────────────
foreach ($missing_by_guardian as $guardian_id => $entries) {
    if ($guardian_id > 0) {
        $guser    = db_one("SELECT email, imie_nazwisko FROM users WHERE id=? AND is_active=1", [$guardian_id]);
        $to_email = $guser ? ($guser['email'] ?? '') : '';
        $to_name  = $guser ? ($guser['imie_nazwisko'] ?? '') : '';
    } else {
        $to_email = $admin_email;
        $to_name  = $admin_user['imie_nazwisko'] ?? 'Administrator';
    }

    if (!$to_email) {
        echo "  [SKIP] guardian_id={$guardian_id} — brak adresu e-mail\n";
        continue;
    }

    $rows_html = '';
    foreach ($entries as $e) {
        $missing_list = implode(', ', array_map('htmlspecialchars', $e['missing']));
        $rows_html .= '<tr>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;font-size:14px">'
            . htmlspecialchars($e['name'])
            . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;font-size:13px;color:#64748b">'
            . htmlspecialchars($e['numer'])
            . '</td>'
            . '<td style="padding:8px 12px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#dc3545">'
            . $missing_list
            . '</td>'
            . '</tr>';
    }

    $rendered = email_tpl_render('szkolenia_brakujace', [
        'greeting'  => $to_name ? ', <strong>' . htmlspecialchars($to_name) . '</strong>' : '',
        'month'     => $month_pl,
        'count'     => count($entries),
        'rows_html' => $rows_html,
        'app_url'   => defined('APP_URL') ? APP_URL . '/szkolenia/' : '',
    ]);

    if (!$rendered['enabled']) {
        continue;
    }

    try {
        mail_queue_add($to_email, $to_name, $rendered['subject'], $rendered['html']);
        echo "[" . date('Y-m-d H:i:s') . "] Wysłano do {$to_email} (" . count($entries) . " wolontariuszy)\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] BŁĄD {$to_email}: " . $e->getMessage() . "\n";
        $errs++;
    }
}

// ── Zapisz znacznik miesięczny ────────────────────────────────────────────────
if ($sent > 0 || empty($missing_by_guardian)) {
    db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")
        ->execute([$month_key, $today]);
}

// ── Kolejka pocztowa ──────────────────────────────────────────────────────────
try {
    $result = mail_queue_process();
    echo "[" . date('Y-m-d H:i:s') . "] Kolejka: wysłano={$result['sent']}, błędy={$result['failed']}\n";
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] BŁĄD kolejki: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Wysłano={$sent}, błędy={$errs}\n";

// ── Pomocnicze ────────────────────────────────────────────────────────────────

function _szn_month_pl(string $ym): string
{
    [$y, $m] = explode('-', $ym);
    $months  = [
        'stycznia','lutego','marca','kwietnia','maja','czerwca',
        'lipca','sierpnia','września','października','listopada','grudnia',
    ];
    return ($months[(int) $m - 1] ?? $m) . ' ' . $y;
}
