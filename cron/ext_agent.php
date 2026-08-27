#!/usr/bin/env php
<?php
/**
 * cron/ext_agent.php — agent modułu Materiały zewnętrzne.
 *
 * Trzy zadania, wszystkie takie, których nie wolno robić w żądaniu HTTP:
 *   1. stemplowanie dużych plików z kolejki (mPDF na 400 stronach trwa),
 *   2. sprzątanie: wygasłe bilety i osierocone kopie ze znakiem wodnym,
 *   3. raz na dobę — ostrzeżenie o licencjach kończących się w ciągu 30 dni.
 *
 * Uruchamiany przez cron/dispatcher.php (wpis 'ext_agent', co 2 minuty).
 */

define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ext_access.php';
require_once dirname(__DIR__) . '/includes/ext_deliver.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';

ext_migrate();

$log = function (string $m): void { echo '[' . date('Y-m-d H:i:s') . "] ext_agent: $m\n"; };

// ── 1. Kolejka stemplowania ─────────────────────────────────────────────────
// Bierzemy po kilka na przebieg: agent ma skończyć przed kolejnym uruchomieniem,
// a stemplowanie grubej książki potrafi zająć kilkanaście sekund.
$jobs = db_all("SELECT * FROM k30_ext_wm_queue WHERE status='pending' ORDER BY id LIMIT 3");
foreach ($jobs as $j) {
    db_exec("UPDATE k30_ext_wm_queue SET status='running' WHERE id=?", [(int)$j['id']]);

    $resource = ext_resource_get((int)$j['resource_id']);
    $ticket   = ext_ticket_get((string)$j['ticket_id']);
    if (!$resource || !$ticket) {
        db_exec("UPDATE k30_ext_wm_queue SET status='error', error='brak zasobu albo biletu', done_at=datetime('now') WHERE id=?",
                [(int)$j['id']]);
        continue;
    }

    $src = ext_storage_path((string)$resource['checksum']);
    @mkdir(dirname((string)$j['dest']), 0770, true);
    $ok  = ext_watermark_pdf($src, (string)$j['dest'], ext_watermark_text($ticket), (string)$ticket['watermark']);

    db_exec("UPDATE k30_ext_wm_queue SET status=?, error=?, done_at=datetime('now') WHERE id=?",
        [$ok ? 'done' : 'error', $ok ? '' : 'stemplowanie nie powiodło się', (int)$j['id']]);
    $log(($ok ? 'ostemplowano' : 'BŁĄD stempla') . ' zasób #' . (int)$resource['id']);
}

// ── 2. Sprzątanie ───────────────────────────────────────────────────────────
$n = ext_tickets_prune();
if ($n) $log("skasowano wygasłe bilety: $n");

// Kopie ze znakiem wodnym starsze niż 7 dni — powstaną na nowo, gdy będą potrzebne.
$wmDir = rtrim(UPLOAD_DIR, '/') . '/ext/wm';
$freed = 0;
if (is_dir($wmDir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wmDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getMTime() < time() - 7 * 86400) {
            $freed += $file->getSize();
            @unlink($file->getPathname());
        }
    }
}
if ($freed) $log('zwolniono ' . round($freed / 1048576, 1) . ' MB starych kopii ze znakiem wodnym');

// ── 3. Licencje kończące się w ciągu 30 dni (raz na dobę) ───────────────────
if (org_setting('ext_lic_warn_date') !== date('Y-m-d')) {
    $soon = db_all("SELECT l.*, p.name AS publisher_name
                    FROM k30_ext_licenses l JOIN k30_ext_publishers p ON p.id=l.publisher_id
                    WHERE l.is_active=1 AND l.valid_to BETWEEN date('now') AND date('now','+30 days')");
    foreach ($soon as $l) {
        $log("licencja kończy się {$l['valid_to']}: {$l['publisher_name']} — {$l['name']}");

        // Wiadomość idzie na adres modułu (ustawienie ext_notify_email). Bez adresu
        // zostaje sam wpis w logu crona — nie zgadujemy, kto ma to dostać.
        $to = trim(org_setting('ext_notify_email'));
        if ($to !== '' && function_exists('mail_queue_add')) {
            $html = '<p>Licencja <strong>' . htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8')
                  . '</strong> (' . htmlspecialchars($l['publisher_name'], ENT_QUOTES, 'UTF-8')
                  . ') wygasa <strong>' . htmlspecialchars($l['valid_to'], ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                  . '<p>Po tej dacie materiały tego wydawcy przestaną być dostępne dla osób bez osobnego '
                  . 'uprawnienia. Jeśli umowa jest przedłużona, zaktualizuj daty w module.</p>';
            try {
                mail_queue_add($to, '', 'Licencja na materiały wygasa ' . $l['valid_to'], $html,
                               '', 'ext_license', (int)$l['id']);
            } catch (\Throwable $e) { error_log('[ext_agent] ' . $e->getMessage()); }
        }
    }
    org_setting_set('ext_lic_warn_date', date('Y-m-d'));
}
