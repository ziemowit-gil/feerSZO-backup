<?php
/**
 * Dokument odwołania upoważnienia do wglądu w panel kursanta.
 * Dostęp tylko dla administratora / pracownika D3.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

k30_ti_staff_access();
karty30_migrate();

$id = (int)($_GET['id'] ?? 0);
$ap = $id ? db_one(
    "SELECT p.*, a.client_id, cl.name AS student_name, cl.email AS student_email
     FROM k30_ti_authorized_persons p
     JOIN k30_ti_student_accounts a ON a.id = p.student_account_id
     JOIN k30_clients cl ON cl.id = a.client_id
     WHERE p.id = ?", [$id]
) : null;
if (!$ap) { http_response_code(404); die('Nie znaleziono.'); }

$org        = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$revoker    = !empty($ap['revoked_by_name']) ? $ap['revoked_by_name'] : (current_user()['name'] ?? 'Administrator');
$revoked_at = !empty($ap['revoked_at'])
    ? date('d.m.Y', strtotime($ap['revoked_at']))
    : date('d.m.Y');
$today      = date('d.m.Y');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Odwołanie upoważnienia — <?= htmlspecialchars($ap['name'], ENT_QUOTES) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Arial', sans-serif; font-size: 11.5pt; color: #111; background: #fff; }
  .page { width: 190mm; margin: 10mm auto; }
  h1 { font-size: 14pt; font-weight: bold; text-align: center; margin-bottom: 2mm; letter-spacing: .02em; }
  .subtitle { text-align: center; font-size: 10pt; color: #555; margin-bottom: 8mm; }
  .meta { display: flex; justify-content: space-between; font-size: 9.5pt; color: #555; margin-bottom: 8mm; }
  .section { margin-bottom: 6mm; }
  .section-title { font-size: 9.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: .06em;
                   color: #444; border-bottom: 1px solid #bbb; padding-bottom: 1mm; margin-bottom: 3mm; }
  dl { display: grid; grid-template-columns: 52mm 1fr; gap: 2mm 4mm; }
  dt { font-size: 9.5pt; color: #555; }
  dd { font-size: 10.5pt; }
  dd strong { font-weight: bold; }
  .declaration { border: 1px solid #bbb; border-radius: 3px; padding: 4mm 6mm;
                 font-size: 10pt; line-height: 1.65; margin-bottom: 7mm; background: #fafafa; }
  .revoke-box { border: 2px solid #b91c1c; border-radius: 3px; padding: 3mm 6mm;
                font-size: 11pt; font-weight: bold; color: #b91c1c; text-align: center;
                letter-spacing: .04em; margin-bottom: 6mm; }
  .sign-row { display: flex; justify-content: flex-start; gap: 10mm; margin-top: 14mm; }
  .sign-block { max-width: 80mm; border-top: 1px solid #555; padding-top: 2mm;
                font-size: 9pt; color: #555; text-align: center; }
  .sign-block strong { display: block; margin-top: 1mm; font-size: 10pt; color: #111; }
  .notice { font-size: 8.5pt; color: #888; line-height: 1.5; margin-top: 8mm;
            border-top: 1px solid #e5e7eb; padding-top: 3mm; }
  @media print {
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .no-print { display: none !important; }
    .page { margin: 0; width: 100%; }
  }
</style>
</head>
<body>

<div class="no-print" style="padding:12px;background:#f1f5f9;border-bottom:1px solid #cbd5e1;display:flex;gap:10px;align-items:center">
  <button onclick="window.print()" style="background:#b91c1c;color:#fff;border:none;border-radius:5px;padding:6px 18px;font-size:13px;cursor:pointer">
    🖨 Drukuj / Zapisz PDF
  </button>
  <a href="accounts.php?authp=<?= (int)$ap['student_account_id'] ?>" style="color:#374151;font-size:13px;text-decoration:none">← Wróć do upoważnień</a>
</div>

<div class="page">

  <h1><?= htmlspecialchars($org, ENT_QUOTES) ?></h1>
  <div class="subtitle">Odwołanie upoważnienia do wglądu w panel kursanta</div>

  <div class="meta">
    <span>Data odwołania: <strong><?= $revoked_at ?></strong></span>
    <span>Dokument wygenerowano: <?= $today ?></span>
  </div>

  <div class="revoke-box">UPOWAŻNIENIE ODWOŁANE</div>

  <div class="section">
    <div class="section-title">Administrator odwołujący</div>
    <dl>
      <dt>Imię i nazwisko:</dt><dd><strong><?= htmlspecialchars($revoker, ENT_QUOTES) ?></strong></dd>
      <dt>Data odwołania:</dt><dd><?= $revoked_at ?></dd>
    </dl>
  </div>

  <div class="section">
    <div class="section-title">Kursant (właściciel konta)</div>
    <dl>
      <dt>Imię i nazwisko:</dt><dd><?= htmlspecialchars($ap['student_name'], ENT_QUOTES) ?></dd>
      <?php if (!empty($ap['student_email'])): ?>
      <dt>E-mail:</dt><dd><?= htmlspecialchars($ap['student_email'], ENT_QUOTES) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

  <div class="section">
    <div class="section-title">Osoba, której odwołano upoważnienie</div>
    <dl>
      <dt>Imię i nazwisko:</dt><dd><strong><?= htmlspecialchars($ap['name'], ENT_QUOTES) ?></strong></dd>
      <?php if (!empty($ap['email'])): ?>
      <dt>E-mail:</dt><dd><?= htmlspecialchars($ap['email'], ENT_QUOTES) ?></dd>
      <?php endif; ?>
      <?php if (!empty($ap['notes'])): ?>
      <dt>Stosunek do kursanta:</dt><dd><?= htmlspecialchars($ap['notes'], ENT_QUOTES) ?></dd>
      <?php endif; ?>
      <dt>Login (dezaktywowany):</dt><dd><?= htmlspecialchars($ap['login'], ENT_QUOTES) ?></dd>
      <?php if (!empty($ap['reason'])): ?>
      <dt>Pierwotny powód:</dt><dd><?= htmlspecialchars($ap['reason'], ENT_QUOTES) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

  <div class="declaration">
    Ja, niżej podpisany/a administrator/ka systemu <strong><?= htmlspecialchars($org, ENT_QUOTES) ?></strong>
    — <strong><?= htmlspecialchars($revoker, ENT_QUOTES) ?></strong> — oświadczam, że w dniu
    <strong><?= $revoked_at ?></strong> odwołałem/am upoważnienie do wglądu w panel kursanta
    <strong><?= htmlspecialchars($ap['student_name'], ENT_QUOTES) ?></strong>
    udzielone osobie <strong><?= htmlspecialchars($ap['name'], ENT_QUOTES) ?></strong>.
    Konto dostępowe zostało dezaktywowane. Osoba wymieniona powyżej utraciła dostęp do panelu
    z chwilą dokonania tej czynności w systemie.
  </div>

  <div class="sign-row">
    <div class="sign-block">
      Podpis administratora<br><br><br>
      <strong><?= htmlspecialchars($revoker, ENT_QUOTES) ?></strong>
    </div>
  </div>

  <p class="notice">
    Dokument wewnętrzny — do przechowywania w aktach organizacji razem z dokumentem pierwotnego upoważnienia.
    <?= !empty($ap['revoke_scan_path']) ? 'Do dokumentu dołączony skan odwołania.' : 'Skan podpisanego odwołania należy dołączyć po podpisaniu.' ?>
  </p>

</div>
</body>
</html>
