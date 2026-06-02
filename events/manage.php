<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/events.php';

require_login();
require_module_enabled('events_enabled', 'Moduł wydarzeń');

foreach ([
    "ALTER TABLE ev_events ADD COLUMN rodo_clause    TEXT",
    "ALTER TABLE ev_events ADD COLUMN notify_new_reg INTEGER NOT NULL DEFAULT 1",
    "ALTER TABLE ev_events ADD COLUMN notify_email   TEXT",
    "ALTER TABLE ev_events ADD COLUMN crm_auto_sync  INTEGER NOT NULL DEFAULT 1",
] as $_sql) { try { db()->exec($_sql); } catch (\Throwable $e) {} }

$event_id = (int)($_GET['id'] ?? 0);
if (!$event_id) { flash_set('error', 'Brak ID.'); header('Location: ' . APP_URL . '/events/index.php'); exit; }

$event = db_one("SELECT * FROM ev_events WHERE id=?", [$event_id]);
if (!$event) { flash_set('error', 'Wydarzenie nie istnieje.'); header('Location: ' . APP_URL . '/events/index.php'); exit; }

ev_require_role($event_id, ['admin', 'volunteer']);

$my_role = ev_role($event_id);
$PAGE_TITLE = 'Zarządzaj: ' . ($event['title'] ?? '');
$EV_ID = $event_id;
$tab = $_GET['tab'] ?? 'participants';
$q   = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';

// Participants
$reg_params = [$event_id];
$reg_where  = ["r.event_id=?"];
if ($q) {
    $reg_where[] = "(r.first_name LIKE ? OR r.last_name LIKE ? OR r.email LIKE ? OR r.ticket_code LIKE ?)";
    $like = '%' . $q . '%';
    array_push($reg_params, $like, $like, $like, $like);
}
if ($status_filter) {
    $reg_where[] = "r.status=?";
    $reg_params[] = $status_filter;
}
$registrations = [];
try {
    $registrations = db_all(
        "SELECT r.* FROM ev_registrations r WHERE " . implode(' AND ', $reg_where) . " ORDER BY r.created_at DESC",
        $reg_params
    );
} catch (\Throwable $e) {}

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $tab === 'participants') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uczestnicy_' . $event['slug'] . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    fputcsv($out, ['Bilet', 'Imię', 'Nazwisko', 'Email', 'Telefon', 'Status', 'Rejestracja', 'Check-in'], ';');
    foreach ($registrations as $r) {
        fputcsv($out, [
            $r['ticket_code'], $r['first_name'], $r['last_name'],
            $r['email'], $r['phone'] ?? '',
            $r['status'], $r['created_at'], $r['checked_in_at'] ?? '',
        ], ';');
    }
    fclose($out);
    exit;
}

// Volunteers
$roles = [];
try { $roles = db_all(
    "SELECT er.*, u.first_name, u.last_name, u.email FROM ev_roles er
     JOIN users u ON u.id=er.user_id WHERE er.event_id=? ORDER BY er.added_at DESC",
    [$event_id]
); } catch (\Throwable $e) {}

// Checkin tokens
$tokens = [];
try { $tokens = db_all(
    "SELECT t.*, u.first_name, u.last_name FROM ev_checkin_tokens t
     LEFT JOIN users u ON u.id=t.created_by WHERE t.event_id=? ORDER BY t.created_at DESC",
    [$event_id]
); } catch (\Throwable $e) {}

// All users for role add
$all_users = [];
try { $all_users = db_all("SELECT id, first_name, last_name, email FROM users ORDER BY first_name, last_name"); } catch (\Throwable $e) {}

$type_info   = EV_TYPE[$event['type']] ?? ['label' => $event['type'], 'icon' => 'bi-calendar'];
$status_info = EV_STATUS[$event['status']] ?? ['label' => $event['status'], 'class' => 'secondary'];

include dirname(__DIR__) . '/events/includes/header_events.php';
?>

<!-- Event header -->
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h4 class="mb-0 fw-bold text-truncate"><?= h($event['title']) ?></h4>
                    <span class="badge bg-<?= h($status_info['class']) ?>"><?= h($status_info['label']) ?></span>
                    <span class="badge bg-light text-dark border">
                        <i class="bi <?= h($type_info['icon']) ?> me-1"></i><?= h($type_info['label']) ?>
                    </span>
                </div>
                <div class="text-muted small mt-1">
                    <?php if ($event['start_at']): ?>
                    <i class="bi bi-clock me-1"></i><?= date('d.m.Y H:i', strtotime($event['start_at'])) ?>
                    <?php endif; ?>
                    <?php if ($event['venue']): ?>
                    · <i class="bi bi-geo-alt me-1"></i><?= h($event['venue']) ?>
                    <?php elseif ($event['meeting_url']): ?>
                    · <a href="<?= h($event['meeting_url']) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-camera-video me-1"></i>Link do spotkania
                    </a>
                    <?php endif; ?>
                </div>
                <?php if ($event['is_public'] && $event['slug']): ?>
                <?php $pub_url = APP_URL . '/events/public/register.php?slug=' . urlencode($event['slug']); ?>
                <div class="d-flex align-items-center gap-2 mt-2 px-2 py-1 rounded" style="background:#f5f3ff;border:1px solid #ede9fe;max-width:fit-content">
                    <i class="bi bi-link-45deg" style="color:#7c3aed"></i>
                    <span class="text-muted small">Publiczna rejestracja:</span>
                    <a href="<?= h($pub_url) ?>" target="_blank" class="small fw-semibold text-break" style="color:#7c3aed"><?= h($pub_url) ?></a>
                    <button type="button" class="btn btn-sm p-0 ms-1" style="color:#7c3aed;background:none;border:none;line-height:1"
                            onclick="navigator.clipboard.writeText(<?= json_encode($pub_url) ?>);this.innerHTML='<i class=\'bi bi-check2\'></i>';setTimeout(()=>this.innerHTML='<i class=\'bi bi-clipboard\'></i>',1500)"
                            title="Kopiuj"><i class="bi bi-clipboard"></i></button>
                </div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <?php if ($my_role === 'admin'): ?>
                <a href="<?= APP_URL ?>/events/edit.php?id=<?= $event_id ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i>Edytuj
                </a>
                <a href="<?= APP_URL ?>/events/public/preview.php?id=<?= $event_id ?>" target="_blank"
                   class="btn btn-sm" style="background:#f5f3ff;color:#7c3aed;border:1px solid #ddd6fe">
                    <i class="bi bi-eye me-1"></i>Podgląd
                </a>
                <?php if ($event['status'] === 'draft'): ?>
                <button class="btn btn-sm btn-success" onclick="changeStatus('published')">
                    <i class="bi bi-check-circle me-1"></i>Opublikuj
                </button>
                <?php elseif ($event['status'] === 'published'): ?>
                <button class="btn btn-sm btn-warning" onclick="changeStatus('cancelled')">
                    <i class="bi bi-x-circle me-1"></i>Odwołaj
                </button>
                <?php endif; ?>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/events/checkin.php?id=<?= $event_id ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-qr-code-scan me-1"></i>Check-in
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab==='participants'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=participants">
            <i class="bi bi-people me-1"></i>Uczestnicy
            <span class="badge bg-secondary ms-1"><?= count($registrations) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='checkin'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=checkin">
            <i class="bi bi-qr-code-scan me-1"></i>Check-in
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='volunteers'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=volunteers">
            <i class="bi bi-person-badge me-1"></i>Wolontariusze
            <span class="badge bg-secondary ms-1"><?= count($roles) ?></span>
        </a>
    </li>
    <?php if ($my_role === 'admin'): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='tokens'?'active':'' ?>" href="?id=<?= $event_id ?>&tab=tokens">
            <i class="bi bi-key me-1"></i>Tokeny check-in
        </a>
    </li>
    <?php endif; ?>
</ul>

<?php if ($tab === 'participants'): ?>
<!-- Participants -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex align-items-center gap-2 flex-wrap">
        <form class="d-flex gap-2 flex-grow-1" method="get">
            <input type="hidden" name="id"  value="<?= $event_id ?>">
            <input type="hidden" name="tab" value="participants">
            <input type="search" class="form-control form-control-sm" name="q" value="<?= h($q) ?>"
                   placeholder="Szukaj po imieniu, emailu, bilecie…">
            <select class="form-select form-select-sm" name="status" style="max-width:150px">
                <option value="">Wszystkie</option>
                <option value="confirmed"  <?= $status_filter==='confirmed'?'selected':'' ?>>Potwierdzone</option>
                <option value="cancelled"  <?= $status_filter==='cancelled'?'selected':'' ?>>Anulowane</option>
                <option value="waitlist"   <?= $status_filter==='waitlist' ?'selected':'' ?>>Lista oczekujących</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
        </form>
        <div class="d-flex gap-2">
            <?php if ($my_role === 'admin'): ?>
            <button class="btn btn-sm btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)"
                    onclick="showAddParticipant()">
                <i class="bi bi-person-plus me-1"></i>Dodaj
            </button>
            <?php endif; ?>
            <a href="?id=<?= $event_id ?>&tab=participants&export=csv<?= $q ? '&q='.urlencode($q) : '' ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-download me-1"></i>CSV
            </a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($registrations)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-person-x" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mt-2 mb-0">Brak uczestników</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Bilet</th>
                        <th>Uczestnik</th>
                        <th>Email / Tel</th>
                        <th>Status</th>
                        <th>Rejestracja</th>
                        <th>Check-in</th>
                        <?php if ($my_role === 'admin'): ?><th class="text-end">Akcje</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($registrations as $r):
                    $s_class = match($r['status']) {
                        'confirmed' => 'success', 'cancelled' => 'danger', 'waitlist' => 'warning', default => 'secondary'
                    };
                    $s_label = match($r['status']) {
                        'confirmed' => 'Potwierdzone', 'cancelled' => 'Anulowane', 'waitlist' => 'Oczekujące', default => h($r['status'])
                    };
                ?>
                <tr>
                    <td><code><?= h($r['ticket_code']) ?></code></td>
                    <td class="fw-semibold"><?= h($r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td>
                        <?= h($r['email']) ?>
                        <?php if ($r['phone']): ?><br><span class="text-muted"><?= h($r['phone']) ?></span><?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= $s_class ?>"><?= $s_label ?></span></td>
                    <td class="text-nowrap"><?= $r['created_at'] ? date('d.m.Y H:i', strtotime($r['created_at'])) : '—' ?></td>
                    <td>
                        <?php if ($r['checked_in_at']): ?>
                        <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i><?= date('H:i', strtotime($r['checked_in_at'])) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($my_role === 'admin'): ?>
                    <td class="text-end text-nowrap">
                        <?php if ($r['status'] === 'confirmed'): ?>
                        <button class="btn btn-sm btn-outline-danger" onclick="cancelReg(<?= $r['id'] ?>)">
                            <i class="bi bi-x"></i>
                        </button>
                        <?php elseif ($r['status'] === 'cancelled' || $r['status'] === 'waitlist'): ?>
                        <button class="btn btn-sm btn-outline-success" onclick="restoreReg(<?= $r['id'] ?>)">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'checkin'): ?>
<div class="text-center py-3">
    <a href="<?= APP_URL ?>/events/checkin.php?id=<?= $event_id ?>" class="btn btn-lg btn-primary"
       style="background:var(--ev-purple);border-color:var(--ev-purple)">
        <i class="bi bi-qr-code-scan me-2"></i>Otwórz panel check-in
    </a>
</div>

<?php elseif ($tab === 'volunteers'): ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="bi bi-person-badge me-2" style="color:var(--ev-purple)"></i>Zespół wolontariuszy</span>
        <?php if ($my_role === 'admin'): ?>
        <button class="btn btn-sm btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)"
                onclick="showAddRole()">
            <i class="bi bi-person-plus me-1"></i>Dodaj
        </button>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($roles)): ?>
        <div class="text-center text-muted py-4">Brak przypisanych ról.</div>
        <?php else: ?>
        <div class="list-group list-group-flush">
        <?php foreach ($roles as $r):
            $rc = match($r['role']) { 'admin' => 'danger', 'volunteer' => 'primary', 'checkin' => 'success', default => 'secondary' };
        ?>
        <div class="list-group-item d-flex align-items-center gap-3 px-4 py-2">
            <div class="flex-grow-1">
                <span class="fw-semibold"><?= h($r['first_name'] . ' ' . $r['last_name']) ?></span>
                <span class="text-muted small ms-2"><?= h($r['email']) ?></span>
            </div>
            <span class="badge bg-<?= $rc ?>"><?= h($r['role']) ?></span>
            <?php if ($my_role === 'admin'): ?>
            <button class="btn btn-sm btn-outline-danger" onclick="removeRole(<?= $r['id'] ?>)">
                <i class="bi bi-trash"></i>
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'tokens' && $my_role === 'admin'): ?>
<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="bi bi-key me-2" style="color:var(--ev-purple)"></i>Tokeny check-in (bez logowania)</span>
        <button class="btn btn-sm btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)"
                onclick="generateToken()">
            <i class="bi bi-plus-lg me-1"></i>Generuj token
        </button>
    </div>
    <div class="card-body p-0">
        <?php if (empty($tokens)): ?>
        <div class="text-center text-muted py-4">Brak tokenów.</div>
        <?php else: ?>
        <div class="list-group list-group-flush">
        <?php foreach ($tokens as $t): ?>
        <div class="list-group-item d-flex align-items-center gap-3 px-4 py-2">
            <code class="flex-grow-1 text-break"><?= h($t['token']) ?></code>
            <div class="text-muted small text-nowrap">
                <?= $t['expires_at'] ? 'do ' . date('d.m.Y H:i', strtotime($t['expires_at'])) : 'bezterminowy' ?>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" title="Kopiuj link"
                        onclick="copyToken('<?= APP_URL ?>/events/checkin.php?id=<?= $event_id ?>&token=<?= urlencode($t['token']) ?>')">
                    <i class="bi bi-clipboard"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="revokeToken(<?= $t['id'] ?>)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Add Participant Modal -->
<div class="modal fade" id="addParticipantModal" tabindex="-1" aria-labelledby="addParticipantLabel" aria-modal="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addParticipantLabel">Dodaj uczestnika ręcznie</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Imię <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="ap_first_name">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Nazwisko <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="ap_last_name">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" id="ap_email">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Telefon</label>
                    <input type="tel" class="form-control" id="ap_phone">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notatka</label>
                    <textarea class="form-control" id="ap_notes" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)"
                        onclick="addParticipant()">Dodaj uczestnika</button>
            </div>
        </div>
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
const EV_ID = <?= $event_id ?>;
const CSRF  = <?= json_encode(csrf_token()) ?>;

async function post(url, data){
    const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:CSRF,...data})});
    return r.json();
}

function showAddParticipant(){ new bootstrap.Modal(document.getElementById('addParticipantModal')).show(); }
function showAddRole(){ new bootstrap.Modal(document.getElementById('addRoleModal')).show(); }

async function addParticipant(){
    const d = await post('<?= APP_URL ?>/events/api/registration.php',{
        action:'add', event_id:EV_ID,
        first_name: document.getElementById('ap_first_name').value,
        last_name:  document.getElementById('ap_last_name').value,
        email:      document.getElementById('ap_email').value,
        phone:      document.getElementById('ap_phone').value,
        notes:      document.getElementById('ap_notes').value,
    });
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}

async function cancelReg(id){
    if(!confirm('Anulować rejestrację?')) return;
    const d = await post('<?= APP_URL ?>/events/api/registration.php',{action:'cancel',id});
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}

async function restoreReg(id){
    const d = await post('<?= APP_URL ?>/events/api/registration.php',{action:'restore',id});
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}

async function addRole(){
    const d = await post('<?= APP_URL ?>/events/api/roles.php',{
        action:'add', event_id:EV_ID,
        user_id: parseInt(document.getElementById('role_user_id').value),
        role:    document.getElementById('role_role').value,
    });
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}

async function removeRole(id){
    if(!confirm('Usunąć tę rolę?')) return;
    const d = await post('<?= APP_URL ?>/events/api/roles.php',{action:'remove',id});
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}

async function generateToken(){
    const expires = prompt('Data ważności (YYYY-MM-DD HH:MM) lub puste dla bezterminowego:');
    if(expires === null) return;
    const d = await post('<?= APP_URL ?>/events/api/checkin_tokens.php',{
        action:'generate', event_id:EV_ID,
        expires_at: expires.trim() || null,
    });
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}

async function revokeToken(id){
    if(!confirm('Unieważnić token?')) return;
    const d = await post('<?= APP_URL ?>/events/api/checkin_tokens.php',{action:'revoke',id});
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}

function copyToken(url){
    navigator.clipboard.writeText(url).then(()=>alert('Link skopiowany:\n'+url));
}

async function changeStatus(status){
    if(!confirm('Zmienić status na "'+status+'"?')) return;
    const d = await post('<?= APP_URL ?>/events/api/event.php',{action:'update_status',id:EV_ID,status});
    if(d.ok){ location.reload(); } else { alert(d.error||'Błąd.'); }
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
