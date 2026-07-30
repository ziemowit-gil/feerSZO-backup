<?php
/**
 * panel/includes/pv_extra_sections.php — Sekcje wspólne pulpitu: „Moje
 * wydarzenia" + „Rezerwacje zasobów".
 *
 * Wydzielone z panel/index.php, aby:
 *  - u WOLONTARIUSZA renderować je w zakładce „Narzędzia" (w kontekście .pvtz),
 *  - u EDYTORA/ADMINA renderować w dashboardzie jak dotychczas.
 *
 * Wymaga w zasięgu: $user, APP_URL, h(), module_enabled(), db_all(),
 * res_* / ev_* (ładowane lokalnie). Markup Bootstrap — pod .pvtz karty są
 * zaokrąglane do estetyki tz regułą `.pvtz .card`.
 */
if (!defined('APP_URL')) { return; }
?>
<!-- ── Moje wydarzenia ────────────────────────────────────────────────────── -->
<?php if (module_enabled('events_enabled')):
    require_once dirname(__DIR__, 2) . '/includes/events.php';
    $uid_ev = (int)$user['id'];
    $_my_events = db_all(
        "SELECT e.id, e.slug, e.title, e.type, e.status, e.start_at, e.end_at,
                e.venue, e.meeting_url,
                r.ticket_code, r.status AS reg_status, r.checked_in_at
         FROM ev_registrations r
         JOIN ev_events e ON e.id = r.event_id
         WHERE r.email = ? AND r.status != 'cancelled'
         ORDER BY e.start_at DESC
         LIMIT 6",
        [$user['email'] ?? '']
    );
    $_managed_events = db_all(
        "SELECT e.id, e.title, e.type, e.status, e.start_at,
                (SELECT COUNT(*) FROM ev_registrations rr WHERE rr.event_id=e.id AND rr.status='confirmed') AS reg_count
         FROM ev_roles er
         JOIN ev_events e ON e.id = er.event_id
         WHERE er.user_id = ? AND e.status IN ('published','draft')
         ORDER BY e.start_at DESC
         LIMIT 4",
        [$uid_ev]
    );
    if ($_my_events || $_managed_events):
?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2 px-3">
    <i class="bi bi-calendar-event-fill" style="color:#7c3aed" aria-hidden="true"></i>
    <h2 class="h6 fw-bold mb-0 flex-grow-1">Moje wydarzenia</h2>
    <a href="<?= APP_URL ?>/events/index.php" class="btn btn-sm btn-outline-secondary py-0">
      <i class="bi bi-arrow-right me-1"></i>Wszystkie
    </a>
  </div>
  <div class="card-body p-3">
    <?php if ($_managed_events): ?>
    <p class="text-muted small fw-semibold mb-2 text-uppercase" style="font-size:.68rem;letter-spacing:.07em">Zarządzam</p>
    <div class="row g-2 mb-3">
      <?php foreach ($_managed_events as $mev): ?>
      <div class="col-12 col-sm-6">
        <a href="<?= APP_URL ?>/events/manage.php?id=<?= $mev['id'] ?>"
           class="d-flex align-items-center gap-2 p-2 rounded border text-decoration-none"
           style="background:#faf5ff;border-color:#ede9fe!important">
          <span style="width:32px;height:32px;border-radius:8px;background:#7c3aed;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi bi-<?= $mev['type']==='webinar' ? 'camera-video' : 'geo-alt' ?> text-white" style="font-size:.85rem"></i>
          </span>
          <div style="min-width:0">
            <div class="fw-semibold text-dark text-truncate" style="font-size:.85rem"><?= h($mev['title']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= $mev['start_at'] ? date('d.m.Y', strtotime($mev['start_at'])) : '—' ?>
              · <span class="badge" style="font-size:.65rem;background:<?= $mev['status']==='published'?'#16a34a':($mev['status']==='draft'?'#64748b':'#dc2626') ?>"><?= $mev['reg_count'] ?> os.</span>
            </div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($_my_events): ?>
    <p class="text-muted small fw-semibold mb-2 text-uppercase" style="font-size:.68rem;letter-spacing:.07em">Moje rejestracje</p>
    <div class="list-group list-group-flush" style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0">
      <?php foreach ($_my_events as $ev):
        $is_upcoming = $ev['start_at'] && strtotime($ev['start_at']) > time();
        $checked_in  = !empty($ev['checked_in_at']);
      ?>
      <div class="list-group-item list-group-item-action px-3 py-2 d-flex align-items-center gap-3">
        <span style="width:28px;height:28px;border-radius:6px;background:<?= $is_upcoming?'#7c3aed':'#94a3b8' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="bi bi-<?= $ev['type']==='webinar'?'camera-video':'geo-alt' ?> text-white" style="font-size:.75rem"></i>
        </span>
        <div class="flex-grow-1" style="min-width:0">
          <div class="fw-semibold text-dark text-truncate" style="font-size:.85rem"><?= h($ev['title']) ?></div>
          <div class="text-muted" style="font-size:.75rem">
            <?= $ev['start_at'] ? date('d.m.Y H:i', strtotime($ev['start_at'])) : '—' ?>
            <?php if ($ev['type']==='stationary' && $ev['venue']): ?>· <?= h($ev['venue']) ?><?php endif; ?>
          </div>
        </div>
        <div class="text-end flex-shrink-0">
          <?php if ($checked_in): ?>
            <span class="badge bg-success" style="font-size:.7rem"><i class="bi bi-check2-circle me-1"></i>Check-in</span>
          <?php elseif ($is_upcoming): ?>
            <code class="text-purple fw-bold" style="font-size:.78rem;color:#7c3aed"><?= h($ev['ticket_code']) ?></code>
          <?php else: ?>
            <span class="badge bg-secondary" style="font-size:.7rem">Zakończone</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; /* _my_events || _managed_events */ ?>
<?php endif; /* events_enabled */ ?>

<!-- ── Rezerwacje zasobów ─────────────────────────────────────────────────── -->
<?php
try {
    require_once dirname(__DIR__, 2) . '/includes/resources.php';
    resources_migrate();
    $my_reservations = res_reservations_for_user((int)$user['id']);
    $my_res_active = array_filter($my_reservations, fn($r) => !in_array($r['status'], ['odmowa','anulowana']));
    $my_res_pending = array_filter($my_reservations, fn($r) => in_array($r['status'], ['zlozony','pending_admin','pending_dysponent','oczekuje_dokumenty']));
    $res_enabled = true;
} catch (\Throwable $e) { $res_enabled = false; }
?>
<?php if ($res_enabled): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <div class="d-flex align-items-center mb-3">
      <h2 class="h6 mb-0 fw-bold"><i class="bi bi-calendar-check-fill text-primary me-2"></i>Rezerwacje zasobów</h2>
      <div class="ms-auto d-flex gap-2">
        <?php if ($my_res_pending): ?>
        <span class="badge bg-warning text-dark"><?= count($my_res_pending) ?> oczekuje</span>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/resources/" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-plus-lg me-1"></i>Zarezerwuj zasób
        </a>
        <a href="<?= APP_URL ?>/resources/my.php" class="btn btn-sm btn-outline-secondary">
          Moje rezerwacje
        </a>
      </div>
    </div>
    <?php if ($my_res_active): ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.84rem">
        <thead class="table-light">
          <tr><th>Zasób</th><th>Termin</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($my_res_active, 0, 5) as $rr): ?>
          <tr>
            <td>
              <i class="bi <?= h($rr['cat_icon']??'bi-box') ?>" style="color:<?= h($rr['cat_color']??'#666') ?>"></i>
              <?= h($rr['res_name']) ?>
            </td>
            <td class="text-nowrap"><?= h($rr['date_from']) ?><?= $rr['date_to']!==$rr['date_from']?' – '.h($rr['date_to']):'' ?></td>
            <td><?= res_status_badge($rr['status']) ?></td>
            <td><a href="<?= APP_URL ?>/resources/view.php?id=<?= (int)$rr['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2" aria-label="Podgląd rezerwacji"><i class="bi bi-eye"></i></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p class="text-muted small mb-0">Brak aktywnych rezerwacji. <a href="<?= APP_URL ?>/resources/">Przeglądaj dostępne zasoby</a>.</p>
    <?php endif; ?>
  </div>
</div>
<style>.res-status-badge{display:inline-block;padding:.18em .5em;border-radius:6px;font-size:.73rem;font-weight:600;}</style>
<?php endif; ?>
