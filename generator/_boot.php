<?php
/** generator/_boot.php — wspólny start stron generatora dokumentów (logowanie, uprawnienia, logika). */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/doc_generator/logic/docGenStore.php';
require_once dirname(__DIR__) . '/modules/doc_registry/lib/DocRegistry.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
DocGenStore::migrate();
