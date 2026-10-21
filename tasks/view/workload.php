<?php
/**
 * tasks/view/workload.php — Obciążenie zespołu.
 * Wejście: $rep (people, weeks, scope), $f (ws, from, to), $workspaces (obszary do filtra).
 */
$people = $rep['people'];
$fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 1, ',', ' '), '0'), ',') ?: '0';
$maxEst = max(1, ...array_map(fn($p) => (float)$p['est'], $people ?: [['est' => 1]]));
$sum = fn($k) => array_sum(array_column($people, $k));
$maxCell = 1;
foreach ($people as $p) foreach ($p['weeks'] as $w) $maxCell = max($maxCell, $w['h'] ?: $w['n']);
$csvUrl = route('work.csv', array_filter($f));
?>
<style>
html body #tsk-main .twl-heat td.cell{text-align:center;font-variant-numeric:tabular-nums;white-space:nowrap;min-width:5.5rem}
html body #tsk-main .twl-heat td.cell small{display:block;color:inherit;opacity:.8}
html body #tsk-main details.twl-more summary{cursor:pointer;color:var(--tsk-text);font-weight:600}
html body #tsk-main details.twl-more ul{list-style:none;margin:.4rem 0 0;padding:0}
html body #tsk-main details.twl-more li{padding:.25rem 0;border-top:1px solid var(--tm-line);font-size:.85rem}
</style>

<div class="tp-head">
  <div>
    <div class="tp-kicker">Zadania · raport lidera</div>
    <h1 class="tp-title">Obciążenie zespołu</h1>
    <p class="tp-lead"><?= $rep['scope'] === 'team'
        ? 'Wykonawcy zadań w obszarach, w których jesteś liderem.'
        : 'Twoje zadania — pełny raport zespołu widzą liderzy obszarów.' ?>
      Szacunek zadania z kilkoma wykonawcami dzielony jest po równo. Rozkład tygodniowy według terminu (zaległe — w bieżącym tygodniu).</p>
  </div>
  <a class="tp-btn tp-btn--ghost" href="<?= h($csvUrl) ?>"><i class="bi bi-filetype-csv" aria-hidden="true"></i>Eksport CSV</a>
</div>

<form class="tp-bar" method="get" action="<?= h(route('work.index')) ?>" aria-label="Filtry raportu"><?= route_field('work.index') ?>
  <div>
    <label class="tp-l" for="twl-ws">Obszar</label>
    <select class="tp-in" id="twl-ws" name="ws">
      <option value="">Wszystkie</option>
      <?php foreach ($workspaces as $w): ?>
      <option value="<?= (int)$w['id'] ?>" <?= (int)$f['ws'] === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label class="tp-l" for="twl-from">Okres od</label><input class="tp-in" type="date" id="twl-from" name="from" value="<?= h($f['from']) ?>"></div>
  <div><label class="tp-l" for="twl-to">do</label><input class="tp-in" type="date" id="twl-to" name="to" value="<?= h($f['to']) ?>"></div>
  <button class="tp-btn" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i>Pokaż</button>
</form>

<div class="tp-tiles" role="list">
  <div role="listitem" class="tp-tile is-dark"><b><?= count($people) ?></b><span>Osób</span></div>
  <div role="listitem" class="tp-tile"><b><?= (int)$sum('open') ?></b><span>Otwartych przypisań</span></div>
  <div role="listitem" class="tp-tile is-red"><b><?= (int)$sum('overdue') ?></b><span>Po terminie</span></div>
  <div role="listitem" class="tp-tile is-blue"><b><?= h($fmt($sum('est'))) ?></b><span>Godz. szacowanych (otwarte)</span></div>
  <div role="listitem" class="tp-tile is-orange"><b><?= h($fmt($sum('logged'))) ?></b><span>Godz. zalogowanych w okresie</span></div>
  <div role="listitem" class="tp-tile is-dark"><b><?= (int)$sum('done') ?></b><span>Ukończonych w okresie</span></div>
</div>

<?php if (!$people): ?>
<div class="tp-empty">Brak przypisanych zadań w wybranym zakresie.</div>
<?php return; endif; ?>

<h2 class="tp-section">Osoby</h2>
<div class="tp-scroll">
<table class="tp-table">
  <thead><tr>
    <th scope="col">Osoba</th><th scope="col" class="num">Otwarte</th><th scope="col" class="num">Po terminie</th>
    <th scope="col" class="num">W tym tyg.</th><th scope="col" class="num">Bez terminu</th>
    <th scope="col">Szacowane godz.</th><th scope="col" class="num">Zalogowane</th><th scope="col" class="num">Ukończone</th>
  </tr></thead>
  <tbody>
  <?php foreach ($people as $p): $pct = min(100, round($p['est'] / $maxEst * 100)); ?>
    <tr>
      <th scope="row" style="font-weight:600">
        <?= h($p['name']) ?><?= $p['active'] ? '' : ' <span class="tp-tag">nieaktywne konto</span>' ?>
        <?php if ($p['tasks']): ?>
        <details class="twl-more"><summary><span class="tp-muted">Zadania (<?= count($p['tasks']) ?>)</span></summary>
          <ul>
          <?php foreach ($p['tasks'] as $t): ?>
            <li><a href="<?= h(tasksPlusTaskUrl((int)$t['id'])) ?>"><?= h($t['title']) ?></a>
              <span class="tp-muted"> · <?= h($t['ws_name']) ?> · <?= h($t['list_name']) ?>
              <?= $t['due_date'] ? ' · termin ' . h(task_due_label($t, 'd.m.Y')) : ' · bez terminu' ?>
              <?= $t['share'] !== null ? ' · ' . h($fmt($t['share'])) . ' godz.' : '' ?></span>
              <?= task_is_overdue($t) ? '<span class="tp-tag is-red">po terminie</span>' : '' ?></li>
          <?php endforeach; ?>
          </ul>
        </details>
        <?php endif; ?>
      </th>
      <td class="num"><?= (int)$p['open'] ?></td>
      <td class="num"><?= $p['overdue'] ? '<span class="tp-tag is-red">' . (int)$p['overdue'] . '</span>' : '0' ?></td>
      <td class="num"><?= (int)$p['this_week'] ?></td>
      <td class="num"><?= (int)$p['no_due'] ?></td>
      <td style="min-width:11rem">
        <div style="display:flex;align-items:center;gap:.5rem">
          <div class="tp-meter" style="flex:1" role="img" aria-label="<?= h($fmt($p['est'])) ?> godzin, <?= $pct ?>% największego obciążenia"><i style="width:<?= $pct ?>%"></i></div>
          <span class="num"><?= h($fmt($p['est'])) ?></span>
        </div>
        <?php if ($p['no_est']): ?><div class="tp-muted">bez szacunku: <?= (int)$p['no_est'] ?></div><?php endif; ?>
      </td>
      <td class="num"><?= h($fmt($p['logged'])) ?> godz.</td>
      <td class="num"><?= (int)$p['done'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2 class="tp-section">Najbliższe tygodnie</h2>
<p class="tp-muted">Zadania / godziny szacowane z terminem w danym tygodniu. Ciemniejsze pole — większe obciążenie.</p>
<div class="tp-scroll">
<table class="tp-table twl-heat">
  <thead><tr><th scope="col">Osoba</th><?php foreach ($rep['weeks'] as $i => $w): ?>
    <th scope="col" style="text-align:center"><?= $i === 0 ? 'Ten tydzień' : 'Tydz.' ?><br><span style="font-weight:400"><?= h($w['label']) ?></span></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($people as $p): ?>
    <tr><th scope="row" style="font-weight:600"><?= h($p['name']) ?></th>
    <?php foreach ($p['weeks'] as $w):
        $v = $w['h'] ?: $w['n'];
        $a = $v ? round(0.15 + 0.85 * min(1, $v / $maxCell), 2) : 0;
        $style = $a ? 'background:rgba(0,122,0,' . $a . ');color:' . ($a > .55 ? '#fff' : 'var(--tsk-text)') : ''; ?>
      <td class="cell" style="<?= $style ?>"><?= $w['n'] ? (int)$w['n'] . ' zad.<small>' . h($fmt($w['h'])) . ' godz.</small>' : '<span class="tp-muted">—</span>' ?></td>
    <?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
