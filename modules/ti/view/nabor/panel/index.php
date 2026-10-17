<?php
/**
 * modules/ti/view/nabor/panel/index.php — lista / kanban zgłoszeń.
 * Zmienne: $f (filtry), $view (list|kanban), $rows, $courses, $counts. Kontroler: NaborPanelController::index.
 */
$J = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$kanbanData = array_map(fn($r) => [
    'id' => (int)$r['id'], 'test' => (bool)$r['is_test'], 'nr' => $r['registration_number'], 'status' => $r['status'], 'name' => $r['participant_name'],
    'course' => $r['is_custom_schedule'] ? 'Termin do ustalenia' : (string)$r['course_name'], 'ezd' => $r['znak_sprawy'],
], $rows);
$publicUrl = naborUrl('/zapisy');
$back = fn() => implode('', array_map(fn($k) => '<input type="hidden" name="_back[' . $k . ']" value="' . h($k === 'view' ? $view : $f[$k]) . '">', ['status', 'course', 'q', 'view']));

$title = 'Nabór do grup — zgłoszenia';
$kierLabel = 'Nabór do grup';
include __DIR__ . '/_head.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-person-plus text-primary"></i> Nabór do grup — zgłoszenia</h4>
  <div class="d-flex gap-2 flex-wrap">
    <form method="post" action="<?= h(route('nabor.test')) ?>" class="d-inline"><?= Router::csrfField() ?>
      <button class="btn btn-sm btn-outline-warning" title="Fikcyjne dane, bez SMS/e-maili, EZD i CRM; przyjęcie nie zapisuje do grupy"><i class="bi bi-bug"></i> Utwórz zgłoszenie testowe</button></form>
    <form method="post" action="<?= h(route('nabor.toggle')) ?>" class="d-inline"><?= Router::csrfField() ?><?= $back() ?>
      <button class="btn btn-sm <?= naborPublicOpen() ? 'btn-success' : 'btn-outline-secondary' ?>" title="Przełącz dostępność formularza">
        <i class="bi bi-<?= naborPublicOpen() ? 'unlock' : 'lock' ?>"></i> Formularz <?= naborPublicOpen() ? 'otwarty' : 'zamknięty' ?></button></form>
    <a class="btn btn-sm btn-outline-primary" href="<?= h($publicUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Formularz</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('nabor.courses')) ?>"><i class="bi bi-collection"></i> Grupy w naborze</a>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('nabor.form.edit')) ?>"><i class="bi bi-ui-checks"></i> Edytuj formularz</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('nabor.instructor')) ?>"><i class="bi bi-person-video3"></i> Widok prowadzącego</a>
  </div>
</div>
<?= flash_html() ?>

<ul class="nav nav-pills mb-3 small flex-wrap">
  <li class="nav-item"><a class="nav-link <?= $f['status'] === '' ? 'active' : '' ?>" href="?<?= h(http_build_query([...$f, 'status' => '', 'view' => $view])) ?>">W obiegu</a></li>
  <?php foreach (NABOR_STATUSES as $k => [$label]): ?>
    <li class="nav-item"><a class="nav-link <?= $f['status'] === $k ? 'active' : '' ?>" href="?<?= h(http_build_query([...$f, 'status' => $k, 'view' => $view])) ?>">
      <?= h($label) ?> <span class="badge text-bg-light"><?= $counts[$k] ?? 0 ?></span></a></li>
  <?php endforeach; ?>
</ul>

<form class="row g-2 mb-3" method="get">
  <input type="hidden" name="status" value="<?= h($f['status']) ?>"><input type="hidden" name="view" value="<?= h($view) ?>">
  <div class="col-md-5"><label class="visually-hidden" for="zp-q">Szukaj</label>
    <input id="zp-q" class="form-control form-control-sm" name="q" value="<?= h($f['q']) ?>" placeholder="Numer, uczestnik, opiekun, e-mail, telefon"></div>
  <div class="col-md-4"><label class="visually-hidden" for="zp-c">Grupa</label>
    <select id="zp-c" name="course" class="form-select form-select-sm">
      <option value="">Wszystkie grupy</option>
      <option value="custom" <?= $f['course'] === 'custom' ? 'selected' : '' ?>>Termin do ustalenia</option>
      <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['course'] === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['title']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-md-3 d-flex gap-2">
    <button class="btn btn-sm btn-primary">Filtruj</button>
    <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Widok">
      <a class="btn btn-outline-secondary <?= $view === 'list' ? 'active' : '' ?>" href="?<?= h(http_build_query([...$f, 'view' => 'list'])) ?>"><i class="bi bi-list-ul"></i> Lista</a>
      <a class="btn btn-outline-secondary <?= $view === 'kanban' ? 'active' : '' ?>" href="?<?= h(http_build_query([...$f, 'view' => 'kanban'])) ?>"><i class="bi bi-kanban"></i> Kanban</a>
    </div>
  </div>
</form>

<?php if ($view === 'kanban'): ?>
<div x-data="naborKanban(<?= h(json_encode(['items' => $kanbanData, 'csrf' => Router::csrfToken(), 'moveUrl' => route('nabor.move'), 'showUrl' => route('nabor.show', ['id' => 0])], $J)) ?>)">
  <div class="form-check mb-2 small"><input class="form-check-input" type="checkbox" id="zp-kn" x-model="notify"><label class="form-check-label" for="zp-kn">Powiadamiaj opiekuna przy przeniesieniu karty</label></div>
  <div class="alert alert-danger py-2 small" x-show="err" x-text="err" role="alert"></div>
  <div class="tw-grid tw-gap-3 tw-grid-cols-1 md:tw-grid-cols-5">
    <?php foreach (NABOR_KANBAN as $col): ?>
    <section class="tw-bg-slate-100 tw-rounded-xl tw-p-2 tw-min-h-[200px]" @dragover.prevent @drop="drop('<?= $col ?>')" aria-label="<?= h(naborStatusLabel($col)) ?>">
      <h6 class="tw-font-bold tw-px-1 tw-mb-2"><?= h(naborStatusLabel($col)) ?> <span class="badge text-bg-light" x-text="items.filter(i => i.status === '<?= $col ?>').length"></span></h6>
      <template x-for="i in items.filter(i => i.status === '<?= $col ?>')" :key="i.id">
        <article class="tw-bg-white tw-rounded-lg tw-p-2 tw-mb-2 tw-shadow-sm tw-cursor-grab" draggable="true" @dragstart="drag = i">
          <a :href="showUrl.replace(/\/0$/, '/' + i.id)" class="tw-font-semibold tw-no-underline" x-text="i.name"></a>
          <div class="small text-muted"><span x-text="i.nr"></span> <span class="badge text-bg-warning" x-show="i.test">TEST</span></div>
          <div class="small" x-text="i.course"></div>
          <div class="small text-danger" x-show="i.ezd" x-text="'EZD: ' + i.ezd"></div>
          <label class="visually-hidden" :for="'mv' + i.id">Przenieś</label>
          <select :id="'mv' + i.id" class="form-select form-select-sm mt-1" @change="move(i, $event.target.value); $event.target.value = ''">
            <option value="">Przenieś do…</option>
            <?php foreach (NABOR_MANUAL_STATUSES as $s): ?><option value="<?= $s ?>"><?= h(naborStatusLabel($s)) ?></option><?php endforeach; ?>
          </select>
        </article>
      </template>
    </section>
    <?php endforeach; ?>
  </div>
</div>
<script>
function naborKanban(init) {
  return { ...init, drag: null, notify: true, err: '',
    drop(col) { if (this.drag) this.move(this.drag, col); this.drag = null; },
    async move(item, to) {
      if (!to || item.status === to) return;
      this.err = '';
      const fd = new FormData();
      fd.append('_token', this.csrf); fd.append('id', item.id); fd.append('to_status', to);
      if (this.notify) fd.append('notify', '1');
      const r = await (await fetch(this.moveUrl, { method: 'POST', body: fd })).json().catch(() => ({ ok: false, error: 'Błąd połączenia.' }));
      if (r.ok) item.status = to; else this.err = r.error;
    } };
}
</script>
<?php else: ?>
<form method="post" action="<?= h(route('nabor.bulk')) ?>" x-data="{ sel: [], all: false }">
  <?= Router::csrfField() ?><?= $back() ?>
  <div class="card mb-2" x-show="sel.length" x-cloak>
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
      <strong x-text="'Zaznaczono: ' + sel.length"></strong>
      <label class="visually-hidden" for="zp-bs">Nowy status</label>
      <select id="zp-bs" name="to_status" class="form-select form-select-sm w-auto">
        <?php foreach (NABOR_MANUAL_STATUSES as $s): ?><option value="<?= $s ?>"><?= h(naborStatusLabel($s)) ?></option><?php endforeach; ?>
      </select>
      <label class="visually-hidden" for="zp-bc">Notatka</label>
      <input id="zp-bc" name="comment" class="form-control form-control-sm w-auto flex-grow-1" placeholder="Notatka (trafi do historii i do opiekuna)">
      <div class="form-check"><input class="form-check-input" type="checkbox" name="notify" value="1" id="zp-bn" checked><label class="form-check-label small" for="zp-bn">Powiadom e-mail/SMS</label></div>
      <button class="btn btn-sm btn-primary">Zmień status</button>
    </div>
  </div>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead><tr>
      <th><input type="checkbox" class="form-check-input" aria-label="Zaznacz wszystkie" x-model="all" @change="sel = all ? [...document.querySelectorAll('.zp-row')].map(e => e.value) : []"></th>
      <th>Numer</th><th>Uczestnik</th><th>Grupa</th><th>Opiekun</th><th>Status</th><th>EZD</th><th>Złożono</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><input type="checkbox" class="form-check-input zp-row" name="ids[]" value="<?= (int)$r['id'] ?>" x-model="sel" aria-label="Zaznacz <?= h($r['registration_number']) ?>"></td>
        <td><a href="<?= h(route('nabor.show', ['id' => (int)$r['id']])) ?>"><?= h($r['registration_number']) ?></a><?= $r['is_test'] ? ' <span class="badge text-bg-warning">TEST</span>' : '' ?></td>
        <td><?= h($r['participant_name']) ?><?= $r['participant_school_class'] !== '' ? '<div class="small text-muted">' . h($r['participant_school_class']) . '</div>' : '' ?></td>
        <td><?= $r['is_custom_schedule'] && !$r['course_id'] ? '<span class="text-warning-emphasis">Termin do ustalenia</span>' : h((string)$r['course_name']) ?></td>
        <td><?= h($r['guardian_name']) ?><div class="small text-muted"><?= h($r['guardian_email']) ?> · +<?= h($r['guardian_phone']) ?></div></td>
        <td><?= naborStatusBadge($r['status']) ?></td>
        <td><?= $r['ezd_case_id'] ? '<a href="' . h(naborEzdUrl((int)$r['ezd_case_id'])) . '">' . h($r['znak_sprawy'] ?: '#' . $r['ezd_case_id']) . '</a>' : '<span class="text-muted">—</span>' ?></td>
        <td class="small text-nowrap"><?= h(naborTime($r['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">Brak zgłoszeń.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
