<?php
/** Role modułu Noclegi. Zmienne: $rows, $budgets, $users, $role. */
$PAGE_TITLE = 'Role — noclegi'; $active = 'role';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
?>
<h1>Role w module Noclegi</h1>
<p class="ncl-lead">Role są osobne od ról SZO. <strong>Użytkownik</strong> to każde aktywne konto (własne zgłoszenia i rezerwacje). <strong>Koordynator Projektu</strong> akceptuje koszty zgłoszeń w swoich budżetach (wybierz budżet albo „wszystkie”). <strong>Logistyk / Administrator</strong> zarządza obiektami, roomingiem, fakturami i rolami; administrator SZO jest nim zawsze.</p>
<table class="ncl-table"><caption>Nadane role</caption>
  <thead><tr><th scope="col">Osoba</th><th scope="col">Rola</th><th scope="col">Zakres</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="4" class="ncl-muted">Brak nadanych ról (poza administratorami SZO).</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr>
    <td><?= h($r['name'] ?: '#' . $r['user_id']) ?><br><small class="ncl-muted"><?= h($r['email']) ?></small></td>
    <td><?= h(noclRoleModulu()[$r['role']] ?? $r['role']) ?></td>
    <td><?= $r['role'] === 'koordynator' ? ((int)$r['budget_id'] ? h($r['budget_code'] . ' — ' . $r['budget_name']) : 'wszystkie budżety') : 'cały moduł' ?></td>
    <td><form method="post" action="<?= h(route('nocl.role.del', ['id' => $r['id']])) ?>" onsubmit="return confirm('Odebrać tę rolę?');"><?= Router::csrfField() ?><button class="btn btn-sm btn-outline-danger">Odbierz<span class="visually-hidden"> rolę: <?= h(($r['name'] ?: '') . ', ' . (noclRoleModulu()[$r['role']] ?? '')) ?></span></button></form></td>
  </tr><?php endforeach; ?></tbody></table>
<form method="post" action="<?= h(route('nocl.role.add')) ?>" class="ncl-card mt-3">
  <?= Router::csrfField() ?>
  <h2 class="mt-0">Nadaj rolę</h2>
  <div class="row g-3 align-items-end">
    <div class="col-md-4"><label class="form-label" for="user_id">Osoba</label><select class="form-select" id="user_id" name="user_id" required><option value="">— wybierz —</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name'] . ' (' . $u['email'] . ')') ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="rola">Rola</label><select class="form-select" id="rola" name="role"><?php foreach (noclRoleModulu() as $c => $l): if ($c === 'uzytkownik') continue; ?><option value="<?= h($c) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label" for="rb">Budżet (dla Koordynatora)</label><select class="form-select" id="rb" name="budget_id"><option value="0">wszystkie budżety</option><?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['code'] . ' — ' . $b['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">Nadaj</button></div>
  </div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>
