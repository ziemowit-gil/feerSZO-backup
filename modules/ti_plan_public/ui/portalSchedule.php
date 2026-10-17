<?php
/**
 * modules/ti_plan_public/ui/portalSchedule.php — Plan tygodnia (siatka + widok dzień pod dniem) i lista terminów.
 *
 * tiPlanPortalSchedule($o) — $o:
 *   lessons  wynik tiPlanPublicLessons() dla zakresu [poniedziałek bieżącego tygodnia, +weeks tyg.]
 *   weeks    zakres w tygodniach,  self  ścieżka skryptu,  q  bazowe parametry adresu (np. ['g' => token])
 *   mode     group | instr | room — decyduje, które „pigułki” pokazać na karcie
 *   groupLinks [course_id => token] grup z katalogu,  instrLinks [user_id => token],  roomLinks bool
 *   hideGroups bool — ukryj nazwy grup spoza katalogu (plan prowadzącego/sali w katalogu publicznym)
 *   label    opis planu dla czytników ekranu (np. „grupy X”)
 */

function tiPlanPortalCard(array $l, array $o, bool $mobile = false): string {
    $self = (string)$o['self'];
    $mode = (string)$o['mode'];
    $now  = date('Y-m-d') === $l['date'] && $l['tf'] <= date('H:i') && date('H:i') < $l['tt'];
    $cls  = 'lesson-card' . ($l['cancelled'] ? ' is-cancelled' : '') . ($l['method'] === 'online' ? ' is-online' : '') . ($now && !$l['cancelled'] ? ' is-now' : '');
    $pill = function (string $label, string $value, ?string $href = null, string $aria = ''): string {
        $in = '<span class="meta-pill-label">' . h($label) . '</span><span class="meta-pill-value">' . h($value) . '</span>';
        return $href !== null
            ? '<a class="meta-pill" href="' . $href . '"' . ($aria !== '' ? ' aria-label="' . h($aria) . '"' : '') . '>' . $in . '</a>'
            : '<span class="meta-pill">' . $in . '</span>';
    };
    $pills = [];
    if ($mode !== 'group') {
        $tok = $o['groupLinks'][$l['course_id']] ?? null;
        if ($tok !== null) $pills[] = $pill('Grupa', $l['course'], tiPlanPortalUrl($self, ['g' => $tok]), 'Plan grupy ' . $l['course']);
        elseif (empty($o['hideGroups'])) $pills[] = $pill('Grupa', $l['course']);
        else $pills[] = $pill('Grupa', 'niepubliczna');
    }
    if ($mode !== 'room') {
        if ($l['method'] === 'online') $pills[] = $pill('Forma', 'online');
        elseif ($l['room'] !== '') $pills[] = $pill('Sala', $l['room'], !empty($o['roomLinks']) && $l['room_id'] ? tiPlanPortalUrl($self, ['s' => $l['room_id']]) : null, 'Plan sali ' . $l['room']);
    }
    if ($mode !== 'instr' && $l['instr'] !== '') {
        $tok = $o['instrLinks'][$l['instr_id']] ?? null;
        $pills[] = $pill('Prowadzący', $l['instr'], $tok !== null ? tiPlanPortalUrl($self, ['p' => $tok]) : null, 'Plan prowadzącego ' . $l['instr']);
    }
    $tags = [];
    if ($l['cancelled'])                     $tags[] = '<span class="tag tag-bad">Odwołane</span>';
    if ($l['moved'] !== '')                  $tags[] = '<span class="tag tag-info">Przeniesione z ' . h($l['moved']) . '</span>';
    if ($l['flag'] === 'tentative')          $tags[] = '<span class="tag tag-warn">Termin niepewny</span>';
    if ($l['flag'] === 'change_possible')    $tags[] = '<span class="tag tag-info">Możliwa zmiana terminu</span>';
    if ($mode === 'room' && !$l['cancelled']) $tags[] = $l['room_ok'] ? '<span class="tag tag-ok">Sala zarezerwowana</span>' : '<span class="tag tag-warn">Rezerwacja do potwierdzenia</span>';
    if ($now && !$l['cancelled'])            $tags[] = '<span class="tag tag-ok">Trwa teraz</span>';
    $join = $l['url'] !== '' && !$l['cancelled'] ? '<a class="join" href="' . h($l['url']) . '" target="_blank" rel="noopener">Dołącz do zajęć online<span class="sr"> — ' . h($l['subject']) . ', ' . h(date('d.m', strtotime($l['date']))) . '</span></a>' : '';
    return '<article class="' . $cls . '">'
         . ($mobile ? '<div class="lesson-time-lg">' . h($l['time'] ?: '—') . '</div>' : '')
         . '<strong class="lesson-subject">' . h($l['subject']) . '</strong>'
         . ($tags ? '<div class="lesson-meta">' . implode('', $tags) . '</div>' : '')
         . ($pills ? '<div class="lesson-meta">' . implode('', $pills) . '</div>' : '')
         . $join
         . '</article>';
}

function tiPlanPortalSchedule(array $o): void {
    $self    = (string)$o['self'];
    $q       = (array)$o['q'];
    $weeks   = max(1, (int)$o['weeks']);
    $lessons = (array)$o['lessons'];
    $first   = tiPlanPublicMonday(date('Y-m-d'));
    $last    = tiPlanPublicMonday(date('Y-m-d', strtotime("+{$weeks} weeks")));
    $view    = ($_GET['v'] ?? '') === 'lista' ? 'lista' : 'tydzien';

    // Tygodnie w zakresie (z liczbą terminów) — do przełącznika
    $weekList = [];
    for ($m = $first; $m <= $last; $m = date('Y-m-d', strtotime($m . ' +7 days'))) $weekList[$m] = 0;
    foreach ($lessons as $l) if (!$l['cancelled']) { $m = tiPlanPublicMonday($l['date']); if (isset($weekList[$m])) $weekList[$m]++; }

    $t = (string)($_GET['t'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) || !isset($weekList[tiPlanPublicMonday($t)])) {
        $nx = tiPlanPublicNext($lessons);   // domyślnie: tydzień najbliższych zajęć
        $t  = $nx ? $nx['date'] : date('Y-m-d');
    }
    $cur  = tiPlanPublicMonday($t);
    if (!isset($weekList[$cur])) $cur = $first;
    $keys = array_keys($weekList);
    $idx  = array_search($cur, $keys, true);
    $prev = $idx > 0 ? $keys[$idx - 1] : null;
    $next = $idx < count($keys) - 1 ? $keys[$idx + 1] : null;
    $W    = tiPlanPublicWeekGrid($lessons, $cur);
    $range = fn(string $m) => date('d.m', strtotime($m)) . '–' . date('d.m.Y', strtotime($m . ' +6 days'));
    ?>
    <div class="plan-tools">
      <nav class="seg" aria-label="Rodzaj widoku">
        <a href="<?= tiPlanPortalUrl($self, $q + ['t' => $cur]) ?>"<?= $view === 'tydzien' ? ' aria-current="page"' : '' ?>>Tydzień</a>
        <a href="<?= tiPlanPortalUrl($self, $q + ['v' => 'lista']) ?>"<?= $view === 'lista' ? ' aria-current="page"' : '' ?>>Terminy</a>
      </nav>
      <?php if ($view === 'tydzien'): ?>
      <nav class="week-nav" aria-label="Wybór tygodnia">
        <a class="btn" href="<?= $prev ? tiPlanPortalUrl($self, $q + ['t' => $prev]) : '#' ?>"<?= $prev ? '' : ' aria-disabled="true" tabindex="-1"' ?>><?= tiPlanPortalIcon('prev') ?><span class="sr">Poprzedni tydzień</span></a>
        <label class="sr" for="tiWeek">Tydzień</label>
        <select id="tiWeek" data-week-select>
          <?php foreach ($weekList as $m => $n): ?>
          <option value="<?= tiPlanPortalUrl($self, $q + ['t' => $m]) ?>"<?= $m === $cur ? ' selected' : '' ?>><?= h($range($m)) ?><?= $m === $first ? ' (bieżący)' : '' ?> — <?= $n ? $n . ' zaj.' : 'brak zajęć' ?></option>
          <?php endforeach; ?>
        </select>
        <a class="btn" href="<?= $next ? tiPlanPortalUrl($self, $q + ['t' => $next]) : '#' ?>"<?= $next ? '' : ' aria-disabled="true" tabindex="-1"' ?>><?= tiPlanPortalIcon('next') ?><span class="sr">Następny tydzień</span></a>
        <?php if ($cur !== $first): ?><a class="btn" href="<?= tiPlanPortalUrl($self, $q + ['t' => date('Y-m-d')]) ?>">Dziś</a><?php endif; ?>
      </nav>
      <?php endif; ?>
      <span class="spacer"></span>
      <?php if (!empty($o['icsUrl'])): ?><a class="btn" href="<?= $o['icsUrl'] ?>"><?= tiPlanPortalIcon('cal') ?>Dodaj do kalendarza</a><?php endif; ?>
      <button class="btn" type="button" data-print><?= tiPlanPortalIcon('print') ?>Drukuj</button>
    </div>
    <div class="print-head"><strong><?= h($o['printTitle'] ?? '') ?></strong> — <?= $view === 'lista' ? 'terminy' : 'tydzień ' . h($range($cur)) ?></div>

    <?php if ($view === 'lista'):
        $up = array_values(array_filter($lessons, fn($l) => $l['date'] >= date('Y-m-d')));
        $byDay = [];
        foreach ($up as $l) $byDay[$l['date']][] = $l; ?>
      <div class="list-view">
        <h2 class="sr">Lista terminów <?= h($o['label'] ?? '') ?></h2>
        <?php if (!$byDay): ?><div class="empty-box">Brak zaplanowanych terminów w najbliższych <?= $weeks ?> tygodniach.</div><?php endif; ?>
        <div class="day-list">
          <?php foreach ($byDay as $d => $ls): ?>
          <section class="day-block" aria-labelledby="d<?= h($d) ?>">
            <h3 id="d<?= h($d) ?>"><?= h(TI_DAYS_PL_FULL[(int)date('N', strtotime($d))]) ?>, <?= h(date('d.m.Y', strtotime($d))) ?><?= $d === date('Y-m-d') ? ' <span class="tag tag-ok">dziś</span>' : '' ?></h3>
            <?php foreach ($ls as $l) echo tiPlanPortalCard($l, $o, true); ?>
          </section>
          <?php endforeach; ?>
        </div>
      </div>
    <?php else: ?>
      <?php if (!$W['slots']): ?>
        <div class="empty-box">W tygodniu <?= h($range($cur)) ?> nie ma zajęć.<?php if ($next): ?> <a href="<?= tiPlanPortalUrl($self, $q + ['t' => $next]) ?>">Następny tydzień</a><?php endif; ?></div>
      <?php else: ?>
      <section class="table-wrap desktop-grid">
        <table class="timetable">
          <caption class="sr">Plan <?= h($o['label'] ?? '') ?>, tydzień <?= h($range($cur)) ?> — godziny w wierszach, dni w kolumnach</caption>
          <colgroup><col class="c-slot"><?php foreach ($W['days'] as $_): ?><col><?php endforeach; ?></colgroup>
          <thead><tr>
            <th scope="col">Godzina</th>
            <?php foreach ($W['days'] as $d): ?><th scope="col"<?= $d['today'] ? ' class="is-today"' : '' ?>><?= h($d['full']) ?><small><?= h(date('d.m', strtotime($d['date']))) ?><?= $d['today'] ? ' · dziś' : '' ?></small></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($W['slots'] as $i => $s): ?>
            <tr>
              <th scope="row" class="slot-cell"><span><?= h($s['tf']) ?></span><small>do <?= h($s['tt']) ?></small></th>
              <?php foreach ($W['days'] as $dow => $d): $cell = $W['cells'][$s['key']][$dow] ?? []; ?>
              <td<?= $d['today'] ? ' class="is-today"' : '' ?>><?php foreach ($cell as $l) echo tiPlanPortalCard($l, $o); ?><?= $cell ? '' : '<span class="sr">brak zajęć</span>' ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
      <nav class="day-jump" aria-label="Przejdź do dnia">
        <?php foreach ($W['days'] as $d): ?><a href="#day-<?= $d['id'] ?>"><?= h($d['short']) ?> <?= h(date('d.m', strtotime($d['date']))) ?><span><?= (int)$d['count'] ?></span></a><?php endforeach; ?>
      </nav>
      <div class="day-list">
        <?php foreach ($W['days'] as $dow => $d): $ls = array_values(array_filter($W['lessons'], fn($l) => $l['dow'] === $dow)); ?>
        <section class="day-block" id="day-<?= $d['id'] ?>" aria-labelledby="dh-<?= $d['id'] ?>"<?= $d['today'] ? ' data-today' : '' ?>>
          <h3 id="dh-<?= $d['id'] ?>"><?= h($d['full']) ?>, <?= h(date('d.m', strtotime($d['date']))) ?><?= $d['today'] ? ' <span class="tag tag-ok">dziś</span>' : '' ?></h3>
          <?php if (!$ls): ?><div class="free">Brak zajęć</div><?php endif; ?>
          <?php foreach ($ls as $l) echo tiPlanPortalCard($l, $o, true); ?>
        </section>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php
}
