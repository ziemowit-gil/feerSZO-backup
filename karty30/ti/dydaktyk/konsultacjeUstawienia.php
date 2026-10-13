<?php
/**
 * karty30/ti/dydaktyk/konsultacjeUstawienia.php — Ustawienia konsultacji (kierownik): link do Polityki Prywatności
 * i regulaminu, treść zgody RODO i akceptacji regulaminu ({polityka}, {regulamin} → linki), słowniki danych zgłoszenia
 * na karcie konsultacji (zmiana nazw, dodawanie, wyłączanie — wyłączone zostają opisane na starszych kartach).
 */
require __DIR__ . '/_rezerwacje_nav.php';
if (!$staff) { flash_set('warning', 'Ustawienia konsultacji zmienia kierownik.'); header('Location: rezerwacje.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'consents') {
        $err = [];
        foreach (['ti_consult_privacy_url' => 'privacy_url', 'ti_consult_terms_url' => 'terms_url'] as $key => $f) {
            $u = trim((string)($_POST[$f] ?? ''));
            if ($u !== '' && !preg_match('#^https?://\S+$#i', $u)) { $err[] = 'Adres musi zaczynać się od http:// lub https://.'; continue; }
            org_setting_set($key, $u);
        }
        org_setting_set('ti_consult_rodo_text', mb_substr(trim((string)($_POST['rodo_text'] ?? '')), 0, 1000));
        org_setting_set('ti_consult_terms_text', mb_substr(trim((string)($_POST['terms_text'] ?? '')), 0, 1000));
        flash_set($err ? 'warning' : 'success', $err ? implode(' ', array_unique($err)) : 'Zapisano linki i treść zgód. Nowe karty zapiszą przy zgodzie aktualny tekst.');
    } elseif ($op === 'dicts') {
        TiConsultations::saveDicts((array)($_POST['d'] ?? []));
        flash_set('success', 'Zapisano słowniki zgłoszenia.');
    } elseif ($op === 'dicts_reset') {
        org_setting_set('ti_consult_dicts', '');
        flash_set('success', 'Przywrócono domyślne słowniki zgłoszenia.');
    }
    header('Location: konsultacjeUstawienia.php'); exit;
}
$all = TiConsultations::dicts(); $act = TiConsultations::dicts(true);
$RZ_TITLE = 'Ustawienia konsultacji';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1000px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-gear text-primary me-2" aria-hidden="true"></i>Ustawienia konsultacji</h1>
<?php rz_nav('ustawienia'); ?>
<?= flash_html() ?>

<form method="post" class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Zgody i linki</h2>
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="consents">
  <div class="row g-2 mb-2">
    <div class="col-md-6"><label class="form-label small mb-0" for="us-pp">Link do Polityki Prywatności</label><input class="form-control form-control-sm" type="url" id="us-pp" name="privacy_url" placeholder="https://…" value="<?= h(org_setting('ti_consult_privacy_url')) ?>"></div>
    <div class="col-md-6"><label class="form-label small mb-0" for="us-tu">Link do regulaminu konsultacji</label><input class="form-control form-control-sm" type="url" id="us-tu" name="terms_url" placeholder="https://…" value="<?= h(org_setting('ti_consult_terms_url')) ?>"></div>
  </div>
  <label class="form-label small mb-0" for="us-rodo">Treść zgody RODO</label>
  <textarea class="form-control form-control-sm mb-1" id="us-rodo" name="rodo_text" rows="2" placeholder="<?= h(TiConsultations::RODO_DEFAULT) ?>"><?= h(org_setting('ti_consult_rodo_text')) ?></textarea>
  <label class="form-label small mb-0" for="us-terms">Treść akceptacji regulaminu</label>
  <textarea class="form-control form-control-sm mb-1" id="us-terms" name="terms_text" rows="2" placeholder="<?= h(TiConsultations::TERMS_DEFAULT) ?>"><?= h(org_setting('ti_consult_terms_text')) ?></textarea>
  <div class="form-text mb-2">Puste pole = tekst domyślny. <code>{polityka}</code> i <code>{regulamin}</code> zamieniają się na linki powyżej. Przy każdej zgodzie karta zapisuje tekst obowiązujący w chwili wyrażenia zgody.</div>
  <p class="small mb-2"><strong>Podgląd:</strong> <?= TiConsultations::consentHtml('rodo') ?> · <?= TiConsultations::consentHtml('terms') ?></p>
  <button class="btn btn-sm btn-primary">Zapisz</button>
</div></form>

<form method="post" class="card border-0 shadow-sm mb-4"><div class="card-body">
  <h2 class="h6 fw-bold">Słowniki zgłoszenia na karcie konsultacji</h2>
  <p class="small text-body-secondary">Zmień nazwę, odznacz „aktywna”, aby ukryć pozycję w formularzu (na starszych kartach i wydrukach zostaje opisana), dopisz nowe pozycje w pustych wierszach.</p>
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="dicts">
  <div class="row g-3">
  <?php foreach (TiConsultations::DICT_NAMES as $d => $dn): $i = 0; ?>
    <fieldset class="col-md-6"><legend class="small fw-bold mb-1"><?= h($dn) ?></legend>
      <?php foreach ($all[$d] as $k => $l): ?>
      <div class="input-group input-group-sm mb-1">
        <div class="input-group-text"><input class="form-check-input mt-0" type="checkbox" name="d[<?= h($d) ?>][<?= $i ?>][on]" value="1" aria-label="Aktywna: <?= h($l) ?>"<?= isset($act[$d][$k]) ? ' checked' : '' ?>></div>
        <input type="hidden" name="d[<?= h($d) ?>][<?= $i ?>][key]" value="<?= h($k) ?>">
        <input class="form-control" name="d[<?= h($d) ?>][<?= $i ?>][label]" value="<?= h($l) ?>" aria-label="Nazwa pozycji (<?= h($dn) ?>)">
      </div>
      <?php $i++; endforeach; ?>
      <?php for ($n = 0; $n < 2; $n++, $i++): ?>
      <div class="input-group input-group-sm mb-1">
        <div class="input-group-text"><input class="form-check-input mt-0" type="checkbox" name="d[<?= h($d) ?>][<?= $i ?>][on]" value="1" checked aria-label="Aktywna — nowa pozycja"></div>
        <input class="form-control" name="d[<?= h($d) ?>][<?= $i ?>][label]" placeholder="nowa pozycja…" aria-label="Nowa pozycja (<?= h($dn) ?>)">
      </div>
      <?php endfor; ?>
    </fieldset>
  <?php endforeach; ?>
  </div>
  <div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-primary">Zapisz słowniki</button></div>
</div></form>
<form method="post" onsubmit="return confirm('Przywrócić domyślne słowniki? Twoje zmiany nazw i nowe pozycje zostaną usunięte (starsze karty pokażą domyślne nazwy).')">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="dicts_reset">
  <button class="btn btn-sm btn-link text-danger px-0">Przywróć domyślne słowniki</button>
</form>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
