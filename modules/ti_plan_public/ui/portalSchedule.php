<?php
/**
 * modules/ti_plan_public/ui/portalSchedule.php — Plan tygodnia w stylu „Plan lekcji Optivum” (Nr | Godz | dni)
 * oraz lista terminów.
 *
 * tiPlanPortalSchedule($o) — $o:
 *   lessons  wynik tiPlanPublicLessons() dla zakresu [poniedziałek bieżącego tygodnia, +weeks tyg.]
 *   weeks    zakres w tygodniach,  self  ścieżka skryptu,  q  bazowe parametry adresu (np. ['g' => token])
 *   mode     group | instr | room — co pokazać w komórce (przedmiot zawsze; grupa / prowadzący / sala wg widoku)
 *   groupLinks [course_id => token] grup z katalogu,  instrLinks [user_id => token],  roomLinks bool
 *   hideGroups bool — nazwy grup spoza katalogu już podmienione na „niepubliczna” (bez linku)
 *   label    opis planu dla czytników ekranu,  icsUrl  adres .ics (opcjonalnie)
 */

/** Jeden wpis w komórce: przedmiot (pogrubiony), potem grupa / prowadzący / sala jako linki — jak w Optivum. */
function tiPlanPortalCard(array $l, array $o, bool $withTime = false): string {
    $self = (string)$o['self'];
    $mode = (string)$o['mode'];
    $parts = [];
    $full = tiPlanPublicFull();   // widok pełny: linki do każdej grupy (?c=) i prowadzącego (?u=)
    if ($mode !== 'group') {
        $tok = $o['groupLinks'][$l['course_id']] ?? null;
        if (($l['kind'] ?? '') === 'consult') $parts[] = '<span class="k">(konsultacja indywidualna)</span>';
        else $parts[] = ($full && $l['course_id']) || $tok !== null
            ? '<a class="k" href="' . tiPlanPortalUrl($self, $full ? ['c' => $l['course_id']] : ['g' => $tok]) . '" title="Plan grupy">' . h($l['course']) . '</a>'
            : '<span class="k">' . h($l['course'] === 'niepubliczna' ? '(grupa niepubliczna)' : $l['course']) . '</span>';
    }
    if ($mode !== 'instr' && $l['instr'] !== '') {
        $tok = $o['instrLinks'][$l['instr_id']] ?? null;
        $parts[] = ($full && $l['instr_id']) || $tok !== null
            ? '<a class="n" href="' . tiPlanPortalUrl($self, $full ? ['u' => $l['instr_id']] : ['p' => $tok]) . '" title="Plan prowadzącego">' . h($l['instr']) . '</a>'
            : '<span class="n">' . h($l['instr']) . '</span>';
    }
    // Brak danych: wyraźnie „Nie wskazano” zamiast pustego miejsca
    if ($mode !== 'instr' && trim($l['instr']) === '') $parts[] = '<span class="n none">prowadzący: Nie wskazano</span>';
    if ($mode !== 'room' && $l['method'] !== 'online' && $l['room'] === '' && !($l['room_id'] && in_array((int)$l['room_id'], tiPlanPublicOnlineRoomIds(), true)))
        $parts[] = '<span class="s none">sala: Nie wskazano</span>';
    if ($mode !== 'room') {
        if ($l['method'] === 'online') $parts[] = !empty($o['roomLinks'])
            ? '<a class="s" href="' . tiPlanPortalUrl($self, ['s' => 'online']) . '" title="Zajęcia online — bez rezerwacji sali">zdalnie (online)</a>'
            : '<span class="s">zdalnie (online)</span>';
        elseif ($l['room_id'] && in_array((int)$l['room_id'], tiPlanPublicOnlineRoomIds(), true)) $parts[] = !empty($o['roomLinks'])
            ? '<a class="s" href="' . tiPlanPortalUrl($self, ['s' => 'online']) . '" title="Zajęcia online — bez rezerwacji sali">zdalnie (online)</a>'
            : '<span class="s">zdalnie (online)</span>';
        elseif ($l['room'] !== '') $parts[] = !empty($o['roomLinks']) && $l['room_id']
            ? '<a class="s" href="' . tiPlanPortalUrl($self, ['s' => $l['room_id']]) . '" title="Plan sali">' . h($l['room']) . '</a>'
            : '<span class="s">' . h($l['room']) . '</span>';
    }
    $st = [];
    if ($l['cancelled'])                  $st[] = '<span class="st st-bad">odwołane</span>';
    if ($l['moved'] !== '')               $st[] = '<span class="st st-info">przeniesione z ' . h($l['moved']) . '</span>';
    if ($l['flag'] === 'tentative')       $st[] = '<span class="st st-warn">termin niepewny</span>';
    if ($l['flag'] === 'change_possible') $st[] = '<span class="st st-info">możliwa zmiana terminu</span>';
    if (($l['kind'] ?? '') === 'szk')     $st[] = '<span class="st st-info">szkolenie krótkie (SzK)</span>';
    if (($l['kind'] ?? '') === 'consult' && $l['flag'] === '') $st[] = '<span class="st st-info">konsultacja</span>';
    if ($mode === 'room' && !$l['cancelled'] && $l['room_id'] > 0 && !in_array((int)$l['room_id'], tiPlanPublicOnlineRoomIds(), true)) $st[] = $l['room_ok'] ? '<span class="st st-ok">sala zarezerwowana</span>' : '<span class="st st-warn">rezerwacja do potwierdzenia</span>';
    $join = $l['url'] !== '' && !$l['cancelled'] ? ' <a class="join" href="' . h($l['url']) . '" target="_blank" rel="noopener">dołącz<span class="sr"> do zajęć online ' . h(date('d.m', strtotime($l['date']))) . '</span></a>' : '';
    return '<div class="lesson' . ($l['cancelled'] ? ' is-cancelled' : '') . '">'
         . ($withTime ? '<span class="sr">' . h($l['time']) . ' </span>' : '')
         . '<span class="p">' . h($l['subject']) . '</span> ' . implode(' ', $parts) . $join . implode('', $st)
         . '</div>';
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
      <span class="views" role="navigation" aria-label="Rodzaj widoku">
        <a href="<?= tiPlanPortalUrl($self, $q + ['t' => $cur]) ?>"<?= $view === 'tydzien' ? ' aria-current="page"' : '' ?>>Plan tygodnia</a> |
        <a href="<?= tiPlanPortalUrl($self, $q + ['v' => 'lista']) ?>"<?= $view === 'lista' ? ' aria-current="page"' : '' ?>>Lista terminów</a>
      </span>
      <?php if ($view === 'tydzien'): ?>
      <span class="week-nav" role="navigation" aria-label="Wybór tygodnia">
        <?php if ($prev): ?><a href="<?= tiPlanPortalUrl($self, $q + ['t' => $prev]) ?>">« poprzedni</a><?php else: ?><span class="dis" aria-hidden="true">« poprzedni</span><?php endif; ?>
        <label class="sr" for="tiWeek">Tydzień</label>
        <select id="tiWeek" data-week-select>
          <?php foreach ($weekList as $m => $n): ?>
          <option value="<?= tiPlanPortalUrl($self, $q + ['t' => $m]) ?>"<?= $m === $cur ? ' selected' : '' ?>><?= h($range($m)) ?><?= $m === $first ? ' (bieżący)' : '' ?> — <?= $n ? $n . ' zaj.' : 'brak zajęć' ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($next): ?><a href="<?= tiPlanPortalUrl($self, $q + ['t' => $next]) ?>">następny »</a><?php else: ?><span class="dis" aria-hidden="true">następny »</span><?php endif; ?>
      </span>
      <?php endif; ?>
      <?php if (!empty($o['icsUrl'])): ?><a href="<?= $o['icsUrl'] ?>">Dodaj do kalendarza</a><?php endif; ?>
      <button class="linkbtn" type="button" data-print>Drukuj plan</button>
    </div>

    <?php if ($view === 'lista'):
        $byDay = [];
        foreach ($lessons as $l) if ($l['date'] >= date('Y-m-d')) $byDay[$l['date']][] = $l; ?>
      <div class="terms">
        <h2 class="sr">Lista terminów <?= h($o['label'] ?? '') ?></h2>
        <?php if (!$byDay): ?><p class="empty">Brak zaplanowanych terminów w najbliższych <?= $weeks ?> tygodniach.</p><?php endif; ?>
        <?php foreach ($byDay as $d => $ls): ?>
        <h3><?= h(TI_DAYS_PL_FULL[(int)date('N', strtotime($d))]) ?>, <?= h(date('d.m.Y', strtotime($d))) ?><?= $d === date('Y-m-d') ? ' (dziś)' : '' ?></h3>
        <?php foreach ($ls as $l): ?><div class="row"><div class="g<?= $l['cancelled'] ? ' is-cancelled' : '' ?>"><?= $l['cancelled'] ? '<s>' . h($l['time'] ?: '—') . '</s>' : h($l['time'] ?: '—') ?></div><?= tiPlanPortalCard($l, $o) ?></div><?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    <?php elseif (!$W['slots']): ?>
      <p class="empty">W tygodniu <?= h($range($cur)) ?> nie ma zajęć.<?php if ($next): ?> <a href="<?= tiPlanPortalUrl($self, $q + ['t' => $next]) ?>">Następny tydzień »</a><?php endif; ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tabela">
          <caption class="sr">Plan <?= h($o['label'] ?? '') ?>, tydzień <?= h($range($cur)) ?> — godziny w wierszach, dni w kolumnach</caption>
          <thead><tr>
            <th scope="col">Nr</th><th scope="col" class="g">Godz</th>
            <?php foreach ($W['days'] as $d): ?><th scope="col"<?= $d['today'] ? ' class="is-today"' : '' ?>><?= h($d['full']) ?><small><?= h(date('d.m', strtotime($d['date']))) ?><?= $d['today'] ? ' · dziś' : '' ?></small></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($W['slots'] as $i => $s):
              // Godzina przekreślona, gdy wszystkie terminy w wierszu są odwołane
              $rowLs = array_merge(...array_values(array_map(fn($c) => $c, $W['cells'][$s['key']] ?? [[]])));
              $allCx = $rowLs && !array_filter($rowLs, fn($x) => !$x['cancelled']); ?>
            <tr>
              <td class="nr"><?= $i + 1 ?></td>
              <th scope="row" class="g<?= $allCx ? ' is-cancelled' : '' ?>"><?= $allCx ? '<s>' : '' ?><?= h($s['tf']) ?>-<?= h($s['tt']) ?><?= $allCx ? '</s>' : '' ?></th>
              <?php foreach ($W['days'] as $dow => $d): $cell = $W['cells'][$s['key']][$dow] ?? []; ?>
              <td class="l<?= $d['today'] ? ' is-today' : '' ?>"><?php foreach ($cell as $l) echo tiPlanPortalCard($l, $o); ?><?= $cell ? '' : '<span class="sr">brak zajęć</span>&nbsp;' ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <?php
}
