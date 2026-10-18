<?php
/**
 * modules/ti_room_srs/view/rooms.php — przypisanie sal TI do wzoru operatora (Reymonta 20 / Centrum C10), opcji „Sala”
 * w tym wzorze i zasobu SRS.
 * Zmienne: $me, $rooms (tiRoomSrsRooms()), $resources (tiRoomSrsResources()). Kontroler: RoomSrsController::rooms.
 */
$title = 'Sale TI ↔ wzory ↔ SRS';
include __DIR__ . '/_head.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
  <li class="breadcrumb-item"><a href="<?= h(route('trs.index')) ?>">Formularze rezerwacji sal</a></li>
  <li class="breadcrumb-item active" aria-current="page">Sale ↔ wzory ↔ SRS</li>
</ol></nav>
<h1 class="h4 fw-bold mb-1"><i class="bi bi-link-45deg text-primary me-2" aria-hidden="true"></i>Sale TI ↔ wzory operatorów ↔ zasoby SRS</h1>
<p class="text-body-secondary small mb-3">
  Zasób SRS to sala w Systemie Rezerwacji Sal — do niego trafiają rezerwacje z formularzy, a jego akceptacja potwierdza
  terminy lekcji. „Wzór i sala we wzorze” — który formularz operatora wypełniamy (Reymonta 20 — DOCX, Centrum C10 — PDF)
  i którą salę w nim zaznaczamy; podpowiadamy z nazwy sali. Brakującą salę (np. 1W014 „akwarium”, sale C10) dodaj
  najpierw w wykazie sal panelu dydaktyka (Sale / lokalizacje).
</p>
<?php if (!$rooms): ?>
<div class="card"><div class="card-body text-center text-body-secondary py-4">Brak aktywnych sal TI.</div></div>
<?php else: ?>
<form method="post" action="<?= h(route('trs.rooms.save')) ?>">
  <?= Router::csrfField() ?>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <thead class="table-light"><tr><th scope="col">Sala TI</th><th scope="col">Wzór i sala we wzorze</th><th scope="col">Zasób SRS</th></tr></thead>
    <tbody>
    <?php foreach ($rooms as $r): $id = (int)$r['id']; ?>
      <tr>
        <td class="small"><strong><?= h((string)($r['short_label'] ?: $r['name'])) ?></strong>
          <div class="text-muted"><?= h((string)$r['name']) ?><?= $r['operator_label'] ? ' · operator: ' . h((string)$r['operator_label']) : '' ?></div></td>
        <td>
          <label class="visually-hidden" for="fr<?= $id ?>">Opcja we wzorze dla sali <?= h((string)$r['name']) ?></label>
          <select class="form-select form-select-sm" id="fr<?= $id ?>" name="rooms[<?= $id ?>][form]">
            <option value="">— bez wzoru operatora —</option>
            <?php foreach (TI_ROOM_SRS_TEMPLATES as $tk => $t): ?>
            <optgroup label="<?= h($t['label']) ?>">
              <option value="<?= h($tk) ?>:" <?= $r['template'] === $tk && $r['form_room'] === '' ? 'selected' : '' ?>><?= h(explode(' — ', $t['label'])[0]) ?> — sala do wyboru w formularzu</option>
              <?php foreach ($t['rooms'] as $k => $l): ?><option value="<?= h($tk . ':' . $k) ?>" <?= $r['template'] === $tk && $r['form_room'] === (string)$k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <label class="visually-hidden" for="rs<?= $id ?>">Zasób SRS dla sali <?= h((string)$r['name']) ?></label>
          <select class="form-select form-select-sm" id="rs<?= $id ?>" name="rooms[<?= $id ?>][resource_id]">
            <option value="">— brak —</option>
            <?php foreach ($resources as $res): ?><option value="<?= (int)$res['id'] ?>" <?= (int)$r['resource_id'] === (int)$res['id'] ? 'selected' : '' ?>><?= h($res['name'] . ($res['cat_name'] ? ' (' . $res['cat_name'] . ')' : '')) ?></option><?php endforeach; ?>
          </select>
          <?php if ((int)$r['resource_id'] && !(int)$r['resource_active']): ?><div class="small text-danger">Zasób nieaktywny w SRS.</div><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <button class="btn btn-primary"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz przypisania</button>
</form>

<?php if (array_filter($rooms, fn($r) => !(int)$r['resource_id'])): ?>
<h2 class="h6 fw-bold mt-4">Utwórz zasób SRS z sali TI</h2>
<p class="small text-body-secondary">Zakłada w SRS salę z zatwierdzaniem (wniosek → administrator → dysponent), dostępną dla Dydaktyka 3, i od razu ją przypisuje.</p>
<form method="post" action="<?= h(route('trs.rooms.save')) ?>" class="d-flex gap-2 align-items-end flex-wrap">
  <?= Router::csrfField() ?>
  <input type="hidden" name="_op" value="create">
  <div><label class="form-label small fw-semibold" for="trs_create_room">Sala TI</label>
    <select class="form-select form-select-sm" id="trs_create_room" name="room_id">
      <?php foreach ($rooms as $r): if ((int)$r['resource_id']) continue; ?><option value="<?= (int)$r['id'] ?>"><?= h((string)($r['short_label'] ?: $r['name'])) ?></option><?php endforeach; ?>
    </select></div>
  <button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz zasób SRS</button>
</form>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
