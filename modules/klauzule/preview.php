<?php
/**
 * modules/klauzule/preview.php — podgląd na żywo dla edytora (XHR POST).
 * Renderuje TYM SAMYM kodem co strona publiczna (GdprClauseService::render),
 * więc podgląd = dokładnie to, co zobaczy odbiorca. Nieznane tagi podświetlone.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/klauzule.php';

require_role('admin', 'editor');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_check();

$svc     = new GdprClauseService();
$content = (string)($_POST['content'] ?? '');

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'html'    => $svc->render($content, $_POST['updated_at'] ?? null, true),
    'unknown' => $svc->unknownTags($content),
], JSON_UNESCAPED_UNICODE);
exit;
