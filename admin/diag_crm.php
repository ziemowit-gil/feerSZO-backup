<?php
/**
 * admin/diag_crm.php — Diagnostyka CRM / Oferty / sesji.
 *
 * Strona dla admina, gdy coś zwraca 500 albo „kod IKA nie działa" — pokazuje
 * realną przyczynę bez dostępu do logów serwera: zapisywalność bazy, stan sesji
 * (sesje trzymane są w bazie — brak zapisu = utrata weryfikacji IKA), obecność
 * tabel modułów oraz wynik migracji modułu Oferty wraz z treścią wyjątku.
 *
 * Strona samodzielna (bez powłoki) — działa nawet gdy layout jest zepsuty.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');

$checks = [];
$add = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

// ── Środowisko ───────────────────────────────────────────────────────────────
$add('PHP', PHP_VERSION_ID >= 80000, PHP_VERSION . ' (' . php_sapi_name() . ')');
$add('Typ bazy', true, defined('DB_TYPE') ? DB_TYPE : '(nieokreślony)');

$db_path = defined('DB_PATH') ? DB_PATH : '';
if ($db_path) {
    $w = is_writable($db_path);
    $add('Plik bazy zapisywalny', $w, $db_path . ($w ? '' : ' — BRAK PRAWA ZAPISU'));
    $dir = dirname($db_path);
    $add('Katalog bazy zapisywalny (WAL/journal)', is_writable($dir), $dir);
    $free = @disk_free_space($dir);
    $add('Wolne miejsce na dysku', $free === false || $free > 50 * 1024 * 1024,
        $free === false ? 'nie udało się ustalić' : round($free / 1048576) . ' MB');
}

// ── Zapis do bazy (realny test) ──────────────────────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS _diag_write_test (id INTEGER PRIMARY KEY, ts TEXT)");
    db()->prepare("INSERT INTO _diag_write_test (ts) VALUES (?)")->execute([date('c')]);
    db()->exec("DROP TABLE _diag_write_test");
    $add('Zapis do bazy (CREATE/INSERT/DROP)', true, 'OK');
} catch (\Throwable $e) {
    $add('Zapis do bazy (CREATE/INSERT/DROP)', false, $e->getMessage());
}

// ── Sesje (IKA zależy od zapisu sesji) ───────────────────────────────────────
$add('Handler sesji', true, ini_get('session.save_handler') . ' / status=' . session_status());
$_SESSION['_diag_ping'] = $ping = bin2hex(random_bytes(4));
$sess_ok = ($_SESSION['_diag_ping'] ?? '') === $ping;
$add('Zapis do sesji w tym żądaniu', $sess_ok, $sess_ok ? 'OK' : 'sesja nie przyjmuje danych');
$add('Token IKA w sesji', !empty($_SESSION['_ika_ts']),
    !empty($_SESSION['_ika_ts'])
        ? ('ustawiony ' . date('H:i:s', (int)$_SESSION['_ika_ts']) . ' (ważny 30 min)')
        : 'brak — po wpisaniu kodu IKA powinien się pojawić; jeśli nie, sesja nie utrwala danych');
// Sesje trzymane są w tabeli php_sessions (DbSessionHandler)
try {
    $sess_tbl = db_one("SELECT COUNT(*) AS n FROM php_sessions");
    $live = db_one("SELECT COUNT(*) AS n FROM php_sessions WHERE expires > ?", [time()]);
    $add('Tabela php_sessions', true,
        (int)($sess_tbl['n'] ?? 0) . ' rekordów, aktywnych: ' . (int)($live['n'] ?? 0));
} catch (\Throwable $e) {
    $add('Tabela php_sessions', false, $e->getMessage() . ' — sesje działają na plikach (fallback)');
}

// ── Tabele CRM ───────────────────────────────────────────────────────────────
$tables = [
    'crm_contacts', 'crm_cases', 'crm_activities', 'crm_communications',
    'crm_contact_persons', 'crm_service_types', 'crm_contact_services',
    'crm_offers', 'crm_offer_items', 'crm_offer_variants', 'crm_offer_catalog',
    'crm_offer_confirmations', 'crm_offer_events', 'crm_offer_templates',
];
require_once dirname(__DIR__) . '/includes/crm.php';
try { crm_migrate(); $add('crm_migrate()', true, 'OK'); }
catch (\Throwable $e) { $add('crm_migrate()', false, $e->getMessage()); }

require_once dirname(__DIR__) . '/includes/crm_offers.php';
$off_ok = crm_offers_migrate();
$add('crm_offers_migrate()', $off_ok, $off_ok ? 'OK' : (crm_offers_last_error() ?: 'nieznany błąd'));

$add('Baza CRM', true, json_encode(crm_db_status(), JSON_UNESCAPED_UNICODE));

foreach ($tables as $t) {
    try {
        $r = crm_one("SELECT COUNT(*) AS n FROM $t");
        $add('Tabela ' . $t, true, (int)($r['n'] ?? 0) . ' rekordów (baza CRM)');
    } catch (\Throwable $e) {
        try {
            $r = db_one("SELECT COUNT(*) AS n FROM $t");
            $add('Tabela ' . $t, true, (int)($r['n'] ?? 0) . ' rekordów (baza główna)');
        } catch (\Throwable $e2) {
            $add('Tabela ' . $t, false, $e2->getMessage());
        }
    }
}

// ── Log PHP ──────────────────────────────────────────────────────────────────
$log = ini_get('error_log');
$log_tail = '';
if ($log && @is_readable($log)) {
    $lines = @file($log);
    if ($lines) $log_tail = implode('', array_slice($lines, -40));
} elseif ($log) {
    $log_tail = "Log ustawiony na: $log — brak prawa odczytu z PHP.";
} else {
    $log_tail = 'Brak skonfigurowanego error_log (błędy idą do logu serwera WWW).';
}

$fail = count(array_filter($checks, static fn($c) => !$c['ok']));
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnostyka CRM</title>
<style>
body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; margin:0; background:#F3F4F6; color:#111827 }
.wrap { max-width:960px; margin:0 auto; padding:20px 16px 40px }
h1 { font-size:1.2rem } h2 { font-size:1rem; margin-top:1.6rem }
table { width:100%; border-collapse:collapse; background:#fff; border-radius:8px; overflow:hidden; font-size:.86rem }
th, td { text-align:left; padding:.45rem .6rem; border-bottom:1px solid #F1F2F4; vertical-align:top }
th { background:#F9FAFB; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#6B7280 }
.ok { color:#15803D; font-weight:700 } .bad { color:#B91C1C; font-weight:700 }
pre { background:#111827; color:#E5E7EB; padding:12px; border-radius:8px; overflow:auto; font-size:.76rem; max-height:420px }
.sum { padding:.7rem .9rem; border-radius:8px; margin:12px 0; font-size:.9rem }
.sum.good { background:#EFF7ED; border:1px solid #86C79A } .sum.bad { background:#FEF2F2; border:1px solid #F5A3A3 }
code { word-break:break-all }
</style>
</head>
<body><div class="wrap">
<h1>Diagnostyka CRM / Oferty / sesje</h1>
<div class="sum <?= $fail ? 'bad' : 'good' ?>">
  <?= $fail ? ("Nieprawidłowości: <strong>$fail</strong> — pozycje na czerwono poniżej.") : 'Wszystkie testy przeszły.' ?>
</div>

<table>
<thead><tr><th style="width:34%">Sprawdzenie</th><th style="width:9%">Wynik</th><th>Szczegóły</th></tr></thead>
<tbody>
<?php foreach ($checks as $c): ?>
<tr>
  <td><?= h($c['name']) ?></td>
  <td class="<?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? 'OK' : 'BŁĄD' ?></td>
  <td><code><?= h($c['detail']) ?></code></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<h2>Ostatnie wpisy z logu PHP</h2>
<pre><?= h($log_tail) ?></pre>

<p style="font-size:.8rem;color:#6B7280">
  Strona tylko do odczytu (poza testem zapisu, który po sobie sprząta).
  Adres: <code><?= h(APP_URL) ?>/admin/diag_crm.php</code>
</p>
</div></body>
</html>
