<?php
/**
 * karty30/ti/dydaktyk/syllabus_wzor.php — pobranie wzoru pliku CSV sylabusa.
 *
 * ?przyklad=1 → pełny przykładowy sylabus (kurs Pythona) zamiast pustego wzoru.
 * Treść buduje wspólny helper k30_ti_syllabus_csv() (BOM UTF-8, separator „;",
 * CRLF — pod polskiego Excela); te same kolumny czyta k30_ti_curriculum_import_csv().
 */
require_once __DIR__ . '/auth.php';

dyd_require();

$przyklad = !empty($_GET['przyklad']);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . ($przyklad ? 'przyklad-sylabusa.csv' : 'wzor-sylabusa.csv') . '"');
echo k30_ti_syllabus_csv($przyklad);
