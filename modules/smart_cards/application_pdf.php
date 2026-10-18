<?php
/**
 * modules/smart_cards/application_pdf.php — wydruk PDF wniosku o kartę (formularz WK-1).
 * Dostęp: obsługa (admin/editor) albo sam wnioskujący (z „Mojej karty”).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/lib/applicationPdf.php';

require_login();
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$me      = current_user();
$isStaff = in_array($me['role'] ?? '', ['admin', 'editor'], true);
$svc     = new SmartCardService($me);
$app     = $svc->application((int)($_GET['id'] ?? 0));
if (!$app || (!$isStaff && (int)$app['user_id'] !== (int)$me['id'])) {
    http_response_code(404);
    exit('Wniosek nie istnieje.');
}
if (!$isStaff) ika_require();

try {
    $pdf = scardApplicationPdf($svc, $app);
} catch (\Throwable $e) {
    error_log('[smart_cards/application_pdf] ' . $e->getMessage());
    http_response_code(500);
    exit('Nie udało się przygotować wydruku.');
}
audit_log('smart_cards.application_printed', ['application_id' => (int)$app['id']]);
$name = 'wniosek_karta_' . str_replace('/', '-', $app['number']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, no-store');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
