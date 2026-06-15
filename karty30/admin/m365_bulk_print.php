<?php
/**
 * karty30/admin/m365_bulk_print.php — Wydruk tabelki kont M365 (PDF-ready).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak dostępu.'); }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$ts     = (int)($_GET['ts'] ?? 0);
$key    = 'k30_m365_bulk_print_' . $ts;
$result = $_SESSION[$key] ?? null;

if (!$result || empty($result['created'])) {
    die('<p style="font-family:sans-serif;color:red;padding:2rem">Brak danych do wydruku lub sesja wygasła. Zamknij to okno i wróć do wyników.</p>');
}

$org      = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$date_str = date('d.m.Y H:i');
$count    = count($result['created']);
$password = $result['password'];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Konta M365 — <?= h($org) ?> — <?= $date_str ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1e293b; background: #fff; }

@media screen {
  body { max-width: 860px; margin: 0 auto; padding: 24px; background: #f8fafc; }
  .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 16px rgba(0,0,0,.08); padding: 28px; }
  .no-print { display: flex; gap: 10px; margin-bottom: 16px; }
  .btn-print { background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 9px 20px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
  .btn-close-win { background: none; border: 1px solid #94a3b8; border-radius: 6px; padding: 8px 16px; font-size: 13px; cursor: pointer; color: #475569; }
}

@media print {
  body { padding: 0; background: #fff; font-size: 11px; }
  .card { padding: 0; box-shadow: none; }
  .no-print { display: none !important; }
  @page { size: A4; margin: 15mm 18mm; }
}

/* Layout */
.doc-header { display: flex; align-items: flex-start; justify-content: space-between; border-bottom: 2px solid #1e293b; padding-bottom: 12px; margin-bottom: 18px; }
.org-name   { font-size: 15px; font-weight: 700; }
.doc-title  { font-size: 11px; color: #64748b; margin-top: 3px; }
.doc-meta   { text-align: right; font-size: 11px; color: #64748b; }

/* Alert hasło */
.pass-box {
  background: #fffbeb; border: 1.5px solid #f59e0b; border-radius: 6px;
  padding: 10px 14px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px;
}
.pass-label { font-size: 11px; color: #92400e; font-weight: 600; }
.pass-value { font-family: 'Courier New', monospace; font-size: 15px; font-weight: 800; color: #dc2626; letter-spacing: .05em; }
.pass-note  { font-size: 10px; color: #78350f; margin-top: 3px; }

/* Tabela */
table { width: 100%; border-collapse: collapse; }
thead th {
  background: #1e293b; color: #f1f5f9; font-size: 10px;
  text-transform: uppercase; letter-spacing: .06em;
  padding: 7px 10px; text-align: left; font-weight: 700;
}
tbody td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
tbody tr:nth-child(even) td { background: #f8fafc; }
tbody tr:last-child td { border-bottom: none; }
.num  { color: #94a3b8; font-size: 11px; text-align: center; width: 36px; }
.mono { font-family: 'Courier New', monospace; font-size: 11.5px; }
.id   { color: #64748b; }
.pass { color: #dc2626; font-weight: 700; }

/* Stopka */
.doc-footer { margin-top: 22px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 10px; color: #94a3b8; display: flex; justify-content: space-between; }

/* Per-user cut lines dla nożyczek (opcjonalne) */
.cut-hint { font-size: 9px; color: #cbd5e1; text-align: center; margin-top: 16px; }
</style>
</head>
<body>

<!-- Pasek akcji — tylko ekran -->
<div class="no-print">
  <button class="btn-print" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16">
      <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
      <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/>
    </svg>
    Drukuj / Zapisz PDF
  </button>
  <button class="btn-close-win" onclick="window.close()">Zamknij</button>
</div>

<div class="card">

  <!-- Nagłówek -->
  <div class="doc-header">
    <div>
      <div class="org-name"><?= h($org) ?></div>
      <div class="doc-title">Konta Microsoft 365 — lista dostępowa</div>
    </div>
    <div class="doc-meta">
      <div>Wygenerowano: <?= $date_str ?></div>
      <div>Liczba kont: <strong><?= $count ?></strong></div>
    </div>
  </div>

  <!-- Hasło -->
  <div class="pass-box">
    <div>
      <div class="pass-label">⚠ Hasło startowe — jednakowe dla wszystkich kont</div>
      <div class="pass-value"><?= h($password) ?></div>
      <div class="pass-note">Przekaż hasło oddzielnie od tego dokumentu. Zalecana zmiana po pierwszym logowaniu.</div>
    </div>
  </div>

  <!-- Tabela kont -->
  <table>
    <thead>
      <tr>
        <th class="num">#</th>
        <th>Beneficjent</th>
        <th>Login (UPN)</th>
        <th>ID konta</th>
        <th>Hasło startowe</th>
        <th>Portal</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($result['created'] as $i => $c): ?>
      <tr>
        <td class="num mono"><?= $i + 1 ?></td>
        <td><?= h($c['name'] ?? '') ?></td>
        <td class="mono"><?= h($c['login']) ?></td>
        <td class="mono id"><?= h($c['emp_id']) ?></td>
        <td class="mono pass"><?= h($password) ?></td>
        <td style="font-size:10px;color:#64748b">portal.office.com</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Stopka -->
  <div class="doc-footer">
    <span><?= h($org) ?> · Konta Microsoft 365 K30</span>
    <span>Wydrukowano <?= $date_str ?></span>
  </div>

</div><!-- /card -->

<script>
// Automatyczny wydruk jeśli ?auto=1
if (new URLSearchParams(window.location.search).get('auto') === '1') {
    window.addEventListener('load', function() { window.print(); });
}
</script>
</body>
</html>
