<?php
/**
 * Książka nadawcza — wydruk pozycji za wybrany okres (domyślnie dzisiejszy dzień).
 * Format zestawienia zdawczego dla operatora pocztowego / do akt kancelaryjnych.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_login(); require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$od = $_GET['od'] ?? date('Y-m-d');
$do = $_GET['do'] ?? $od;
if (strtotime($do) < strtotime($od)) [$od, $do] = [$do, $od];

$rows    = ezd_rpwy_ksiazka($od, $do);
$sumSzt  = array_sum(array_map(fn($r) => (int)$r['liczba_szt'], $rows));
$sumKosz = array_sum(array_map(fn($r) => (float)$r['koszt'], $rows));

$org    = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$adres  = org_setting('org_adres');
$miejsc = org_setting('org_miejscowosc') ?: org_setting('org_miasto');
$okres  = ($od === $do) ? date_pl($od) : date_pl($od) . ' – ' . date_pl($do);
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<title>Książka nadawcza <?= h($okres) ?> — wydruk</title>
<style>
  *{box-sizing:border-box}
  body{font-family:"Times New Roman",Georgia,serif;color:#000;margin:0;padding:24px;font-size:12pt;line-height:1.4}
  .sheet{max-width:1000px;margin:0 auto}
  .head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px}
  .org{font-weight:bold}
  .org small{font-weight:normal;font-size:10pt;color:#333}
  .meta{text-align:right;font-size:10pt}
  h1{text-align:center;font-size:15pt;margin:8px 0 2px}
  .sub{text-align:center;font-size:11pt;margin-bottom:18px}
  table{width:100%;border-collapse:collapse;margin-bottom:18px;font-size:10pt}
  th,td{border:1px solid #000;padding:4px 6px;vertical-align:top}
  th{background:#eee;text-align:left}
  td.c,th.c{text-align:center}
  td.r,th.r{text-align:right}
  .sum td{font-weight:bold;background:#f4f4f4}
  .sig{display:flex;justify-content:space-between;margin-top:44px;gap:40px}
  .sig div{flex:1;text-align:center;border-top:1px solid #000;padding-top:4px;font-size:10pt}
  .toolbar{max-width:1000px;margin:0 auto 16px;display:flex;gap:8px;justify-content:flex-end;align-items:center;font-family:sans-serif;font-size:11pt}
  .btn{font-family:sans-serif;font-size:11pt;padding:6px 14px;border:1px solid #666;background:#f5f5f5;border-radius:4px;cursor:pointer;text-decoration:none;color:#000}
  .empty{text-align:center;padding:24px;font-style:italic}
  @media print{.toolbar{display:none}body{padding:0}}
</style>
</head><body>
<div class="toolbar">
  <form method="get" style="display:flex;gap:6px;align-items:center;margin:0">
    <label for="od">Od</label><input type="date" name="od" id="od" value="<?= h($od) ?>">
    <label for="do">do</label><input type="date" name="do" id="do" value="<?= h($do) ?>">
    <button class="btn" type="submit">Pokaż</button>
  </form>
  <a href="#" class="btn" onclick="window.print();return false;">🖨 Drukuj</a>
  <a href="<?= APP_URL ?>/ezd/rpwy/index.php" class="btn">← Wróć</a>
</div>
<div class="sheet">
  <div class="head">
    <div class="org"><?= h($org) ?><br><small><?= h($adres) ?></small></div>
    <div class="meta"><?= h($miejsc) ?><?= $miejsc ? ', ' : '' ?><?= ezd_data_slownie() ?></div>
  </div>

  <h1>KSIĄŻKA NADAWCZA</h1>
  <div class="sub">Rejestr przesyłek wychodzących za okres <?= h($okres) ?></div>

  <?php if (!$rows): ?>
  <div class="empty">W wybranym okresie nie zarejestrowano przesyłek wychodzących.</div>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th class="c" style="width:34px">Lp.</th>
        <th style="width:96px">Nr RPW-W</th>
        <th style="width:74px">Data</th>
        <th>Odbiorca i adres</th>
        <th style="width:130px">Znak pisma</th>
        <th style="width:120px">Sposób</th>
        <th style="width:118px">Nr nadania</th>
        <th class="c" style="width:38px">Szt.</th>
        <th class="r" style="width:66px">Opłata</th>
      </tr>
    </thead>
    <tbody>
    <?php $i = 0; foreach ($rows as $r): $i++;
      $sp = EZD_RPWY_SPOSOBY[$r['sposob']] ?? ['label' => $r['sposob']]; ?>
      <tr>
        <td class="c"><?= $i ?></td>
        <td><?= h(ezd_rpwy_label($r)) ?></td>
        <td><?= date_pl($r['data_wysylki']) ?></td>
        <td><?= h($r['odbiorca']) ?><?= $r['adres'] ? ', ' . h($r['adres']) : '' ?><?= $r['ade'] ? '<br><small>ADE: ' . h($r['ade']) . '</small>' : '' ?></td>
        <td><?= h($r['pismo_sygnatura'] ?: ($r['znak_sprawy'] ?: '—')) ?></td>
        <td><?= h($sp['label']) ?></td>
        <td><?= h($r['nr_nadania'] ?: '—') ?></td>
        <td class="c"><?= (int)$r['liczba_szt'] ?></td>
        <td class="r"><?= $r['koszt'] > 0 ? number_format((float)$r['koszt'], 2, ',', ' ') : '—' ?></td>
      </tr>
    <?php endforeach; ?>
      <tr class="sum">
        <td colspan="7">Razem pozycji: <?= count($rows) ?></td>
        <td class="c"><?= $sumSzt ?></td>
        <td class="r"><?= number_format($sumKosz, 2, ',', ' ') ?></td>
      </tr>
    </tbody>
  </table>
  <?php endif; ?>

  <div class="sig">
    <div>podpis pracownika kancelarii</div>
    <div>podpis operatora / potwierdzenie przyjęcia</div>
  </div>
</div>
</body></html>
