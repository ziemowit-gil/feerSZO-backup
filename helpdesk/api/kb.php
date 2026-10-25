<?php
/**
 * helpdesk/api/kb.php — podpowiedzi artykułów z bazy wiedzy (Portal Usług IT).
 * GET ?q=… → {ok, items:[{id,title,category,url,excerpt}]}. Operatorzy widzą wszystkie opublikowane artykuły,
 * pozostali zalogowani — tylko usługi bez ograniczenia do grup odbiorców.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 2) . '/modules/helpdeskExtras/lib/helpdeskExtras.php';
header('Content-Type: application/json; charset=UTF-8');
auth_start();
if (empty($_SESSION['user'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Brak sesji']); exit; }
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 300);
echo json_encode(['ok' => true, 'items' => $q === '' ? [] : hdKbSearch($q, 5, !hd_is_operator())], JSON_UNESCAPED_UNICODE);
