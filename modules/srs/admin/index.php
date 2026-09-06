<?php
/**
 * resources/admin/index.php — Panel decyzji o rezerwacjach (admin / dysponent).
 *
 * Treść w języku wizualnym modułu „Tożsamość" (pv_ui.php: .pv-table, .tz-card).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/modules/srs/logic/srs.php';

require_login();
ika_require();
resources_migrate();

$user         = current_user();
$is_admin     = is_admin() || can_write('resources');
// Dysponent = użytkownik przypisany do co najmniej jednego zasobu. Wcześniej
// `$is_dysponent = !$is_admin` sprawiało, że warunek poniżej NIGDY nie był
// spełniony i panel decyzji otwierał każdy zalogowany użytkownik.
$is_dysponent = !$is_admin && res_is_dysponent((int)$user['id']);

if (!$is_admin && !$is_dysponent) {
    flash_set('danger', 'Nie masz uprawnień do panelu decyzji o rezerwacjach.');
    header('Location: ' . APP_URL . '/modules/srs/'); exit;
}

$PAGE_TITLE = 'Zarządzanie rezerwacjami';

$status_filter  = $_GET['status'] ?? '';
$date_from      = $_GET['date_from'] ?? '';
$date_to        = $_GET['date_to']   ?? '';

// Admini widzą wszystkie, dysponent tylko swoje
if ($is_admin) {
    $reservations = res_all_reservations($status_filter, $date_from, $date_to);
    // Pending dla admina
    $pending_admin = array_filter($reservations, fn($r) => in_array($r['status'], ['zlozony','pending_admin']));
} else {
    $reservations = res_reservations_pending_dysponent((int)$user['id']);
    $pending_admin = [];
}

include dirname(__DIR__, 3) . '/includes/header.php';
require_once dirname(__DIR__, 3) . '/panel/includes/pv_ui.php';

$_actions = '';
if ($is_admin) {
    $_actions .= '<a href="' . APP_URL . '/modules/srs/admin/resources.php" class="tz-btn tz-btn--ghost"><i class="bi bi-box" aria-hidden="true"></i>Zasoby</a> '
              .  '<a href="' . APP_URL . '/modules/srs/admin/categories.php" class="tz-btn tz-btn--ghost"><i class="bi bi-tags" aria-hidden="true"></i>Kategorie</a> ';
}
$_actions .= '<a href="' . APP_URL . '/modules/srs/" class="tz-btn tz-btn--ghost"><i class="bi bi-grid" aria-hidden="true"></i>Lista zasobów</a>';

pv_page_header('Rezerwacje zasobów', [
    'icon'    => 'bi-calendar-check',
    'sub'     => $is_admin ? 'Wszystkie wnioski i rezerwacje w organizacji' : 'Wnioski oczekujące na Twoją decyzję jako dysponenta',
    'actions' => $_actions,
]);
?>

<div class="pv-wrap">

<?= flash_html() ?>

<?php if ($is_admin && $pending_admin): ?>
<div class="pv-alert pv-alert-warning" role="alert">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <span><strong><?= count($pending_admin) ?></strong>
    <?= count($pending_admin) === 1 ? 'rezerwacja oczekuje' : 'rezerwacji oczekuje' ?> na Twoją decyzję.</span>
</div>
<?php endif; ?>

<!-- Filtry (tylko admin) -->
<?php if ($is_admin): ?>
<form method="get" class="tz-card mb-3">
  <div class="tz-card__bd">
    <div class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label" for="f_status">Status</label>
        <select name="status" id="f_status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Wszystkie</option>
          <?php foreach (RES_STATUSES as $k => $st): ?>
          <option value="<?= h($k) ?>" <?= $status_filter===$k?'selected':'' ?>><?= h($st['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label" for="f_from">Od</label>
        <input type="date" name="date_from" id="f_from" class="form-control form-control-sm" value="<?= h($date_from) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label" for="f_to">Do</label>
        <input type="date" name="date_to" id="f_to" class="form-control form-control-sm" value="<?= h($date_to) ?>">
      </div>
      <div class="col-sm-2 d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary">Filtruj</button>
        <?php if ($status_filter || $date_from || $date_to): ?>
        <a href="?" class="btn btn-sm btn-link text-muted px-1">Wyczyść</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
<?php endif; ?>

<?php if (!$reservations): ?>
<div class="tz-empty">
  <i class="bi bi-calendar-x" aria-hidden="true"></i>
  <p class="fw-semibold mb-1">Brak rezerwacji do wyświetlenia.</p>
  <p class="small mb-0"><?= $is_admin ? 'Zmień filtry albo poczekaj na nowe wnioski.' : 'Nic nie czeka teraz na Twoją decyzję.' ?></p>
</div>
<?php else: ?>
<div class="pv-table-wrap">
  <table class="pv-table">
    <caption class="visually-hidden">Rezerwacje zasobów</caption>
    <thead>
      <tr>
        <th scope="col">#</th>
        <th scope="col">Zasób</th>
        <th scope="col">Wnioskodawca</th>
        <th scope="col">Termin</th>
        <th scope="col">Cel</th>
        <th scope="col">Status</th>
        <th scope="col">Złożono</th>
        <th scope="col"><span class="visually-hidden">Akcje</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($reservations as $r):
        $_attn = in_array($r['status'], ['zlozony','pending_admin','pending_dysponent'], true);
      ?>
      <tr<?= $_attn ? ' class="pv-row-attn"' : '' ?>>
        <td style="color:var(--tz-muted)"><?= (int)$r['id'] ?></td>
        <td>
          <span class="d-inline-flex align-items-center gap-2">
            <i class="bi <?= h($r['cat_icon'] ?? 'bi-box') ?>" style="color:<?= h($r['cat_color'] ?? '#6b7280') ?>" aria-hidden="true"></i>
            <span class="fw-semibold"><?= h($r['res_name']) ?></span>
          </span>
        </td>
        <td><?= h($r['user_name']) ?></td>
        <td class="text-nowrap">
          <?= h($r['date_from']) ?>
          <?php if ($r['date_to']!==$r['date_from']): ?> – <?= h($r['date_to']) ?><?php endif; ?>
        </td>
        <td style="max-width:200px">
          <span class="d-block text-truncate" style="max-width:180px" title="<?= h($r['purpose']) ?>"><?= h($r['purpose']) ?></span>
        </td>
        <td><?= res_status_badge($r['status']) ?></td>
        <td class="text-nowrap" style="color:var(--tz-muted)"><?= date('d.m.Y', strtotime($r['created_at'])) ?></td>
        <td class="text-end">
          <a href="<?= APP_URL ?>/modules/srs/view.php?id=<?= (int)$r['id'] ?>"
             class="btn btn-sm <?= $_attn ? 'btn-primary' : 'btn-outline-secondary' ?>"
             aria-label="<?= $_attn ? 'Rozpatrz' : 'Podgląd' ?> rezerwacji #<?= (int)$r['id'] ?>">
            <i class="bi <?= $_attn ? 'bi-check2-circle' : 'bi-eye' ?>" aria-hidden="true"></i>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

</div><!-- /pv-wrap -->

<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>
