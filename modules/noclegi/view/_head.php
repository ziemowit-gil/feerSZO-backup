<?php
/** Wspólny początek widoków: tytuł strony, nagłówek SZO, arkusz METRO. Zmienne: $PAGE_TITLE. */
include dirname(__DIR__, 3) . '/includes/header.php';
$_css = __DIR__ . '/noclegi.css';
?>
<link rel="stylesheet" href="<?= h(APP_URL) ?>/modules/noclegi/view/noclegi.css?v=<?= is_file($_css) ? (int)filemtime($_css) : 1 ?>">
<main class="ncl" id="main-content">
