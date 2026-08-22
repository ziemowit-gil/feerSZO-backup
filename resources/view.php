<?php
/**
 * resources/view.php — Szczegóły rezerwacji + historia + akcje zatwierdzania.
 *
 * Widoczne dla:
 *  - wnioskodawcy (user_id)
 *  - admina/editora (panel zatwierdzania admin)
 *  - dysponenta zasobu (panel zatwierdzania dysponent)
 *
 * Powłoka: panel wolontariusza dla wolontariuszy, powłoka SZO dla edytorów/adminów.
 * Treść w języku wizualnym modułu „Tożsamość" (pv_ui.php: .tz-card, .tz-dl, .tz-btn).
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

$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include dirname(__DIR__) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
require_once dirname(__DIR__) . '/panel/includes/pv_ui.php';

pv_page_header('Rezerwacja #' . $id, [
    'icon'    => 'bi-calendar-check',
    'sub'     => $reservation['res_name'] . ($reservation['cat_name'] ? ' · ' . $reservation['cat_name'] : ''),
    'back'    => $is_admin
        ? ['url' => APP_URL . '/resources/admin/index.php', 'label' => 'Decyzje']
        : ['url' => APP_URL . '/resources/my.php',          'label' => 'Moje rezerwacje'],
    'actions' => res_status_badge($reservation['status']),
]);
?>

<style>
/* Historia decyzji — os czasu w tokenach tz */
.res-tl{border-left:2px solid var(--tz-line);padding-left:1.2rem;margin:0}
.res-tl-item{position:relative;margin-bottom:.9rem;font-size:.84rem}
.res-tl-item:last-child{margin-bottom:0}
.res-tl-item::before{content:'';position:absolute;left:-1.51rem;top:.35rem;width:10px;height:10px;border-radius:50%;
  background:var(--tz);border:2px solid var(--tz-bg)}
</style>

<div class="pv-wrap">

<?= flash_html() ?>

<div class="row g-3">

  <!-- Szczegóły -->
  <div class="col-lg-7">
    <div class="tz-card mb-3">
      <div class="tz-card__hd">
        <span class="tz-card__icon" aria-hidden="true"><i class="bi <?= h($reservation['cat_icon'] ?? 'bi-box') ?>"></i></span>
        <div class="flex-grow-1" style="min-width:0">
          <div class="tz-card__title"><?= h($reservation['res_name']) ?></div>
          <?php if ($reservation['cat_name'] ?? ''): ?><div class="tz-card__sub"><?= h($reservation['cat_name']) ?></div><?php endif; ?>
        </div>
      </div>
      <dl class="tz-dl">
        <div>
          <dt>Wnioskodawca</dt>
          <dd><?= h($reservation['user_name']) ?><br><span style="font-weight:500;color:var(--tz-muted);font-size:.82rem"><?= h($reservation['user_email']) ?></span></dd>
        </div>
        <div>
          <dt>Termin</dt>
          <dd>
            <?= h($reservation['date_from']) ?>
            <?php if ($reservation['date_to'] !== $reservation['date_from']): ?> – <?= h($reservation['date_to']) ?><?php endif; ?>
            <?php if ($reservation['time_from']): ?>
            <br><span style="font-weight:500;color:var(--tz-muted);font-size:.82rem"><?= h($reservation['time_from']) ?><?= $reservation['time_to'] ? ' – ' . h($reservation['time_to']) : '' ?></span>
            <?php endif; ?>
          </dd>
        </div>
        <div>
          <dt>Złożono</dt>
          <dd><?= date('d.m.Y, H:i', strtotime($reservation['created_at'])) ?></dd>
        </div>
        <?php if ($reservation['dysponent_name']): ?>
        <div><dt>Dysponent</dt><dd><?= h($reservation['dysponent_name']) ?></dd></div>
        <?php endif; ?>
        <?php foreach ($field_defs as $fd):
          $val = $extra_fields[$fd['id']] ?? '';
          if ($val === '') continue;
        ?>
        <div><dt><?= h($fd['label']) ?></dt><dd><?= $fd['field_type']==='checkbox' ? ($val?'Tak':'Nie') : h($val) ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <div class="tz-card__bd" style="border-top:1px solid var(--tz-line)">
        <h2 class="tz-section-h">Cel rezerwacji</h2>
        <p class="mb-0" style="font-size:.9rem"><?= nl2br(h($reservation['purpose'])) ?></p>
        <?php if ($reservation['admin_note']): ?>
        <h2 class="tz-section-h">Nota administratora</h2>
        <p class="mb-0" style="font-size:.88rem;color:var(--tz-muted)"><?= nl2br(h($reservation['admin_note'])) ?></p>
        <?php endif; ?>
        <?php if ($reservation['dysponent_note']): ?>
        <h2 class="tz-section-h">Nota dysponenta</h2>
        <p class="mb-0" style="font-size:.88rem;color:var(--tz-muted)"><?= nl2br(h($reservation['dysponent_note'])) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Historia -->
    <div class="tz-card mb-0">
      <div class="tz-card__hd"><i class="bi bi-clock-history" aria-hidden="true"></i>Historia decyzji</div>
      <div class="tz-card__bd">
        <?php if (!$log): ?>
        <p class="mb-0" style="font-size:.86rem;color:var(--tz-muted)">Brak wpisów.</p>
        <?php else: ?>
        <ol class="res-tl list-unstyled">
          <?php foreach ($log as $entry): ?>
          <li class="res-tl-item">
            <div class="d-flex justify-content-between gap-2 flex-wrap">
              <span>
                <?php if ($entry['old_status'] && $entry['new_status']): ?>
                <span style="color:var(--tz-muted)"><?= h(RES_STATUSES[$entry['old_status']]['label'] ?? $entry['old_status']) ?></span>
                → <strong><?= h(RES_STATUSES[$entry['new_status']]['label'] ?? $entry['new_status']) ?></strong>
                <?php else: ?>
                <strong><?= h(RES_STATUSES[$entry['new_status']]['label'] ?? $entry['new_status']) ?></strong>
                <?php endif; ?>
                <?php if ($entry['note']): ?>
                <span style="color:var(--tz-muted)">— <?= h($entry['note']) ?></span>
                <?php endif; ?>
              </span>
              <span class="text-nowrap" style="color:var(--tz-muted);font-size:.77rem">
                <?= h($entry['user_name'] ?? 'system') ?> · <?= date('d.m H:i', strtotime($entry['created_at'])) ?>
              </span>
            </div>
          </li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Akcje -->
  <div class="col-lg-5">

    <?php if ($is_admin && in_array($reservation['status'], ['zlozony','pending_admin'])): ?>
    <div class="tz-card mb-3">
      <div class="tz-card__hd"><i class="bi bi-shield-check" aria-hidden="true"></i>Decyzja administratora</div>
      <div class="tz-card__bd">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <div class="mb-3">
            <label class="form-label" for="admin_note">Notatka (opcjonalna)</label>
            <textarea class="form-control" id="admin_note" name="note" rows="2" placeholder="Uwagi do decyzji…"></textarea>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" name="_action" value="admin_approve" class="btn btn-success">
              <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zatwierdź<?= $reservation['dysponent_user_id'] ? ' i przekaż do dysponenta' : '' ?>
            </button>
            <button type="submit" name="_action" value="admin_reject" class="btn btn-outline-danger"
                    onclick="return confirm('Odmówić tej rezerwacji?')">
              <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Odrzuć
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($is_dysponent && $reservation['status'] === 'pending_dysponent'): ?>
    <div class="tz-card mb-3">
      <div class="tz-card__hd"><i class="bi bi-person-check" aria-hidden="true"></i>Decyzja dysponenta</div>
      <div class="tz-card__bd">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <div class="mb-3">
            <label class="form-label" for="dy_note">Notatka do wnioskodawcy</label>
            <textarea class="form-control" id="dy_note" name="note" rows="2"
                      placeholder="Uzasadnienie decyzji, wymagane dokumenty itp."></textarea>
          </div>
          <div class="d-grid gap-2">
            <button type="submit" name="_action" value="dy_rezerwacja" class="btn btn-success">
              <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Rezerwacja — potwierdzona
            </button>
            <button type="submit" name="_action" value="dy_zgoda" class="btn btn-outline-success">
              <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Zgoda — oczekuje na podpis
            </button>
            <button type="submit" name="_action" value="dy_oczekuje" class="btn btn-outline-secondary">
              <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Oczekuje na dokumenty
            </button>
            <button type="submit" name="_action" value="dy_odmowa" class="btn btn-outline-danger"
                    onclick="return confirm('Odmówić tej rezerwacji?')">
              <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odmowa
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($is_owner && !in_array($reservation['status'], ['odmowa','anulowana','rezerwacja'])): ?>
    <div class="tz-card mb-0">
      <div class="tz-card__bd">
        <form method="post" onsubmit="return confirm('Anulować tę rezerwację?')">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="cancel">
          <button type="submit" class="btn btn-outline-danger w-100">
            <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Anuluj wniosek
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

</div><!-- /pv-wrap -->

<?php if ($_is_volunteer_only): ?>
<?php include dirname(__DIR__) . '/panel/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
<?php endif; ?>
