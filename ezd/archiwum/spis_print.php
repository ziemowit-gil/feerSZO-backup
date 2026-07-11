<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$id   = (int)($_GET['id'] ?? 0);
$spis = ezd_arch_spis_get($id);
if (!$spis) { http_response_code(404); exit('Spis nie istnieje.'); }
$pozycje = ezd_arch_pozycje($id);
$brak    = $spis['typ'] === 'brakowanie';
$naglowek= $brak ? 'PROTOKÓŁ OCENY DOKUMENTACJI NIEARCHIWALNEJ' : 'SPIS ZDAWCZO-ODBIORCZY';
$podnag  = $brak ? '(przeznaczonej do brakowania)' : 'akt przekazanych do archiwum zakładowego';
$org     = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
$adres   = org_setting('org_adres');
$miejsc  = org_setting('org_miejscowosc') ?: org_setting('org_miasto');
$sumTecz = array_sum(array_map(fn($p)=>(int)($p['liczba_teczek'] ?: 1), $pozycje));
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<title><?= h(ezd_arch_spis_sygnatura($spis)) ?> — wydruk</title>
<style>
  *{box-sizing:border-box}
  body{font-family:"Times New Roman",Georgia,serif;color:#000;margin:0;padding:24px;font-size:12pt;line-height:1.4}
  .sheet{max-width:800px;margin:0 auto}
  .head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px}
  .org{font-weight:bold}
  .org small{font-weight:normal;font-size:10pt;color:#333}
  .meta{text-align:right;font-size:10pt}
  h1{text-align:center;font-size:15pt;margin:8px 0 2px}
  .sub{text-align:center;font-size:11pt;margin-bottom:6px}
  .syg{text-align:center;font-family:monospace;margin-bottom:18px}
  table{width:100%;border-collapse:collapse;margin-bottom:18px;font-size:10.5pt}
  th,td{border:1px solid #000;padding:4px 6px;vertical-align:top}
  th{background:#eee;text-align:left}
  td.c,th.c{text-align:center}
  .sum td{font-weight:bold;background:#f4f4f4}
  .info{font-size:10pt;margin-bottom:14px}
  .sig{display:flex;justify-content:space-between;margin-top:48px;gap:40px}
  .sig div{flex:1;text-align:center;border-top:1px solid #000;padding-top:4px;font-size:10pt}
  .toolbar{max-width:800px;margin:0 auto 16px;text-align:right}
  .btn{font-family:sans-serif;font-size:11pt;padding:6px 14px;border:1px solid #666;background:#f5f5f5;border-radius:4px;cursor:pointer;text-decoration:none;color:#000}
  @media print{.toolbar{display:none}body{padding:0}}
</style>
</head><body>
<div class="toolbar">
  <a href="#" class="btn" onclick="window.print();return false;">🖨 Drukuj</a>
  <a href="<?= APP_URL ?>/ezd/archiwum/spis_view.php?id=<?= $id ?>" class="btn">← Wróć</a>
</div>
<div class="sheet">
  <div class="head">
    <div class="org">
      <?= h($org) ?><br>
      <small><?= h($adres) ?></small>
    </div>
    <div class="meta">
      <?= h($miejsc) ?><?= $miejsc ? ', ' : '' ?><?= ezd_data_slownie() ?>
    </div>
  </div>

  <h1><?= h($naglowek) ?></h1>
  <div class="sub"><?= h($podnag) ?></div>
  <div class="syg">Sygnatura: <?= h(ezd_arch_spis_sygnatura($spis)) ?><?= $spis['tytul'] ? ' — ' . h($spis['tytul']) : '' ?></div>

  <div class="info">
    <?php if ($spis['komorka']): ?>Komórka organizacyjna przekazująca: <strong><?= h($spis['komorka']) ?></strong><br><?php endif; ?>
    <?php if ($brak && $spis['zgoda_ap']): ?>Zgoda archiwum państwowego nr: <strong><?= h($spis['zgoda_ap']) ?></strong><br><?php endif; ?>
  </div>

  <table>
    <thead>
      <tr>
        <th class="c" style="width:36px">Lp.</th>
        <th style="width:130px">Znak teczki</th>
        <th>Tytuł teczki aktowej</th>
        <th class="c" style="width:80px">Daty skrajne</th>
        <th class="c" style="width:60px">Kat. arch.</th>
        <th class="c" style="width:60px"><?= $brak ? 'Rok brak.' : 'Brak. po' ?></th>
        <th style="width:110px">Uwagi</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($pozycje as $p): ?>
      <tr>
        <td class="c"><?= (int)$p['lp'] ?></td>
        <td style="font-family:monospace"><?= h($p['znak']) ?></td>
        <td><?= h($p['tytul']) ?></td>
        <td class="c"><?= h(trim(($p['rok_od'] ?: '') . (($p['rok_do'] && $p['rok_do']!=$p['rok_od']) ? '–' . $p['rok_do'] : ''), '–')) ?: '—' ?></td>
        <td class="c"><?= h($p['kat_arch'] ?: '—') ?></td>
        <td class="c"><?= $p['rok_brakowania'] ? (int)$p['rok_brakowania'] : '—' ?></td>
        <td><?= h($p['uwagi']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr class="sum">
        <td colspan="2" class="c">Razem pozycji: <?= count($pozycje) ?></td>
        <td colspan="5">Łączna liczba teczek: <?= (int)$sumTecz ?></td>
      </tr>
    </tbody>
  </table>

  <div class="sig">
    <div>Przekazujący<br>(imię, nazwisko, podpis)</div>
    <div><?= $brak ? 'Komisja / Archiwista' : 'Przyjmujący (archiwista)' ?><br>(imię, nazwisko, podpis)</div>
    <div>Zatwierdził<br>(kierownik jednostki)</div>
  </div>
</div>
</body></html>
