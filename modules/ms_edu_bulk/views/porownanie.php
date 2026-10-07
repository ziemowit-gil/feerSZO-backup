<?php
/** Porównanie rejestru generatora z listą użytkowników tenanta EDU. Wejście: $cmp (null = jeszcze nie pobrano), $cmpErr, $self. */
?>
<form method="post" action="<?= h($self) ?>" class="mb-3 d-flex align-items-center gap-2 flex-wrap">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <button name="_op" value="compare" class="btn btn-sm btn-primary"><i class="bi bi-cloud-arrow-down me-1" aria-hidden="true"></i>Pobierz listę z tenanta i porównaj</button>
  <span class="small text-muted">Tylko odczyt z Entra ID — nic nie jest zmieniane ani usuwane w tenancie.</span>
</form>
<?php if ($cmpErr): ?><div class="alert alert-danger" role="alert"><?= h($cmpErr) ?></div><?php endif; ?>
<?php if ($cmp): ?>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <span class="badge text-bg-secondary">w tenancie: <?= (int)$cmp['tenant'] ?></span>
    <span class="badge text-bg-success">zgodne z rejestrem: <?= (int)$cmp['ok'] ?></span>
    <span class="badge text-bg-<?= $cmp['missing'] ? 'danger' : 'secondary' ?>">w rejestrze, brak w tenancie: <?= count($cmp['missing']) ?></span>
    <span class="badge text-bg-<?= $cmp['extra'] ? 'warning' : 'secondary' ?>">w tenancie, brak w rejestrze: <?= count($cmp['extra']) ?></span>
  </div>

  <h2 class="h6 fw-bold">W rejestrze, ale nie istnieją w tenancie (usunięte poza SZO)</h2>
  <?php if (!$cmp['missing']): ?><p class="text-muted small">Wszystkie konta z rejestru istnieją w tenancie.</p><?php else: ?>
  <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('Przenieść zaznaczone konta do rejestru usuniętych? Tenant nie jest zmieniany.')">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.85rem">
      <thead class="table-light"><tr><th style="width:2rem"><input type="checkbox" aria-label="Zaznacz wszystkie" onchange="document.querySelectorAll('.cmpSel').forEach(c=>c.checked=this.checked)" checked></th><th>Osoba</th><th>Login</th><th>Grupa</th><th>Utworzono</th></tr></thead>
      <tbody><?php foreach ($cmp['missing'] as $r): ?>
        <tr><td><input type="checkbox" class="cmpSel" name="ids[]" value="<?= (int)$r['id'] ?>" checked></td>
          <td><?= h(trim($r['imie'] . ' ' . $r['nazwisko'])) ?></td><td class="font-monospace"><?= h($r['upn']) ?></td>
          <td class="small"><?= h($r['grupa'] ?: '—') ?></td><td class="text-muted small"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <button name="_op" value="sync_missing" class="btn btn-sm btn-outline-danger"><i class="bi bi-archive me-1" aria-hidden="true"></i>Przenieś zaznaczone do usuniętych</button>
  </form>
  <?php endif; ?>

  <h2 class="h6 fw-bold mt-4">W tenancie, ale spoza rejestru generatora</h2>
  <?php if (!$cmp['extra']): ?><p class="text-muted small">Brak — każde konto w tenancie jest w rejestrze.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.85rem">
    <thead class="table-light"><tr><th>Nazwa</th><th>Login</th><th>Status</th><th>Utworzono</th></tr></thead>
    <tbody><?php foreach ($cmp['extra'] as $u): ?>
      <tr><td><?= h($u['displayName'] ?? '—') ?></td><td class="font-monospace"><?= h($u['userPrincipalName'] ?? '') ?></td>
        <td><?= ($u['accountEnabled'] ?? true) ? '<span class="badge bg-success">aktywne</span>' : '<span class="badge bg-danger">wyłączone</span>' ?></td>
        <td class="text-muted small"><?= h(substr((string)($u['createdDateTime'] ?? ''), 0, 10)) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
<?php endif; ?>
