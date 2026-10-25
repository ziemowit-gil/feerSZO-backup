<?php
/**
 * modules/ti_licencje_dydaktyczne/view/korekty.php — AMSK: lista korekt, wyszukiwarka i „Skanuj kod” (Code128 z formularza AM).
 * Zmienne: $q, $rows.
 */
require __DIR__ . '/_parts.php';
require_once dirname(__DIR__) . '/lib/LicenseCorrection.php';
require_once dirname(__DIR__) . '/lib/LicenseRotation.php';
$title = 'Korekty AMSK';
$kierCur = 'licencjeDydaktyczne.php?_route=%2Fkorekty';
include __DIR__ . '/_head.php';
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Licencje AM5 / AM7</a><a class="active" href="<?= h(route('ld.corr.index')) ?>" aria-current="page">Korekty AMSK</a></div>
<h1><span class="sym" style="background:#7c2d12">AMSK</span> Korekty dokumentów AM</h1>
<p class="text-secondary">Korekta danych w dokumentach AM czeka na <b>odręczny podpis kierownika</b> na druku kontrolnym <b>AMSK/1</b> (dane osobowe). Po podpisaniu potwierdź go w SZO — dopiero wtedy zmiany wchodzą do danych sprawy.</p>
<div class="row g-3 mb-3">
  <form class="col-md-6" method="get" action="<?= h(route('ld.scan')) ?>" role="search" aria-label="Skanuj kod formularza">
    <label class="form-label small" for="kod">Skanuj kod z formularza AM (Code128 w stopce, np. LD000002/AM7-2, OP000005/AM20, AMSK-000012)</label>
    <div class="input-group"><input class="form-control" id="kod" name="kod" autocomplete="off" autocapitalize="characters" placeholder="zeskanuj lub wpisz kod"><button class="btn btn-metro dark">Otwórz</button></div></form>
  <form class="col-md-6" method="get" action="<?= h(route('ld.corr.index')) ?>" role="search" aria-label="Szukaj korekt">
    <label class="form-label small" for="q">Szukaj korekty (kod, numer sprawy, uzasadnienie, wystawiający)</label>
    <div class="input-group"><input class="form-control" id="q" name="q" value="<?= h($q) ?>"><button class="btn btn-metro dark">Szukaj</button><?php if ($q !== ''): ?><a class="btn btn-outline-secondary" href="<?= h(route('ld.corr.index')) ?>">Wyczyść</a><?php endif; ?></div></form>
</div>
<div class="table-responsive panel p-0"><table class="table table-hover mb-0">
  <thead><tr><th>Kod</th><th>Sprawa / dokument</th><th>Zakres</th><th>Stan</th><th>Wystawiono</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="5" class="text-secondary p-3">Brak korekt<?= $q !== '' ? ' dla tego wyszukiwania' : '' ?>. Korektę wystawisz z karty sprawy (zakładka Korekty AMSK) albo z karty operacji.</td></tr><?php endif; ?>
  <?php foreach ($rows as $c): ?>
    <tr><td class="text-nowrap"><a href="<?= h(route('ld.corr', ['id' => $c['id']])) ?>"><?= h(LicenseCorrection::code((int)$c['id'])) ?></a></td>
      <td><?= h($c['target_number']) ?> <span class="small text-secondary"><?= $c['scope'] === 'operation' ? '(operacja)' : '(sprawa)' ?></span></td>
      <td class="small"><?= h(implode(', ', array_map(fn($i) => $i['label'], $c['itemsList']))) ?></td>
      <td><?= ldChip(LicenseCorrection::statusLabel($c['status']), LicenseCorrection::statusColor($c['status'])) ?><?php if ($c['status'] === LicenseCorrection::APPLIED && !LicenseCorrection::hasPackage((int)$c['id'])): ?> <?= ldChip('brak pakietu — ręczne generowanie', '#fa6800') ?><?php endif; ?></td>
      <td class="small text-nowrap"><?= h(ldDt($c['created_at'])) ?> · <?= h($c['created_name']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php $_rots = db_all("SELECT * FROM license_rotations ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, id DESC LIMIT 30"); ?>
<h2 class="h5 mt-4"><span class="sym" style="background:#14532d">ASK-UP1</span> Uzasadnienia rotacji licencji</h2>
<div class="table-responsive panel p-0"><table class="table table-hover mb-0">
  <thead><tr><th>Kod</th><th>Sprawa</th><th>Było → jest</th><th>Stan</th><th>Wystawiono</th></tr></thead><tbody>
  <?php if (!$_rots): ?><tr><td colspan="5" class="text-secondary p-3">Brak uzasadnień rotacji. Wystawisz je z karty sprawy lub operacji (sekcja ASK-UP1).</td></tr><?php endif; ?>
  <?php foreach ($_rots as $r): ?>
    <tr><td class="text-nowrap"><a href="<?= h(route('ld.rot', ['id' => $r['id']])) ?>"><?= h(LicenseRotation::code((int)$r['id'])) ?></a></td><td><?= h($r['target_number']) ?></td>
      <td class="small"><?= h($r['from_holder'] ?: '—') ?> → <b><?= h($r['to_holder']) ?></b></td><td><?= ldChip(LicenseRotation::statusLabel($r['status']), LicenseRotation::statusColor($r['status'])) ?></td>
      <td class="small text-nowrap"><?= h(ldDt($r['created_at'])) ?> · <?= h($r['created_name']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
