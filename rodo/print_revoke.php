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

// Ostatnie odwołanie
$rev = db_one(
    "SELECT * FROM rodo_revocations WHERE authorization_id=? ORDER BY revoked_at DESC LIMIT 1",
    [$id]
);

$org_city = org_setting('org_miejscowosc') ?: (org_setting('org_name') ? '' : '_______________');
$org_krs  = org_setting('org_krs') ?: '';
$today    = date('d.m.Y');

$revoked_date    = $rev ? date('d.m.Y', strtotime($rev['revoked_at'])) : $today;
$revoked_by_name = $rev ? ($rev['revoked_by_name'] ?: '') : ($row['signed_by_name'] ?: '');
$reason          = $rev ? ($rev['reason'] ?? '') : '';

// Numer dokumentu odwołania: [nr_upoważnienia]/ODW
$cof_number = $row['number'] . '/ODW';

$contract_date_fmt = $row['contract_date'] ? date('d.m.Y', strtotime($row['contract_date'])) : '';
$meta              = rodo_type_meta($row['contract_type']);
$auth_from_fmt     = $row['authorized_from'] ? date('d.m.Y', strtotime($row['authorized_from'])) : $today;
?>
<?php
$_n = 0;
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
<title>Odwołanie upoważnienia RODO — <?= h($cof_number) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/drukFormularz.css?v=<?= $cssV ?>">
</head>
<body class="df">

<div class="df-toolbar" role="toolbar" aria-label="Wydruk">
  <button type="button" onclick="window.print()">Drukuj / PDF</button>
  <a href="view.php?id=<?= $id ?>">← Wróć do upoważnienia</a>
  <span class="df-tb-num">Nr <?= h($cof_number) ?></span>
</div>

<table class="df-hdr"><tr>
  <td style="width:60%"><div class="df-org"><?= h($row['org_name']) ?></div>
    <div class="df-small"><?= h($row['org_address']) ?><br>NIP <?= h($row['org_nip']) ?><?= $org_krs ? ' · KRS ' . h($org_krs) : '' ?></div></td>
  <td class="df-right" style="width:40%"><?= h($org_city) ?>, dnia <?= $revoked_date ?><div class="df-small">miejscowość i data odwołania</div></td>
</tr></table>

<div class="df-title">Odwołanie upoważnienia do przetwarzania danych osobowych</div>
<div class="df-subtitle">na podstawie art. 29 Rozporządzenia (UE) 2016/679 (RODO)</div>
<div class="df-num">Numer: <b><?= h($cof_number) ?></b> · dotyczy upoważnienia nr <b><?= h($row['number']) ?></b> z dnia <?= $auth_from_fmt ?></div>

<table class="df-t">
  <tr><td class="df-sec" colspan="2">A. Administrator danych osobowych</td></tr>
  <tr><?= $fld('Nazwa administratora', h($row['org_name']), ' colspan="2"') ?></tr>
  <tr><?= $fld('Siedziba', h($row['org_address']), ' style="width:60%"') ?><?= $fld('NIP / KRS', h($row['org_nip']) . ($org_krs ? ' / ' . h($org_krs) : '')) ?></tr>
</table>

<table class="df-t">
  <tr><td class="df-sec" colspan="3">B. Osoba, której odwołano upoważnienie</td></tr>
  <tr><?= $fld('Imię i nazwisko', h($row['person_name']), ' style="width:45%"') ?><?= $fld('PESEL', h((string)$row['person_pesel']), ' style="width:22%"') ?><?= $fld('Podstawa współpracy', h($contractTxt) ?: '<span class="df-ph">—</span>') ?></tr>
</table>

<table class="df-t">
  <tr><td class="df-sec" colspan="2">C. Treść odwołania</td></tr>
  <tr><td class="df-pc" colspan="2"><b>§ 1.</b> Z dniem <b><?= $revoked_date ?></b> odwołuję udzielone Panu/Pani <b><?= h($row['person_name']) ?></b> upoważnienie do przetwarzania danych osobowych
    nr <b><?= h($row['number']) ?></b> z dnia <?= $auth_from_fmt ?>, wydane przez <?= h($row['org_name']) ?>. Z chwilą odwołania osoba traci prawo do przetwarzania
    danych osobowych we wszystkich zbiorach prowadzonych przez Administratora.</td></tr>
  <tr><?= $fld('Powód odwołania', $reason !== '' ? h($reason) : '<span class="df-v df-n">ustanie ' . h($meta['doc_relation']) . ' lub zakończenie ' . h($meta['doc_contract']) . ($row['contract_number'] ? ' ' . ($row['contract_type'] === 'bez_umowy' ? '—' : 'nr') . ' ' . h($row['contract_number']) : '') . ', w związku z którym upoważnienie zostało wydane</span>', ' colspan="2"') ?></tr>
  <tr><td class="df-pc" colspan="2"><b>§ 2.</b> Osoba, której odwołano upoważnienie, jest zobowiązana do:
    <ol class="df-list">
      <li>niezwłocznego zaprzestania przetwarzania danych osobowych we wszystkich zbiorach objętych odwołanym upoważnieniem;</li>
      <li>usunięcia lub zwrotu wszelkich danych osobowych pobranych ze zbiorów Administratora, w tym kopii papierowych i elektronicznych;</li>
      <li>zachowania w pełnej poufności informacji uzyskanych w trakcie obowiązywania upoważnienia, zgodnie z przepisami Rozporządzenia (UE) 2016/679 (RODO);</li>
      <li>wydania Administratorowi wszelkich nośników i dokumentów zawierających dane osobowe w terminie niezwłocznym, nie dłuższym niż 3 dni robocze od dnia odwołania upoważnienia.</li>
    </ol></td></tr>
  <tr><td class="df-pc" colspan="2"><b>§ 3.</b> Odwołanie wchodzi w życie z dniem <b><?= $revoked_date ?></b> i podlega odnotowaniu w Rejestrze osób upoważnionych do przetwarzania
    danych osobowych prowadzonym przez <?= h($row['org_name']) ?> zgodnie z art. 5 ust. 2 RODO.</td></tr>
</table>

<table class="df-t" style="page-break-inside:avoid">
  <tr><td class="df-sec" colspan="2">D. Podpis administratora</td></tr>
  <tr><?= $fld('Miejscowość i data', h($org_city) . ', ' . $revoked_date, ' style="width:40%"') ?>
    <td class="df-f df-sign"><div class="df-lb"><b><?= ++$_n ?>.</b> Podpis administratora danych / osoby reprezentującej organizację</div>
      <div class="df-sign-line"></div><div class="df-sign-cap"><?= $revoked_by_name ? h($revoked_by_name) : '(czytelny podpis)' ?></div></td></tr>
</table>

<table class="df-t" style="page-break-inside:avoid">
  <tr><td class="df-sec" colspan="2">E. Potwierdzenie odbioru i zapoznania się z treścią odwołania</td></tr>
  <tr><td class="df-pc" colspan="2">Oświadczam, że otrzymałem/am niniejszy dokument i zapoznałem/am się z jego treścią. Zobowiązuję się do niezwłocznego zaprzestania
    przetwarzania danych osobowych oraz usunięcia wszelkich kopii danych uzyskanych w ramach odwołanego upoważnienia.</td></tr>
  <tr><?= $fld('Miejscowość i data odbioru', '<span class="df-ph">.........................., dnia ....................</span>', ' style="width:40%"') ?>
    <td class="df-f df-sign"><div class="df-lb"><b><?= ++$_n ?>.</b> Podpis osoby, której odwołano upoważnienie</div>
      <div class="df-sign-line"></div><div class="df-sign-cap"><?= h($row['person_name']) ?></div></td></tr>
</table>

<div class="df-foot"><span>Rejestr upoważnień RODO · <?= h($row['org_name']) ?></span><span><?= h($cof_number) ?> · wygenerowano <?= date('d.m.Y') ?></span></div>

</body>
</html>
