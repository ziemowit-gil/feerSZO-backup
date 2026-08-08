<?php
/**
 * Oświadczenie administratora o wystawieniu upoważnienia i dokonaniu zmian w systemie.
 * Dostęp tylko dla administratora / pracownika D3.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$id = (int)($_GET['id'] ?? 0);
$ap = $id ? db_one(
    "SELECT p.*, a.client_id, a.login AS student_login, a.created_at AS student_created_at,
            cl.name AS student_name, cl.email AS student_email
     FROM k30_ti_authorized_persons p
     JOIN k30_ti_student_accounts a ON a.id = p.student_account_id
     JOIN k30_clients cl ON cl.id = a.client_id
     WHERE p.id = ?", [$id]
) : null;
if (!$ap) { http_response_code(404); die('Nie znaleziono.'); }

$org      = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$admin    = !empty($ap['added_by_name']) ? $ap['added_by_name'] : (current_user()['name'] ?? 'Administrator');
$today    = date('d.m.Y');
$now      = date('d.m.Y, H:i');
$scan_ok  = !empty($ap['scan_path']);

// Lista zmian w systemie
$changes = [
    'Utworzono konto osoby upoważnionej w tabeli <em>k30_ti_authorized_persons</em>.',
    'Wygenerowano unikalny login: <strong>' . htmlspecialchars($ap['login'], ENT_QUOTES) . '</strong>.',
    'Ustawiono hasło dostępowe (hash bcrypt — hasło przekazane ustnie).',
    !empty($ap['email'])
        ? 'Wysłano powiadomienie e-mail na adres: <strong>' . htmlspecialchars($ap['email'], ENT_QUOTES) . '</strong>.'
        : 'Nie podano adresu e-mail — powiadomienie nie zostało wysłane.',
    $scan_ok
        ? 'Załączono skan podpisanego dokumentu upoważnienia.'
        : 'Skan dokumentu upoważnienia nie został jeszcze dołączony do systemu.',
];
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Oświadczenie administratora — <?= htmlspecialchars($ap['name'], ENT_QUOTES) ?></title>
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
  .changes { padding-left: 5mm; list-style: none; }
  .changes li { font-size: 10pt; line-height: 1.7; padding-left: 4mm; position: relative; }
  .changes li::before { content: '✓'; position: absolute; left: 0; color: #222; font-size: 9pt; top: .1em; }
  .declaration { border: 1px solid #bbb; border-radius: 3px; padding: 4mm 6mm;
                 font-size: 10pt; line-height: 1.65; margin-bottom: 7mm; background: #fafafa; }
  .declaration strong { font-weight: bold; }
  .sign-row { display: flex; justify-content: space-between; gap: 10mm; margin-top: 14mm; }
  .sign-block { flex: 1; border-top: 1px solid #555; padding-top: 2mm;
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
  <button onclick="window.print()" style="background:#1d4ed8;color:#fff;border:none;border-radius:5px;padding:6px 18px;font-size:13px;cursor:pointer">
    🖨 Drukuj / Zapisz PDF
  </button>
  <a href="authp_print.php?id=<?= $id ?>" style="color:#374151;font-size:13px;text-decoration:none">Kartka upoważnienia dla osoby →</a>
  <a href="accounts.php?authp=<?= (int)$ap['student_account_id'] ?>" style="color:#374151;font-size:13px;text-decoration:none">← Wróć do upoważnień</a>
</div>

<div class="page">

  <h1><?= htmlspecialchars($org, ENT_QUOTES) ?></h1>
  <div class="subtitle">Oświadczenie administratora o wystawieniu upoważnienia i dokonaniu zmian w systemie</div>

  <div class="meta">
    <span>Data wystawienia: <strong><?= $today ?></strong></span>
    <span>Dokument wygenerowano: <?= $now ?></span>
  </div>

  <div class="section">
    <div class="section-title">Administrator wystawiający</div>
    <dl>
      <dt>Imię i nazwisko:</dt><dd><strong><?= htmlspecialchars($admin, ENT_QUOTES) ?></strong></dd>
      <dt>Powód upoważnienia:</dt><dd><?= htmlspecialchars($ap['reason'] ?? '—', ENT_QUOTES) ?></dd>
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
    <div class="section-title">Osoba upoważniona</div>
    <dl>
      <dt>Imię i nazwisko:</dt><dd><strong><?= htmlspecialchars($ap['name'], ENT_QUOTES) ?></strong></dd>
      <?php if (!empty($ap['email'])): ?>
      <dt>E-mail:</dt><dd><?= htmlspecialchars($ap['email'], ENT_QUOTES) ?></dd>
      <?php endif; ?>
      <?php if (!empty($ap['notes'])): ?>
      <dt>Stosunek do kursanta:</dt><dd><?= htmlspecialchars($ap['notes'], ENT_QUOTES) ?></dd>
      <?php endif; ?>
      <dt>Login dostępowy:</dt><dd><strong><?= htmlspecialchars($ap['login'], ENT_QUOTES) ?></strong></dd>
      <dt>Data rejestracji:</dt><dd><?= htmlspecialchars($ap['created_at'] ?? $today, ENT_QUOTES) ?></dd>
    </dl>
  </div>

  <div class="section">
    <div class="section-title">Zmiany dokonane w systemie</div>
    <ul class="changes">
      <?php foreach ($changes as $ch): ?>
      <li><?= $ch ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="declaration">
    Ja, niżej podpisany/a administrator/ka systemu <strong><?= htmlspecialchars($org, ENT_QUOTES) ?></strong>
    — <strong><?= htmlspecialchars($admin, ENT_QUOTES) ?></strong> — oświadczam, że w dniu
    <strong><?= $today ?></strong> na podstawie pisemnego upoważnienia wystawiłem/am dostęp do panelu
    kursanta <strong><?= htmlspecialchars($ap['student_name'], ENT_QUOTES) ?></strong>
    dla osoby <strong><?= htmlspecialchars($ap['name'], ENT_QUOTES) ?></strong>.
    Dostęp jest wyłącznie do odczytu i obejmuje: lekcje, frekwencję oraz rozliczenia kursanta.
    Powód udzielenia dostępu: <em><?= htmlspecialchars($ap['reason'] ?? '—', ENT_QUOTES) ?></em>.
    Wszystkie powyższe zmiany zostały dokonane zgodnie z obowiązującymi procedurami
    ochrony danych i regulaminem organizacji.
  </div>

  <div class="sign-row" style="justify-content:flex-start">
    <div class="sign-block" style="max-width:80mm">
      Podpis administratora<br><br><br>
      <strong><?= htmlspecialchars($admin, ENT_QUOTES) ?></strong>
    </div>
  </div>

  <p class="notice">
    Dokument wewnętrzny — do przechowywania w aktach organizacji.
    <?= $scan_ok ? 'Do dokumentu dołączony skan podpisanego upoważnienia.' : 'Skan podpisanego upoważnienia należy dołączyć po podpisaniu przez obie strony.' ?>
    Upoważnienie może zostać cofnięte przez administratora w dowolnym momencie w panelu zarządzania kontami.
  </p>

</div>
</body>
</html>
