<?php
/**
 * tasks/view/task_new.php — formularz „Nowe zadanie” (z e-maila lub z karty powiązanego rekordu).
 * Wejście: $targets (obszary lidera z kolumnami i osobami), $mail (wiadomość albo null), $existing (zadanie już
 * utworzone z tej wiadomości), $atts (załączniki), $prefill (title, description, ws), $src, $back,
 * opcjonalnie $linkLabel/$linkSpec (powiązanie — dodawane przy powiązaniach).
 */
$linkSpec = $linkSpec ?? '';
$linkLabel = $linkLabel ?? '';
$wsSel = isset($targets[$prefill['ws']]) ? $prefill['ws'] : (int)(array_key_first($targets) ?? 0);
$fmtSize = fn($b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' kB';
?>
<style>
html body #tsk-main .tnw{display:grid;grid-template-columns:minmax(0,2fr) minmax(16rem,1fr);gap:1rem;align-items:start}
@media (max-width: 900px){html body #tsk-main .tnw{grid-template-columns:1fr}}
html body #tsk-main .tnw .tp-in{width:100%}
html body #tsk-main .tnw-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem;margin-bottom:.75rem}
html body #tsk-main .tnw-field{margin-bottom:.75rem}
html body #tsk-main .tnw textarea.tp-in{min-height:16rem;font-family:inherit;line-height:1.45}
html body #tsk-main .tnw-people{max-height:13rem;overflow:auto;background:#fff;border:2px solid var(--tm-field);padding:.35rem .5rem}
html body #tsk-main .tnw-people label{display:flex;gap:.4rem;align-items:center;padding:.15rem 0;font-size:.9rem}
html body #tsk-main .tnw-side h2{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--tm-muted);margin:0 0 .4rem}
html body #tsk-main .tnw-side dl{margin:0 0 .75rem;font-size:.88rem}
html body #tsk-main .tnw-side dt{color:var(--tm-muted);font-weight:400;font-size:.75rem}
html body #tsk-main .tnw-side dd{margin:0 0 .4rem;word-break:break-word}
html body #tsk-main .tnw-att label{display:flex;gap:.4rem;align-items:flex-start;font-size:.88rem;padding:.2rem 0}
</style>

<div class="tp-head">
  <div>
    <div class="tp-kicker">Zadania · <?= $mail ? 'z wiadomości e-mail' : ($linkLabel !== '' ? 'powiązane' : 'nowe') ?></div>
    <h1 class="tp-title">Nowe zadanie</h1>
  </div>
  <?php if ($back !== ''): ?><a class="tp-btn tp-btn--ghost" href="<?= h($back) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i><?= $mail ? 'Wróć do wiadomości' : 'Wróć' ?></a><?php endif; ?>
</div>

<?php if ($existing): ?>
<div class="tp-group" role="status">
  Z tej wiadomości utworzono już zadanie <a href="<?= h(APP_URL . '/tasks/index.php?ws=' . (int)$existing['workspace_id'] . '&task=' . (int)$existing['task_id']) ?>">
  #<?= (int)$existing['task_id'] ?> „<?= h($existing['title']) ?>”</a>.
</div>
<?php return; endif; ?>

<?php if (!$targets): ?>
<div class="tp-empty">Zadania zakłada lider obszaru — nie jesteś liderem ani edytorem żadnego obszaru z kolumnami.</div>
<?php return; endif; ?>

<form method="post" action="<?= h(route('new.store')) ?>" class="tnw">
  <?= Router::csrfField() ?>
  <?php if ($mail): ?><input type="hidden" name="comm" value="<?= (int)$mail['id'] ?>"><input type="hidden" name="src" value="<?= h($src) ?>"><?php endif; ?>
  <?php if ($linkSpec !== ''): ?><input type="hidden" name="link" value="<?= h($linkSpec) ?>"><?php endif; ?>
  <div class="tp-group">
    <div class="tnw-row">
      <div><label class="tp-l" for="tnw-ws">Obszar</label>
        <select class="tp-in" id="tnw-ws" name="workspace_id" required>
          <?php foreach ($targets as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $wsSel === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="tp-l" for="tnw-list">Kolumna</label><select class="tp-in" id="tnw-list" name="list_id" required></select></div>
      <div><label class="tp-l" for="tnw-prio">Priorytet</label>
        <select class="tp-in" id="tnw-prio" name="priority"><?php foreach ([1, 2, 3, 4] as $p): ?><option value="<?= $p ?>" <?= $p === 2 ? 'selected' : '' ?>><?= h(tasksPlusPriorityLabel($p)) ?></option><?php endforeach; ?></select></div>
      <div><label class="tp-l" for="tnw-due">Termin</label><input class="tp-in" type="date" id="tnw-due" name="due_date"></div>
    </div>
    <div class="tnw-field"><label class="tp-l" for="tnw-title">Tytuł</label>
      <input class="tp-in" id="tnw-title" name="title" required maxlength="300" value="<?= h($prefill['title']) ?>"></div>
    <div class="tnw-field"><label class="tp-l" for="tnw-desc">Opis</label>
      <textarea class="tp-in" id="tnw-desc" name="description"><?= h($prefill['description']) ?></textarea></div>
    <fieldset class="tnw-field" style="border:0;padding:0;margin:0 0 .75rem">
      <legend class="tp-l">Wykonawcy</legend>
      <div class="tnw-people" id="tnw-people"></div>
    </fieldset>
    <button class="tp-btn" type="submit"><i class="bi bi-plus-lg" aria-hidden="true"></i>Utwórz zadanie</button>
  </div>

  <aside class="tnw-side">
    <?php if ($linkLabel !== ''): ?>
    <div class="tp-group"><h2>Powiązanie</h2><p style="margin:0"><?= h($linkLabel) ?></p></div>
    <?php endif; ?>
    <?php if ($mail): ?>
    <div class="tp-group">
      <h2>Wiadomość</h2>
      <dl>
        <dt>Od</dt><dd><?= h(trim($mail['from_name'] . ' <' . $mail['from_email'] . '>')) ?></dd>
        <dt>Otrzymano</dt><dd><?= h(date('d.m.Y H:i', strtotime((string)$mail['sent_at']))) ?></dd>
        <dt>Temat</dt><dd><?= h($mail['subject']) ?></dd>
      </dl>
      <a href="<?= h($back) ?>">Otwórz wiadomość</a>
      <?php if ($atts): ?>
      <fieldset class="tnw-att" style="border:0;padding:0;margin:.75rem 0 0">
        <legend class="tp-l">Załączniki do zadania</legend>
        <?php foreach ($atts as $a): ?>
        <label><input type="checkbox" name="att[]" value="<?= (int)$a['id'] ?>" checked>
          <span><?= h($a['original_name']) ?> <span class="tp-muted">(<?= h($fmtSize((int)$a['size_bytes'])) ?><?= $a['stored_path'] ? '' : ', pobierany ze skrzynki' ?>)</span></span></label>
        <?php endforeach; ?>
        <p class="tp-muted" style="margin:.3rem 0 0">Pliki niedozwolonego typu (np. .exe) zostaną pominięte.</p>
      </fieldset>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </aside>
</form>
<script>
(function () {
  var T = <?= json_encode(array_map(fn($w) => ['lists' => $w['lists'], 'people' => $w['people']], $targets), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var ws = document.getElementById('tnw-ws'), list = document.getElementById('tnw-list'), ppl = document.getElementById('tnw-people');
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function sync() {
    var t = T[ws.value] || {lists: [], people: []};
    list.innerHTML = t.lists.map(function (l) { return '<option value="' + l.id + '">' + esc(l.name) + '</option>'; }).join('');
    ppl.innerHTML = t.people.length ? t.people.map(function (p) {
      return '<label><input type="checkbox" name="assignees[]" value="' + p.id + '"> ' + esc(p.name) + '</label>';
    }).join('') : '<span class="tp-muted">Brak członków obszaru.</span>';
  }
  ws.addEventListener('change', sync);
  sync();
})();
</script>
