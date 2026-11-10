<?php
/**
 * karty30/ti/dydaktyk/licencjeFundacji.php — „Pula licencji Fundacji” w panelu dydaktyka:
 * wejście routera modułu modules/license_pool (wspólny z IT SZO; sesja i CSRF panelu; kadra zarządzająca).
 */
// Licencje są teraz w Panelu Licencji (Administrator Licencji, logowanie Microsoft 365 / SZO) — polecenie 2026-11-10: znikają z panelu kierownika.
$_qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /licencje/licencjeFundacji.php' . ($_qs !== '' ? '?' . $_qs : ''), true, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 302 : 307);
exit;
