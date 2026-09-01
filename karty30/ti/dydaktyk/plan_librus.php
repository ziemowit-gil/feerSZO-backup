<?php
/**
 * karty30/ti/dydaktyk/plan_librus.php — Plan zajęć grupy w formacie zbliżonym do Librusa.
 *
 * Siatka dzień×godzina (jak „Plan lekcji" w Librusie): wiersze to godziny
 * zajęć grupy, kolumny to dni tygodnia, komórka zawiera przedmiot/grupę,
 * prowadzącego i salę/lokalizację. Dostępny dla kierownika (dowolna grupa)
 * lub prowadzącego tej grupy — patrz dyd_owns_course().
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_room_reports.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_reschedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
karty30_migrate();
ti_planner_ext_migrate();
k30_ti_reschedule_migrate();

$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id || !dyd_owns_course($uid, $course_id)) {
    http_response_code(403); die('Brak dostępu do planu tej grupy.');
}

$weeks = max(1, min(52, (int)($_GET['weeks'] ?? 12)));
$L = ti_librus_grid($course_id, $weeks);
if (!$L['course']) { http_response_code(404); die('Nie znaleziono grupy.'); }
$course = $L['course'];

ti_print_log_add('plan_librus', 'Plan zajęć (siatka) — ' . $course['name'], $course_id, 0, ['weeks' => $weeks], $me);

$dow_cols = [1,2,3,4,5,6,7];
$dow_lbl  = TI_DAYS_PL_FULL;
$org = ti_org_contact_info();
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Plan zajęć — <?= h($course['name']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 10.5pt; color: #111; margin: 0; padding: 0; }
  .print-header { background: #1e293b; color: #fff; padding: 12px 20px; display: flex; justify-content: space-between; align-items: flex-end; }
  .print-header h1 { margin: 0; font-size: 15pt; font-weight: 700; }
  .print-header .meta { font-size: 9pt; opacity: .75; text-align: right; }
  .controls { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 8px 20px; display: flex; gap: 12px; align-items: center; }
  .controls select, .controls button { font-size: 10pt; padding: 3px 8px; border: 1px solid #cbd5e1; border-radius: 4px; }
  .controls button { background: #1e293b; color: #fff; cursor: pointer; border-color: #1e293b; }
  .content { padding: 16px 20px; }
  table.librus { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.librus th, table.librus td { border: 1px solid #cbd5e1; padding: 4px 6px; vertical-align: top; }
  table.librus thead th { background: #f1f5f9; font-size: 9pt; color: #334155; text-align: center; }
  table.librus .time-col { width: 78px; background: #f8fafc; font-weight: 600; font-size: 9pt; text-align: center; white-space: nowrap; }
  .lb-cell .subject   { font-weight: 700; font-size: 10pt; }
  .lb-cell .instr     { color: #334155; font-size: 9pt; }
  .lb-cell .room      { color: #0f766e; font-size: 9pt; }
  .lb-cell .valid     { color: #b45309; font-size: 7.5pt; margin-top: 2px; }
  .lb-cell .flag      { display: inline-block; font-size: 7.5pt; font-weight: 700; margin-top: 2px; padding: 1px 5px; border-radius: 3px; }
  .lb-cell .flag-tentative { color: #92400e; background: #fef3c7; }
  .lb-cell .flag-change    { color: #075985; background: #e0f2fe; }
  .lb-empty { color: #cbd5e1; }
  .info-cols { display: flex; gap: 24px; margin-top: 18px; }
  .info-col { flex: 1; min-width: 0; }
  .info-col h2 { font-size: 10.5pt; color: #334155; margin: 0 0 6px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
  .info-col ul { margin: 0; padding-left: 18px; }
  .info-col li { font-size: 9.5pt; margin-bottom: 4px; }
  .contact-name { font-weight: 600; }
  .contact-line { color: #475569; font-size: 8.5pt; }
  .arrow { color: #94a3b8; margin: 0 4px; }
  .room { color: #0f766e; }
  .page-footer { color: #94a3b8; font-size: 8pt; padding: 10px 20px 16px; border-top: 1px solid #e2e8f0; margin-top: 8px; }
  @media (max-width: 700px) { .info-cols { flex-direction: column; gap: 12px; } }
  @media print {
    .controls { display: none; }
    .print-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.librus thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.librus .time-col { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { size: A4 landscape; margin: 10mm; }
  }
</style>
</head>
<body>

<div class="controls">
  <label>Zakres:
    <select onchange="location.href='plan_librus.php?course_id=<?= $course_id ?>&weeks='+this.value">
      <?php foreach ([4,8,12,16,26,52] as $w): ?>
        <option value="<?= $w ?>" <?= $weeks === $w ? 'selected' : '' ?>><?= $w ?> tyg.</option>
      <?php endforeach; ?>
    </select>
  </label>
  <button onclick="window.print()">🖨 Drukuj</button>
  <a href="plan_librus_pdf.php?course_id=<?= $course_id ?>&weeks=<?= $weeks ?>" target="_blank" style="text-decoration:none">
    <button type="button">📄 PDF</button>
  </a>
  <button onclick="window.close()">Zamknij</button>
</div>

<div class="print-header">
  <h1>Plan zajęć — <?= h($course['name']) ?></h1>
  <div class="meta">
    <?php if ($org['name'] !== ''): ?><strong><?= h($org['name']) ?></strong><br><?php endif; ?>
    <?php if ($org['address'] !== ''): ?><?= h($org['address']) ?><br><?php endif; ?>
    <?php $_oc = array_filter([$org['phone'], $org['email']]); if ($_oc): ?><?= h(implode(' · ', $_oc)) ?><br><?php endif; ?>
    Prowadzący: <?= h($course['instructor_name'] ?? '—') ?>
  </div>
</div>

<div class="content">
<?php if (!$L['time_slots']): ?>
  <p style="color:#64748b;font-style:italic">Brak zaplanowanych terminów w wybranym okresie.</p>
<?php else: ?>
  <table class="librus">
    <thead>
      <tr>
        <th class="time-col">Godzina</th>
        <?php foreach ($dow_cols as $d): ?><th><?= h($dow_lbl[$d]) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($L['time_slots'] as $tk): ?>
      <tr>
        <td class="time-col"><?= h($tk) ?></td>
        <?php foreach ($dow_cols as $d):
          $cell = $L['grid'][$tk][$d] ?? null;
        ?>
        <td>
          <?php if ($cell): ?>
          <div class="lb-cell">
            <div class="subject"><?= h($cell['subject']) ?></div>
            <div class="instr"><?= h($cell['instructor']) ?></div>
            <div class="room"><?= h($cell['room']) ?></div>
            <?php if ($L['multi_slot']): ?>
            <div class="valid">obowiązuje: <?= h(date('d.m.Y', strtotime($cell['valid_from']))) ?>–<?= h(date('d.m.Y', strtotime($cell['valid_to']))) ?></div>
            <?php endif; ?>
            <?php if (($cell['date_flag'] ?? '') === 'change_possible'): ?>
            <div class="flag flag-change">Możliwa zmiana terminu</div>
            <?php elseif (($cell['date_flag'] ?? '') === 'tentative'): ?>
            <div class="flag flag-tentative">Termin niepewny</div>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <span class="lb-empty">—</span>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php
  $_has_flags = false;
  foreach ($L['grid'] ?? [] as $_row) foreach ($_row as $_c) if (($_c['date_flag'] ?? '') !== '') { $_has_flags = true; break 2; }
?>
<div class="info-cols">
  <div class="info-col">
    <h2>Kontakty do prowadzących</h2>
    <?php if (!$L['instructors']): ?>
    <p style="color:#94a3b8;font-size:9pt">Brak przypisanego prowadzącego.</p>
    <?php else: ?>
    <ul>
      <?php foreach ($L['instructors'] as $ins): ?>
      <li>
        <span class="contact-name"><?= h($ins['name']) ?></span><br>
        <?php $_ic = array_filter([$ins['phone'], $ins['email']]); ?>
        <?php if ($_ic): ?><span class="contact-line"><?= h(implode(' · ', $_ic)) ?></span>
        <?php else: ?><span class="contact-line">brak danych kontaktowych w systemie</span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <div class="info-col">
    <h2>Uwagi</h2>
    <?php if (!$L['multi_slot'] && !$_has_flags && !$L['exceptions']): ?>
    <p style="color:#94a3b8;font-size:9pt">Brak uwag do wybranego okresu.</p>
    <?php else: ?>
    <ul>
      <?php if ($L['multi_slot']): ?>
      <li>Harmonogram tej grupy zmienia się w wybranym okresie — przy każdym terminie podano zakres dat, w którym obowiązuje.</li>
      <?php endif; ?>
      <?php if ($_has_flags): ?>
      <li>Etykiety „Termin niepewny" / „Możliwa zmiana terminu" przy niektórych terminach — dotyczą co najmniej jednej daty w danym slocie.</li>
      <?php endif; ?>
      <?php foreach ($L['exceptions'] as $ex): ?>
      <li>Zmiana terminu: <?= h($ex['from_label']) ?><span class="arrow">→</span><strong><?= h($ex['to_label']) ?></strong><?php if ($ex['room'] !== '—'): ?>, <span class="room"><?= h($ex['room']) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
</div>
<div class="page-footer">Wygenerowano: <?= date('d.m.Y H:i') ?> przez <?= h($me['name'] ?? '') ?></div>
</body>
</html>
