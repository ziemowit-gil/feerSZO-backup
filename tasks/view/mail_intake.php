<?php
/**
 * tasks/view/mail_intake.php — skrzynka obszaru (e-mail → zadania).
 * Wejście: $leaderWs, $wsId, $cfg (konfiguracja task_mail_imap albo null), $imapDefault ([serwer, port]), $targets, $recent, $skips.
 * Od 2026-11-13 skrzynka IMAP obszaru (bez Microsoft 365). Hasło nigdy nie wraca do formularza.
 */
$t = $targets[$wsId] ?? ['lists' => [], 'people' => []];
?>
<div class="tp-head">
  <div>
    <div class="tp-kicker">Zadania · ustawienia lidera</div>
    <h1 class="tp-title">Skrzynka obszaru</h1>
    <p class="tp-lead">Wiadomości przychodzące na skrzynkę obszaru zamieniają się w zadania. Temat staje się tytułem, treść opisem,
      a załączniki plikami zadania. Nadawca dostaje z adresu systemowego potwierdzenie z numerem <strong>ZAD-…</strong>.
      Jeśli odpowie z tym numerem w temacie, odpowiedź trafi do zadania jako komentarz.</p>
    <p class="tp-lead">System sprawdza skrzynkę co 5 minut przez IMAP. Pomija autorespondery, adresy „no-reply” i własne skrzynki Fundacji.
      Wiadomości w skrzynce zostają, nic nie jest usuwane.</p>
  </div>
</div>

<?php if (!$leaderWs): ?>
<div class="tp-empty">Skrzynkę obszaru ustawia lider obszaru.</div>
<?php return; endif; ?>

<form class="tp-bar" method="get" action="<?= h(route('intake.index')) ?>"><?= route_field('intake.index') ?>
  <div><label class="tp-l" for="tmi-ws">Obszar</label>
    <select class="tp-in" id="tmi-ws" name="ws" onchange="this.form.submit()">
      <?php foreach ($leaderWs as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $wsId === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?>
    </select></div>
  <noscript><button class="tp-btn" type="submit">Pokaż</button></noscript>
</form>

<form class="tp-group" method="post" action="<?= h(route('intake.save')) ?>"><?= Router::csrfField() ?>
  <input type="hidden" name="ws" value="<?= (int)$wsId ?>">
  <fieldset style="border:0;padding:0;margin:0 0 .75rem">
    <legend class="tp-l" style="font-size:.95rem;font-weight:600">Skrzynka</legend>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:.75rem;align-items:end">
      <div><label class="tp-l" for="tmi-addr">Adres skrzynki</label>
        <input class="tp-in" id="tmi-addr" name="address" type="email" autocomplete="off" style="width:100%"
               value="<?= h($cfg['address'] ?? '') ?>" placeholder="np. rekrutacja@feer.org.pl" aria-describedby="tmi-addr-h">
        <div class="tp-muted" id="tmi-addr-h">Puste pole odłącza skrzynkę.</div></div>
      <div><label class="tp-l" for="tmi-user">Login</label>
        <input class="tp-in" id="tmi-user" name="user" type="text" autocomplete="off" style="width:100%"
               value="<?= h($cfg['user'] ?? '') ?>" placeholder="jak adres skrzynki"></div>
      <div><label class="tp-l" for="tmi-pass">Hasło do skrzynki</label>
        <input class="tp-in" id="tmi-pass" name="pass" type="password" autocomplete="new-password" style="width:100%" aria-describedby="tmi-pass-h">
        <div class="tp-muted" id="tmi-pass-h"><?= $cfg ? 'Hasło jest zapisane. Zostaw puste, żeby go nie zmieniać.' : 'Zapiszemy je zaszyfrowane.' ?></div></div>
      <div><label class="tp-l" for="tmi-host">Serwer IMAP</label>
        <input class="tp-in" id="tmi-host" name="host" type="text" autocomplete="off" style="width:100%"
               value="<?= h($cfg['host'] ?? '') ?>" placeholder="<?= h($imapDefault[0]) ?>" aria-describedby="tmi-host-h">
        <div class="tp-muted" id="tmi-host-h">Puste pole: <?= h($imapDefault[0]) ?>.</div></div>
      <div><label class="tp-l" for="tmi-port">Port</label>
        <input class="tp-in" id="tmi-port" name="port" type="number" min="1" max="65535" style="width:100%"
               value="<?= (int)($cfg['port'] ?? $imapDefault[1]) ?>"></div>
      <div><label class="tp-l" for="tmi-sec">Szyfrowanie</label>
        <select class="tp-in" id="tmi-sec" name="security" style="width:100%">
          <?php foreach (ImapReader::SECURITY as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= ($cfg['security'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
  </fieldset>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));gap:.75rem;align-items:end">
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
  <p class="tp-muted">Zadania powstają z wiadomości otrzymanych od <?= h(date('d.m.Y H:i', strtotime($cfg['since_at']))) ?>.
    Ostatnie sprawdzenie: <?= $cfg['last_run_at'] ? h(date('d.m.Y H:i', strtotime($cfg['last_run_at']))) : 'jeszcze nie' ?> (co 5 minut).</p>
  <?php if (trim((string)$cfg['last_error']) !== ''): ?>
  <p class="tp-muted" role="status" style="color:#b91c1c"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
    Ostatnie sprawdzenie nie powiodło się: <?= h($cfg['last_error']) ?></p>
  <?php endif; ?>
  <?php else: ?>
  <p class="tp-muted">Po podłączeniu zadania powstają tylko z wiadomości, które przyjdą od tej chwili. Przy zapisie sprawdzimy logowanie do skrzynki.</p>
  <?php endif; ?>
  <button class="tp-btn" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i>Zapisz i sprawdź połączenie</button>
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
