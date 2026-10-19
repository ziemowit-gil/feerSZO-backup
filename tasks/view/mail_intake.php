<?php
/**
 * tasks/view/mail_intake.php — skrzynka obszaru (e-mail → zadania).
 * Wejście: $leaderWs, $wsId, $cfg (konfiguracja albo null), $mailboxes (dostępne skrzynki), $targets, $recent, $skips.
 */
$t = $targets[$wsId] ?? ['lists' => [], 'people' => []];
?>
<div class="tp-head">
  <div>
    <div class="tp-kicker">Zadania · ustawienia lidera</div>
    <h1 class="tp-title">Skrzynka obszaru</h1>
    <p class="tp-lead">Wiadomości przychodzące na wybraną skrzynkę (z modułu Poczta — ta sama synchronizacja co Skrzynka CRM)
      zamieniają się w zadania obszaru: temat → tytuł, treść → opis, załączniki → pliki. Nadawca dostaje potwierdzenie z numerem
      <strong>ZAD-…</strong> z adresu systemowego; odpowiedź z tym numerem w temacie trafia jako komentarz do zadania.
      Autorespondery, adresy „no-reply” i własne skrzynki są pomijane.</p>
  </div>
</div>

<?php if (!$leaderWs): ?>
<div class="tp-empty">Skrzynkę obszaru ustawia lider obszaru.</div>
<?php return; endif; ?>

<form class="tp-bar" method="get" action="<?= h(route('intake.index')) ?>">
  <div><label class="tp-l" for="tmi-ws">Obszar</label>
    <select class="tp-in" id="tmi-ws" name="ws" onchange="this.form.submit()">
      <?php foreach ($leaderWs as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $wsId === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?>
    </select></div>
  <noscript><button class="tp-btn" type="submit">Pokaż</button></noscript>
</form>

<form class="tp-group" method="post" action="<?= h(route('intake.save')) ?>"><?= Router::csrfField() ?>
  <input type="hidden" name="ws" value="<?= (int)$wsId ?>">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:.75rem;align-items:end">
    <div><label class="tp-l" for="tmi-mbx">Skrzynka</label>
      <select class="tp-in" id="tmi-mbx" name="mailbox_id" style="width:100%">
        <option value="">— brak (wyłączone) —</option>
        <?php foreach ($mailboxes as $m): ?>
        <option value="<?= (int)$m['id'] ?>" <?= (int)($cfg['mailbox_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>>
          <?= h($m['mailbox'] . ($m['display_name'] ? ' — ' . $m['display_name'] : '') . ((int)$m['enabled'] ? '' : ' (skanowanie wyłączone)')) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div><label class="tp-l" for="tmi-list">Kolumna nowych zadań</label>
      <select class="tp-in" id="tmi-list" name="list_id" style="width:100%">
        <?php foreach ($t['lists'] as $l): ?><option value="<?= (int)$l['id'] ?>" <?= (int)($cfg['list_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= h($l['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="tp-l" for="tmi-asg">Przypisz do</label>
      <select class="tp-in" id="tmi-asg" name="assignee_user_id" style="width:100%"><option value="">— nikogo (liderzy dostaną powiadomienie) —</option>
        <?php foreach ($t['people'] as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($cfg['assignee_user_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="tp-l" for="tmi-prio">Priorytet</label>
      <select class="tp-in" id="tmi-prio" name="priority" style="width:100%"><?php foreach ([1, 2, 3, 4] as $p): ?><option value="<?= $p ?>" <?= (int)($cfg['priority'] ?? 2) === $p ? 'selected' : '' ?>><?= h(tasksPlusPriorityLabel($p)) ?></option><?php endforeach; ?></select></div>
  </div>
  <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin:.75rem 0">
    <label class="tp-check"><input type="checkbox" name="is_active" value="1" <?= !$cfg || (int)$cfg['is_active'] ? 'checked' : '' ?>>Aktywna</label>
    <label class="tp-check"><input type="checkbox" name="ack_enabled" value="1" <?= !$cfg || (int)$cfg['ack_enabled'] ? 'checked' : '' ?>>Wysyłaj nadawcy potwierdzenie z numerem</label>
  </div>
  <?php if ($cfg): ?>
  <p class="tp-muted">Zadania z wiadomości otrzymanych od <?= h(date('d.m.Y H:i', strtotime($cfg['since_at']))) ?>.
    Ostatnie sprawdzenie: <?= $cfg['last_run_at'] ? h(date('d.m.Y H:i', strtotime($cfg['last_run_at']))) : 'jeszcze nie' ?> (co 5 minut).</p>
  <?php else: ?>
  <p class="tp-muted">Po podłączeniu zadania powstają tylko z wiadomości, które przyjdą od tej chwili. Na liście są skrzynki z modułu Poczta, do których masz dostęp.</p>
  <?php endif; ?>
  <button class="tp-btn" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i>Zapisz</button>
</form>

<?php if ($recent): ?>
<h2 class="tp-section">Ostatnie zadania ze skrzynki</h2>
<div class="tp-scroll"><table class="tp-table">
  <thead><tr><th scope="col">Zadanie</th><th scope="col">Nadawca</th><th scope="col">Otrzymano</th><th scope="col">Potwierdzenie</th></tr></thead>
  <tbody><?php foreach ($recent as $r): ?>
    <tr><td><a href="<?= h(tasksPlusTaskUrl((int)$r['task_id'])) ?>">ZAD-<?= (int)$r['task_id'] ?> · <?= h($r['title']) ?></a></td>
      <td><?= h($r['from_name'] ?: $r['from_email']) ?><div class="tp-muted"><?= h($r['from_email']) ?></div></td>
      <td class="num"><?= $r['received_at'] ? h(date('d.m.Y H:i', strtotime($r['received_at']))) : '' ?></td>
      <td><?= $r['ack_sent_at'] ? '<span class="tp-tag is-green">wysłane</span>' : '<span class="tp-tag">nie</span>' ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<?php if ($skips): ?>
<h2 class="tp-section">Pominięte wiadomości</h2>
<div class="tp-scroll"><table class="tp-table">
  <thead><tr><th scope="col">Wiadomość</th><th scope="col">Powód</th><th scope="col">Kiedy</th></tr></thead>
  <tbody><?php foreach ($skips as $s): ?>
    <tr><td><?= h($s['subject']) ?><div class="tp-muted"><?= h($s['from_email']) ?></div></td><td><?= h($s['reason']) ?></td>
      <td class="num"><?= h(date('d.m.Y H:i', strtotime($s['created_at']))) ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
