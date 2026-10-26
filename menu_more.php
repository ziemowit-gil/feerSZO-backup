<?php
/** menu_more.php — „Więcej”: pełna lista pozycji zakładki menu (te, które nie mieszczą się w rozwijanym menu), pogrupowana. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/menu.php';
require_login();
$tab = (string)($_GET['tab'] ?? '');
$full = menu_build(false);
$node = null;
foreach ($full['tree'] as $n) if ((string)($n['id'] ?? '') === $tab && !empty($n['groups'])) { $node = $n; break; }
if (!$node) { header('Location: ' . APP_URL . '/index.php'); exit; }
$groups = $node['groups'];
$PAGE_TITLE = $node['label'] . ' — wszystkie pozycje';
include __DIR__ . '/includes/header.php';
?>
<h4 class="mb-3"><i class="bi <?= h($node['icon']) ?> text-primary"></i> <?= h($node['label']) ?> — wszystkie pozycje</h4>
<?php foreach ($groups as $g): ?>
  <?php if (!empty($g['label'])): ?><h5 class="h6 text-uppercase text-muted mt-4 mb-2"><?= h($g['label']) ?></h5><?php endif; ?>
  <div class="row g-2">
  <?php foreach ($g['items'] as $it): ?>
    <div class="col-sm-6 col-lg-4 col-xl-3"><a class="card h-100 shadow-sm text-decoration-none" href="<?= APP_URL . h($it['path']) ?>"><div class="card-body py-2 d-flex gap-2 align-items-center">
      <i class="bi <?= h($it['icon']) ?> text-primary" aria-hidden="true"></i><span class="text-body"><?= h($it['label']) ?></span>
      <?php if (!empty($it['badge'])): ?><span class="badge bg-danger ms-auto"><?= (int)$it['badge'] ?></span><?php endif; ?></div></a></div>
  <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
