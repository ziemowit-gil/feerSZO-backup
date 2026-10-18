<?php
/**
 * modules/ti_room_srs/view/index.php — grupy z salą w okresie: formularz rezerwacji sali i rezerwacja w SRS.
 * Zmienne: $me, $rr (range/from/to/prev/next/label), $items (tiRoomSrsCandidates), $res (id rezerwacji SRS → status),
 * $rooms (id sali → tiRoomSrsRooms()). Kontroler: RoomSrsController::index.
 */
$title = 'Formularze rezerwacji sal';
include __DIR__ . '/_head.php';
$nav = fn(string $w, ?string $range = null) => route('trs.index', ['range' => $range ?? $rr['range'], 'w' => $w]);
$unmapped = array_filter($rooms, fn($r) => !(int)$r['resource_id']);
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-2">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Formularze rezerwacji sal</h1>
  <div class="ms-auto d-flex align-items-center flex-wrap gap-2">
    <select class="form-select form-select-sm" style="width:auto" aria-label="Zakres"
            onchange="location.href=this.value">
      <?php foreach (['week' => 'Tydzień', 'month' => 'Miesiąc', 'quarter' => '3 miesiące'] as $k => $l): ?>
      <option value="<?= h($nav($rr['from'], $k)) ?>" <?= $rr['range'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
      <?php endforeach; ?>
    </select>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($nav($rr['prev'])) ?>" aria-label="Poprzedni okres"><i class="bi bi-chevron-left"></i></a>
    <span class="small fw-semibold"><?= h($rr['label']) ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($nav($rr['next'])) ?>" aria-label="Następny okres"><i class="bi bi-chevron-right"></i></a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h($nav(date('Y-m-d'))) ?>">Dziś</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('trs.rooms')) ?>"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Sale ↔ SRS</a>
    <a class="btn btn-sm btn-outline-secondary" href="sale_rezerwacje.php">Wykaz sal do rezerwacji</a>
  </div>
</div>
<p class="text-body-secondary small">
  Jeden formularz obejmuje wszystkie terminy grupy w danej sali w wybranym okresie. Dane uzupełniają się z systemu
  (grupa, terminy, uczestnicy, prowadzący, dane organizacji); PESEL i adres zamieszkania osoby odpowiedzialnej
  wpisujesz przy generowaniu — nie są zapisywane. Wygenerowanie zakłada rezerwację w SRS, a jej akceptacja
  w SRS oznacza terminy jako „Potwierdzone”.
</p>
<?php if ($unmapped): ?>
<div class="alert alert-warning py-2 small">
  Sale bez zasobu SRS: <?= h(implode(', ', array_map(fn($r) => (string)($r['short_label'] ?: $r['name']), $unmapped))) ?>.
  Dla nich formularz wygeneruje się, ale bez rezerwacji w SRS — <a href="<?= h(route('trs.rooms')) ?>">przypisz zasoby</a>.
</div>
<?php endif; ?>

<?php if (!$items): ?>
<div class="card"><div class="card-body text-center text-body-secondary py-4">Brak terminów z przypisaną salą w wybranym okresie.</div></div>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead class="table-light"><tr>
    <th scope="col">Sala</th><th scope="col">Grupa</th><th scope="col" class="text-nowrap">Od – do</th>
    <th scope="col" class="text-center">Terminów</th><th scope="col">Status sali</th><th scope="col">SRS</th><th scope="col"></th>
  </tr></thead>
  <tbody>
  <?php foreach ($items as $it):
      $room = $rooms[$it['room_id']] ?? null;
      $rid = (int)($it['form']['srs_reservation_id'] ?? 0);
      $st = $rid ? ($res[$rid] ?? null) : null; ?>
    <tr>
      <td class="small"><?= h($it['room_label']) ?>
        <?php if ($room && $room['form_room'] !== ''): ?><div class="text-muted">wzór: <?= h($room['form_room']) ?></div><?php endif; ?></td>
      <td class="small"><?= h($it['course_name']) ?></td>
      <td class="small text-nowrap"><?= h(date('d.m.Y', strtotime($it['date_from']))) ?> – <?= h(date('d.m.Y', strtotime($it['date_to']))) ?></td>
      <td class="text-center"><span class="badge bg-secondary"><?= (int)$it['count'] ?></span></td>
      <td class="small"><?php if ($it['pending'] === 0): ?><span class="badge text-bg-success">potwierdzone</span>
        <?php else: ?><span class="badge text-bg-warning"><?= (int)$it['pending'] ?>/<?= (int)$it['count'] ?> do rezerwacji</span><?php endif; ?></td>
      <td class="small"><?php if ($st): ?>
          <span class="badge" style="background:<?= h(RES_STATUSES[$st]['bg'] ?? '#eee') ?>;color:<?= h(RES_STATUSES[$st]['color'] ?? '#333') ?>">#<?= $rid ?> <?= h(RES_STATUSES[$st]['label'] ?? $st) ?></span>
        <?php elseif ($it['form']): ?><span class="text-muted">formularz zapisany</span>
        <?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
      <td class="text-end">
        <a class="btn btn-sm btn-primary" href="<?= h(route('trs.form', ['course' => $it['course_id'], 'room' => $it['room_id'], 'from' => $it['date_from'], 'to' => $it['date_to'], 'back' => $nav($rr['from'])])) ?>">
          <i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i><?= $it['form'] ? 'Otwórz formularz' : 'Formularz' ?><span class="visually-hidden"> — <?= h($it['course_name']) ?>, <?= h($it['room_label']) ?></span>
        </a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
