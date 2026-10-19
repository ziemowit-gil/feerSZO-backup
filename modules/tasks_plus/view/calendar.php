<?php
/**
 * modules/tasks_plus/view/calendar.php — Kalendarz zadań (Metro).
 * Wejście: $view (month|agenda), $year, $month, $f (ws, mine, done), $weeks, $items (Y-m-d => zadania),
 * $workspaces, $icalUrl (null = link wyłączony).
 */
$q = fn(array $over = []) => route('cal.index') . '?' . http_build_query(array_filter(array_merge([
    'view' => $view, 'm' => sprintf('%04d-%02d', $year, $month),
    'ws' => $f['ws'] ?: null, 'mine' => $f['mine'] ? 1 : null, 'done' => $f['done'] ? 1 : null,
], $over), fn($v) => $v !== null && $v !== ''));
$prev = date('Y-m', strtotime(sprintf('%04d-%02d-01 -1 month', $year, $month)));
$next = date('Y-m', strtotime(sprintf('%04d-%02d-01 +1 month', $year, $month)));
$today = date('Y-m-d');
$dayNames = ['Poniedziałek', 'Wtorek', 'Środa', 'Czwartek', 'Piątek', 'Sobota', 'Niedziela'];
$dayShort = ['Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'];
$total = array_sum(array_map('count', $items));
$chip = function (array $t): string {
    $time = task_normalize_due_time($t['due_time'] ?? '');
    $cls = 'tcal-chip' . ($t['overdue'] ? ' is-overdue' : '') . (!empty($t['completed_at']) ? ' is-done' : '');
    $label = ($t['is_start'] ? 'Start: ' : '') . $t['title'];
    $sr = ' (' . $t['ws_name'] . ', ' . $t['list_name'] . ($t['overdue'] ? ', po terminie' : '')
        . (!empty($t['completed_at']) ? ', ukończone' : '') . ')';
    return '<a class="' . $cls . '" href="' . h(tasksPlusTaskUrl((int)$t['id'])) . '" style="--ws:' . h($t['ws_color']) . '"'
        . ' title="' . h($t['ws_name'] . ' · ' . $t['list_name']) . '">'
        . ($time ? '<span class="tcal-time">' . h($time) . '</span>' : '')
        . ($t['is_start'] ? '<i class="bi bi-play-fill" aria-hidden="true"></i>' : '')
        . '<span class="tcal-t">' . h($label) . '</span><span class="tp-sr">' . h($sr) . '</span></a>';
};
?>
<style>
html body #tsk-main .tcal{width:100%;border-collapse:collapse;table-layout:fixed;background:#fff}
html body #tsk-main .tcal caption{caption-side:top;text-align:left;padding:0 0 .4rem;color:var(--tm-muted);font-size:.82rem}
html body #tsk-main .tcal th{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--tm-muted);padding:.35rem .4rem;text-align:left;border-bottom:2px solid var(--tsk-text)}
html body #tsk-main .tcal td{border:1px solid var(--tm-line);vertical-align:top;height:7.4rem;padding:.25rem;background:#fff}
html body #tsk-main .tcal td.is-out{background:#fafafa}
html body #tsk-main .tcal td.is-out .tcal-day{color:#a3a3a3}
html body #tsk-main .tcal td.is-weekend{background:#f7f7f7}
html body #tsk-main .tcal td.is-today{box-shadow:inset 0 0 0 3px var(--tsk-green)}
html body #tsk-main .tcal-day{display:flex;justify-content:space-between;font-size:.82rem;font-weight:600;color:var(--tsk-text);margin-bottom:.15rem}
html body #tsk-main .tcal-day .tcal-n{font-weight:400;color:var(--tm-muted);font-size:.72rem}
html body #tsk-main .tcal-list{display:flex;flex-direction:column;gap:2px;max-height:6rem;overflow-y:auto}
html body #tsk-main .tcal-chip{display:flex;align-items:center;gap:.25rem;font-size:.76rem;line-height:1.25;padding:2px 4px;text-decoration:none;color:var(--tsk-text);
  background:var(--tm-light);border-left:4px solid var(--ws,#2563eb);min-width:0}
html body #tsk-main .tcal-chip:hover{background:var(--tm-light-h)}
html body #tsk-main .tcal-chip .tcal-t{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
html body #tsk-main .tcal-chip .tcal-time{font-variant-numeric:tabular-nums;font-weight:600}
html body #tsk-main .tcal-chip.is-overdue{background:#fbe5ea;color:var(--tm-red)}
html body #tsk-main .tcal-chip.is-done .tcal-t{text-decoration:line-through;color:var(--tm-muted)}
html body #tsk-main .tagenda-day{display:grid;grid-template-columns:9rem 1fr;gap:.75rem;border-top:1px solid var(--tm-line);padding:.6rem 0}
html body #tsk-main .tagenda-day.is-today .tagenda-date{color:var(--tsk-green)}
html body #tsk-main .tagenda-date{font-weight:600}
html body #tsk-main .tagenda-date small{display:block;font-weight:400;color:var(--tm-muted)}
html body #tsk-main .tagenda-day .tcal-list{max-height:none}
html body #tsk-main .tagenda-day .tcal-chip{font-size:.88rem;padding:.35rem .5rem}
html body #tsk-main .tagenda-day .tcal-chip .tcal-t{white-space:normal}
html body #tsk-main .tcal-url{width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.8rem}
@media (max-width: 760px){
  html body #tsk-main .tcal thead{display:none}
  html body #tsk-main .tcal,html body #tsk-main .tcal tbody,html body #tsk-main .tcal tr,html body #tsk-main .tcal td{display:block;width:100%}
  html body #tsk-main .tcal td{height:auto;border-width:0 0 1px}
  html body #tsk-main .tcal td.is-empty{display:none}
  html body #tsk-main .tcal .tcal-list{max-height:none}
  html body #tsk-main .tagenda-day{grid-template-columns:1fr}
}
</style>

<div class="tp-head">
  <div>
    <div class="tp-kicker">Zadania · terminy</div>
    <h1 class="tp-title"><?= h(tasksCalendarMonthName($month) . ' ' . $year) ?></h1>
    <p class="tp-lead">Zadania w dniu terminu (z godziną, jeśli ją ustawiono). Zadania bez terminu, ale z datą rozpoczęcia, widać w dniu startu.</p>
  </div>
  <div class="tp-seg" role="group" aria-label="Widok kalendarza">
    <a href="<?= h($q(['view' => 'month'])) ?>" <?= $view === 'month' ? 'aria-current="page"' : '' ?>><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Miesiąc</a>
    <a href="<?= h($q(['view' => 'agenda'])) ?>" <?= $view === 'agenda' ? 'aria-current="page"' : '' ?>><i class="bi bi-list-ul me-1" aria-hidden="true"></i>Lista</a>
  </div>
</div>

<form class="tp-bar" method="get" action="<?= h(route('cal.index')) ?>" aria-label="Filtry kalendarza">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <div>
    <label class="tp-l" for="tcal-m">Miesiąc</label>
    <input class="tp-in" type="month" id="tcal-m" name="m" value="<?= h(sprintf('%04d-%02d', $year, $month)) ?>">
  </div>
  <div>
    <label class="tp-l" for="tcal-ws">Obszar</label>
    <select class="tp-in" id="tcal-ws" name="ws">
      <option value="">Wszystkie obszary</option>
      <?php foreach ($workspaces as $w): ?>
      <option value="<?= (int)$w['id'] ?>" <?= (int)$f['ws'] === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <label class="tp-check"><input type="checkbox" name="mine" value="1" <?= $f['mine'] ? 'checked' : '' ?>>Tylko moje</label>
  <label class="tp-check"><input type="checkbox" name="done" value="1" <?= $f['done'] ? 'checked' : '' ?>>Z ukończonymi</label>
  <button class="tp-btn" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i>Pokaż</button>
  <span style="flex:1"></span>
  <a class="tp-btn tp-btn--ghost" href="<?= h($q(['m' => $prev])) ?>" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
  <a class="tp-btn tp-btn--ghost" href="<?= h($q(['m' => date('Y-m')])) ?>">Dziś</a>
  <a class="tp-btn tp-btn--ghost" href="<?= h($q(['m' => $next])) ?>" aria-label="Następny miesiąc"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
</form>

<?php if ($view === 'month'): ?>
<div class="tp-scroll">
<table class="tcal">
  <caption>Zadań w widoku: <?= (int)$total ?>. Kliknij zadanie, aby otworzyć szczegóły.</caption>
  <thead><tr><?php foreach ($dayShort as $i => $d): ?><th scope="col"><abbr title="<?= h($dayNames[$i]) ?>"><?= h($d) ?></abbr></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($weeks as $week): ?>
    <tr>
    <?php foreach ($week as $i => $d):
        $list = $items[$d] ?? [];
        $cls = [];
        if ((int)substr($d, 5, 2) !== $month) $cls[] = 'is-out';
        if ($i >= 5) $cls[] = 'is-weekend';
        if ($d === $today) $cls[] = 'is-today';
        if (!$list) $cls[] = 'is-empty'; ?>
      <td class="<?= implode(' ', $cls) ?>" <?= $d === $today ? 'aria-current="date"' : '' ?>>
        <div class="tcal-day">
          <span><?= (int)substr($d, 8, 2) ?><span class="tp-sr"> <?= h($dayNames[$i] . ', ' . date('d.m.Y', strtotime($d))) ?></span></span>
          <?php if ($list): ?><span class="tcal-n"><?= count($list) ?> <span class="tp-sr">zadań</span></span><?php endif; ?>
        </div>
        <?php if ($list): ?><div class="tcal-list"><?php foreach ($list as $t) echo $chip($t); ?></div><?php endif; ?>
      </td>
    <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
  <?php if (!$items): ?>
  <div class="tp-empty">Brak zadań z terminem w tym miesiącu.</div>
  <?php endif; ?>
  <?php foreach ($items as $d => $list): $n = (int)date('N', strtotime($d)) - 1; ?>
  <section class="tagenda-day <?= $d === $today ? 'is-today' : '' ?>" aria-label="<?= h($dayNames[$n] . ' ' . date('d.m.Y', strtotime($d))) ?>">
    <div class="tagenda-date"><?= h(date('d.m.Y', strtotime($d))) ?><small><?= h($dayNames[$n]) ?><?= $d === $today ? ' · dziś' : '' ?></small></div>
    <div class="tcal-list"><?php foreach ($list as $t) echo $chip($t); ?></div>
  </section>
  <?php endforeach; ?>
<?php endif; ?>

<h2 class="tp-section" id="ical">Kalendarz w Outlooku i telefonie (iCal)</h2>
<div class="tp-group">
  <p class="tp-muted" style="margin-top:0">Prywatny link pokazuje zadania <strong>przypisane do Ciebie</strong>, nieukończone, z terminem.
    Dodaj go w Outlooku (Dodaj kalendarz → Subskrybuj z sieci Web), Kalendarzu Google („Z adresu URL”) albo na iPhonie
    (Ustawienia → Kalendarz → Konta → Dodaj subskrypcję). Kalendarze odświeżają się co kilka godzin. Nie udostępniaj linku —
    każdy, kto go zna, zobaczy Twoje terminy.</p>
  <?php if ($icalUrl): ?>
  <label class="tp-l" for="tcal-url">Twój link</label>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.6rem">
    <input class="tp-in tcal-url" id="tcal-url" type="text" readonly value="<?= h($icalUrl) ?>" style="flex:1 1 24rem" onfocus="this.select()">
    <button class="tp-btn" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('tcal-url').value).then(()=>{this.textContent='Skopiowano';})">
      <i class="bi bi-clipboard" aria-hidden="true"></i>Kopiuj</button>
    <a class="tp-btn tp-btn--ghost" href="<?= h(preg_replace('#^https?://#', 'webcal://', $icalUrl)) ?>"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Otwórz w aplikacji</a>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <form method="post" action="<?= h(route('cal.token')) ?>"><?= Router::csrfField() ?>
      <button class="tp-btn tp-btn--ghost tp-btn--sm" type="submit"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Wygeneruj nowy link</button></form>
    <form method="post" action="<?= h(route('cal.revoke')) ?>"><?= Router::csrfField() ?>
      <button class="tp-btn tp-btn--danger tp-btn--sm" type="submit"><i class="bi bi-x-lg" aria-hidden="true"></i>Wyłącz link</button></form>
  </div>
  <?php else: ?>
  <form method="post" action="<?= h(route('cal.token')) ?>"><?= Router::csrfField() ?>
    <button class="tp-btn" type="submit"><i class="bi bi-link-45deg" aria-hidden="true"></i>Utwórz prywatny link iCal</button></form>
  <?php endif; ?>
</div>
