<?php
/**
 * modules/ti_licencje_dydaktyczne/view/op_account.php — karta konta: dane, posiadane licencje (TI, AM5/AM7, Microsoft 365)
 * z aktualnym płatnikiem i akcjami AM20–AM24, nowe wnioski AM11–AM13, historia operacji.
 * Zmienne: $acc, $res, $list, $actor.
 */
require __DIR__ . '/_parts.php';
$title = $acc['name'] . ' — licencje';
include __DIR__ . '/_head.php';
$new = fn(string $form, string $key = '') => route('ld.op.new', array_filter(['konto' => $acc['kind'] . ':' . $acc['id'], 'form' => $form, 'zasob' => $key]));
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Sprawy AM5 / AM7</a><a href="<?= h(route('ld.accounts')) ?>">Operacje na licencjach</a><a href="#" aria-current="page"><?= h($acc['name']) ?></a></div>
<div class="tiles">
  <div class="tile wide" style="background:#1d1d1d"><span class="n" style="font-size:1.3rem;font-weight:600"><?= h($acc['name']) ?></span><span class="l"><?= h($acc['role']) ?> · ID konta SZO <?= (int)$acc['id'] ?></span></div>
  <div class="tile" style="background:#1ba1e2"><span class="n" style="font-size:1rem;font-weight:600"><?= h($acc['login'] ?: '—') ?></span><span class="l">Login / UPN</span></div>
  <div class="tile" style="background:#0050ef"><span class="n" style="font-size:1rem;font-weight:600"><?= h($acc['course_name'] ?: '—') ?></span><span class="l">Kurs / projekt</span></div>
  <div class="tile" style="background:#647687"><span class="n" style="font-size:1.2rem"><?= h($acc['sub_id']) ?></span><span class="l">SubID (kurs / kursant)</span></div>
</div>
<?php if ($acc['kind'] === 'client'): ?><p><a class="btn btn-metro" style="background:#0050ef" href="<?= h(route('ld.amz', ['id' => $acc['id']])) ?>"><span class="sym" style="background:#1d1d1d">AMZ</span> Zestawienie licencji i usług na koncie kursanta</a></p><?php endif; ?>
<?php if ($acc['kind'] === 'client' && $acc['student_account_id']): ?>
  <p><a href="konta.php?selected=<?= (int)$acc['student_account_id'] ?>&amp;ptab=licencje#detail-panel"><i class="bi bi-person-badge" aria-hidden="true"></i> Kartoteka uczestnika (Ewidencja Uczestników)</a></p>
<?php endif; ?>

<section class="panel accent">
  <h2 class="mt-0">Posiadane licencje i zasoby</h2>
  <?php if (!$res): ?><p class="text-secondary mb-0">Brak przypisanych licencji.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Dostawca / produkt</th><th>Typ</th><th>Okres</th><th>Płatnik / finansowanie</th><th>Stan</th><th>Operacje</th></tr></thead>
    <tbody>
    <?php foreach ($res as $r): $active = $r['status'] === 'aktywna' || $r['status'] === 'wygasła'; ?>
      <tr>
        <td><b><?= h($r['sku']) ?></b><div class="small text-secondary"><?= h($r['vendor']) ?> · <?= h($r['source']) ?><?= $r['login'] !== '' ? ' · ' . h($r['login']) : '' ?></div></td>
        <td class="small"><?= h($r['type']) ?></td>
        <td class="small text-nowrap"><?= h(trim(ldDate($r['assigned_at']) . ' — ' . ldDate($r['expires_at']), ' —')) ?></td>
        <td class="small"><?= h($r['funding']['payer']) ?> <span class="text-secondary">(<?= h($r['funding']['source']) ?>)</span>
          <?php if ($r['funding']['mode'] !== ''): ?><div><?= $r['funding']['mode'] === 'zajecia' ? 'doliczane do zajęć' : 'wpłata na rachunek' ?>: <?= h(LicenseOperationService::money((float)$r['funding']['amount'])) ?> od <?= h(ldDate($r['funding']['from'])) ?></div><?php endif; ?>
          <?= $r['funding']['budget'] !== '' ? '<div class="text-secondary">' . h($r['funding']['budget']) . '</div>' : '' ?></td>
        <td><?= ldChip($r['status'], $r['status'] === 'aktywna' ? '#008a00' : ($r['status'] === 'wygasła' ? '#f0a30a' : '#647687')) ?></td>
        <td class="text-nowrap">
          <?php if ($active): ?>
            <a class="btn btn-sm btn-metro green" href="<?= h($new('AM20', $r['key'])) ?>" title="AM20 — zmiana płatnika">Płatnik</a>
            <div class="btn-group">
              <button type="button" class="btn btn-sm btn-metro dark dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Więcej</button>
              <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach ([OperationForm::Am21, OperationForm::Am22, OperationForm::Am23, OperationForm::Am24] as $f): ?>
                  <li><a class="dropdown-item" href="<?= h($new($f->value, $r['key'])) ?>"><span class="sym" style="background:<?= h($f->color()) ?>"><?= h($f->value) ?></span> <?= h($f->short()) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2 class="mt-0">Nowy wniosek</h2>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ([OperationForm::Am11, OperationForm::Am12, OperationForm::Am13] as $f): ?>
      <a class="btn btn-metro" style="background:<?= h($f->color()) ?>" href="<?= h($new($f->value)) ?>"><?= h($f->value) ?> — <?= h($f->short()) ?></a>
    <?php endforeach; ?>
    <?php if ($acc['kind'] === 'client'): ?><a class="btn btn-metro dark" href="<?= h(route('ld.new7', ['kurs' => $acc['course_id']])) ?>">AM7 — licencja / dostęp do oprogramowania</a><?php endif; ?>
  </div>
</section>

<section class="panel">
  <h2 class="mt-0">Historia operacji</h2>
  <?php if (!$list): ?><p class="text-secondary mb-0">Brak operacji.</p><?php else: ?>
  <ul class="list-unstyled mb-0">
    <?php foreach ($list as $o): ?><li class="mb-1"><span class="sym" style="background:<?= h($o['formEnum']->color()) ?>"><?= h($o['formEnum']->value) ?></span>
      <a href="<?= h(route('ld.op', ['id' => $o['id']])) ?>"><?= h($o['number']) ?></a> — <?= h($o['formEnum']->short()) ?><?= ($o['state']['sku'] ?? '') !== '' ? ' · ' . h($o['state']['sku']) : '' ?>
      <?= ldChip($o['statusEnum']->label(), $o['statusEnum']->color()) ?> <span class="small text-secondary"><?= h(ldDt($o['created_at'])) ?></span></li><?php endforeach; ?>
  </ul><?php endif; ?>
</section>
<?php include __DIR__ . '/_foot.php'; ?>
