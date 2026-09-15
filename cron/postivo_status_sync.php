<?php
/**
 * Skrypt cron: automatyczna synchronizacja statusów przesyłek Postivo.pl dla pism EZD.
 * Uruchamiaj co kilka godzin, np.:
 *   15 * * * * php /var/www/html/cron/postivo_status_sync.php
 *
 * Bez tego skryptu status w rejestrze aktualizował się wyłącznie ręcznie,
 * przyciskiem „Odśwież status" na karcie pisma (ezd/pisma/postivo_action.php).
 * Każda faktyczna zmiana statusu trafia do dziennika sprawy (ezd_log), więc
 * widać ją w zakładce „Dziennik" bez zaglądania na kartę pisma.
 *
 * Odpytujemy tylko pisma w stanie nieostatecznym — 'sent'/'delivered'/'failed'/
 * 'cancelled' się już nie zmienią, więc nie warto ich co chwilę odpytywać API.
 */

if (php_sapi_name() !== 'cli') {
    die("Dostęp dozwolony tylko z poziomu konsoli (CLI).\n");
}

$base_dir = dirname(__DIR__);
require_once $base_dir . '/config.php';
require_once $base_dir . '/includes/db.php';
require_once $base_dir . '/includes/functions.php';
require_once $base_dir . '/includes/ezd.php';
require_once $base_dir . '/includes/postivo.php';

echo "[" . date('Y-m-d H:i:s') . "] Start: postivo_status_sync\n";

if (!module_enabled('ezd_enabled')) {
    echo "  EZD wyłączony — pomijam.\n"; exit;
}
if (postivo_setting('postivo_enabled') !== '1') {
    echo "  Integracja Postivo.pl wyłączona — pomijam.\n"; exit;
}

$client = new PostivoClient();
if (!$client->is_configured()) {
    echo "  Brak klucza API Postivo.pl — pomijam.\n"; exit;
}

const POSTIVO_NONFINAL_STATUSES = ['draft', 'processing', 'sent', 'unknown'];

$pending = db_all(
    "SELECT id, sprawa_id, postivo_job_id, postivo_status
       FROM ezd_pisma
      WHERE postivo_job_id IS NOT NULL AND postivo_job_id <> ''
        AND (postivo_status IS NULL OR postivo_status = '' OR postivo_status IN ("
    . implode(',', array_fill(0, count(POSTIVO_NONFINAL_STATUSES), '?')) . "))",
    POSTIVO_NONFINAL_STATUSES
);

$checked = 0; $changed = 0; $errs = 0;

foreach ($pending as $p) {
    $checked++;
    $old_status = $p['postivo_status'] ?? '';
    try {
        $status_data = $client->get_status($p['postivo_job_id']);
        $new_status  = $status_data['status'];

        if ($new_status === $old_status) continue;

        db()->prepare(
            "UPDATE ezd_pisma SET postivo_status=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$new_status, $p['id']]);

        ezd_log(null, (int)$p['sprawa_id'], (int)$p['id'], null, 0, 'postivo_status',
            'Status Postivo.pl (auto): ' . ($old_status ?: '—') . ' → ' . $new_status
            . ($status_data['tracking'] ? ' (nr śledzenia: ' . $status_data['tracking'] . ')' : ''));

        echo "  ✓ pismo #{$p['id']}: {$old_status} → {$new_status}\n";
        $changed++;
    } catch (\Throwable $e) {
        echo "  ✗ pismo #{$p['id']}: " . $e->getMessage() . "\n";
        $errs++;
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Koniec. Sprawdzono: $checked, zmienione: $changed, błędy: $errs\n";
