<?php
/**
 * modules/ti_room_srs/view/index.php — grupy z salą w okresie (formularz operatora + rezerwacja SRS) i rezerwacje
 * przestrzeni bez grupy. Źródło każdej pozycji: DYD (panel dydaktyka) albo SZO.
 * Zmienne: $me, $rr (range/from/to/prev/next/label), $items (tiRoomSrsCandidates), $res (id rezerwacji SRS → status),
 * $rooms (id sali → tiRoomSrsRooms()), $adhoc (tiRoomSrsAdhocList), $pending (kierownik: czekające na akceptację).
 * Kontroler: RoomSrsController::index.
 */
$title = 'Formularze rezerwacji sal';
include __DIR__ . '/_head.php';
$nav = fn(string $w, ?string $range = null) => route('trs.index', ['range' => $range ?? $rr['range'], 'w' => $w]);
$unmapped = array_filter($rooms, fn($r) => $r['template'] === '' || !(int)$r['resource_id']);
$apprBadge = fn(?array $f) => $f && !tiRoomSrsIsApproved($f) ? ' <span class="badge text-bg-warning">czeka na akceptację</span>' : '';
$badge = fn(?string $st, int $rid) => $st
    ? '<span class="badge" style="background:' . h(RES_STATUSES[$st]['bg'] ?? '#eee') . ';color:' . h(RES_STATUSES[$st]['color'] ?? '#333') . '">#' . $rid . ' ' . h(RES_STATUSES[$st]['label'] ?? $st) . '</span>'
    : '';
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-2">
  <h1 class="h4 fw-bold mb-0"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Formularze rezerwacji sal</h1>
  <div class="ms-auto d-flex align-items-center flex-wrap gap-2">
    <a class="btn btn-sm btn-primary" href="<?= h(route('trs.adhoc')) ?>"><i class="bi bi-door-open me-1" aria-hidden="true"></i>Rezerwuj przestrzeń</a>
    <?php if ($me['staff']): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(route('trs.rooms')) ?>"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Sale ↔ wzory ↔ SRS</a><?php endif; ?>
    <?php if (defined('TI_ROOM_SRS_PANEL') && $me['staff']): ?><a class="btn btn-sm btn-outline-secondary" href="sale_rezerwacje.php">Wykaz sal do rezerwacji</a><?php endif; ?>
  </div>
</div>
<p class="text-body-secondary small">
  Formularz grupy obejmuje wszystkie jej terminy w danej sali w wybranym okresie; „Rezerwuj przestrzeń” — salę na jeden
  termin bez grupy (np. konsultacje). Dane uzupełniają się z systemu, a dokument powstaje z oryginalnego wzoru operatora
  sali (Reymonta 20 — DOCX, Centrum C10 — PDF). PESEL i adres zamieszkania osoby odpowiedzialnej wpisujesz przy
  generowaniu — nie są zapisywane. Wygenerowanie zakłada rezerwację w SRS; jej akceptacja oznacza terminy jako „Potwierdzone”.
  Znacznik <span class="badge text-bg-info">DYD</span> / <span class="badge text-bg-dark">SZO</span> mówi, skąd złożono formularz.
</p>
<?php if ($unmapped && $me['staff']): ?>
<div class="alert alert-warning py-2 small">
  Sale bez wzoru operatora albo zasobu SRS: <?= h(implode(', ', array_map(fn($r) => (string)($r['short_label'] ?: $r['name']), $unmapped))) ?> —
  <a href="<?= h(route('trs.rooms')) ?>">uzupełnij przypisanie</a>.
</div>
<?php endif; ?>

<?php if ($pending): ?>
<section class="card border-warning mb-3" aria-labelledby="trs-pend-h">
  <div class="card-header fw-semibold bg-warning-subtle" id="trs-pend-h"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Czeka na Twoją akceptację (<?= count($pending) ?>)</div>
  <div class="card-body small text-body-secondary pb-0">Formularz można wydrukować dopiero po zatwierdzeniu. Zatwierdzenie składa też rezerwację w SRS (jeśli autor ją zaznaczył) i wysyła autorowi e-mail.</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th scope="col">Co</th><th scope="col">Sala</th><th scope="col">Termin</th><th scope="col">Kto</th><th scope="col">Źródło</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($pending as $p): $pd = json_decode((string)$p['form_data'], true) ?: [];
        $open = (int)$p['course_id'] > 0
            ? route('trs.form', ['course' => (int)$p['course_id'], 'room' => (int)$p['room_id'], 'from' => $p['date_from'], 'to' => $p['date_to']])
            : route('trs.adhoc', ['id' => (int)$p['id']]); ?>
      <tr>
        <td class="small"><?= (int)$p['course_id'] > 0 ? 'Grupa: ' . h((string)($p['course_display'] ?: $p['course_name'])) : 'Przestrzeń: ' . h(mb_strimwidth((string)($pd['topic'] ?? ''), 0, 60, '…')) ?></td>
        <td class="small"><?= h((string)($p['room_short_label'] ?: $p['room_name'])) ?></td>
        <td class="small"><?= nl2br(h(mb_strimwidth((string)($pd['terms'] ?? ''), 0, 160, '…'))) ?></td>
        <td class="small"><?= h((string)$p['user_name']) ?></td>
        <td><?= tiRoomSrsSourceBadge($p['source'] ?? 'dyd') ?></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-secondary" href="<?= h($open) ?>">Sprawdź<span class="visually-hidden"> formularz — <?= h((string)$p['user_name']) ?></span></a>
          <form method="post" action="<?= h(route('trs.approve', ['id' => (int)$p['id']])) ?>" class="d-inline">
            <?= Router::csrfField() ?><input type="hidden" name="back" value="<?= h($nav($rr['from'])) ?>">
            <button class="btn btn-sm btn-success"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zatwierdź<span class="visually-hidden"> formularz — <?= h((string)$p['user_name']) ?></span></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<h2 class="h6 fw-bold mt-3">Rezerwacje przestrzeni (bez grupy)</h2>
<?php if (!$adhoc): ?>
<p class="small text-body-secondary">Brak bieżących rezerwacji przestrzeni<?= $me['staff'] ? '' : ' złożonych przez Ciebie' ?>.</p>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead class="table-light"><tr><th scope="col">Dzień i godziny</th><th scope="col">Sala</th><th scope="col">Temat</th><th scope="col">Kto</th><th scope="col">Źródło</th><th scope="col">SRS</th><th scope="col"></th></tr></thead>
  <tbody>
  <?php foreach ($adhoc as $a): $fd = json_decode((string)$a['form_data'], true) ?: []; ?>
    <tr>
      <td class="small text-nowrap"><?= h(date('d.m.Y', strtotime($a['date_from']))) ?> <?= h(($fd['timeFrom'] ?? '') . '–' . ($fd['timeTo'] ?? '')) ?></td>
      <td class="small"><?= h((string)($a['room_short_label'] ?: $a['room_name'])) ?></td>
      <td class="small"><?= h(mb_strimwidth((string)($fd['topic'] ?? ''), 0, 80, '…')) ?></td>
      <td class="small"><?= h((string)$a['user_name']) ?></td>
      <td><?= tiRoomSrsSourceBadge($a['source'] ?? 'dyd') ?><?= $apprBadge($a) ?></td>
      <td class="small"><?= $badge($a['srs_status'], (int)$a['srs_reservation_id']) ?: '<span class="text-muted">—</span>' ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(route('trs.adhoc', ['id' => (int)$a['id']])) ?>">Otwórz<span class="visually-hidden"> rezerwację z <?= h($a['date_from']) ?></span></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<div class="d-flex align-items-center flex-wrap gap-2 mt-4 mb-2">
  <h2 class="h6 fw-bold mb-0">Grupy z salą<?= $me['staff'] ? '' : ' (Twoje grupy)' ?></h2>
  <div class="ms-auto d-flex align-items-center flex-wrap gap-2">
    <label class="visually-hidden" for="trs_range">Zakres</label>
    <select class="form-select form-select-sm" id="trs_range" style="width:auto" onchange="location.href=this.value">
      <?php foreach (['week' => 'Tydzień', 'month' => 'Miesiąc', 'quarter' => '3 miesiące'] as $k => $l): ?>
      <option value="<?= h($nav($rr['from'], $k)) ?>" <?= $rr['range'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
      <?php endforeach; ?>
    </select>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($nav($rr['prev'])) ?>" aria-label="Poprzedni okres"><i class="bi bi-chevron-left"></i></a>
    <span class="small fw-semibold"><?= h($rr['label']) ?></span>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($nav($rr['next'])) ?>" aria-label="Następny okres"><i class="bi bi-chevron-right"></i></a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h($nav(date('Y-m-d'))) ?>">Dziś</a>
  </div>
</div>
<?php if (!$items): ?>
<div class="card"><div class="card-body text-center text-body-secondary py-4">Brak terminów z przypisaną salą w wybranym okresie.</div></div>
<?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead class="table-light"><tr>
    <th scope="col">Sala</th><th scope="col">Grupa</th><th scope="col" class="text-nowrap">Od – do</th>
    <th scope="col" class="text-center">Terminów</th><th scope="col">Status sali</th><th scope="col">Źródło</th><th scope="col">SRS</th><th scope="col"></th>
  </tr></thead>
  <tbody>
  <?php foreach ($items as $it):
      $room = $rooms[$it['room_id']] ?? null;
      $rid = (int)($it['form']['srs_reservation_id'] ?? 0);
      $st = $rid ? ($res[$rid] ?? null) : null; ?>
    <tr>
      <td class="small"><?= h($it['room_label']) ?>
        <?php if ($room && $room['template'] !== ''): ?><div class="text-muted"><?= h(explode(' — ', TI_ROOM_SRS_TEMPLATES[$room['template']]['label'])[0]) ?><?= $room['form_room'] !== '' ? ' · ' . h($room['form_room']) : '' ?></div><?php endif; ?></td>
      <td class="small"><?= h($it['course_name']) ?></td>
      <td class="small text-nowrap"><?= h(date('d.m.Y', strtotime($it['date_from']))) ?> – <?= h(date('d.m.Y', strtotime($it['date_to']))) ?></td>
      <td class="text-center"><span class="badge bg-secondary"><?= (int)$it['count'] ?></span></td>
      <td class="small"><?php if ($it['pending'] === 0): ?><span class="badge text-bg-success">potwierdzone</span>
        <?php else: ?><span class="badge text-bg-warning"><?= (int)$it['pending'] ?>/<?= (int)$it['count'] ?> do rezerwacji</span><?php endif; ?></td>
      <td><?= $it['form'] ? tiRoomSrsSourceBadge($it['form']['source'] ?? 'dyd') . $apprBadge($it['form']) : '<span class="text-muted small">—</span>' ?></td>
      <td class="small"><?= $badge($st, $rid) ?: ($it['form'] ? '<span class="text-muted">formularz zapisany</span>' : '<span class="text-muted">—</span>') ?></td>
      <td class="text-end">
        <a class="btn btn-sm btn-primary" href="<?= h(route('trs.form', ['course' => $it['course_id'], 'room' => $it['room_id'], 'from' => $it['date_from'], 'to' => $it['date_to'], 'back' => $nav($rr['from'])])) ?>">
          <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i><?= $it['form'] ? 'Otwórz formularz' : 'Formularz' ?><span class="visually-hidden"> — <?= h($it['course_name']) ?>, <?= h($it['room_label']) ?></span>
        </a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
