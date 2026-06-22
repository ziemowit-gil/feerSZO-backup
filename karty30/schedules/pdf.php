<?php
/**
 * karty30/schedules/pdf.php — Karta terminu K30 do wydruku / zapisu jako PDF.
 *
 * Wydruk uruchamiany przez window.print() lub ręcznie z przeglądarki.
 * Strona jest czysta: brak sidebara, brak nawigacji.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); die('Brak ID'); }

$schedule = db_one(
    "SELECT s.*, c.name AS client_name, c.phone AS client_phone,
                   c.email AS client_email, c.address AS client_address,
                   c.date_of_birth,
             u.name AS consultant_name, u.email AS consultant_email,
             r.name AS resource_name, r.location AS resource_location,
             rc.name AS resource_cat,
             pc.contract_number AS pfron_contract_number
     FROM k30_schedules s
     LEFT JOIN k30_clients c  ON c.id=s.client_id
     LEFT JOIN users u        ON u.id=s.assigned_to
     LEFT JOIN resources r    ON r.id=s.resource_id
     LEFT JOIN resource_categories rc ON rc.id=r.category_id
     LEFT JOIN k30_pfron_contracts pc ON pc.id=s.pfron_contract_id
     WHERE s.id=?",
    [$id]
);
if (!$schedule) { http_response_code(404); die('Termin nie istnieje.'); }

$dt    = new DateTime($schedule['start_time']);
$dt_e  = clone $dt;
$dt_e->modify('+' . (int)$schedule['duration_minutes'] . ' minutes');

$org   = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$bt    = K30_BILLING_TYPES[$schedule['billing_type'] ?? 'free'] ?? ['label'=>'Bezpłatne'];
$pfron_st = K30_PFRON_STATUSES[$schedule['pfron_status'] ?? ''] ?? null;

// Seria
$series_info = '';
if ($schedule['series_id']) {
    $total = (int)(db_one("SELECT COUNT(*) AS c FROM k30_schedules WHERE series_id=?", [$schedule['series_id']])['c'] ?? 0);
    $series_info = 'Wizyta ' . ((int)$schedule['series_index'] + 1) . ' z ' . $total . ' (seria cykliczna)';
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Karta terminu K30 #<?= $id ?> — <?= h($org) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; color: #1e293b; background: #fff; }

/* ── Ekran ── */
@media screen {
  body { max-width: 800px; margin: 0 auto; padding: 24px; background: #f8fafc; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 16px rgba(0,0,0,.08); padding: 32px; }
  .no-print-bar { display: flex; gap: 12px; margin-bottom: 16px; }
}

/* ── Druk ── */
@media print {
  body { padding: 0; background: #fff; }
  .card { padding: 0; box-shadow: none; }
  .no-print-bar { display: none !important; }
  @page { size: A4; margin: 18mm 20mm 18mm 20mm; }
}

/* ── Layout ── */
.header { display: flex; align-items: flex-start; justify-content: space-between; border-bottom: 2px solid #1e293b; padding-bottom: 12px; margin-bottom: 18px; }
.org-name { font-size: 16px; font-weight: 700; color: #1e293b; }
.doc-title { font-size: 12px; color: #64748b; margin-top: 3px; }
.doc-id { font-size: 20px; font-weight: 800; color: #2563eb; text-align: right; }
.doc-date-issued { font-size: 11px; color: #64748b; text-align: right; }

.section { margin-bottom: 16px; }
.section-title { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-bottom: 8px; }

table.info { width: 100%; border-collapse: collapse; }
table.info td { padding: 4px 6px; vertical-align: top; }
table.info td:first-child { width: 38%; font-weight: 600; color: #475569; white-space: nowrap; }
table.info tr:nth-child(even) td { background: #f8fafc; }

.billing-box { border: 1.5px solid; border-radius: 6px; padding: 8px 12px; display: inline-block; font-size: 12px; font-weight: 700; }
.billing-free  { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }
.billing-paid  { border-color: #2563eb; color: #2563eb; background: #eff6ff; }
.billing-pfron { border-color: #7c3aed; color: #7c3aed; background: #f5f3ff; }

.series-badge { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 4px; padding: 2px 8px; font-size: 11px; font-weight: 600; display: inline-block; }

.signature-row { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; padding-top: 12px; border-top: 1px solid #e2e8f0; }
.sig-box { text-align: center; }
.sig-line { border-top: 1px solid #94a3b8; margin: 40px 12px 4px; }
.sig-label { font-size: 10px; color: #64748b; }

.footer-note { margin-top: 28px; font-size: 10px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 8px; }

/* Przyciski na ekranie */
.btn-print { background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 9px 20px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.btn-back  { background: none; border: 1px solid #94a3b8; border-radius: 6px; padding: 8px 16px; font-size: 13px; cursor: pointer; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
</style>
</head>
<body>

<!-- Pasek akcji (tylko ekran) -->
<div class="no-print-bar">
  <button class="btn-print" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
      <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
      <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/>
    </svg>
    Drukuj / Zapisz PDF
  </button>
  <a href="view.php?id=<?= $id ?>" class="btn-back">← Wróć do terminu</a>
</div>

<div class="card">

  <!-- Nagłówek -->
  <div class="header">
    <div>
      <div class="org-name"><?= h($org) ?></div>
      <div class="doc-title">Karta terminu konsultacji — Dydaktyka / Karty 30</div>
      <?php if ($series_info): ?>
      <div style="margin-top:6px"><span class="series-badge">↻ <?= h($series_info) ?></span></div>
      <?php endif; ?>
    </div>
    <div>
      <div class="doc-id">#<?= $id ?></div>
      <div class="doc-date-issued">Wydruk: <?= date('d.m.Y H:i') ?></div>
    </div>
  </div>

  <!-- Beneficjent -->
  <div class="section">
    <div class="section-title">Beneficjent</div>
    <table class="info">
      <tr><td>Imię i nazwisko</td><td><strong><?= h($schedule['client_name']) ?></strong></td></tr>
      <?php if ($schedule['client_phone']): ?>
      <tr><td>Telefon</td><td><?= h($schedule['client_phone']) ?></td></tr>
      <?php endif; ?>
      <?php if ($schedule['client_email']): ?>
      <tr><td>E-mail</td><td><?= h($schedule['client_email']) ?></td></tr>
      <?php endif; ?>
      <?php if ($schedule['client_address']): ?>
      <tr><td>Adres</td><td><?= h($schedule['client_address']) ?></td></tr>
      <?php endif; ?>
      <?php if ($schedule['date_of_birth']): ?>
      <tr><td>Data urodzenia</td><td><?= h($schedule['date_of_birth']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- Szczegóły terminu -->
  <div class="section">
    <div class="section-title">Termin konsultacji</div>
    <table class="info">
      <tr><td>Data</td><td><strong><?= $dt->format('d.m.Y') ?> (<?= $dt->format('l') ?>)</strong></td></tr>
      <tr><td>Godzina</td>
          <td><?= $schedule['time_from'] ?: $dt->format('H:i') ?>
              <?= $schedule['time_to'] ? ' – ' . h($schedule['time_to']) : ' – ' . $dt_e->format('H:i') ?>
              (<?= (int)$schedule['duration_minutes'] ?> min)
          </td>
      </tr>
      <?php if ($schedule['consultant_name']): ?>
      <tr><td>Konsultant</td><td><?= h($schedule['consultant_name']) ?></td></tr>
      <?php endif; ?>
      <tr><td>Lokalizacja</td>
          <td><?php if ($schedule['is_remote']): ?>
              <em>Zdalnie</em>
              <?php elseif ($schedule['resource_name']): ?>
              <?= h($schedule['resource_name']) ?><?= $schedule['resource_location'] ? ' — '.h($schedule['resource_location']) : '' ?>
              <?php else: ?>—<?php endif; ?>
          </td>
      </tr>
      <tr><td>Status</td><td><?= K30_SCHEDULE_STATUSES[$schedule['status']]['label'] ?? h($schedule['status']) ?></td></tr>
      <?php if ($schedule['description']): ?>
      <tr><td>Opis / uwagi</td><td><?= nl2br(h($schedule['description'])) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- Rozliczenie -->
  <div class="section">
    <div class="section-title">Rozliczenie</div>
    <div style="margin-bottom:8px">
      <span class="billing-box billing-<?= h($schedule['billing_type'] ?? 'free') ?>">
        <?= h($bt['label']) ?>
      </span>
      <?php if ($schedule['pfron_contract_number']): ?>
      &nbsp; <span style="color:#7c3aed;font-size:12px">Umowa PFRON: <strong><?= h($schedule['pfron_contract_number']) ?></strong></span>
      <?php endif; ?>
      <?php if ($pfron_st): ?>
      &nbsp; <span style="font-size:11px;color:<?= h($pfron_st['color']) ?>">(<?= h($pfron_st['label']) ?>)</span>
      <?php endif; ?>
    </div>
    <?php if ((float)($schedule['billed_hours'] ?? 0) > 0): ?>
    <table class="info">
      <tr><td>Godziny łącznie</td><td><?= number_format((float)$schedule['billed_hours'],2,',','') ?> h</td></tr>
      <?php if ((float)$schedule['free_hours'] > 0): ?>
      <tr><td>Godziny bezpłatne</td><td><?= number_format((float)$schedule['free_hours'],2,',','') ?> h</td></tr>
      <?php endif; ?>
      <?php if ((float)$schedule['charged_hours'] > 0): ?>
      <tr><td>Godziny odpłatne</td><td><?= number_format((float)$schedule['charged_hours'],2,',','') ?> h</td></tr>
      <?php endif; ?>
      <tr><td>Kwota do zapłaty</td>
          <td><strong><?= (float)$schedule['amount_due'] > 0
              ? number_format((float)$schedule['amount_due'],2,',','') . ' zł'
              : 'Bezpłatnie' ?></strong></td></tr>
      <?php if ($schedule['pricing_note']): ?>
      <tr><td>Kalkulacja</td><td style="font-size:11px;color:#64748b"><?= h($schedule['pricing_note']) ?></td></tr>
      <?php endif; ?>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($schedule['needs_invoice']): ?>
  <!-- FV -->
  <div class="section">
    <div class="section-title">Prośba o fakturę</div>
    <table class="info">
      <tr><td>Rodzaj FV</td><td><?= ($schedule['invoice_type']??'') === 'personal' ? 'Imienna' : 'Firmowa' ?></td></tr>
      <tr><td>Nazwa / Imię</td><td><?= h($schedule['invoice_name']) ?></td></tr>
      <?php if ($schedule['invoice_nip']): ?>
      <tr><td>NIP</td><td><?= h($schedule['invoice_nip']) ?></td></tr>
      <?php endif; ?>
      <tr><td>Adres</td><td><?= h($schedule['invoice_address']) ?></td></tr>
      <?php if ($schedule['invoice_email']): ?>
      <tr><td>E-mail FV</td><td><?= h($schedule['invoice_email']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
  <?php endif; ?>

  <!-- Podpisy -->
  <div class="signature-row">
    <div class="sig-box">
      <div class="sig-line"></div>
      <div class="sig-label">Podpis beneficjenta</div>
    </div>
    <div class="sig-box">
      <div class="sig-line"></div>
      <div class="sig-label">Podpis konsultanta / organizacji</div>
    </div>
  </div>

  <!-- Stopka -->
  <div class="footer-note">
    <?= h($org) ?> · Karta terminu K30 #<?= $id ?> · Wygenerowano <?= date('d.m.Y H:i') ?>
  </div>

</div>

<script>
// Automatycznie otwórz dialog drukowania gdy ?auto=1
if (new URLSearchParams(window.location.search).get('auto') === '1') {
    window.addEventListener('load', function() { window.print(); });
}
</script>
</body>
</html>
