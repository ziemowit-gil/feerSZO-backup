<?php
/**
 * modules/contract_card/view/karta.php — wydruk „Karta umowy — informacje o umowie” (A4, układ urzędowego druku jak CIT-8).
 * Dane: $type, $row, $head, $sections, $history, $user (ContractCardController::show).
 */
$org   = defined('ORG_NAME') ? ORG_NAME : (function_exists('org_setting') ? org_setting('org_name') : '');
$now   = date('d.m.Y H:i');
$cell  = function (array $f): string {
    [$nr, $label, $val, $kind] = $f;
    $span = $kind === 'long' ? 'w12' : 'w4';
    if ($kind === 'check') {
        $v = '<span class="chk">' . ($val === '1' ? '☒' : '☐') . '</span> 1. tak &nbsp; <span class="chk">' . ($val === '1' ? '☐' : '☒') . '</span> 2. nie';
    } elseif ($kind === 'money') {
        $v = h($val) . ' <span class="unit">zł</span>';
    } elseif ($kind === 'file') {
        $v = $val !== '' ? '<span class="chk">☒</span> dołączono <span class="unit">(' . h($val) . ')</span>' : '<span class="chk">☐</span> <span class="unit">nie dołączono</span>';
    } else {
        $v = nl2br(h($val));
    }
    return '<div class="f ' . $span . ($kind === 'money' ? ' num' : '') . '"><div class="lbl"><b>' . $nr . '.</b> ' . h($label) . '</div><div class="val">' . $v . '</div></div>';
};
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Karta umowy <?= h($head['numer']) ?></title>
<style>
@page { size: A4; margin: 12mm 12mm 16mm; @bottom-right { content: "KU  " counter(page) "/" counter(pages); font: 700 9pt Calibri, Arial, sans-serif; } }
* { box-sizing: border-box; }
body { margin: 0; background: #e5e7eb; color: #000; font: 9pt/1.3 Calibri, "Segoe UI", Arial, sans-serif; }
.sheet { width: 210mm; min-height: 297mm; margin: 12px auto; padding: 12mm; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.15); }
.toolbar { text-align: center; padding: 10px; font-family: system-ui, sans-serif; }
.toolbar button { font: 600 14px system-ui, sans-serif; padding: 8px 18px; border: 0; background: #1d4ed8; color: #fff; cursor: pointer; }
.top { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; }
.top .brand { font-size: 13pt; }
.top .note { font-size: 6.5pt; text-transform: uppercase; text-align: right; }
.grid { display: grid; grid-template-columns: repeat(12, 1fr); border-left: 1px solid #000; border-top: 1px solid #000; }
.f { border-right: 1px solid #000; border-bottom: 1px solid #000; padding: 1.5px 4px 3px; min-height: 9mm; }
.f .lbl { font-size: 7pt; font-weight: 600; }
.f .val { font-size: 10pt; padding-top: 2px; min-height: 4.5mm; overflow-wrap: anywhere; }
.f.num .val { text-align: right; }
.f.grey { background: #d9d9d9; }
.w2 { grid-column: span 2; } .w3 { grid-column: span 3; } .w4 { grid-column: span 4; } .w5 { grid-column: span 5; } .w6 { grid-column: span 6; } .w8 { grid-column: span 8; } .w12 { grid-column: span 12; }
.sym { font: 700 17pt Calibri, Arial, sans-serif; margin: 4px 0 0; }
.title { text-align: center; font: 700 12.5pt Calibri, Arial, sans-serif; margin: -6px 0 6px; }
.subtitle { text-align: center; font-size: 10pt; margin-bottom: 6px; }
.period { display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 8px; }
.period .f { width: 52mm; border: 1px solid #000; }
.period .lead { font-weight: 700; font-size: 10pt; }
.info { background: #d9d9d9; border: 1px solid #000; padding: 4px 6px; display: grid; grid-template-columns: 30mm 1fr; gap: 1px 6px; font-size: 8.5pt; margin-bottom: 0; }
.sec { background: #d9d9d9; border: 1px solid #000; border-top: 0; padding: 3px 6px; font: 700 12.5pt Calibri, Arial, sans-serif; break-after: avoid; }
.sec small { display: block; font: 400 8pt Calibri, Arial, sans-serif; margin-left: 14px; }
.block { break-inside: auto; }
.block .grid { border-top: 0; }
.chk { font-size: 11pt; }
.unit { font-size: 8pt; color: #333; }
table.hist { width: 100%; border-collapse: collapse; font-size: 8pt; }
table.hist th, table.hist td { border: 1px solid #000; padding: 2px 4px; text-align: left; vertical-align: top; }
table.hist th { background: #efefef; font-weight: 600; }
.sign { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid #000; border-top: 0; }
.sign .f { min-height: 18mm; border-bottom: 0; }
.foot { display: flex; justify-content: flex-end; margin-top: 8px; }
.foot .box { border: 1px solid #000; display: flex; font: 700 13pt Calibri, Arial, sans-serif; }
.foot .box span { padding: 2px 10px; } .foot .box span + span { border-left: 1px solid #000; font-size: 9pt; font-weight: 400; display: flex; align-items: center; }
@media print { body { background: #fff; } .sheet { margin: 0; padding: 0; width: auto; min-height: 0; box-shadow: none; } .toolbar, .foot { display: none; } }
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Drukuj kartę umowy</button></div>
<main class="sheet">
  <div class="top">
    <div class="brand"><?= h($org) ?></div>
    <div class="note">Pola wypełnia system na podstawie Rejestru Umów. Pola ciemne — dane rejestru.<br>Stan na dzień: <?= $now ?></div>
  </div>
  <div class="grid">
    <div class="f w5"><div class="lbl"><b>1.</b> Numer umowy</div><div class="val"><?= h($head['numer']) ?></div></div>
    <div class="f w4 grey"><div class="lbl"><b>2.</b> Nr w Rejestrze Umów</div><div class="val"><?= h($head['rejestr']) ?></div></div>
    <div class="f w3 grey"><div class="lbl"><b>3.</b> Status</div><div class="val"><?= h($head['status']) ?></div></div>
  </div>
  <div class="sym">KU</div>
  <div class="title">Karta umowy — informacje o umowie</div>
  <div class="subtitle"><?= h($head['rodzaj']) ?></div>
  <div class="period">
    <span class="lead">okres obowiązywania</span>
    <div class="f"><div class="lbl"><b>4.</b> Od (dzień - miesiąc - rok)</div><div class="val"><?= h($head['od']) ?></div></div>
    <div class="f"><div class="lbl"><b>5.</b> Do (dzień - miesiąc - rok)</div><div class="val"><?= h($head['do']) ?></div></div>
  </div>
  <div class="info">
    <div>Rodzaj umowy:</div><div><?= h($head['rodzaj']) ?></div>
    <div>Wprowadził(a):</div><div><?= h($head['autor'] ?: '—') ?><?= $head['utw'] ? ', ' . h($head['utw']) : '' ?><?= $head['zmiana'] ? ' · ostatnia zmiana: ' . h($head['zmiana']) : '' ?></div>
    <div>Koszulka EZD:</div><div><?= h($head['ezd'] ?: 'brak powiązanej koszulki') ?></div>
    <div>Sporządził(a):</div><div><?= h((string)($user['name'] ?? '')) ?>, <?= $now ?></div>
    <div>Przeznaczenie:</div><div>Dokument wewnętrzny — zawiera dane osobowe. Nie udostępniać osobom nieuprawnionym.</div>
  </div>

  <?php foreach ($sections as $k => $fields): ?>
  <section class="block">
    <div class="sec"><?= $k ?>. <?= h(CONTRACT_CARD_SECTIONS[$k]) ?><?= $k === 'G' ? '<small>(wyłącznie pola wypełnione)</small>' : '' ?></div>
    <div class="grid">
      <?php
      // dopełnij wiersz krótkich pól do pełnej szerokości (12 kolumn = 3 pola po 4)
      $short = count(array_filter($fields, fn($f) => $f[3] !== 'long'));
      foreach ($fields as $i => $f) {
          echo $cell($f);
          if ($i === $short - 1 && $short % 3) echo '<div class="f w' . (4 * (3 - $short % 3)) . ' grey"></div>';
      }
      ?>
    </div>
  </section>
  <?php endforeach; ?>

  <section class="block">
    <div class="sec">H. Historia umowy<small>(ostatnie wpisy dziennika zmian)</small></div>
    <?php if ($history): ?>
    <table class="hist">
      <thead><tr><th style="width:24mm">Data</th><th style="width:38mm">Kto</th><th style="width:26mm">Czynność</th><th>Opis</th></tr></thead>
      <tbody>
      <?php foreach ($history as $hh): ?>
        <tr><td><?= h(date('d.m.Y H:i', strtotime((string)$hh['created_at']))) ?></td><td><?= h((string)$hh['user_snapshot']) ?></td><td><?= h((string)$hh['action']) ?></td><td><?= h(mb_strimwidth((string)$hh['note'], 0, 220, '…')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <div class="grid"><div class="f w12"><div class="val">Brak wpisów.</div></div></div>
    <?php endif; ?>
  </section>

  <section class="block">
    <div class="sec">I. Potwierdzenie zgodności z dokumentacją</div>
    <div class="sign">
      <div class="f"><div class="lbl"><b>A.</b> Data i podpis osoby sporządzającej kartę</div></div>
      <div class="f" style="border-left:1px solid #000"><div class="lbl"><b>B.</b> Data i podpis osoby zatwierdzającej</div></div>
    </div>
  </section>

  <div class="foot"><div class="box"><span>KU</span><span><?= h($head['numer']) ?></span></div></div>
</main>
</body>
</html>
