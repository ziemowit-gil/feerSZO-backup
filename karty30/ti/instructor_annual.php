<?php
/**
 * karty30/ti/instructor_annual.php — Raport ROCZNY per prowadzący → PDF.
 * Sekcja na prowadzącego: jego grupy z liczbą lekcji odbytych/odwołanych i frekwencją grupy,
 * plus podsumowanie prowadzącego (łączne lekcje + śr. frekwencja). Tylko frekwencja (bez kwot).
 * GET: ?y=YYYY (domyślnie bieżący), ?instructor_id=N (opcjonalnie — jeden prowadzący).
 * Dostęp: pracownik D3 / administrator. Wynik: tylko PDF.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_participant_report.php';

k30_require_access();
karty30_migrate();

if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

$year = (int)($_GET['y'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$from = sprintf('%04d-01-01', $year);
$to   = sprintf('%04d-12-31', $year);

$report_title = 'Raport roczny — per prowadzący · ' . $year;
$file_name    = 'raport_prowadzacy_rok_' . $year;
$err_tag      = 'instructor_annual ' . $year;

require __DIR__ . '/_instructor_report_body.php';
