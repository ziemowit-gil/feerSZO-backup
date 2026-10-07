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
  <p class="small text-muted mb-2">Odpytany tenant: <code><?= h($cmpTenant['id'] ?? '—') ?></code><?= !empty($cmpTenant['domain']) ? ' · domena <code>' . h($cmpTenant['domain']) . '</code>' : '' ?>
    — porównaj z katalogiem (Directory ID), w którym szukasz kont w portalu Entra.</p>
  <div class="d-flex gap-2 flex-wrap mb-3">
    <span class="badge text-bg-secondary">w tenancie: <?= (int)$cmp['tenant'] ?></span>
    <span class="badge text-bg-success">zgodne z rejestrem: <?= (int)$cmp['ok'] ?></span>
    <span class="badge text-bg-<?= $cmp['missing'] ? 'danger' : 'secondary' ?>">w rejestrze, brak w tenancie: <?= count($cmp['missing']) ?></span>
    <span class="badge text-bg-<?= $cmp['szoUnregistered'] ? 'warning' : 'secondary' ?>">SZO poza rejestrem: <?= count($cmp['szoUnregistered']) ?></span>
    <span class="badge text-bg-<?= $cmp['szoMissing'] ? 'danger' : 'secondary' ?>">SZO bez konta w tenancie: <?= count($cmp['szoMissing']) ?></span>
    <span class="badge text-bg-<?= $cmp['extra'] ? 'warning' : 'secondary' ?>">w tenancie, brak w rejestrze: <?= count($cmp['extra']) ?></span>
  </div>

  <h2 class="h6 fw-bold">Konta kursantów SZO — z kontem Microsoft w tenancie, ale spoza rejestru EDU</h2>
  <?php if (!$cmp['szoUnregistered']): ?><p class="text-muted small">Brak — wszystkie konta kursantów SZO z kontem Microsoft są w rejestrze.</p><?php else: ?>
  <form method="post" action="<?= h($self) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.85rem">
      <thead class="table-light"><tr><th style="width:2rem"><input type="checkbox" aria-label="Zaznacz wszystkie" onchange="document.querySelectorAll('.szoReg').forEach(c=>c.checked=this.checked)" checked></th><th>Kursant</th><th>Login Microsoft</th></tr></thead>
      <tbody><?php foreach ($cmp['szoUnregistered'] as $a): ?>
        <tr><td><input type="checkbox" class="szoReg" name="sids[]" value="<?= (int)$a['id'] ?>" checked></td><td><?= h($a['client_name']) ?></td><td class="font-monospace"><?= h($a['ms_upn']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <button name="_op" value="register_szo" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-plus me-1" aria-hidden="true"></i>Dodaj zaznaczone do rejestru EDU</button>
    <span class="small text-muted ms-2">Tylko wpis w SZO — konta w tenancie nie są zmieniane.</span>
  </form>
  <?php endif; ?>

  <h2 class="h6 fw-bold mt-4">Konta kursantów SZO — przypisane konto Microsoft nie istnieje w tenancie</h2>
  <?php if (!$cmp['szoMissing']): ?><p class="text-muted small">Brak — każde przypisane konto istnieje w tenancie.</p><?php else: ?>
  <form method="post" action="<?= h($self) ?>" onsubmit="return confirm('UTWORZYĆ REALNE konta w tenancie EDU dla zaznaczonych kursantów? Nowy login i hasło zostaną wysłane kursantom (e-mail/SMS).')">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.85rem">
      <thead class="table-light"><tr><th style="width:2rem"><input type="checkbox" aria-label="Zaznacz wszystkie" onchange="document.querySelectorAll('.szoNew').forEach(c=>c.checked=this.checked)"></th><th>Kursant</th><th>Dotychczasowy login Microsoft</th></tr></thead>
      <tbody><?php foreach ($cmp['szoMissing'] as $a): ?>
        <tr><td><input type="checkbox" class="szoNew" name="sids[]" value="<?= (int)$a['id'] ?>"></td><td><?= h($a['client_name']) ?></td><td class="font-monospace"><?= h($a['ms_upn'] ?: '—') ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <button name="_op" value="recreate_szo" class="btn btn-sm btn-danger"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Utwórz zaznaczone konta w tenancie EDU</button>
  </form>
  <?php endif; ?>

  <h2 class="h6 fw-bold mt-4">W rejestrze, ale nie istnieją w tenancie (usunięte poza SZO)</h2>
  <?php if (!$cmp['missing']): ?><p class="text-muted small">Wszystkie konta z rejestru istnieją w tenancie.</p><?php else: ?>
  <form method="post" action="<?= h($self) ?>">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.85rem">
      <thead class="table-light"><tr><th style="width:2rem"><input type="checkbox" aria-label="Zaznacz wszystkie" onchange="document.querySelectorAll('.cmpSel').forEach(c=>c.checked=this.checked)" checked></th><th>Osoba</th><th>Login</th><th>Grupa</th><th>Utworzono</th></tr></thead>
      <tbody><?php foreach ($cmp['missing'] as $r): ?>
        <tr><td><input type="checkbox" class="cmpSel" name="ids[]" value="<?= (int)$r['id'] ?>" checked></td>
          <td><?= h(trim($r['imie'] . ' ' . $r['nazwisko'])) ?></td><td class="font-monospace"><?= h($r['upn']) ?></td>
          <td class="small"><?= h($r['grupa'] ?: '—') ?></td><td class="text-muted small"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <div class="row g-2 mb-2">
      <div class="col-lg-6"><label class="form-label small fw-semibold mb-0" for="cmpPw">Hasła, które mają zostać bez zmian <span class="text-muted fw-normal">(opcjonalnie, „login hasło” w linii — jak w PDF z generatora)</span></label>
        <textarea id="cmpPw" name="passwords" rows="3" class="form-control form-control-sm font-monospace" placeholder="i.nowak2026@domena   Haslo123..."></textarea>
        <div class="form-text">Hasła nie są zapisywane w rejestrze. Bez wpisu (i spoza bieżących wyników generatora) konto dostanie nowe hasło — zostanie pokazane w wyniku.</div></div>
      <div class="col-lg-3"><label class="form-label small fw-semibold mb-0" for="cmpSku">Licencja (opcj.)</label>
        <select id="cmpSku" name="skus[]" class="form-select form-select-sm"><option value="">— brak —</option>
          <?php foreach (($zaSkus ?? []) as $sk): ?><option value="<?= h($sk['skuId']) ?>"><?= h($sk['skuPartNumber']) ?></option><?php endforeach; ?></select></div>
    </div>
    <button name="_op" value="recreate_registry" class="btn btn-sm btn-danger" formnovalidate onclick="return confirm('UTWORZYĆ REALNE konta w tenancie EDU dla zaznaczonych (ten sam login)? Hasła z listy zostają bez zmian; konta już istniejące w tenancie nie są ruszane.')"><i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>Utwórz zaznaczone w tenancie EDU</button>
    <button name="_op" value="sync_missing" class="btn btn-sm btn-outline-danger" formnovalidate onclick="return confirm('Przenieść zaznaczone konta do rejestru usuniętych? Tenant nie jest zmieniany.')"><i class="bi bi-archive me-1" aria-hidden="true"></i>Przenieś zaznaczone do usuniętych</button>
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
