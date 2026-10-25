<?php
/** Wspólny początek widoków: tytuł strony, nagłówek SZO, arkusze Dynamics + noclegi.css. Zmienne: $PAGE_TITLE. */
include dirname(__DIR__, 3) . '/includes/header.php';
require_once dirname(__DIR__, 3) . '/includes/dynRecord.php';
$_css = __DIR__ . '/noclegi.css'; $_dyn = dirname(__DIR__, 3) . '/assets/css/dynamics.css';
?>
<link rel="stylesheet" href="<?= h(APP_URL) ?>/assets/css/dynamics.css?v=<?= is_file($_dyn) ? (int)filemtime($_dyn) : 1 ?>">
<link rel="stylesheet" href="<?= h(APP_URL) ?>/modules/noclegi/view/noclegi.css?v=<?= is_file($_css) ? (int)filemtime($_css) : 1 ?>">
<main class="ncl" id="main-content">
