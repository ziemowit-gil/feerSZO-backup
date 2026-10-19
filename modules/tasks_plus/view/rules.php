<?php
/**
 * modules/tasks_plus/view/rules.php — Reguły automatyzacji obszaru.
 * Wejście: $leaderWs, $wsId, $rules, $opt (lists, people, tags).
 */
$opts = fn(array $rows, string $empty = '') => ($empty !== '' ? '<option value="">' . h($empty) . '</option>' : '')
    . implode('', array_map(fn($r) => '<option value="' . (int)$r['id'] . '">' . h($r['name']) . '</option>', $rows));
?>
<style>
html body #tsk-main .trl-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:.75rem;align-items:end}
html body #tsk-main .trl-form .tp-in{width:100%}
html body #tsk-main .trl-val[hidden]{display:none}
html body #tsk-main .trl-step{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--tsk-green);margin:.2rem 0 .3rem}
html body #tsk-main tr.is-off td{opacity:.55}
</style>

<div class="tp-head">
  <div>
    <div class="tp-kicker">Zadania · ustawienia lidera</div>
    <h1 class="tp-title">Reguły automatyzacji</h1>
    <p class="tp-lead">„Gdy … → zrób …” — reguły działają w jednym obszarze, w kolejności dodania. Zmiany wykonane przez regułę
      nie uruchamiają kolejnych reguł. Każde zadziałanie widać w historii zadania.</p>
  </div>
</div>

<?php if (!$leaderWs): ?>
<div class="tp-empty">Reguły automatyzacji ustawia lider obszaru (rola lider/edytor w obszarze).</div>
<?php return; endif; ?>

<form class="tp-bar" method="get" action="<?= h(route('rules.index')) ?>">
  <div>
    <label class="tp-l" for="trl-ws">Obszar</label>
    <select class="tp-in" id="trl-ws" name="ws" onchange="this.form.submit()">
      <?php foreach ($leaderWs as $w): ?>
      <option value="<?= (int)$w['id'] ?>" <?= $wsId === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="tp-btn" type="submit">Pokaż</button></noscript>
</form>

<h2 class="tp-section">Reguły obszaru</h2>
<?php if (!$rules): ?>
<div class="tp-empty">Brak reguł. Dodaj pierwszą poniżej — np. „Gdy zadanie trafi do kolumny »Do weryfikacji« → powiadom liderów”.</div>
<?php else: ?>
<div class="tp-scroll">
<table class="tp-table">
  <thead><tr><th scope="col">Reguła</th><th scope="col">Działanie</th><th scope="col" class="num">Zadziałała</th><th scope="col">Stan</th><th scope="col"><span class="tp-sr">Akcje</span></th></tr></thead>
  <tbody>
  <?php foreach ($rules as $r): ?>
    <tr class="<?= (int)$r['is_active'] ? '' : 'is-off' ?>">
      <th scope="row" style="font-weight:600"><?= h($r['name']) ?></th>
      <td><?= h(tasksRulesDescribe($r, $opt)) ?></td>
      <td class="num"><?= (int)$r['fire_count'] ?>×<?= $r['last_fired_at'] ? '<div class="tp-muted">' . h(date('d.m.Y H:i', strtotime($r['last_fired_at']))) . '</div>' : '' ?></td>
      <td><span class="tp-tag <?= (int)$r['is_active'] ? 'is-green' : '' ?>"><?= (int)$r['is_active'] ? 'włączona' : 'wyłączona' ?></span></td>
      <td style="white-space:nowrap">
        <form method="post" action="<?= h(route('rules.toggle', ['id' => $r['id']])) ?>" style="display:inline"><?= Router::csrfField() ?>
          <button class="tp-btn tp-btn--ghost tp-btn--sm" type="submit"><?= (int)$r['is_active'] ? 'Wyłącz' : 'Włącz' ?><span class="tp-sr"> regułę <?= h($r['name']) ?></span></button></form>
        <form method="post" action="<?= h(route('rules.delete', ['id' => $r['id']])) ?>" style="display:inline"
              onsubmit="return confirm('Usunąć regułę „<?= h(addslashes($r['name'])) ?>”?')"><?= Router::csrfField() ?>
          <button class="tp-btn tp-btn--danger tp-btn--sm" type="submit">Usuń<span class="tp-sr"> regułę <?= h($r['name']) ?></span></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2 class="tp-section" id="nowa">Nowa reguła</h2>
<form class="tp-group" method="post" action="<?= h(route('rules.store')) ?>" id="trl-new"><?= Router::csrfField() ?>
  <input type="hidden" name="ws" value="<?= (int)$wsId ?>">
  <div class="trl-step">1. Gdy</div>
  <div class="trl-form">
    <div><label class="tp-l" for="trl-trigger">Wyzwalacz</label>
      <select class="tp-in" id="trl-trigger" name="trigger_type" required>
        <?php foreach (TASKS_RULE_TRIGGERS as $k => $lab): ?><option value="<?= h($k) ?>"><?= h($lab) ?></option><?php endforeach; ?>
      </select></div>
    <div class="trl-when" data-for="moved"><label class="tp-l" for="trl-list">Kolumna</label>
      <select class="tp-in" id="trl-list" name="trigger_list_id"><?= $opts($opt['lists'], 'dowolna kolumna') ?></select></div>
    <div><label class="tp-l" for="trl-prio">Tylko priorytet</label>
      <select class="tp-in" id="trl-prio" name="cond_priority"><option value="0">dowolny</option>
        <?php foreach ([4, 3, 2, 1] as $p): ?><option value="<?= $p ?>"><?= h(tasksPlusPriorityLabel($p)) ?></option><?php endforeach; ?>
      </select></div>
  </div>
  <div class="trl-step" style="margin-top:.9rem">2. Zrób</div>
  <div class="trl-form">
    <div><label class="tp-l" for="trl-action">Akcja</label>
      <select class="tp-in" id="trl-action" name="action_type" required>
        <?php foreach (TASKS_RULE_ACTIONS as $k => $lab): ?><option value="<?= h($k) ?>"><?= h($lab) ?></option><?php endforeach; ?>
      </select></div>
    <div class="trl-val" data-for="assign"><label class="tp-l" for="trl-v-assign">Osoba do przypisania</label>
      <select class="tp-in" id="trl-v-assign" name="value_assign"><?= $opts($opt['people'], '— wybierz —') ?></select></div>
    <div class="trl-val" data-for="set_priority"><label class="tp-l" for="trl-v-prio">Priorytet</label>
      <select class="tp-in" id="trl-v-prio" name="value_set_priority"><?php foreach ([4, 3, 2, 1] as $p): ?><option value="<?= $p ?>"><?= h(tasksPlusPriorityLabel($p)) ?></option><?php endforeach; ?></select></div>
    <div class="trl-val" data-for="notify"><label class="tp-l" for="trl-v-notify">Kogo powiadomić</label>
      <select class="tp-in" id="trl-v-notify" name="value_notify"><option value="leaders">Liderów obszaru</option><option value="assignees">Wykonawców zadania</option>
        <optgroup label="Osoba"><?= $opts($opt['people']) ?></optgroup></select></div>
    <div class="trl-val" data-for="move"><label class="tp-l" for="trl-v-move">Kolumna docelowa</label>
      <select class="tp-in" id="trl-v-move" name="value_move"><?= $opts($opt['lists'], '— wybierz —') ?></select></div>
    <div class="trl-val" data-for="set_due"><label class="tp-l" for="trl-v-due">Termin za (dni)</label>
      <input class="tp-in" id="trl-v-due" name="value_set_due" type="number" min="0" max="365" value="7"></div>
    <div class="trl-val" data-for="watch"><label class="tp-l" for="trl-v-watch">Obserwujący</label>
      <select class="tp-in" id="trl-v-watch" name="value_watch"><?= $opts($opt['people'], '— wybierz —') ?></select></div>
    <div class="trl-val" data-for="tag"><label class="tp-l" for="trl-v-tag">Tag</label>
      <select class="tp-in" id="trl-v-tag" name="value_tag"><?= $opts($opt['tags'], $opt['tags'] ? '— wybierz —' : 'brak tagów w obszarze') ?></select></div>
  </div>
  <div class="trl-form" style="margin-top:.9rem">
    <div style="grid-column:1/-1"><label class="tp-l" for="trl-name">Nazwa reguły (opcjonalnie)</label>
      <input class="tp-in" id="trl-name" name="name" maxlength="120" placeholder="np. Weryfikacja → powiadom liderów"></div>
  </div>
  <button class="tp-btn" type="submit" style="margin-top:.9rem"><i class="bi bi-plus-lg" aria-hidden="true"></i>Dodaj regułę</button>
</form>
<script>
(function () {
  var f = document.getElementById('trl-new');
  if (!f) return;
  function sync() {
    var t = f.trigger_type.value, a = f.action_type.value;
    f.querySelectorAll('.trl-when').forEach(function (el) { el.hidden = el.dataset.for !== t; });
    f.querySelectorAll('.trl-val').forEach(function (el) { el.hidden = el.dataset.for !== a; });
  }
  f.trigger_type.addEventListener('change', sync);
  f.action_type.addEventListener('change', sync);
  sync();
})();
</script>
