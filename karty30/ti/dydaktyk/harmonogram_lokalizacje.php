<?php
/**
 * karty30/ti/dydaktyk/harmonogram_lokalizacje.php — Tabela: Grupa | Dzień i Godziny | Lokalizacja.
 *
 * Zestawienie wszystkich grup stacjonarnych z ich wzorcem spotkań (dzień
 * tygodnia + godziny) i przypisaną salą/lokalizacją — do szybkiego podglądu,
 * druku lub skopiowania jako Markdown. Widok kierownika (ekran zbiorczy,
 * poza kontekstem jednego kursu).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
karty30_migrate();
ti_planner_ext_migrate();

$weeks = max(1, min(26, (int)($_GET['weeks'] ?? 8)));
$rows  = ti_group_location_summary($weeks);
ti_print_log_add('harmonogram_lokalizacje', 'Harmonogram grup — dzień/godziny/lokalizacja', 0, 0, ['weeks' => $weeks], $me);

$org = defined('APP_ORG') ? APP_ORG : '';
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Harmonogram grup — dzień, godziny, lokalizacja</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; color: #111; margin: 0; padding: 0; }
  .print-header { background: #1e293b; color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: flex-end; }
  .print-header h1 { margin: 0; font-size: 15pt; font-weight: 700; }
  .print-header .meta { font-size: 9pt; opacity: .75; text-align: right; }
  .controls { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 8px 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
  .controls select, .controls button { font-size: 10pt; padding: 3px 8px; border: 1px solid #cbd5e1; border-radius: 4px; }
  .controls button { background: #1e293b; color: #fff; cursor: pointer; border-color: #1e293b; }
  .content { padding: 16px 20px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #f8fafc; border-bottom: 2px solid #e2e8f0; padding: 6px 10px; text-align: left; font-size: 9pt; color: #475569; }
  td { border-bottom: 1px solid #e2e8f0; padding: 6px 10px; font-size: 10.5pt; }
  tr:last-child td { border-bottom: none; }
  .no-loc { color: #94a3b8; font-style: italic; }
  .page-footer { color: #94a3b8; font-size: 8pt; padding: 10px 20px 16px; border-top: 1px solid #e2e8f0; margin-top: 8px; }
  #mdOut { width: 100%; height: 220px; font-family: ui-monospace, Consolas, monospace; font-size: 9.5pt; margin-top: 8px; }
  @media print {
    .controls, #mdWrap { display: none; }
    .print-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { margin: 12mm; }
  }
</style>
</head>
<body>

<div class="controls">
  <label>Zakres:
    <select onchange="location.href='harmonogram_lokalizacje.php?weeks='+this.value">
      <?php foreach ([4,8,12,16,26] as $w): ?>
        <option value="<?= $w ?>" <?= $weeks === $w ? 'selected' : '' ?>><?= $w ?> tyg.</option>
      <?php endforeach; ?>
    </select>
  </label>
  <button onclick="window.print()">🖨 Drukuj</button>
  <button onclick="toggleMd()">Kopiuj jako Markdown</button>
  <button onclick="window.close()">Zamknij</button>
</div>

<div class="print-header">
  <h1>Harmonogram grup — dzień, godziny, lokalizacja</h1>
  <div class="meta"><?= h($org) ?><br>Grupy z zajęciami w ciągu najbliższych <?= $weeks ?> tyg.</div>
</div>

<div class="content">
<div id="mdWrap" style="display:none">
  <textarea id="mdOut" readonly onclick="this.select()"></textarea>
</div>
<table id="tbl">
  <thead><tr><th>Grupa</th><th>Dzień i godziny</th><th>Lokalizacja</th></tr></thead>
  <tbody>
    <?php if (!$rows): ?>
    <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:20px 0">Brak grup z zajęciami stacjonarnymi w wybranym okresie.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= h($r['course_name']) ?><?= $r['group_code'] !== '' ? ' <span style="color:#94a3b8">(' . h($r['group_code']) . ')</span>' : '' ?></td>
      <td><?= h($r['day_label']) ?>, <?= h($r['time_from']) ?>–<?= h($r['time_to']) ?></td>
      <td<?= $r['location'] === '—' ? ' class="no-loc"' : '' ?>><?= h($r['location']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="page-footer">Wygenerowano: <?= date('d.m.Y H:i') ?> przez <?= h($me['name'] ?? '') ?></div>

<script>
function toggleMd() {
  var wrap = document.getElementById('mdWrap');
  if (wrap.style.display === 'none') {
    var rows = document.querySelectorAll('#tbl tbody tr');
    var lines = ['| Grupa | Dzień i Godziny | Lokalizacja |', '|---|---|---|'];
    rows.forEach(function(tr) {
      var tds = tr.querySelectorAll('td');
      if (tds.length === 3) {
        lines.push('| ' + tds[0].textContent.trim() + ' | ' + tds[1].textContent.trim() + ' | ' + tds[2].textContent.trim() + ' |');
      }
    });
    document.getElementById('mdOut').value = lines.join('\n');
    wrap.style.display = '';
    document.getElementById('mdOut').select();
  } else {
    wrap.style.display = 'none';
  }
}
</script>
</body>
</html>
