<?php
/**
 * modules/ti_licencje_dydaktyczne/view/show.php — karta sprawy licencji AM5: kafle stanu, wniosek AM5/1 i decyzja,
 * akcje obiegu (wszczęcie, ponowna wysyłka, decyzja z papieru, wycofanie, zawieszenie), przydziały z oświadczeniami
 * AM5/2 i AM5/3, zmianami danych (audit trail) i weryfikacją, dziennik zdarzeń i log powiadomień (notification_logs).
 * Zmienne: $l, $asg, $decl [asgId => list], $changes [asgId => list], $events, $notifs, $people, $actor.
 */
require __DIR__ . '/_parts.php';
$title = $l['case_number'] ?: ('Licencja #' . $l['id']);
$kierLabel = $title;
include __DIR__ . '/_head.php';
$id = (int)$l['id'];
$st = $l['requestStatus'];
$d = $l['decision'];
$fieldLabels = HolderDeclarationDto::FIELDS + ['address' => 'Adres'];
$am7 = $l['form'] === LicenseForm::Am7;
$chLabel = ['applied' => ['wprowadzona', '#008a00'], 'pending' => ['czeka na akceptację', '#f0a30a'], 'rejected' => ['odrzucona', '#a20025']];
?>
<div class="pivot"><a href="<?= h(route('ld.index')) ?>">Pulpit</a><a href="<?= h(route('ld.show', ['id' => $id])) ?>" aria-current="page"><?= h($title) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><?= h($l['title']) ?></h1>
  <a class="btn btn-metro dark" href="<?= h(route('ld.request.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Wniosek <?= h($l['form']->requestDoc()->symbol()) ?> (PDF)</a>
  <?php if ($am7 && $d): ?><a class="btn btn-metro" style="background:#0050ef" href="<?= h(route('ld.am7.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Decyzja <?= !empty($d['grant']) ? 'AM7/2' : 'AM7/3' ?><?= empty($d['approved_at']) ? ' — projekt' : '' ?> (PDF)</a><?php endif; ?>
</div>

<div class="tiles">
  <div class="tile wide" style="background:#1ba1e2"><span class="n" style="font-size:1.35rem;font-weight:600"><?= h($l['case_number']) ?></span><span class="l">Numer sprawy</span></div>
  <div class="tile" style="background:<?= h($st->color()) ?>"><span class="n" style="font-size:1rem;font-weight:600"><?= h($st->label()) ?></span><span class="l">Wniosek</span></div>
  <div class="tile" style="background:<?= h($l['poolStatus']?->color() ?? '#647687') ?>"><span class="n" style="font-size:1.3rem"><?= h($l['poolStatus']?->label() ?? '—') ?></span><span class="l">Pula</span></div>
  <div class="tile" style="background:<?= LicenseRepository::overdue($l) ? '#a20025' : '#f0a30a' ?>"><span class="n" style="font-size:1.3rem"><?= h(ldDate($l['due_on']) ?: '—') ?></span><span class="l">Termin <?= LD_REALIZATION_DAYS ?> dni<?= LicenseRepository::overdue($l) ? ' — minął' : '' ?></span></div>
  <div class="tile" style="background:#008a00"><span class="n"><?= (int)$l['activeAssignments'] ?><?= (int)$l['seats'] > 0 ? '<small>/' . (int)$l['seats'] . '</small>' : '' ?></span><span class="l">Przydziały</span></div>
</div>

<div class="row g-3">
<section class="col-lg-7"><div class="panel accent h-100">
  <h2 class="mt-0">Wniosek <?= h($l['form']->requestDoc()->symbol()) ?> <small class="text-secondary"><?= h($l['form']->label()) ?></small></h2>
  <?php if ($am7): $rq = $l['request']; ?>
  <dl class="row mb-0 small">
    <dt class="col-sm-4">Kursant</dt><dd class="col-sm-8"><?= h($l['interested_name']) ?><?= $l['interested_email'] !== '' ? ' · ' . h($l['interested_email']) : '' ?><?= $l['interested_phone'] !== '' ? ' · ' . h($l['interested_phone']) : '' ?></dd>
    <dt class="col-sm-4">Kurs</dt><dd class="col-sm-8"><?= h($l['course_name']) ?></dd>
    <dt class="col-sm-4">Konto / identyfikator</dt><dd class="col-sm-8"><?= h(($rq['account'] ?? '') ?: '—') ?></dd>
    <dt class="col-sm-4">Produkt i licencja</dt><dd class="col-sm-8"><?= h($rq['plan'] ?? '') ?></dd>
    <dt class="col-sm-4">Sposób dostępu</dt><dd class="col-sm-8"><?= ($rq['access'] ?? '') === 'other' ? 'inny: ' . h($rq['access_other'] ?? '') : 'konto w tenancie ośrodka' ?></dd>
    <dt class="col-sm-4">Wnioskowany okres</dt><dd class="col-sm-8"><?= h(trim(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to']), ' —')) ?></dd>
    <dt class="col-sm-4">Uzasadnienie</dt><dd class="col-sm-8" style="white-space:pre-line"><?= h(trim((string)($rq['justification'] ?? '')) !== '' ? $rq['justification'] : '—') ?></dd>
    <?php if ($l['started_at']): ?><dt class="col-sm-4">Wszczęto</dt><dd class="col-sm-8"><?= h(ldDt($l['started_at'])) ?> — <?= h($l['started_name']) ?></dd><?php endif; ?>
    <dt class="col-sm-4">Podpis kursanta</dt><dd class="col-sm-8"><?= $l['requester_signed_at'] ? ldChip('podpisany ' . ldDt($l['requester_signed_at']), '#008a00') . ' IP ' . h($l['requester_signed_ip']) : (!empty($d['paper_signed']) ? ldChip('podpis odręczny', '#647687') : ldChip('czeka na podpis', '#f0a30a')) ?></dd>
    <?php if ($d): ?><dt class="col-sm-4">Decyzja</dt><dd class="col-sm-8"><?= !empty($d['grant']) ? ldChip('przyznanie (AM7/2)', '#008a00') : ldChip('odmowa (AM7/3)', '#a20025') ?>
      projekt: <?= h($d['prepared_name'] ?? '') ?>, <?= h(ldDt($d['prepared_at'] ?? '')) ?><?= !empty($d['approved_at']) ? '; <b>zatwierdził(a): ' . h($d['approved_name'] ?? '') . ', ' . h(ldDt($d['approved_at'])) . '</b>' : ' — <b>czeka na zatwierdzenie kierownika</b>' ?></dd><?php endif; ?>
    <?php $_tu = db_one("SELECT tenant_kind, tenant_upn, tenant_created_at FROM licenses WHERE id = ?", [$id]); if (!empty($_tu['tenant_upn'])): ?><dt class="col-sm-4">Konto w tenancie</dt><dd class="col-sm-8"><?= h($_tu['tenant_upn']) ?> <?= ldChip($_tu['tenant_kind'] === 'feer' ? 'FEER' : 'EDU FEER', '#0050ef') ?> <?= h(ldDt($_tu['tenant_created_at'])) ?></dd><?php endif; ?>
    <?php if (!empty($l['helpdesk_ticket_id'])): ?><dt class="col-sm-4">Helpdesk IT</dt><dd class="col-sm-8"><a href="<?= h(ldSzoUrl() . '/helpdesk/view.php?id=' . (int)$l['helpdesk_ticket_id']) ?>"><?= h($l['helpdesk_number']) ?></a></dd><?php endif; ?>
  </dl>
  <?php else: ?>
  <dl class="row mb-0 small">
    <dt class="col-sm-4">Licencjodawca</dt><dd class="col-sm-8"><?= h($l['licensor_name']) ?><?= $l['licensor_email'] !== '' ? ' · ' . h($l['licensor_email']) : '' ?><?= $l['licensor_phone'] !== '' ? ' · ' . h($l['licensor_phone']) : '' ?></dd>
    <dt class="col-sm-4">Wnioskodawca</dt><dd class="col-sm-8"><?= h($l['applicant_name']) ?></dd>
    <dt class="col-sm-4">Kurs</dt><dd class="col-sm-8"><?= h($l['course_name'] ?: '—') ?></dd>
    <dt class="col-sm-4">Osoba zainteresowana</dt><dd class="col-sm-8"><?= h($l['interested_name'] ?: '—') ?></dd>
    <dt class="col-sm-4">Opis utworu</dt><dd class="col-sm-8" style="white-space:pre-line"><?= h($l['work_description']) ?></dd>
    <dt class="col-sm-4">Zakres</dt><dd class="col-sm-8"><ul class="mb-0 ps-3"><?php foreach ((array)($l['request']['scopes'] ?? []) as $k => $s): ?><li><?= h(LicenseRequestDto::SCOPES[$k][0] ?? $k) ?>: <b><?= h($s) ?></b></li><?php endforeach; ?></ul></dd>
    <dt class="col-sm-4">Okres / terytorium</dt><dd class="col-sm-8"><?= h(trim(ldDate($l['period_from']) . ' — ' . ldDate($l['period_to']), ' —')) ?> · <?= h($l['territory']) ?></dd>
    <dt class="col-sm-4">Wynagrodzenie</dt><dd class="col-sm-8"><?= h($l['request']['remuneration'] ?? '') ?></dd>
    <?php if ($l['started_at']): ?><dt class="col-sm-4">Wszczęto</dt><dd class="col-sm-8"><?= h(ldDt($l['started_at'])) ?> — <?= h($l['started_name']) ?></dd><?php endif; ?>
    <?php if ($d): ?>
      <dt class="col-sm-4">Decyzja</dt><dd class="col-sm-8"><?= !empty($d['grant']) ? ldChip('udzielona — ' . (($d['kind'] ?? '') === 'wylaczna' ? 'wyłączna' : 'niewyłączna'), '#008a00') : ldChip('odmowa', '#a20025') ?>
        <?= h(ldDt($l['decided_at'])) ?>, <?= h($d['signer_name'] ?? '') ?> (<?= $l['decided_via'] === 'portal' ? 'portal' : 'wpis z papieru' ?>)
        <?php if (!empty($d['grant'])): ?><div style="white-space:pre-line"><?= h($d['accepted_scope'] ?? '') ?></div><?php if (($d['terms'] ?? '') !== ''): ?><div>Warunki: <?= h($d['terms']) ?></div><?php endif; ?>
        <?php elseif (($d['refusal_reason'] ?? '') !== ''): ?><div><?= h($d['refusal_reason']) ?></div><?php endif; ?></dd>
    <?php endif; ?>
    <?php if (!empty($l['suspended'])): ?><dt class="col-sm-4">Zawieszona</dt><dd class="col-sm-8"><?= h($l['suspended_reason']) ?></dd><?php endif; ?>
  </dl>
  <?php endif; ?>
</div></section>

<section class="col-lg-5"><div class="panel h-100">
  <h2 class="mt-0">Akcje</h2>
  <style>
    .ld-acts .ld-gh { font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #5f5f5f; margin: .9rem 0 .3rem; padding-bottom: .2rem; border-bottom: 1px solid #d0d0d0; }
    .ld-acts .ld-gh:first-child { margin-top: 0; }
    .ld-acts form.d-inline, .ld-acts > a.btn, .ld-acts .ld-grp > a.btn { display: block; }
    .ld-acts .btn { width: 100%; text-align: left; }
    .ld-acts .btn-metro.dark.ld-out { background: #fff; color: #1d1d1d; box-shadow: inset 0 0 0 2px #1d1d1d; }
    .ld-acts .btn-metro.dark.ld-out:hover { background: #1d1d1d; color: #fff; }
    .ld-acts .ld-note { font-size: .78rem; color: #5f5f5f; margin: .15rem 0 0; }
    .ld-acts .ld-susp { display: grid; grid-template-columns: 1fr auto; gap: 4px; }
    .ld-acts .ld-susp .btn { width: auto; }
  </style>
  <div class="d-grid gap-2 ld-acts">
  <?php if ($am7 && !empty($d['approved_at'])): ?><div class="ld-gh">Decyzja</div><?= ldPost(route('ld.am7.resend', ['id' => $id]), 'Wyślij decyzję ponownie (PDF e-mailem)', 'btn-metro', [], 'Wysłać decyzję ponownie do kursanta?') ?><?php endif; ?>
  <?php if ($st === LicenseRequestStatus::Draft): ?>
    <a class="btn btn-metro dark" href="<?= h(route('ld.edit', ['id' => $id])) ?>">Edytuj wniosek</a>
    <form method="post" action="<?= h(route('ld.start', ['id' => $id])) ?>" class="d-grid gap-1"><?= Router::csrfField() ?>
      <button class="btn btn-metro">Wszcznij proces — termin <?= LD_REALIZATION_DAYS ?> dni</button>
      <div class="form-check small"><input class="form-check-input" type="checkbox" name="paper_only" value="1" id="po"><label class="form-check-label" for="po">bez e-maila do licencjodawcy (obieg papierowy)</label></div>
      <div class="small text-secondary">Osoba zainteresowana dostanie e-mail i SMS (fallback: drugi kanał, potem alert do biura).</div>
    </form>
    <?= ldPost(route('ld.delete', ['id' => $id]), 'Usuń wniosek roboczy', 'btn-outline-danger', [], 'Usunąć wniosek roboczy?') ?>
  <?php elseif ($st === LicenseRequestStatus::Started && $am7): ?>
    <?php if (!$l['requester_signed_at']): ?><?= ldPost(route('ld.am7.signlink', ['id' => $id]), 'Wyślij kursantowi link do podpisu (e-mail + SMS)', 'btn-metro') ?><?php endif; ?>
    <?php if ($d && empty($d['approved_at'])): ?>
      <?php if ($canApprove): ?><?= ldPost(route('ld.am7.approve', ['id' => $id]), 'Zatwierdź i wyślij decyzję (PDF e-mailem)', 'btn-metro green', [], 'Zatwierdzić decyzję? Kursant dostanie ją e-mailem w PDF' . (!empty($d['grant']) ? ', a Helpdesk IT zlecenie założenia konta.' : '.')) ?>
      <?php else: ?><p class="small text-secondary mb-0">Decyzję zatwierdza kierownik (rola „kierownik” panelu TI) lub administrator.</p><?php endif; ?>
    <?php endif; ?>
    <form method="post" action="<?= h(route('ld.withdraw', ['id' => $id])) ?>" class="d-flex gap-1" onsubmit="return confirm('Wycofać wniosek?')"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="wr">Powód wycofania</label><input class="form-control form-control-sm" id="wr" name="reason" placeholder="powód wycofania">
      <button class="btn btn-sm btn-metro red">Wycofaj</button></form>
  <?php elseif ($st === LicenseRequestStatus::Started): ?>
    <?= ldPost(route('ld.licensor.resend', ['id' => $id]), 'Wyślij ponownie do licencjodawcy (nowy link)', 'btn-metro') ?>
    <form method="post" action="<?= h(route('ld.withdraw', ['id' => $id])) ?>" class="d-flex gap-1" onsubmit="return confirm('Wycofać wniosek?')"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="wr">Powód wycofania</label><input class="form-control form-control-sm" id="wr" name="reason" placeholder="powód wycofania">
      <button class="btn btn-sm btn-metro red">Wycofaj</button></form>
  <?php elseif ($st === LicenseRequestStatus::Granted): ?>
    <?php $_tn = $am7 ? (string)(db_one("SELECT tenant_upn FROM licenses WHERE id = ?", [$id])['tenant_upn'] ?? '') : '';
          $_am90 = ($am7 && $_tn !== '') ? (string)(db_one("SELECT am90_sent_at FROM licenses WHERE id = ?", [$id])['am90_sent_at'] ?? '') : '';
          $_tenantAccess = ($d['access'] ?? ($rq['access'] ?? 'tenant')) !== 'other'; ?>
    <?php if ($am7 && ($_tenantAccess || $_tn !== '')): ?>
    <div class="ld-gh">Konto w tenancie</div>
    <?php if ($_tenantAccess && $_tn === ''): ?>
      <a class="btn btn-sm btn-metro green" href="<?= h(route('ld.am7.tenant', ['id' => $id])) ?>">Utwórz konto w tenancie</a>
      <div class="ld-note">Wybierasz tenant (FEER / EDU FEER); braki w danych uzupełnisz przed wysyłką.</div>
    <?php elseif ($_tn !== ''): ?>
      <div class="ld-note" style="margin:0 0 .2rem">Konto: <b><?= h($_tn) ?></b></div>
      <a class="btn btn-sm btn-metro dark ld-out" href="<?= h(route('ld.am90.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener">Druk AM90 — potwierdzenie warunków</a>
      <?= ldPost(route('ld.am90.send', ['id' => $id]), $_am90 !== '' ? 'Wyślij AM90 ponownie (ręcznie)' : 'Wyślij AM90 ręcznie', 'btn-metro', [], 'Wysłać AM90 e-mailem w PDF (kopia do administratora)? Zgłoszenie w Helpdesku zostanie zamknięte.') ?>
      <?php if ($_am90 !== ''): ?><div class="ld-note">AM90 wysłano <?= h(ldDt($_am90)) ?>; zgłoszenie w Helpdesku zamknięte.</div><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($am7 && empty($l['helpdesk_ticket_id'])): ?>
    <div class="ld-gh">Helpdesk IT</div>
    <?= ldPost(route('ld.am7.helpdesk', ['id' => $id]), 'Utwórz zlecenie Helpdesku IT', 'btn-metro dark') ?>
    <?php endif; ?>
    <div class="ld-gh">Licencja</div>
    <form method="post" action="<?= h(route('ld.suspend', ['id' => $id])) ?>" class="ld-susp"><?= Router::csrfField() ?>
      <?php if (empty($l['suspended'])): ?><input type="hidden" name="on" value="1"><label class="visually-hidden" for="sr">Powód zawieszenia</label>
        <input class="form-control form-control-sm" id="sr" name="reason" placeholder="powód zawieszenia" required><button class="btn btn-sm btn-metro orange">Zawieś</button>
      <?php else: ?><button class="btn btn-sm btn-metro green" style="grid-column:1/-1;width:100%">Wznów licencję</button><?php endif; ?></form>
  <?php else: ?>
    <p class="text-secondary mb-0">Sprawa zamknięta (<?= h(mb_strtolower($st->label())) ?>). Zostaje w dzienniku audytowym.</p>
  <?php endif; ?>
  </div>
</div></section>
</div>

<?php if ($st === LicenseRequestStatus::Started && $am7):
  $o = $am7Old ?: ($d ?: ['grant' => true, 'access' => $l['request']['access'] ?? 'tenant', 'access_other' => $l['request']['access_other'] ?? '', 'account' => $l['request']['account'] ?? '',
        'plan' => $l['request']['plan'] ?? '', 'period_from' => $l['period_from'], 'period_to' => $l['period_to'], 'signer_name' => $actor['name']]);
  $ov = fn(string $k) => h((string)($o[$k] ?? ''));
  $isGrant = isset($o['decision']) ? $o['decision'] === 'grant' : !empty($o['grant']); ?>
<section class="panel warn" id="decyzja">
  <h2 class="mt-0">Decyzja placówki — <?= $d ? 'projekt (możesz poprawić)' : 'przygotuj projekt' ?> <small class="text-secondary">wysyłka e-mailem w PDF po zatwierdzeniu przez kierownika</small></h2>
  <form method="post" action="<?= h(route('ld.am7.prepare', ['id' => $id])) ?>" class="row g-2"><?= Router::csrfField() ?>
    <div class="col-md-3"><label class="form-label small" for="ad">Rozstrzygnięcie</label><select class="form-select" id="ad" name="decision">
      <option value="grant"<?= $isGrant ? ' selected' : '' ?>>przyznanie — AM7/2</option><option value="refuse"<?= !$isGrant ? ' selected' : '' ?>>odmowa — AM7/3</option></select></div>
    <div class="col-md-3"><label class="form-label small" for="aa">Sposób udostępnienia</label><select class="form-select" id="aa" name="access">
      <option value="tenant"<?= ($o['access'] ?? '') !== 'other' ? ' selected' : '' ?>>konto w tenancie ośrodka</option><option value="other"<?= ($o['access'] ?? '') === 'other' ? ' selected' : '' ?>>inny sposób</option></select></div>
    <div class="col-md-6"><label class="form-label small" for="aao">Inny sposób (opis)</label><input class="form-control" id="aao" name="access_other" value="<?= $ov('access_other') ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="aac">Konto przypisane kursantowi</label><input class="form-control" id="aac" name="account" value="<?= $ov('account') ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="apl">Produkt, rodzaj licencji, plan / SKU</label><input class="form-control" id="apl" name="plan" value="<?= $ov('plan') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="apf">Dostęp od</label><input class="form-control" type="date" id="apf" name="period_from" value="<?= $ov('period_from') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="apt">do</label><input class="form-control" type="date" id="apt" name="period_to" value="<?= $ov('period_to') ?>"></div>
    <div class="col-md-6"><label class="form-label small" for="asv">Zakres udostępnionych funkcji lub usług</label><input class="form-control" id="asv" name="services" value="<?= $ov('services') ?>" placeholder="np. wszystkie aplikacje, 100 GB chmury"></div>
    <div class="col-md-6"><label class="form-label small" for="ars">Ograniczenia i zasady korzystania</label><input class="form-control" id="ars" name="restrictions" value="<?= $ov('restrictions') ?>"></div>
    <div class="col-md-6"><label class="form-label small" for="aae">Postępowanie po zakończeniu kursu lub okresu dostępu</label><textarea class="form-control" id="aae" name="after_end" rows="2"><?= $ov('after_end') ?></textarea></div>
    <div class="col-md-6"><label class="form-label small" for="aad">Postępowanie z danymi i materiałami</label><textarea class="form-control" id="aad" name="account_data" rows="2"><?= $ov('account_data') ?></textarea></div>
    <div class="col-md-8"><label class="form-label small" for="arr">Uzasadnienie odmowy (przy odmowie)</label><input class="form-control" id="arr" name="refusal_reason" value="<?= $ov('refusal_reason') ?>"></div>
    <div class="col-md-4"><label class="form-label small" for="asn">Osoba upoważniona do podjęcia decyzji</label><input class="form-control" id="asn" name="signer_name" value="<?= $ov('signer_name') ?>"></div>
    <?php if (!$l['requester_signed_at']): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="paper_signed" value="1" id="aps"<?= !empty($o['paper_signed']) ? ' checked' : '' ?>><label class="form-check-label small" for="aps">Kursant podpisał wniosek odręcznie (nie w portalu)</label></div></div><?php endif; ?>
    <div class="col-12"><button class="btn btn-metro">Zapisz projekt decyzji</button> <span class="small text-secondary">Kierownik dostanie alert; wysyłka dopiero po zatwierdzeniu.</span></div>
  </form>
</section>
<?php elseif ($st === LicenseRequestStatus::Started): ?>
<section class="panel warn" id="decyzja">
  <h2 class="mt-0">Decyzja licencjodawcy z papieru <small class="text-secondary">(sekcja H wydruku — gdy licencjodawca nie użył portalu)</small></h2>
  <form method="post" action="<?= h(route('ld.decide', ['id' => $id])) ?>" class="row g-2"><?= Router::csrfField() ?>
    <div class="col-md-3"><label class="form-label small" for="dd">Rozstrzygnięcie</label><select class="form-select" id="dd" name="decision"><option value="grant">udzielono licencji</option><option value="refuse">odmowa</option></select></div>
    <div class="col-md-3"><label class="form-label small" for="dk">Rodzaj</label><select class="form-select" id="dk" name="kind"><option value="niewylaczna">niewyłączna</option><option value="wylaczna">wyłączna</option></select></div>
    <div class="col-md-3"><label class="form-label small" for="dpf">Okres od</label><input class="form-control" type="date" id="dpf" name="period_from" value="<?= h($l['period_from']) ?>"></div>
    <div class="col-md-3"><label class="form-label small" for="dpt">do</label><input class="form-control" type="date" id="dpt" name="period_to" value="<?= h($l['period_to']) ?>"></div>
    <div class="col-md-12"><label class="form-label small" for="ds">Zaakceptowany zakres i ograniczenia</label><textarea class="form-control" id="ds" name="accepted_scope" rows="2"></textarea></div>
    <div class="col-md-3"><label class="form-label small" for="dt">Terytorium</label><input class="form-control" id="dt" name="territory" value="<?= h($l['territory']) ?>"></div>
    <div class="col-md-3"><label class="form-label small" for="dw">Wynagrodzenie / warunki</label><input class="form-control" id="dw" name="terms" value="<?= h($l['request']['remuneration'] ?? '') ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="dpl">Miejscowość</label><input class="form-control" id="dpl" name="place"></div>
    <div class="col-md-2"><label class="form-label small" for="dda">Data</label><input class="form-control" type="date" id="dda" name="date" value="<?= h(ldToday()) ?>"></div>
    <div class="col-md-2"><label class="form-label small" for="dsn">Podpisał(a)</label><input class="form-control" id="dsn" name="signer_name" value="<?= h($l['licensor_name']) ?>"></div>
    <div class="col-md-9"><label class="form-label small" for="drr">Uzasadnienie odmowy</label><input class="form-control" id="drr" name="refusal_reason"></div>
    <div class="col-md-3 d-flex align-items-end gap-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="dcf"><label class="form-check-label small" for="dcf">zgodne z podpisanym wydrukiem</label></div></div>
    <div class="col-12"><button class="btn btn-metro">Zapisz decyzję</button></div>
  </form>
</section>
<?php endif; ?>

<section class="panel" id="przydzialy">
  <h2 class="mt-0">Przydziały i oświadczenia <?= h(implode(' / ', array_map(fn($x) => $x->symbol(), $l['form']->declarationDocs()))) ?></h2>
  <?php if (!$asg): ?><p class="text-secondary">Brak przydziałów.</p><?php endif; ?>
  <?php foreach ($asg as $a): $aid = (int)$a['id']; $s = $a['statusEnum']; ?>
    <div class="border-start border-4 ps-3 mb-3" style="border-color:<?= h($s->color()) ?> !important">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <b><?= h($a['holder_name']) ?></b> <?= ldChip($s->label(), $s->color()) ?>
        <span class="small text-secondary"><?= ['client' => 'uczestnik', 'user' => 'współpracownik', 'external' => 'spoza SZO'][$a['holder_kind']] ?? '' ?>
          · przydział <?= h(ldDt($a['assigned_at'])) ?> · termin <?= h(ldDate($a['due_on'])) ?><?= $s->canSubmit() && $a['due_on'] < ldToday() ? ' <b class="text-danger">minął</b>' : '' ?></span>
        <span class="ms-auto d-flex gap-1">
          <?php if ($s->canSubmit()): ?><?= ldPost(route('ld.invite', ['id' => $aid]), 'Wyślij link ponownie', 'btn-metro') ?><?php endif; ?>
          <?php if ($s !== LicenseAssignmentStatus::Revoked): ?>
            <form method="post" action="<?= h(route('ld.revoke', ['id' => $aid])) ?>" class="d-flex gap-1" onsubmit="return confirm('Cofnąć przydział?')"><?= Router::csrfField() ?>
              <label class="visually-hidden" for="rv<?= $aid ?>">Powód cofnięcia</label><input class="form-control form-control-sm" id="rv<?= $aid ?>" name="reason" placeholder="powód cofnięcia" style="width:12rem">
              <button class="btn btn-sm btn-outline-danger">Cofnij</button></form>
          <?php endif; ?>
        </span>
      </div>
      <?php if (!empty($decl[$aid])): ?>
        <ul class="list-unstyled small mt-2 mb-1">
        <?php foreach ($decl[$aid] as $dc): $ds = LicenseDeclarationStatus::tryFrom((string)$dc['status']); ?>
          <li><span class="sym"><?= h($dc['doc']) ?></span> <a href="<?= h(route('ld.decl.file', ['id' => $dc['id']])) ?>" target="_blank" rel="noopener"><?= h($dc['number']) ?></a>
            · <?= h(ldDt($dc['signed_at'])) ?> · IP <?= h($dc['signed_ip']) ?> · <?= h($ds?->label() ?? $dc['status']) ?> <?= str_ends_with((string)$dc['file_path'], '.html') ? '(HTML — PDF był niedostępny)' : '' ?>
            <span class="text-secondary">SHA-256 <?= h(substr((string)$dc['sha256'], 0, 16)) ?>…</span></li>
        <?php endforeach; ?></ul>
      <?php endif; ?>
      <?php if ($s === LicenseAssignmentStatus::Submitted): ?>
        <form method="post" action="<?= h(route('ld.verify', ['id' => $aid])) ?>" class="d-flex flex-wrap gap-2 my-2"><?= Router::csrfField() ?>
          <label class="visually-hidden" for="vn<?= $aid ?>">Uwagi / powód odrzucenia</label>
          <input class="form-control form-control-sm" id="vn<?= $aid ?>" name="note" placeholder="uwagi (przy odrzuceniu — wymagany powód)" style="max-width:28rem">
          <button class="btn btn-sm btn-metro green" name="decision" value="approve">Zatwierdź</button>
          <button class="btn btn-sm btn-metro red" name="decision" value="reject">Odrzuć — wyślij nowy link</button></form>
      <?php elseif ($a['decision_note'] !== null && $a['decision_note'] !== ''): ?><p class="small mb-1">Weryfikacja (<?= h($a['decided_name']) ?>): <?= h($a['decision_note']) ?></p><?php endif; ?>
      <?php if (!empty($changes[$aid])): ?>
        <details class="small"><summary>Zmiany danych z portalu (<?= count($changes[$aid]) ?>)</summary>
          <table class="table table-sm mb-1"><thead><tr><th>Pole</th><th>Było</th><th>Jest</th><th>Stan</th><th>Kiedy</th><th></th></tr></thead><tbody>
          <?php foreach ($changes[$aid] as $c): [$cl, $cc] = $chLabel[$c['status']] ?? [$c['status'], '#647687']; ?>
            <tr><td><?= h($fieldLabels[$c['field']] ?? $c['field']) ?></td><td><?= h($c['old_value']) ?></td><td><b><?= h($c['new_value']) ?></b></td><td><?= ldChip($cl, $cc) ?></td><td><?= h(ldDt($c['created_at'])) ?></td>
              <td class="text-nowrap"><?php if ($c['status'] === 'pending' && empty($c['cdc_id'])): ?>
                <?= ldPost(route('ld.change', ['id' => $c['id']]), 'Wprowadź', 'btn-metro green', ['decision' => 'apply']) ?> <?= ldPost(route('ld.change', ['id' => $c['id']]), 'Odrzuć', 'btn-outline-secondary', ['decision' => 'reject']) ?>
              <?php elseif (!empty($c['cdc_id'])): ?><a href="<?= h(rtrim(APP_URL, '/') . '/modules/collaborator_data/router.php?_route=/admin') ?>">kolejka „Zmiana danych”</a><?php endif; ?></td></tr>
          <?php endforeach; ?></tbody></table></details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($l['poolStatus'] === LicenseStatus::Free || $l['poolStatus'] === LicenseStatus::Assigned): ?>
  <form method="post" action="<?= h(route('ld.assign', ['id' => $id])) ?>" class="row g-2 align-items-end border-top pt-3"><?= Router::csrfField() ?>
    <div class="col-12"><b>Przydziel licencję</b> <span class="small text-secondary">— osoba dostanie e-mail i SMS z linkiem do oświadczeń (termin <?= LD_REALIZATION_DAYS ?> dni).</span></div>
    <div class="col-md-2"><label class="form-label small" for="hk">Kto</label><select class="form-select" id="hk" name="holder_kind">
      <option value="client">uczestnik</option><option value="user">współpracownik</option><option value="external">spoza SZO</option></select></div>
    <div class="col-md-3" data-ld-kind="client"><label class="form-label small" for="hc">Uczestnik</label><select class="form-select" id="hc" name="holder_id_client"><option value="">—</option>
      <?php foreach ($people['client'] as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3" data-ld-kind="user"><label class="form-label small" for="hu">Współpracownik</label><select class="form-select" id="hu" name="holder_id_user"><option value="">—</option>
      <?php foreach ($people['user'] as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small" for="hn">Imię i nazwisko</label><input class="form-control" id="hn" name="name" placeholder="spoza SZO"></div>
    <div class="col-md-2"><label class="form-label small" for="he">E-mail</label><input class="form-control" type="email" id="he" name="email" placeholder="puste = z SZO"></div>
    <div class="col-md-2"><label class="form-label small" for="hp">Telefon</label><input class="form-control" id="hp" name="phone" placeholder="puste = z SZO"></div>
    <div class="col-md-1"><button class="btn btn-metro w-100">Przydziel</button></div>
  </form>
  <script>
  (function () {
    var sel = document.getElementById('hk');
    function sync() { document.querySelectorAll('#przydzialy [data-ld-kind]').forEach(function (el) { el.hidden = el.getAttribute('data-ld-kind') !== sel.value; }); }
    sel.addEventListener('change', sync); sync();
  })();
  </script>
  <?php endif; ?>
</section>

<div class="row g-3">
<section class="col-lg-6"><div class="panel h-100">
  <h2 class="mt-0">Dziennik sprawy</h2>
  <ol class="list-unstyled small mb-0" style="max-height:24rem;overflow:auto">
  <?php foreach ($events as $ev): ?><li class="mb-1"><span class="text-secondary"><?= h(ldDt($ev['created_at'])) ?></span> <b><?= h($ev['actor_name'] ?: 'system') ?></b> — <?= h($ev['note']) ?></li><?php endforeach; ?>
  </ol>
</div></section>
<section class="col-lg-6"><div class="panel h-100">
  <h2 class="mt-0">Powiadomienia</h2>
  <?php if (!$notifs): ?><p class="text-secondary small mb-0">Jeszcze nic nie wysłano.</p><?php else: ?>
  <div style="max-height:24rem;overflow:auto"><table class="table table-sm small mb-0"><thead><tr><th>Kiedy</th><th>Rodzaj</th><th>Kanał</th><th>Adresat</th><th>Stan</th></tr></thead><tbody>
  <?php foreach ($notifs as $n): $nt = LicenseNotificationType::tryFrom((string)$n['type']); $col = ['sent' => '#008a00', 'queued' => '#f0a30a', 'failed' => '#a20025', 'skipped' => '#647687'][$n['status']] ?? '#647687'; ?>
    <tr><td class="text-nowrap"><?= h(ldDt($n['created_at'])) ?></td><td><?= h($nt?->label() ?? $n['type']) ?></td><td><?= $n['channel'] === 'sms' ? 'SMS' : 'e-mail' ?><?= $n['is_fallback'] ? ' <span class="badge text-bg-secondary">fallback</span>' : '' ?></td>
      <td><?= h($n['recipient']) ?></td><td><?= ldChip(LicenseNotificationService::statusLabel((string)$n['status']), $col) ?><?= $n['error'] ? '<div class="text-secondary">' . h($n['error']) . '</div>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div></section>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
