<?php
/**
 * modules/ti_licencje_dydaktyczne/view/korekta.php — AMSK: karta korekty (zakres, druki AMSK/1 i AMSK, potwierdzenie odręcznego podpisu, wysyłka).
 * Zmienne: $c, $t, $canApprove.
 */
require __DIR__ . '/_parts.php';
require_once dirname(__DIR__) . '/lib/LicenseCorrection.php';
require_once dirname(__DIR__) . '/lib/LicenseDocPackage.php';
$id = (int)$c['id'];
$code = LicenseCorrection::code($id);
$title = $code;
$kierCur = 'licencjeDydaktyczne.php?_route=%2Fkorekty';
include __DIR__ . '/_head.php';
$st = (string)$c['status'];
$isOp = $c['scope'] === 'operation';
$targetUrl = $isOp ? route('ld.op', ['id' => $c['target_id']]) : route('ld.show', ['id' => $c['target_id']]);
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Licencje AM5 / AM7</a><a href="<?= h(route('ld.corr.index')) ?>">Korekty AMSK</a><a class="active" href="<?= h(route('ld.corr', ['id' => $id])) ?>" aria-current="page"><?= h($code) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:#7c2d12">AMSK</span> Korekta <?= h($code) ?> <?= ldChip(LicenseCorrection::statusLabel($st), LicenseCorrection::statusColor($st)) ?></h1>
  <a class="btn btn-metro" style="background:#7c2d12" href="<?= h(route('ld.corr.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Druk kontrolny AMSK/1 (do podpisu)</a>
  <?php if ($st === LicenseCorrection::APPLIED): ?><a class="btn btn-metro dark" href="<?= h(route('ld.corr.pdf', ['id' => $id, 'druk' => 'korekta'])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> AMSK (korekta)</a><?php endif; ?>
</div>
<p class="text-secondary">Dotyczy: <a href="<?= h($targetUrl) ?>"><?= h($c['target_number']) ?></a> <?= $isOp ? '(operacja)' : '(sprawa)' ?> · wystawił(a) <?= h($c['created_name']) ?>, <?= h(ldDt($c['created_at'])) ?></p>

<section class="panel"><h2 class="mt-0">Zakres korekty</h2>
  <div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>Dokument</th><th>Pole</th><th>Było</th><th>Jest po korekcie</th></tr></thead><tbody>
  <?php foreach ($c['itemsList'] as $it): ?><tr><td class="small"><?= h($it['doc']) ?></td><td><?= h($it['label']) ?></td><td><?= nl2br(h($it['old_disp'])) ?></td><td><b><?= nl2br(h($it['new_disp'])) ?></b></td></tr><?php endforeach; ?></tbody></table></div>
  <p class="mb-0"><b>Uzasadnienie:</b> <?= nl2br(h($c['reason'])) ?></p>
</section>

<div class="row g-3">
<section class="col-lg-7"><div class="panel h-100"><h2 class="mt-0">Obieg</h2>
  <ol class="mb-0">
    <li><b>Wystawiono</b> — <?= h(ldDt($c['created_at'])) ?> (<?= h($c['created_name']) ?>).</li>
    <li>Wydrukuj <b>AMSK/1</b> (zawiera QR i Code128), kierownik <b>podpisuje odręcznie</b>, podpisany druk trafia do teczki sprawy.</li>
    <li><?= $st === LicenseCorrection::APPLIED ? '<b>Podpis potwierdzono</b> — ' . h(ldDt($c['applied_at'])) . ' (' . h($c['applied_name']) . '), zmiany wprowadzone.' : ($st === LicenseCorrection::CANCELLED ? '<b>Anulowano</b>' . ($c['cancel_reason'] ? ': ' . h($c['cancel_reason']) : '') . '.' : 'Potwierdź podpis w SZO (panel obok) — dopiero wtedy zmiany wejdą do danych sprawy.') ?></li>
    <li>Druk <b>AMSK</b> (korekta) idzie do osoby e-mailem<?= $c['sent_at'] ? ' — wysłano ' . h(ldDt($c['sent_at'])) : '' ?>.</li>
  </ol></div></section>
<section class="col-lg-5"><div class="panel h-100"><h2 class="mt-0">Akcje</h2>
  <?php if ($st === LicenseCorrection::PENDING): ?>
    <?php if ($canApprove): ?>
    <form method="post" action="<?= h(route('ld.corr.approve', ['id' => $id])) ?>" class="mb-3" onsubmit="return confirm('Wprowadzić korektę w danych sprawy? Zmiany zapiszą się od razu.')"><?= Router::csrfField() ?>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="signed" name="signed" value="1" required>
        <label class="form-check-label" for="signed">Druk kontrolny <?= h($code) ?> (AMSK/1) jest podpisany odręcznie przez kierownika</label></div>
      <button class="btn btn-sm btn-metro green">Potwierdź podpis i wprowadź korektę</button></form>
    <?php else: ?><p class="small text-secondary">Podpis potwierdza kierownik (rola „kierownik” w panelu TI) albo administrator.</p><?php endif; ?>
    <form method="post" action="<?= h(route('ld.corr.cancel', ['id' => $id])) ?>" class="d-flex gap-1" onsubmit="return confirm('Anulować korektę?')"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="cr">Powód anulowania</label><input class="form-control form-control-sm" id="cr" name="reason" placeholder="powód anulowania">
      <button class="btn btn-sm btn-metro red">Anuluj</button></form>
  <?php elseif ($st === LicenseCorrection::APPLIED): ?>
    <?= ldPost(route('ld.corr.send', ['id' => $id]), $c['sent_at'] ? 'Wyślij AMSK ponownie' : 'Wyślij AMSK do osoby', 'btn-metro green', [], 'Wysłać AMSK e-mailem w PDF do osoby, której dotyczy sprawa?') ?>
  <?php else: ?><p class="text-secondary mb-0">Korekta anulowana — brak akcji.</p><?php endif; ?>
</div></section>
</div>
<?php if ($st === LicenseCorrection::APPLIED): $pk = LicenseDocPackage::forCorrection($id); ?>
<section class="panel <?= $pk ? '' : 'warn' ?>" id="pakiet">
  <h2 class="mt-0">Pakiet poprawionych druków AM</h2>
  <?php if (!$pk): ?>
    <div class="alert alert-warning" role="alert"><b>Korekta jest zatwierdzona, ale nie ma pakietu poprawionych druków AM — wymagane ręczne generowanie.</b>
      Automatyczne wygenerowanie i wysyłka nie powiodły się albo korekta powstała wcześniej. Wygenerowanie pakietu odtworzy druki sprawy z poprawionych danych (kod z nową rewizją R<?= (int)LicenseCodes::revision($c['scope'], (int)$c['target_id']) ?>)
      i <b>unieważni dotychczasowe druki</b>; potem wyślesz ZIP z hasłem do osoby i kierownika.</div>
    <?= ldPost(route('ld.corr.pack', ['id' => $id]), 'Wygeneruj pakiet druków AM i unieważnij stare', 'btn-metro green', [], 'Wygenerować pakiet poprawionych druków AM? Dotychczasowe druki zostaną unieważnione, powstanie druk AMSG, a ten sam pakiet (ZIP z hasłem) trafi do kierownika.') ?>
  <?php else: $pi = LicenseDocPackage::packageIssues((int)$pk['id']); ?>
    <p class="small text-secondary">Rewizja <b>R<?= (int)$pk['revision'] ?></b> · generowanie nr <?= (int)$pk['gen_no'] ?> (<?= h($pk['gen_reason']) ?>) · <?= h(ldDt($pk['created_at'])) ?> (<?= h($pk['created_name']) ?>) · do osoby: <?= $pk['sent_at'] ? 'wysłano ' . h(ldDt($pk['sent_at'])) : '<b class="text-danger">nie wysłano</b>' ?> · do kierownika: <?= $pk['manager_sent_at'] ? 'wysłano ' . h(ldDt($pk['manager_sent_at'])) : '<b class="text-danger">nie wysłano</b>' ?></p>
    <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Dokument</th><th>Numer</th><th>Kod Code128</th><th>Stan</th></tr></thead><tbody>
      <?php foreach ($pi as $i): ?><tr><td><?= h($i['label']) ?></td><td class="small"><?= h($i['doc_number']) ?></td><td><code><?= h($i['code']) ?></code></td><td><?= ldChip('Obowiązuje', '#008a00') ?></td></tr><?php endforeach; ?>
      <?php foreach (db_all("SELECT * FROM license_doc_issues WHERE scope = ? AND target_id = ? AND status = 'void' AND void_correction_id = ? ORDER BY id", [$c['scope'], $c['target_id'], $id]) as $i): ?>
        <tr class="text-secondary"><td style="text-decoration:line-through"><?= h($i['label']) ?></td><td class="small"><?= h($i['doc_number']) ?></td><td><code><?= h($i['code']) ?></code></td><td><?= ldChip('UNIEWAŻNIONY', '#a20025') ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if (!str_starts_with((string)$pk['gen_reason'], 'Automatycznie')): ?><a class="btn btn-sm btn-metro" style="background:#334155" href="<?= h(route('ld.pack.amsg', ['pid' => $pk['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> AMSG/<?= (int)$pk['gen_no'] ?> — Regeneracja pakietu</a><?php endif; ?>
    <a class="btn btn-sm btn-metro" style="background:#1e3a8a" href="<?= h(route('ld.pack.pp', ['pid' => $pk['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> PP — protokół przekazania hasła</a>
    <a class="btn btn-sm btn-metro" style="background:#0f4c5c" href="<?= h(route('ld.am56' . ($c['scope'] === 'operation' ? '.op' : ''), ['id' => $c['target_id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-list-check" aria-hidden="true"></i> AM56 — spis dokumentów</a>
    <a class="btn btn-sm btn-metro dark" href="<?= h(route('ld.pack.zip', ['pid' => $pk['id']])) ?>"><i class="bi bi-file-earmark-zip" aria-hidden="true"></i> Pobierz ZIP (jawny)</a>
    <?php if (!empty($pk['locked_at'])): ?>
      <?= ldChip('Pakiet zablokowany ' . ldDt($pk['locked_at']) . ' (' . $pk['locked_name'] . ')', '#1e3a8a') ?>
      <a class="btn btn-sm btn-metro" style="background:#1e3a8a" href="<?= h(route('ld.pack.merged', ['pid' => $pk['id']])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Scalony PDF (AM56 + wszystkie druki, bez AMSK)</a>
    <?php else: ?>
      <?= ldPost(route('ld.pack.lock', ['pid' => $pk['id']]), 'Zablokuj pakiet i drukuj 1 PDF', 'btn-metro red', [], 'Zablokować pakiet? Zawartość zostanie zamrożona (bez regeneracji), a SZO wygeneruje JEDEN PDF: spis AM56 z QR do spisu elektronicznego + wszystkie druki pakietu bez AMSK.') ?>
    <?php endif; ?>
    <?php if (empty($pk['locked_at']) && (int)$pk['revision'] === LicenseCodes::revision($c['scope'], (int)$c['target_id'])): ?>
      <?= ldPost(route('ld.pack.regen', ['pid' => $pk['id']]), 'Regeneruj pakiet', 'btn-metro orange', [], 'Wygenerować druki pakietu ponownie z aktualnych danych (ta sama rewizja R' . (int)$pk['revision'] . ', kody bez zmian)? Powstanie druk AMSG, ten sam pakiet trafi do kierownika, a do osoby zostanie oznaczony jako niewysłany.') ?>
    <?php endif; ?>
    <?= ldPost(route('ld.pack.send', ['pid' => $pk['id']]), $pk['sent_at'] ? 'Wyślij pakiet ponownie (ZIP z hasłem + SMS)' : 'Wyślij pakiet do osoby i kierownika (ZIP z hasłem + SMS)', 'btn-metro green', [], 'Wysłać pakiet jako ZIP z hasłem do osoby i kierownika? Hasło pójdzie osobnym SMS-em.') ?>
    <?php if ($canApprove): $_myPhone = (string)(db_one("SELECT phone_number FROM users WHERE id = ?", [(int)$actor['id']])['phone_number'] ?? ''); ?>
    <form method="post" action="<?= h(route('ld.pack.pwd', ['pid' => $pk['id']])) ?>" class="row g-2 align-items-end mt-3 pt-3 border-top" onsubmit="return confirm('Wygenerować NOWE hasło do pakietu? Nowe archiwum trafi na Twój e-mail, a hasło SMS-em na wskazany numer.')"><?= Router::csrfField() ?>
      <div class="col-md-5"><label class="form-label small mb-0" for="pwdphone">Numer telefonu, na który wysłać nowe hasło (tylko kierownik)</label>
        <input class="form-control form-control-sm" id="pwdphone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required value="<?= h($_myPhone) ?>" placeholder="np. 600 100 200"></div>
      <div class="col-auto"><button class="btn btn-sm btn-metro dark"><i class="bi bi-key" aria-hidden="true"></i> Regeneruj hasło do pakietu</button></div>
      <div class="col-12 small text-secondary">Powstaje nowe archiwum ZIP z nowym hasłem: ZIP dostaniesz e-mailem, hasło SMS-em na wskazany numer. Hasła nigdzie nie zapisujemy; w dzienniku zostaje tylko kto i końcówka numeru.</div>
    </form>
    <?php else: ?><p class="small text-secondary mt-3 mb-0"><i class="bi bi-key" aria-hidden="true"></i> Hasło do archiwum regeneruje wyłącznie kierownik.</p><?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
