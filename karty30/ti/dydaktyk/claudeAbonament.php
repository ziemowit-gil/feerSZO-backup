<?php
/**
 * karty30/ti/dydaktyk/claudeAbonament.php — „Abonament Claude” w panelu dydaktyka: wejście routera modułu modules/claude_billing
 * (sesja i CSRF panelu; kadra zarządzająca). Adresy: claudeAbonament.php (pulpit), ?_route=/abonament/{id}, ?_route=/test (test całego przebiegu).
 */
// Licencje są teraz w Panelu Licencji (Administrator Licencji, logowanie Microsoft 365 / SZO) — polecenie 2026-11-10: znikają z panelu kierownika.
$_qs = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /licencje/claudeAbonament.php' . ($_qs !== '' ? '?' . $_qs : ''), true, ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? 302 : 307);
exit;
