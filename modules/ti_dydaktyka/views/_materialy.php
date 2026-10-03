<?php
/**
 * Sekcja „Materiały dla kursanta” — materiały przypisane jednemu kursantowi (poza dostępem z zapisu na kurs).
 * Zmienne: $clientId, $courseCtx, $actor, $assigned, $assignable, $errors, $open, $af (note, due_at).
 */
$_by_course = [];
foreach ($assignable as $m) $_by_course[$m['course_name']][] = $m;
?>
<section id="materialy" aria-labelledby="h-materialy" class="card border-0 shadow-sm mb-4">
  <div class="card-header"><h2 class="h5 mb-0" id="h-materialy"><i class="bi bi-collection-play text-primary me-2" aria-hidden="true"></i>Materiały dla kursanta</h2></div>
  <div class="card-body">
    <p class="small text-body-secondary">Materiał przypisany tutaj kursant widzi w swoim panelu (Dydaktyka / eLearning) i może pobrać — także wtedy, gdy nie należy do jego grupy.</p>
    <?php if (!$assigned): ?>
      <p class="text-body-secondary small">Kursant nie ma przypisanych materiałów.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle" style="font-size:.88rem">
        <caption class="visually-hidden">Materiały przypisane kursantowi: tytuł, typ, kto przypisał, termin i czy kursant je otworzył</caption>
        <thead class="table-light"><tr><th scope="col">Materiał</th><th scope="col">Przypisał(a)</th><th scope="col">Termin</th><th scope="col">Otwarty</th><th scope="col">Uwaga</th><th scope="col" class="text-end">Akcje</th></tr></thead>
        <tbody>
        <?php foreach ($assigned as $a): ?>
          <tr>
            <th scope="row" class="fw-semibold"><?= h($a['title']) ?>
              <div class="small fw-normal text-body-secondary"><?= h(k30_ti_material_type_label((string)$a['type'])) ?><?= !empty($a['course_name']) ? ' · ' . h($a['course_name']) : '' ?><?= $a['attach_name'] !== '' ? ' · ' . h($a['attach_name']) : '' ?><?= empty($a['is_active']) ? ' · nieaktywny' : '' ?></div></th>
            <td><?= h((string)($a['assigned_by_name'] ?? '—')) ?><div class="small text-body-secondary"><?= h(dk_date($a['assigned_at'])) ?></div></td>
            <td><?= !empty($a['due_at']) ? h(dk_date($a['due_at'])) : '—' ?></td>
            <td><?= !empty($a['opened_at']) ? dk_badge('bi-check-circle-fill', 'dk-ok', 'otwarty ' . dk_date($a['opened_at'])) : dk_badge('bi-circle', 'dk-muted', 'nie otwarty') ?></td>
            <td><?= $a['note'] !== '' ? h($a['note']) : '—' ?></td>
            <td class="text-end">
              <form method="post" class="d-inline" action="<?= h(dk_url($clientId, $courseCtx, [], '#materialy')) ?>" onsubmit="return confirm('Cofnąć przypisanie tego materiału?')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="unassign_material">
                <input type="hidden" name="client" value="<?= (int)$clientId ?>"><input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Cofnij przypisanie materiału: <?= h($a['title']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">Cofnij</span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <details id="materialy-form"<?= $open === 'materialy' ? ' open' : '' ?>>
      <summary class="fw-semibold text-primary" style="cursor:pointer">Przypisz materiały</summary>
      <?php if (!$assignable): ?>
        <p class="small text-body-secondary mt-2 mb-0">Brak materiałów do przypisania — dodaj materiały w zakładce Materiały swojej grupy.</p>
      <?php else: ?>
      <form method="post" class="mt-2" action="<?= h(dk_url($clientId, $courseCtx, [], '#materialy')) ?>">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="assign_material">
        <input type="hidden" name="client" value="<?= (int)$clientId ?>">
        <?php foreach ($_by_course as $cname => $mats): ?>
        <fieldset class="border rounded p-2 mb-2">
          <legend class="float-none w-auto px-1 fs-6 fw-semibold mb-1"><?= h($cname) ?></legend>
          <?php foreach ($mats as $m): $id = 'am-' . (int)$m['id']; ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="material_ids[]" id="<?= $id ?>" value="<?= (int)$m['id'] ?>">
            <label class="form-check-label" for="<?= $id ?>"><?= h($m['title']) ?> <span class="small text-body-secondary">(<?= h(k30_ti_material_type_label((string)$m['type'])) ?><?= $m['attach_name'] !== '' ? ', ' . h($m['attach_name']) : '' ?>)</span></label>
          </div>
          <?php endforeach; ?>
        </fieldset>
        <?php endforeach; ?>
        <div class="row g-2">
          <div class="col-md-8"><label class="form-label" for="am-note">Uwaga dla kursanta (opcjonalnie)</label><input type="text" class="form-control" id="am-note" name="note" value="<?= h($af['note']) ?>" maxlength="500" placeholder="np. przeczytaj rozdział 2 przed następną lekcją"></div>
          <div class="col-md-4"><label class="form-label" for="am-due">Termin (opcjonalnie)</label><input type="date" class="form-control" id="am-due" name="due_at" value="<?= h($af['due_at']) ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary mt-2"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Przypisz zaznaczone</button>
      </form>
      <?php endif; ?>
    </details>
  </div>
</section>
