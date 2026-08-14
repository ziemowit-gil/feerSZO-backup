<?php
/**
 * contracts/zlecenie/rachunek_download.php
 * Publiczny podgląd/wydruk rachunku dla zleceniobiorcy — dostęp przez jednorazowy token.
 * Nie wymaga logowania. Token generowany przy wysyłce powiadomienia.
 *
 * GET: ?token=XXX
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ksiegowy_email.php';
require_once dirname(dirname(__DIR__)) . '/includes/rozliczenia.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') { http_response_code(400); die('Brak tokenu dostępu.'); }

$rozl = get_rozliczenie_by_token($token);
if (!$rozl) { http_response_code(404); die('Nie znaleziono rachunku lub link wygasł.'); }

$contract = db_one("SELECT * FROM umowy_zlecenie WHERE id=?", [(int)$rozl['contract_id']]);
if (!$contract) { http_response_code(404); die('Nie znaleziono umowy.'); }

// ── Dane organizacji ──────────────────────────────────────────────────────────
$org_name     = org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_krs      = org_setting('org_krs')         ?: '';
$org_nip      = org_setting('org_nip')         ?: '';
$org_regon    = org_setting('org_regon')       ?: '';
$org_adres    = org_setting('org_adres')       ?: '';
$org_miasto   = org_setting('org_miejscowosc') ?: '';
$org_logo_key = org_setting('org_logo')        ?: '';
$org_logo_url = $org_logo_key ? APP_URL . '/uploads/' . $org_logo_key : '';

$email_row  = rozliczenie_email_row($contract, $rozl);
$numer      = $contract['numer_umowy'] ?? '';
$imie_nazw  = $contract['imie_nazwisko'] ?? '';
$adres_osob = trim(address_format($contract));
if ($adres_osob === '') $adres_osob = trim((string)($contract['adres'] ?? ''));

$data_umowy   = !empty($contract['data_zawarcia'])   ? date_pl($contract['data_zawarcia'])   : '';
$data_rach    = !empty($rozl['data_rachunku'])        ? date_pl($rozl['data_rachunku'])        : '';
$okres        = h($rozl['okres'] ?? '');
$kwota_brutto = ($rozl['kwota_brutto'] !== null && $rozl['kwota_brutto'] !== '')
              ? money((float)$rozl['kwota_brutto']) : '';
$godziny      = h($rozl['liczba_godzin'] ?? '');
$powod        = h($rozl['powod'] ?? '');
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rachunek do umowy zlecenie<?= $numer ? ' · ' . h($numer) : '' ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;font-size:12pt;color:#111;background:#fff;line-height:1.6}
.page{width:210mm;min-height:297mm;margin:0 auto;padding:22mm 22mm 18mm 25mm;position:relative}
.org-header{display:flex;justify-content:space-between;align-items:flex-start;
            border-bottom:2.5pt solid #000;padding-bottom:6pt;margin-bottom:14pt}
.org-logo img{max-height:52pt;max-width:100pt}
.org-logo .no-logo{font-size:22pt;font-weight:700;letter-spacing:-1px}
.org-details{text-align:right;font-size:8.5pt;line-height:1.7}
.org-name{font-weight:700;font-size:10.5pt;text-align:right}
.doc-title-wrap{text-align:center;margin:18pt 0 14pt}
.doc-label{font-size:7.5pt;letter-spacing:2px;text-transform:uppercase;color:#555}
.doc-type{font-size:15pt;font-weight:700;letter-spacing:.5px;margin:3pt 0}
.doc-number{font-size:10pt;font-weight:700;border:1pt solid #000;display:inline-block;padding:3pt 12pt;margin-top:4pt}
.data-table{width:100%;border-collapse:collapse;margin-top:10pt}
.data-table td{padding:4pt 8pt;border:1pt solid #ccc;vertical-align:top;font-size:11.5pt}
.data-table td:first-child{font-weight:700;width:46%;background:#f9f9f9}
.info-box{border:1pt solid #b0b0b0;background:#f5f8ff;padding:9pt 12pt;margin-top:16pt;
          font-size:10.5pt;line-height:1.6}
.info-box strong{font-size:11pt}
.page-footer{position:absolute;bottom:10mm;left:25mm;right:22mm;
             border-top:1pt solid #ccc;padding-top:4pt;
             display:flex;justify-content:space-between;font-size:7pt;color:#888}
@media print{
  body{font-size:11.5pt}
  .page{padding:15mm 18mm 16mm 22mm;width:100%}
  .no-print{display:none!important}
  @page{size:A4;margin:0}
}
.action-bar{position:fixed;top:0;left:0;right:0;background:#1e293b;color:#fff;
            padding:6px 16px;display:flex;align-items:center;gap:10px;z-index:999;
            font-family:system-ui,sans-serif;font-size:.85rem}
.action-bar button{padding:4px 14px;border-radius:4px;font-size:.82rem;cursor:pointer;border:none}
.btn-print{background:#3b82f6;color:#fff}
@media print{.action-bar{display:none}}
</style>
</head>
<body>

<div class="action-bar no-print">
  <button class="btn-print" onclick="window.print()">&#9113; Drukuj / Zapisz PDF</button>
  <span style="color:#94a3b8">Rachunek<?= $numer ? ' &middot; ' . h($numer) : '' ?></span>
</div>
<div style="height:34px" class="no-print"></div>

<div class="page">

  <div class="org-header">
    <div class="org-logo">
      <?php if ($org_logo_url): ?>
      <img src="<?= h($org_logo_url) ?>" alt="Logo">
      <?php else: ?>
      <span class="no-logo"><?= mb_strtoupper(mb_substr($org_name, 0, 2)) ?></span>
      <?php endif; ?>
    </div>
    <div class="org-details">
      <div class="org-name"><?= h($org_name) ?></div>
      <?php if ($org_krs):   ?><div>KRS: <?= h($org_krs) ?></div><?php endif; ?>
      <?php if ($org_nip):   ?><div>NIP: <?= h($org_nip) ?></div><?php endif; ?>
      <?php if ($org_regon): ?><div>REGON: <?= h($org_regon) ?></div><?php endif; ?>
      <?php if ($org_adres): ?><div><?= h($org_adres) ?><?= $org_miasto ? ', ' . h($org_miasto) : '' ?></div><?php endif; ?>
    </div>
  </div>

  <div class="doc-title-wrap">
    <div class="doc-label">Umowa zlecenie</div>
    <div class="doc-type">Rachunek do umowy zlecenie</div>
    <?php if ($numer): ?><div class="doc-number"><?= h($numer) ?></div><?php endif; ?>
  </div>

  <table class="data-table">
    <tr><td>Zleceniobiorca</td><td><?= h($imie_nazw) ?></td></tr>
    <?php if ($adres_osob): ?>
    <tr><td>Adres</td><td><?= h($adres_osob) ?></td></tr>
    <?php endif; ?>
    <?php if ($data_umowy): ?>
    <tr><td>Data zawarcia umowy</td><td><?= $data_umowy ?></td></tr>
    <?php endif; ?>
    <?php if ($data_rach): ?>
    <tr><td>Data rachunku</td><td><?= $data_rach ?></td></tr>
    <?php endif; ?>
    <?php if ($okres): ?>
    <tr><td>Za jaki okres</td><td><?= $okres ?></td></tr>
    <?php endif; ?>
    <?php if ($kwota_brutto): ?>
    <tr><td>Kwota wynagrodzenia</td><td><?= $kwota_brutto ?> (brutto)</td></tr>
    <?php endif; ?>
    <?php if ($godziny): ?>
    <tr><td>Liczba godzin</td><td><?= $godziny ?></td></tr>
    <?php endif; ?>
    <?php if ($powod): ?>
    <tr><td>Powód wystawienia</td><td><?= $powod ?></td></tr>
    <?php endif; ?>
  </table>

  <div class="info-box">
    <strong>Co dalej?</strong><br>
    Proszę wydrukować ten dokument, podpisać go odręcznie i dostarczyć do<?php echo $org_name ? ' ' . h($org_name) : ' organizacji' ?> w formie papierowej lub zeskanowanej.
    <?php if (!empty($contract['rachunek_bankowy'])): ?>
    Wynagrodzenie zostanie przekazane na rachunek bankowy wskazany w umowie.
    <?php endif; ?>
  </div>

  <div class="page-footer">
    <span><?= h($org_name) ?></span>
    <span>Wygenerowano: <?= date('d.m.Y H:i') ?></span>
  </div>

</div>

</body>
</html>
