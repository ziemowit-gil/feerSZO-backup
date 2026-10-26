<?php
/**
 * karty30/clients/card_print_form.php — Karta Beneficjenta w układzie formularza urzędowego (assets/css/drukFormularz.css,
 * jak upoważnienia RODO i druki licencji): sekcje A–G z szarym paskiem, numerowane pola, miejsca na podpis.
 * Dołączany z card_print.php przy ?styl=formularz — korzysta z jego zmiennych ($client, $courses, $pfron_contracts,
 * $consultations, $schedules, $remaining, $pct_used, $org, $generated, etykiety, $is_kursant_mode, $client_id).
 */
$_n = 0;
$_sec = 0;
$L = function () use (&$_sec): string { return chr(65 + $_sec++) . '.'; };   // litery sekcji bez dziur, gdy sekcja jest pominięta
$fld = function (string $label, string $html, string $attr = '') use (&$_n): string {
    $_n++;
    return '<td class="df-f"' . $attr . '><div class="df-lb"><b>' . $_n . '.</b> ' . h($label) . '</div><div class="df-v">' . ($html !== '' ? $html : '<span class="df-ph">—</span>') . '</div></td>';
};
$d = fn($v, string $f = 'd.m.Y') => $v ? date($f, strtotime((string)$v)) : '';
$hrs = fn($v) => number_format((float)$v, 1, ',', ' ') . ' h';
$st = $client['status'] ?? 'new';
$back = $is_kursant_mode ? 'index.php?tab=dane' : 'view.php?id=' . (int)$client_id;
$swap = '?' . http_build_query(array_filter(['id' => $is_kursant_mode ? null : $client_id, 'kursant' => $is_kursant_mode ? 1 : null]));
$orgAddr = function_exists('org_setting') ? trim((string)org_setting('org_adres')) : '';
$cssV = (int)@filemtime(dirname(__DIR__, 2) . '/assets/css/drukFormularz.css');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Karta Beneficjenta — <?= h($client['name']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/drukFormularz.css?v=<?= $cssV ?>">
</head>
<body class="df">

<div class="df-toolbar" role="toolbar" aria-label="Wydruk">
  <button type="button" onclick="window.print()">Drukuj / PDF</button>
  <a href="<?= h($swap) ?>">Wariant: karta</a>
  <a href="<?= h($back) ?>">← <?= $is_kursant_mode ? 'Wróć do panelu' : 'Wróć do karty' ?></a>
  <span class="df-tb-num">Beneficjent #<?= (int)$client_id ?></span>
</div>

<table class="df-hdr"><tr>
  <td style="width:60%"><div class="df-org"><?= h($org) ?></div><?php if ($orgAddr !== ''): ?><div class="df-small"><?= h($orgAddr) ?></div><?php endif; ?></td>
  <td class="df-right" style="width:40%"><?= h($generated) ?><div class="df-small">data wygenerowania</div></td>
</tr></table>

<div class="df-title">Karta beneficjenta</div>
<div class="df-num">Numer beneficjenta: <b><?= (int)$client_id ?></b> · stan: <b><?= h($status_labels[$st] ?? $st) ?></b></div>

<table class="df-t">
  <tr><td class="df-sec" colspan="3"><?= $L() ?> Dane osobowe</td></tr>
  <tr><?= $fld('Imię i nazwisko', h($client['name']), ' colspan="2" style="width:67%"') ?><?= $fld('Data urodzenia', $d($client['date_of_birth'] ?? '')) ?></tr>
  <tr><?= $fld('Płeć', h($gender_labels[$client['gender'] ?? ''] ?? '')) ?><?= $fld('Telefon', h((string)($client['phone'] ?? ''))) ?><?= $fld('E-mail', h((string)($client['email'] ?? ''))) ?></tr>
  <tr><?= $fld('Adres', h((string)($client['address'] ?? '')), ' colspan="2"') ?><?= $fld('Zgoda RODO', !empty($client['consent']) ? '<span class="df-bx">&#9746;</span> tak' : '<span class="df-bx">&#9744;</span> brak') ?></tr>
</table>

<table class="df-t">
  <tr><td class="df-sec" colspan="2"><?= $L() ?> Cel wsparcia i sprzęt</td></tr>
  <tr><?= $fld('Cel wsparcia / potrzeba', nl2br(h(mb_substr((string)($client['problem'] ?? ''), 0, 1200))), ' colspan="2"') ?></tr>
  <tr><?= $fld('Sprzęt', nl2br(h(mb_substr((string)($client['equipment'] ?? ''), 0, 600))), ' colspan="2"') ?></tr>
</table>

<table class="df-t">
  <tr><td class="df-sec" colspan="3"><?= $L() ?> Godziny wsparcia</td></tr>
  <tr><?= $fld('Limit godzin', $hrs($client['available_hours'] ?? 0)) ?><?= $fld('Wykorzystano', $hrs($client['used'] ?? 0) . ' (' . (int)$pct_used . '%)') ?><?= $fld('Pozostało', $hrs($remaining)) ?></tr>
</table>

<?php if ($courses): ?>
<table class="df-t">
  <tr><td class="df-sec" colspan="4"><?= $L() ?> Zapisy na zajęcia</td></tr>
  <tr><th>Grupa / kurs</th><th style="width:22%">Dzień i godziny</th><th style="width:26%">Prowadzący</th><th style="width:12%">Stan</th></tr>
  <?php foreach ($courses as $c): ?>
  <tr><td class="df-cell"><b><?= h($c['course_name']) ?></b></td>
    <td class="df-cell"><?= h($day_labels[(string)($c['day_of_week'] ?? '')] ?? '—') ?> <?= ($c['time_from'] && $c['time_to']) ? h(substr($c['time_from'], 0, 5)) . '–' . h(substr($c['time_to'], 0, 5)) : '' ?></td>
    <td class="df-cell"><?= h($c['instructor_name'] ?: '—') ?></td>
    <td class="df-cell"><?= ($c['status'] ?? '') === 'active' ? 'aktywny' : h((string)($c['status'] ?? '—')) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($pfron_contracts): ?>
<table class="df-t">
  <tr><td class="df-sec" colspan="5"><?= $L() ?> Umowy o dofinansowanie (PFRON)</td></tr>
  <tr><th>Numer umowy</th><th style="width:14%">Limit</th><th style="width:16%">Wykorzystano</th><th style="width:14%">Pozostało</th><th style="width:24%">Ważność</th></tr>
  <?php foreach ($pfron_contracts as $pc): $rem = max(0, (float)$pc['hours_limit'] - (float)$pc['hours_used']); ?>
  <tr><td class="df-cell"><b><?= h(($pc['doc_number'] ?? '') ?: $pc['contract_number']) ?></b></td><td class="df-cell"><?= h($hrs($pc['hours_limit'])) ?></td>
    <td class="df-cell"><?= h($hrs($pc['hours_used'])) ?></td><td class="df-cell"><b><?= h($hrs($rem)) ?></b></td>
    <td class="df-cell"><?= h(trim($d($pc['valid_from']) . ' – ' . $d($pc['valid_to']), ' –')) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($consultations || $schedules): ?>
<table class="df-t">
  <tr><td class="df-sec" colspan="4"><?= $L() ?> Ostatnie konsultacje i terminy</td></tr>
  <tr><th style="width:22%">Data</th><th style="width:18%">Rodzaj</th><th style="width:16%">Czas</th><th>Konsultant / prowadzący</th></tr>
  <?php foreach ($consultations as $co): ?>
  <tr><td class="df-cell"><?= h($d($co['consultation_datetime'])) ?></td><td class="df-cell">konsultacja</td>
    <td class="df-cell"><?= $co['duration_minutes'] ? (int)$co['duration_minutes'] . ' min' : '—' ?></td><td class="df-cell"><?= h($co['consultant_name'] ?: '—') ?><?= ($co['status'] ?? '') !== '' ? ' · ' . h($co['status']) : '' ?></td></tr>
  <?php endforeach; ?>
  <?php foreach ($schedules as $s): ?>
  <tr><td class="df-cell"><?= h($d($s['start_time'], 'd.m.Y H:i')) ?></td><td class="df-cell">termin</td>
    <td class="df-cell"><?= (int)$s['duration_minutes'] ?> min</td><td class="df-cell"><?= h($s['consultant_name'] ?: '—') ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<table class="df-t" style="page-break-inside:avoid">
  <tr><td class="df-sec" colspan="2"><?= $L() ?> Potwierdzenie zgodności danych</td></tr>
  <tr><td class="df-pc" colspan="2">Potwierdzam, że dane zawarte w karcie są zgodne ze stanem faktycznym. Dane osobowe przetwarzane są zgodnie z Rozporządzeniem (UE) 2016/679 (RODO).</td></tr>
  <tr><td class="df-f df-sign" style="width:50%"><div class="df-lb"><b><?= ++$_n ?>.</b> Data i podpis beneficjenta / przedstawiciela ustawowego</div><div class="df-sign-line"></div><div class="df-sign-cap"><?= h($client['name']) ?></div></td>
    <td class="df-f df-sign"><div class="df-lb"><b><?= ++$_n ?>.</b> Data i podpis osoby prowadzącej kartę</div><div class="df-sign-line"></div><div class="df-sign-cap">(czytelny podpis)</div></td></tr>
</table>

<div class="df-foot"><span>Karta beneficjenta · <?= h($org) ?> · dokument wygenerowany elektronicznie</span><span>#<?= (int)$client_id ?> · <?= h($generated) ?></span></div>
<?php if (($doc_codes ?? '') !== ''): ?><div style="margin-top:1.5mm"><?= $doc_codes ?></div><?php endif; ?>

<script>if (new URLSearchParams(location.search).get('autoprint') === '1') window.print();</script>
</body>
</html>
