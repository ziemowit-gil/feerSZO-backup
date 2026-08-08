<?php
/**
 * karty30/ti/instructor_monthly.php — Raport MIESIĘCZNY per prowadzący → PDF.
 * Sekcja na prowadzącego: jego grupy z liczbą lekcji odbytych/odwołanych i frekwencją grupy,
 * plus podsumowanie prowadzącego (łączne lekcje + śr. frekwencja). Tylko frekwencja (bez kwot).
 * GET: ?m=YYYY-MM (domyślnie bieżący), ?instructor_id=N (opcjonalnie — jeden prowadzący).
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

$ym = (string)($_GET['m'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
[$year, $month] = array_map('intval', explode('-', $ym));
$from = sprintf('%04d-%02d-01', $year, $month);
$to   = date('Y-m-t', strtotime($from));

$report_title = 'Raport miesięczny — per prowadzący · ' . (TI_PR_MONTHS_PL[$month] ?? '') . ' ' . $year;
$file_name    = 'raport_prowadzacy_' . $ym;
$err_tag      = 'instructor_monthly ' . $ym;

require __DIR__ . '/_instructor_report_body.php';
