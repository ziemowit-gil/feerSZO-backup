<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/events.php';

require_login();
require_module_enabled('events_enabled', 'Moduł wydarzeń');

$PAGE_TITLE = 'Dashboard';
$uid = (int)(current_user()['id'] ?? 0);
$admin = is_admin();

// KPIs
$total_events   = 0;
$upcoming_count = 0;
$total_regs     = 0;
$today_checkins = 0;

try {
    if ($admin) {
        $total_events   = (int)(db_one("SELECT COUNT(*) AS n FROM ev_events")['n'] ?? 0);
        $upcoming_count = (int)(db_one("SELECT COUNT(*) AS n FROM ev_events WHERE status='published' AND start_at >= datetime('now','localtime')")['n'] ?? 0);
        $total_regs     = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations WHERE status='confirmed'")['n'] ?? 0);
        $today_checkins = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations WHERE checked_in_at IS NOT NULL AND DATE(checked_in_at)=DATE('now','localtime')")['n'] ?? 0);
    } else {
        $total_events   = (int)(db_one("SELECT COUNT(DISTINCT er.event_id) AS n FROM ev_roles er WHERE er.user_id=?", [$uid])['n'] ?? 0);
        $upcoming_count = (int)(db_one("SELECT COUNT(DISTINCT er.event_id) AS n FROM ev_roles er JOIN ev_events e ON e.id=er.event_id WHERE er.user_id=? AND e.status='published' AND e.start_at>=datetime('now','localtime')", [$uid])['n'] ?? 0);
        $total_regs     = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations r JOIN ev_roles er ON er.event_id=r.event_id WHERE er.user_id=? AND r.status='confirmed'", [$uid])['n'] ?? 0);
        $today_checkins = (int)(db_one("SELECT COUNT(*) AS n FROM ev_registrations r JOIN ev_roles er ON er.event_id=r.event_id WHERE er.user_id=? AND r.checked_in_at IS NOT NULL AND DATE(r.checked_in_at)=DATE('now','localtime')", [$uid])['n'] ?? 0);
    }
} catch (\Throwable $e) {}

// Upcoming events
$upcoming_events = [];
try {
    if ($admin) {
        $upcoming_events = db_all(
            "SELECT e.*, (SELECT COUNT(*) FROM ev_registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS reg_count
             FROM ev_events e WHERE e.status='published' AND e.start_at>=datetime('now','localtime')
             ORDER BY e.start_at ASC LIMIT 5"
        );
    } else {
        $upcoming_events = db_all(
            "SELECT e.*, (SELECT COUNT(*) FROM ev_registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS reg_count
             FROM ev_events e JOIN ev_roles er ON er.event_id=e.id
             WHERE er.user_id=? AND e.status='published' AND e.start_at>=datetime('now','localtime')
             ORDER BY e.start_at ASC LIMIT 5",
            [$uid]
        );
    }
} catch (\Throwable $e) {}

include dirname(__DIR__) . '/events/includes/header_events.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2 text-purple" style="color:var(--ev-purple)"></i>Dashboard wydarzeń</h4>
        <p class="text-muted small mb-0">Przegląd aktywności i nadchodzących wydarzeń</p>
    </div>
    <?php if ($admin): ?>
    <a href="<?= APP_URL ?>/events/add.php" class="btn btn-sm btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
        <i class="bi bi-plus-lg me-1"></i>Nowe wydarzenie
    </a>
    <?php endif; ?>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body text-center py-3">
                <div style="font-size:1.8rem;font-weight:700;color:var(--ev-purple)"><?= $total_events ?></div>
                <div class="text-muted small">Wszystkie wydarzenia</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body text-center py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#059669"><?= $upcoming_count ?></div>
                <div class="text-muted small">Nadchodzące</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body text-center py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#2563eb"><?= $total_regs ?></div>
                <div class="text-muted small">Łącznie rejestracji</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body text-center py-3">
                <div style="font-size:1.8rem;font-weight:700;color:#d97706"><?= $today_checkins ?></div>
                <div class="text-muted small">Check-in dziś</div>
            </div>
        </div>
    </div>
</div>

<!-- Upcoming events -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-calendar-check me-2" style="color:var(--ev-purple)"></i>Nadchodzące wydarzenia</span>
        <a href="<?= APP_URL ?>/events/index.php?filter=upcoming" class="btn btn-sm btn-outline-secondary">Zobacz wszystkie</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($upcoming_events)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-calendar-x" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mt-2 mb-0">Brak nadchodzących wydarzeń</p>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($upcoming_events as $ev):
                $type_info   = EV_TYPE[$ev['type']] ?? ['label' => $ev['type'], 'icon' => 'bi-calendar'];
                $status_info = EV_STATUS[$ev['status']] ?? ['label' => $ev['status'], 'class' => 'secondary'];
                $start = $ev['start_at'] ? date('d.m.Y H:i', strtotime($ev['start_at'])) : '—';
                $cap   = $ev['capacity'] ? h($ev['reg_count']) . '/' . h($ev['capacity']) : h($ev['reg_count']);
            ?>
            <div class="list-group-item list-group-item-action d-flex align-items-center gap-3 px-4 py-3">
                <div class="flex-shrink-0 rounded-2 d-flex align-items-center justify-content-center"
                     style="width:44px;height:44px;background:var(--ev-purple-bg);color:var(--ev-purple)">
                    <i class="bi <?= h($type_info['icon']) ?>" style="font-size:1.2rem"></i>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate"><?= h($ev['title']) ?></div>
                    <div class="text-muted small">
                        <i class="bi bi-clock me-1"></i><?= $start ?>
                        <?php if ($ev['venue']): ?> · <i class="bi bi-geo-alt me-1"></i><?= h($ev['venue']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <span class="badge bg-<?= h($status_info['class']) ?>"><?= h($status_info['label']) ?></span>
                    <span class="badge bg-light text-dark border"><i class="bi bi-people me-1"></i><?= $cap ?></span>
                    <a href="<?= APP_URL ?>/events/manage.php?id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
