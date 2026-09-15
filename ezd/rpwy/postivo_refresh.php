<?php
/**
 * ezd/rpwy/postivo_refresh.php — odśwież "na żądanie" (bez czekania na
 * cron/postivo_status_sync.php) szczegóły i historię statusu z Postivo.pl dla
 * wpisu RPW-W. Wołane z modala "Śledź historię" (ezd/rpwy/view.php).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST wymagane']); exit; }
csrf_check();

$id = (int)($_POST['id'] ?? 0);
$r  = $id ? ezd_rpwy_get($id) : null;
if (!$r || empty($r['postivo_job_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Wpis nie istnieje albo nie ma zlecenia Postivo.']);
    exit;
}

$sprawa = $r['sprawa_id'] ? ezd_sprawa_get((int)$r['sprawa_id']) : null;
$access = $sprawa ? ezd_sprawa_access($sprawa, (int)current_user()['id']) : null;
if (!$access) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Brak dostępu.']); exit; }

try {
    $client      = new PostivoClient();
    $status_data = $client->get_status($r['postivo_job_id']);
    ezd_rpwy_apply_postivo_status($id, $status_data, (int)current_user()['id']);
    $updated = ezd_rpwy_get($id);
    echo json_encode([
        'ok'            => true,
        'operator'      => $updated['postivo_operator'],
        'service_name'  => $updated['postivo_service_name'],
        'status_name'   => $updated['postivo_status_name'],
        'dispatch_date' => $updated['postivo_dispatch_date'] ? date_pl($updated['postivo_dispatch_date']) : '—',
        'pages'         => (int)$updated['postivo_pages'],
        'tracking'      => $updated['nr_nadania'],
        'status'        => $updated['status'],
        'status_label'  => EZD_RPWY_STATUSY[$updated['status']]['label'] ?? $updated['status'],
        'events'        => array_reverse(json_decode((string)$updated['postivo_events_json'], true) ?: []),
    ]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
exit;
