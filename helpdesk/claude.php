<?php
/** helpdesk/claude.php — dawny adres kreatora; kreator jest wbudowany w „Nowe zgłoszenie IT” (helpdesk/new.php?type=claude). */
require_once dirname(__DIR__) . '/config.php';
header('Location: ' . APP_URL . '/helpdesk/new.php?type=claude', true, 302);
exit;
