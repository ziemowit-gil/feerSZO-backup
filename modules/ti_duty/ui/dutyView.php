<?php
/**
 * modules/ti_duty/ui/dutyView.php — DK: tydzień dyżurów kierownika / Zarządu (styl tabeli Optivum) — w planie TI (?k=dk)
 * i na osobnej stronie „Dyżury” (modules/ti_duty/public/index.php).
 * Wymaga: modules/ti_duty/logic/dutyManager.php, modules/ti_plan_public/ui/portalLayout.php (tiPlanPortalUrl),
 * planPublicPortal.php (tiPlanPublicFull, tiPlanPublicInitials).
 */

/** Dyżurny na stronie: inicjały + funkcja (widok pełny: pełne imię i nazwisko). */
function tiDutyPersonLabel(array $it): string {
    $name = trim((string)$it['person_name']);
    $name = $name === '' ? '' : (tiPlanPublicFull() ? $name : tiPlanPublicInitials($name));
    return implode(' — ', array_filter([$name, trim((string)$it['role_label'])]));
}

/** Jeden dyżur w komórce tabeli / na liście. */
function tiDutyItemHtml(array $it, bool $special): string {
    $ph = preg_replace('/[^\d+]/', '', (string)$it['contact_phone']);
    $c = [];
    if ($ph !== '') $c[] = '<a href="tel:' . h($ph) . '">' . h($it['contact_phone']) . '</a>';
    if (trim((string)$it['contact_email']) !== '') $c[] = '<a href="mailto:' . h($it['contact_email']) . '">' . h($it['contact_email']) . '</a>';
    return '<div class="lesson">'
         . '<span class="p">' . h(tiDutyPersonLabel($it)) . '</span>'
         . (trim((string)$it['place']) !== '' ? ' <span class="s">' . h($it['place']) . '</span>' : '')
         . ($c ? '<br>' . implode(' · ', $c) : '')
         . (trim((string)$it['note']) !== '' ? '<div class="st st-info">' . tiPlanPublicTextHtml((string)$it['note']) . '</div>' : '')
         . ($special ? '<span class="st st-warn">dyżur w tym dniu zmieniony</span>' : '')
         . '</div>';
}

/** Ramka „Dyżur teraz / najbliższy dyżur” (strona startowa i DK). */
function tiDutyNowBox(string $self, array $q = ['k' => 'dk']): void {
    $n = tiDutyCurrentOrNext();
    if (!$n) return;
    $it = $n['item'];
    echo '<section class="box next" aria-label="Dyżur kierownika lub Zarządu"><strong>' . ($n['now'] ? 'Dyżur teraz' : 'Najbliższy dyżur') . ':</strong> '
       . h(TI_DAYS_PL_FULL[(int)date('N', strtotime($n['date']))]) . ', ' . h(date('d.m', strtotime($n['date']))) . ', ' . h($it['time_from'] . '–' . $it['time_to'])
       . ' — ' . h(tiDutyPersonLabel($it)) . (trim((string)$it['place']) !== '' ? ', ' . h($it['place']) : '')
       . (trim((string)$it['contact_phone']) !== '' ? ', tel. <a href="tel:' . h(preg_replace('/[^\d+]/', '', (string)$it['contact_phone'])) . '">' . h($it['contact_phone']) . '</a>' : '')
       . ' <a href="' . tiPlanPortalUrl($self, $q) . '">grafik dyżurów »</a></section>';
}

/** Tydzień dyżurów: Godz | dni (Pn–Pt zawsze, Sb/Nd gdy są dyżury). */
function tiDutyRenderWeek(string $self, array $q = ['k' => 'dk']): void {
    $first = tiPlanPublicMonday(date('Y-m-d'));
    $last  = tiPlanPublicMonday(date('Y-m-d', strtotime('+12 weeks')));
    $t = (string)($_GET['t'] ?? '');
    $cur = preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) ? tiPlanPublicMonday($t) : $first;
    if ($cur < $first || $cur > $last) $cur = $first;
    $sun  = date('Y-m-d', strtotime($cur . ' +6 days'));
    $occ  = tiDutyOccurrences($cur, $sun);
    $prev = $cur > $first ? date('Y-m-d', strtotime($cur . ' -7 days')) : null;
    $next = $cur < $last ? date('Y-m-d', strtotime($cur . ' +7 days')) : null;
    $range = date('d.m', strtotime($cur)) . '–' . date('d.m.Y', strtotime($sun));

    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($cur . " +$i days"));
        if ($i >= 5 && !isset($occ[$d])) continue;
        $days[$d] = (int)date('N', strtotime($d));
    }
    $slots = []; $cells = [];
    foreach ($occ as $d => $o) foreach ($o['items'] as $it) {
        $k = $it['time_from'] . '-' . $it['time_to'];
        $slots[$k] = [$it['time_from'], $it['time_to']];
        $cells[$k][$d][] = $it;
    }
    uasort($slots, fn($a, $b) => $a <=> $b);
    ?>
    <div class="plan-tools">
      <span class="week-nav" role="navigation" aria-label="Wybór tygodnia">
        <?php if ($prev): ?><a href="<?= tiPlanPortalUrl($self, $q + ['t' => $prev]) ?>">« poprzedni</a><?php else: ?><span class="dis" aria-hidden="true">« poprzedni</span><?php endif; ?>
        <strong>tydzień <?= h($range) ?></strong>
        <?php if ($next): ?><a href="<?= tiPlanPortalUrl($self, $q + ['t' => $next]) ?>">następny »</a><?php else: ?><span class="dis" aria-hidden="true">następny »</span><?php endif; ?>
      </span>
      <button class="linkbtn" type="button" data-print>Drukuj</button>
    </div>
    <?php if (!$slots && !array_filter($occ, fn($o) => $o['cancelled'])): ?>
      <p class="empty">W tygodniu <?= h($range) ?> nie zaplanowano dyżurów.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="tabela">
        <caption class="sr">Dyżury kierownika i Zarządu, tydzień <?= h($range) ?> — godziny w wierszach, dni w kolumnach</caption>
        <thead><tr><th scope="col" class="g">Godz</th>
          <?php foreach ($days as $d => $dow): $today = $d === date('Y-m-d'); ?><th scope="col"<?= $today ? ' class="is-today"' : '' ?>><?= h(TI_DAYS_PL_FULL[$dow]) ?><small><?= h(date('d.m', strtotime($d))) ?><?= $today ? ' · dziś' : '' ?></small></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php if (!$slots): ?>
          <tr><th scope="row" class="g">—</th><?php foreach ($days as $d => $dow): ?><td class="l"><?= !empty($occ[$d]['cancelled']) ? '<span class="st st-bad">brak dyżuru</span>' : '&nbsp;' ?></td><?php endforeach; ?></tr>
        <?php endif; ?>
        <?php $firstRow = true; foreach ($slots as $k => $s): ?>
          <tr>
            <th scope="row" class="g"><?= h($s[0]) ?>-<?= h($s[1]) ?></th>
            <?php foreach ($days as $d => $dow): $cell = $cells[$k][$d] ?? []; $today = $d === date('Y-m-d'); ?>
            <td class="l<?= $today ? ' is-today' : '' ?>"><?php
              foreach ($cell as $it) echo tiDutyItemHtml($it, !empty($occ[$d]['special']));
              if (!$cell && $firstRow && !empty($occ[$d]['cancelled'])) echo '<span class="st st-bad">brak dyżuru</span>';
              if (!$cell) echo '<span class="sr">brak dyżuru</span>&nbsp;';
            ?></td>
            <?php endforeach; ?>
          </tr>
        <?php $firstRow = false; endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif;
}
