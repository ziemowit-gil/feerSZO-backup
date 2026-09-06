<?php
/**
 * resources/my.php — Moje rezerwacje.
 *
 * Powłoka: panel wolontariusza dla wolontariuszy, powłoka SZO dla edytorów/adminów.
 * Treść w języku wizualnym modułu „Tożsamość" (pv_ui.php: .pv-table, .tz-card).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/srs/logic/srs.php';

require_login();
ika_require();
resources_migrate();

$PAGE_TITLE = 'Moje rezerwacje';
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    $res_id = (int)($_POST['reservation_id'] ?? 0);
    if ($action === 'cancel' && $res_id) {
        $r = res_reservation_get($res_id);
        if ($r && (int)$r['user_id'] === (int)$user['id'] && !in_array($r['status'], ['odmowa','anulowana','rezerwacja'])) {
            res_change_status($res_id, 'anulowana', 'Anulowana przez wnioskodawcę');
            flash_set('success', 'Rezerwacja anulowana.');
        }
    }
    header('Location: ' . APP_URL . '/modules/srs/my.php');
    exit;
}

$reservations = res_reservations_for_user((int)$user['id']);

$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include dirname(__DIR__, 2) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__, 2) . '/includes/header.php';
}
require_once dirname(__DIR__, 2) . '/panel/includes/pv_ui.php';

pv_page_header('Moje rezerwacje', [
    'icon'    => 'bi-list-check',
    'sub'     => 'Wnioski i potwierdzone rezerwacje zasobów organizacji',
    'back'    => ['url' => APP_URL . '/modules/srs/', 'label' => 'Zasoby'],
    'actions' => '<a href="' . APP_URL . '/modules/srs/" class="tz-btn"><i class="bi bi-plus-lg" aria-hidden="true"></i>Nowa rezerwacja</a>',
]);
?>

<div class="pv-wrap">

<?= flash_html() ?>

<?php if (!$reservations): ?>
<div class="tz-empty">
  <i class="bi bi-calendar-x" aria-hidden="true"></i>
  <p class="fw-semibold mb-1">Nie masz jeszcze żadnych rezerwacji.</p>
  <p class="small mb-0"><a href="<?= APP_URL ?>/modules/srs/">Przejdź do listy zasobów</a> i wybierz termin.</p>
</div>
<?php else: ?>
<div class="pv-table-wrap">
  <table class="pv-table">
    <caption class="visually-hidden">Lista Twoich rezerwacji zasobów</caption>
    <thead>
      <tr>
        <th scope="col">Zasób</th>
        <th scope="col">Termin</th>
        <th scope="col">Cel</th>
        <th scope="col">Status</th>
        <th scope="col">Złożono</th>
        <th scope="col"><span class="visually-hidden">Akcje</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($reservations as $r):
        $_attn = in_array($r['status'], ['zlozony','pending_admin','pending_dysponent','oczekuje_dokumenty'], true);
      ?>
      <tr<?= $_attn ? ' class="pv-row-attn"' : '' ?>>
        <td>
          <span class="d-inline-flex align-items-center gap-2">
            <i class="bi <?= h($r['cat_icon'] ?? 'bi-box') ?>" style="color:<?= h($r['cat_color'] ?? '#6b7280') ?>" aria-hidden="true"></i>
            <span class="fw-semibold"><?= h($r['res_name']) ?></span>
          </span>
        </td>
        <td class="text-nowrap">
          <?= h($r['date_from']) ?>
          <?php if ($r['date_to'] !== $r['date_from']): ?> – <?= h($r['date_to']) ?><?php endif; ?>
          <?php if ($r['time_from']): ?>
          <div style="font-size:.78rem;color:var(--tz-muted)"><?= h($r['time_from']) ?><?= $r['time_to'] ? ' – ' . h($r['time_to']) : '' ?></div>
          <?php endif; ?>
        </td>
        <td style="max-width:220px">
          <span class="d-block text-truncate" style="max-width:200px" title="<?= h($r['purpose']) ?>"><?= h($r['purpose']) ?></span>
        </td>
        <td><?= res_status_badge($r['status']) ?></td>
        <td style="color:var(--tz-muted)" class="text-nowrap"><?= date('d.m.Y', strtotime($r['created_at'])) ?></td>
        <td class="text-end text-nowrap">
          <a href="<?= APP_URL ?>/modules/srs/view.php?id=<?= (int)$r['id'] ?>"
             class="btn btn-sm btn-outline-secondary" aria-label="Szczegóły rezerwacji <?= h($r['res_name']) ?>">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </a>
          <?php if (!in_array($r['status'], ['odmowa','anulowana','rezerwacja'])): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Anulować tę rezerwację?')">
            <input type="hidden" name="_csrf"          value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_action"        value="cancel">
            <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    aria-label="Anuluj rezerwację <?= h($r['res_name']) ?>">
              <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

</div><!-- /pv-wrap -->

<?php if ($_is_volunteer_only): ?>
<?php include dirname(__DIR__, 2) . '/panel/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
<?php endif; ?>
