<?php
/**
 * modules/ti_test_accounts/logic/testFilter.php — wykluczanie kont testowych z raportów (lekki plik, bez zależności).
 *
 * Osoby testowe: k30_clients.notes = TI_TEST_MARKER (zakłada dydaktyk/kontaTestowe.php — TiTestAccounts);
 * szkolenia testowe: k30_ti_courses.internal_desc zaczyna się od „TEST —”.
 * Użycie w SQL:  "… AND " . ti_test_client_sql('s.client_id') . " AND " . ti_test_course_sql('s.course_id')
 */
if (!defined('TI_TEST_MARKER')) define('TI_TEST_MARKER', 'KONTO TESTOWE TI (konsultacje / szkolenia krótkie)');

/** Warunek „to nie jest osoba testowa” dla kolumny z id beneficjenta (k30_clients.id). */
function ti_test_client_sql(string $col): string
{
    return "$col NOT IN (SELECT id FROM k30_clients WHERE notes = '" . str_replace("'", "''", TI_TEST_MARKER) . "')";
}

/** Warunek „to nie jest szkolenie testowe” dla kolumny z id kursu (k30_ti_courses.id); NULL przechodzi. */
function ti_test_course_sql(string $col): string
{
    return "COALESCE($col, 0) NOT IN (SELECT id FROM k30_ti_courses WHERE internal_desc LIKE 'TEST —%')";
}
