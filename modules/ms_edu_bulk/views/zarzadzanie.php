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
  <div class="card border-0 shadow-sm mb-3 position-sticky" style="top:.5rem;z-index:5"><div class="card-body py-2">
    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
      <span class="badge text-bg-primary" id="zaCount">0 zaznaczonych</span>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('zaAll').click()">Zaznacz / odznacz wszystkie</button>
      <?php if ($zaAllowRoles): ?><button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.zaSel').forEach(function(c){c.checked=c.dataset.grupa===''});zaCount()">Zaznacz bez grupy</button><?php endif; ?>
      <span class="small text-muted ms-auto">Akcje działają na zaznaczone konta · szybkie zawieszenie jest przy każdym koncie</span>
    </div>
    <div class="row g-3">
      <div class="col-lg-3"><div class="border rounded p-2 h-100">
        <div class="small fw-bold text-uppercase text-muted mb-2">Dostęp do konta</div>
        <div class="d-grid gap-1">
          <button name="_op" value="suspend" class="btn btn-sm btn-outline-danger text-start" onclick="return confirm('Zawiesić zaznaczone konta (blokada logowania, wylogowanie sesji)?')"><i class="bi bi-person-lock me-1" aria-hidden="true"></i>Zawieś (offboarding)</button>
          <button name="_op" value="restore" class="btn btn-sm btn-outline-success text-start"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Przywróć</button>
          <?php if ($zaAllowRoles): ?><button name="_op" value="reset_mail" class="btn btn-sm btn-outline-warning text-start" onclick="return confirm('Zresetować hasła zaznaczonych kont i WYSŁAĆ im nowe dane e-mailem (na adres alternatywny)? Poprzednie hasła przestaną działać. Konta bez e-maila zostaną pominięte.')"><i class="bi bi-envelope-arrow-up me-1" aria-hidden="true"></i>Resetuj hasła i wyślij e-mail</button><?php endif; ?>
        </div>
        <?php if ($zaAllowRoles): ?>
        <div class="border-top mt-2 pt-2"><div class="small fw-semibold mb-1"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Regeneracja konta (przycisk przy koncie)</div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="zaRegMail" name="regen_mail" value="1" checked>
            <label class="form-check-label small" for="zaRegMail">Wyślij e-mail z danymi i przeprosinami</label></div>
          <label class="form-label small mb-0 mt-1" for="zaRegMsg">Własna wiadomość w mailu (opcj.)</label>
          <textarea id="zaRegMsg" name="regen_msg" rows="2" class="form-control form-control-sm" maxlength="1000" placeholder="np. Prosimy o zalogowanie się do piątku."></textarea>
          <button name="_op" value="regenerate" class="btn btn-sm btn-outline-warning w-100 mt-2" onclick="return confirm('Zregenerować zaznaczone konta w tenancie EDU? Zostaną utworzone ponownie z tym samym loginem tam, gdzie ich brakuje (istniejące konta nie są ruszane). Jeśli zaznaczono opcję e-maila, osoby z adresem alternatywnym dostaną dane i przeprosiny.')"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Regeneruj zaznaczone</button>
        </div>
        <?php endif; ?>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="zaLic" name="licenses" value="1" checked>
          <label class="form-check-label small" for="zaLic">Zawieszając, odbierz licencje</label></div>
      </div></div>

      <div class="col-lg-5"><div class="border rounded p-2 h-100">
        <div class="small fw-bold text-uppercase text-muted mb-2">Licencje i grupy</div>
        <div class="row g-2 align-items-end">
          <?php for ($n = 0; $n < 2; $n++): ?>
          <div class="col-6"><label class="form-label small fw-semibold mb-0" for="zaSku<?= $n ?>">Licencja <?= $n + 1 ?><?= $n ? ' (opcj.)' : '' ?></label>
            <select id="zaSku<?= $n ?>" name="skus[]" class="form-select form-select-sm"><option value="">— brak —</option>
              <?php foreach (($zaSkus ?? []) as $sk): ?><option value="<?= h($sk['skuId']) ?>"><?= h($sk['skuPartNumber']) ?></option><?php endforeach; ?></select></div>
          <?php endfor; ?>
          <div class="col-12"><button name="_op" value="license" class="btn btn-sm btn-outline-primary w-100" onclick="return confirm('Przypisać wybrane licencje zaznaczonym kontom?')"><i class="bi bi-award me-1" aria-hidden="true"></i>Przypisz licencje</button></div>
          <div class="col-12"><?= msEduBulkGroupField('', msEduBulkTiGroups(), 'zaGw', msEduBulkEntraGroups()) ?></div>
          <div class="col-12"><button name="_op" value="group" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Przypisz do grupy</button></div>
        </div>
      </div></div>

      <div class="col-lg-4"><div class="border rounded p-2 h-100">
        <div class="small fw-bold text-uppercase text-muted mb-2">Organizacja i bezpieczeństwo</div>
        <label class="form-label small fw-semibold mb-0" for="zaMgr">Menedżer (UPN)</label>
        <div class="input-group input-group-sm mb-2"><input id="zaMgr" name="manager" class="form-control" placeholder="jan.kowalski@domena">
          <button name="_op" value="manager" class="btn btn-outline-primary"><i class="bi bi-diagram-2 me-1" aria-hidden="true"></i>Ustaw</button></div>
        <div class="btn-group btn-group-sm w-100 mb-2" role="group" aria-label="MFA">
          <button name="_op" value="mfa" class="btn btn-outline-primary"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Wymagaj MFA</button>
          <button name="_op" value="mfa_off" class="btn btn-outline-secondary" onclick="return confirm('Wyłączyć wymuszanie MFA dla zaznaczonych kont?')"><i class="bi bi-shield-slash me-1" aria-hidden="true"></i>Wyłącz MFA</button>
        </div>
        <?php if ($zaAllowRoles): ?>
        <label class="form-label small fw-semibold mb-0" for="zaRole">Rola administracyjna Entra</label>
        <div class="input-group input-group-sm"><select id="zaRole" name="role" class="form-select"><option value="">— wybierz —</option>
          <?php foreach (M365Graph::SAFE_DIRECTORY_ROLES as $k => $r): ?><option value="<?= h($k) ?>"><?= h($r[1]) ?></option><?php endforeach; ?></select>
          <button name="_op" value="role" class="btn btn-outline-warning" onclick="return confirm('Nadać rolę administracyjną w Entra ID zaznaczonym kontom?')"><i class="bi bi-key me-1" aria-hidden="true"></i>Nadaj</button></div>
        <?php endif; ?>
      </div></div>

      <?php if ($zaAllowRoles): ?>
      <div class="col-12"><div class="border border-danger-subtle rounded p-2 d-flex align-items-center gap-2 flex-wrap">
        <span class="small fw-bold text-uppercase text-danger">Strefa niebezpieczna</span>
        <span class="small text-muted">Trwałe usunięcie z Entra ID — tylko konta bez przypisanej grupy.</span>
        <button name="_op" value="delete" class="btn btn-sm btn-danger ms-auto" onclick="return confirm('TRWALE usunąć zaznaczone konta z Entra ID? Dotyczy tylko kont bez przypisanej grupy. Tej operacji nie można cofnąć.')"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konta bez grupy</button>
      </div></div>
      <?php endif; ?>
    </div>
  </div></div>
  <div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.85rem">
    <thead class="table-light"><tr><th style="width:2rem"><input type="checkbox" id="zaAll" aria-label="Zaznacz wszystkie"></th><th>Osoba / konto</th><th>Grupa</th><th>Login</th><th>Entra ID</th><th>Status</th><th>Menedżer</th><th>MFA</th><th>Rola Entra</th><th>Utworzono</th><th class="text-end">Akcje</th></tr></thead>
    <tbody>
    <?php if (!$zaRows): ?><tr><td colspan="11" class="text-center text-muted py-4">Brak kont w rejestrze (rejestr zapełnia się od momentu wdrożenia — konta tworzone wcześniej nie są w nim ujęte).</td></tr><?php endif; ?>
    <?php foreach ($zaRows as $r): ?>
      <tr><td><input type="checkbox" class="zaSel" name="ids[]" value="<?= (int)$r['id'] ?>" data-grupa="<?= h($r['grupa'] ?? '') ?>"></td>
        <td><?= h(trim($r['imie'] . ' ' . $r['nazwisko'])) ?><?= $r['anonim'] ? ' <span class="badge bg-secondary">anonim</span>' : '' ?></td>
        <td><?= ($r['grupa'] ?? '') !== '' ? h($r['grupa']) . (empty($r['grupa_ok']) ? ' <span class="badge bg-warning text-dark" title="dodanie do grupy w MS365 nieudane">!</span>' : '') : '<span class="text-muted">bez grupy</span>' ?></td><td class="font-monospace"><?php if (!empty($zaDetailUrl)): ?><a href="<?= h($zaDetailUrl) ?>?id=<?= (int)$r['id'] ?>"><?= h($r['upn']) ?></a><?php else: ?><?= h($r['upn']) ?><?php endif; ?></td>
        <td class="small font-monospace"><?php $eid = (string)($r['ms_user_id'] ?? ''); ?><?= $eid !== '' ? '<span title="' . h($eid) . '">' . h(substr($eid, 0, 8)) . '…</span> <button type="button" class="btn btn-sm btn-link p-0 align-baseline" title="Kopiuj Entra ID (Object ID)" aria-label="Kopiuj Entra ID konta ' . h($r['upn']) . '" onclick="navigator.clipboard&&navigator.clipboard.writeText(\'' . h($eid) . '\')"><i class="bi bi-clipboard" aria-hidden="true"></i></button>' : '<span class="badge bg-warning text-dark" title="Brak identyfikatora — konto mogło nie zostać utworzone w Entra ID">brak</span>' ?></td>
        <td><?= $r['status'] === 'suspended' ? '<span class="badge bg-danger">zawieszone</span>' : '<span class="badge bg-success">aktywne</span>' ?></td>
        <td class="small"><?= h($r['manager_upn'] ?: '—') ?></td><td><?= $r['mfa'] ? '<i class="bi bi-shield-check text-success" aria-label="tak"></i>' : '—' ?></td>
        <td class="small"><?= h($r['admin_role'] ?: '—') ?></td><td class="text-muted small"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
        <td class="text-end text-nowrap">
          <?php $_susp = $r['status'] === 'suspended'; ?>
          <button type="submit" name="_op" value="<?= $_susp ? 'restore_one' : 'suspend_one' ?>" formnovalidate class="btn btn-sm <?= $_susp ? 'btn-outline-success' : 'btn-outline-danger' ?>"
                  onclick="document.getElementById('zaOne').value='<?= (int)$r['id'] ?>'; return <?= $_susp ? 'true' : "confirm('Zawiesić konto " . h(addslashes($r['upn'])) . " (blokada logowania, wylogowanie sesji)?')" ?>"
                  title="<?= $_susp ? 'Przywróć konto' : 'Szybkie zawieszenie konta' ?>"><i class="bi <?= $_susp ? 'bi-person-check' : 'bi-person-lock' ?>" aria-hidden="true"></i><span class="visually-hidden"><?= $_susp ? 'Przywróć' : 'Zawieś' ?></span></button>
          <?php if ($zaAllowRoles): ?><button type="submit" name="_op" value="regenerate_one" formnovalidate class="btn btn-sm btn-outline-warning"
                  onclick="document.getElementById('zaOne').value='<?= (int)$r['id'] ?>'; return confirm('Zregenerować konto <?= h(addslashes($r['upn'])) ?> w tenancie EDU? Zostanie utworzone ponownie z tym samym loginem, o ile go tam brak (istniejące konto nie jest ruszane).')"
                  title="Regeneruj konto — utwórz ponownie w Entra ID, jeśli go brak"><i class="bi bi-arrow-repeat" aria-hidden="true"></i><span class="visually-hidden">Regeneruj</span></button><?php endif; ?>
          <?php if (!empty($zaDetailUrl)): ?><a href="<?= h($zaDetailUrl) ?>?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Szczegóły konta i zarządzanie grupami"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Szczegóły / grupy</a><?php endif; ?>
        </td>
</tr>
    <?php endforeach; ?>
    </tbody></table></div>
</form>
<script>function zaCount(){var n=document.querySelectorAll('.zaSel:checked').length;document.getElementById('zaCount').textContent=n+' zaznaczonych';}
document.querySelectorAll('.zaSel').forEach(function(c){c.addEventListener('change',zaCount)});
document.getElementById('zaAll').addEventListener('change', function () { document.querySelectorAll('.zaSel').forEach(function (c) { c.checked = this.checked; }, this); zaCount(); });</script>
