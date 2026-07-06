<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';

require_login();
require_module_enabled('poczta_enabled', 'Moduł Poczty');

$PAGE_TITLE = 'Dashboard';

$_kpi_mailboxes = 0;
$_kpi_attention = 0;
$_kpi_today     = 0;
$_kpi_total     = 0;
try {
    $_kpi_mailboxes = (int)(db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes WHERE enabled=1")['n'] ?? 0);
    $_kpi_attention = (int)(db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes WHERE status IN ('auth_error','rate_limited')")['n'] ?? 0);
    $_kpi_today     = (int)(db_one("SELECT COUNT(*) AS n FROM crm_communications WHERE mailbox_id IS NOT NULL AND DATE(sent_at)=DATE('now','localtime')")['n'] ?? 0);
    $_kpi_total     = (int)(db_one("SELECT COUNT(*) AS n FROM crm_communications WHERE mailbox_id IS NOT NULL")['n'] ?? 0);
} catch (\Throwable $e) {}

$_mailboxes = [];
try {
    $_mailboxes = db_all("SELECT * FROM poczta_mailboxes ORDER BY (status != 'ok') DESC, mailbox ASC");
} catch (\Throwable $e) {}

$_recent_log = [];
try {
    $_recent_log = db_all(
        "SELECT l.*, m.mailbox FROM poczta_scan_log l
         LEFT JOIN poczta_mailboxes m ON m.id = l.mailbox_id
         ORDER BY l.run_at DESC LIMIT 15"
    );
} catch (\Throwable $e) {}

include __DIR__ . '/includes/header_poczta.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-envelope-fill me-2" style="color:var(--pc-blue)"></i>Poczta — dashboard</h4>
    <a href="<?= APP_URL ?>/poczta/add.php" class="btn btn-sm btn-primary" style="background:var(--pc-blue);border-color:var(--pc-blue)">
        <i class="bi bi-plus-lg me-1"></i>Dodaj skrzynkę
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><i class="bi bi-inboxes me-1"></i>Skrzynki (aktywne)</div>
                <div class="fs-3 fw-bold"><?= $_kpi_mailboxes ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 <?= $_kpi_attention ? 'border-warning' : '' ?>">
            <div class="card-body">
                <div class="text-muted small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Wymaga uwagi</div>
                <div class="fs-3 fw-bold <?= $_kpi_attention ? 'text-warning' : '' ?>"><?= $_kpi_attention ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><i class="bi bi-calendar-day me-1"></i>Dopasowano dziś</div>
                <div class="fs-3 fw-bold"><?= $_kpi_today ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-1"><i class="bi bi-collection me-1"></i>Dopasowano łącznie</div>
                <div class="fs-3 fw-bold"><?= $_kpi_total ?></div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($_mailboxes)): ?>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-inboxes me-1"></i>Skrzynki</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Skrzynka</th><th>Status</th><th>Ostatnie skanowanie</th><th>Dopasowania</th><th class="text-end">Akcje</th></tr>
            </thead>
            <tbody>
            <?php foreach ($_mailboxes as $mb):
                $status_map = [
                    'ok'           => ['label' => 'OK',                  'class' => 'success'],
                    'rate_limited' => ['label' => 'Limit zapytań',       'class' => 'warning'],
                    'auth_error'   => ['label' => 'Wymaga autoryzacji',  'class' => 'danger'],
                    'disabled'     => ['label' => 'Wyłączona',          'class' => 'secondary'],
                ];
                $si = $status_map[$mb['status']] ?? ['label' => h($mb['status']), 'class' => 'secondary'];
            ?>
            <tr>
                <td>
                    <div class="fw-semibold"><?= h($mb['display_name'] ?: $mb['mailbox']) ?></div>
                    <div class="text-muted small"><?= h($mb['mailbox']) ?></div>
                </td>
                <td>
                    <span class="badge bg-<?= $si['class'] ?>"><?= $si['label'] ?></span>
                    <?php if ($mb['status_detail']): ?>
                    <div class="text-muted small mt-1" style="max-width:260px"><?= h(mb_substr($mb['status_detail'],0,120)) ?></div>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap small"><?= $mb['last_scan_at'] ? date('d.m.Y H:i', strtotime($mb['last_scan_at'])) : '—' ?></td>
                <td><?= (int)$mb['matched_total'] ?></td>
                <td class="text-end text-nowrap">
                    <a href="<?= APP_URL ?>/poczta/edit.php?id=<?= $mb['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edytuj"><i class="bi bi-pencil"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-ul me-1"></i>Ostatnie skanowania</div>
    <?php if (empty($_recent_log)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:.3"></i>
        <p class="mt-2 mb-0">Brak historii skanowania — dodaj skrzynkę, aby zacząć.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr><th>Data</th><th>Skrzynka</th><th>Pobrane</th><th>Dopasowane</th><th>Nowe</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($_recent_log as $l):
                $lc = match($l['status']) { 'ok'=>'success', 'rate_limited'=>'warning', 'error'=>'danger', default=>'secondary' };
            ?>
            <tr>
                <td class="text-nowrap text-muted"><?= date('d.m.Y H:i', strtotime($l['run_at'])) ?></td>
                <td><?= h($l['mailbox'] ?? '—') ?></td>
                <td><?= (int)$l['fetched'] ?></td>
                <td><?= (int)$l['matched'] ?></td>
                <td><?= (int)$l['created'] ?></td>
                <td>
                    <span class="badge bg-<?= $lc ?>"><?= h($l['status']) ?></span>
                    <?php if ($l['error']): ?><br><small class="text-danger"><?= h(mb_substr($l['error'],0,60)) ?></small><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
