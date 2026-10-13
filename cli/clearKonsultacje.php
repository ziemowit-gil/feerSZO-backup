<?php
/**
 * cli/clearKonsultacje.php — czyszczenie danych konsultacji (i szkoleń krótkich SzK) z CLI.
 *
 *   php cli/clearKonsultacje.php                       # podgląd: dane KONT TESTOWYCH (nic nie usuwa)
 *   php cli/clearKonsultacje.php --apply               # usuwa dane kont testowych (dydaktyk/kontaTestowe.php):
 *                                                      #   osoby testowe z kartami, rezerwacjami, kolejką, kontami panelu,
 *                                                      #   skanami dokumentów i szkoleniami SzK „TEST — …”
 *   php cli/clearKonsultacje.php --all                 # podgląd: WSZYSTKIE karty konsultacji, rezerwacje terminów, kolejka
 *   php cli/clearKonsultacje.php --all --przed=2026-01-01 --apply   # tylko starsze niż data (karty, rezerwacje, zamknięte wpisy kolejki)
 *   php cli/clearKonsultacje.php --all --konta --apply # + konta beneficjentów konsultacji (tryb 4, login k1234) bez innych danych
 *   php cli/clearKonsultacje.php --all --szk --apply   # + wszystkie szkolenia krótkie SzK (lekcje, zapisy, obecność, ich karty)
 *   php cli/clearKonsultacje.php --all --liczniki --apply  # + zeruje liczniki godzin beneficjentów (k30_clients.used/used_paid)
 *
 * Przed --apply robi kopię bazy SQLite obok pliku (umowy.db.bak-konsultacje-RRRRMMDD-GGMMSS) i wpis w audycie.
 * Nie rusza: kartoteki beneficjentów (poza osobami testowymi), umów PFRON, cenników, sal, ustawień, słowników.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Tylko z CLI.'); }
define('APP_CLI', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/karty30.php';
require_once dirname(__DIR__) . '/modules/ti_test_accounts/logic/testAccounts.php';

$o = getopt('', ['apply', 'all', 'konta', 'szk', 'liczniki', 'przed:']);
$apply = isset($o['apply']); $all = isset($o['all']);
$before = (string)($o['przed'] ?? '');
if ($before !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) { fwrite(STDERR, "Zła data --przed (RRRR-MM-DD): {$before}\n"); exit(1); }
if (!$all && (isset($o['konta']) || isset($o['szk']) || isset($o['liczniki']) || $before !== '')) { fwrite(STDERR, "--konta, --szk, --liczniki i --przed działają tylko z --all.\n"); exit(1); }
karty30_migrate();
TiStudentDocs::migrate();

/** Lista operacji: [opis, SQL liczący, SQL usuwający, parametry]. */
$ops = [];
$in = fn(string $sql) => "IN ($sql)";
if (!$all) {
    $M = TiTestAccounts::MARKER;
    $cl = "SELECT id FROM k30_clients WHERE notes = ?";
    $szk = "SELECT id FROM k30_ti_courses WHERE simple_mode > 0 AND internal_desc LIKE 'TEST —%'";
    $ops[] = ['karty konsultacji osób testowych i szkoleń testowych', "SELECT COUNT(*) n FROM k30_consultations WHERE client_id " . $in($cl) . " OR course_id " . $in($szk), "DELETE FROM k30_consultations WHERE client_id " . $in($cl) . " OR course_id " . $in($szk), [$M]];
    $ops[] = ['rezerwacje terminów osób testowych', "SELECT COUNT(*) n FROM k30_schedules WHERE client_id " . $in($cl), "DELETE FROM k30_schedules WHERE client_id " . $in($cl), [$M]];
    $ops[] = ['kolejka oczekujących osób testowych', "SELECT COUNT(*) n FROM k30_waiting_list WHERE client_id " . $in($cl), "DELETE FROM k30_waiting_list WHERE client_id " . $in($cl), [$M]];
    $ops[] = ['obecności w szkoleniach testowych', "SELECT COUNT(*) n FROM k30_ti_attendance WHERE session_id IN (SELECT id FROM k30_ti_sessions WHERE course_id " . $in($szk) . ")", "DELETE FROM k30_ti_attendance WHERE session_id IN (SELECT id FROM k30_ti_sessions WHERE course_id " . $in($szk) . ")", []];
    $ops[] = ['lekcje szkoleń testowych', "SELECT COUNT(*) n FROM k30_ti_sessions WHERE course_id " . $in($szk), "DELETE FROM k30_ti_sessions WHERE course_id " . $in($szk), []];
    $ops[] = ['zapisy do szkoleń testowych', "SELECT COUNT(*) n FROM k30_ti_enrollments WHERE course_id " . $in($szk), "DELETE FROM k30_ti_enrollments WHERE course_id " . $in($szk), []];
    $ops[] = ['szkolenia krótkie „TEST —”', "SELECT COUNT(*) n FROM k30_ti_courses WHERE id " . $in($szk), "DELETE FROM k30_ti_courses WHERE id " . $in($szk), []];
    $ops[] = ['konta panelu kursanta osób testowych', "SELECT COUNT(*) n FROM k30_ti_student_accounts WHERE client_id " . $in($cl), "DELETE FROM k30_ti_student_accounts WHERE client_id " . $in($cl), [$M]];
    $ops[] = ['osoby testowe (kartoteka)', "SELECT COUNT(*) n FROM k30_clients WHERE notes = ?", "DELETE FROM k30_clients WHERE notes = ?", [$M]];
} else {
    $w = $before !== '' ? " WHERE DATE(consultation_datetime) < ?" : ''; $p = $before !== '' ? [$before] : [];
    $ops[] = ['karty konsultacji' . ($before ? " sprzed $before" : ''), "SELECT COUNT(*) n FROM k30_consultations$w", "DELETE FROM k30_consultations$w", $p];
    $w = $before !== '' ? " WHERE DATE(start_time) < ?" : '';
    $ops[] = ['rezerwacje terminów' . ($before ? " sprzed $before" : ''), "SELECT COUNT(*) n FROM k30_schedules$w", "DELETE FROM k30_schedules$w", $p];
    $w = $before !== '' ? " WHERE status IN ('scheduled','done','cancelled') AND DATE(updated_at) < ?" : '';
    $ops[] = ['kolejka oczekujących' . ($before ? " (zamknięte wpisy sprzed $before)" : ''), "SELECT COUNT(*) n FROM k30_waiting_list$w", "DELETE FROM k30_waiting_list$w", $p];
    if (isset($o['szk'])) {
        $szk = "SELECT id FROM k30_ti_courses WHERE simple_mode > 0";
        $ops[] = ['karty terminów szkoleń krótkich', "SELECT COUNT(*) n FROM k30_consultations WHERE course_id " . $in($szk), "DELETE FROM k30_consultations WHERE course_id " . $in($szk), []];
        $ops[] = ['obecności w szkoleniach krótkich', "SELECT COUNT(*) n FROM k30_ti_attendance WHERE session_id IN (SELECT id FROM k30_ti_sessions WHERE course_id " . $in($szk) . ")", "DELETE FROM k30_ti_attendance WHERE session_id IN (SELECT id FROM k30_ti_sessions WHERE course_id " . $in($szk) . ")", []];
        $ops[] = ['lekcje szkoleń krótkich', "SELECT COUNT(*) n FROM k30_ti_sessions WHERE course_id " . $in($szk), "DELETE FROM k30_ti_sessions WHERE course_id " . $in($szk), []];
        $ops[] = ['zapisy do szkoleń krótkich', "SELECT COUNT(*) n FROM k30_ti_enrollments WHERE course_id " . $in($szk), "DELETE FROM k30_ti_enrollments WHERE course_id " . $in($szk), []];
        $ops[] = ['szkolenia krótkie SzK', "SELECT COUNT(*) n FROM k30_ti_courses WHERE simple_mode > 0", "DELETE FROM k30_ti_courses WHERE simple_mode > 0", []];
    }
    if (isset($o['konta'])) {
        // tylko konta trybu 4 bez zapisów do grup i bez rozliczeń TI — beneficjenci wyłącznie konsultacji
        $acc = "simple_mode = 4 AND client_id NOT IN (SELECT client_id FROM k30_ti_enrollments) AND client_id NOT IN (SELECT client_id FROM k30_ti_billing)";
        $ops[] = ['konta beneficjentów konsultacji (tryb 4, login k1234)', "SELECT COUNT(*) n FROM k30_ti_student_accounts WHERE $acc", "DELETE FROM k30_ti_student_accounts WHERE $acc", []];
    }
    if (isset($o['liczniki'])) {
        $ops[] = ['liczniki godzin beneficjentów (used, used_paid → 0)', "SELECT COUNT(*) n FROM k30_clients WHERE used <> 0 OR used_paid <> 0", "UPDATE k30_clients SET used = 0, used_paid = 0", []];
    }
}

echo ($apply ? 'USUWANIE' : 'PODGLĄD (nic nie usuwam — dodaj --apply)') . ' — ' . ($all ? 'WSZYSTKIE dane konsultacji' : 'dane kont testowych') . "\n";
$total = 0;
foreach ($ops as [$label, $cnt, , $par]) {
    try { $n = (int)(db_one($cnt, $par)['n'] ?? 0); } catch (\Throwable $e) { $n = 0; }
    printf("  %-62s %6d\n", $label, $n); $total += $n;
}
// skany dokumentów osób testowych / kont do usunięcia
$files = [];
foreach (db_all("SELECT doc_path FROM k30_ti_student_accounts WHERE doc_path <> '' AND client_id IN (SELECT id FROM k30_clients WHERE notes = ?)", [TiTestAccounts::MARKER]) as $r) {
    if (!$all) $files[] = rtrim(UPLOAD_DIR, '/') . '/' . $r['doc_path'];
}
if ($files) printf("  %-62s %6d\n", 'skany dokumentów potwierdzających (pliki)', count($files));
if (!$apply) { echo $total ? "\nAby usunąć: dodaj --apply (przed usunięciem zrobię kopię bazy).\n" : "\nNic do usunięcia.\n"; exit(0); }
if (!$total && !$files) { echo "Nic do usunięcia.\n"; exit(0); }

if (defined('DB_TYPE') && DB_TYPE === 'sqlite' && defined('DB_PATH') && is_file(DB_PATH)) {
    $bak = DB_PATH . '.bak-konsultacje-' . date('Ymd-His');
    if (!@copy(DB_PATH, $bak)) { fwrite(STDERR, "Nie udało się zrobić kopii bazy ($bak) — przerywam.\n"); exit(1); }
    echo "Kopia bazy: $bak\n";
}
$pdo = db(); $pdo->beginTransaction();
try {
    foreach ($ops as [$label, , $del, $par]) { $st = $pdo->prepare($del); $st->execute($par); printf("  usunięto/zmieniono: %-50s %6d\n", $label, $st->rowCount()); }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack(); fwrite(STDERR, "Błąd — nic nie zmieniono: " . $e->getMessage() . "\n"); exit(1);
}
foreach ($files as $f) if (is_file($f)) @unlink($f);
try {
    require_once dirname(__DIR__) . '/modules/audit_logs/logic/audit_logs.php';
    audit_log('ti.consultations.clear_cli', ['scope' => $all ? 'all' : 'test', 'before' => $before, 'konta' => isset($o['konta']), 'szk' => isset($o['szk']), 'liczniki' => isset($o['liczniki']), 'rows' => $total], null);
} catch (\Throwable $e) {}
echo "Gotowe.\n";
