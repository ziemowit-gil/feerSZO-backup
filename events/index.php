<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/events.php';

require_login();
require_module_enabled('events_enabled', 'Moduł wydarzeń');

$PAGE_TITLE = 'Wszystkie wydarzenia';
$uid   = (int)(current_user()['id'] ?? 0);
$admin = is_admin();
$filter = $_GET['filter'] ?? 'all';

// Build query
$params = [];
$where  = [];

if (!$admin) {
    $where[]  = "e.id IN (SELECT event_id FROM ev_roles WHERE user_id=?)";
    $params[] = $uid;
}

if ($filter === 'upcoming') {
    $where[] = "e.status='published' AND e.start_at >= datetime('now','localtime')";
} elseif ($filter === 'archive') {
    $where[] = "e.status IN ('archived','cancelled')";
}

$sql  = "SELECT e.*, (SELECT COUNT(*) FROM ev_registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS reg_count FROM ev_events e";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY e.start_at DESC";

$events = [];
try { $events = db_all($sql, $params); } catch (\Throwable $e) {}

include dirname(__DIR__) . '/events/includes/header_events.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-calendar3 me-2" style="color:var(--ev-purple)"></i>Wszystkie wydarzenia</h4>
    <?php if ($admin): ?>
    <a href="<?= APP_URL ?>/events/add.php" class="btn btn-sm btn-primary" style="background:var(--ev-purple);border-color:var(--ev-purple)">
        <i class="bi bi-plus-lg me-1"></i>Nowe wydarzenie
    </a>
    <?php endif; ?>
</div>

<!-- Filter tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $filter==='all'?'active':'' ?>" href="?filter=all">Wszystkie</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $filter==='upcoming'?'active':'' ?>" href="?filter=upcoming">Nadchodzące</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $filter==='archive'?'active':'' ?>" href="?filter=archive">Archiwum</a>
    </li>
</ul>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <?php if (empty($events)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-calendar-x" style="font-size:2.5rem;opacity:.3"></i>
            <p class="mt-2 mb-0">Brak wydarzeń</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tytuł</th>
                        <th>Typ</th>
                        <th>Status</th>
                        <th>Data</th>
                        <th>Rejestracje</th>
                        <th class="text-end">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($events as $ev):
                    $type_info   = EV_TYPE[$ev['type']] ?? ['label' => $ev['type'], 'icon' => 'bi-calendar'];
                    $status_info = EV_STATUS[$ev['status']] ?? ['label' => $ev['status'], 'class' => 'secondary'];
                    $start = $ev['start_at'] ? date('d.m.Y H:i', strtotime($ev['start_at'])) : '—';
                    $cap   = $ev['capacity'] ? h($ev['reg_count']) . '/' . h($ev['capacity']) : h($ev['reg_count']);
                ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/events/manage.php?id=<?= $ev['id'] ?>" class="fw-semibold text-decoration-none text-dark">
                            <?= h($ev['title']) ?>
                        </a>
                        <?php if ($ev['venue']): ?>
                        <div class="text-muted small"><i class="bi bi-geo-alt"></i> <?= h($ev['venue']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border">
                            <i class="bi <?= h($type_info['icon']) ?> me-1"></i><?= h($type_info['label']) ?>
                        </span>
                    </td>
                    <td><span class="badge bg-<?= h($status_info['class']) ?>"><?= h($status_info['label']) ?></span></td>
                    <td class="text-nowrap small"><?= $start ?></td>
                    <td>
                        <span class="badge bg-light text-dark border">
                            <i class="bi bi-people me-1"></i><?= $cap ?>
                        </span>
                    </td>
                    <td class="text-end text-nowrap">
                        <a href="<?= APP_URL ?>/events/manage.php?id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Zarządzaj">
                            <i class="bi bi-people"></i>
                        </a>
                        <?php if ($admin || ev_can($ev['id'], 'admin')): ?>
                        <a href="<?= APP_URL ?>/events/edit.php?id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edytuj">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php endif; ?>
                        <?php if ($ev['status'] === 'published'): ?>
                        <a href="<?= APP_URL ?>/events/public/register.php?slug=<?= urlencode($ev['slug']) ?>"
                           target="_blank" class="btn btn-sm btn-outline-success" title="Formularz rejestracji">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
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

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
