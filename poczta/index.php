<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';
require_once dirname(__DIR__) . '/includes/poczta_acl.php';

require_login();
require_module_enabled('poczta_enabled', 'Moduł Poczty');
// Moduł jest dostępny dla każdego zalogowanego (także wolontariusza), ale lista
// pokazuje wyłącznie skrzynki, do których ma uprawnienia: swoją osobistą oraz te
// współdzielone, które nadał administrator (poczta_mailbox_acl). Dodawanie i
// konfiguracja skrzynek pozostają po stronie administratora.
poczta_acl_migrate();
$_can_add = is_admin();

$PAGE_TITLE = 'Skrzynki';

$_mailboxes = poczta_mailboxes_for_user();

$_webmail_url = org_setting('poczta_webmail_url');

include __DIR__ . '/includes/header_poczta.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-inboxes me-2" style="color:var(--pc-blue)"></i>Skrzynki do skanowania</h4>
    <div class="d-flex gap-2 flex-wrap">
        <?php foreach (poczta_webmail_options() as $w): ?>
        <a href="<?= h($w['url']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"
           title="<?= h($w['hint']) ?>">
            <i class="bi <?= h($w['icon']) ?> me-1"></i><?= h($w['label']) ?>
        </a>
        <?php endforeach; ?>
        <a href="<?= APP_URL ?>/crm/inbox.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-inbox me-1"></i>Skrzynka CRM
        </a>
        <?php if ($_can_add): ?>
        <a href="<?= APP_URL ?>/poczta/add.php" class="btn btn-sm btn-primary" style="background:var(--pc-blue);border-color:var(--pc-blue)">
            <i class="bi bi-plus-lg me-1"></i>Dodaj skrzynkę
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-secondary py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    Skanowane są wyłącznie skrzynki z tej listy (jawna zgoda, opt-in) — nie zaciągamy automatycznie
    poczty wszystkich kont Microsoft 365.
    <?php if (!is_admin()): ?>
    Widzisz swoją skrzynkę osobistą i te współdzielone, do których administrator nadał Ci dostęp.
    <?php endif; ?>
</div>

<?php if (is_admin() && $_mailboxes): ?>
<div class="d-flex align-items-center gap-2 mb-2 flex-wrap" id="pc-bulk" style="display:none!important">
    <span class="small text-muted"><span id="pc-count">0</span> zaznaczonych:</span>
    <button class="btn btn-sm btn-outline-primary pc-bulk-act" data-act="scan_now">
        <i class="bi bi-arrow-repeat me-1"></i>Skanuj
    </button>
    <button class="btn btn-sm btn-outline-secondary pc-bulk-act" data-act="enable">
        <i class="bi bi-toggle-on me-1"></i>Włącz
    </button>
    <button class="btn btn-sm btn-outline-secondary pc-bulk-act" data-act="disable">
        <i class="bi bi-toggle-off me-1"></i>Wyłącz
    </button>
    <button class="btn btn-sm btn-outline-warning pc-bulk-act" data-act="reset_status">
        <i class="bi bi-arrow-counterclockwise me-1"></i>Zresetuj status
    </button>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if (empty($_mailboxes)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mt-2 mb-0">
              <?= $_can_add
                  ? 'Brak skrzynek — dodaj pierwszą, aby zacząć skanowanie.'
                  : 'Nie masz dostępu do żadnej skrzynki. O dostęp do skrzynki współdzielonej poproś administratora.' ?>
            </p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if (is_admin()): ?>
                        <th style="width:34px"><input type="checkbox" id="pc-all" class="form-check-input" aria-label="Zaznacz wszystkie"></th>
                        <?php endif; ?>
                        <th>Skrzynka</th>
                        <th>Rodzaj</th>
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
                    <?php if (is_admin()): ?>
                    <td><input type="checkbox" class="form-check-input pc-pick" value="<?= (int)$mb['id'] ?>"
                               aria-label="Wybierz <?= h($mb['mailbox']) ?>"></td>
                    <?php endif; ?>
                    <td>
                        <div class="fw-semibold"><?= h($mb['display_name'] ?: $mb['mailbox']) ?></div>
                        <div class="text-muted small"><?= h($mb['mailbox']) ?></div>
                    </td>
                    <td class="small">
                        <?php $k = POCZTA_KINDS[$mb['kind'] ?? 'shared'] ?? POCZTA_KINDS['shared']; ?>
                        <i class="bi <?= $k['icon'] ?> me-1 text-muted"></i><?= h($k['label']) ?>
                        <?php if (($mb['kind'] ?? '') === 'personal' && !empty($mb['owner_name'])): ?>
                        <div class="text-muted"><?= h($mb['owner_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input pc-toggle" role="switch"
                                   data-id="<?= $mb['id'] ?>" <?= $mb['enabled'] ? 'checked' : '' ?>
                                   <?= empty($mb['can_manage']) ? 'disabled' : '' ?>
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
                        <?php if (empty($mb['can_manage'])): ?>
                        <span class="text-muted small">tylko odczyt</span>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm btn-outline-primary pc-scan-now" data-id="<?= $mb['id'] ?>" title="Skanuj teraz">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        <?php if ($mb['status'] === 'auth_error'): ?>
                        <button type="button" class="btn btn-sm btn-outline-warning pc-reset-status" data-id="<?= $mb['id'] ?>" title="Zresetuj status po naprawie">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                        <?php endif; ?>
                        <a href="<?= APP_URL ?>/poczta/edit.php?id=<?= $mb['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Edytuj i uprawnienia"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
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

/* ── Masowe akcje na skrzynkach ──────────────────────────────────────────── */
(function () {
    const bar   = document.getElementById('pc-bulk');
    const all   = document.getElementById('pc-all');
    const count = document.getElementById('pc-count');
    if (!bar) return;

    function picked() { return Array.from(document.querySelectorAll('.pc-pick:checked')).map(c => c.value); }
    function refresh() {
        const n = picked().length;
        count.textContent = n;
        bar.style.setProperty('display', n ? 'flex' : 'none', 'important');
    }
    document.addEventListener('change', function (e) {
        if (e.target === all) {
            document.querySelectorAll('.pc-pick').forEach(c => { c.checked = all.checked; });
        }
        if (e.target === all || e.target.classList.contains('pc-pick')) refresh();
    });

    bar.addEventListener('click', function (e) {
        const btn = e.target.closest('.pc-bulk-act');
        if (!btn) return;
        const ids = picked();
        if (!ids.length) return;
        const act = btn.dataset.act;
        const label = btn.textContent.trim();
        if (!confirm(label + ' — dotyczy ' + ids.length + ' skrzynek. Kontynuować?')) return;

        bar.querySelectorAll('button').forEach(b => { b.disabled = true; });
        // Po jednej skrzynce, żeby błąd na jednej nie przerywał pozostałych
        const calls = ids.map(function (id) {
            const body = act === 'enable'  ? {action: 'toggle_enabled', mailbox_id: id, enabled: 1}
                       : act === 'disable' ? {action: 'toggle_enabled', mailbox_id: id, enabled: 0}
                       : {action: act, mailbox_id: id};
            return fetch('<?= APP_URL ?>/poczta/api/action.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
            }).then(r => r.json()).catch(() => ({ok: false}));
        });
        Promise.all(calls).then(function (res) {
            const bad = res.filter(r => !r.ok).length;
            if (bad) alert('Nie udało się wykonać akcji dla ' + bad + ' z ' + ids.length + ' skrzynek.');
            window.location.reload();
        });
    });
})();

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
