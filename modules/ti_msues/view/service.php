<?php
/**
 * modules/ti_msues/view/service.php — gotowość usługi (grupy TI) do audytu MSUES: checklista kadry, blokady, paczka ZIP, EZD.
 * Zmienne: $r (MsuesComplianceService::serviceReadiness), $ezdActive, $audits (koszulki paczek), $req (MsuesRequirements::evaluate albo null), $actor. Kontroler: MsuesController::service.
 */
require __DIR__ . '/_parts.php';
require __DIR__ . '/_reqList.php';
$title = 'MSUES — ' . $r['service']['name'];
$kierLabel = 'Certyfikacja MSUES';
include __DIR__ . '/_head.php';
$sid = (int)$r['service']['id'];
?>
<p class="mb-2"><a href="<?= h(route('msues.index')) ?>">&larr; Certyfikacja MSUES</a></p>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Gotowość do audytu: <?= h($r['service']['name']) ?></h1>
    <div class="small text-muted"><?= (int)$r['done'] ?> z <?= (int)$r['total'] ?> wymaganych kryteriów · kadra: <?= count($r['staff']) ?> · wymóg oświadczenia: <b><?= $r['enforced'] ? 'włączony' : 'wyłączony' ?></b></div>
  </div>
  <div style="min-width:260px"><?= msuesBar((int)$r['score'], (bool)$r['ready'], (bool)$r['blocked']) ?></div>
</div>

<section class="card mb-3"><div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
  <span>Wymagania standardu MSUES (I–IV)</span>
  <span class="d-flex gap-2 align-items-center"><?php if ($req): ?><span style="width:160px"><?= msuesReqSummary($req) ?></span><?php endif; ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.profile', ['id' => $sid])) ?>">Profil usługi</a></span></div>
  <?php if ($req): ?><?= msuesReqList($req) ?>
  <?php else: ?><div class="card-body small">Grupa nie jest oznaczona jako usługa MSUES — wymagania usługi (analiza potrzeb, efekty, harmonogram, sale…) nie są sprawdzane.
    <form method="post" action="<?= h(route('msues.profile.save', ['id' => $sid])) ?>" class="d-inline"><?= Router::csrfField() ?><input type="hidden" name="_quick" value="1"><input type="hidden" name="_back" value="<?= h(route('msues.service', ['id' => $sid])) ?>">
      <button class="btn btn-sm btn-primary ms-2">Oznacz jako usługę MSUES</button></form></div><?php endif; ?>
</section>

<?php if ($r['blockers']): ?>
<div class="alert <?= $r['blocked'] ? 'alert-danger' : 'alert-warning' ?>" role="status">
  <b><?= $r['blocked'] ? 'Blokada paczki audytowej:' : 'Uwagi (wymóg wyłączony — nie blokują):' ?></b>
  <ul class="mb-0"><?php foreach ($r['blockers'] as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="d-flex gap-2 flex-wrap mb-3">
  <a class="btn btn-primary<?= $r['blocked'] ? ' disabled' : '' ?>" href="<?= h(route('msues.package', ['id' => $sid])) ?>"<?= $r['blocked'] ? ' aria-disabled="true" tabindex="-1"' : '' ?>><i class="bi bi-file-zip"></i> Pobierz paczkę audytową ZIP</a>
  <?php if ($ezdActive): ?>
  <form method="post" action="<?= h(route('msues.package.ezd', ['id' => $sid])) ?>"><?= Router::csrfField() ?>
    <button class="btn btn-outline-primary"<?= $r['blocked'] ? ' disabled' : '' ?>><i class="bi bi-archive"></i> Odłóż paczkę do koszulki EZD</button></form>
  <?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?= h(route('msues.service.json', ['id' => $sid])) ?>"><i class="bi bi-braces"></i> Status w JSON</a>
</div>
<?php if ($audits): ?><p class="small">Koszulki audytu w EZD: <?php foreach ($audits as $a): ?><a href="<?= h(MsuesEzdService::url((int)$a['id'])) ?>" target="_blank" rel="noopener"><?= h($a['znak_sprawy'] ?? '#' . $a['id']) ?></a> <?php endforeach; ?></p><?php endif; ?>

<?php foreach ($r['staff'] as $p): ?>
<section class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><b><?= h($p['name']) ?></b> <span class="text-muted small">— <?= h($p['role']) ?></span></span>
    <span class="d-flex gap-2 align-items-center"><span class="small"><?= (int)$p['done'] ?>/<?= (int)$p['required'] ?></span>
      <?= $p['readiness']['allowed'] ? '<span class="badge text-bg-success">dopuszczony</span>' : '<span class="badge text-bg-danger">niedopuszczony</span>' ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.trainer', ['id' => $p['id']])) ?>">Dokumenty i oświadczenia</a></span>
  </div>
  <table class="table table-sm mb-0">
    <tbody><?php foreach ($p['items'] as $it): ?>
      <tr><td style="width:2rem"><?= msuesCheckIcon($it['ok'], $it['required']) ?></td>
        <td style="width:35%"><?= h($it['label']) ?><?= $it['required'] ? '' : ' <span class="text-muted small">(opcjonalne)</span>' ?></td>
        <td class="small"><?= h($it['detail']) ?>
          <?php if ($it['code'] === 'oswiadczenie' && $it['evidence']): $d = $it['evidence'][0]; ?> · <a href="<?= h(route('msues.decl', ['id' => $d['id']])) ?>">otwórz</a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</section>
<?php endforeach; ?>
<?php include __DIR__ . '/_foot.php'; ?>
