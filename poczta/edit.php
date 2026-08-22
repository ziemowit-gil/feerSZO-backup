<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';
require_once dirname(__DIR__) . '/includes/poczta_acl.php';

require_login();
require_module_enabled('poczta_enabled', 'Moduł Poczty');
poczta_acl_migrate();

$id = (int)($_GET['id'] ?? 0);
$mb = db_one("SELECT * FROM poczta_mailboxes WHERE id=?", [$id]);
if (!$mb) { flash_set('error', 'Nie znaleziono skrzynki.'); header('Location: ' . APP_URL . '/poczta/index.php'); exit; }

// Konfigurację zmienia osoba zarządzająca skrzynką (admin, właściciel skrzynki
// osobistej, delegat z can_manage). Rodzaj skrzynki, właściciela i listę dostępu
// ustala wyłącznie administrator — to decyzja organizacyjna, nie użytkownika.
if (!poczta_can_access($id, 'manage')) {
    flash_set('error', 'Brak uprawnień do tej skrzynki.');
    header('Location: ' . APP_URL . '/poczta/index.php'); exit;
}
$is_adm = is_admin();

$PAGE_TITLE = 'Edytuj skrzynkę';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'acl_grant' && $is_adm) {
        $u = (int)($_POST['acl_user_id'] ?? 0);
        if ($u) {
            poczta_acl_grant($id, $u, !empty($_POST['acl_manage']));
            flash_set('success', 'Dostęp nadany.');
        } else {
            flash_set('error', 'Wybierz osobę.');
        }
        header('Location: ' . APP_URL . '/poczta/edit.php?id=' . $id); exit;
    }

    if ($action === 'acl_revoke' && $is_adm) {
        poczta_acl_revoke($id, (int)($_POST['acl_user_id'] ?? 0));
        flash_set('success', 'Dostęp odebrany.');
        header('Location: ' . APP_URL . '/poczta/edit.php?id=' . $id); exit;
    }

    if ($action === 'delete') {
        if (!$is_adm) { flash_set('error', 'Skrzynkę usuwa administrator.'); header('Location: ' . APP_URL . '/poczta/edit.php?id=' . $id); exit; }
        db()->prepare("DELETE FROM poczta_mailboxes WHERE id=?")->execute([$id]);
        flash_set('success', 'Skrzynka usunięta z listy skanowania.');
        header('Location: ' . APP_URL . '/poczta/index.php'); exit;
    }

    $display_name = trim($_POST['display_name'] ?? '');
    $enabled      = isset($_POST['enabled']) ? 1 : 0;

    db()->prepare(
        "UPDATE poczta_mailboxes SET display_name=?, enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=?"
    )->execute([$display_name, $enabled, $id]);

    if ($is_adm) {
        $kind  = in_array($_POST['kind'] ?? '', array_keys(POCZTA_KINDS), true) ? $_POST['kind'] : 'shared';
        $owner = $kind === 'personal' ? ((int)($_POST['owner_user_id'] ?? 0) ?: null) : null;
        db()->prepare("UPDATE poczta_mailboxes SET kind=?, owner_user_id=? WHERE id=?")
            ->execute([$kind, $owner, $id]);
    }

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
            <?php if ($is_adm): ?>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label">Rodzaj skrzynki</label>
                    <select name="kind" id="pc-kind" class="form-select"
                            onchange="document.getElementById('pc-owner-wrap').style.display = this.value === 'personal' ? '' : 'none'">
                        <?php foreach (POCZTA_KINDS as $kk => $kv): ?>
                        <option value="<?= h($kk) ?>" <?= ($mb['kind'] ?? 'shared') === $kk ? 'selected' : '' ?>><?= h($kv['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text" style="font-size:.75rem">
                        Współdzielona — dostęp z listy poniżej. Osobista — skrzynka współpracownika,
                        dostępna właścicielowi (i delegatom z listy).
                    </div>
                </div>
                <div class="col-6" id="pc-owner-wrap" style="<?= ($mb['kind'] ?? 'shared') === 'personal' ? '' : 'display:none' ?>">
                    <label class="form-label">Właściciel</label>
                    <select name="owner_user_id" class="form-select">
                        <option value="">— nie wskazano —</option>
                        <?php foreach (db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name") as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)($mb['owner_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= h($u['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>
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

<?php if ($is_adm): ?>
<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-lock me-1"></i>Kto ma dostęp do tej skrzynki</div>
    <div class="card-body">
        <?php $acl = poczta_acl_list($id); ?>
        <?php if (($mb['kind'] ?? 'shared') === 'personal' && !empty($mb['owner_user_id'])):
            $own = db_one("SELECT name FROM users WHERE id=?", [(int)$mb['owner_user_id']]); ?>
        <div class="small text-muted mb-2">
            <i class="bi bi-person-fill me-1"></i>Właściciel: <strong><?= h($own['name'] ?? '—') ?></strong>
            — ma dostęp bez wpisu na liście.
        </div>
        <?php endif; ?>

        <?php if (!$acl): ?>
        <div class="text-muted small mb-2">
            Nikomu nie nadano dostępu.
            <?= ($mb['kind'] ?? 'shared') === 'shared'
                ? 'Skrzynka współdzielona bez wpisów jest widoczna tylko dla administratorów.'
                : '' ?>
        </div>
        <?php else: ?>
        <table class="table table-sm align-middle" style="font-size:.85rem">
            <thead class="table-light"><tr><th>Osoba</th><th>Zarządza</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($acl as $a): ?>
            <tr>
                <td><?= h($a['user_name']) ?><div class="text-muted" style="font-size:.75rem"><?= h($a['user_email']) ?></div></td>
                <td><?= (int)$a['can_manage'] === 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end">
                    <form method="post" onsubmit="return confirm('Odebrać dostęp?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="acl_revoke">
                        <input type="hidden" name="acl_user_id" value="<?= (int)$a['user_id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Odbierz dostęp"><i class="bi bi-x-lg"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="acl_grant">
            <div class="col-7">
                <label class="form-label small mb-1">Nadaj dostęp</label>
                <select name="acl_user_id" class="form-select form-select-sm" required>
                    <option value="">— wybierz osobę —</option>
                    <?php foreach (db_all("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name") as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?> (<?= h($u['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="acl_manage" value="1" id="aclm">
                    <label class="form-check-label small" for="aclm">może zarządzać</label>
                </div>
            </div>
            <div class="col-2 text-end">
                <button class="btn btn-sm btn-primary w-100" style="background:var(--pc-blue);border-color:var(--pc-blue)">
                    <i class="bi bi-plus-lg"></i>
                </button>
            </div>
            <div class="col-12 form-text" style="font-size:.75rem">
                Odczyt = widzi wiadomości tej skrzynki w Skrzynce CRM i Poczcie EZD.
                Zarządzanie = może też skanować i zmieniać konfigurację skrzynki.
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

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
