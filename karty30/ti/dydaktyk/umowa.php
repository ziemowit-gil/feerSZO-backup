<?php
/**
 * karty30/ti/dydaktyk/umowa.php — dawna strona „Umowa”. Scalona z zakładką Formalności
 * (sekcja „Umowa zlecenie i e-ewidencja godzin”, _umowa_ewidencja.php) — tu tylko przekierowanie.
 */
$q = ['tab' => 'formalnosci'];
if (!empty($_GET['u'])) $q['um_u'] = (int)$_GET['u'];
if (!empty($_GET['m']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['m'])) $q['um_m'] = (string)$_GET['m'];
header('Location: index.php?' . http_build_query($q) . '#umowa', true, 302);
exit;
