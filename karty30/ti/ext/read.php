<?php
/**
 * karty30/ti/ext/read.php — czytnik: plik osadzony w stronie modułu.
 *
 * Osadzamy zamiast przekierowywać, żeby użytkownik został w systemie (pasek
 * powrotu, informacja o czasie ważności) i żeby dało się nałożyć politykę
 * bezpieczeństwa na sam widok.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_subject($EXT_SUBJECT);

$ticket = ext_ticket_get((string)($_GET['t'] ?? ''));
if (!$ticket || $ticket['expires_at'] < date('Y-m-d H:i:s')) {
    flash_set('danger', 'Link do materiału wygasł — otwórz go ponownie.');
    header('Location: index.php'); exit;
}

$resource = ext_resource_get((int)$ticket['resource_id']);
$ctx      = $resource ? ext_resource_context((int)$resource['id']) : null;
if (!$ctx) { header('Location: index.php'); exit; }

$EXT_TITLE = $ctx['title']['title'];
$EXT_TAB   = 'katalog';
include __DIR__ . '/_head.php';
?>

<div class="skin-crumbs mb-2">
  <a href="index.php">Katalog</a> &rsaquo;
  <a href="title.php?id=<?= (int)$ctx['title']['id'] ?>"><?= h($ctx['title']['title']) ?></a> &rsaquo;
  <strong><?= h($resource['name']) ?></strong>
</div>

<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <p class="small text-body-secondary mb-0">
    <i class="bi bi-clock me-1" aria-hidden="true"></i>
    Ten podgląd jest ważny do <time datetime="<?= h(str_replace(' ', 'T', substr((string)$ticket['expires_at'], 0, 16))) ?>"><?= h(substr((string)$ticket['expires_at'], 11, 5)) ?></time>.
    Po tym czasie otwórz materiał ponownie z karty tytułu.
  </p>
  <?php if ($ticket['watermark'] !== 'none'): ?>
  <p class="small text-body-secondary mb-0 ms-auto">
    <i class="bi bi-droplet me-1" aria-hidden="true"></i>Egzemplarz oznaczony Twoimi danymi.
  </p>
  <?php endif; ?>
</div>

<?php /* Wysokość liczona od okna, żeby czytnik nie walczył o miejsce z paskami. */ ?>
<iframe src="file.php?t=<?= h((string)$ticket['id']) ?>"
        title="Podgląd materiału: <?= h($resource['name']) ?>"
        style="width:100%;height:calc(100vh - 12rem);border:1px solid var(--ti-grid)"></iframe>

<noscript>
  <p class="small">Jeśli podgląd się nie pojawił, <a href="file.php?t=<?= h((string)$ticket['id']) ?>">otwórz plik bezpośrednio</a>.</p>
</noscript>

<?php include __DIR__ . '/_foot.php'; ?>
