<?php
/**
 * crm/offers/print.php — wydruk oferty (HTML) lub pobranie PDF (?pdf=1).
 * Strona samodzielna — celowo bez powłoki CRM.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_offers_migrate();

$id       = (int)($_GET['id'] ?? 0);
$internal = !empty($_GET['internal']);
$offer    = $id ? crm_offer_full($id) : null;
if (!$offer) { http_response_code(404); exit('Oferta nie istnieje.'); }

if (!empty($_GET['pdf'])) {
    $pdf = crm_offer_pdf($id, $internal);
    if ($pdf === null) {
        http_response_code(500);
        exit('Nie udało się wygenerować PDF. Sprawdź log aplikacji.');
    }
    crm_offer_log($id, 'pdf', ['detail' => 'Pobranie PDF' . ($internal ? ' (wersja wewnętrzna)' : '')]);
    $fn = 'Oferta_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$offer['offer_number']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Oferta <?= h($offer['offer_number']) ?></title>
<?= crm_offer_font_link() ?>
<style>
body { margin:0; background:#F3F4F6; font-family:'Lato','Segoe UI',-apple-system,BlinkMacSystemFont,Roboto,sans-serif; }
.sheet { max-width:820px; margin:18px auto; background:#fff; padding:26px 30px; box-shadow:0 2px 12px rgba(0,0,0,.08); }
.bar { max-width:820px; margin:0 auto; padding:8px 4px; display:flex; gap:8px; }
.bar a, .bar button { font-size:.85rem; padding:.35rem .8rem; border-radius:6px; border:1px solid #D1D5DB;
  background:#fff; color:#111827; text-decoration:none; cursor:pointer; }
<?= crm_offer_document_css() ?>
@media print { body { background:#fff } .bar { display:none } .sheet { box-shadow:none; margin:0; max-width:none; padding:0 } }
</style>
</head>
<body>
<div class="bar">
  <button onclick="window.print()">Drukuj</button>
  <a href="print.php?id=<?= $id ?>&pdf=1<?= $internal ? '&internal=1' : '' ?>">Pobierz PDF</a>
  <a href="view.php?id=<?= $id ?>">Wróć do oferty</a>
  <?php if (!$internal): ?><a href="print.php?id=<?= $id ?>&internal=1">Wersja wewnętrzna</a><?php endif; ?>
</div>
<div class="sheet"><div class="of-doc">
<?= crm_offer_document_html($offer, ['internal' => $internal]) ?>
</div></div>
</body>
</html>
