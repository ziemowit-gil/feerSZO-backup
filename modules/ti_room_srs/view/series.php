<?php
/**
 * modules/ti_room_srs/view/series.php — zarządzanie serią cyklicznej rezerwacji przestrzeni.
 * Zmienne: $me, $s (tiRoomSrsSeriesGet), $forms (tiRoomSrsSeriesForms). Kontroler: RoomSrsController::series.
 */
$title = 'Rezerwacja cykliczna';
include __DIR__ . '/_head.php';
$live = array_values(array_filter($forms, fn($f) => empty($f['cancelled_at'])));
$pendingAppr = array_filter($live, fn($f) => !tiRoomSrsIsApproved($f));
$today = date('Y-m-d');
$future = array_filter($live, fn($f) => $f['date_from'] >= $today);
$roomLbl = (string)($s['room_short_label'] ?: $s['room_name']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
  <li class="breadcrumb-item"><a href="<?= h(route('trs.index')) ?>">Formularze rezerwacji sal</a></li>
  <li class="breadcrumb-item active" aria-current="page">Rezerwacja cykliczna #<?= (int)$s['id'] ?></li>
</ol></nav>
<h1 class="h4 fw-bold mb-1"><i class="bi bi-arrow-repeat text-primary me-2" aria-hidden="true"></i>Rezerwacja cykliczna #<?= (int)$s['id'] ?></h1>
<p class="text-body-secondary small mb-3">
  <?= h($roomLbl) ?> · <?= h(tiRoomSrsSeriesRuleLabel($s)) ?> · złożył(a): <?= h((string)$s['user_name']) ?>
  <?php if (!empty($s['cancelled_at'])): ?> · <span class="badge text-bg-secondary">seria odwołana</span><?php endif; ?>
</p>

<?php if ($pendingAppr): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2">
  <i class="bi bi-hourglass-split" aria-hidden="true"></i>
  <span class="me-auto">Seria czeka na akceptację kierownika (<?= count($pendingAppr) ?> <?= h(tiRoomSrsTermWord(count($pendingAppr))) ?>) — wydruk po zatwierdzeniu.</span>
  <?php if ($me['staff']): ?>
  <form method="post" action="<?= h(route('trs.series.approve', ['id' => (int)$s['id']])) ?>" class="d-inline">
    <?= Router::csrfField() ?>
    <button class="btn btn-sm btn-success"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zatwierdź całą serię</button>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3">
  <?php if ($live && !$pendingAppr): ?>
  <a class="btn btn-sm btn-primary" href="<?= h(route('trs.series.file', ['id' => (int)$s['id']])) ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz wniosek dla całej serii</a>
  <?php endif; ?>
  <?php if ($me['staff'] && $live): $allOk = !array_filter($live, fn($f) => ($f['manual_status'] ?? '') !== 'potwierdzone' && !in_array((string)$f['srs_status'], TI_ROOM_SRS_CONFIRMED, true)); ?>
  <form method="post" action="<?= h(route('trs.series.confirm', ['id' => (int)$s['id']])) ?>" class="d-inline">
    <?= Router::csrfField() ?><input type="hidden" name="status" value="<?= $allOk ? 'do_rezerwacji' : 'potwierdzone' ?>">
    <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i><?= $allOk ? 'Cofnij potwierdzenie sali' : 'Oznacz salę jako potwierdzoną' ?></button>
  </form>
  <?php endif; ?>
  <?php if ($future): ?>
  <form method="post" action="<?= h(route('trs.series.cancel', ['id' => (int)$s['id']])) ?>" class="d-inline"
        onsubmit="return confirm('Odwołać wszystkie przyszłe terminy serii? Rezerwacje SRS tych terminów zostaną anulowane.')">
    <?= Router::csrfField() ?><input type="hidden" name="scope" value="future">
    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Odwołaj przyszłe terminy</button>
  </form>
  <?php endif; ?>
  <?php if ($live): ?>
  <form method="post" action="<?= h(route('trs.series.cancel', ['id' => (int)$s['id']])) ?>" class="d-inline"
        onsubmit="return confirm('Odwołać całą serię (także terminy z przeszłości)? Rezerwacje SRS zostaną anulowane.')">
    <?= Router::csrfField() ?><input type="hidden" name="scope" value="all">
    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Odwołaj całą serię</button>
  </form>
  <?php endif; ?>
</div>

<div class="table-responsive"><table class="table table-sm align-middle">
  <caption class="visually-hidden">Terminy serii rezerwacji</caption>
  <thead class="table-light"><tr><th scope="col">Dzień</th><th scope="col">Godziny</th><th scope="col">Status</th><th scope="col">SRS</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($forms as $f): $fd = json_decode((string)$f['form_data'], true) ?: []; $canc = !empty($f['cancelled_at']);
      $st = (string)$f['srs_status']; ?>
    <tr class="<?= $canc ? 'text-body-secondary' : '' ?>">
      <td class="small text-nowrap"><?= h(TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$f['date_from']))] . ', ' . date('d.m.Y', strtotime((string)$f['date_from']))) ?></td>
      <td class="small text-nowrap"><?= h(($fd['timeFrom'] ?? '') . '–' . ($fd['timeTo'] ?? '')) ?></td>
      <td class="small"><?php if ($canc): ?><span class="badge text-bg-secondary">odwołany</span>
        <?php elseif (!tiRoomSrsIsApproved($f)): ?><span class="badge text-bg-warning">czeka na akceptację</span>
        <?php else: ?><span class="badge text-bg-success">zatwierdzony</span><?php endif; ?>
        <?php if (!$canc && (($f['manual_status'] ?? '') === 'potwierdzone' || in_array($st, TI_ROOM_SRS_CONFIRMED, true))): ?> <span class="badge text-bg-success">sala potwierdzona</span><?php endif; ?></td>
      <td class="small"><?php if ((int)$f['srs_reservation_id']): ?>#<?= (int)$f['srs_reservation_id'] ?> <?= h(RES_STATUSES[$st]['label'] ?? $st) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      <td class="text-end text-nowrap">
        <?php if (!$canc): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= h(route('trs.adhoc', ['id' => (int)$f['id']])) ?>">Edytuj<span class="visually-hidden"> termin <?= h((string)$f['date_from']) ?></span></a>
        <form method="post" action="<?= h(route('trs.term.cancel', ['id' => (int)$f['id']])) ?>" class="d-inline"
              onsubmit="return confirm('Odwołać ten jeden termin?')">
          <?= Router::csrfField() ?>
          <button class="btn btn-sm btn-outline-danger">Odwołaj<span class="visually-hidden"> termin <?= h((string)$f['date_from']) ?></span></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<p class="small text-body-secondary">Edycja terminu zmienia tylko ten termin (dzień, godziny, dane wniosku); reszta serii zostaje bez zmian.</p>
<?php include __DIR__ . '/_foot.php'; ?>
