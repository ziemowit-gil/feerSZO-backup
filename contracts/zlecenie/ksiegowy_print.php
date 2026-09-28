<?php
/**
 * contracts/zlecenie/ksiegowy_print.php
 * Wydruk (czysty tekst, bez papieru firmowego) bloku do księgowego — wymagane dane do rachunku.
 *
 * GET: [id] — opcjonalne, służy tylko do pokazania numeru umowy w nagłówku
 *             oraz do linku „wróć”. Treść bloku jest stała (szablon).
 *
 * Strona jest czystym HTML bez layoutu aplikacji; otwiera się w nowej karcie.
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ksiegowy_email.php';
require_once dirname(dirname(__DIR__)) . '/includes/rozliczenia.php';

require_login();

$id     = (int)($_GET['id'] ?? 0);
$rozlId = (int)($_GET['rozliczenie_id'] ?? 0);
$row    = $id ? db_one("SELECT * FROM umowy_zlecenie WHERE id = ?", [$id]) : null;
$numer  = $row['numer_umowy'] ?? '';

// Gdy podano konkretne rozliczenie — blok e-mail z danymi tego rozliczenia
$email_row = $row ?: [];
if ($rozlId && $row) {
    $rozl = get_rozliczenie($rozlId);
    if ($rozl && (int)$rozl['contract_id'] === $id) {
        $email_row = rozliczenie_email_row($row, $rozl);
    }
}

$tekst = ksiegowy_rachunek_email_text($email_row);
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dane do rachunku — e-mail do księgowego<?= $numer ? ' · ' . h($numer) : '' ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:ui-monospace,Menlo,Consolas,'Courier New',monospace;font-size:11pt;color:#000;background:#fff;line-height:1.5}
.tekst{white-space:pre-wrap;word-wrap:break-word;max-width:210mm;margin:0 auto;padding:20mm}
@media print{
  .no-print{display:none!important}
  .tekst{padding:0;max-width:none}
  @page{size:A4;margin:20mm}
}
.action-bar{position:fixed;top:0;left:0;right:0;background:#1e293b;color:#fff;
            padding:6px 16px;display:flex;align-items:center;gap:10px;z-index:999;
            font-family:system-ui,sans-serif;font-size:.85rem}
.action-bar button,.action-bar a{padding:4px 14px;border-radius:4px;font-size:.82rem;cursor:pointer;border:none}
.btn-print{background:#3b82f6;color:#fff}
.btn-copy{background:#475569;color:#fff}
.btn-close-bar{background:#475569;color:#fff;margin-left:auto;text-decoration:none;display:inline-block}
</style>
</head>
<body>

<div class="action-bar no-print">
  <button class="btn-print" onclick="window.print()">⎙ Drukuj / Zapisz PDF</button>
  <button class="btn-copy" onclick="navigator.clipboard.writeText(document.getElementById('tekst').innerText).then(()=>{this.textContent='✓ Skopiowano'})">⧉ Kopiuj tekst</button>
  <span style="color:#94a3b8">Dane do rachunku<?= $numer ? ' · ' . h($numer) : '' ?></span>
  <a class="btn-close-bar" href="javascript:window.close()">✕ Zamknij</a>
</div>
<div style="height:34px" class="no-print"></div>

<pre class="tekst" id="tekst"><?= h($tekst) ?></pre>

</body>
</html>
