<?php
/**
 * resources/reserve.php — Formularz rezerwacji zasobu.
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

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="Ścieżka" class="mb-3">
  <ol class="breadcrumb mb-0" style="font-size:.83rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/resources/">Zasoby</a></li>
    <li class="breadcrumb-item active"><?= h($resource['name']) ?></li>
  </ol>
</nav>

<div class="row g-4" style="max-width:900px">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h5 class="mb-1 d-flex align-items-center gap-2">
          <span class="rounded d-inline-flex align-items-center justify-content-center"
                style="width:34px;height:34px;background:<?= h($resource['cat_color'] ?? '#6366f1') ?>1a;color:<?= h($resource['cat_color'] ?? '#6366f1') ?>">
            <i class="bi <?= h($resource['cat_icon'] ?? 'bi-box') ?>"></i>
          </span>
          <?= h($resource['name']) ?>
        </h5>
        <?php if ($resource['description']): ?>
        <p class="text-muted small mb-3"><?= nl2br(h($resource['description'])) ?></p>
        <?php endif; ?>

        <?php if ($errors): ?>
        <div class="alert alert-danger py-2">
          <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
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
          <hr class="my-3">
          <div class="mb-1 fw-semibold text-muted small text-uppercase" style="letter-spacing:.07em">Dodatkowe informacje</div>
          <div class="row g-3 mb-3">
            <?php foreach ($field_defs as $fd):
              $fval = $_POST['extra_fields'][$fd['id']] ?? '';
              $fname = "extra_fields[{$fd['id']}]";
              $col = $fd['field_type'] === 'textarea' ? 'col-12' : 'col-sm-6';
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

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-calendar-check me-1"></i>
              <?= $resource['requires_approval'] ? 'Złóż wniosek' : 'Zarezerwuj' ?>
            </button>
            <a href="<?= APP_URL ?>/resources/" class="btn btn-outline-secondary">Anuluj</a>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Info panel -->
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body" style="font-size:.85rem">
        <div class="fw-semibold mb-2">Informacje o zasobie</div>
        <?php if ($resource['location']): ?>
        <div class="mb-1"><i class="bi bi-geo-alt text-muted me-1"></i><?= h($resource['location']) ?></div>
        <?php endif; ?>
        <?php if ($resource['capacity']): ?>
        <div class="mb-1"><i class="bi bi-people text-muted me-1"></i>Max <?= (int)$resource['capacity'] ?> osób</div>
        <?php endif; ?>
        <?php if ($resource['dysponent_name']): ?>
        <div class="mb-1"><i class="bi bi-person-check text-muted me-1"></i>Dysponent: <strong><?= h($resource['dysponent_name']) ?></strong></div>
        <?php endif; ?>
        <?php if ($resource['requires_approval']): ?>
        <div class="alert alert-warning small mt-2 mb-0 py-2">
          <i class="bi bi-exclamation-triangle me-1"></i>
          Rezerwacja tego zasobu wymaga zatwierdzenia przez administratora i dysponenta.
        </div>
        <?php else: ?>
        <div class="alert alert-success small mt-2 mb-0 py-2">
          <i class="bi bi-check-circle me-1"></i>
          Rezerwacja jest zatwierdzana automatycznie.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
