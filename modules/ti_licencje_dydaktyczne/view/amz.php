<?php
/**
 * modules/ti_licencje_dydaktyczne/view/amz.php — AMZ: zestawienie licencji i usług na koncie kursanta (panel kadry):
 * dane identyfikacyjne, wykaz pozycji (typ, status, okres, zużycie i limity, akcje), certyfikaty, wydruk PDF i wysyłka kursantowi.
 * Zmienne: $st (AmzStatement::build), $sent (log wysyłek), $actor.
 */
require __DIR__ . '/_parts.php';
$p = $st['person'];
$title = 'AMZ — ' . $p['name'];
include __DIR__ . '/_head.php';
$cid = (int)$p['id'];
$opNew = fn(string $form, string $key) => route('ld.op.new', ['konto' => 'client:' . $cid, 'form' => $form, 'zasob' => $key]);
?>
<div class="pivot"><a href="<?= h(route('ld.accounts')) ?>">Operacje na licencjach</a><a href="<?= h(route('ld.account', ['kind' => 'client', 'id' => $cid])) ?>"><?= h($p['name']) ?></a><a href="#" aria-current="page">AMZ</a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:#0050ef">AMZ</span> Zestawienie licencji i usług na koncie kursanta</h1>
  <a class="btn btn-metro dark" href="<?= h(route('ld.amz.pdf', ['id' => $cid])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PDF</a>
  <?= ldPost(route('ld.amz.send', ['id' => $cid]), 'Wyślij kursantowi (e-mail z PDF)', 'btn-metro', [], 'Wysłać zestawienie AMZ kursantowi (małoletniemu: opiekunowi)?') ?>
</div>
<div class="tiles">
  <div class="tile wide" style="background:#1d1d1d"><span class="n" style="font-size:1.2rem;font-weight:600"><?= h($p['name']) ?></span><span class="l">#<?= $cid ?> · <?= h($p['login'] ?: $p['email']) ?></span></div>
  <?php foreach ($st['counts'] as $s => $n): ?><div class="tile" style="background:<?= h(AmzStatement::statusColor($s)) ?>"><span class="n"><?= (int)$n ?></span><span class="l"><?= h($s) ?></span></div><?php endforeach; ?>
</div>
<section class="panel accent">
  <h2 class="mt-0">1. Dane identyfikacyjne</h2>
  <dl class="row small mb-0"><dt class="col-sm-3">Imię i nazwisko</dt><dd class="col-sm-9"><?= h($p['name']) ?></dd>
    <dt class="col-sm-3">ID / login / e-mail</dt><dd class="col-sm-9">#<?= $cid ?> · <?= h($p['login'] ?: '—') ?> · <?= h($p['email'] ?: '—') ?></dd>
    <dt class="col-sm-3">Organizacja / firma</dt><dd class="col-sm-9"><?= h($p['organization'] ?: '— (nie dotyczy)') ?></dd>
    <dt class="col-sm-3">Numer zestawienia</dt><dd class="col-sm-9"><?= h($st['number']) ?>, stan na <?= h(ldDt($st['generated_at'])) ?></dd></dl>
</section>
<section class="panel">
  <h2 class="mt-0">2–4. Licencje, dostępy i usługi</h2>
  <?php if (!$st['items']): ?><p class="text-secondary mb-0">Na koncie nie ma przypisanych licencji ani usług.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Nazwa licencji / dostępu</th><th>Typ / wariant</th><th>Status</th><th>Okres</th><th>Zużycie i limity</th><th>Akcje</th></tr></thead><tbody>
    <?php $g = ''; foreach ($st['items'] as $it): if ($it['group'] !== $g): $g = $it['group']; ?><tr class="table-light"><th colspan="6" class="small text-uppercase"><?= h($g) ?></th></tr><?php endif; ?>
      <tr>
        <td><b><?= h($it['name']) ?></b><div class="small text-secondary"><?= h($it['vendor']) ?></div></td>
        <td class="small"><?= h($it['variant']) ?></td>
        <td><?= ldChip($it['status'], AmzStatement::statusColor($it['status'])) ?></td>
        <td class="small text-nowrap"><?= h(ldDate($it['from']) ?: '—') ?> — <?= h($it['to'] !== '' ? ldDate($it['to']) . ', 23:59' : 'bezterminowo') ?></td>
        <td class="small"><?= h($it['usage'] ?: '—') ?>
          <?php if ($it['progress'] !== null): ?><div class="progress mt-1" style="height:.6rem" role="progressbar" aria-label="Postęp" aria-valuenow="<?= (int)$it['progress'] ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= max(2, (int)$it['progress']) ?>%"></div></div><?php endif; ?>
          <?php if ($it['license_key'] !== ''): ?><div>klucz: <span class="font-monospace"><?= h($it['license_key']) ?></span></div><?php endif; ?></td>
        <td class="small text-nowrap">
          <?php foreach ($it['actions'] as $al => $au): if ($au === '') continue; ?><a href="<?= h($au) ?>" target="_blank" rel="noopener"><?= h($al) ?></a><br><?php endforeach; ?>
          <?php if (preg_match('/^(ti|am|m365):/', $it['key']) && $it['status'] !== 'Cofnięta'): ?><a href="<?= h($opNew('AM24', $it['key'])) ?>">Przedłuż (AM24)</a><br><a href="<?= h($opNew('AM20', $it['key'])) ?>">Zmień płatnika (AM20)</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>
<section class="panel">
  <h2 class="mt-0">Certyfikaty i zaświadczenia</h2>
  <?php if (!$st['certs']): ?><p class="small text-secondary mb-0">Brak w teczce kursanta (kategorie: certyfikat / zaświadczenie).</p><?php else: ?>
  <ul class="small mb-0"><?php foreach ($st['certs'] as $c): ?><li><?= h($c['title']) ?> — <?= h(ldDate(substr((string)$c['created_at'], 0, 10))) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (!empty($p['student_account_id'])): ?><p class="small mt-2 mb-0"><a href="konta.php?selected=<?= (int)$p['student_account_id'] ?>&amp;ptab=dokumenty#detail-panel">Teczka kursanta (Dokumenty)</a></p><?php endif; ?>
</section>
<?php if ($sent): ?><section class="panel"><h2 class="mt-0">Wysłane zestawienia</h2><ul class="small mb-0">
  <?php foreach ($sent as $n): ?><li><?= h(ldDt($n['created_at'])) ?> — <?= $n['channel'] === 'sms' ? 'SMS' : 'e-mail' ?> <?= h($n['recipient']) ?>: <?= h(LicenseNotificationService::statusLabel((string)$n['status'])) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
