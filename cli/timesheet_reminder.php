#!/usr/bin/env php
<?php
/**
 * cli/timesheet_reminder.php — przypomnienie o ewidencji godzin wolontariatu.
 *
 * Wysyła e-mail do wolontariuszy, którzy nie złożyli jeszcze timesheeta
 * za bieżący miesiąc (lub miesiąc wskazany przez --month=YYYY-MM).
 *
 * Uruchamiaj ostatniego dnia miesiąca, np. przez cron:
 *   0 8 28-31 * * [ "$(date +\%d)" = "$(cal | awk 'NF{LAST=$NF}END{print LAST}')" ] \
 *       && php /ścieżka/do/cli/timesheet_reminder.php
 *
 * Lub codziennie (skrypt sam sprawdza czy to ostatni dzień):
 *   0 8 * * * php /ścieżka/do/cli/timesheet_reminder.php
 *
 * Opcje:
 *   --force            Wyślij nawet jeśli nie jest ostatni dzień miesiąca
 *   --month=YYYY-MM    Wyślij przypomnienie za konkretny miesiąc
 *   --dry-run          Tylko pokaż kto dostałby maila, bez wysyłki
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403); exit('Tylko CLI.');
}

if (!defined('APP_INSTALLED')) define('APP_INSTALLED', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/timesheets.php';

// ── Argumenty ─────────────────────────────────────────────────────────────────
$args     = array_slice($argv ?? [], 1);
$force    = in_array('--force', $args);
$dry_run  = in_array('--dry-run', $args);

// --month=YYYY-MM
$target_month = null;
foreach ($args as $arg) {
    if (preg_match('/^--month=(\d{4}-\d{2})$/', $arg, $m)) {
        $target_month = $m[1];
        break;
    }
}

// ── Wyznacz rok i miesiąc ─────────────────────────────────────────────────────
if ($target_month) {
    [$rok, $miesiac] = array_map('intval', explode('-', $target_month));
    $last_day = true; // przy ręcznym miesiącu zawsze wysyłamy
} else {
    $today    = new \DateTimeImmutable('today');
    $tomorrow = $today->modify('+1 day');
    $last_day = $today->format('Y-m') !== $tomorrow->format('Y-m');
    $rok      = (int)$today->format('Y');
    $miesiac  = (int)$today->format('n');
}

if (!$last_day && !$force) {
    echo "  Nie jest ostatni dzień miesiąca. Nic do zrobienia.\n";
    echo "  Użyj --force aby wymusić wysyłkę, lub --month=YYYY-MM dla konkretnego miesiąca.\n";
    exit(0);
}

$month_label = MIESIAC_PL[$miesiac] . ' ' . $rok;

echo "\n";
echo "  Ewidencja godzin — przypomnienia za {$month_label}\n";
if ($dry_run) echo "  ⚠  Tryb dry-run — nic nie zostanie wysłane\n";
echo "  " . str_repeat('─', 55) . "\n";

// ── Aktywne umowy wolontariatu z adresem e-mail ───────────────────────────────
$active_statuses = ['projekt', 'podpisana', 'w realizacji', 'obowiązująca'];
$placeholders    = implode(',', array_fill(0, count($active_statuses), '?'));

$contracts = db_all(
    "SELECT id, imie_nazwisko, email
     FROM umowy_wolontariat
     WHERE status IN ({$placeholders})
       AND email IS NOT NULL
       AND trim(email) != ''
     ORDER BY imie_nazwisko",
    $active_statuses
);

if (!$contracts) {
    echo "  Brak aktywnych umów wolontariatu z adresem e-mail.\n\n";
    exit(0);
}

$sent    = 0;
$skipped = 0;

foreach ($contracts as $c) {
    $contract_id = (int)$c['id'];
    $email       = trim($c['email'] ?? '');
    $name        = trim($c['imie_nazwisko'] ?? 'Wolontariusz');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "  ✗  [#{$contract_id}] {$name} — nieprawidłowy e-mail: " . ($email ?: '(pusty)') . "\n";
        $skipped++;
        continue;
    }

    // Sprawdź czy already złożono/zatwierdzono (szkice nie liczą się)
    $existing = db_one(
        "SELECT id, status FROM timesheets
         WHERE contract_id = ? AND rok = ? AND miesiac = ?
           AND status IN ('złożone', 'zatwierdzone')
         LIMIT 1",
        [$contract_id, $rok, $miesiac]
    );

    if ($existing) {
        $st = $existing['status'];
        echo "  ⏭  {$name} <{$email}> — już złożono (status: {$st}), pomijam\n";
        $skipped++;
        continue;
    }

    // Zbuduj i zakolejkuj e-mail
    $panel_url = rtrim(APP_URL, '/') . '/panel/timesheets.php';

    $subject = 'Prośba o wpisanie godzin za ' . $month_label . ' — ' . ORG_NAME;

    $body_html = '<!DOCTYPE html><html lang="pl"><body style="font-family:Arial,sans-serif;color:#1e293b;max-width:560px;margin:0 auto;padding:24px">'
        . '<h2 style="margin-bottom:8px;color:#0d6efd">Ewidencja godzin wolontariatu</h2>'
        . '<p style="margin:0 0 16px">Drogi/a <strong>' . htmlspecialchars($name) . '</strong>,</p>'
        . '<p style="margin:0 0 16px">Zbliża się koniec miesiąca <strong>' . htmlspecialchars($month_label) . '</strong>. '
        . 'Prosimy o uzupełnienie ewidencji godzin przepracowanych w ramach umowy wolontariackiej '
        . 'z <strong>' . htmlspecialchars(ORG_NAME) . '</strong>.</p>'
        . '<p style="margin:0 0 24px">Zaloguj się do panelu i wpisz liczbę godzin za ten miesiąc — '
        . 'zajmie to tylko chwilę.</p>'
        . '<p style="margin:0 0 24px"><a href="' . htmlspecialchars($panel_url) . '" style="'
        . 'display:inline-block;background:#0d6efd;color:#fff;padding:12px 24px;'
        . 'border-radius:6px;text-decoration:none;font-weight:600;font-size:15px">'
        . '📋&nbsp; Wpisz godziny za ' . htmlspecialchars($month_label) . '</a></p>'
        . '<p style="color:#64748b;font-size:13px;margin:0 0 8px">'
        . 'Jeśli przycisk nie działa, skopiuj poniższy link do przeglądarki:</p>'
        . '<p style="color:#64748b;font-size:13px;word-break:break-all;margin:0 0 24px">'
        . '<a href="' . htmlspecialchars($panel_url) . '" style="color:#3b82f6">' . htmlspecialchars($panel_url) . '</a></p>'
        . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0">'
        . '<p style="color:#94a3b8;font-size:12px;margin:0">'
        . 'Wiadomość wysłana automatycznie przez system ' . htmlspecialchars(ORG_NAME) . '. Nie odpowiadaj na ten e-mail.</p>'
        . '</body></html>';

    if (!$dry_run) {
        mail_queue_add(
            $email, $name, $subject, $body_html,
            '', 'timesheet_reminder', $contract_id
        );
    }

    echo "  ✓  {$name} <{$email}>" . ($dry_run ? ' [dry-run, nie wysłano]' : '') . "\n";
    $sent++;
}

echo "  " . str_repeat('─', 55) . "\n";
printf("  Łącznie: %d przypomnień %s, %d pominięto\n\n",
    $sent,
    $dry_run ? '(dry-run)' : 'dodanych do kolejki',
    $skipped
);

if ($dry_run) {
    echo "  Aby faktycznie wysłać, uruchom bez --dry-run.\n\n";
}
