<?php
/**
 * cron/backup_monitor.php — Monitor kopii zapasowych.
 * Rejestrowany w cron/dispatcher.php (raz dziennie). Można uruchomić ręcznie.
 *
 * Robi dwie rzeczy i alarmuje administratorów (dzwonek + e-mail):
 *   1) RPO/przeterminowanie — brak udanego backupu od > progu godzin
 *      (ustawienie backup_alert_max_hours, domyślnie 8h),
 *   2) integralność — weryfikacja SHA-256 wszystkich kopii + integrity_check baz;
 *      alert, gdy wykryto uszkodzenie / zmianę / brak pliku.
 */

if (php_sapi_name() !== 'cli') { die("Tylko CLI.\n"); }

$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/backup.php';
require_once $base . '/includes/notifications.php';
require_once $base . '/includes/mail_queue.php';

echo "[" . date('Y-m-d H:i:s') . "] Start: backup_monitor\n";

// ── 1. Przeterminowanie (RPO) ────────────────────────────────────────────────
$last = backup_last_ok_ts();
if ($last === 0) {
    echo "[INFO] Brak znacznika udanego backupu — pomijam kontrolę RPO (jeszcze nie było przebiegu z monitorem).\n";
} elseif (backup_is_stale()) {
    $age_h = round((time() - $last) / 3600, 1);
    $max   = backup_max_age_hours();
    echo "[ALERT] Ostatni udany backup: {$age_h}h temu (próg {$max}h).\n";
    backup_alert(
        'Brak świeżej kopii zapasowej',
        "Ostatni udany backup wykonano {$age_h} godzin temu, próg alertu to {$max}h.\n"
        . "Sprawdź, czy agent kopii (cron) działa poprawnie.",
        'backup_stale', 12
    );
} else {
    $age_h = round((time() - $last) / 3600, 1);
    echo "[OK] Ostatni udany backup: {$age_h}h temu — w normie.\n";
}

// ── 2. Weryfikacja integralności ─────────────────────────────────────────────
$res = backup_verify(true);
echo "[INFO] Zweryfikowano {$res['checked']} kopii, problemów: {$res['problems']}.\n";

$bad = array_filter($res['items'], fn($i) => !in_array($i['status'], ['ok', 'unmanifested'], true));
if ($bad) {
    $lines = [];
    foreach ($bad as $b) $lines[] = "• {$b['rel']} — {$b['status']}: {$b['detail']}";
    foreach ($bad as $b) echo "[ALERT] {$b['rel']} — {$b['status']}\n";
    backup_alert(
        'Wykryto problem z integralnością kopii',
        "Weryfikacja kopii zapasowych wykryła " . count($bad) . " problem(ów):\n\n" . implode("\n", $lines),
        'backup_integrity', 12
    );
} else {
    echo "[OK] Wszystkie kopie przeszły weryfikację integralności.\n";
}

// Przetwórz kolejkę pocztową (alerty czekają w mail_queue).
if (function_exists('mail_queue_process')) {
    try { $r = mail_queue_process(); echo "[INFO] Poczta: wysłano={$r['sent']}, błędy={$r['failed']}\n"; }
    catch (\Throwable $e) { echo "[WARN] Poczta: " . $e->getMessage() . "\n"; }
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec: backup_monitor\n";
