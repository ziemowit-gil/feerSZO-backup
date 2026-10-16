<?php
/**
 * modules/ti_dyd_group_picker/logic/dydGroupPicker.php — selektor grupy w górnym pasku panelu dydaktyka.
 *
 * Przycisk „GRUPA <nazwa>” otwiera okno dialogowe (natywny <dialog>, przenoszony do <body>, żeby nie dziedziczył
 * stylów granatowego paska): wyszukiwarka (nazwa grupy / prowadzący), filtr stanu Aktywne / Nieaktywne / Archiwum
 * z licznikami (liczą się po wyszukiwaniu — widać, że trafienie jest w innej zakładce) i — gdy kierownik ma własne
 * grupy — przełącznik Moje / Wszystkie. Wybór = ta sama zakładka w wybranej grupie.
 *
 * Domyślnie okno otwiera się na stanie i zakresie bieżącej grupy (zawsze jest widoczna, oznaczona aria-current).
 * Klawiatura: filtry to zwykłe radio (strzałki), ↓ z wyszukiwarki do listy, ↑/↓/Home/End po liście,
 * Enter w wyszukiwarce przy jednym trafieniu przechodzi do grupy, Esc zamyka i wraca fokusem na przycisk.
 *
 * Wołane z karty30/ti/dydaktyk/index.php (wynik trafia do paska jako 'notifications').
 */

/** Stan grupy dla filtra: 'active' | 'inactive' | 'archived' oraz etykieta do plakietki. */
function dydGroupPickerState(array $c): array
{
    $status = (string)($c['status'] ?? '');
    if ($status === 'archived') return ['archived', !empty($c['closed_at']) ? 'zamknięta' : 'archiwum'];
    if ($status === 'cancelled') return ['inactive', 'anulowana'];
    if (empty($c['is_active']))  return ['inactive', 'nieaktywna'];
    return ['active', ''];
}

/**
 * @param array $courses   grupy otwierane z panelu (mogą zawierać archiwalne — zostaną odsiane)
 * @param array $archived  grupy archiwalne
 * @param array $mineSet   [course_id => true] własne grupy kierownika (puste = bez przełącznika Moje/Wszystkie)
 * @param bool  $staff     kierownik / zastępca / admin — pokazuje kolumnę prowadzącego
 */
function dydGroupPickerRender(array $courses, array $archived, array $mineSet, bool $staff, int $cur, string $tab): string
{
    $rows = [];
    $seen = [];
    foreach (array_merge($courses, $archived) as $c) {
        $id = (int)$c['id'];
        if (isset($seen[$id])) continue;
        $seen[$id] = true;
        [$st, $label] = dydGroupPickerState($c);
        $rows[] = [
            'id' => $id, 'name' => (string)$c['name'], 'st' => $st, 'label' => $label,
            'mine' => $staff ? isset($mineSet[$id]) : true,
            'who' => (string)($c['instructor_name'] ?? ''), 'n' => (int)($c['enrolled_count'] ?? 0),
            'co' => !empty($c['is_co_instructor']),
        ];
    }
    // Moje przed cudzymi, w obrębie — kolejność z zapytania (alfabetyczna)
    usort($rows, fn($a, $b) => (int)$b['mine'] <=> (int)$a['mine']);

    $curRow = null;
    foreach ($rows as $r) if ($r['id'] === $cur) { $curRow = $r; break; }
    $split   = $staff && !empty($mineSet);
    $defSt   = $curRow['st'] ?? 'active';
    $defMine = $split && (!$curRow || $curRow['mine']);
    $stLabels = ['active' => 'Aktywne', 'inactive' => 'Nieaktywne', 'archived' => 'Archiwum'];
    $stIcons  = ['active' => 'people', 'inactive' => 'pause-circle', 'archived' => 'archive'];

    ob_start(); ?>
<div class="dyd-cs">
  <?php $_cs_lbl = 'Grupa: ' . ($curRow ? $curRow['name'] : 'nie wybrano') . ' — zmień grupę'; ?>
  <button type="button" class="dyd-cs-btn dyd-cs-icon" aria-haspopup="dialog" aria-controls="dydGp" aria-expanded="false" title="<?= h($_cs_lbl) ?>">
    <i class="bi bi-collection" aria-hidden="true"></i><i class="bi bi-chevron-down dyd-cs-chev" aria-hidden="true"></i>
    <span class="visually-hidden"><?= h($_cs_lbl) ?></span>
  </button>
  <dialog id="dydGp" class="dgp" aria-labelledby="dydGpTitle">
    <div class="dgp-head">
      <h2 id="dydGpTitle" class="dgp-title">Zmień grupę</h2>
      <button type="button" class="dgp-x" data-dgp-close aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dgp-tools">
      <label for="dydGpQ" class="visually-hidden">Szukaj grupy lub prowadzącego</label>
      <input type="search" id="dydGpQ" class="form-control dgp-q" placeholder="Szukaj grupy lub prowadzącego…" autocomplete="off" aria-describedby="dydGpCount">
      <fieldset class="dgp-seg">
        <legend class="visually-hidden">Stan grupy</legend>
        <?php foreach ($stLabels as $k => $lbl): ?>
        <input type="radio" class="dgp-radio" name="dgp_st" id="dgpSt_<?= $k ?>" value="<?= $k ?>"<?= $k === $defSt ? ' checked' : '' ?>>
        <label for="dgpSt_<?= $k ?>"><i class="bi bi-<?= $stIcons[$k] ?>" aria-hidden="true"></i><?= $lbl ?> <span class="dgp-n" data-dgp-n="<?= $k ?>">0</span></label>
        <?php endforeach; ?>
      </fieldset>
      <?php if ($split): ?>
      <fieldset class="dgp-seg dgp-seg-sm">
        <legend class="visually-hidden">Zakres</legend>
        <input type="radio" class="dgp-radio" name="dgp_mine" id="dgpMine1" value="1"<?= $defMine ? ' checked' : '' ?>>
        <label for="dgpMine1">Moje grupy</label>
        <input type="radio" class="dgp-radio" name="dgp_mine" id="dgpMine0" value="0"<?= $defMine ? '' : ' checked' ?>>
        <label for="dgpMine0">Wszystkie</label>
      </fieldset>
      <?php endif; ?>
    </div>
    <p id="dydGpCount" class="dgp-count" role="status" aria-live="polite"></p>
    <ul class="dgp-list" aria-label="Grupy">
      <?php foreach ($rows as $r):
          $act  = $r['id'] === $cur;
          $who  = $staff ? ($r['mine'] && $split ? 'ja' : ($r['who'] !== '' ? $r['who'] : '—')) : ($r['co'] ? 'współprowadzę' : '');
          $srch = mb_strtolower($r['name'] . ' ' . $r['who']); ?>
      <li data-st="<?= $r['st'] ?>" data-mine="<?= $r['mine'] ? 1 : 0 ?>" data-search="<?= h($srch) ?>">
        <a class="dgp-item<?= $act ? ' is-cur' : '' ?><?= $r['st'] !== 'active' ? ' is-off' : '' ?>" href="index.php?course=<?= $r['id'] ?>&amp;tab=<?= h($tab) ?>"<?= $act ? ' aria-current="page"' : '' ?>>
          <i class="bi bi-<?= $act ? 'check2' : ($r['label'] === 'zamknięta' ? 'lock' : $stIcons[$r['st']]) ?> dgp-ico" aria-hidden="true"></i>
          <span class="dgp-name"><?= h($r['name']) ?><?= $act ? '<span class="visually-hidden"> (bieżąca grupa)</span>' : '' ?></span>
          <?php if ($who !== ''): ?><span class="dgp-who"><?= h($who) ?></span><?php endif; ?>
          <span class="dgp-meta"><?= $r['label'] !== '' ? '<span class="dgp-badge">' . h($r['label']) . '</span> ' : '' ?><?= $r['n'] ?> os.</span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="dgp-empty" hidden>Brak grup w tym widoku. Sprawdź inne zakładki stanu<?= $split ? ' lub zakres „Wszystkie”' : '' ?>.</p>
  </dialog>
</div>
<style>
.dgp { width: min(46rem, calc(100vw - 2rem)); max-height: min(44rem, calc(100vh - 2rem)); padding: 0; border: 1px solid #a8bcd2;
  border-radius: 2px; background: #fff; color: #1d2733; box-shadow: 0 .75rem 2rem rgba(16,51,92,.3); font-size: .875rem; }
.dgp[open] { display: flex; flex-direction: column; }
.dgp::backdrop { background: rgba(16,51,92,.45); }
.dgp-head { display: flex; align-items: center; justify-content: space-between; padding: .6rem .9rem; background: #10335c; color: #fff; }
.dgp-title { font-size: 1rem; font-weight: 700; margin: 0; }
.dgp-x { background: transparent; border: 0; color: #fff; padding: .2rem .4rem; line-height: 1; }
.dgp-x:focus-visible { outline: 2px solid #ffd863; outline-offset: 1px; }
.dgp-tools { display: flex; flex-wrap: wrap; gap: .5rem; padding: .75rem .9rem .5rem; border-bottom: 1px solid #dde6f0; }
.dgp-q { flex: 1 1 100%; }
.dgp-seg { display: inline-flex; flex-wrap: wrap; margin: 0; padding: 0; border: 0; min-width: 0; }
.dgp-radio { position: absolute; opacity: 0; width: 1px; height: 1px; }
.dgp-seg label { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .7rem; border: 1px solid #a8bcd2; margin-left: -1px;
  background: #fff; color: #10335c; cursor: pointer; font-weight: 600; font-size: .8rem; }
.dgp-seg label:first-of-type { margin-left: 0; }
.dgp-radio:checked + label { background: #10335c; color: #fff; border-color: #10335c; }
.dgp-radio:focus-visible + label { outline: 3px solid #ffbf47; outline-offset: 1px; position: relative; z-index: 1; }
.dgp-n { display: inline-block; min-width: 1.4rem; padding: 0 .3rem; border-radius: 2px; background: #dde6f0; color: #10335c; text-align: center; font-size: .72rem; }
.dgp-radio:checked + label .dgp-n { background: #ffd863; color: #10335c; }
.dgp-seg-sm { margin-left: auto; }
.dgp-count { margin: 0; padding: .35rem .9rem; font-size: .75rem; color: #4a5566; }
.dgp-list { list-style: none; margin: 0; padding: 0 0 .4rem; overflow-y: auto; flex: 1 1 auto; min-height: 6rem; }
.dgp-item { display: grid; grid-template-columns: 1.1rem minmax(0, 1fr) auto auto; align-items: center; gap: .6rem;
  padding: .45rem .9rem; color: #10335c; text-decoration: none; border-left: 3px solid transparent; }
.dgp-item:hover { background: #f0f4f9; color: #10335c; }
.dgp-item:focus-visible { outline: 3px solid #ffbf47; outline-offset: -3px; background: #f0f4f9; }
.dgp-item.is-cur { background: #dde6f0; border-left-color: #10335c; font-weight: 700; }
.dgp-item.is-off .dgp-name { color: #4a5566; }
.dgp-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dgp-who { color: #4a5566; font-size: .78rem; max-width: 14rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dgp-meta { color: #4a5566; font-size: .75rem; white-space: nowrap; text-align: right; }
.dgp-badge { display: inline-block; padding: 0 .35rem; border: 1px solid #a8bcd2; border-radius: 2px; font-size: .68rem; }
.dgp-empty { margin: 0; padding: 1rem .9rem; color: #4a5566; }
@media (max-width: 575.98px) {
  .dgp-item { grid-template-columns: 1.1rem minmax(0, 1fr) auto; }
  .dgp-who { grid-column: 2; grid-row: 2; }
  .dgp-seg-sm { margin-left: 0; }
}
</style>
<script>
(function () {
  var root = document.currentScript.previousElementSibling.previousElementSibling;
  var btn = root.querySelector('.dyd-cs-btn'), dlg = root.querySelector('dialog');
  if (!dlg || typeof dlg.showModal !== 'function') {   // bardzo stara przeglądarka — lista jako zwykły panel
    btn.addEventListener('click', function () { dlg.toggleAttribute('open'); }); return;
  }
  document.body.appendChild(dlg);   // poza granatowym paskiem: bez dziedziczenia kolorów/czcionek nagłówka
  var q = dlg.querySelector('.dgp-q'), lis = Array.prototype.slice.call(dlg.querySelectorAll('.dgp-list > li')),
      cnt = dlg.querySelector('.dgp-count'), empty = dlg.querySelector('.dgp-empty');
  var stNames = {active: 'aktywnych', inactive: 'nieaktywnych', archived: 'w archiwum'};
  function val(name) { var r = dlg.querySelector('input[name="' + name + '"]:checked'); return r ? r.value : null; }
  function apply() {
    var t = q.value.trim().toLowerCase(), st = val('dgp_st'), mine = val('dgp_mine'), n = {active: 0, inactive: 0, archived: 0}, shown = 0;
    lis.forEach(function (li) {
      var hit = (!t || li.dataset.search.indexOf(t) !== -1) && (mine !== '1' || li.dataset.mine === '1');
      if (hit) n[li.dataset.st]++;
      li.hidden = !(hit && li.dataset.st === st);
      if (!li.hidden) shown++;
    });
    Object.keys(n).forEach(function (k) { dlg.querySelector('[data-dgp-n="' + k + '"]').textContent = n[k]; });
    cnt.textContent = 'Pokazano ' + shown + ' ' + (shown === 1 ? 'grupę' : (shown % 10 >= 2 && shown % 10 <= 4 && (shown % 100 < 10 || shown % 100 >= 20) ? 'grupy' : 'grup'))
      + ' ' + stNames[st] + (mine === '1' ? ' (moje)' : '') + (t ? ' pasujących do „' + q.value.trim() + '”' : '') + '.';
    empty.hidden = shown > 0;
  }
  function visible() { return lis.filter(function (li) { return !li.hidden; }).map(function (li) { return li.firstElementChild; }); }
  btn.addEventListener('click', function () {
    apply(); dlg.showModal(); btn.setAttribute('aria-expanded', 'true');
    var c = dlg.querySelector('.dgp-item.is-cur'); if (c && !c.parentElement.hidden) c.scrollIntoView({block: 'nearest'});
    q.focus();
  });
  dlg.addEventListener('close', function () { btn.setAttribute('aria-expanded', 'false'); btn.focus(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });   // klik w tło
  dlg.querySelector('[data-dgp-close]').addEventListener('click', function () { dlg.close(); });
  dlg.querySelectorAll('.dgp-radio').forEach(function (r) { r.addEventListener('change', apply); });
  q.addEventListener('input', apply);
  q.addEventListener('keydown', function (e) {
    var v = visible();
    if (e.key === 'ArrowDown' && v.length) { e.preventDefault(); v[0].focus(); }
    if (e.key === 'Enter') { e.preventDefault(); if (v.length === 1) location.href = v[0].href; }
  });
  dlg.querySelector('.dgp-list').addEventListener('keydown', function (e) {
    var v = visible(), i = v.indexOf(document.activeElement);
    if (i < 0) return;
    var to = e.key === 'ArrowDown' ? i + 1 : e.key === 'ArrowUp' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? v.length - 1 : null;
    if (to === null) return;
    e.preventDefault();
    if (to < 0) { q.focus(); return; }
    v[Math.min(to, v.length - 1)].focus();
  });
})();
</script>
<?php
    return (string)ob_get_clean();
}
