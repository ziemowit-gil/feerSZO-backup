<?php
/**
 * Zarządzanie kontami EDU z rejestru generatora.
 * Wejście: $zaSkus (SKU z tenanta), [$zaDetailUrl — link do podglądu konta, tylko admin], $zaGrupa (filtr: ''|brak|jest), $zaRows, $zaAction, $zaTokenName, $zaTokenValue, $zaQ, $zaStatus, $zaAllowRoles (bool — role Entra tylko dla admina).
 */
?>
<form method="get" class="d-flex gap-2 mb-3 flex-wrap">
  <input name="q" value="<?= h($zaQ) ?>" class="form-control form-control-sm" style="max-width:20rem" placeholder="Szukaj: login, imię, klasa…" aria-label="Szukaj">
  <select name="status" class="form-select form-select-sm" style="max-width:11rem" aria-label="Status">
    <option value="">wszystkie</option><option value="active"<?= $zaStatus === 'active' ? ' selected' : '' ?>>aktywne</option><option value="suspended"<?= $zaStatus === 'suspended' ? ' selected' : '' ?>>zawieszone</option></select>
  <select name="grupa" class="form-select form-select-sm" style="max-width:12rem" aria-label="Grupa">
    <option value="">grupa: wszystkie</option><option value="brak"<?= $zaGrupa === 'brak' ? ' selected' : '' ?>>bez grupy</option><option value="jest"<?= $zaGrupa === 'jest' ? ' selected' : '' ?>>z grupą</option></select>
  <button class="btn btn-sm btn-outline-secondary">Filtruj</button>
</form>
<form method="post" action="<?= h($zaAction) ?>" id="zaForm">
  <input type="hidden" name="<?= h($zaTokenName) ?>" value="<?= h($zaTokenValue) ?>"><input type="hidden" name="one" id="zaOne" value="">
  <div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label small fw-semibold" for="zaMgr">Menedżer (UPN)</label>
        <input id="zaMgr" name="manager" class="form-control form-control-sm" placeholder="np. jan.kowalski@domena"></div>
      <div class="col-md-4"><?= msEduBulkGroupField('', msEduBulkTiGroups(), 'zaGw', msEduBulkEntraGroups()) ?></div>
      <?php if ($zaAllowRoles): ?>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="zaRole">Rola administracyjna Entra</label>
        <select id="zaRole" name="role" class="form-select form-select-sm"><option value="">— wybierz —</option>
          <?php foreach (M365Graph::SAFE_DIRECTORY_ROLES as $k => $r): ?><option value="<?= h($k) ?>"><?= h($r[1]) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <?php for ($n = 0; $n < 2; $n++): ?>
      <div class="col-md-2"><label class="form-label small fw-semibold" for="zaSku<?= $n ?>">Licencja <?= $n + 1 ?><?= $n ? ' (opcj.)' : '' ?></label>
        <select id="zaSku<?= $n ?>" name="skus[]" class="form-select form-select-sm"><option value="">— brak —</option>
          <?php foreach (($zaSkus ?? []) as $sk): ?><option value="<?= h($sk['skuId']) ?>"><?= h($sk['skuPartNumber']) ?></option><?php endforeach; ?></select></div>
      <?php endfor; ?>
      <div class="col-auto form-check ms-2"><input class="form-check-input" type="checkbox" id="zaLic" name="licenses" value="1" checked>
        <label class="form-check-label small" for="zaLic">Zawieszając, odbierz licencje</label></div>
    </div>
    <div class="d-flex gap-2 flex-wrap mt-3">
      <button name="_op" value="suspend" class="btn btn-sm btn-outline-danger" onclick="return confirm('Zawiesić zaznaczone konta (blokada logowania, wylogowanie sesji)?')"><i class="bi bi-person-lock me-1" aria-hidden="true"></i>Zawieś (offboarding)</button>
      <button name="_op" value="restore" class="btn btn-sm btn-outline-success"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Przywróć</button>
      <button name="_op" value="manager" class="btn btn-sm btn-outline-primary"><i class="bi bi-diagram-2 me-1" aria-hidden="true"></i>Ustaw menedżera</button>
      <button name="_op" value="license" class="btn btn-sm btn-outline-primary" onclick="return confirm('Przypisać wybrane licencje zaznaczonym kontom?')"><i class="bi bi-award me-1" aria-hidden="true"></i>Przypisz licencje</button>
      <button name="_op" value="group" class="btn btn-sm btn-outline-primary"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Przypisz do grupy</button>
      <button name="_op" value="mfa" class="btn btn-sm btn-outline-primary"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Wymagaj MFA</button>
      <?php if ($zaAllowRoles): ?><button name="_op" value="delete" class="btn btn-sm btn-danger ms-auto" onclick="return confirm('TRWALE usunąć zaznaczone konta z Entra ID? Dotyczy tylko kont bez przypisanej grupy. Tej operacji nie można cofnąć.')"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konta bez grupy</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.zaSel').forEach(function(c){c.checked=c.dataset.grupa===''})">Zaznacz bez grupy</button><?php endif; ?>
      <?php if ($zaAllowRoles): ?><button name="_op" value="role" class="btn btn-sm btn-outline-warning" onclick="return confirm('Nadać rolę administracyjną w Entra ID zaznaczonym kontom?')"><i class="bi bi-key me-1" aria-hidden="true"></i>Nadaj rolę</button><?php endif; ?>
    </div>
  </div></div>
  <div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.85rem">
    <thead class="table-light"><tr><th style="width:2rem"><input type="checkbox" id="zaAll" aria-label="Zaznacz wszystkie"></th><th>Osoba / konto</th><th>Grupa</th><th>Login</th><th>Status</th><th>Menedżer</th><th>MFA</th><th>Rola Entra</th><th>Utworzono</th><th class="text-end">Akcje</th></tr></thead>
    <tbody>
    <?php if (!$zaRows): ?><tr><td colspan="10" class="text-center text-muted py-4">Brak kont w rejestrze (rejestr zapełnia się od momentu wdrożenia — konta tworzone wcześniej nie są w nim ujęte).</td></tr><?php endif; ?>
    <?php foreach ($zaRows as $r): ?>
      <tr><td><input type="checkbox" class="zaSel" name="ids[]" value="<?= (int)$r['id'] ?>" data-grupa="<?= h($r['grupa'] ?? '') ?>"></td>
        <td><?= h(trim($r['imie'] . ' ' . $r['nazwisko'])) ?><?= $r['anonim'] ? ' <span class="badge bg-secondary">anonim</span>' : '' ?></td>
        <td><?= ($r['grupa'] ?? '') !== '' ? h($r['grupa']) . (empty($r['grupa_ok']) ? ' <span class="badge bg-warning text-dark" title="dodanie do grupy w MS365 nieudane">!</span>' : '') : '<span class="text-muted">bez grupy</span>' ?></td><td class="font-monospace"><?php if (!empty($zaDetailUrl)): ?><a href="<?= h($zaDetailUrl) ?>?id=<?= (int)$r['id'] ?>"><?= h($r['upn']) ?></a><?php else: ?><?= h($r['upn']) ?><?php endif; ?></td>
        <td><?= $r['status'] === 'suspended' ? '<span class="badge bg-danger">zawieszone</span>' : '<span class="badge bg-success">aktywne</span>' ?></td>
        <td class="small"><?= h($r['manager_upn'] ?: '—') ?></td><td><?= $r['mfa'] ? '<i class="bi bi-shield-check text-success" aria-label="tak"></i>' : '—' ?></td>
        <td class="small"><?= h($r['admin_role'] ?: '—') ?></td><td class="text-muted small"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
        <td class="text-end text-nowrap">
          <?php $_susp = $r['status'] === 'suspended'; ?>
          <button type="submit" name="_op" value="<?= $_susp ? 'restore_one' : 'suspend_one' ?>" formnovalidate class="btn btn-sm <?= $_susp ? 'btn-outline-success' : 'btn-outline-danger' ?>"
                  onclick="document.getElementById('zaOne').value='<?= (int)$r['id'] ?>'; return <?= $_susp ? 'true' : "confirm('Zawiesić konto " . h(addslashes($r['upn'])) . " (blokada logowania, wylogowanie sesji)?')" ?>"
                  title="<?= $_susp ? 'Przywróć konto' : 'Szybkie zawieszenie konta' ?>"><i class="bi <?= $_susp ? 'bi-person-check' : 'bi-person-lock' ?>" aria-hidden="true"></i><span class="visually-hidden"><?= $_susp ? 'Przywróć' : 'Zawieś' ?></span></button>
          <?php if (!empty($zaDetailUrl)): ?><a href="<?= h($zaDetailUrl) ?>?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Szczegóły konta i zarządzanie grupami"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Szczegóły / grupy</a><?php endif; ?>
        </td>
</tr>
    <?php endforeach; ?>
    </tbody></table></div>
</form>
<script>document.getElementById('zaAll').addEventListener('change', function () { document.querySelectorAll('.zaSel').forEach(function (c) { c.checked = this.checked; }, this); });</script>
