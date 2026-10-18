<?php
/**
 * modules/collaborator_data/view/admin.php — administrator: zmiany czekające na akceptację (np. rachunek bankowy)
 * i dziennik wszystkich zmian danych współpracowników. Zmienne: $pending, $log, $fields, $filterUser.
 */
$PAGE_TITLE = 'Zmiany danych współpracowników';
include dirname(__DIR__, 3) . '/includes/header.php';
?>
<style>
.cda{max-width:1300px;--a:#0050ef;--d:#1d1d1d;--m:#5f5f5f}
.cda *{border-radius:0!important}
.cda-k{font-size:.68rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#003bb0}
.cda h1{font-size:2.1rem;font-weight:300;margin:.15rem 0 1rem}
.cda h2{font-size:1.25rem;font-weight:300;border-bottom:2px solid var(--d);padding-bottom:.4rem;margin:1.5rem 0 .75rem}
.cda table{width:100%;border-collapse:collapse;font-size:.85rem}
.cda th{font-size:.66rem;text-transform:uppercase;letter-spacing:.1em;color:var(--m);border-bottom:2px solid var(--d);padding:.5rem .6rem;text-align:left}
.cda td{border-bottom:1px solid #e1e1e1;padding:.5rem .6rem;vertical-align:top}
.cda .st{display:inline-block;font-size:.62rem;font-weight:700;color:#fff;padding:.1rem .35rem}
.cda form.dec{display:flex;gap:2px;flex-wrap:wrap}
.cda form.dec input{border:2px solid #8a8a8a;height:32px;font-size:.8rem;padding:0 .4rem;min-width:160px}
.cda form.dec button{border:0;height:32px;padding:0 .8rem;color:#fff;font-weight:600;font-size:.8rem}
.cda-empty{background:#f2f2f2;padding:1rem;color:var(--m)}
</style>
<div class="cda">
  <div class="cda-k">Ludzie · zmiana danych współpracownika</div>
  <h1>Zmiany danych<?= $filterUser ? ' — ' . h($filterUser['name']) : '' ?></h1>
  <?= flash_html() ?>

  <h2>Do akceptacji <?= $pending ? '(' . count($pending) . ')' : '' ?></h2>
  <?php if (!$pending): ?><div class="cda-empty">Brak zmian czekających na decyzję.</div><?php else: ?>
  <table>
    <tr><th>Osoba</th><th>Pole</th><th>Dotychczas</th><th>Nowa wartość</th><th>Zgłoszono</th><th>Decyzja</th></tr>
    <?php foreach ($pending as $c): ?>
    <tr>
      <td><a href="?u=<?= (int)$c['user_id'] ?>"><?= h($c['user_name']) ?></a><br><small class="text-muted"><?= h($c['user_email']) ?></small></td>
      <td><?= h($fields[$c['field']]['label'] ?? $c['field']) ?></td>
      <td><?= h(CollaboratorDataService::display((string)$c['field'], $c['old_value'], true)) ?></td>
      <td><b><?= h(CollaboratorDataService::display((string)$c['field'], $c['new_value'], true)) ?></b></td>
      <td><?= h(date('d.m.Y H:i', strtotime((string)$c['created_at']))) ?><br><small class="text-muted">IP <?= h($c['ip']) ?></small></td>
      <td>
        <form class="dec" method="post" action="<?= h(route('cdc.decide', ['id' => (int)$c['id']])) ?>">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input name="note" placeholder="Uwaga (opcjonalnie)" aria-label="Uwaga do decyzji">
          <button name="decision" value="approve" style="background:#007a00">Wprowadź</button>
          <button name="decision" value="reject" style="background:#a20025">Odrzuć</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="text-muted small mt-2">Przy zmianie rachunku potwierdź ją z osobą innym kanałem (telefon na znany numer), zanim klikniesz „Wprowadź”.</p>
  <?php endif; ?>

  <h2>Dziennik zmian<?= $filterUser ? ' · <a href="' . h(route('cdc.admin')) . '" style="font-size:.85rem">pokaż wszystkich</a>' : '' ?></h2>
  <?php if (!$log): ?><div class="cda-empty">Dziennik jest pusty.</div><?php else: ?>
  <table>
    <tr><th>Kiedy</th><th>Osoba</th><th>Pole</th><th>Zmiana</th><th>Status</th><th>Kto</th></tr>
    <?php foreach ($log as $c): $st = CollabChangeStatus::tryFrom((string)$c['status']) ?? CollabChangeStatus::Applied; ?>
    <tr>
      <td><?= h(date('d.m.Y H:i', strtotime((string)$c['created_at']))) ?></td>
      <td><a href="?u=<?= (int)$c['user_id'] ?>"><?= h($c['user_name']) ?></a></td>
      <td><?= h($fields[$c['field']]['label'] ?? $c['field']) ?></td>
      <td><?= h(CollaboratorDataService::display((string)$c['field'], $c['old_value'])) ?> → <b><?= h(CollaboratorDataService::display((string)$c['field'], $c['new_value'])) ?></b></td>
      <td><span class="st" style="background:<?= $st->color() ?>"><?= h($st->label()) ?></span><?= $c['decision_note'] ? '<br><small>' . h($c['decision_note']) . '</small>' : '' ?></td>
      <td><?= h($c['actor_name'] ?? '') ?><?= $c['decider_name'] && $c['decided_by'] !== $c['changed_by'] ? '<br><small class="text-muted">decyzja: ' . h($c['decider_name']) . '</small>' : '' ?><br><small class="text-muted"><?= h($c['source']) ?></small></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php';
