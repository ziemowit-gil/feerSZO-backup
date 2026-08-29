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

// Zakres dat
$weeks  = max(1, min(26, (int)($_GET['weeks'] ?? 8)));
$from   = date('Y-m-d');
$to     = date('Y-m-d', strtotime("+{$weeks} weeks"));

// Dane prowadzącego
$instructor = db_one("SELECT id, name, email FROM users WHERE id=? AND is_active=1", [$target_uid]);
if (!$instructor) { http_response_code(404); die('Nie znaleziono prowadzącego.'); }

// Sesje w zakresie
$sessions = db_all("
    SELECT s.*, c.name AS course_name,
           GROUP_CONCAT(cl.name, ', ') AS student_names
    FROM k30_ti_sessions s
    JOIN k30_ti_courses c ON c.id = s.course_id
    LEFT JOIN k30_ti_attendance a ON a.session_id = s.id
    LEFT JOIN k30_clients cl ON cl.id = a.client_id
    WHERE c.instructor_id = ?
      AND s.lesson_date BETWEEN ? AND ?
      AND s.status NOT IN ('cancelled')
    GROUP BY s.id
    ORDER BY s.lesson_date, s.time_from
", [$target_uid, $from, $to]);

// Grupowanie po tygodniu
$by_week = [];
foreach ($sessions as $s) {
    $wd = (int)date('N', strtotime((string)$s['lesson_date'])); // 1=Pn … 7=Nd
    $week_start = date('Y-m-d', strtotime((string)$s['lesson_date'] . ' -' . ($wd - 1) . ' days'));
    $by_week[$week_start][] = $s;
}
ksort($by_week);

$days_pl  = [1=>'Poniedziałek',2=>'Wtorek',3=>'Środa',4=>'Czwartek',5=>'Piątek',6=>'Sobota',7=>'Niedziela'];
$months_pl = [1=>'sty',2=>'lut',3=>'mar',4=>'kwi',5=>'maj',6=>'cze',7=>'lip',8=>'sie',9=>'wrz',10=>'paź',11=>'lis',12=>'gru'];

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
  .course-name { font-weight: 600; }
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
  <label>Liczba tygodni:
    <select id="wkSel" onchange="location.href='plan_print.php?weeks='+this.value+'&instructor_id=<?= $target_uid ?>'">
      <?php foreach ([4,8,12,16,26] as $w): ?>
        <option value="<?= $w ?>"<?= $weeks == $w ? ' selected' : '' ?>><?= $w ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button onclick="window.print()"><i>🖨</i> Drukuj</button>
  <button onclick="window.close()">Zamknij</button>
</div>

<div class="print-header">
  <h1>Plan zajęć — <?= h($instructor['name']) ?></h1>
  <div class="meta">
    <?= h($org) ?><br>
    <?= h($from) ?> – <?= h($to) ?> (<?= $weeks ?> tyg.)<br>
    Wydruk: <?= date('d.m.Y H:i') ?>
  </div>
</div>

<div class="content">
<?php if (empty($by_week)): ?>
  <p style="color:#64748b;font-style:italic">Brak zajęć w wybranym okresie.</p>
<?php else: ?>
<?php foreach ($by_week as $week_start => $wsessions):
    $ws_ts = strtotime($week_start);
    $we_ts = strtotime($week_start . ' +6 days');
    $wlabel = date('j', $ws_ts) . ' ' . $months_pl[(int)date('n', $ws_ts)]
            . ' – ' . date('j', $we_ts) . ' ' . $months_pl[(int)date('n', $we_ts)]
            . ' ' . date('Y', $ws_ts);
?>
  <div class="week-header">Tydzień <?= h($wlabel) ?></div>
  <table>
    <thead>
      <tr>
        <th>Dzień</th>
        <th>Godziny</th>
        <th>Kurs</th>
        <th>Uczestnicy</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($wsessions as $s):
        $wd = (int)date('N', strtotime((string)$s['lesson_date']));
        $day_label = $days_pl[$wd] . ', ' . date('j', strtotime((string)$s['lesson_date'])) . ' ' . $months_pl[(int)date('n', strtotime((string)$s['lesson_date']))];
        $time_label = ($s['time_from'] && $s['time_to']) ? h($s['time_from']) . '–' . h($s['time_to']) : '–';
        $st_class = match((string)$s['status']) {
            'held', 'individual_change' => 'st-held',
            'remote_material' => 'st-remote',
            default => 'st-planned',
        };
        $st_label = K30_TI_SESSION_STATUSES[(string)$s['status']]['label'] ?? h($s['status']);
        $is_today = ((string)$s['lesson_date'] === $today);
    ?>
      <tr<?= $is_today ? ' style="background:#fffbeb"' : '' ?>>
        <td class="day-col"><?= h($day_label) ?></td>
        <td class="time-col"><?= $time_label ?></td>
        <td>
          <div class="course-name"><?= h($s['course_name']) ?></div>
          <?php if ($s['topic']): ?><div style="font-size:9pt;color:#64748b"><?= h($s['topic']) ?></div><?php endif; ?>
        </td>
        <td class="students"><?= h($s['student_names'] ?? '–') ?></td>
        <td><span class="status-badge <?= $st_class ?>"><?= $st_label ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>
<?php endif; ?>
</div>
</body>
</html>
