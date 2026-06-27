<?php
/**
 * karty30/clients/card_print.php — Karta Beneficjenta (wydruk / zapis PDF).
 *
 * Tryb admin:   ?id=X    — wymaga uprawnień karty30
 * Tryb kursanta: ?kursant=1 — wymaga aktywnej sesji kursanta
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

$is_kursant_mode = !empty($_GET['kursant']);

if ($is_kursant_mode) {
    require_once __DIR__ . '/../ti/kursant/auth.php';
    karty30_migrate();
    $student   = student_require();
    $client_id = (int)$student['client_id'];
} else {
    k30_require_access();
    karty30_migrate();
    $client_id = (int)($_GET['id'] ?? 0);
    if (!$client_id) { header('Location: index.php'); exit; }
}

$client = db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]);
if (!$client) {
    if ($is_kursant_mode) { header('Location: index.php'); exit; }
    flash_set('danger', 'Beneficjent nie istnieje.');
    header('Location: index.php'); exit;
}

// RODO: rejestr wydruku karty beneficjenta (tylko dostęp personelu)
if (!$is_kursant_mode) k30_log_access('client', $client_id, 'print', $client['name'] ?? '');

// Dane TI
$courses       = k30_ti_client_courses($client_id);
$active_courses = array_filter($courses, fn($c) => ($c['status'] ?? '') === 'active');

// PFRON
$pfron_contracts = k30_pfron_contracts_for_client($client_id);

// Konsultacje (ostatnie 10)
$consultations = db_all(
    "SELECT co.*, u.name AS consultant_name
     FROM k30_consultations co LEFT JOIN users u ON u.id=co.consultant_id
     WHERE co.client_id=? ORDER BY co.consultation_datetime DESC LIMIT 10",
    [$client_id]
);

// Terminy (ostatnie 10 zrealizowanych)
$schedules = db_all(
    "SELECT s.*, u.name AS consultant_name
     FROM k30_schedules s LEFT JOIN users u ON u.id=s.assigned_to
     WHERE s.client_id=? AND s.status IN ('completed','confirmed')
     ORDER BY s.start_time DESC LIMIT 10",
    [$client_id]
);

$remaining  = max(0, (float)$client['available_hours'] - (float)$client['used']);
$pct_used   = ($client['available_hours'] > 0) ? min(100, round($client['used'] / $client['available_hours'] * 100)) : 0;
$org        = defined('ORG_NAME') ? ORG_NAME : 'FEER';
$generated  = date('d.m.Y H:i');

$gender_labels = ['male' => 'Mężczyzna', 'female' => 'Kobieta', 'other' => 'Inne'];
$status_labels = [
    'new' => 'Nowy', 'enrolled' => 'Zapisany', 'learning' => 'W trakcie nauki',
    'completed' => 'Ukończono', 'graduated' => 'Absolwent', 'resigned' => 'Zrezygnował',
];
$day_labels = ['mon'=>'Pn','tue'=>'Wt','wed'=>'Śr','thu'=>'Cz','fri'=>'Pt','sat'=>'So','sun'=>'Nd'];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Karta Beneficjenta — <?= h($client['name']) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box}
@page{size:A4 portrait;margin:18mm 16mm 14mm 16mm}
html,body{margin:0;padding:0;font-family:'Segoe UI',Arial,sans-serif;font-size:10.5pt;color:#1a1a1a;background:#fff}

/* ── Toolbar (tylko ekran, ukryty w druku) ─── */
.print-toolbar{
  position:sticky;top:0;z-index:100;
  background:#1e3a5f;color:#fff;
  display:flex;align-items:center;gap:.75rem;
  padding:.6rem 1.2rem;
  print-color-adjust:exact;
}
.print-toolbar a,.print-toolbar button{
  background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
  color:#fff;padding:.3rem .85rem;border-radius:6px;
  text-decoration:none;font-size:.82rem;cursor:pointer;
  display:inline-flex;align-items:center;gap:.35rem;
}
.print-toolbar a:hover,.print-toolbar button:hover{background:rgba(255,255,255,.25)}
.print-toolbar .tb-title{font-size:.9rem;font-weight:700;margin-right:auto}

/* ── Karta ─────────────────────────────────── */
.karta{max-width:780px;margin:1.5rem auto;padding:1.5rem;background:#fff}

/* ── Nagłówek ──────────────────────────────── */
.k-head{display:flex;align-items:flex-start;justify-content:space-between;
  border-bottom:3px solid #1e3a5f;padding-bottom:1rem;margin-bottom:1.25rem}
.k-org{font-size:.8rem;color:#555;margin-bottom:.15rem}
.k-title{font-size:1.15rem;font-weight:800;color:#1e3a5f;letter-spacing:.03em;text-transform:uppercase}
.k-id{font-size:.72rem;color:#888;margin-top:.2rem}
.k-logo-placeholder{width:60px;height:60px;border-radius:10px;background:#1e3a5f;
  display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.6rem}

/* ── Imię i status ─────────────────────────── */
.k-name{font-size:1.4rem;font-weight:800;color:#111;margin:0 0 .25rem}
.k-status{display:inline-block;padding:.18rem .65rem;border-radius:999px;font-size:.75rem;
  font-weight:700;background:#dbeafe;color:#1e3a5f;border:1px solid #93c5fd}
.k-status.graduated{background:#dcfce7;color:#166534;border-color:#86efac}
.k-status.resigned{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.k-status.learning{background:#fef9c3;color:#713f12;border-color:#fde047}

/* ── Sekcje ────────────────────────────────── */
.k-section{margin-bottom:1.2rem}
.k-section-title{
  font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
  color:#1e3a5f;border-bottom:1.5px solid #1e3a5f20;padding-bottom:.3rem;margin-bottom:.6rem;
  display:flex;align-items:center;gap:.4rem;
}
.k-section-title svg{width:13px;height:13px;fill:currentColor}

/* ── Grid 2 kol ─────────────────────────────── */
.k-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.k-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.8rem}

/* ── Pole danych ───────────────────────────── */
.k-field{margin-bottom:.45rem}
.k-field label{display:block;font-size:.69rem;color:#666;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.05rem}
.k-field span{font-size:.9rem;color:#111}

/* ── Godziny ───────────────────────────────── */
.k-hours{display:flex;align-items:center;gap:1rem;background:#f0f7ff;
  border:1px solid #bfdbfe;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem}
.k-hours-num{font-size:2rem;font-weight:900;color:#1e40af;line-height:1}
.k-hours-label{font-size:.72rem;color:#666;margin-top:.1rem}
.k-hours-bar{flex:1}
.k-hours-bar-track{height:8px;background:#dbeafe;border-radius:4px;overflow:hidden;margin-top:.3rem}
.k-hours-bar-fill{height:8px;border-radius:4px;background:#1e3a5f}

/* ── Tabela ────────────────────────────────── */
.k-table{width:100%;border-collapse:collapse;font-size:.83rem;margin-bottom:.5rem}
.k-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;
  color:#555;font-weight:700;padding:.35rem .5rem;border-bottom:1.5px solid #e5e7eb}
.k-table td{padding:.35rem .5rem;border-bottom:1px solid #f1f5f9;vertical-align:top}
.k-table tr:last-child td{border-bottom:none}
.k-table .mono{font-family:monospace;font-size:.8rem}

/* ── Kursy badge ───────────────────────────── */
.k-badge{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.7rem;font-weight:700}
.k-badge.active{background:#dcfce7;color:#166534}
.k-badge.inactive{background:#f1f5f9;color:#475569}

/* ── Stopka ────────────────────────────────── */
.k-footer{
  border-top:1.5px solid #e5e7eb;margin-top:1.5rem;padding-top:.75rem;
  display:flex;justify-content:space-between;align-items:flex-end;
  font-size:.72rem;color:#888;
}
.k-sign{width:180px;border-top:1px solid #aaa;padding-top:.25rem;text-align:center;font-size:.69rem;color:#666}

/* ── Druk ──────────────────────────────────── */
@media print{
  .print-toolbar{display:none!important}
  .karta{margin:0;padding:0;max-width:100%}
  @page{margin:15mm 14mm 12mm 14mm}
}
</style>
</head>
<body>

<!-- Toolbar (tylko ekran) -->
<div class="print-toolbar" aria-hidden="true">
  <span class="tb-title">Karta Beneficjenta — <?= h($client['name']) ?></span>
  <button onclick="window.print()">&#128438; Drukuj / Zapisz PDF</button>
  <?php if ($is_kursant_mode): ?>
  <a href="index.php?tab=dane">&#8592; Wróć do panelu</a>
  <?php else: ?>
  <a href="view.php?id=<?= $client_id ?>">&#8592; Wróć do karty</a>
  <?php endif; ?>
</div>

<div class="karta" role="main">

  <!-- Nagłówek -->
  <div class="k-head">
    <div>
      <div class="k-org"><?= h($org) ?></div>
      <div class="k-title">Karta Beneficjenta</div>
      <div class="k-id">Wygenerowano: <?= $generated ?></div>
    </div>
    <div class="k-logo-placeholder" aria-hidden="true">&#128100;</div>
  </div>

  <!-- Imię, status -->
  <div style="margin-bottom:1rem">
    <div class="k-name"><?= h($client['name']) ?></div>
    <?php
    $st = $client['status'] ?? 'new';
    $stc = in_array($st, ['graduated','resigned','learning']) ? $st : '';
    ?>
    <span class="k-status <?= $stc ?>"><?= h($status_labels[$st] ?? $st) ?></span>
    <?php if (!empty($client['consent'])): ?>
    <span class="k-status" style="margin-left:.4rem;background:#f0fdf4;color:#166534;border-color:#86efac">&#10003; Zgoda RODO</span>
    <?php endif; ?>
  </div>

  <!-- Godziny -->
  <div class="k-hours">
    <div>
      <div class="k-hours-num"><?= number_format($remaining, 1, ',', '') ?></div>
      <div class="k-hours-label">godzin pozostało</div>
    </div>
    <div class="k-hours-bar">
      <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#555;margin-bottom:.1rem">
        <span>Wykorzystano: <?= number_format((float)$client['used'], 1, ',', '') ?> h</span>
        <span>Limit: <?= number_format((float)$client['available_hours'], 1, ',', '') ?> h</span>
      </div>
      <div class="k-hours-bar-track">
        <div class="k-hours-bar-fill" style="width:<?= $pct_used ?>%"></div>
      </div>
      <div style="font-size:.69rem;color:#888;margin-top:.2rem;text-align:right"><?= $pct_used ?>% wykorzystane</div>
    </div>
  </div>

  <div class="k-grid">
    <!-- Dane osobowe -->
    <div class="k-section">
      <div class="k-section-title">Dane osobowe</div>
      <div class="k-field"><label>Imię i nazwisko</label><span><?= h($client['name']) ?></span></div>
      <?php if ($client['date_of_birth']): ?>
      <div class="k-field"><label>Data urodzenia</label><span><?= date('d.m.Y', strtotime($client['date_of_birth'])) ?></span></div>
      <?php endif; ?>
      <?php if ($client['gender']): ?>
      <div class="k-field"><label>Płeć</label><span><?= $gender_labels[$client['gender']] ?? '—' ?></span></div>
      <?php endif; ?>
      <?php if ($client['email']): ?>
      <div class="k-field"><label>E-mail</label><span><?= h($client['email']) ?></span></div>
      <?php endif; ?>
      <?php if ($client['phone']): ?>
      <div class="k-field"><label>Telefon</label><span><?= h($client['phone']) ?></span></div>
      <?php endif; ?>
      <?php if ($client['address']): ?>
      <div class="k-field"><label>Adres</label><span><?= h($client['address']) ?></span></div>
      <?php endif; ?>
    </div>

    <!-- Problem / potrzeba -->
    <div class="k-section">
      <div class="k-section-title">Cel wsparcia</div>
      <?php if ($client['problem']): ?>
      <div style="font-size:.85rem;color:#333;line-height:1.55"><?= nl2br(h(mb_substr($client['problem'], 0, 400))) ?></div>
      <?php else: ?>
      <div style="color:#aaa;font-size:.83rem">—</div>
      <?php endif; ?>
      <?php if ($client['equipment']): ?>
      <div style="margin-top:.6rem">
        <div class="k-section-title" style="border:0;padding:0;margin-bottom:.3rem">Sprzęt</div>
        <div style="font-size:.83rem;color:#555"><?= nl2br(h(mb_substr($client['equipment'], 0, 200))) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Kursy TI -->
  <?php if ($courses): ?>
  <div class="k-section">
    <div class="k-section-title">Zapisy na kursy (TI)</div>
    <table class="k-table">
      <thead><tr><th>Kurs</th><th>Dzień / czas</th><th>Prowadzący</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($courses as $c): ?>
        <tr>
          <td style="font-weight:600"><?= h($c['course_name']) ?></td>
          <td><?= isset($day_labels[$c['day_of_week']]) ? $day_labels[$c['day_of_week']] : '—' ?>
              <?= ($c['time_from'] && $c['time_to']) ? h(substr($c['time_from'],0,5)).'–'.h(substr($c['time_to'],0,5)) : '' ?></td>
          <td><?= $c['instructor_name'] ? h($c['instructor_name']) : '—' ?></td>
          <td><span class="k-badge <?= ($c['status']??'')=='active'?'active':'inactive' ?>"><?= h($c['status'] ?? '—') ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Umowy PFRON -->
  <?php if ($pfron_contracts): ?>
  <div class="k-section">
    <div class="k-section-title">Umowy PFRON</div>
    <table class="k-table">
      <thead><tr><th>Numer umowy</th><th>Limit</th><th>Wykorzystano</th><th>Pozostało</th><th>Ważność</th></tr></thead>
      <tbody>
        <?php foreach ($pfron_contracts as $pc):
          $rem_pc = max(0, (float)$pc['hours_limit'] - (float)$pc['hours_used']);
        ?>
        <tr>
          <td class="mono"><?= h($pc['contract_number']) ?></td>
          <td><?= number_format((float)$pc['hours_limit'], 2, ',', '') ?> h</td>
          <td><?= number_format((float)$pc['hours_used'], 2, ',', '') ?> h</td>
          <td style="<?= $rem_pc<=0?'color:#dc2626;font-weight:700':'' ?>"><?= number_format($rem_pc, 2, ',', '') ?> h</td>
          <td><?= $pc['valid_from'] ? h($pc['valid_from']) : '' ?><?= $pc['valid_to'] ? ' – '.h($pc['valid_to']) : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Historia konsultacji -->
  <?php if ($consultations): ?>
  <div class="k-section">
    <div class="k-section-title">Historia konsultacji</div>
    <table class="k-table">
      <thead><tr><th>Data</th><th>Czas</th><th>Konsultant</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($consultations as $co): ?>
        <tr>
          <td><?= date('d.m.Y', strtotime($co['consultation_datetime'])) ?></td>
          <td><?= $co['duration_minutes'] ? (int)$co['duration_minutes'].' min' : '—' ?></td>
          <td><?= $co['consultant_name'] ? h($co['consultant_name']) : '—' ?></td>
          <td><?= h($co['status'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Historia terminów -->
  <?php if ($schedules): ?>
  <div class="k-section">
    <div class="k-section-title">Historia terminów harmonogramu</div>
    <table class="k-table">
      <thead><tr><th>Data / czas</th><th>Czas trwania</th><th>Konsultant</th></tr></thead>
      <tbody>
        <?php foreach ($schedules as $s): ?>
        <tr>
          <td><?= date('d.m.Y H:i', strtotime($s['start_time'])) ?></td>
          <td><?= (int)$s['duration_minutes'] ?> min</td>
          <td><?= $s['consultant_name'] ? h($s['consultant_name']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Stopka / podpisy -->
  <div class="k-footer">
    <div>
      <div><?= h($org) ?> &nbsp;·&nbsp; <?= h($generated) ?></div>
      <div style="margin-top:.15rem">Dokument wygenerowany elektronicznie — nie wymaga pieczęci.</div>
    </div>
    <div style="display:flex;gap:2rem">
      <div class="k-sign">Podpis beneficjenta</div>
      <div class="k-sign">Podpis operatora</div>
    </div>
  </div>

</div><!-- /karta -->

<script>
// Jeśli otwarto z parametrem ?autoprint=1 — automatycznie drukuj
if (new URLSearchParams(location.search).get('autoprint') === '1') window.print();
</script>
</body>
</html>
