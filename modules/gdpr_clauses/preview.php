<?php
/**
 * modules/gdpr_clauses/preview.php — podgląd na żywo dla edytora (XHR POST).
 * Renderuje TYM SAMYM kodem co strona publiczna (GdprClauseService::render),
 * więc podgląd = dokładnie to, co zobaczy odbiorca. Nieznane tagi podświetlone.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/gdpr_clauses.php';

require_role('admin', 'editor');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_check();

$svc     = new GdprClauseService();
$content = (string)($_POST['content'] ?? '');
$local   = json_decode((string)($_POST['local_vars'] ?? '{}'), true);
$local   = is_array($local) ? array_map('strval', array_filter($local, fn($k) => preg_match(GDPR_VAR_KEY_RE, (string)$k), ARRAY_FILTER_USE_KEY)) : [];

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'html'    => $svc->render($content, $_POST['updated_at'] ?? null, true, $local),
    'unknown' => $svc->unknownTags($content, $local),
], JSON_UNESCAPED_UNICODE);
exit;
