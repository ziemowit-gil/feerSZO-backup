<?php
/** contracts/obsluga.php — „Więcej narzędzi” obsługi umów: kafle z pozycjami spoza głównego menu Umowy (opis + odznaki). */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/menu.php';
require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
$tools = _menu_contract_more(_menu_counts());
$PAGE_TITLE = 'Obsługa umów — więcej narzędzi';
include dirname(__DIR__) . '/includes/header.php';
?>
<h4 class="mb-3"><i class="bi bi-grid-3x3-gap text-primary"></i> Obsługa umów — więcej narzędzi</h4>
<?php if (!$tools): ?><div class="alert alert-light border">Brak dodatkowych narzędzi dostępnych dla Twojego konta.</div><?php endif; ?>
<div class="row g-3">
<?php foreach ($tools as $t): ?>
  <div class="col-md-6 col-lg-4"><a class="card h-100 shadow-sm text-decoration-none" href="<?= APP_URL . h($t['path']) ?>">
    <div class="card-body d-flex gap-3 align-items-start"><i class="bi <?= h($t['icon']) ?> fs-3 text-primary" aria-hidden="true"></i>
      <div><div class="fw-semibold text-body"><?= h($t['label']) ?><?php if (!empty($t['badge'])): ?> <span class="badge bg-danger"><?= (int)$t['badge'] ?></span><?php endif; ?></div>
        <div class="small text-muted"><?= h($t['desc'] ?? '') ?></div></div></div></a></div>
<?php endforeach; ?>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
