<?php
/**
 * karty30/ti/dydaktyk/umowaSzkolenia.php — TESTOWE generowanie „Umowy o przeprowadzenie szkolenia / zajęć dodatkowych”
 * (TI/UMSZ/XXX/RRRR) do DOCX. Formularz → plik do pobrania; nic nie jest zapisywane w bazie. Dostęp: kierownik (dyd_is_staff).
 * Logika i treść wzoru: modules/ti_training_contract.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_training_contract/logic/trainingContract.php';
$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }

$d = isset($_GET['przyklad']) ? TiTrainingContract::sample() : TiTrainingContract::defaults();
$err = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    [$d, $err] = TiTrainingContract::normalize($_POST);
    if (!$err) {
        $bytes = TiTrainingContract::docx($d);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . TiTrainingContract::filename($d) . '"');
        header('Cache-Control: no-store, private');
        echo $bytes;
        exit;
    }
}

$KP_TITLE = 'Umowa szkoleniowa (test) — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = 'umowaSzkolenia.php'; $KIER_LABEL = 'Umowa szkoleniowa (test)'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:860px">
<h1 class="h4 fw-bold mb-1"><i class="bi bi-file-earmark-word text-primary me-2" aria-hidden="true"></i>Umowa o przeprowadzenie szkolenia — generowanie testowe</h1>
<p class="small text-body-secondary">Wypełnij dane i pobierz DOCX. Nic nie zapisujemy w bazie, numer „XXX” nie jest nadawany.
  Puste pola zostaną na wydruku jako linie do ręcznego uzupełnienia.
  <a href="umowaSzkolenia.php?przyklad=1">Wstaw dane przykładowe</a></p>
<?php if ($err): ?><div class="alert alert-danger" role="alert"><?php foreach ($err as $e): ?><div><?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm"><div class="card-body row g-3">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <div class="col-md-6"><label class="form-label" for="u-nr">Numer umowy</label>
    <input class="form-control" id="u-nr" name="nr" value="<?= h($d['nr']) ?>"></div>
  <div class="col-md-6"><label class="form-label" for="u-data">Data zawarcia</label>
    <input class="form-control" id="u-data" name="data" type="date" value="<?= h($d['data']) ?>"></div>
  <fieldset class="col-12"><legend class="form-label fs-6 mb-1">Kursant</legend>
    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="plec" id="u-k" value="K" <?= $d['plec'] === 'K' ? 'checked' : '' ?>><label class="form-check-label" for="u-k">Pani</label></div>
    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="plec" id="u-m" value="M" <?= $d['plec'] === 'M' ? 'checked' : '' ?>><label class="form-check-label" for="u-m">Pan</label></div>
  </fieldset>
  <div class="col-md-6"><label class="form-label" for="u-name">Imię i nazwisko</label>
    <input class="form-control" id="u-name" name="imie_nazwisko" value="<?= h($d['imie_nazwisko']) ?>" autocomplete="off"></div>
  <div class="col-md-6"><label class="form-label" for="u-pesel">PESEL</label>
    <input class="form-control" id="u-pesel" name="pesel" inputmode="numeric" maxlength="11" value="<?= h($d['pesel']) ?>" autocomplete="off"></div>
  <div class="col-12"><label class="form-label" for="u-adres">Adres zamieszkania</label>
    <input class="form-control" id="u-adres" name="adres" value="<?= h($d['adres']) ?>" autocomplete="off"></div>
  <div class="col-12"><label class="form-label" for="u-temat">Tematyka szkolenia / kursu</label>
    <input class="form-control" id="u-temat" name="temat" value="<?= h($d['temat']) ?>"></div>
  <fieldset class="col-12"><legend class="form-label fs-6 mb-1">Finansowanie</legend>
    <?php foreach (TiTrainingContract::FUNDING as $k => $lbl): ?>
    <div class="form-check"><input class="form-check-input" type="radio" name="finansowanie" id="u-f-<?= h($k) ?>" value="<?= h($k) ?>" <?= $d['finansowanie'] === $k ? 'checked' : '' ?>>
      <label class="form-check-label" for="u-f-<?= h($k) ?>"><?= h($lbl) ?></label></div>
    <?php endforeach; ?>
  </fieldset>
  <div class="col-12"><label class="form-label" for="u-zrodlo">Źródło finansowania publicznego (gdy publiczne)</label>
    <input class="form-control" id="u-zrodlo" name="zrodlo_publ" value="<?= h($d['zrodlo_publ']) ?>"></div>
  <div class="col-12"><button class="btn btn-primary"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz umowę (DOCX)</button></div>
</div></form>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
