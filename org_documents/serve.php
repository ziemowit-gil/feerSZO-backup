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

org_docs_send_file((int)($_GET['id'] ?? 0), isset($_GET['download']));
