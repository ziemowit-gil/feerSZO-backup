<?php
/**
 * karty30/ti/dydaktyk/billing_fv_summary.php — Podsumowanie pozycji do FVAT (PDF)
 * dla kierownika (sesja panelu dydaktyka — current_user() jest tu puste).
 * Parametry jak w karty30/ti/billing_fv_summary.php; generator wspólny.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_fv_summary.php';

if (!dyd_is_staff()) { http_response_code(403); die('Brak uprawnień.'); }

ti_fv_summary_output(
    (int)($_GET['id'] ?? 0),
    (int)($_GET['course_id'] ?? 0),
    (int)($_GET['client_id'] ?? 0),
    max(1, min(12, (int)($_GET['month'] ?? date('n')))),
    (int)($_GET['year'] ?? date('Y'))
);
