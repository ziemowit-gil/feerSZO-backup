<?php
/** @var array $type @var array $stages @var bool $own @var array $users @var bool $canEdit */
$ro = $canEdit ? '' : ' disabled';
?>
<nav aria-label="Ścieżka" class="small mb-2"><a href="<?= h(route('appwf.index')) ?>" class="text-decoration-none"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Workflow wniosków</a></nav>
<div class="d-flex flex-wrap align-items-center gap-3 mb-3">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0"><i class="bi <?= h($type['icon'] ?: 'bi-diagram-3') ?> text-primary fs-4" aria-hidden="true"></i></div>
  <div class="flex-grow-1">
    <h1 class="h4 mb-0">Etapy: <?= h($type['label']) ?></h1>
    <div class="text-muted small"><?= $own ? 'Własny workflow typu.' : 'Typ korzysta z etapów domyślnych — zapis utworzy jego własną kopię.' ?> Kolejność wierszy = kolejność etapów; pierwszy etap startuje przy złożeniu wniosku.</div>
  </div>
</div>
<?= flash_html() ?>

<form method="post" action="<?= h(route('appwf.save', ['id' => (int)$type['id']])) ?>" id="wf-form">
  <?= Router::csrfField() ?>
  <ol class="list-unstyled d-flex flex-column gap-2 mb-3" id="wf-list">
  <?php foreach ($stages as $i => $s): ?>
    <li class="card shadow-sm wf-row">
      <div class="card-body py-2">
        <input type="hidden" name="stages[<?= $i ?>][id]" value="<?= (int)$s['id'] ?>" class="wf-id">
        <div class="row g-2 align-items-end">
          <div class="col-auto"><span class="badge bg-primary rounded-pill wf-no" aria-hidden="true"><?= $i + 1 ?></span></div>
          <div class="col-md-3"><label class="form-label small mb-0" for="st<?= $i ?>n">Nazwa etapu</label>
            <input id="st<?= $i ?>n" name="stages[<?= $i ?>][name]" value="<?= h($s['name']) ?>" class="form-control form-control-sm" maxlength="80" required<?= $ro ?>></div>
          <div class="col-md-2"><label class="form-label small mb-0" for="st<?= $i ?>s">Status wniosku</label>
            <select id="st<?= $i ?>s" name="stages[<?= $i ?>][status]" class="form-select form-select-sm"<?= $ro ?>>
              <?php foreach (APP_WF_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= $s['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2"><label class="form-label small mb-0" for="st<?= $i ?>r">Odpowiada</label>
            <select id="st<?= $i ?>r" name="stages[<?= $i ?>][role]" class="form-select form-select-sm"<?= $ro ?>>
              <?php foreach (APP_WF_ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $s['role'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2"><label class="form-label small mb-0" for="st<?= $i ?>u">Osoba</label>
            <select id="st<?= $i ?>u" name="stages[<?= $i ?>][assignee_user_id]" class="form-select form-select-sm"<?= $ro ?>>
              <option value="0">— nikt konkretny —</option>
              <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$s['assignee_user_id'] === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-1"><label class="form-label small mb-0" for="st<?= $i ?>d">Termin (dni)</label>
            <input id="st<?= $i ?>d" type="number" min="0" max="365" name="stages[<?= $i ?>][due_days]" value="<?= (int)$s['due_days'] ?>" class="form-control form-control-sm"<?= $ro ?>></div>
          <div class="col">
            <div class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" id="st<?= $i ?>a" name="stages[<?= $i ?>][act_upz]" value="1"<?= (int)$s['act_upz'] ? ' checked' : '' ?><?= $ro ?>><label class="form-check-label" for="st<?= $i ?>a">wystaw UPZ</label></div>
            <div class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" id="st<?= $i ?>m" name="stages[<?= $i ?>][act_notify]" value="1"<?= (int)$s['act_notify'] ? ' checked' : '' ?><?= $ro ?>><label class="form-check-label" for="st<?= $i ?>m">e-mail do składającego</label></div>
            <div class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" id="st<?= $i ?>f" name="stages[<?= $i ?>][is_final]" value="1"<?= (int)$s['is_final'] ? ' checked' : '' ?><?= $ro ?>><label class="form-check-label" for="st<?= $i ?>f">końcowy</label></div>
          </div>
          <?php if ($canEdit): ?>
          <div class="col-auto btn-group btn-group-sm" role="group" aria-label="Kolejność i usuwanie etapu <?= $i + 1 ?>">
            <button type="button" class="btn btn-outline-secondary wf-up" aria-label="Przesuń wyżej"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-outline-secondary wf-down" aria-label="Przesuń niżej"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-outline-danger wf-del" aria-label="Usuń etap"><i class="bi bi-trash" aria-hidden="true"></i></button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </li>
  <?php endforeach; ?>
  </ol>
  <?php if ($canEdit): ?>
  <div class="d-flex flex-wrap gap-2">
    <button type="button" class="btn btn-outline-primary" id="wf-add"><i class="bi bi-plus-lg" aria-hidden="true"></i> Dodaj etap</button>
    <span class="flex-grow-1"></span>
    <?php if ($own): ?><button type="submit" name="reset" value="1" class="btn btn-outline-secondary" formnovalidate onclick="return confirm('Przywrócić etapy domyślne? Wnioski tego typu wrócą do etapów wg statusu.')">Przywróć domyślne</button><?php endif; ?>
    <button type="submit" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> Zapisz workflow</button>
  </div>
  <?php else: ?><p class="text-muted small">Edycja etapów wymaga uprawnień administratora.</p><?php endif; ?>
</form>

<?php if ($canEdit): ?>
<script>
(function () {
  const list = document.getElementById('wf-list');
  // po każdej zmianie kolejności: numeracja i indeksy name="stages[i][…]" zgodne z kolejnością wierszy
  const renumber = () => [...list.children].forEach((li, i) => {
    li.querySelector('.wf-no').textContent = i + 1;
    li.querySelectorAll('[name^="stages["]').forEach(el => el.name = el.name.replace(/^stages\[\d+\]/, 'stages[' + i + ']'));
    li.querySelectorAll('[id^="st"]').forEach(el => { const o = el.id; el.id = o.replace(/^st\d+x?/, 'st' + i + 'x'); const l = li.querySelector('label[for="' + o + '"]'); if (l) l.htmlFor = el.id; });
  });
  list.addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    const li = b.closest('li');
    if (b.classList.contains('wf-up') && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
    else if (b.classList.contains('wf-down') && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
    else if (b.classList.contains('wf-del')) { if (list.children.length > 1) li.remove(); else return; }
    else return;
    renumber(); b.focus();
  });
  document.getElementById('wf-add').addEventListener('click', () => {
    const tpl = list.lastElementChild.cloneNode(true);
    tpl.querySelector('.wf-id').value = 0;
    tpl.querySelectorAll('input[type=text], input:not([type])').forEach(el => { if (!el.classList.contains('wf-id')) el.value = ''; });
    tpl.querySelectorAll('input[type=number]').forEach(el => el.value = 0);
    tpl.querySelectorAll('input[type=checkbox]').forEach(el => el.checked = false);
    list.appendChild(tpl); renumber();
    tpl.querySelector('input[name$="[name]"]').focus();
  });
})();
</script>
<?php endif; ?>
