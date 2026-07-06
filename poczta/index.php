<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';

require_login();
require_module_enabled('poczta_enabled', 'Moduł Poczty');
require_role('admin'); // lista skrzynek (opt-in, RODO) — zarządzają tylko administratorzy

$PAGE_TITLE = 'Skrzynki';

$_mailboxes = [];
try {
    $_mailboxes = db_all("SELECT * FROM poczta_mailboxes ORDER BY mailbox ASC");
} catch (\Throwable $e) {}

$_webmail_url = org_setting('poczta_webmail_url');

include __DIR__ . '/includes/header_poczta.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-inboxes me-2" style="color:var(--pc-blue)"></i>Skrzynki do skanowania</h4>
    <div class="d-flex gap-2">
        <?php if ($_webmail_url): ?>
        <a href="<?= h($_webmail_url) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz Roundcube
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/poczta/add.php" class="btn btn-sm btn-primary" style="background:var(--pc-blue);border-color:var(--pc-blue)">
            <i class="bi bi-plus-lg me-1"></i>Dodaj skrzynkę
        </a>
    </div>
</div>

<div class="alert alert-secondary py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    Skanowane są wyłącznie skrzynki wskazane poniżej (jawna lista opt-in) — nie skanujemy automatycznie
    poczty wszystkich kont Microsoft 365 w organizacji.
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if (empty($_mailboxes)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mt-2 mb-0">Brak skrzynek — dodaj pierwszą, aby zacząć skanowanie.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Skrzynka</th>
                        <th>Włączona</th>
                        <th>Status</th>
                        <th>Ostatnie skanowanie</th>
                        <th>Dopasowania</th>
                        <th class="text-end">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($_mailboxes as $mb):
                    $status_map = [
                        'ok'           => ['label' => 'OK',                 'class' => 'success'],
                        'rate_limited' => ['label' => 'Limit zapytań',      'class' => 'warning'],
                        'auth_error'   => ['label' => 'Wymaga autoryzacji', 'class' => 'danger'],
                        'disabled'     => ['label' => 'Wyłączona',         'class' => 'secondary'],
                    ];
                    $si = $status_map[$mb['status']] ?? ['label' => h($mb['status']), 'class' => 'secondary'];
                ?>
                <tr data-mailbox-id="<?= $mb['id'] ?>">
                    <td>
                        <div class="fw-semibold"><?= h($mb['display_name'] ?: $mb['mailbox']) ?></div>
                        <div class="text-muted small"><?= h($mb['mailbox']) ?></div>
                    </td>
                    <td>
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input pc-toggle" role="switch"
                                   data-id="<?= $mb['id'] ?>" <?= $mb['enabled'] ? 'checked' : '' ?>
                                   aria-label="Włącz/wyłącz skanowanie skrzynki <?= h($mb['mailbox']) ?>">
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-<?= $si['class'] ?>"><?= $si['label'] ?></span>
                        <?php if ($mb['status_detail']): ?>
                        <div class="text-muted small mt-1" style="max-width:280px"><?= h(mb_substr($mb['status_detail'],0,140)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap small"><?= $mb['last_scan_at'] ? date('d.m.Y H:i', strtotime($mb['last_scan_at'])) : '—' ?></td>
                    <td><?= (int)$mb['matched_total'] ?></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-primary pc-scan-now" data-id="<?= $mb['id'] ?>" title="Skanuj teraz">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        <?php if ($mb['status'] === 'auth_error'): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning pc-reset-status" data-id="<?= $mb['id'] ?>" title="Zresetuj status po naprawie">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/poczta/edit.php?id=<?= $mb['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edytuj"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('pc-toggle')) return;
    const id = e.target.dataset.id;
    fetch('<?= APP_URL ?>/poczta/api/action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'toggle_enabled', mailbox_id: id, enabled: e.target.checked ? 1 : 0})
    }).then(r => r.json()).then(j => { if (!j.ok) alert(j.message || 'Błąd zapisu.'); });
});

document.addEventListener('click', function (e) {
    const btn = e.target.closest('.pc-scan-now, .pc-reset-status');
    if (!btn) return;
    const action = btn.classList.contains('pc-scan-now') ? 'scan_now' : 'reset_status';
    const id = btn.dataset.id;
    btn.disabled = true;
    fetch('<?= APP_URL ?>/poczta/api/action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: action, mailbox_id: id})
    }).then(r => r.json()).then(j => {
        if (!j.ok) { alert(j.message || 'Błąd.'); btn.disabled = false; return; }
        window.location.reload();
    }).catch(() => { btn.disabled = false; });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
