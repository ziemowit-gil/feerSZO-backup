<?php
/**
 * modules/tasks_plus/view/sla.php — SLA i eskalacje.
 * Wejście: $leaderWs (obszary lidera), $wsId (filtr), $state, $rows (zadania), $counts, $polWs (obszar w formularzu zasad),
 * $policies [priority => wiersz], $people (kandydaci na poziom 2).
 */
$stateTag = [
    'ok' => ['W terminie', 'is-green'], 'risk' => ['Zagrożone', 'is-orange'],
    'breach' => ['Po terminie', 'is-red'], 'escalated' => ['Eskalowane', 'is-red'],
];
$url = fn(array $p) => route('sla.index') . '?' . http_build_query(array_filter($p + ['ws' => $wsId ?: null]));
$hrs = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
?>
<div class="tp-head">
  <div>
    <div class="tp-kicker">Zadania · nadzór terminów</div>
    <h1 class="tp-title">SLA i eskalacje</h1>
    <p class="tp-lead">Zadanie po terminie (albo bez terminu — po czasie realizacji z zasad obszaru) jest automatycznie zgłaszane
      liderom obszaru, a po kolejnym czasie — wskazanej osobie. Sprawdzanie co godzinę.</p>
  </div>
  <?php if ($leaderWs): ?>
  <form method="post" action="<?= h(route('sla.run')) ?>"><?= Router::csrfField() ?>
    <input type="hidden" name="ws" value="<?= (int)$wsId ?>">
    <button class="tp-btn tp-btn--ghost" type="submit"><i class="bi bi-lightning" aria-hidden="true"></i>Sprawdź teraz</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$leaderWs): ?>
<div class="tp-empty">SLA i eskalacje są dostępne dla liderów obszarów (rola lider/edytor w obszarze).</div>
<?php return; endif; ?>

<form class="tp-bar" method="get" action="<?= h(route('sla.index')) ?>">
  <div>
    <label class="tp-l" for="sla-ws">Obszar</label>
    <select class="tp-in" id="sla-ws" name="ws" onchange="this.form.submit()">
      <option value="">Wszystkie moje obszary</option>
      <?php foreach ($leaderWs as $w): ?>
      <option value="<?= (int)$w['id'] ?>" <?= $wsId === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="tp-btn" type="submit">Pokaż</button></noscript>
</form>

<div class="tp-tiles" role="list">
  <a role="listitem" class="tp-tile is-red" href="<?= h($url(['state' => 'escalated'])) ?>"><b><?= $counts['escalated'] ?></b><span>Eskalowane</span></a>
  <a role="listitem" class="tp-tile is-red" href="<?= h($url(['state' => 'breach'])) ?>"><b><?= $counts['breach'] ?></b><span>Po terminie, przed eskalacją</span></a>
  <a role="listitem" class="tp-tile is-orange" href="<?= h($url(['state' => 'risk'])) ?>"><b><?= $counts['risk'] ?></b><span>Zagrożone (&lt; <?= TASKS_SLA_RISK_HOURS ?> godz.)</span></a>
  <a role="listitem" class="tp-tile" href="<?= h($url(['state' => 'ok'])) ?>"><b><?= $counts['ok'] ?></b><span>W terminie</span></a>
  <a role="listitem" class="tp-tile is-dark" href="<?= h($url(['state' => 'all'])) ?>"><b><?= array_sum($counts) ?></b><span>Pod nadzorem SLA</span></a>
</div>

<h2 class="tp-section"><?= h(['' => 'Wymaga uwagi', 'all' => 'Wszystkie pod nadzorem', 'ok' => 'W terminie',
    'risk' => 'Zagrożone', 'breach' => 'Po terminie', 'escalated' => 'Eskalowane'][$state]) ?></h2>
<?php if (!$rows): ?>
<div class="tp-empty">Brak zadań w tym widoku<?= array_sum($counts) ? '' : ' — ustaw zasady SLA obszaru poniżej' ?>.</div>
<?php else: ?>
<div class="tp-scroll">
<table class="tp-table">
  <thead><tr><th scope="col">Zadanie</th><th scope="col">Obszar / kolumna</th><th scope="col">Wykonawcy</th>
    <th scope="col">Termin SLA</th><th scope="col">Stan</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $t): [$lab, $cls] = $stateTag[$t['sla_state']]; ?>
    <tr>
      <td><a href="<?= h(tasksPlusTaskUrl((int)$t['id'])) ?>"><?= h($t['title']) ?></a>
        <div class="tp-muted"><?= h(tasksPlusPriorityLabel((int)$t['priority'])) ?></div></td>
      <td><span class="tp-dot" style="background:<?= h($t['ws_color']) ?>" aria-hidden="true"></span><?= h($t['ws_name']) ?>
        <div class="tp-muted"><?= h($t['list_name']) ?></div></td>
      <td><?= $t['assignees'] ? h($t['assignees']) : '<span class="tp-muted">brak</span>' ?></td>
      <td class="num"><?= date('d.m.Y H:i', (int)$t['sla_deadline']) ?>
        <div class="tp-muted"><?= $t['sla_from_due'] ? 'termin zadania' : 'czas realizacji' ?> ·
          <?= $t['sla_left_h'] >= 0 ? 'zostało ' . h(tasksSlaLateLabel((int)($t['sla_left_h'] * 3600))) : 'po terminie ' . h(tasksSlaLateLabel((int)(-$t['sla_left_h'] * 3600))) ?></div></td>
      <td><span class="tp-tag <?= $cls ?>"><?= $lab ?><?= $t['esc_level'] ? ' · poz. ' . (int)$t['esc_level'] : '' ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<h2 class="tp-section" id="zasady">Zasady SLA obszaru</h2>
<form class="tp-group" method="post" action="<?= h(route('sla.save')) ?>"><?= Router::csrfField() ?>
  <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:.75rem">
    <div>
      <label class="tp-l" for="sla-pws">Obszar</label>
      <select class="tp-in" id="sla-pws" name="ws" onchange="location.href='<?= h(route('sla.index')) ?>?ws='+this.value+'#zasady'">
        <?php foreach ($leaderWs as $w): ?>
        <option value="<?= (int)$w['id'] ?>" <?= $polWs === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <p class="tp-muted" style="margin:0;flex:1 1 20rem">Czasy w godzinach (72 = 3 doby, liczone kalendarzowo). Zasada konkretnego priorytetu
      ma pierwszeństwo przed „Każdy priorytet”. Puste „Czas realizacji” = nadzór tylko zadań z terminem.</p>
  </div>
  <div class="tp-scroll">
  <table class="tp-table">
    <thead><tr><th scope="col">Priorytet</th><th scope="col">Aktywna</th><th scope="col">Czas realizacji bez terminu (godz.)</th>
      <th scope="col">Eskalacja do liderów po terminie + (godz.)</th><th scope="col">Poziom 2 po (godz.)</th><th scope="col">Poziom 2 — do kogo</th></tr></thead>
    <tbody>
    <?php foreach ([0, 4, 3, 2, 1] as $p): $r = $policies[$p] ?? []; $n = 'p[' . $p . ']'; $lid = 'sla-' . $p; ?>
      <tr>
        <th scope="row" id="<?= $lid ?>"><?= h(tasksSlaPriorityName($p)) ?></th>
        <td><input type="checkbox" name="<?= $n ?>[active]" value="1" aria-labelledby="<?= $lid ?>" <?= !empty($r['is_active']) ? 'checked' : '' ?> style="width:1.15rem;height:1.15rem"></td>
        <td><input class="tp-in" type="text" inputmode="decimal" size="6" name="<?= $n ?>[resolve_hours]" value="<?= h($hrs($r['resolve_hours'] ?? null)) ?>" aria-label="<?= h(tasksSlaPriorityName($p)) ?>: czas realizacji w godzinach"></td>
        <td><input class="tp-in" type="text" inputmode="decimal" size="6" name="<?= $n ?>[grace_hours]" value="<?= h($hrs($r['grace_hours'] ?? null)) ?>" placeholder="0" aria-label="<?= h(tasksSlaPriorityName($p)) ?>: eskalacja po terminie, godziny"></td>
        <td><input class="tp-in" type="text" inputmode="decimal" size="6" name="<?= $n ?>[level2_hours]" value="<?= h($hrs($r['level2_hours'] ?? null)) ?>" aria-label="<?= h(tasksSlaPriorityName($p)) ?>: poziom 2 po godzinach"></td>
        <td><select class="tp-in" name="<?= $n ?>[level2_user_id]" aria-label="<?= h(tasksSlaPriorityName($p)) ?>: adresat poziomu 2">
          <option value="">— brak —</option>
          <?php foreach ($people as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)($r['level2_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <button class="tp-btn" type="submit" style="margin-top:.75rem"><i class="bi bi-check-lg" aria-hidden="true"></i>Zapisz zasady</button>
</form>
