<?php
require_once dirname(dirname(dirname(__FILE__))) . '/config.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__FILE__))) . '/includes/events.php';

require_login();
require_role('admin');
require_module_enabled('events_enabled', 'Moduł wydarzeń');

$PAGE_TITLE = 'Role i dostęp';
$EV_ID = (int)($_GET['id'] ?? 0);

// If a specific event is given, show roles for that event only
$event = null;
if ($EV_ID) {
    $event = db_one("SELECT * FROM ev_events WHERE id=?", [$EV_ID]);
}

// Load all events with role counts
$events_with_roles = [];
try {
    $events_with_roles = db_all(
        "SELECT e.id, e.title, e.status,
         (SELECT COUNT(*) FROM ev_roles WHERE event_id=e.id) AS role_count
         FROM ev_events e ORDER BY e.start_at DESC"
    );
} catch (\Throwable $e) {}

// Load roles for selected event
$roles = [];
if ($EV_ID) {
    try {
        $roles = db_all(
            "SELECT er.id, er.role, er.added_at, u.first_name, u.last_name, u.email
             FROM ev_roles er JOIN users u ON u.id=er.user_id
             WHERE er.event_id=? ORDER BY er.role, u.first_name",
            [$EV_ID]
        );
    } catch (\Throwable $e) {}
}

// All users for adding
$all_users = [];
try { $all_users = db_all("SELECT id, first_name, last_name, email FROM users ORDER BY first_name, last_name"); } catch (\Throwable $e) {}

include dirname(dirname(dirname(__FILE__))) . '/events/includes/header_events.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <h4 class="mb-0 fw-bold"><i class="bi bi-people-fill me-2" style="color:var(--ev-purple)"></i>Role i dostęp</h4>
</div>

<div class="row g-3">
    <!-- Events list -->
    <div class="col-md-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-calendar3 me-2" style="color:var(--ev-purple)"></i>Wybierz wydarzenie
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($events_with_roles as $ev):
                        $si = EV_STATUS[$ev['status']] ?? ['class' => 'secondary', 'label' => $ev['status']];
                    ?>
                    <a href="?id=<?= $ev['id'] ?>"
                       class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2 px-3 <?= $EV_ID === (int)$ev['id'] ? 'active' : '' ?>">
                        <div class="flex-grow-1 min-w-0">
                            <div class="text-truncate small fw-semibold"><?= h($ev['title']) ?></div>
                        </div>
                        <span class="badge bg-<?= h($si['class']) ?>" style="font-size:.65rem"><?= h($si['label']) ?></span>
                        <?php if ($ev['role_count'] > 0): ?>
                        <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= $ev['role_count'] ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                    <?php if (empty($events_with_roles)): ?>
                    <div class="text-muted text-center py-3 small">Brak wydarzeń.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Roles for selected event -->
    <div class="col-md-8">
        <?php if ($event): ?>
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex align-items-center justify-content-between">
                <span class="fw-semibold"><?= h($event['title']) ?></span>
                <button class="btn btn-sm btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)"
                        onclick="showAddRole()">
                    <i class="bi bi-person-plus me-1"></i>Dodaj rolę
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($roles)): ?>
                <div class="text-center text-muted py-4">Brak przypisanych ról.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Użytkownik</th>
                                <th>Email</th>
                                <th>Rola</th>
                                <th>Dodano</th>
                                <th class="text-end">Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($roles as $r):
                            $rc = match($r['role']) { 'admin' => 'danger', 'volunteer' => 'primary', 'checkin' => 'success', default => 'secondary' };
                        ?>
                        <tr>
                            <td class="fw-semibold"><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
                            <td class="text-muted small"><?= h($r['email']) ?></td>
                            <td><span class="badge bg-<?= $rc ?>"><?= h($r['role']) ?></span></td>
                            <td class="text-muted small"><?= $r['added_at'] ? date('d.m.Y', strtotime($r['added_at'])) : '—' ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger" onclick="removeRole(<?= $r['id'] ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-arrow-left" style="font-size:2rem;opacity:.3"></i>
                <p class="mt-2 mb-0">Wybierz wydarzenie z listy</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1" aria-modal="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Przypisz rolę</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Użytkownik</label>
                    <select class="form-select" id="role_user_id">
                        <?php foreach ($all_users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= h($u['first_name'] . ' ' . $u['last_name'] . ' <' . $u['email'] . '>') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Rola</label>
                    <select class="form-select" id="role_role">
                        <option value="volunteer">Wolontariusz</option>
                        <option value="checkin">Check-in</option>
                        <option value="admin">Admin wydarzenia</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)"
                        onclick="addRole()">Przypisz</button>
            </div>
        </div>
    </div>
</div>

<script>
const EV_ID = <?= $EV_ID ?: 0 ?>;
const CSRF  = <?= json_encode(csrf_token()) ?>;

function showAddRole(){ if(!EV_ID){alert('Wybierz najpierw wydarzenie.');return;} new bootstrap.Modal(document.getElementById('addRoleModal')).show(); }

async function addRole(){
    const d = await fetch('<?= APP_URL ?>/events/api/roles.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            _csrf:CSRF, action:'add', event_id:EV_ID,
            user_id:parseInt(document.getElementById('role_user_id').value),
            role:document.getElementById('role_role').value,
        })
    }).then(r=>r.json());
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}

async function removeRole(id){
    if(!confirm('Usunąć rolę?')) return;
    const d = await fetch('<?= APP_URL ?>/events/api/roles.php',{
        method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({_csrf:CSRF,action:'remove',id})
    }).then(r=>r.json());
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}
</script>

<?php include dirname(dirname(dirname(__FILE__))) . '/includes/footer.php'; ?>
