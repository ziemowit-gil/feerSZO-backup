<?php
/**
 * karty30/ti/dydaktyk/licencjeDydaktyczne.php — „Licencje dydaktyczne (AM5) i Office 365 (AM7)” w panelu dydaktyka:
 * wejście routera modułu modules/ti_licencje_dydaktyczne (sesja i CSRF panelu; tylko kadra zarządzająca).
 * Adresy: licencjeDydaktyczne.php (pulpit), ?_route=/nowy, /nowy-am7, /licencje/{id}, /wzor/AM7-2 …
 */
// Licencje są teraz w Panelu Licencji (Administrator Licencji, logowanie Microsoft 365 / SZO) — polecenie 2026-11-10: znikają z panelu kierownika.
$_qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /licencje/licencjeDydaktyczne.php' . ($_qs !== '' ? '?' . $_qs : ''), true, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 302 : 307);
exit;
