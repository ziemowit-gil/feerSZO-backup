<?php
/**
 * resources/reserve.php — Formularz rezerwacji zasobu.
 *
 * Powłoka: panel wolontariusza dla wolontariuszy, powłoka SZO dla edytorów/adminów.
 * Treść w języku wizualnym modułu „Tożsamość" (pv_ui.php: .tz-card, .tz-note, .tz-btn).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/resources.php';

require_login();
ika_require();
resources_migrate();

$resource_id = (int)($_GET['id'] ?? 0);
$resource    = $resource_id ? res_get($resource_id) : null;

if (!$resource || !$resource['is_active']) {
    flash_set('danger', 'Zasób nie istnieje lub jest niedostępny.');
    header('Location: ' . APP_URL . '/resources/');
    exit;
}

$PAGE_TITLE = 'Rezerwacja: ' . $resource['name'];
$errors     = [];
$field_defs = res_field_defs($resource_id);
$user       = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $date_from   = trim($_POST['date_from'] ?? '');
    $date_to     = trim($_POST['date_to']   ?? '');
    $time_from   = trim($_POST['time_from'] ?? '');
    $time_to     = trim($_POST['time_to']   ?? '');
    $purpose     = trim($_POST['purpose']   ?? '');
    $extra       = (array)($_POST['extra_fields'] ?? []);

    if (!$date_from)                     $errors[] = 'Data od jest wymagana.';
    if (!$date_to)                       $errors[] = 'Data do jest wymagana.';
    if ($date_from && $date_to && $date_to < $date_from) $errors[] = 'Data do nie może być wcześniejsza niż data od.';
    if (!$purpose)                       $errors[] = 'Cel rezerwacji jest wymagany.';

    // Walidacja wymaganych pól dodatkowych
    foreach ($field_defs as $fd) {
        if ($fd['is_required'] && empty($extra[$fd['id']])) {
            $errors[] = 'Pole „' . $fd['label'] . '" jest wymagane.';
        }
    }

    // Sprawdź konflikty (tylko dla zasobów bez podwójnych rezerwacji)
    if (!$errors && $date_from && $date_to) {
        $conflicts = res_conflicts($resource_id, $date_from, $date_to);
        if ($conflicts) {
            $conf_str = implode(', ', array_map(fn($c) => $c['date_from'] . '–' . $c['date_to'] . ' (' . $c['user_name'] . ')', $conflicts));
            $errors[] = 'Zasób jest już zarezerwowany w tym terminie: ' . $conf_str;
        }
    }

    if (!$errors) {
        $id = res_reserve([
            'resource_id'  => $resource_id,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'time_from'    => $time_from,
            'time_to'      => $time_to,
            'purpose'      => $purpose,
            'extra_fields' => $extra,
        ]);

        $msg = $resource['requires_approval']
            ? 'Wniosek złożony. Oczekuje na zatwierdzenie przez administratora.'
            : 'Rezerwacja przyjęta!';
        flash_set('success', $msg);
        header('Location: ' . APP_URL . '/resources/my.php');
        exit;
    }
}

$_is_volunteer_only = is_viewer()
    && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include dirname(__DIR__) . '/panel/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
require_once dirname(__DIR__) . '/panel/includes/pv_ui.php';

pv_page_header($resource['name'], [
    'icon' => $resource['cat_icon'] ?? 'bi-box',
    'sub'  => trim(($resource['cat_name'] ?? '') . ($resource['location'] ? ' · ' . $resource['location'] : '')) ?: 'Rezerwacja zasobu',
    'back' => ['url' => APP_URL . '/resources/', 'label' => 'Zasoby'],
]);
?>

<div class="pv-wrap">

<?= flash_html() ?>

<?php if ($resource['requires_approval']): ?>
<div class="tz-note tz-note--warn" role="note">
  <i class="bi bi-shield-check" aria-hidden="true"></i>
  <span>Rezerwacja tego zasobu <strong>wymaga zatwierdzenia</strong> — najpierw przez administratora,
    a potem przez dysponenta<?= $resource['dysponent_name'] ? ' (' . h($resource['dysponent_name']) . ')' : '' ?>.
    O każdej decyzji dostaniesz powiadomienie.</span>
</div>
<?php else: ?>
<div class="tz-note tz-note--ok" role="note">
  <i class="bi bi-lightning-charge-fill" aria-hidden="true"></i>
  <span>Ten zasób rezerwujesz <strong>bezpośrednio</strong> — rezerwacja jest potwierdzana od razu po wysłaniu.</span>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="tz-card mb-0">
      <div class="tz-card__hd"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Termin i cel</div>
      <div class="tz-card__bd">

        <?php if ($errors): ?>
        <div class="pv-alert pv-alert-danger" role="alert">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <div>
            <strong>Nie udało się zapisać rezerwacji:</strong>
            <ul class="mb-0 ps-3 mt-1">
              <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
          </div>
        </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

          <div class="row g-3 mb-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold" for="date_from">Data od <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="date_from" name="date_from"
                     value="<?= h($_POST['date_from'] ?? date('Y-m-d')) ?>" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold" for="date_to">Data do <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="date_to" name="date_to"
                     value="<?= h($_POST['date_to'] ?? date('Y-m-d')) ?>" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="time_from">Godzina od</label>
              <input type="time" class="form-control" id="time_from" name="time_from"
                     value="<?= h($_POST['time_from'] ?? '') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="time_to">Godzina do</label>
              <input type="time" class="form-control" id="time_to" name="time_to"
                     value="<?= h($_POST['time_to'] ?? '') ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold" for="purpose">Cel rezerwacji <span class="text-danger">*</span></label>
            <textarea class="form-control" id="purpose" name="purpose" rows="3" required
                      placeholder="Opisz w jakim celu rezerwujesz zasób…"><?= h($_POST['purpose'] ?? '') ?></textarea>
          </div>

          <?php if ($field_defs): ?>
          <h2 class="tz-section-h">Dodatkowe informacje</h2>
          <div class="row g-3 mb-3">
            <?php foreach ($field_defs as $fd):
              $fval  = $_POST['extra_fields'][$fd['id']] ?? '';
              $fname = "extra_fields[{$fd['id']}]";
              $col   = $fd['field_type'] === 'textarea' ? 'col-12' : 'col-sm-6';
            ?>
            <div class="<?= $col ?>">
              <label class="form-label" for="ef_<?= (int)$fd['id'] ?>">
                <?= h($fd['label']) ?>
                <?php if ($fd['is_required']): ?><span class="text-danger">*</span><?php endif; ?>
              </label>
              <?php if ($fd['field_type'] === 'select'):
                $opts = json_decode($fd['options'], true) ?: [];
              ?>
              <select class="form-select" id="ef_<?= (int)$fd['id'] ?>" name="<?= $fname ?>">
                <option value="">— wybierz —</option>
                <?php foreach ($opts as $o): ?>
                <option value="<?= h($o) ?>" <?= $fval===$o?'selected':'' ?>><?= h($o) ?></option>
                <?php endforeach; ?>
              </select>
              <?php elseif ($fd['field_type'] === 'textarea'): ?>
              <textarea class="form-control" id="ef_<?= (int)$fd['id'] ?>" name="<?= $fname ?>" rows="2"><?= h($fval) ?></textarea>
              <?php elseif ($fd['field_type'] === 'date'): ?>
              <input type="date" class="form-control" id="ef_<?= (int)$fd['id'] ?>" name="<?= $fname ?>" value="<?= h($fval) ?>">
              <?php elseif ($fd['field_type'] === 'number'): ?>
              <input type="number" class="form-control" id="ef_<?= (int)$fd['id'] ?>" name="<?= $fname ?>" value="<?= h($fval) ?>">
              <?php elseif ($fd['field_type'] === 'checkbox'): ?>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="ef_<?= (int)$fd['id'] ?>" name="<?= $fname ?>" value="1" <?= $fval?'checked':'' ?>>
                <label class="form-check-label" for="ef_<?= (int)$fd['id'] ?>">Tak</label>
              </div>
              <?php else: ?>
              <input type="text" class="form-control" id="ef_<?= (int)$fd['id'] ?>" name="<?= $fname ?>" value="<?= h($fval) ?>">
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="tz-btn">
              <i class="bi bi-calendar-check" aria-hidden="true"></i>
              <?= $resource['requires_approval'] ? 'Złóż wniosek' : 'Zarezerwuj' ?>
            </button>
            <a href="<?= APP_URL ?>/resources/" class="tz-btn tz-btn--ghost">Anuluj</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Informacje o zasobie -->
  <div class="col-lg-4">
    <div class="tz-card mb-0">
      <div class="tz-card__hd"><i class="bi bi-info-circle" aria-hidden="true"></i>O zasobie</div>
      <div class="tz-card__bd">
        <?php if ($resource['description']): ?>
        <p style="font-size:.86rem;color:var(--tz-muted)"><?= nl2br(h($resource['description'])) ?></p>
        <?php endif; ?>
        <div class="tz-kv"><i class="bi bi-tag" aria-hidden="true"></i><?= h($resource['cat_name'] ?? 'Zasób') ?></div>
        <?php if ($resource['location']): ?>
        <div class="tz-kv"><i class="bi bi-geo-alt" aria-hidden="true"></i><?= h($resource['location']) ?></div>
        <?php endif; ?>
        <?php if ($resource['capacity']): ?>
        <div class="tz-kv"><i class="bi bi-people" aria-hidden="true"></i>Maksymalnie <?= (int)$resource['capacity'] ?> osób</div>
        <?php endif; ?>
        <?php if ($resource['dysponent_name']): ?>
        <div class="tz-kv"><i class="bi bi-person-check" aria-hidden="true"></i>Dysponent: <strong><?= h($resource['dysponent_name']) ?></strong></div>
        <?php endif; ?>
      </div>
      <div class="tz-card__ft">
        <a href="<?= APP_URL ?>/resources/my.php" class="tz-card__link">
          <i class="bi bi-list-check" aria-hidden="true"></i>Moje rezerwacje
        </a>
      </div>
    </div>
  </div>
</div>

</div><!-- /pv-wrap -->

<?php if ($_is_volunteer_only): ?>
<?php include dirname(__DIR__) . '/panel/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
<?php endif; ?>
