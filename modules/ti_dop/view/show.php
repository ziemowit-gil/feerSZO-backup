<?php
/**
 * modules/ti_dop/view/show.php — karta wniosku DOP1 / DOP2: dane, podpis wnioskodawcy (link / skan), ścieżka obsługi
 * (weryfikacja, zatwierdzenie, realizacja) ze stemplami, dziennik i powiadomienia. Zmienne: $r, $c, $events, $notifs, $actor, $canApprove.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$f = $r['formEnum']; $st = $r['statusEnum']; $id = (int)$r['id']; $steps = $r['stepsData'];
$title = $r['number'];
include __DIR__ . '/_head.php';
$fmt = fn($v) => number_format((float)$v, 2, ',', ' ') . ' zł';
$stamp = fn(array $s) => h(($s['name'] ?? '') . ', ' . ldDt($s['at'] ?? '') . ', IP ' . ($s['ip'] ?? ''));
?>
<div class="pivot"><a href="<?= h(route('dop.index')) ?>">Dokumenty rozliczeniowe</a><a href="#" aria-current="page"><?= h($r['number']) ?></a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:<?= h($f->color()) ?>"><?= h($f->value) ?></span> <?= h($f->title()) ?></h1>
  <a class="btn btn-metro dark" href="<?= h(route('dop.pdf', ['id' => $id])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Wniosek (PDF)</a>
</div>
<div class="tiles">
  <div class="tile wide" style="background:<?= h($f->color()) ?>"><span class="n" style="font-size:1.2rem;font-weight:600"><?= h($r['number']) ?></span><span class="l"><?= h($r['client_name']) ?></span></div>
  <div class="tile" style="background:<?= h($st->color()) ?>"><span class="n" style="font-size:1rem;font-weight:600"><?= h($st->label()) ?></span><span class="l">Stan</span></div>
  <?php if ($f->isNonFinancial()): ?>
  <div class="tile wide" style="background:#1d1d1d"><span class="n" style="font-size:1.05rem;font-weight:600"><?= h(($r['d']['grupa_nazwa'] ?? '') ?: (($r['d']['zakres'] ?? '') === 'wszystkie' ? 'wszystkie zajęcia i usługi' : '—')) ?></span><span class="l"><?= $f->isResignation() ? 'Rezygnacja z dniem ' . h(ldDate((string)($r['d']['data_rezygnacji'] ?? ''))) : 'Grupa · obecnie: ' . h($r['d']['obecny'] ?? '—') ?></span></div>
  <?php else: ?>
  <div class="tile" style="background:#1d1d1d"><span class="n" style="font-size:1.3rem"><?= h($fmt($r['d']['kwota'] ?? 0)) ?></span><span class="l">Kwota</span></div>
  <div class="tile" style="background:<?= $c && $c['credit'] + 0.004 >= (float)($r['d']['kwota'] ?? 0) ? '#008a00' : '#a20025' ?>"><span class="n" style="font-size:1.3rem"><?= h($fmt($c['credit'] ?? 0)) ?></span><span class="l">Nadpłata / saldo w księdze teraz</span></div>
  <?php endif; ?>
  <div class="tile" style="background:#f0a30a"><span class="n" style="font-size:1.2rem"><?= h(ldDate($r['due_on'])) ?></span><span class="l">Termin <?= LD_REALIZATION_DAYS ?> dni</span></div>
</div>
<div class="row g-3">
<section class="col-lg-6"><div class="panel accent h-100"><h2 class="mt-0">Wniosek</h2>
  <dl class="row small mb-0">
    <?php foreach ($f->fields() as $k => [$label, $type, , $opts]): $vv = (string)($r['d'][$k] ?? ''); if ($type === 'check') $vv = $vv !== '' ? 'tak' : 'nie'; elseif ($type === 'money' && $vv !== '') $vv = $fmt($vv); elseif ($type === 'date') $vv = ldDate($vv); elseif ($type === 'select') $vv = (string)($opts[$vv] ?? $vv); elseif ($type === 'course') $vv = (string)($r['d']['grupa_nazwa'] ?? ''); ?>
      <dt class="col-sm-5"><?= h($label) ?></dt><dd class="col-sm-7"><?= h($vv !== '' ? $vv : '—') ?></dd>
    <?php endforeach; ?>
    <dt class="col-sm-5">Miejscowość, złożono</dt><dd class="col-sm-7"><?= h($r['place']) ?>, <?= h(ldDt($r['created_at'])) ?> — <?= h($r['created_name']) ?></dd>
    <dt class="col-sm-5">Podpis wnioskodawcy</dt><dd class="col-sm-7"><?= $r['signed_at'] ? ldChip($r['sign_mode'] === 'link' ? 'podpisano elektronicznie' : 'podpis odręczny', '#008a00') . ' ' . h($r['signed_name']) . ', ' . h(ldDt($r['signed_at'])) . ($r['signed_ip'] ? ', IP ' . h($r['signed_ip']) : '') : ldChip('czeka', '#fa6800') ?>
      <?php if ($r['scan_path']): ?><div><a href="<?= h(route('dop.scan.get', ['id' => $id])) ?>" target="_blank" rel="noopener">skan: <?= h($r['scan_name']) ?></a></div><?php endif; ?></dd>
  </dl>
</div></section>
<section class="col-lg-6"><div class="panel h-100" id="obsluga"><h2 class="mt-0">Obsługa</h2>
  <ol class="small ps-3">
    <?php foreach ($f->steps() as $sk => [$sl, $needKier]): $done = $steps[$sk] ?? null; ?>
      <li class="mb-1"><b><?= h($sl) ?></b><?= $needKier ? ' <span class="text-secondary">(kierownik)</span>' : '' ?>: <?= $done ? $stamp($done) : '<span class="text-secondary">—</span>' ?>
        <?php if ($done && $sk === 'verify' && $f->isNonFinancial()): ?><div><?= !empty($done['grant']) ? 'zgoda' : 'odmowa' ?><?= !empty($done['powod']) ? ': ' . h($done['powod']) : '' ?></div>
        <?php elseif ($done && $sk === 'execute' && $f->isNonFinancial()): ?><div><?= $f->isResignation() ? 'zapis zakończony z dniem ' . h(ldDate($done['data_zakonczenia'] ?? '')) . (!empty($done['zapisy']) ? ' (' . h(implode(', ', $done['zapisy'])) . ')' : '') : 'od ' . h(ldDate($done['data_zmiany'] ?? '')) . (($done['nowy'] ?? '') !== '' ? ': ' . h($done['nowy']) : '') . (!empty($done['w_systemie']) ? ' — ustawiono w grupie' : '') ?></div>
        <?php elseif ($done && $sk === 'verify'): ?><div>saldo: <?= h($fmt($done['saldo'] ?? 0)) ?><?= isset($done['zgodnosc']) ? ', rachunek ' . h($done['zgodnosc']) : '' ?><?= !empty($done['powod']) ? ', powód: ' . h($done['powod']) : '' ?></div><?php endif; ?>
        <?php if ($done && $sk === 'approve'): ?><div><?= !empty($done['zgoda']) ? 'zgoda' : 'brak zgody: ' . h($done['uwagi'] ?? '') ?></div><?php endif; ?>
        <?php if ($done && $sk === 'execute'): ?><div>przelew <?= h(ldDate($done['data_przelewu'] ?? '')) ?>, transakcja <b><?= h($done['transakcja'] ?? '') ?></b></div><?php endif; ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($st === DopStatus::Signing): ?>
    <?php if ($r['sign_mode'] === 'link'): ?><?= ldPost(route('dop.link', ['id' => $id]), 'Wyślij link do podpisu ponownie (e-mail + SMS)', 'btn-metro') ?><?php endif; ?>
    <form method="post" action="<?= h(route('dop.scan', ['id' => $id])) ?>" enctype="multipart/form-data" class="mt-2 d-flex gap-2 align-items-end"><?= Router::csrfField() ?>
      <div class="flex-fill"><label class="form-label small mb-0" for="skan">Skan wniosku podpisanego odręcznie</label><input class="form-control form-control-sm" type="file" id="skan" name="skan" accept=".pdf,.jpg,.jpeg,.png"></div><button class="btn btn-sm btn-metro dark">Wgraj</button></form>
  <?php elseif ($st === DopStatus::Verify && $f->isNonFinancial()): ?>
    <?php if ($canApprove): ?>
    <form method="post" action="<?= h(route('dop.verify', ['id' => $id])) ?>" class="d-grid gap-2"><?= Router::csrfField() ?>
      <label class="form-label small mb-0" for="powodN">Uwagi (przy odmowie — wymagane)</label><input class="form-control form-control-sm" id="powodN" name="powod">
      <div class="d-flex gap-2"><button class="btn btn-metro green flex-fill" name="decision" value="grant"><?= $f->isResignation() ? 'Przyjmij rezygnację' : 'Zgoda na zmianę' ?></button><button class="btn btn-metro red flex-fill" name="decision" value="reject">Odmów</button></div>
    </form>
    <?php else: ?><p class="small text-secondary">Decyzję podejmuje kierownik albo administrator.</p><?php endif; ?>
  <?php elseif ($st === DopStatus::Execute && $f->isResignation()): ?>
    <form method="post" action="<?= h(route('dop.execute', ['id' => $id])) ?>" class="row g-2"><?= Router::csrfField() ?>
      <div class="col-6"><label class="form-label small mb-0" for="dz">Zakończ zapis z dniem</label><input class="form-control form-control-sm" type="date" id="dz" name="data_przelewu" value="<?= h((string)($r['d']['data_rezygnacji'] ?? ldToday())) ?>"></div>
      <div class="col-6 small align-self-end"><?= ($r['d']['zakres'] ?? '') === 'grupa' ? 'grupa: <b>' . h($r['d']['grupa_nazwa'] ?? '') . '</b>' : '<b>wszystkie aktywne zapisy</b>' ?></div>
      <div class="col-12"><button class="btn btn-metro green w-100">Zakończ zapis i wyślij potwierdzenie</button><div class="small text-secondary mt-1">Zapis przejdzie na nieaktywny (z datą końca); uczestnik, rodzic i prowadzący dostaną potwierdzenie wypisania; dokument trafi do teczki.</div></div>
    </form>
  <?php elseif ($st === DopStatus::Execute && $f === DopForm::Zpro): ?>
    <form method="post" action="<?= h(route('dop.execute', ['id' => $id])) ?>" class="row g-2"><?= Router::csrfField() ?>
      <div class="col-6"><label class="form-label small mb-0" for="dzm">Zmiana od dnia</label><input class="form-control form-control-sm" type="date" id="dzm" name="data_przelewu" value="<?= h((string)(($r['d']['od_dnia'] ?? '') ?: ldToday())) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="np">Nowa osoba prowadząca</label><select class="form-select form-select-sm" id="np" name="nowy_prowadzacy"><option value="0">— bez zmiany w systemie —</option><?php foreach ($instructors ?? [] as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="uwg" name="ustaw_w_grupie" value="1"><label class="form-check-label small" for="uwg">Ustaw jako prowadzącego grupy „<?= h($r['d']['grupa_nazwa'] ?? '') ?>” w systemie</label></div></div>
      <div class="col-12"><button class="btn btn-metro green w-100">Zarejestruj zmianę i wyślij decyzję</button></div>
    </form>
  <?php elseif ($st === DopStatus::Verify && $f === DopForm::Dop1): ?>
    <?php if ($canApprove): ?>
    <form method="post" action="<?= h(route('dop.verify', ['id' => $id])) ?>" class="d-grid gap-2"><?= Router::csrfField() ?>
      <div class="small">Saldo nadpłaty w księdze na dziś (<?= h(ldDate(ldToday())) ?>): <b><?= h($fmt($c['credit'] ?? 0)) ?></b></div>
      <div><span class="small d-block">Zgodność numeru rachunku</span>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="zgodnosc" id="z1" value="zgodny"><label class="form-check-label small" for="z1">zgodny z historią wpłat</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="zgodnosc" id="z2" value="niezgodny"><label class="form-check-label small" for="z2">niezgodny (załączono upoważnienie)</label></div></div>
      <label class="form-label small mb-0" for="powod">Powód odrzucenia</label><input class="form-control form-control-sm" id="powod" name="powod">
      <div class="d-flex gap-2"><button class="btn btn-metro green flex-fill" name="decision" value="grant">Zatwierdź do zwrotu</button><button class="btn btn-metro red flex-fill" name="decision" value="reject">Odrzuć</button></div>
    </form>
    <?php else: ?><p class="small text-secondary">Decyzję podejmuje kierownik albo administrator.</p><?php endif; ?>
  <?php elseif ($st === DopStatus::Verify): ?>
    <form method="post" action="<?= h(route('dop.verify', ['id' => $id])) ?>" class="d-grid gap-2"><?= Router::csrfField() ?>
      <div class="small">Dostępne saldo subkonta (nadpłata w księdze): <b><?= h($fmt($c['credit'] ?? 0)) ?></b> · kwota do uruchomienia <b><?= h($fmt($r['d']['kwota'] ?? 0)) ?></b></div>
      <label class="form-label small mb-0" for="powod2">Uwagi / powód odrzucenia</label><input class="form-control form-control-sm" id="powod2" name="powod">
      <div class="d-flex gap-2"><button class="btn btn-metro green flex-fill" name="decision" value="grant">Środki dostępne — do zatwierdzenia</button><button class="btn btn-metro red flex-fill" name="decision" value="reject">Odrzuć</button></div>
    </form>
  <?php elseif ($st === DopStatus::Approve): ?>
    <?php if ($canApprove): ?>
    <form method="post" action="<?= h(route('dop.approve', ['id' => $id])) ?>" class="d-grid gap-2"><?= Router::csrfField() ?>
      <label class="form-label small mb-0" for="uw">Uwagi (przy braku zgody — wymagane)</label><input class="form-control form-control-sm" id="uw" name="uwagi">
      <div class="d-flex gap-2"><button class="btn btn-metro green flex-fill" name="decision" value="yes">Wyrażam zgodę na realizację</button><button class="btn btn-metro red flex-fill" name="decision" value="no">Brak zgody</button></div>
    </form>
    <?php else: ?><p class="small text-secondary">Zatwierdza kierownik albo administrator.</p><?php endif; ?>
  <?php elseif ($st === DopStatus::Execute): ?>
    <form method="post" action="<?= h(route('dop.execute', ['id' => $id])) ?>" class="row g-2"><?= Router::csrfField() ?>
      <div class="col-6"><label class="form-label small mb-0" for="dp">Data wykonania przelewu</label><input class="form-control form-control-sm" type="date" id="dp" name="data_przelewu" value="<?= h(ldToday()) ?>"></div>
      <div class="col-6"><label class="form-label small mb-0" for="tx">Nr transakcji / wyciągu</label><input class="form-control form-control-sm" id="tx" name="transakcja"></div>
      <div class="col-12"><button class="btn btn-metro green w-100">Zarejestruj realizację przelewu</button>
        <div class="small text-secondary mt-1"><?= $f === DopForm::Dop1 ? 'Zwrot zostanie zaksięgowany w rejestrze nadpłat i księdze uczestnika.' : 'Płatność zostanie zaksięgowana w księdze uczestnika (wypłata z subkonta).' ?> Dokument trafi do wnioskodawcy i do teczki kursanta.</div></div>
    </form>
  <?php endif; ?>
  <?php if (in_array($st, [DopStatus::Signing, DopStatus::Verify, DopStatus::Approve], true)): ?>
    <form method="post" action="<?= h(route('dop.cancel', ['id' => $id])) ?>" class="d-flex gap-1 mt-3" onsubmit="return confirm('Wycofać wniosek?')"><?= Router::csrfField() ?>
      <label class="visually-hidden" for="cr">Powód</label><input class="form-control form-control-sm" id="cr" name="reason" placeholder="powód wycofania"><button class="btn btn-sm btn-outline-danger">Wycofaj</button></form>
  <?php endif; ?>
</div></section>
</div>
<div class="row g-3">
<section class="col-lg-6"><div class="panel h-100"><h2 class="mt-0">Dziennik</h2>
  <ol class="list-unstyled small mb-0"><?php foreach ($events as $ev): ?><li class="mb-1"><span class="text-secondary"><?= h(ldDt($ev['created_at'])) ?></span> <b><?= h($ev['actor_name'] ?: 'system') ?></b> — <?= h($ev['note']) ?></li><?php endforeach; ?></ol></div></section>
<section class="col-lg-6"><div class="panel h-100"><h2 class="mt-0">Powiadomienia</h2>
  <?php if (!$notifs): ?><p class="small text-secondary mb-0">Brak.</p><?php else: ?><table class="table table-sm small mb-0"><tbody>
  <?php foreach ($notifs as $n): $nt = LicenseNotificationType::tryFrom((string)$n['type']); ?><tr><td class="text-nowrap"><?= h(ldDt($n['created_at'])) ?></td><td><?= h($nt?->label() ?? $n['type']) ?></td><td><?= $n['channel'] === 'sms' ? 'SMS' : 'e-mail' ?></td><td><?= h($n['recipient']) ?></td><td><?= h(LicenseNotificationService::statusLabel((string)$n['status'])) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?></div></section>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
