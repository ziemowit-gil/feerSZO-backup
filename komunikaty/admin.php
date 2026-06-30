<?php
/**
 * komunikaty/admin.php — Zarządzanie komunikatami placówki (admin).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_login();
notif_migrate();

if (!is_admin()) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/komunikaty/index.php');
    exit;
}

$_cu = current_user();

// ── Akcje ─────────────────────────────────────────────────────────────────────
$op = $_POST['_op'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($op === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db_query("UPDATE announcements SET is_active=0, updated_at=datetime('now') WHERE id=?", [$id]);
        flash_set('success', 'Komunikat dezaktywowany.');
        header('Location: ' . APP_URL . '/komunikaty/admin.php');
        exit;
    }
    if ($op === 'activate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db_query("UPDATE announcements SET is_active=1, updated_at=datetime('now') WHERE id=?", [$id]);
        flash_set('success', 'Komunikat aktywowany.');
        header('Location: ' . APP_URL . '/komunikaty/admin.php');
        exit;
    }
    if ($op === 'pin') {
        $id = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['val'] ?? 0);
        if ($id) db_query("UPDATE announcements SET is_pinned=?, updated_at=datetime('now') WHERE id=?", [$val, $id]);
        flash_set('success', $val ? 'Komunikat przypięty.' : 'Odepnięto komunikat.');
        header('Location: ' . APP_URL . '/komunikaty/admin.php');
        exit;
    }
    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db_query("DELETE FROM announcement_reads WHERE announcement_id=?", [$id]);
            db_query("DELETE FROM announcements WHERE id=?", [$id]);
        }
        flash_set('success', 'Komunikat usunięty.');
        header('Location: ' . APP_URL . '/komunikaty/admin.php');
        exit;
    }
}

// ── Dane ──────────────────────────────────────────────────────────────────────
$filter_active = $_GET['active'] ?? 'all'; // all | 1 | 0
$where = match($filter_active) {
    '1' => 'WHERE a.is_active=1',
    '0' => 'WHERE a.is_active=0',
    default => '',
};

$announcements = db_all(
    "SELECT a.*,
            (SELECT COUNT(*) FROM announcement_reads ar WHERE ar.announcement_id=a.id) AS reads_count
     FROM announcements a
     $where
     ORDER BY a.is_pinned DESC, a.created_at DESC",
    []
);

$total_active   = (int)(db_one("SELECT COUNT(*) AS n FROM announcements WHERE is_active=1", [])['n'] ?? 0);
$total_inactive = (int)(db_one("SELECT COUNT(*) AS n FROM announcements WHERE is_active=0", [])['n'] ?? 0);

$AUDIENCE_LABELS = [
    'all'         => ['label'=>'Wszyscy','icon'=>'bi-people-fill','color'=>'#2563eb'],
    'public'      => ['label'=>'Publiczne','icon'=>'bi-globe','color'=>'#059669'],
    'role:admin'  => ['label'=>'Administratorzy','icon'=>'bi-shield-lock','color'=>'#7c3aed'],
    'role:editor' => ['label'=>'Edytorzy','icon'=>'bi-pencil','color'=>'#d97706'],
    'role:viewer' => ['label'=>'Przeglądający','icon'=>'bi-eye','color'=>'#6b7280'],
];

$DISPLAY_LABELS = [
    'feed'   => ['label'=>'Kanał','icon'=>'bi-list-ul'],
    'banner' => ['label'=>'Banner','icon'=>'bi-megaphone'],
    'popup'  => ['label'=>'Popup','icon'=>'bi-window-stack'],
];

$PAGE_TITLE = 'Zarządzanie komunikatami';
require_once dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:1000px">
<?= flash_html() ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-megaphone me-2 text-primary"></i>Komunikaty placówki</h1>
  <a href="<?= APP_URL ?>/komunikaty/compose.php" class="btn btn-primary btn-sm ms-auto">
    <i class="bi bi-plus-lg me-1"></i>Nowy komunikat
  </a>
</div>

<!-- Filtry + statystyki -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="?active=all" class="btn btn-sm <?= $filter_active==='all'?'btn-secondary':'btn-outline-secondary' ?>">Wszystkie (<?= $total_active+$total_inactive ?>)</a>
  <a href="?active=1"   class="btn btn-sm <?= $filter_active==='1'?'btn-success':'btn-outline-success' ?>">Aktywne (<?= $total_active ?>)</a>
  <a href="?active=0"   class="btn btn-sm <?= $filter_active==='0'?'btn-secondary':'btn-outline-secondary' ?>">Nieaktywne (<?= $total_inactive ?>)</a>
</div>

<?php if (!$announcements): ?>
<div class="alert alert-light border text-body-secondary">
  <i class="bi bi-megaphone me-1"></i>Brak komunikatów. Kliknij „Nowy komunikat", aby opublikować pierwszy.
</div>
<?php else: ?>
<div class="d-flex flex-column gap-2">
<?php foreach ($announcements as $a):
  $ainfo = $AUDIENCE_LABELS[$a['audience']] ?? ['label'=>h($a['audience']),'icon'=>'bi-person','color'=>'#6b7280'];
  $dinfo = $DISPLAY_LABELS[$a['display_mode']] ?? $DISPLAY_LABELS['feed'];
  $is_active = (int)$a['is_active'];
  $is_pinned = (int)$a['is_pinned'];
  $expired = $a['expires_at'] && $a['expires_at'] < date('Y-m-d');
?>
<div class="card border-0 shadow-sm <?= !$is_active ? 'opacity-60' : '' ?>" style="<?= $is_pinned?'border-left:3px solid #f59e0b!important':'' ?>">
  <div class="card-body py-2 px-3">
    <div class="d-flex flex-wrap align-items-start gap-2">
      <!-- Tytuł + meta -->
      <div class="flex-grow-1 min-width-0">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <?php if ($is_pinned): ?><i class="bi bi-pin-angle-fill text-warning" title="Przypięty"></i><?php endif; ?>
          <a href="<?= APP_URL ?>/komunikaty/announcement.php?id=<?= (int)$a['id'] ?>" class="fw-semibold text-decoration-none text-body">
            <?= h($a['title']) ?>
          </a>
          <?php if (!$is_active): ?><span class="badge text-bg-secondary">nieaktywny</span><?php endif; ?>
          <?php if ($expired): ?><span class="badge text-bg-warning">wygasł</span><?php endif; ?>
        </div>
        <div class="mt-1 d-flex flex-wrap gap-2" style="font-size:.8rem">
          <span class="text-body-secondary"><i class="bi bi-person me-1"></i><?= h($a['author_name'] ?? '—') ?></span>
          <span class="text-body-secondary"><i class="bi bi-clock me-1"></i><?= substr($a['created_at'],0,16) ?></span>
          <span style="color:<?= h($ainfo['color']) ?>"><i class="<?= h($ainfo['icon']) ?> me-1"></i><?= h($ainfo['label']) ?></span>
          <span class="text-body-secondary"><i class="<?= h($dinfo['icon']) ?> me-1"></i><?= h($dinfo['label']) ?></span>
          <?php if ($a['expires_at']): ?><span class="text-body-secondary"><i class="bi bi-calendar-x me-1"></i>do <?= h($a['expires_at']) ?></span><?php endif; ?>
          <span class="text-body-secondary"><i class="bi bi-eye me-1"></i><?= (int)$a['reads_count'] ?> odczytań</span>
        </div>
      </div>
      <!-- Akcje -->
      <div class="d-flex gap-1 flex-shrink-0">
        <?php if ($is_pinned): ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="pin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="val" value="0">
          <button class="btn btn-sm btn-outline-warning py-0 px-2" title="Odepnij"><i class="bi bi-pin-angle"></i></button>
        </form>
        <?php else: ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="pin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="val" value="1">
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Przypnij"><i class="bi bi-pin-angle-fill"></i></button>
        </form>
        <?php endif; ?>
        <?php if ($is_active): ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Dezaktywować komunikat?')">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="deactivate"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Dezaktywuj"><i class="bi bi-eye-slash"></i></button>
        </form>
        <?php else: ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="activate"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="btn btn-sm btn-outline-success py-0 px-2" title="Aktywuj"><i class="bi bi-eye"></i></button>
        </form>
        <?php endif; ?>
        <form method="post" class="d-inline" onsubmit="return confirm('Trwale usunąć komunikat?')">
          <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń"><i class="bi bi-trash"></i></button>
        </form>
      </div>
    </div>
    <?php if ($a['body']): ?>
    <div class="mt-1 text-body-secondary small text-truncate" style="max-width:600px"><?= h(mb_substr($a['body'],0,150)) ?><?= mb_strlen($a['body'])>150?'…':'' ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
