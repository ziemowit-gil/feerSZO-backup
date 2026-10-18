<?php
/**
 * modules/ti_dop/view/settle_form.php — nowy PRZ-P / PRZ / RKO: uczestnik, grupa albo całe konto, pola dokumentu, tryb obiegu.
 * Zmienne: $kind, $c, $courses, $clients, $q, $errors, $old, $baselines, $requests.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'Nowy ' . $kind->value . ' — ' . $kind->title();
include __DIR__ . '/_head.php';
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
?>
<div class="pivot"><a href="<?= h(route('settle.index')) ?>">Rozliczenia</a><a href="#" aria-current="page"><?= h($kind->value) ?></a></div>
<h1><span class="sym" style="background:<?= h($kind->color()) ?>"><?= h($kind->value) ?></span> <?= h($kind->title()) ?></h1>
<?php if (!$c): ?>
<section class="panel accent"><form method="get" action="wnioskiDop.php" class="d-flex gap-2" role="search"><input type="hidden" name="_route" value="/rozliczenia/nowy"><input type="hidden" name="kind" value="<?= h($kind->value) ?>">
  <label class="visually-hidden" for="q">Uczestnik</label><input class="form-control" id="q" name="q" value="<?= h($q) ?>" placeholder="imię, nazwisko, e-mail uczestnika" autofocus><button class="btn btn-metro dark">Szukaj</button></form>
  <ul class="list-unstyled mt-2 mb-0"><?php foreach ($clients as $cl): ?><li class="py-1"><a class="btn btn-sm btn-metro" href="<?= h(route('settle.new', ['kind' => $kind->value, 'klient' => $cl['id']])) ?>">Wybierz</a> <?= h($cl['name']) ?></li><?php endforeach; ?></ul></section>
<?php else: ?>
<p><b><?= h($c['name']) ?></b> · saldo teraz <?= h(number_format($c['balance'], 2, ',', ' ')) ?> zł · <a href="<?= h(route('settle.new', ['kind' => $kind->value])) ?>">inny uczestnik</a></p>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>
<form method="post" action="<?= h(route('settle.new')) ?>" novalidate><?= Router::csrfField() ?><input type="hidden" name="kind" value="<?= h($kind->value) ?>"><input type="hidden" name="klient" value="<?= (int)$c['id'] ?>">
<section class="panel accent"><div class="row g-3">
  <?php if ($kind === SettleKind::Rko): ?>
  <div class="col-md-4"><span class="form-label d-block">Zakres</span>
    <div class="form-check"><input class="form-check-input" type="radio" name="scope" id="sc1" value="group"<?= ($old['scope'] ?? 'group') !== 'account' ? ' checked' : '' ?>><label class="form-check-label" for="sc1">grupa (zakończenie kursu)</label></div>
    <div class="form-check"><input class="form-check-input" type="radio" name="scope" id="sc2" value="account"<?= ($old['scope'] ?? '') === 'account' ? ' checked' : '' ?>><label class="form-check-label" for="sc2">całe konto uczestnika</label></div></div>
  <?php endif; ?>
  <div class="col-md-5"><label class="form-label" for="course_id">Grupa<?= in_array($kind, [SettleKind::Przp, SettleKind::Rm, SettleKind::Rr, SettleKind::Rrr], true) ? ' (puste = całe konto)' : '' ?></label>
    <select class="form-select" id="course_id" name="course_id"><option value="0"><?= in_array($kind, [SettleKind::Prz, SettleKind::Rmr], true) ? '— wybierz grupę —' : 'całe konto' ?></option>
      <?php foreach ($courses as $co): $lab = (trim((string)$co['display_name']) !== '' ? $co['display_name'] : $co['name']) . (trim((string)$co['group_code']) !== '' ? ' (' . $co['group_code'] . ')' : ''); ?>
        <option value="<?= (int)$co['id'] ?>"<?= (string)($old['course_id'] ?? '') === (string)$co['id'] ? ' selected' : '' ?>><?= h($lab) ?><?= $kind === SettleKind::Prz ? (in_array((int)$co['id'], $baselines, true) || in_array(0, $baselines, true) ? ' — PRZ-P ✓' : ' — brak PRZ-P') : '' ?></option><?php endforeach; ?></select><?= $err('course_id') ?></div>
  <div class="col-md-3"><label class="form-label" for="rn">Numer wniosku (opcjonalnie)</label><input class="form-control" id="rn" name="request_number" value="<?= $v('request_number') ?>" list="rnl" placeholder="pusto = <?= h($kind->suffix()) ?>">
    <datalist id="rnl"><?php foreach ($requests as $rq): ?><option value="<?= h($rq) ?>"><?php endforeach; ?></datalist></div>
</div></section>

<?php if (in_array($kind, [SettleKind::Rm, SettleKind::Rmr], true)): ?>
<section class="panel"><div class="row g-3"><div class="col-md-4"><label class="form-label" for="ym">Miesiąc rozliczenia *</label><input class="form-control" type="month" id="ym" name="ym" value="<?= $v('ym') ?: h(date('Y-m', strtotime('first day of last month'))) ?>"><?= $err('ym') ?></div>
  <div class="col-md-8 small text-secondary align-self-end"><?= $kind === SettleKind::Rm ? 'Dane z księgi: zajęcia z obecnością, godziny, należności, wpłaty i saldo na koniec miesiąca.' : 'Pozycje rozliczenia wpisujesz ręcznie; po zatwierdzeniu staną się kwotą ręczną rozliczenia tego miesiąca (miesięcy z fakturą nie zmieniamy).' ?></div></div></section>
<?php if ($kind === SettleKind::Rmr): $ls = array_values((array)($old['l'] ?? [])); while (count($ls) < 4) $ls[] = ['opis' => '', 'h' => '', 'rate' => '', 'amount' => '']; ?>
<section class="panel"><h2 class="mt-0">Pozycje rozliczenia</h2><?= $err('l') ?>
  <table class="table table-sm"><thead><tr><th>Pozycja (opis)</th><th>Godziny</th><th>Stawka (zł/h)</th><th>Kwota (puste = godz. × stawka)</th></tr></thead><tbody>
  <?php foreach ($ls as $i => $l): ?><tr><td><input class="form-control form-control-sm" name="l[<?= $i ?>][opis]" value="<?= h($l['opis']) ?>" aria-label="Pozycja <?= $i + 1 ?>"></td><td><input class="form-control form-control-sm" name="l[<?= $i ?>][h]" value="<?= h($l['h']) ?>" inputmode="decimal" aria-label="Godziny <?= $i + 1 ?>"></td>
    <td><input class="form-control form-control-sm" name="l[<?= $i ?>][rate]" value="<?= h($l['rate'] !== '' ? $l['rate'] : (string)($courses[0]['hourly_rate'] ?? '')) ?>" inputmode="decimal" aria-label="Stawka <?= $i + 1 ?>"></td><td><input class="form-control form-control-sm" name="l[<?= $i ?>][amount]" value="<?= h($l['amount']) ?>" inputmode="decimal" aria-label="Kwota <?= $i + 1 ?>"></td></tr><?php endforeach; ?>
  </tbody></table></section>
<?php endif; ?>
<?php elseif (in_array($kind, [SettleKind::Rr, SettleKind::Rrr], true)): ?>
<section class="panel"><div class="row g-3"><div class="col-md-3"><label class="form-label" for="yr">Rok *</label><input class="form-control" type="number" id="yr" name="year" min="2000" max="<?= (int)date('Y') + 1 ?>" value="<?= $v('year') ?: (int)date('Y') ?>"><?= $err('year') ?></div>
  <div class="col-md-9 small text-secondary align-self-end"><?= $kind === SettleKind::Rr ? 'Dane z księgi miesiąc po miesiącu: zajęcia, godziny, należności, wpłaty, saldo na koniec miesiąca.' : 'Dane roczne wpisujesz ręcznie (np. za lata sprzed SZO) — dokument bez zmian w księdze.' ?></div></div></section>
<?php if ($kind === SettleKind::Rrr): $ys = array_values((array)($old['y'] ?? [])); ?>
<section class="panel"><h2 class="mt-0">Miesiące</h2><?= $err('y') ?>
  <table class="table table-sm"><thead><tr><th>Miesiąc</th><th>Opis</th><th>Godziny</th><th>Należności (zł)</th><th>Wpłaty (zł)</th></tr></thead><tbody>
  <?php for ($m = 1; $m <= 12; $m++): $y = $ys[$m - 1] ?? []; ?><tr><td><?= sprintf('%02d', $m) ?><input type="hidden" name="y[<?= $m - 1 ?>][m]" value="<?= $m ?>"></td>
    <td><input class="form-control form-control-sm" name="y[<?= $m - 1 ?>][opis]" value="<?= h($y['opis'] ?? '') ?>" aria-label="Opis <?= $m ?>"></td><td><input class="form-control form-control-sm" name="y[<?= $m - 1 ?>][h]" value="<?= h($y['h'] ?? '') ?>" inputmode="decimal" aria-label="Godziny <?= $m ?>"></td>
    <td><input class="form-control form-control-sm" name="y[<?= $m - 1 ?>][charges]" value="<?= h($y['charges'] ?? '') ?>" inputmode="decimal" aria-label="Należności <?= $m ?>"></td><td><input class="form-control form-control-sm" name="y[<?= $m - 1 ?>][payments]" value="<?= h($y['payments'] ?? '') ?>" inputmode="decimal" aria-label="Wpłaty <?= $m ?>"></td></tr><?php endfor; ?>
  </tbody></table></section>
<?php endif; ?>
<?php elseif ($kind === SettleKind::Przp): ?>
<section class="panel"><h2 class="mt-0">Stan początkowy</h2><div class="row g-3">
  <div class="col-md-3"><label class="form-label" for="as_of">Na dzień *</label><input class="form-control" type="date" id="as_of" name="as_of" value="<?= $v('as_of') ?>"><?= $err('as_of') ?></div>
  <div class="col-md-3"><label class="form-label" for="gw">Godziny wykorzystane</label><input class="form-control" id="gw" name="godz_wykorzystane" value="<?= $v('godz_wykorzystane') ?>" inputmode="decimal"></div>
  <div class="col-md-3"><label class="form-label" for="go">Godziny opłacone</label><input class="form-control" id="go" name="godz_oplacone" value="<?= $v('godz_oplacone') ?>" inputmode="decimal"><?= $err('godz_oplacone') ?></div>
  <div class="col-md-3"><label class="form-label" for="sa">Saldo (zł; + nadpłata, − zaległość)</label><input class="form-control" id="sa" name="saldo" value="<?= $v('saldo') ?>" inputmode="decimal"><?= $err('saldo') ?></div>
  <div class="col-12"><label class="form-label" for="zr">Podstawa stanu początkowego *</label><input class="form-control" id="zr" name="zrodlo" value="<?= $v('zrodlo') ?>" placeholder="np. zestawienie z poprzedniego systemu z dnia …, umowa nr …"><?= $err('zrodlo') ?></div>
</div></section>
<?php elseif ($kind === SettleKind::Prz): $ms = array_values((array)($old['m'] ?? [])); while (count($ms) < 4) $ms[] = ['ym' => '', 'h' => '', 'rate' => '']; ?>
<section class="panel"><h2 class="mt-0">Godziny per miesiąc</h2><?= $err('m') ?>
  <p class="small text-secondary">Faktyczne godziny × stawka = kwota rozliczenia miesiąca (zastąpi wyliczenie kalkulatora jako kwota ręczna). Miesięcy z wystawioną fakturą nie zmienimy.</p>
  <table class="table table-sm"><thead><tr><th>Miesiąc (RRRR-MM)</th><th>Faktyczne godziny</th><th>Stawka (zł/h)</th></tr></thead><tbody>
  <?php foreach ($ms as $i => $m): ?><tr><td><label class="visually-hidden" for="ym<?= $i ?>">Miesiąc</label><input class="form-control form-control-sm" type="month" id="ym<?= $i ?>" name="m[<?= $i ?>][ym]" value="<?= h($m['ym']) ?>"></td>
    <td><label class="visually-hidden" for="hh<?= $i ?>">Godziny</label><input class="form-control form-control-sm" id="hh<?= $i ?>" name="m[<?= $i ?>][h]" value="<?= h($m['h']) ?>" inputmode="decimal"></td>
    <td><label class="visually-hidden" for="rt<?= $i ?>">Stawka</label><input class="form-control form-control-sm" id="rt<?= $i ?>" name="m[<?= $i ?>][rate]" value="<?= h($m['rate'] !== '' ? $m['rate'] : (string)($courses[0]['hourly_rate'] ?? '')) ?>" inputmode="decimal"></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>
<section class="panel"><label class="form-label" for="uz">Uzasadnienie<?= in_array($kind, [SettleKind::Prz, SettleKind::Rmr, SettleKind::Rrr], true) ? ' *' : '' ?></label><textarea class="form-control" id="uz" name="uzasadnienie" rows="2"><?= $v('uzasadnienie') ?></textarea><?= $err('uzasadnienie') ?></section>
<section class="panel warn"><h2 class="mt-0">Obieg</h2>
  <div class="form-check"><input class="form-check-input" type="radio" name="mode" id="m1" value="approval"<?= ($old['mode'] ?? 'approval') !== 'direct' ? ' checked' : '' ?>><label class="form-check-label" for="m1"><b>Z akceptacją kierownika</b> — po zatwierdzeniu wykonanie i PDF e-mailem do kursanta i rodzica</label></div>
  <div class="form-check"><input class="form-check-input" type="radio" name="mode" id="m2" value="direct"<?= ($old['mode'] ?? '') === 'direct' ? ' checked' : '' ?>><label class="form-check-label" for="m2"><b>Bez akceptacji, tylko do akt</b> — wykonanie od razu, Twój podpis, dokument w teczce kursanta (bez wysyłki)</label></div>
</section>
<div class="d-flex gap-2 mb-4"><button class="btn btn-metro">Wystaw <?= h($kind->value) ?></button><a class="btn btn-outline-secondary" href="<?= h(route('settle.index')) ?>">Anuluj</a></div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
