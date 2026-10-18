<?php
/**
 * modules/ti_dop/view/dop5_form.php — nowe DOP5: dane podmiotu (z ustawień), umowa / projekt, kwota, dzień, rachunek, załączniki,
 * oświadczenia, osoby podpisujące (podpowiedzi: reprezentacja KRS, pełnomocnictwa), tryb podpisu. Zmienne: $errors, $old, $suggest, $dop2, $orgAccounts.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'Nowe DOP5 — oświadczenie o stanie środków';
include __DIR__ . '/_head.php';
$v = fn(string $k) => h((string)($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . h($errors[$k]) . '</div>' : '';
$signers = array_values((array)($old['signers'] ?? []));
if (!$signers) foreach (array_slice($suggest, 0, 2) as $sg) $signers[] = $sg;
while (count($signers) < 3) $signers[] = ['name' => '', 'function_title' => '', 'basis' => '', 'email' => '', 'role' => count($signers) === 2 ? 'ksiegowy' : 'reprezentant'];
?>
<div class="pivot"><a href="<?= h(route('dop.index')) ?>">Dokumenty rozliczeniowe</a><a href="<?= h(route('dop5.index')) ?>">DOP5</a><a href="#" aria-current="page">Nowe</a></div>
<h1><span class="sym" style="background:#1d1d1d">DOP5</span> Oświadczenie o stanie środków finansowych</h1>
<?php if ($errors): ?><div class="alert alert-danger" role="alert"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>
<form method="post" action="<?= h(route('dop5.new')) ?>" enctype="multipart/form-data" novalidate><?= Router::csrfField() ?>
<section class="panel accent"><h2 class="mt-0">1. Nagłówek i dane formalne</h2><div class="row g-3">
  <div class="col-md-4"><label class="form-label" for="pl">Miejscowość *</label><input class="form-control" id="pl" name="place" value="<?= $v('place') ?>"><?= $err('place') ?></div>
  <div class="col-md-8"><label class="form-label" for="on">Nazwa podmiotu</label><input class="form-control" id="on" name="org_name" value="<?= $v('org_name') ?>"></div>
  <div class="col-md-12"><label class="form-label" for="oa">Adres siedziby</label><input class="form-control" id="oa" name="org_address" value="<?= $v('org_address') ?>"></div>
  <div class="col-md-4"><label class="form-label" for="nip">NIP</label><input class="form-control" id="nip" name="org_nip" value="<?= $v('org_nip') ?>"></div>
  <div class="col-md-4"><label class="form-label" for="reg">REGON</label><input class="form-control" id="reg" name="org_regon" value="<?= $v('org_regon') ?>"></div>
  <div class="col-md-4"><label class="form-label" for="krs">KRS</label><input class="form-control" id="krs" name="org_krs" value="<?= $v('org_krs') ?>"></div>
  <div class="col-md-4"><label class="form-label" for="um">Numer umowy o dofinansowanie / realizację *</label><input class="form-control" id="um" name="umowa_nr" value="<?= $v('umowa_nr') ?>"><?= $err('umowa_nr') ?></div>
  <div class="col-md-8"><label class="form-label" for="ty">Tytuł projektu / zadania publicznego *</label><input class="form-control" id="ty" name="tytul" value="<?= $v('tytul') ?>"><?= $err('tytul') ?></div>
  <div class="col-md-4"><label class="form-label" for="d2">Transza / wniosek DOP2 (opcjonalnie)</label><input class="form-control" id="d2" name="dop2" value="<?= $v('dop2') ?>" list="d2l"><datalist id="d2l"><?php foreach ($dop2 as $x): ?><option value="<?= h($x) ?>"><?php endforeach; ?></datalist></div>
</div></section>
<section class="panel"><h2 class="mt-0">2. Stan środków</h2><div class="row g-3">
  <div class="col-md-4"><label class="form-label" for="kw">Kwota (PLN) *</label><input class="form-control" id="kw" name="kwota" value="<?= $v('kwota') ?>" inputmode="decimal"><?= $err('kwota') ?></div>
  <div class="col-md-4"><label class="form-label" for="ao">Stan na dzień *</label><input class="form-control" type="date" id="ao" name="as_of" value="<?= $v('as_of') ?>"><?= $err('as_of') ?></div>
  <div class="col-md-8"><label class="form-label" for="rb">Wyodrębniony rachunek / subkonto *</label><input class="form-control" id="rb" name="rachunek" value="<?= $v('rachunek') ?>" list="rbl" inputmode="numeric" placeholder="PL __ ____ ____ ____ ____ ____ ____">
    <datalist id="rbl"><?php foreach ($orgAccounts as $oa): ?><option value="<?= h($oa['nrb']) ?>"><?= h($oa['label']) ?></option><?php endforeach; ?></datalist><?= $err('rachunek') ?></div>
  <div class="col-md-4"><label class="form-label" for="ro">Opis rachunku</label><input class="form-control" id="ro" name="rachunek_opis" value="<?= $v('rachunek_opis') ?>" placeholder="np. rachunek projektu"></div>
</div></section>
<section class="panel"><h2 class="mt-0">3. Załączniki</h2><div class="row g-3">
  <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="zw" name="zal_wyciag" value="1"<?= !empty($old['zal_wyciag']) ? ' checked' : '' ?>><label class="form-check-label" for="zw">Wyciąg / historia rachunku na dzień</label></div>
    <input class="form-control form-control-sm mt-1" type="file" name="plik_wyciag" accept=".pdf,.jpg,.jpeg,.png" aria-label="Plik wyciągu"></div>
  <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="zz" name="zal_zaswiadczenie" value="1"<?= !empty($old['zal_zaswiadczenie']) ? ' checked' : '' ?>><label class="form-check-label" for="zz">Zaświadczenie z banku o saldzie</label></div>
    <input class="form-control form-control-sm mt-1" type="file" name="plik_zaswiadczenie" accept=".pdf,.jpg,.jpeg,.png" aria-label="Plik zaświadczenia"></div>
</div></section>
<section class="panel"><h2 class="mt-0">4. Oświadczenia prawne</h2>
  <?php foreach (Dop5Statement::STATEMENTS as $k => $t): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="o<?= $k ?>" name="osw_<?= $k ?>" value="1"<?= !empty($old['osw_' . $k]) ? ' checked' : '' ?>><label class="form-check-label small" for="o<?= $k ?>"><?= h($t) ?></label><?= $err('osw_' . $k) ?></div><?php endforeach; ?>
</section>
<section class="panel warn"><h2 class="mt-0">5. Osoby podpisujące</h2><?= $err('signers') ?>
  <?php if ($suggest): ?><p class="small text-secondary">Podpowiedzi: <?= h(implode('; ', array_map(fn($s) => $s['name'] . ' — ' . $s['basis'], $suggest))) ?></p><?php endif; ?>
  <table class="table table-sm"><thead><tr><th>Imię i nazwisko</th><th>Funkcja</th><th>Podstawa (KRS / pełnomocnictwo)</th><th>E-mail (podpis przez link)</th><th>Rola</th></tr></thead><tbody>
  <?php foreach ($signers as $i => $sg): ?><tr>
    <td><input class="form-control form-control-sm" name="signers[<?= $i ?>][name]" value="<?= h($sg['name']) ?>" aria-label="Imię i nazwisko <?= $i + 1 ?>"></td>
    <td><input class="form-control form-control-sm" name="signers[<?= $i ?>][function_title]" value="<?= h($sg['function_title']) ?>" aria-label="Funkcja <?= $i + 1 ?>"></td>
    <td><input class="form-control form-control-sm" name="signers[<?= $i ?>][basis]" value="<?= h($sg['basis']) ?>" aria-label="Podstawa <?= $i + 1 ?>"></td>
    <td><input class="form-control form-control-sm" type="email" name="signers[<?= $i ?>][email]" value="<?= h($sg['email']) ?>" aria-label="E-mail <?= $i + 1 ?>"></td>
    <td><select class="form-select form-select-sm" name="signers[<?= $i ?>][role]" aria-label="Rola <?= $i + 1 ?>"><option value="reprezentant"<?= ($sg['role'] ?? '') !== 'ksiegowy' ? ' selected' : '' ?>>reprezentacja</option><option value="ksiegowy"<?= ($sg['role'] ?? '') === 'ksiegowy' ? ' selected' : '' ?>>główny księgowy / skarbnik</option></select></td></tr><?php endforeach; ?>
  </tbody></table>
  <div class="form-check"><input class="form-check-input" type="radio" name="sign_mode" id="sm1" value="link"<?= ($old['sign_mode'] ?? 'link') !== 'paper' ? ' checked' : '' ?>><label class="form-check-label" for="sm1">Podpis elektroniczny — każda osoba dostaje link e-mailem</label></div>
  <div class="form-check"><input class="form-check-input" type="radio" name="sign_mode" id="sm2" value="paper"<?= ($old['sign_mode'] ?? '') === 'paper' ? ' checked' : '' ?>><label class="form-check-label" for="sm2">Wersja papierowa — podpisy odręczne, wgrasz skan</label></div>
</section>
<div class="d-flex gap-2 mb-4"><button class="btn btn-metro">Utwórz DOP5</button><a class="btn btn-outline-secondary" href="<?= h(route('dop5.index')) ?>">Anuluj</a></div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>
