<?php
/** Budżety i granty z limitami. Zmienne: $rows, $sum, $edit, $errors, $role. */
$PAGE_TITLE = 'Budżety i granty — noclegi'; $active = 'budzety';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
$isLog = $role === 'logistyk';
$ev = fn(string $k, $d = '') => $edit[$k] ?? $d;
$ee = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="be-' . h($k) . '">' . h($errors[$k]) . '</div>' : '';
?>
<h1>Budżety i granty</h1>
<p class="ncl-lead">Koszty noclegów przypisane do projektów i grantów. Limit całkowity i limit za osobę za noc pilnują, by nie dało się potwierdzić rezerwacji ponad budżet (0 = bez limitu). Koszt liczymy jako noce × cena pokoju.</p>
<?php if (!$rows): ?><p class="ncl-muted">Brak budżetów<?= $isLog ? ' — dodaj pierwszy poniżej' : ' przypisanych do Twojej roli' ?>.</p><?php else: ?>
<div class="ncl-scroll"><table class="ncl-table"><caption>Budżety noclegowe</caption>
  <thead><tr><th scope="col">Kod</th><th scope="col">Nazwa</th><th scope="col">Okres</th><th scope="col">Limit</th><th scope="col">Zobowiązania</th><th scope="col">Faktury</th><th scope="col">Wykorzystanie</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead><tbody>
  <?php foreach ($rows as $b): $s = $sum[$b['id']]; $pct = $s['limit'] > 0 ? (int)round(100 * max($s['committed'], $s['invoiced']) / $s['limit']) : 0; ?>
  <tr>
    <td><?= h($b['code']) ?><?= $b['is_active'] ? '' : ' <span class="badge text-bg-secondary">zamknięty</span>' ?></td><td><?= h($b['name']) ?></td>
    <td><?= $b['valid_from'] || $b['valid_to'] ? h(($b['valid_from'] ? date('d.m.Y', strtotime($b['valid_from'])) : '…') . ' – ' . ($b['valid_to'] ? date('d.m.Y', strtotime($b['valid_to'])) : '…')) : '—' ?></td>
    <td><?= $s['limit'] > 0 ? h(noclMoneyFmt($s['limit'])) : 'bez limitu' ?><?= (float)$b['limit_per_night'] > 0 ? '<br><small class="ncl-muted">' . h(noclMoneyFmt((float)$b['limit_per_night'])) . '/os./noc</small>' : '' ?></td>
    <td><?= h(noclMoneyFmt($s['committed'])) ?></td><td><?= h(noclMoneyFmt($s['invoiced'])) ?><br><small class="ncl-muted">zapłacone <?= h(noclMoneyFmt($s['paid'])) ?></small></td>
    <td style="min-width:130px"><?php if ($s['limit'] > 0): ?><div class="ncl-bar<?= $pct > 100 ? ' over' : '' ?>" role="img" aria-label="<?= $pct ?> procent limitu"><i style="width:<?= min(100, $pct) ?>%"></i></div><small><?= $pct ?>%</small><?php else: ?>—<?php endif; ?></td>
    <td><?php if ($isLog): ?><a href="<?= h(route('nocl.budgets') . (str_contains(route('nocl.budgets'), '?') ? '&' : '?') . 'edytuj=' . (int)$b['id'] . '#form-budzet') ?>">Edytuj<span class="visually-hidden"> budżet <?= h($b['code']) ?></span></a><?php endif; ?></td>
  </tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<?php if ($isLog): ?>
<form method="post" action="<?= h(route('nocl.budget.save')) ?>" id="form-budzet" class="ncl-card mt-3" novalidate>
  <?= Router::csrfField() ?><input type="hidden" name="id" value="<?= (int)$ev('id', 0) ?>">
  <h2 class="mt-0"><?= $edit ? 'Edytuj budżet ' . h($ev('code')) : 'Dodaj budżet / grant' ?></h2>
  <div class="row g-3">
    <div class="col-sm-3"><label class="form-label" for="code">Kod *</label><input class="form-control<?= isset($errors['code']) ? ' is-invalid' : '' ?>" id="code" name="code" required value="<?= h($ev('code')) ?>"><?= $ee('code') ?></div>
    <div class="col-sm-9"><label class="form-label" for="bname">Nazwa projektu / grantu *</label><input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="bname" name="name" required value="<?= h($ev('name')) ?>"><?= $ee('name') ?></div>
    <div class="col-sm-3"><label class="form-label" for="limit_total">Limit całkowity (zł)</label><input class="form-control<?= isset($errors['limit_total']) ? ' is-invalid' : '' ?>" inputmode="decimal" id="limit_total" name="limit_total" value="<?= h($ev('limit_total', '0')) ?>"><?= $ee('limit_total') ?></div>
    <div class="col-sm-3"><label class="form-label" for="limit_per_night">Limit os./noc (zł)</label><input class="form-control" inputmode="decimal" id="limit_per_night" name="limit_per_night" value="<?= h($ev('limit_per_night', '0')) ?>"></div>
    <div class="col-sm-3"><label class="form-label" for="valid_from">Obowiązuje od</label><input class="form-control<?= isset($errors['valid_from']) ? ' is-invalid' : '' ?>" type="date" id="valid_from" name="valid_from" value="<?= h($ev('valid_from')) ?>"><?= $ee('valid_from') ?></div>
    <div class="col-sm-3"><label class="form-label" for="valid_to">Obowiązuje do</label><input class="form-control<?= isset($errors['valid_to']) ? ' is-invalid' : '' ?>" type="date" id="valid_to" name="valid_to" value="<?= h($ev('valid_to')) ?>"><?= $ee('valid_to') ?></div>
    <div class="col-12"><label class="form-label" for="note">Notatka</label><input class="form-control" id="note" name="note" value="<?= h($ev('note')) ?>"></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="b-act" name="is_active" value="1" <?= !$edit || $ev('is_active') ? 'checked' : '' ?>><label class="form-check-label" for="b-act">Budżet otwarty (można do niego przypisywać zgłoszenia)</label></div></div>
  </div>
  <button class="btn btn-primary mt-3">Zapisz budżet</button>
  <?php if ($edit): ?><a class="btn btn-outline-secondary mt-3" href="<?= h(route('nocl.budgets')) ?>">Anuluj</a><?php endif; ?>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
