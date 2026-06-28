<?php
/**
 * org_documents/serve.php — Podgląd / pobranie pliku dokumentu organizacji.
 * Dostęp: każdy zalogowany użytkownik (gdy moduł włączony).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org_docs.php';

require_login();
require_module_enabled('org_documents_enabled', 'Moduł dokumentów organizacji');

$id  = (int)($_GET['id'] ?? 0);
$doc = org_docs_get($id);
if (!$doc) { http_response_code(404); exit('Nie znaleziono dokumentu.'); }

// Personel widzi wszystko; pozostali — zależnie od widoczności/jednostki
$u     = current_user();
$staff = is_admin() || in_array($u['role'] ?? '', ['admin', 'editor'], true);
if (!$staff && !org_docs_can_view($doc, (int)($u['id'] ?? 0))) {
    http_response_code(403);
    exit('Brak dostępu do tego dokumentu.');
}

org_docs_send_file($id, isset($_GET['download']));
