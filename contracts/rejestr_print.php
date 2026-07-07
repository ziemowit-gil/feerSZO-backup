<?php
/**
 * Rejestr Umów (RU) — wydruk. Wersja do druku/PDF listy umów z rejestru,
 * z zachowaniem filtrów przekazanych z contracts/rejestr.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();
if (!can_edit()) { http_response_code(403); die('Brak uprawnień.'); }

$filters = [
    'typ'     => trim($_GET['typ']     ?? ''),
    'status'  => trim($_GET['status']  ?? ''),
    'rok'     => trim($_GET['rok']     ?? ''),
    'opiekun' => trim($_GET['opiekun'] ?? ''),
    'data_od' => trim($_GET['data_od'] ?? ''),
    'data_do' => trim($_GET['data_do'] ?? ''),
    'q'       => trim($_GET['q']       ?? ''),
];
$rejestr = contracts_registry_filter(all_contracts_registry(), $filters);

// Opis aktywnych filtrów do nagłówka wydruku
$filter_desc = array_filter([
    $filters['typ']     !== '' ? 'Typ: ' . (CONTRACT_TYPES[$filters['typ']] ?? $filters['typ']) : '',
    $filters['status']  !== '' ? 'Status: ' . (STATUS_LABELS[$filters['status']]['label'] ?? $filters['status']) : '',
    $filters['rok']     !== '' ? 'Rok rejestru: ' . $filters['rok'] : '',
    $filters['opiekun'] !== '' ? 'Opiekun: ' . $filters['opiekun'] : '',
    ($filters['data_od'] !== '' || $filters['data_do'] !== '')
        ? 'Zawarcie: ' . ($filters['data_od'] ?: '…') . ' – ' . ($filters['data_do'] ?: '…') : '',
    $filters['q']       !== '' ? 'Szukano: „' . $filters['q'] . '"' : '',
]);

$org = defined('ORG_NAME') ? ORG_NAME : '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Rejestr umów (RU) — <?= h($org) ?></title>
<style>
  body{font-family:Arial,sans-serif;font-size:10pt;margin:20px}
  h1{font-size:14pt;margin:0 0 4px}
  .meta{font-size:9pt;color:#555;margin-bottom:16px}
  table{width:100%;border-collapse:collapse;font-size:8.5pt}
  th{background:#0d6efd;color:#fff;padding:4px 6px;text-align:left;white-space:nowrap}
  td{padding:3px 6px;border-bottom:1px solid #e0e0e0;vertical-align:top}
  tr:nth-child(even) td{background:#f8f9ff}
  @media print{
    .no-print{display:none}
    @page{margin:1.5cm;size:A4 landscape}
  }
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px">
  <button onclick="window.print()" style="padding:4px 18px;background:red;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11pt">
     Drukuj / Zapisz jako PDF
  </button>
  <a href="rejestr.php" style="margin-left:10px;font-size:10pt">← Powrót</a>
</div>

<h1><?= h($org) ?> — Rejestr umów (RU)</h1>
<div class="meta">
  Wygenerowano: <?= date('d.m.Y H:i') ?> | Liczba pozycji: <?= count($rejestr) ?>
  <?php foreach ($filter_desc as $d): ?> | <?= h($d) ?><?php endforeach; ?>
</div>

<table>
  <thead>
    <tr>
      <th>Lp.</th><th>Nr rejestru</th><th>Typ umowy</th><th>Strona umowy</th>
      <th>Opiekun</th><th>Nr umowy</th><th>Data zawarcia</th><th>Status</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rejestr as $i => $r): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= h($r['nr_rejestru']) ?></td>
    <td><?= h($r['contract_type_label']) ?></td>
    <td><?= h($r['strona'] ?: '—') ?></td>
    <td><?= h($r['opiekun'] ?: '—') ?></td>
    <td><?= h($r['numer_umowy']) ?></td>
    <td><?= $r['data_zawarcia'] ? date('d.m.Y', strtotime($r['data_zawarcia'])) : '—' ?></td>
    <td><?= h(STATUS_LABELS[$r['status']]['label'] ?? $r['status']) ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<script>
if (location.hash !== '#noPrint') window.onload = () => window.print();
</script>
</body>
</html>
