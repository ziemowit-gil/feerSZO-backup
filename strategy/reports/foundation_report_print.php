<?php
/**
 * strategy/reports/foundation_report_print.php
 * Podgląd do druku / generowania PDF sprawozdania fundacji.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/foundation_report.php';

require_login();

$rok    = (int)($_GET['rok'] ?? date('Y') - 1);
$report = db_one("SELECT * FROM foundation_reports WHERE rok=?", [$rok]);
if (!$report) { http_response_code(404); die("Brak sprawozdania za rok {$rok}."); }

$org  = freport_org_data();
$iii  = json_decode($report['iii_json'] ?? '{}', true) ?: [];
$iv   = json_decode($report['iv_json']  ?? '{}', true) ?: [];
$v    = json_decode($report['v_json']   ?? '{}', true) ?: [];
$vii  = json_decode($report['vii_json'] ?? '{}', true) ?: [];

function _p(array $data, string $key): string {
    $v = $data[$key] ?? '';
    return h($v ?: '—');
}
function _k(array $data, string $key): string {
    $v = (float)str_replace([',',' '], ['.',''], $data[$key] ?? '0');
    return $v > 0 ? h(number_format($v, 2, ',', ' ')) . ' PLN' : '—';
}
function _yn(int $v): string { return $v ? 'TAK' : 'NIE'; }
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Sprawozdanie z działalności fundacji — <?= h($org['nazwa']) ?> — <?= $rok ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; }
@page { size: A4; margin: 18mm 15mm 18mm 20mm; }

body {
  font-family: Arial, Helvetica, sans-serif;
  font-size: 9.5pt;
  color: #000;
  background: #fff;
  margin: 0; padding: 0;
  line-height: 1.4;
}

.page-header {
  text-align: center;
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 2px solid #000;
}
.page-header h1 {
  font-size: 13pt;
  font-weight: bold;
  margin: 0 0 4px;
  text-transform: uppercase;
}
.page-header p { font-size: 8pt; margin: 2px 0; color: #333; }

.section {
  margin-bottom: 10px;
  page-break-inside: avoid;
}
.section-title {
  background: #000;
  color: #fff;
  font-weight: bold;
  font-size: 9pt;
  padding: 3px 6px;
  text-transform: uppercase;
  letter-spacing: .03em;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 8.5pt;
  margin: 2px 0;
}
th, td {
  border: 1px solid #555;
  padding: 3px 5px;
  vertical-align: top;
}
th {
  background: #e8e8e8;
  font-weight: bold;
  text-align: center;
  font-size: 8pt;
}
.lbl {
  font-weight: bold;
  background: #f5f5f5;
  width: 35%;
}
.val {
  white-space: pre-wrap;
}
.num {
  text-align: right;
  font-family: 'Courier New', monospace;
  font-size: 9pt;
}
.auto-note {
  font-size: 7pt;
  color: #555;
  font-style: italic;
}
.yn-box {
  display: inline-block;
  border: 1px solid #000;
  width: 14px; height: 14px;
  text-align: center;
  font-size: 9pt;
  line-height: 14px;
  font-weight: bold;
  margin-right: 4px;
}
.footnote {
  font-size: 7.5pt;
  color: #555;
  margin-top: 3px;
  border-top: 1px solid #ccc;
  padding-top: 3px;
}

.signature-block {
  margin-top: 20mm;
  display: flex;
  justify-content: space-between;
}
.sig-line {
  width: 45%;
  border-top: 1px solid #000;
  text-align: center;
  font-size: 8pt;
  padding-top: 4px;
}

@media screen {
  body { max-width: 210mm; margin: 10mm auto; padding: 10mm; box-shadow: 0 0 20px rgba(0,0,0,.15); }
  .print-only { display: block; }
  .no-print-btn {
    position: fixed; top: 10px; right: 10px; z-index: 9999;
    display: flex; gap: 8px;
  }
  .no-print-btn button, .no-print-btn a {
    background: #7c3aed; color: #fff; border: none;
    padding: 8px 16px; border-radius: 6px; cursor: pointer;
    font-size: 13px; text-decoration: none;
  }
}
@media print {
  .no-print-btn { display: none; }
}
</style>
</head>
<body>

<div class="no-print-btn">
  <button onclick="window.print()">🖨️ Drukuj / PDF</button>
  <a href="foundation_report.php?rok=<?= $rok ?>">← Edytuj</a>
</div>

<!-- Nagłówek -->
<div class="page-header">
  <p style="font-size:8pt;color:#555">Załącznik do rozporządzenia Ministra Sprawiedliwości z dnia 20 grudnia 2022 r. (Dz. U. poz. 2791)</p>
  <h1>Sprawozdanie z działalności fundacji</h1>
  <p><strong>Rok sprawozdawczy: <?= $rok ?></strong></p>
  <p style="font-size:7.5pt">
    Podstawa prawna: Ustawa z dnia 6 kwietnia 1984 r. o fundacjach (Dz.U. 2020 r. poz. 2167 oraz z 2022 r. poz. 2185)
  </p>
</div>

<!-- Organ nadzoru -->
<div class="section">
  <table>
    <tr>
      <td class="lbl">Nazwa organu sprawującego nadzór:</td>
      <td class="val"><?= h($report['organ_nadzoru'] ?: '—') ?></td>
    </tr>
  </table>
</div>

<!-- I. Dane fundacji -->
<div class="section">
  <div class="section-title">I. Dane fundacji</div>
  <table>
    <tr>
      <td class="lbl">1. Nazwa fundacji</td>
      <td class="val"><strong><?= h($org['nazwa']) ?></strong></td>
    </tr>
    <tr>
      <td class="lbl">2. Adres siedziby</td>
      <td class="val"><?= h($org['adres']) ?>, <?= h($org['miejscowosc']) ?></td>
    </tr>
    <tr>
      <td class="lbl">Adres poczty elektronicznej</td>
      <td><?= h($org['email'] ?: '—') ?></td>
    </tr>
    <tr>
      <td class="lbl">Adres strony internetowej</td>
      <td><?= h($org['www'] ?: '—') ?></td>
    </tr>
    <tr>
      <td class="lbl">3. Nr REGON</td>
      <td><?= h($org['regon'] ?: '—') ?></td>
    </tr>
    <tr>
      <td class="lbl">5. Nr KRS</td>
      <td><?= h($org['krs'] ?: '—') ?></td>
    </tr>
    <tr>
      <td class="lbl">6. Dane członków zarządu</td>
      <td class="val"><?= h($org['zarzad'] ?: '—') ?></td>
    </tr>
    <tr>
      <td class="lbl">7. NIP fundacji</td>
      <td><?= h($org['nip'] ?: '—') ?></td>
    </tr>
    <tr>
      <td class="lbl">8. Wszystkie cele statutowe</td>
      <td class="val"><?= h($report['ii_zasady_formy'] ?: '—') ?></td>
    </tr>
  </table>
</div>

<!-- II. Charakterystyka działalności -->
<div class="section">
  <div class="section-title">II. Charakterystyka działalności fundacji w roku <?= $rok ?></div>
  <table>
    <tr>
      <td class="lbl" style="vertical-align:top">II.1 Zasady, formy i zakres<br>działalności statutowej</td>
      <td class="val" style="min-height:30mm"><?= h($report['ii_zasady_formy'] ?: '—') ?></td>
    </tr>
    <tr>
      <td class="lbl" style="vertical-align:top">II.2 Główne zdarzenia prawne<br>o skutkach finansowych</td>
      <td class="val" style="min-height:25mm"><?= h($report['ii_zdarzenia_prawne'] ?: '—') ?></td>
    </tr>
    <tr>
      <td class="lbl">II.3 Działalność gospodarcza</td>
      <td>
        <span class="yn-box"><?= ($report['ii_dzialalnosc_gosp']??0) ? 'X' : '' ?></span> TAK &nbsp;&nbsp;&nbsp;
        <span class="yn-box"><?= ($report['ii_dzialalnosc_gosp']??0) ? '' : 'X' ?></span> NIE
      </td>
    </tr>
    <?php if ($report['ii_pkd']): ?>
    <tr>
      <td class="lbl">II.4 Kody PKD</td>
      <td class="val"><?= h($report['ii_pkd']) ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td class="lbl">II.5 Uchwały zarządu/rady</td>
      <td class="val"><?= h($report['ii_uchwaly'] ?: '—') ?></td>
    </tr>
  </table>
</div>

<!-- III. Przychody -->
<div class="section">
  <div class="section-title">III. Informacja o wysokości uzyskanych przychodów</div>
  <table>
    <thead>
      <tr>
        <th style="width:45%;text-align:left">Pozycja</th>
        <th>Przelew (PLN)</th>
        <th>Gotówka (PLN)</th>
        <th>Inne (PLN)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>a. Działalność statutowa</td>
        <td class="num"><?= _k($iii,'statutowe_przelew') ?></td>
        <td class="num"><?= _k($iii,'statutowe_gotowka') ?></td>
        <td class="num"><?= _k($iii,'statutowe_inne') ?></td>
      </tr>
      <tr>
        <td>b. Działalność gospodarcza</td>
        <td class="num"><?= _k($iii,'gosp_przelew') ?></td>
        <td class="num"><?= _k($iii,'gosp_gotowka') ?></td>
        <td class="num">—</td>
      </tr>
      <tr>
        <td>c. Pozostałe przychody</td>
        <td class="num"><?= _k($iii,'pozostale_przelew') ?></td>
        <td class="num">—</td>
        <td class="num">—</td>
      </tr>
    </tbody>
  </table>
  <table style="margin-top:4px">
    <tr><td class="lbl">a. Działalność odpłatna</td><td class="num"><?= _k($iii,'odpłatna') ?></td></tr>
    <tr><td class="lbl">b. Środki publiczne ogółem</td><td class="num"><?= _k($iii,'srodki_publiczne') ?></td></tr>
    <tr><td class="lbl" style="padding-left:15px">— budżet państwa</td><td class="num"><?= _k($iii,'budzet_panstwa') ?></td></tr>
    <tr><td class="lbl" style="padding-left:15px">— budżet JST</td><td class="num"><?= _k($iii,'budzet_jst') ?></td></tr>
    <tr><td class="lbl">c. Spadki, zapisy</td><td class="num"><?= _k($iii,'spadki_zapisy') ?></td></tr>
    <tr><td class="lbl">d. Darowizny</td><td class="num"><?= _k($iii,'darowizny') ?></td></tr>
    <tr><td class="lbl">e. Inne źródła</td><td class="val"><?= _p($iii,'inne_zrodla') ?></td></tr>
    <?php if ($report['ii_dzialalnosc_gosp']??0): ?>
    <tr><td class="lbl">3a. Dochód z dz. gosp.</td><td class="num"><?= _k($iii,'dochod_gosp') ?></td></tr>
    <tr><td class="lbl">3b. Udział % dz. gosp.</td><td class="num"><?= _p($iii,'procent_gosp') ?>%</td></tr>
    <?php endif; ?>
  </table>
</div>

<!-- IV. Koszty -->
<div class="section">
  <div class="section-title">IV. Informacja o poniesionych kosztach</div>
  <table>
    <thead>
      <tr>
        <th style="text-align:left">Pozycja</th><th>Przelew (PLN)</th><th>Gotówka (PLN)</th><th>Inne</th>
      </tr>
    </thead>
    <tbody>
      <tr><td>1. Koszty realizacji celów statutowych</td><td class="num"><?= _k($iv,'cele_stat_przelew') ?></td><td class="num"><?= _k($iv,'cele_stat_gotowka') ?></td><td class="num">—</td></tr>
      <tr><td>2. Koszty administracyjne</td><td class="num"><?= _k($iv,'adm_przelew') ?></td><td class="num"><?= _k($iv,'adm_gotowka') ?></td><td class="num">—</td></tr>
      <tr><td>3. Koszty dz. gospodarczej</td><td class="num"><?= _k($iv,'gosp_przelew') ?></td><td class="num">—</td><td class="num">—</td></tr>
      <tr><td>4. Pozostałe koszty</td><td class="num"><?= _k($iv,'pozostale_przelew') ?></td><td class="num">—</td><td class="num">—</td></tr>
    </tbody>
  </table>
</div>

<!-- V. Zatrudnienie -->
<div class="section">
  <div class="section-title">V. Informacja o zatrudnieniu i wynagrodzeniu</div>
  <table>
    <tr><td class="lbl">V.1 Zatrudnieni na umowę o pracę</td><td><?= h($v['praca_liczba'] ?? '—') ?></td></tr>
    <tr><td class="lbl">V.2 Zatrudnieni wyłącznie w dz. gosp.</td><td><?= h($v['gosp_liczba'] ?? '—') ?></td></tr>
    <tr><td class="lbl">V.3a Wynagrodzenia z umów o pracę</td><td class="num"><?= _k($v,'praca_brutto') ?></td></tr>
    <tr><td class="lbl">V.3b Wynagrodzenia z umów cywilnoprawnych</td><td class="num"><?= _k($v,'umowy_cyw_brutto') ?></td></tr>
    <tr><td class="lbl">V.3c Wynagrodzenia zarządu</td><td class="num"><?= _k($v,'zarzad_wynagrodzenia') ?></td></tr>
  </table>
</div>

<!-- VI. Pożyczki -->
<div class="section">
  <div class="section-title">VI. Informacja o udzielonych pożyczkach</div>
  <table>
    <tr>
      <td class="lbl">VI.1 Fundacja udzielała pożyczek</td>
      <td>
        <span class="yn-box"><?= ($report['vi_pozyczki']??0)?'X':'' ?></span> TAK &nbsp;&nbsp;
        <span class="yn-box"><?= ($report['vi_pozyczki']??0)?'':'X' ?></span> NIE
      </td>
    </tr>
    <?php if ($report['vi_pozyczki']??0): ?>
    <tr><td class="lbl">VI.2 Wysokość pożyczek</td><td><?= h($report['vi_wysokosc']??'—') ?></td></tr>
    <tr><td class="lbl">VI.3 Pożyczkobiorcy</td><td><?= h($report['vi_pozyczkobiorcy']??'—') ?></td></tr>
    <tr><td class="lbl">VI.4 Podstawa statutowa</td><td><?= h($report['vi_podstawa_stat']??'—') ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<!-- VII. Środki -->
<div class="section">
  <div class="section-title">VII. Środki fundacji (stan na 31.12.<?= $rok ?>)</div>
  <table>
    <tr><td class="lbl">VII.1 Rachunki płatnicze</td><td class="val"><?= h($vii['rachunki']??'—') ?></td></tr>
    <tr><td class="lbl">VII.3 Gotówka</td><td class="num"><?= _k($vii,'gotowka') ?></td></tr>
    <tr><td class="lbl">VII.4–6 Obligacje/nieruchomości/środki</td><td class="val"><?= h($vii['obligacje']??'—') ?></td></tr>
    <tr>
      <td class="lbl">VII.7 Aktywa / Zobowiązania</td>
      <td>Aktywa: <strong><?= _k($vii,'aktywa') ?></strong> &nbsp;|&nbsp; Zobowiązania: <strong><?= _k($vii,'zobowiazania') ?></strong></td>
    </tr>
  </table>
</div>

<!-- VIII–XII -->
<div class="section">
  <div class="section-title">VIII. Działalność zlecona</div>
  <table><tr><td class="val" style="min-height:15mm"><?= h($report['viii_opis']??'—') ?></td></tr></table>
</div>

<div class="section">
  <div class="section-title">IX. Rozliczenia podatkowe</div>
  <table>
    <tr><td class="lbl">IX.1 Zobowiązania podatkowe</td><td class="val"><?= h($report['ix_zobowiazania']??'—') ?></td></tr>
    <tr><td class="lbl">IX.2 Deklaracje podatkowe</td><td class="val"><?= h($report['ix_deklaracje']??'—') ?></td></tr>
  </table>
</div>

<div class="section">
  <div class="section-title">X–XII. AML, Płatności gotówkowe, Kontrole</div>
  <table>
    <tr>
      <td class="lbl">X. Instytucja obowiązana (AML)</td>
      <td><span class="yn-box"><?= ($report['x_aml']??0)?'X':'' ?></span> TAK &nbsp;&nbsp; <span class="yn-box"><?= ($report['x_aml']??0)?'':'X' ?></span> NIE</td>
    </tr>
    <tr><td class="lbl">XI. Płatności gotówkowe ≥ 10 000 EUR</td><td class="val"><?= h($report['xi_platnosci']??'—') ?></td></tr>
    <tr>
      <td class="lbl">XII. Kontrola w fundacji</td>
      <td>
        <span class="yn-box"><?= ($report['xii_kontrola']??0)?'X':'' ?></span> TAK &nbsp;&nbsp;
        <span class="yn-box"><?= ($report['xii_kontrola']??0)?'':'X' ?></span> NIE
        <?php if ($report['xii_wyniki']): ?><br><?= h($report['xii_wyniki']) ?><?php endif; ?>
      </td>
    </tr>
  </table>
</div>

<!-- Podpisy -->
<div class="signature-block">
  <div>
    <div style="margin-bottom:20mm"></div>
    <div class="sig-line">podpisy członków zarządu fundacji*</div>
  </div>
  <div>
    <div style="margin-bottom:20mm"></div>
    <div class="sig-line">miejscowość, data</div>
  </div>
</div>

<p class="footnote">
  * podpisy członków zarządu fundacji zgodnie z zasadami reprezentacji określonymi w statucie fundacji<br>
  Dokument wygenerowany automatycznie przez system SZO FEER — <?= date('d.m.Y H:i') ?> | Rok sprawozdawczy: <?= $rok ?>
</p>

</body>
</html>
