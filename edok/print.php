<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/edok.php';

edok_require_access();
edok_migrate();

$id  = (int)($_GET['id'] ?? 0);
$doc = edok_get($id);
if (!$doc) { http_response_code(404); die('Dokument nie istnieje.'); }
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Karta akceptacji dokumentu — <?= h($doc['number']) ?></title>
<style>
  @page { size: A4; margin: 10mm 12mm; }
  <?= edok_print_css() ?>
  .noprint { margin: 10px auto; max-width: 700px; text-align: right; }
  @media print { .noprint { display: none; } body { padding: 0; } }
</style>
</head>
<body>

<div class="noprint">
  <button onclick="window.print()">Drukuj</button>
</div>

<?= edok_print_html($doc) ?>

</body>
</html>
