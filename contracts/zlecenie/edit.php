<?php
/**
 * contracts/zlecenie/edit.php — dawny osobny formularz edycji umowy zlecenie.
 * Edycja odbywa się teraz wyłącznie inline w podglądzie (zakładka „Umowa”,
 * modules/zlecenie_inline) — ze statusem, plikami, kartoteką osób, IS, M365 i dostępem.
 * Ten plik tylko przekierowuje, żeby stare linki i zakładki działały.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . APP_URL . '/contracts/zlecenie/' . ($id ? 'view.php?id=' . $id . '&tab=umowa&edit=1' : 'list.php'), true, 302);
exit;
