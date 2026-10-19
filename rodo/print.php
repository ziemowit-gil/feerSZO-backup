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
$org_city = org_setting('org_miejscowosc') ?: (org_setting('org_name') ? '' : '_______________');
$org_krs  = org_setting('org_krs') ?: '';

$today           = date('d.m.Y');
$signed_date     = $row['signed_at']     ? date('d.m.Y', strtotime($row['signed_at']))     : $today;
$vol_signed_date = $row['vol_signed_at'] ? date('d.m.Y', strtotime($row['vol_signed_at'])) : $today;
$from_date       = $row['authorized_from']  ? date('d.m.Y', strtotime($row['authorized_from']))  : $today;
$until_date      = $row['authorized_until'] ? date('d.m.Y', strtotime($row['authorized_until'])) : '';
$contract_date_fmt = $row['contract_date'] ? date('d.m.Y', strtotime($row['contract_date'])) : '';
$meta              = rodo_type_meta($row['contract_type']);
?>
<?php
$_n = 0;
// Pole formularza: mała numerowana etykieta + wartość (układ jak druki licencji AM5/AM7)
$fld = function (string $label, string $html, string $attr = '') use (&$_n): string {
    $_n++;
    return '<td class="df-f"' . $attr . '><div class="df-lb"><b>' . $_n . '.</b> ' . h($label) . '</div><div class="df-v">' . ($html !== '' ? $html : '&nbsp;') . '</div></td>';
};
$contractTxt = $row['contract_number'] ? $meta['doc_contract_short'] . ($row['contract_type'] === 'bez_umowy' ? ': ' : ' nr ') . $row['contract_number'] . ($contract_date_fmt ? ' z dnia ' . $contract_date_fmt : '') : '';
$cssV = (int)@filemtime(dirname(__DIR__) . '/assets/css/drukFormularz.css');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Upoważnienie RODO — <?= h($row['number']) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/drukFormularz.css?v=<?= $cssV ?>">
</head>
<body class="df">

<div class="df-toolbar" role="toolbar" aria-label="Wydruk">
  <button type="button" onclick="window.print()">Drukuj / PDF</button>
  <a href="view.php?id=<?= $id ?>">← Wróć do upoważnienia</a>
  <span class="df-tb-num">Nr <?= h($row['number']) ?></span>
</div>

<table class="df-hdr"><tr>
  <td style="width:60%"><div class="df-org"><?= h($row['org_name']) ?></div>
    <div class="df-small"><?= h($row['org_address']) ?><br>NIP <?= h($row['org_nip']) ?><?= $org_krs ? ' · KRS ' . h($org_krs) : '' ?></div></td>
  <td class="df-right" style="width:40%"><?= h($org_city) ?>, dnia <?= $signed_date ?><div class="df-small">miejscowość i data wydania</div></td>
</tr></table>

<div class="df-title">Upoważnienie do przetwarzania danych osobowych</div>
<div class="df-subtitle">wydane na podstawie art. 29 oraz art. 32 ust. 4 Rozporządzenia (UE) 2016/679 (RODO)</div>
<div class="df-num">Numer: <b><?= h($row['number']) ?></b></div>

<table class="df-t">
  <tr><td class="df-sec" colspan="2">A. Administrator danych osobowych</td></tr>
  <tr><?= $fld('Nazwa administratora', h($row['org_name']), ' colspan="2"') ?></tr>
  <tr><?= $fld('Siedziba', h($row['org_address']), ' style="width:60%"') ?><?= $fld('NIP / KRS', h($row['org_nip']) . ($org_krs ? ' / ' . h($org_krs) : '')) ?></tr>
</table>

<table class="df-t">
  <tr><td class="df-sec" colspan="3">B. Osoba upoważniona</td></tr>
  <tr><?= $fld('Imię i nazwisko', h($row['person_name']), ' style="width:45%"') ?><?= $fld('PESEL', h((string)$row['person_pesel']), ' style="width:22%"') ?><?= $fld('Podstawa współpracy', h($contractTxt) ?: '<span class="df-ph">—</span>') ?></tr>
</table>

<table class="df-t">
  <tr><td class="df-sec" colspan="2">C. Treść upoważnienia</td></tr>
  <tr><td class="df-pc" colspan="2"><b>§ 1.</b> Z dniem <b><?= $from_date ?></b> upoważniam Pana/Panią <b><?= h($row['person_name']) ?></b>
    do przetwarzania danych osobowych w zbiorach prowadzonych przez <?= h($row['org_name']) ?> w zakresie niezbędnym do realizacji zadań wynikających z <?= h($meta['doc_contract']) ?><?php
    if ($row['contract_number']): ?> <?= $row['contract_type'] === 'bez_umowy' ? '—' : 'nr' ?> <b><?= h($row['contract_number']) ?></b><?php endif; ?><?= $contract_date_fmt ? ' z dnia ' . $contract_date_fmt : '' ?>.</td></tr>
  <tr><?= $fld('Obowiązuje od', $from_date, ' style="width:50%"') ?><?= $fld('Obowiązuje do', $until_date ?: '<span class="df-ph">do wygaśnięcia lub odwołania (§ 4)</span>') ?></tr>
  <tr><td class="df-pc" colspan="2"><b>§ 2.</b> Zakres upoważnienia obejmuje przetwarzanie danych osobowych w systemach informatycznych oraz w formie papierowej, w celu:
    <ul class="df-list df-cols2">
      <?php foreach ($scope as $k): ?><li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li><?php endforeach; ?>
      <?php if ($row['scope_custom']): ?><li><?= h($row['scope_custom']) ?></li><?php endif; ?>
    </ul></td></tr>
  <tr><td class="df-pc" colspan="2"><b>§ 3.</b> Osoba upoważniona jest zobowiązana do:
    <ol class="df-list">
      <li>przetwarzania danych osobowych wyłącznie w zakresie i celu określonym w niniejszym upoważnieniu;</li>
      <li>zachowania w pełnej poufności przetwarzanych danych osobowych oraz sposobów ich zabezpieczenia, również po ustaniu <?= h($meta['doc_relation']) ?>;</li>
      <li>stosowania się do przepisów Rozporządzenia (UE) 2016/679 (RODO) oraz wewnętrznej polityki bezpieczeństwa <?= h($row['org_name']) ?>;</li>
      <li>niezwłocznego zgłaszania Administratorowi każdego przypadku naruszenia ochrony danych lub podejrzenia takiego naruszenia.</li>
    </ol></td></tr>
  <tr><td class="df-pc" colspan="2"><b>§ 4.</b> Upoważnienie wygasa z chwilą <?= $row['contract_type'] === 'bez_umowy' ? 'zakończenia pełnienia funkcji lub ustania ' . h($meta['doc_relation']) : 'rozwiązania lub wygaśnięcia ' . h($meta['doc_contract']) ?><?php
    if ($until_date): ?>, nie później jednak niż w dniu <?= $until_date ?>,<?php endif; ?> lub w przypadku odwołania upoważnienia przez Administratora danych.</td></tr>
</table>

<table class="df-t" style="page-break-inside:avoid">
  <tr><td class="df-sec" colspan="2">D. Podpis administratora</td></tr>
  <tr><?= $fld('Miejscowość i data', h($org_city) . ', ' . $signed_date, ' style="width:40%"') ?>
    <td class="df-f df-sign"><div class="df-lb"><b><?= ++$_n ?>.</b> Podpis administratora danych / osoby reprezentującej organizację</div>
      <div class="df-sign-line"></div><div class="df-sign-cap"><?= $row['signed_by_name'] ? h($row['signed_by_name']) : '(czytelny podpis)' ?></div></td></tr>
</table>

<table class="df-t" style="page-break-inside:avoid">
  <tr><td class="df-sec" colspan="2">E. Oświadczenie osoby upoważnionej</td></tr>
  <tr><td class="df-pc" colspan="2">Oświadczam, że zapoznałem/am się z przepisami dotyczącymi ochrony danych osobowych oraz wewnętrznymi procedurami bezpieczeństwa
    obowiązującymi w <?= h($row['org_name']) ?>. Zobowiązuję się do ich przestrzegania i zachowania w tajemnicy wszelkich danych osobowych,
    do których uzyskam dostęp w ramach niniejszego upoważnienia.</td></tr>
  <tr><?= $fld('Miejscowość i data', h($org_city) . ', ' . $vol_signed_date, ' style="width:40%"') ?>
    <td class="df-f df-sign"><div class="df-lb"><b><?= ++$_n ?>.</b> Podpis osoby upoważnionej</div>
      <div class="df-sign-line"></div><div class="df-sign-cap"><?= h($row['person_name']) ?></div></td></tr>
</table>

<div class="df-foot"><span>Rejestr upoważnień RODO · <?= h($row['org_name']) ?></span><span><?= h($row['number']) ?> · wygenerowano <?= date('d.m.Y') ?></span></div>

</body>
</html>
