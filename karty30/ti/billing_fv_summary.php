<?php
/**
 * karty30/ti/billing_fv_summary.php - Podsumowanie POZYCJI DO FAKTURY VAT (PDF).
 *
 * GET (jeden z wariantów):
 *   ?id=N                                - jedno rozliczenie
 *   ?course_id=N&month=M&year=R          - wszystkie rozliczenia grupy w miesiącu
 *   ?client_id=N&month=M&year=R          - wszystkie rozliczenia kursanta w miesiącu
 * Dostęp: pracownik karty30 (zapis) / administrator. Wynik: tylko PDF.
 * Generator wspólny z endpointem kierownika: includes/ti_fv_summary.php.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_fv_summary.php';

k30_require_access();
if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

ti_fv_summary_output(
    (int)($_GET['id'] ?? 0),
    (int)($_GET['course_id'] ?? 0),
    (int)($_GET['client_id'] ?? 0),
    max(1, min(12, (int)($_GET['month'] ?? date('n')))),
    (int)($_GET['year'] ?? date('Y'))
);
