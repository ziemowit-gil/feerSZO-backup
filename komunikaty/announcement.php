<?php
require_once dirname(__DIR__) . '/includes/header.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_login();
notif_migrate();

$_cu   = current_user();
$_uid  = (int)$_cu['id'];
$_role = $_cu['role'] ?? 'viewer';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash_set('danger', 'Nieprawidłowe ogłoszenie.');
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

try {
    $ann = db_one("SELECT * FROM announcements WHERE id=? AND is_active=1", [$id]);
} catch (\Throwable $e) {
    $ann = null;
}

if (!$ann) {
    flash_set('danger', 'Ogłoszenie nie zostało znalezione.');
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

// Sprawdź dostęp
if (!_ann_user_can_see($ann['audience'] ?? 'all', $_uid, $_role)) {
    flash_set('danger', 'Brak dostępu do tego ogłoszenia.');
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

// Sprawdź wygaśnięcie
if (!empty($ann['expires_at']) && $ann['expires_at'] < date('Y-m-d')) {
    flash_set('warning', 'To ogłoszenie wygasło.');
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

// Auto-oznacz jako przeczytane
ann_mark_read($id, $_uid);

$PAGE_TITLE = h($ann['title']);
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i>
  </a>
  <h5 class="mb-0 fw-semibold"><i class="bi bi-megaphone me-2 text-warning"></i>Ogłoszenie</h5>
</div>

<div class="row justify-content-center">
  <div class="col-lg-9">
    <div class="card border-0 shadow-sm rounded-3"
         style="<?= (int)($ann['is_pinned'] ?? 0) ? 'background:#FFFBEB;border-left:3px solid #F59E0B!important' : '' ?>">
      <div class="card-body p-4">

        <div class="d-flex align-items-start flex-wrap gap-2 mb-3">
          <?php if ((int)($ann['is_pinned'] ?? 0)): ?>
          <span class="badge" style="background:#F59E0B;font-size:.7rem"><i class="bi bi-pin-angle-fill me-1"></i>Przypięte</span>
          <?php endif; ?>
          <span class="badge bg-light text-secondary border" style="font-size:.7rem">
            <?= h(ann_audience_label($ann['audience'] ?? 'all')) ?>
          </span>
          <?php $_kc = ann_kategoria_color($ann['kategoria'] ?? 'ogolne'); ?>
          <span class="badge" style="font-size:.7rem;background:<?= $_kc ?>1A;color:<?= $_kc ?>;border:1px solid <?= $_kc ?>55">
            <?= h(ann_kategoria_label($ann['kategoria'] ?? 'ogolne')) ?>
          </span>
          <?php if (!empty($ann['expires_at'])): ?>
          <span class="badge bg-light text-secondary border" style="font-size:.7rem">
            <i class="bi bi-calendar-x me-1"></i>Wygasa: <?= h($ann['expires_at']) ?>
          </span>
          <?php endif; ?>
        </div>

        <h4 class="fw-bold mb-1"><?= h($ann['title']) ?></h4>

        <div class="text-muted mb-4" style="font-size:.82rem">
          <i class="bi bi-person me-1"></i><?= h($ann['author_name'] ?? '') ?>
          <span class="mx-2">·</span>
          <i class="bi bi-calendar3 me-1"></i><?= h(substr($ann['created_at'] ?? '', 0, 16)) ?>
        </div>

        <div class="ann-body" style="white-space:pre-wrap;line-height:1.7;font-size:.93rem;word-break:break-word">
          <?= h($ann['body']) ?>
        </div>

        <div class="mt-4 pt-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
          <a href="<?= APP_URL ?>/komunikaty/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Powrót do listy
          </a>
          <small class="text-success"><i class="bi bi-check2-all me-1"></i>Oznaczono jako przeczytane</small>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
