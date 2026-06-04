<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();
require_role('admin', 'editor');

$id  = (int)($_GET['id'] ?? 0);
$row = $id ? db_one("SELECT * FROM rodo_authorizations WHERE id=?", [$id]) : null;
if (!$row) { http_response_code(404); die('Brak danych.'); }

$scope    = json_decode($row['scope_items'] ?? '[]', true) ?: [];
$org_city = org_setting('org_miejscowosc') ?: 'Miejscowość';

$signed_date = $row['signed_at'] ? date('d.m.Y', strtotime($row['signed_at'])) : '_______________';
$from_date   = $row['authorized_from'] ? date('d.m.Y', strtotime($row['authorized_from'])) : '_______________';
$contract_date_fmt = $row['contract_date'] ? date('d.m.Y', strtotime($row['contract_date'])) : '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Upoważnienie RODO — <?= h($row['number']) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Times New Roman', Times, serif;
  font-size: 12pt;
  color: #000;
  background: #fff;
  max-width: 210mm;
  margin: 0 auto;
  padding: 20mm 20mm 20mm 25mm;
}

/* Nagłówek dokumentu */
.doc-header { text-align: center; margin-bottom: 1.5em; }
.doc-title  { font-size: 14pt; font-weight: bold; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .4em; }
.doc-number { font-size: 10pt; color: #555; }

/* Sekcja danych */
.parties { margin-bottom: 1.2em; }
.party-block { margin-bottom: .8em; }
.party-head { font-weight: bold; font-size: 11pt; margin-bottom: .3em; }
.party-row  { margin-left: 1em; line-height: 1.6; }

/* Paragrafy */
.paragraph { margin-bottom: 1.2em; }
.para-title { font-weight: bold; text-align: center; margin-bottom: .5em; }
.para-body  { line-height: 1.8; text-align: justify; }
.para-list  { margin: .5em 0 .5em 2em; }
.para-list li { margin-bottom: .35em; line-height: 1.7; }

/* Podpisy */
.signatures {
  display: flex;
  justify-content: space-between;
  gap: 2em;
  margin-top: 2.5em;
  flex-wrap: wrap;
}
.sig-block { flex: 1; min-width: 200px; }
.sig-city  { margin-bottom: .4em; }
.sig-line  { border-bottom: 1px solid #000; margin-bottom: .4em; height: 3em; }
.sig-desc  { font-size: 10pt; text-align: center; color: #444; }

/* Oświadczenie */
.statement { margin-top: 2.5em; padding-top: 1.5em; border-top: 1px dashed #ccc; }
.statement-title { font-weight: bold; margin-bottom: .6em; }

/* Stopka */
.doc-footer { margin-top: 3em; padding-top: 1em; border-top: 1px solid #ddd; font-size: 9pt; color: #777; display: flex; justify-content: space-between; }

/* Print */
@media print {
  body { padding: 15mm 15mm 15mm 20mm; }
  .no-print { display: none !important; }
  @page { size: A4; margin: 0; }
}
</style>
</head>
<body>

<!-- Przycisk druku -->
<div class="no-print" style="text-align:center;margin-bottom:1.5em;padding:1em;background:#f0f4f8;border-radius:8px">
  <button onclick="window.print()" style="background:#1e40af;color:#fff;border:none;padding:.6em 2em;border-radius:6px;font-size:1em;cursor:pointer;margin-right:.5em">
    🖨 Drukuj / Zapisz jako PDF
  </button>
  <a href="view.php?id=<?= $id ?>" style="color:#1e40af;font-size:.9em">← Wróć do zarządzania</a>
</div>

<!-- Nagłówek -->
<div class="doc-header">
  <div class="doc-title">Upoważnienie do przetwarzania danych osobowych</div>
  <div class="doc-number">Nr <?= h($row['number']) ?></div>
</div>

<!-- Strony -->
<div class="parties">
  <div class="party-block">
    <div class="party-head">Administrator danych:</div>
    <div class="party-row">
      <?= h($row['org_name']) ?><br>
      z siedzibą w <?= h($row['org_address']) ?><br>
      NIP: <?= h($row['org_nip']) ?>
    </div>
  </div>
  <div class="party-block">
    <div class="party-head">Osoba upoważniana:</div>
    <div class="party-row">
      Imię i nazwisko: <strong><?= h($row['person_name']) ?></strong><br>
      <?php if ($row['person_pesel']): ?>
      PESEL: <?= h($row['person_pesel']) ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- § 1 -->
<div class="paragraph">
  <div class="para-title">§ 1</div>
  <div class="para-body">
    Z dniem <strong><?= $from_date ?></strong> upoważniam Pana/Panią
    <strong><?= h($row['person_name']) ?></strong>
    do przetwarzania danych osobowych w zbiorach prowadzonych przez
    <?= h($row['org_name']) ?>
    w zakresie niezbędnym do realizacji zadań wolontariackich wynikających z
    porozumienia o wolontariacie
    <?php if ($row['contract_number']): ?>
    nr <strong><?= h($row['contract_number']) ?></strong>
    <?php endif; ?>
    <?php if ($contract_date_fmt): ?>
    z dnia <?= $contract_date_fmt ?>
    <?php endif; ?>.
  </div>
</div>

<!-- § 2 -->
<div class="paragraph">
  <div class="para-title">§ 2</div>
  <div class="para-body">
    Zakres upoważnienia obejmuje przetwarzanie danych osobowych w systemach informatycznych
    oraz w formie papierowej, w następującym celu:
  </div>
  <ul class="para-list">
    <?php foreach ($scope as $k): ?>
    <li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li>
    <?php endforeach; ?>
    <?php if ($row['scope_custom']): ?>
    <li><?= h($row['scope_custom']) ?></li>
    <?php endif; ?>
  </ul>
</div>

<!-- § 3 -->
<div class="paragraph">
  <div class="para-title">§ 3</div>
  <div class="para-body">Osoba upoważniona jest zobowiązana do:</div>
  <ol class="para-list">
    <li>
      Przetwarzania danych osobowych wyłącznie w zakresie i celu określonym
      w niniejszym upoważnieniu.
    </li>
    <li>
      Zachowania w pełnej poufności przetwarzanych danych osobowych oraz sposobów
      ich zabezpieczenia, również po ustaniu stosunku wolontariatu.
    </li>
    <li>
      Stosowania się do przepisów Rozporządzenia Parlamentu Europejskiego i Rady
      (UE) 2016/679 (RODO) oraz wewnętrznej polityki bezpieczeństwa obowiązującej
      w <?= h($row['org_name']) ?>.
    </li>
    <li>
      Niezwłocznego zgłaszania Administratorowi danych każdego przypadku naruszenia
      ochrony danych osobowych lub podejrzenia takiego naruszenia.
    </li>
  </ol>
</div>

<!-- § 4 -->
<div class="paragraph">
  <div class="para-title">§ 4</div>
  <div class="para-body">
    Upoważnienie wygasa z chwilą rozwiązania lub wygaśnięcia porozumienia o wolontariacie
    <?php if ($row['authorized_until']): ?>
    lub z dniem <?= date('d.m.Y', strtotime($row['authorized_until'])) ?>
    <?php endif; ?>
    lub w przypadku cofnięcia upoważnienia przez Administratora.
  </div>
</div>

<!-- Podpisy -->
<div style="margin-top:2em;text-align:right;font-size:11pt">
  <?= h($org_city) ?>, dnia <?= $signed_date ?>
</div>

<div class="signatures">
  <div class="sig-block">
    <div class="sig-line"></div>
    <div class="sig-desc">
      (Podpis Administratora / osoby reprezentującej organizację)
      <?php if ($row['signed_by_name']): ?><br><?= h($row['signed_by_name']) ?><?php endif; ?>
    </div>
  </div>
</div>

<!-- Oświadczenie wolontariusza -->
<div class="statement">
  <div class="statement-title">Oświadczenie wolontariusza:</div>
  <div class="para-body" style="line-height:1.8">
    Oświadczam, że zapoznałem/am się z przepisami dotyczącymi ochrony danych osobowych
    oraz wewnętrznymi procedurami bezpieczeństwa obowiązującymi
    w <?= h($row['org_name']) ?>.
    Zobowiązuję się do ich przestrzegania oraz zachowania w tajemnicy wszelkich danych osobowych,
    do których będę miał/am dostęp.
  </div>

  <div style="margin-top:1.5em;text-align:right">
    <?= h($org_city) ?>, dnia
    <?= $row['vol_signed_at'] ? date('d.m.Y', strtotime($row['vol_signed_at'])) : '_______________' ?>
  </div>

  <div class="signatures" style="margin-top:1em">
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-desc">(Podpis wolontariusza — <?= h($row['person_name']) ?>)</div>
    </div>
  </div>
</div>

<!-- Stopka dokumentu -->
<div class="doc-footer">
  <span>Rejestr upoważnień RODO · <?= h($row['org_name']) ?></span>
  <span><?= h($row['number']) ?> · Wygenerowano <?= date('d.m.Y') ?></span>
</div>

</body>
</html>
