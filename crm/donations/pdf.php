<?php
/**
 * crm/donations/pdf.php — wydanie dokumentu darowizny.
 *
 *   ?contact=ID&year=RRRR — roczne potwierdzenie darowizn pieniężnych,
 *   ?id=ID                — oświadczenie o przyjęciu darowizny rzeczowej.
 *
 * Dokumenty powstają na żądanie, nie są przechowywane: zestawienie roczne musi
 * odzwierciedlać rejestr z chwili wydania, a nie stan z pierwszego wygenerowania.
 * Dopisana później wpłata inaczej nie trafiłaby na potwierdzenie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/donation_pdf.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';

require_login();
require_module_enabled('donations_enabled', 'Moduł Darowizny');
crm_require('donations', 'read');

$uid        = (int)(current_user()['id'] ?? 0);
$donation_id = (int)($_GET['id'] ?? 0);
$contact_id  = (int)($_GET['contact'] ?? 0);
$year        = (int)($_GET['year'] ?? date('Y'));

try {
    if ($donation_id > 0) {
        $res = donation_pdf_acceptance($donation_id, $uid);
        if (!$res) {
            http_response_code(404);
            exit('Oświadczenie o przyjęciu wystawia się tylko dla darowizny rzeczowej.');
        }
    } elseif ($contact_id > 0) {
        $res = donation_pdf_annual($contact_id, $year, $uid);
        if (!$res) {
            http_response_code(404);
            exit('Ten darczyńca nie ma w ' . $year . ' r. darowizn pieniężnych do potwierdzenia.');
        }
    } else {
        http_response_code(400);
        exit('Podaj darowiznę (id) albo darczyńcę i rok (contact, year).');
    }
} catch (\Throwable $e) {
    http_response_code(500);
    exit('Nie udało się wygenerować dokumentu: ' . h($e->getMessage()));
}

[$pdf, $filename] = $res;

header('Content-Type: application/pdf');
header('Content-Length: ' . (string)strlen($pdf));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
