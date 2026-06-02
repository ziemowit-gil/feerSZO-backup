<?php
/**
 * resources/view.php — Szczegóły rezerwacji + historia + akcje zatwierdzania.
 *
 * Widoczne dla:
 *  - wnioskodawcy (user_id)
 *  - admina/editora (panel zatwierdzania admin)
 *  - dysponenta zasobu (panel zatwierdzania dysponent)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/resources.php';

require_login();
ika_require();
resources_migrate();

$id          = (int)($_GET['id'] ?? 0);
$reservation = $id ? res_reservation_get($id) : null;
if (!$reservation) {
    flash_set('danger', 'Rezerwacja nie istnieje.');
    header('Location: ' . APP_URL . '/resources/my.php'); exit;
}

$user        = current_user();
$is_owner    = (int)$reservation['user_id'] === (int)$user['id'];
$is_admin    = is_admin() || can_write('resources');
$is_dysponent= (int)($reservation['dysponent_user_id'] ?? 0) === (int)$user['id'];

if (!$is_owner && !$is_admin && !$is_dysponent) {
    flash_set('danger', 'Brak dostępu.'); header('Location: ' . APP_URL . '/resources/my.php'); exit;
}

$PAGE_TITLE = 'Rezerwacja #' . $id;

// ── POST: akcje zmiany statusu ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $note   = trim($_POST['note'] ?? '');

    // Admin: zatwierdź → pending_dysponent lub odmów
    if ($is_admin && $action === 'admin_approve') {
        $new = $reservation['dysponent_user_id'] ? 'pending_dysponent' : 'rezerwacja';
        db()->prepare("UPDATE resource_reservations SET admin_note=? WHERE id=?")->execute([$note, $id]);
        res_change_status($id, $new, $note ?: 'Zatwierdzone przez administratora');
        flash_set('success', 'Przekazano do dysponenta.');
    }
    if ($is_admin && $action === 'admin_reject') {
        db()->prepare("UPDATE resource_reservations SET admin_note=? WHERE id=?")->execute([$note, $id]);
        res_change_status($id, 'odmowa', $note ?: 'Odmowa administratora');
        flash_set('warning', 'Rezerwacja odrzucona.');
    }

    // Dysponent: decyzja
    if ($is_dysponent && in_array($action, ['dy_odmowa','dy_oczekuje','dy_zgoda','dy_rezerwacja'], true)) {
        $map = ['dy_odmowa'=>'odmowa','dy_oczekuje'=>'oczekuje_dokumenty','dy_zgoda'=>'zgoda','dy_rezerwacja'=>'rezerwacja'];
        $new = $map[$action];
        db()->prepare("UPDATE resource_reservations SET dysponent_note=? WHERE id=?")->execute([$note, $id]);
        res_change_status($id, $new, $note ?: 'Decyzja dysponenta: ' . RES_STATUSES[$new]['label']);
        flash_set('success', 'Decyzja zapisana: ' . RES_STATUSES[$new]['label'] . '.');
    }

    // Anulowanie przez wnioskodawcę
    if ($is_owner && $action === 'cancel' && !in_array($reservation['status'], ['odmowa','anulowana','rezerwacja'])) {
        res_change_status($id, 'anulowana', 'Anulowana przez wnioskodawcę');
        flash_set('success', 'Rezerwacja anulowana.');
    }

    header('Location: ' . APP_URL . '/resources/view.php?id=' . $id); exit;
}

$log         = res_log_for($id);
$extra_fields = json_decode($reservation['extra_fields'] ?? '{}', true) ?: [];
$field_defs  = res_field_defs((int)$reservation['resource_id']);

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.res-status-badge { display:inline-block; padding:.2em .65em; border-radius:8px; font-size:.8rem; font-weight:700; }
.timeline { border-left: 2px solid #e2e8f0; padding-left: 1.2rem; }
.timeline-item { position:relative; margin-bottom:1rem; }
.timeline-item::before { content:''; position:absolute; left:-1.45rem; top:.3rem; width:10px; height:10px; border-radius:50%; background:#94a3b8; border:2px solid #fff; }
.timeline-item.active::before { background:#6366f1; }
</style>

<nav aria-label="Ścieżka" class="mb-3">
  <ol class="breadcrumb mb-0" style="font-size:.83rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/resources/">Zasoby</a></li>
    <?php if ($is_admin): ?>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/resources/admin/">Zarządzanie</a></li>
    <?php endif; ?>
    <li class="breadcrumb-item active">Rezerwacja #<?= $id ?></li>
  </ol>
</nav>

<?= flash_html() ?>

<div class="row g-4" style="max-width:960px">

  <!-- Lewa: szczegóły -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between mb-3">
          <div>
            <h5 class="mb-0"><?= h($reservation['res_name']) ?></h5>
            <div class="text-muted small"><?= h($reservation['cat_name'] ?? '') ?></div>
          </div>
          <?= res_status_badge($reservation['status']) ?>
        </div>

        <dl class="row g-1 mb-0" style="font-size:.88rem">
          <dt class="col-4 text-muted fw-normal">Wnioskodawca</dt>
          <dd class="col-8 mb-1"><?= h($reservation['user_name']) ?> <span class="text-muted">&lt;<?= h($reservation['user_email']) ?>&gt;</span></dd>

          <dt class="col-4 text-muted fw-normal">Termin</dt>
          <dd class="col-8 mb-1">
            <?= h($reservation['date_from']) ?>
            <?php if ($reservation['date_to'] !== $reservation['date_from']): ?> – <?= h($reservation['date_to']) ?><?php endif; ?>
            <?php if ($reservation['time_from']): ?>
            <span class="text-muted ms-1"><?= h($reservation['time_from']) ?><?= $reservation['time_to'] ? '–'.h($reservation['time_to']) : '' ?></span>
            <?php endif; ?>
          </dd>

          <dt class="col-4 text-muted fw-normal">Cel</dt>
          <dd class="col-8 mb-1"><?= nl2br(h($reservation['purpose'])) ?></dd>

          <?php if ($reservation['dysponent_name']): ?>
          <dt class="col-4 text-muted fw-normal">Dysponent</dt>
          <dd class="col-8 mb-1"><?= h($reservation['dysponent_name']) ?></dd>
          <?php endif; ?>

          <?php if ($reservation['admin_note']): ?>
          <dt class="col-4 text-muted fw-normal">Nota admina</dt>
          <dd class="col-8 mb-1 fst-italic"><?= h($reservation['admin_note']) ?></dd>
          <?php endif; ?>

          <?php if ($reservation['dysponent_note']): ?>
          <dt class="col-4 text-muted fw-normal">Nota dysponenta</dt>
          <dd class="col-8 mb-1 fst-italic"><?= h($reservation['dysponent_note']) ?></dd>
          <?php endif; ?>

          <?php foreach ($field_defs as $fd):
            $val = $extra_fields[$fd['id']] ?? '';
            if ($val === '') continue;
          ?>
          <dt class="col-4 text-muted fw-normal"><?= h($fd['label']) ?></dt>
          <dd class="col-8 mb-1"><?= $fd['field_type']==='checkbox' ? ($val?'Tak':'Nie') : h($val) ?></dd>
          <?php endforeach; ?>
        </dl>
      </div>
    </div>

    <!-- Historia -->
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="fw-semibold mb-3 small text-uppercase text-muted" style="letter-spacing:.07em">Historia</div>
        <div class="timeline">
          <?php foreach ($log as $entry): ?>
          <div class="timeline-item active">
            <div class="d-flex justify-content-between" style="font-size:.83rem">
              <span>
                <?php if ($entry['old_status'] && $entry['new_status']): ?>
                <span class="text-muted"><?= h(RES_STATUSES[$entry['old_status']]['label'] ?? $entry['old_status']) ?></span>
                → <strong><?= h(RES_STATUSES[$entry['new_status']]['label'] ?? $entry['new_status']) ?></strong>
                <?php else: ?>
                <strong><?= h(RES_STATUSES[$entry['new_status']]['label'] ?? $entry['new_status']) ?></strong>
                <?php endif; ?>
                <?php if ($entry['note']): ?>
                <span class="text-muted ms-1">— <?= h($entry['note']) ?></span>
                <?php endif; ?>
              </span>
              <span class="text-muted ms-2 text-nowrap" style="font-size:.77rem">
                <?= h($entry['user_name'] ?? 'system') ?> · <?= date('d.m H:i', strtotime($entry['created_at'])) ?>
              </span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Prawa: akcje -->
  <div class="col-lg-5">

    <?php if ($is_admin && in_array($reservation['status'], ['zlozony','pending_admin'])): ?>
    <!-- Panel admina -->
    <div class="card border-0 shadow-sm mb-3" style="border-left:3px solid #6366f1!important">
      <div class="card-body">
        <div class="fw-semibold mb-3"><i class="bi bi-shield-check me-1 text-primary"></i>Decyzja administratora</div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <div class="mb-2">
            <label class="form-label small">Notatka (opcjonalna)</label>
            <textarea class="form-control form-control-sm" name="note" rows="2"
                      placeholder="Uwagi do decyzji…"></textarea>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" name="_action" value="admin_approve" class="btn btn-success btn-sm">
              <i class="bi bi-check-lg me-1"></i>Zatwierdź i przekaż do dysponenta
            </button>
            <button type="submit" name="_action" value="admin_reject" class="btn btn-outline-danger btn-sm"
                    onclick="return confirm('Odmówić tej rezerwacji?')">
              <i class="bi bi-x-lg me-1"></i>Odrzuć
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($is_dysponent && $reservation['status'] === 'pending_dysponent'): ?>
    <!-- Panel dysponenta -->
    <div class="card border-0 shadow-sm mb-3" style="border-left:3px solid #0ea5e9!important">
      <div class="card-body">
        <div class="fw-semibold mb-3"><i class="bi bi-person-check me-1 text-info"></i>Decyzja dysponenta</div>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Notatka do wnioskodawcy</label>
            <textarea class="form-control form-control-sm" name="note" rows="2"
                      placeholder="Uzasadnienie decyzji, wymagane dokumenty itp."></textarea>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" name="_action" value="dy_rezerwacja"
                    class="btn btn-success btn-sm">
              <i class="bi bi-calendar-check me-1"></i>Rezerwacja — potwierdzona
            </button>
            <button type="submit" name="_action" value="dy_zgoda"
                    class="btn btn-outline-success btn-sm">
              <i class="bi bi-check-circle me-1"></i>Zgoda — oczekuje na podpis
            </button>
            <button type="submit" name="_action" value="dy_oczekuje"
                    class="btn btn-outline-warning btn-sm">
              <i class="bi bi-file-earmark-text me-1"></i>Oczekuje na dokumenty
            </button>
            <button type="submit" name="_action" value="dy_odmowa"
                    class="btn btn-outline-danger btn-sm"
                    onclick="return confirm('Odmówić tej rezerwacji?')">
              <i class="bi bi-x-circle me-1"></i>Odmowa
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($is_owner && !in_array($reservation['status'], ['odmowa','anulowana','rezerwacja'])): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <form method="post" onsubmit="return confirm('Anulować tę rezerwację?')">
          <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action"  value="cancel">
          <button type="submit" class="btn btn-outline-danger btn-sm w-100">
            <i class="bi bi-x-circle me-1"></i>Anuluj wniosek
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
