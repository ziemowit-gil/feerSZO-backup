<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/events.php';

require_module_enabled('events_enabled', 'Moduł wydarzeń');

$event_id = (int)($_GET['id'] ?? 0);
if (!$event_id) { flash_set('error', 'Brak ID.'); header('Location: ' . APP_URL . '/events/index.php'); exit; }

// Token auth (no login needed)
$token_raw = trim($_GET['token'] ?? '');
if ($token_raw) {
    auth_start();
    $_SESSION['ev_checkin_token'] = $token_raw;
    // Validate token
    $tok = db_one(
        "SELECT id FROM ev_checkin_tokens WHERE token=? AND event_id=? AND (expires_at IS NULL OR expires_at > datetime('now','localtime'))",
        [$token_raw, $event_id]
    );
    if (!$tok) {
        session_destroy();
        flash_set('error', 'Token check-in jest nieważny lub wygasł.');
        header('Location: ' . APP_URL . '/events/index.php');
        exit;
    }
} else {
    require_login();
    ev_require_role($event_id, ['admin', 'volunteer', 'checkin']);
}

$event = db_one("SELECT * FROM ev_events WHERE id=?", [$event_id]);
if (!$event) { flash_set('error', 'Wydarzenie nie istnieje.'); header('Location: ' . APP_URL . '/events/index.php'); exit; }

$PAGE_TITLE = 'Check-in: ' . ($event['title'] ?? '');
$EV_ID = $event_id;

// Recent check-ins
$recent = [];
try {
    $recent = db_all(
        "SELECT ticket_code, first_name, last_name, checked_in_at FROM ev_registrations
         WHERE event_id=? AND checked_in_at IS NOT NULL ORDER BY checked_in_at DESC LIMIT 20",
        [$event_id]
    );
} catch (\Throwable $e) {}

// Stats
$checked_count = 0;
$total_confirmed = 0;
try {
    $checked_count   = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations WHERE event_id=? AND checked_in_at IS NOT NULL", [$event_id])['n'] ?? 0);
    $total_confirmed = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations WHERE event_id=? AND status='confirmed'", [$event_id])['n'] ?? 0);
} catch (\Throwable $e) {}

include dirname(__DIR__) . '/events/includes/header_events.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <a href="<?= APP_URL ?>/events/manage.php?id=<?= $event_id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0 fw-bold"><i class="bi bi-qr-code-scan me-2" style="color:var(--ev-purple)"></i>Check-in: <?= h($event['title']) ?></h4>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div style="font-size:2rem;font-weight:700;color:var(--ev-purple)"><?= $checked_count ?></div>
            <div class="text-muted small">Odprawione</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div style="font-size:2rem;font-weight:700;color:#2563eb"><?= $total_confirmed ?></div>
            <div class="text-muted small">Zarejestrowani</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div style="font-size:2rem;font-weight:700;color:#059669"><?= $total_confirmed > 0 ? round($checked_count/$total_confirmed*100) : 0 ?>%</div>
            <div class="text-muted small">Obecność</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div style="font-size:2rem;font-weight:700;color:#d97706"><?= max(0, $total_confirmed - $checked_count) ?></div>
            <div class="text-muted small">Oczekuje</div>
        </div>
    </div>
</div>

<!-- Scanner input -->
<div class="card shadow-sm border-0 mb-4" style="max-width:600px;margin:0 auto">
    <div class="card-body">
        <label for="checkin-input" class="form-label fw-bold text-center w-100 mb-3" style="font-size:1.1rem">
            <i class="bi bi-upc-scan me-2" style="color:var(--ev-purple)"></i>Zeskanuj lub wpisz kod biletu
        </label>
        <div class="input-group input-group-lg">
            <input type="text" class="form-control text-center fw-bold" id="checkin-input"
                   placeholder="np. A1B2C3" autocomplete="off" autocapitalize="characters" autofocus>
            <button class="btn btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)"
                    id="checkin-btn" type="button" onclick="doCheckin()">
                <i class="bi bi-check-lg"></i>
            </button>
        </div>
        <div id="checkin-result" class="mt-3" style="min-height:60px;display:none"></div>
    </div>
</div>

<!-- Recent check-ins -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-clock-history me-2" style="color:var(--ev-purple)"></i>Ostatnie odprawienia
    </div>
    <div class="card-body p-0" id="recent-list">
        <?php if (empty($recent)): ?>
        <div class="text-center text-muted py-4">Brak odprawień.</div>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($recent as $r): ?>
            <div class="list-group-item d-flex align-items-center gap-3 px-4 py-2">
                <span class="text-success"><i class="bi bi-check-circle-fill" style="font-size:1.2rem"></i></span>
                <div class="flex-grow-1">
                    <span class="fw-semibold"><?= h($r['first_name'] . ' ' . $r['last_name']) ?></span>
                    <code class="ms-2 text-muted small"><?= h($r['ticket_code']) ?></code>
                </div>
                <span class="text-muted small"><?= $r['checked_in_at'] ? date('H:i:s', strtotime($r['checked_in_at'])) : '' ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
const EV_ID = <?= $event_id ?>;
const CSRF  = <?= json_encode(csrf_token()) ?>;

const input  = document.getElementById('checkin-input');
const result = document.getElementById('checkin-result');

input.addEventListener('keydown', function(e){
    if(e.key === 'Enter') { e.preventDefault(); doCheckin(); }
});

async function doCheckin(){
    const code = input.value.trim().toUpperCase();
    if(!code) return;
    result.style.display = 'none';

    try {
        const r = await fetch('<?= APP_URL ?>/events/api/checkin.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({_csrf:CSRF, event_id:EV_ID, ticket_code:code})
        });
        const data = await r.json();

        if(data.ok){
            const reg = data.data;
            result.innerHTML = `<div class="alert alert-success d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-check-circle-fill fs-4"></i>
                <div><strong>${escHtml(reg.first_name+' '+reg.last_name)}</strong><br>
                <small>${escHtml(reg.email)}</small></div>
            </div>`;
            result.style.display = '';
            prependRecent(reg, code);
        } else {
            result.innerHTML = `<div class="alert alert-danger d-flex align-items-center gap-2 mb-0">
                <i class="bi bi-x-circle-fill fs-4"></i>
                <strong>${escHtml(data.error||'Błąd check-in')}</strong>
            </div>`;
            result.style.display = '';
        }
    } catch(e){
        result.innerHTML = '<div class="alert alert-danger">Błąd połączenia.</div>';
        result.style.display = '';
    }

    input.value = '';
    input.focus();
    setTimeout(()=>{ result.style.display='none'; }, 5000);
}

function prependRecent(reg, code){
    const list = document.getElementById('recent-list');
    const now = new Date();
    const time = now.getHours().toString().padStart(2,'0')+':'+now.getMinutes().toString().padStart(2,'0')+':'+now.getSeconds().toString().padStart(2,'0');
    const html = `<div class="list-group list-group-flush">
        <div class="list-group-item d-flex align-items-center gap-3 px-4 py-2">
            <span class="text-success"><i class="bi bi-check-circle-fill" style="font-size:1.2rem"></i></span>
            <div class="flex-grow-1">
                <span class="fw-semibold">${escHtml(reg.first_name+' '+reg.last_name)}</span>
                <code class="ms-2 text-muted small">${escHtml(code)}</code>
            </div>
            <span class="text-muted small">${time}</span>
        </div>
        ${list.innerHTML.replace('<div class="text-center text-muted py-4">Brak odprawień.</div>','')}
    </div>`;
    list.innerHTML = html;
}

function escHtml(s){ const d=document.createElement('div');d.textContent=s;return d.innerHTML; }
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
