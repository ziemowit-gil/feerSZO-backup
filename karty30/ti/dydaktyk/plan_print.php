<?php
/**
 * karty30/ti/dydaktyk/plan_print.php — Wydruk planu zajęć prowadzącego.
 * Dostępny dla zalogowanego dydaktyka (swój plan) lub admina D3.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_leaves.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me       = dyd_require();
$uid      = (int)$me['user_id'];
$is_staff = dyd_is_staff();

// Admin może oglądać plan dowolnego prowadzącego
$target_uid = $uid;
if ($is_staff && isset($_GET['instructor_id'])) {
    $target_uid = max(1, (int)$_GET['instructor_id']);
}

// Zakres dat + dane prowadzącego/sesji, grupowane dzień-tygodnia+godzina → daty
// (wspólne z plan_pdf.php/plan_docx.php)
$weeks_req = (int)($_GET['weeks'] ?? 8);
$PD = ti_instructor_plan_grouped($target_uid, $weeks_req);
$instructor = $PD['instructor'];
if (!$instructor) { http_response_code(404); die('Nie znaleziono prowadzącego.'); }
$from   = $PD['from'];
$to     = $PD['to'];
$weeks  = $PD['weeks'];
$groups = $PD['groups'];

$today = date('Y-m-d');
$org   = defined('APP_ORG') ? APP_ORG : '';
ti_print_log_add('plan_print', 'Plan zajęć — ' . ($instructor['name'] ?? ''), 0, 0, ['weeks' => $weeks], $me);
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Plan zajęć — <?= h($instructor['name']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11pt; color: #111; margin: 0; padding: 0; }
  .print-header { background: #1e293b; color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: flex-end; }
  .print-header h1 { margin: 0; font-size: 15pt; font-weight: 700; }
  .print-header .meta { font-size: 9pt; opacity: .75; text-align: right; }
  .content { padding: 16px 20px; }
  .week-header { background: #f1f5f9; border-left: 4px solid #3b82f6; padding: 5px 10px; margin: 18px 0 6px; font-weight: 700; font-size: 10.5pt; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  th { background: #f8fafc; border-bottom: 2px solid #e2e8f0; padding: 4px 8px; text-align: left; font-size: 9pt; color: #475569; }
  td { border-bottom: 1px solid #e2e8f0; padding: 5px 8px; vertical-align: top; font-size: 10pt; }
  tr:last-child td { border-bottom: none; }
  .day-col { width: 80px; white-space: nowrap; font-weight: 600; color: #334155; }
  .time-col { width: 80px; white-space: nowrap; color: #475569; }
  .status-badge { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 8pt; font-weight: 600; }
  .st-planned  { background: #dbeafe; color: #1d4ed8; }
  .st-held     { background: #dcfce7; color: #166534; }
  .st-remote   { background: #f3e8ff; color: #7e22ce; }
  .students    { font-size: 9pt; color: #64748b; }
  .no-sessions { color: #94a3b8; font-style: italic; font-size: 10pt; padding: 6px 8px; }
  .controls { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 8px 20px; display: flex; gap: 12px; align-items: center; }
  .controls label { font-size: 10pt; }
  .controls select, .controls button { font-size: 10pt; padding: 3px 8px; border: 1px solid #cbd5e1; border-radius: 4px; }
  .controls button { background: #1e293b; color: #fff; cursor: pointer; border-color: #1e293b; }
  @media print {
    .controls { display: none; }
    .print-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .week-header  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .status-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-size: 10pt; }
    .week-header { break-after: avoid; }
    tr { break-inside: avoid; }
  }
</style>
</head>
<body>

<div class="controls">
  <label>Zakres:
    <select id="wkSel" onchange="location.href='plan_print.php?weeks='+this.value+'&instructor_id=<?= $target_uid ?>'">
      <?php foreach ([4,8,12,16,26] as $w): ?>
        <option value="<?= $w ?>"<?= (!$PD['unbounded'] && $weeks == $w) ? ' selected' : '' ?>><?= $w ?> tyg.</option>
      <?php endforeach; ?>
      <option value="0"<?= $PD['unbounded'] ? ' selected' : '' ?>>Ogólny (bez limitu tygodni)</option>
    </select>
  </label>
  <button onclick="window.print()"><i>🖨</i> Drukuj</button>
  <a href="plan_pdf.php?weeks=<?= $weeks ?>&instructor_id=<?= $target_uid ?>" style="text-decoration:none">
    <button type="button">Pobierz PDF</button>
  </a>
  <a href="plan_docx.php?weeks=<?= $weeks ?>&instructor_id=<?= $target_uid ?>" style="text-decoration:none">
    <button type="button">Pobierz DOCX</button>
  </a>
  <button onclick="window.close()">Zamknij</button>
</div>

<div class="print-header">
  <h1>Plan zajęć — <?= h($instructor['name']) ?></h1>
  <div class="meta">
    <?= h($org) ?><br>
    <?= $PD['unbounded'] ? 'Ogólny — od dziś, bez ograniczenia końcowego' : h($from) . ' – ' . h($to) . ' (' . $weeks . ' tyg.)' ?><br>
    Wydruk: <?= date('d.m.Y H:i') ?> przez <?= h($me['name'] ?? '') ?>
  </div>
</div>

<div class="content">
<?php if (empty($groups)): ?>
  <p style="color:#64748b;font-style:italic">Brak zajęć w wybranym okresie.</p>
<?php endif; ?>
<?php foreach ($groups as $g):
    $time_label = ($g['time_from'] && $g['time_to']) ? substr((string)$g['time_from'],0,5) . '–' . substr((string)$g['time_to'],0,5) : '—';
?>
  <div class="week-header"><?= h($g['day_label']) ?>, <?= h($time_label) ?> · <?= h($g['course_name']) ?></div>
  <table>
    <thead>
      <tr>
        <th>Data</th>
        <th>Uczestnicy</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($g['dates'] as $d):
        $st_class = match($d['status']) {
            'held', 'individual_change' => 'st-held',
            'remote_material' => 'st-remote',
            default => 'st-planned',
        };
        $st_label = K30_TI_SESSION_STATUSES[$d['status']]['label'] ?? h($d['status']);
        $is_today = ($d['date'] === $today);
        $dts = strtotime($d['date']);
    ?>
      <tr<?= $is_today ? ' style="background:#fffbeb"' : '' ?>>
        <td class="day-col"><?= h(date('d.m.Y', $dts)) ?></td>
        <td class="students"><?= h($d['student_names'] !== '' ? $d['student_names'] : '–') ?></td>
        <td><span class="status-badge <?= $st_class ?>"><?= $st_label ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>
</div>
</body>
</html>
