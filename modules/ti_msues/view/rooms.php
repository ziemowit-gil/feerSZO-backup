<?php
/**
 * modules/ti_msues/view/rooms.php — normy sal szkoleniowych i wyposażenie (MSUES III): lista sal + formularz wybranej sali.
 * Zmienne: $rooms, $room (wybrana sala albo null), $actor. Kontroler: MsuesController::rooms / room.
 */
require __DIR__ . '/_parts.php';
$title = 'Normy sal MSUES';
$kierLabel = 'Certyfikacja MSUES';
include __DIR__ . '/_head.php';
$all = count(MsuesRequirements::ROOM_NORMS);
?>
<p class="mb-2"><a href="<?= h(route('msues.reqs')) ?>">&larr; Wymagania usług MSUES</a></p>
<h1 class="h4 mb-3"><i class="bi bi-door-open text-primary" aria-hidden="true"></i> Normy sal i wyposażenie</h1>
<div class="row g-3">
  <div class="<?= $room ? 'col-lg-5' : 'col-12' ?>">
    <section class="card"><div class="list-group list-group-flush">
      <?php foreach ($rooms as $r): $n = count(MsuesRequirements::roomNorms($r)); ?>
      <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center<?= $room && (int)$room['id'] === (int)$r['id'] ? ' active' : '' ?>" href="<?= h(route('msues.room', ['id' => $r['id']])) ?>">
        <span><?= h((string)$r['name']) ?><?= empty($r['is_active']) ? ' <span class="small">(nieaktywna)</span>' : '' ?><?= trim((string)$r['location']) !== '' ? '<br><span class="small opacity-75">' . h((string)$r['location']) . '</span>' : '' ?></span>
        <span class="badge <?= $n === $all ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $n ?>/<?= $all ?></span></a>
      <?php endforeach; if (!$rooms): ?><div class="list-group-item text-muted">Brak sal — dodaj je w Planowanie › Sale / lokalizacje.</div><?php endif; ?>
    </div></section>
  </div>
  <?php if ($room): $have = MsuesRequirements::roomNorms($room); ?>
  <div class="col-lg-7">
    <section class="card"><div class="card-header fw-semibold"><?= h((string)$room['name']) ?></div><div class="card-body">
      <p class="small text-muted">Pojemność: <?= (int)$room['capacity'] ?> · stanowiska: <?= (int)$room['workstations'] ?> · projektor: <?= !empty($room['has_projector']) ? 'tak' : 'nie' ?><?= !empty($room['msues_checked_at']) ? ' · sprawdził(a): ' . h((string)$room['msues_checked_by']) . ', ' . h(substr((string)$room['msues_checked_at'], 0, 10)) : '' ?></p>
      <form method="post" action="<?= h(route('msues.room.save', ['id' => $room['id']])) ?>"><?= Router::csrfField() ?>
        <fieldset><legend class="h6">Sala spełnia</legend>
        <?php foreach (MsuesRequirements::ROOM_NORMS as $k => $l): ?>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="rn_<?= h($k) ?>" name="norms[]" value="<?= h($k) ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>><label class="form-check-label" for="rn_<?= h($k) ?>"><?= h($l) ?></label></div>
        <?php endforeach; ?></fieldset>
        <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-save"></i> Zapisz</button>
      </form>
    </div></section>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
