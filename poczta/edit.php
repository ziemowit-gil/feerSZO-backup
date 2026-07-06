<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';

require_login();
require_module_enabled('poczta_enabled', 'Moduł Poczty');
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$mb = db_one("SELECT * FROM poczta_mailboxes WHERE id=?", [$id]);
if (!$mb) { flash_set('error', 'Nie znaleziono skrzynki.'); header('Location: ' . APP_URL . '/poczta/index.php'); exit; }

$PAGE_TITLE = 'Edytuj skrzynkę';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        db()->prepare("DELETE FROM poczta_mailboxes WHERE id=?")->execute([$id]);
        flash_set('success', 'Skrzynka usunięta z listy skanowania.');
        header('Location: ' . APP_URL . '/poczta/index.php'); exit;
    }

    $display_name = trim($_POST['display_name'] ?? '');
    $enabled      = isset($_POST['enabled']) ? 1 : 0;

    db()->prepare(
        "UPDATE poczta_mailboxes SET display_name=?, enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
    )->execute([$display_name, $enabled, $id]);

    flash_set('success', 'Zapisano zmiany.');
    header('Location: ' . APP_URL . '/poczta/index.php'); exit;
}

$_recent_log = [];
try {
    $_recent_log = db_all("SELECT * FROM poczta_scan_log WHERE mailbox_id=? ORDER BY run_at DESC LIMIT 10", [$id]);
} catch (\Throwable $e) {}

include __DIR__ . '/includes/header_poczta.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-pencil me-2" style="color:var(--pc-blue)"></i>Edytuj skrzynkę</h4>
    <a href="<?= APP_URL ?>/poczta/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Wróć do listy</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?></div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-6">
<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="mb-3">
                <label class="form-label">Adres skrzynki</label>
                <input type="text" class="form-control" value="<?= h($mb['mailbox']) ?>" disabled>
            </div>
            <div class="mb-3">
                <label class="form-label">Nazwa wyświetlana</label>
                <input type="text" name="display_name" class="form-control" value="<?= h($mb['display_name']) ?>">
            </div>
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" role="switch" id="enabled" name="enabled" <?= $mb['enabled'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="enabled">Skanowanie włączone</label>
            </div>
            <button type="submit" class="btn btn-primary" style="background:var(--pc-blue);border-color:var(--pc-blue)">
                <i class="bi bi-check-lg me-1"></i>Zapisz
            </button>
        </form>
    </div>
</div>

<form method="post" class="mt-3" onsubmit="return confirm('Usunąć tę skrzynkę z listy skanowania? Zapisane wiadomości w CRM nie zostaną usunięte.');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Usuń skrzynkę z listy</button>
</form>
</div>

<div class="col-lg-6">
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-ul me-1"></i>Historia skanowania</div>
    <?php if (empty($_recent_log)): ?>
    <div class="text-center text-muted py-4"><small>Brak historii.</small></div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.82rem">
            <thead class="table-light"><tr><th>Data</th><th>Pobrane</th><th>Nowe</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($_recent_log as $l):
                $lc = match($l['status']) { 'ok'=>'success', 'rate_limited'=>'warning', 'error'=>'danger', default=>'secondary' };
            ?>
            <tr>
                <td class="text-nowrap text-muted"><?= date('d.m.Y H:i', strtotime($l['run_at'])) ?></td>
                <td><?= (int)$l['fetched'] ?></td>
                <td><?= (int)$l['created'] ?></td>
                <td><span class="badge bg-<?= $lc ?>"><?= h($l['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
