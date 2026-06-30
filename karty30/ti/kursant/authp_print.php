<?php
/**
 * Kartka upoważnienia do wglądu w panel kursanta — do druku i podpisu.
 * Dostęp tylko dla administratora / pracownika K30.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$id  = (int)($_GET['id'] ?? 0);
$ap  = $id ? db_one(
    "SELECT p.*, a.client_id, cl.name AS student_name, cl.email AS student_email
     FROM k30_ti_authorized_persons p
     JOIN k30_ti_student_accounts a ON a.id=p.student_account_id
     JOIN k30_clients cl ON cl.id=a.client_id
     WHERE p.id=?", [$id]
) : null;
if (!$ap) { http_response_code(404); die('Nie znaleziono.'); }

$org      = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
$portal_url = rtrim(APP_URL, '/') . '/karty30/ti/kursant/authorized_person.php';
$today    = date('d.m.Y');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Upoważnienie do wglądu — <?= h($ap['name']) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Arial', sans-serif; font-size: 12pt; color: #111; background: #fff; }
  .page { width: 190mm; margin: 10mm auto; padding: 0; }
  h1 { font-size: 15pt; font-weight: bold; text-align: center; margin-bottom: 4mm; letter-spacing: .02em; }
  .subtitle { text-align: center; font-size: 10pt; color: #555; margin-bottom: 8mm; }
  .section { margin-bottom: 6mm; }
  .section-title { font-size: 10pt; font-weight: bold; text-transform: uppercase; letter-spacing: .05em;
                   color: #555; border-bottom: 1px solid #bbb; padding-bottom: 1mm; margin-bottom: 3mm; }
  dl { display: grid; grid-template-columns: 48mm 1fr; gap: 2mm 4mm; }
  dt { font-size: 10pt; color: #555; }
  dd { font-size: 11pt; font-weight: bold; }
  .login-box { border: 1.5px solid #222; border-radius: 3px; padding: 4mm 6mm; margin: 5mm 0;
               background: #f9f9f9; }
  .login-box .lbl { font-size: 9pt; color: #666; margin-bottom: 1mm; }
  .login-box .val { font-size: 13pt; font-family: 'Courier New', monospace; font-weight: bold;
                    letter-spacing: .08em; }
  .portal-url { font-family: 'Courier New', monospace; font-size: 10pt; }
  .notice { font-size: 9pt; color: #555; line-height: 1.5; margin-bottom: 6mm; }
  .sign-area { display: grid; grid-template-columns: 1fr 1fr; gap: 10mm; margin-top: 12mm; }
  .sign-block { border-top: 1px solid #555; padding-top: 2mm; font-size: 9pt; color: #555; text-align: center; }
  .sign-date { font-size: 9pt; color: #888; text-align: right; margin-bottom: 8mm; }
  .declaration { border: 1px solid #bbb; border-radius: 3px; padding: 4mm 6mm; font-size: 10pt; line-height: 1.6; margin-bottom: 6mm; }
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
  <a href="accounts.php?authp=<?= (int)$ap['student_account_id'] ?>" style="color:#374151;font-size:13px;text-decoration:none">← Wróć do upoważnień</a>
</div>

<div class="page">

  <h1><?= h($org) ?></h1>
  <div class="subtitle">Upoważnienie do wglądu w panel kursanta</div>

  <div class="sign-date">Data wystawienia: <strong><?= $today ?></strong></div>

  <div class="section">
    <div class="section-title">Kursant</div>
    <dl>
      <dt>Imię i nazwisko:</dt><dd><?= h($ap['student_name']) ?></dd>
      <?php if (!empty($ap['student_email'])): ?>
      <dt>E-mail:</dt><dd><?= h($ap['student_email']) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

  <div class="section">
    <div class="section-title">Osoba upoważniona</div>
    <dl>
      <dt>Imię i nazwisko:</dt><dd><?= h($ap['name']) ?></dd>
      <?php if (!empty($ap['email'])): ?>
      <dt>E-mail:</dt><dd><?= h($ap['email']) ?></dd>
      <?php endif; ?>
      <?php if (!empty($ap['notes'])): ?>
      <dt>Stosunek do kursanta:</dt><dd><?= h($ap['notes']) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

  <div class="section">
    <div class="section-title">Dane dostępowe do panelu</div>
    <div class="login-box">
      <div class="lbl">Adres panelu</div>
      <div class="val portal-url"><?= h($portal_url) ?></div>
    </div>
    <div class="login-box">
      <div class="lbl">Login</div>
      <div class="val"><?= h($ap['login']) ?></div>
    </div>
    <div class="login-box">
      <div class="lbl">Hasło (jednorazowe — zmień po pierwszym logowaniu)</div>
      <div class="val"><?= h('(hasło przekazywane ustnie / generowane przez administratora)') ?></div>
    </div>
    <p class="notice" style="margin-top:3mm">
      Hasło podane ustnie lub przekazane przez administratora. Po pierwszym zalogowaniu zalecana jest zmiana
      hasła w ustawieniach konta. Panel umożliwia wgląd do lekcji i rozliczeń kursanta — wyłącznie do odczytu.
    </p>
  </div>

  <div class="declaration">
    <strong>Oświadczenie osoby upoważnionej:</strong><br>
    Przyjmuję do wiadomości, że zostałam/em upoważniona/y przez kursanta <strong><?= h($ap['student_name']) ?></strong>
    do wglądu w dane jego panelu. Zobowiązuję się do nieudostępniania danych logowania osobom trzecim
    oraz do korzystania z panelu wyłącznie w celach, dla których upoważnienie zostało udzielone.
    Upoważnienie może zostać cofnięte przez administratora w dowolnym momencie.
  </div>

  <div class="sign-area">
    <div class="sign-block">Podpis osoby upoważnionej<br><br><br></div>
    <div class="sign-block">Podpis administratora / pracownika<br><br><br></div>
  </div>

</div>
</body>
</html>
