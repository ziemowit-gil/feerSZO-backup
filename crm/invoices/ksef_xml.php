<?php
/**
 * crm/invoices/ksef_xml.php — podgląd XML FA(3) faktury z wynikiem walidacji.
 *
 * Pozwala zobaczyć dokładnie to, co poszłoby do KSeF, i wyłapać braki danych
 * (adres organizacji, NIP, nieobsługiwana stawka) PRZED wysłaniem — odrzucenie
 * po stronie KSeF jest wolniejsze i mniej czytelne.
 */

declare(strict_types=1);

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ksef.php';

require_login();
require_module_enabled('invoices_enabled', 'Moduł Faktury');
if (!is_admin() && !can_write('crm')) { http_response_code(403); exit('Brak uprawnień.'); }

$inv = invoice_get((int)($_GET['id'] ?? 0));
if (!$inv) { http_response_code(404); exit('Nie znaleziono faktury.'); }

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $xml = ksef_invoice_xml($inv);
} catch (\Throwable $e) {
    echo "NIE UDAŁO SIĘ ZBUDOWAĆ XML\n\n" . $e->getMessage() . "\n";
    exit;
}

$v = ksef_validate_xml($xml);
echo $v['ok']
    ? "WALIDACJA SCHEMATU FA(3): OK\n\n"
    : "WALIDACJA SCHEMATU FA(3): BŁĘDY\n" . implode("\n", array_map(fn($e) => '  - ' . $e, $v['errors'])) . "\n\n";

// Wcięcia tylko na potrzeby podglądu — do KSeF idzie wersja bez formatowania.
$d = new DOMDocument();
$d->preserveWhiteSpace = false;
$d->formatOutput = true;
$d->loadXML($xml);
echo $d->saveXML();
