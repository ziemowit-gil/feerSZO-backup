<?php
/**
 * modules/ti_licencje_dydaktyczne/view/_rejestrDrukow.php — rejestr druków AM sprawy / operacji z kodami i rewizjami (po pakiecie AMSK):
 * które druki obowiązują, a które są UNIEWAŻNIONE (zastąpione po korekcie). Zmienne: $regScope, $regId.
 */
require_once dirname(__DIR__) . '/lib/LicenseDocPackage.php';
$_ri = LicenseDocPackage::issues($regScope, (int)$regId);
$_rcur = LicenseCodes::revision($regScope, (int)$regId);
if (!$_ri && $_rcur <= 1) return;
?>
<h3 class="h6 mt-3">Rejestr druków i kodów — obowiązuje rewizja R<?= (int)$_rcur ?></h3>
<?php if (!$_ri): ?><p class="small text-secondary mb-0">Po zatwierdzonej korekcie nie wygenerowano jeszcze pakietu druków — druki wydane wcześniej są nieaktualne (rewizja R<?= (int)$_rcur ?>). Wygeneruj pakiet na karcie korekty.</p><?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Dokument</th><th>Numer</th><th>Kod</th><th>Rewizja</th><th>Stan</th></tr></thead><tbody>
  <?php foreach ($_ri as $i): $v = $i['status'] === 'void'; ?>
    <tr<?= $v ? ' class="text-secondary"' : '' ?>><td<?= $v ? ' style="text-decoration:line-through"' : '' ?>><?= h($i['label']) ?></td><td class="small text-nowrap"><?= h($i['doc_number']) ?></td><td><code><?= h($i['code']) ?></code></td><td>R<?= (int)$i['revision'] ?></td>
      <td><?= $v ? ldChip('UNIEWAŻNIONY ' . ldDate(substr((string)$i['void_at'], 0, 10)), '#a20025') : ldChip('Obowiązuje', '#008a00') ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
