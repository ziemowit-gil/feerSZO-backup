<?php
/**
 * cli/ti_protocols.php — autotesty protokołów zajęć TI i mostu do EZD.
 * Oba działają w transakcji, którą wycofują — w bazie nic nie zostaje.
 * Uruchamiane też z zakładki „Testy” kierownika (tryb in-process).
 *
 *   php cli/ti_protocols.php selftest
 *       Zatwierdzenie protokołu miesięcznego otwiera następny miesiąc (bez
 *       duplikatów po odblokowaniu i ponownym zatwierdzeniu); „Zamknij i
 *       archiwizuj” blokuje tworzenie/zatwierdzanie/odblokowanie protokołów
 *       i zapisy (strażnik), grupa znika z zaległych; „Przywróć” zdejmuje blokadę.
 *       EZD w tym teście wyłączone (TI_PROT_EZD_OFF).
 *
 *   php cli/ti_protocols.php ezd-selftest
 *       Zatwierdzony protokół → koszulka w segregatorze klasy JRWA 384 z pismem
 *       i PDF; ponowne zatwierdzenie po odblokowaniu → ta sama koszulka, drugie
 *       pismo. Bez synchronizacji z SharePoint (EZD_NO_SP_SYNC); pliki PDF
 *       zapisane w uploads/ezd/ są usuwane po wycofaniu transakcji.
 *
 * Kod wyjścia: 0 = OK, 1 = błąd, 2 = brak danych / EZD wyłączone.
 */
if (PHP_SAPI !== 'cli' && !defined('SZO_CLI_INPROC')) { http_response_code(403); exit("Tylko CLI.\n"); }

$base = dirname(__DIR__);
defined('BOOTSTRAP_CHECKED') || define('BOOTSTRAP_CHECKED', true);
defined('APP_INSTALLED') || define('APP_INSTALLED', true);
defined('TI_PROTOCOLS_NO_AUTO_BACKFILL') || define('TI_PROTOCOLS_NO_AUTO_BACKFILL', true);
require_once $base . '/config.php';
require_once $base . '/includes/cli_inproc.php';
require_once $base . '/includes/db.php';
require_once $base . '/includes/functions.php';
require_once $base . '/includes/karty30.php';
require_once $base . '/includes/ti_protocols.php';
karty30_migrate();
ti_protocols_migrate();
ti_course_close_migrate();

$cmd  = $argv[1] ?? 'help';
$out  = function (string $s = '') { echo $s, "\n"; };
$fail = 0;
$check = function (string $name, bool $ok) use (&$fail, $out) { $out(($ok ? '  ✔ ' : '  ✘ ') . $name); if (!$ok) $fail++; };
$throws = function (callable $f): string { try { $f(); return ''; } catch (\Throwable $e) { return $e->getMessage() ?: get_class($e); } };
$uid = (int)(db_one("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")['id'] ?? 0);

/** Grupa + miniony miesiąc z odbytymi lekcjami, bez zatwierdzonego protokołu (i bez blokady). */
$pick = function () {
    return db_one(
        "SELECT s.course_id, c.name, strftime('%Y-%m', s.lesson_date) AS ym
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
          WHERE s.status IN ('held','individual_change','remote_material')
            AND strftime('%Y-%m', s.lesson_date) < strftime('%Y-%m','now','localtime')
            AND (c.closed_at IS NULL OR c.closed_at='')
            AND NOT EXISTS (SELECT 1 FROM k30_ti_protocols p WHERE p.course_id=s.course_id
                             AND p.year_month=strftime('%Y-%m', s.lesson_date) AND p.status='approved')
          GROUP BY s.course_id, ym ORDER BY ym DESC LIMIT 1"
    );
};
$next_ym = fn(string $ym) => date('Y-m', strtotime($ym . '-01 +1 month'));

switch ($cmd) {

case 'selftest':
    defined('TI_PROT_EZD_OFF') || define('TI_PROT_EZD_OFF', true);
    $p = $pick();
    if (!$p) { $out('Brak grupy z odbytymi lekcjami w minionym miesiącu bez zatwierdzonego protokołu.'); cli_exit(2); }
    $cid = (int)$p['course_id']; $ym = (string)$p['ym']; $nx = $next_ym($ym);
    $out("Grupa „{$p['name']}” (#{$cid}), miesiąc {$ym}");

    db()->beginTransaction();
    try {
        // Grupa aktywna — żeby test otwierania następnego miesiąca był jednoznaczny
        db()->prepare("UPDATE k30_ti_courses SET is_active=1, status='active' WHERE id=?")->execute([$cid]);
        db()->prepare("DELETE FROM k30_ti_protocols WHERE course_id=? AND year_month=?")->execute([$cid, $nx]);

        $prot = ti_protocol_get_or_create_for_month($cid, $ym);
        $e = $throws(fn() => ti_protocol_approve((int)$prot['id'], $uid, 'Autotest'));
        $check('zatwierdzenie protokołu' . ($e ? " — {$e}" : ''), $e === '');
        $st = db_one("SELECT status FROM k30_ti_protocols WHERE id=?", [(int)$prot['id']])['status'] ?? '';
        $check('status = approved', $st === 'approved');
        $n_next = fn() => (int)db_one("SELECT COUNT(*) AS n FROM k30_ti_protocols WHERE course_id=? AND year_month=?", [$cid, $nx])['n'];
        $check("otwarty protokół na następny miesiąc ({$nx})", $n_next() === 1
            && (db_one("SELECT status FROM k30_ti_protocols WHERE course_id=? AND year_month=?", [$cid, $nx])['status'] ?? '') === 'open');

        $e = $throws(fn() => ti_protocol_unlock((int)$prot['id'], $uid, 'Autotest', 'autotest'));
        $check('odblokowanie' . ($e ? " — {$e}" : ''), $e === '');
        $e = $throws(fn() => ti_protocol_approve((int)$prot['id'], $uid, 'Autotest'));
        $check('ponowne zatwierdzenie' . ($e ? " — {$e}" : ''), $e === '');
        $check('następny miesiąc bez duplikatu', $n_next() === 1);

        // Zamknij i archiwizuj
        ti_course_close($cid, $uid, 'Autotest');
        $c = db_one("SELECT status, is_active, closed_at FROM k30_ti_courses WHERE id=?", [$cid]);
        $check('zamknięcie: status archived + closed_at', ($c['status'] ?? '') === 'archived' && !empty($c['closed_at']) && (int)$c['is_active'] === 0);
        $check('strażnik zapisu blokuje (course_id)', ti_course_closed_guard(['course_id' => $cid]) !== null);
        $sid = (int)(db_one("SELECT id FROM k30_ti_sessions WHERE course_id=? LIMIT 1", [$cid])['id'] ?? 0);
        if ($sid) $check('strażnik zapisu blokuje (session_id lekcji grupy)', ti_course_closed_guard(['session_id' => $sid]) !== null);
        $check('odblokowanie protokołu zablokowane', $throws(fn() => ti_protocol_unlock((int)$prot['id'], $uid, 'Autotest', 'x')) !== '');
        $other = date('Y-m', strtotime($ym . '-01 -24 months'));
        $check('tworzenie nowego protokołu zablokowane', $throws(fn() => ti_protocol_get_or_create_for_month($cid, $other)) !== '');
        $open_next = db_one("SELECT id FROM k30_ti_protocols WHERE course_id=? AND year_month=?", [$cid, $nx]);
        if ($open_next) $check('zatwierdzenie protokołu zamkniętej grupy zablokowane', $throws(fn() => ti_protocol_approve((int)$open_next['id'], $uid, 'Autotest')) !== '');
        $check('zamknięta grupa poza zaległymi (miesięczne)', !array_filter(ti_protocols_overdue_months(), fn($r) => (int)$r['course_id'] === $cid));
        $check('brak auto-otwarcia miesiąca dla zamkniętej grupy', ti_protocol_open_next_month(['course_id' => $cid, 'year_month' => $nx]) === null);

        ti_course_reopen($cid);
        $check('„Przywróć z archiwum” zdejmuje blokadę', ti_course_closed_guard(['course_id' => $cid]) === null);
    } finally {
        db()->rollBack();
        ti_course_closed($cid, true);
    }
    $out($fail ? "BŁĘDY: {$fail}" : 'OK — wszystkie testy przeszły (transakcja wycofana).');
    cli_exit($fail ? 1 : 0);

case 'ezd-selftest':
    defined('EZD_NO_SP_SYNC') || define('EZD_NO_SP_SYNC', true);
    require_once $base . '/includes/ezd.php';
    require_once $base . '/modules/ti_protokoly_ezd/logic/ti_protokoly_ezd.php';
    if (!ti_prot_ezd_active()) { $out('EZD wyłączone (moduł ezd_enabled albo ti_protocols_ezd_auto=0) — nie ma czego testować.'); cli_exit(2); }
    $p = $pick();
    if (!$p) { $out('Brak grupy z odbytymi lekcjami w minionym miesiącu bez zatwierdzonego protokołu.'); cli_exit(2); }
    $cid = (int)$p['course_id']; $ym = (string)$p['ym'];
    $out("Grupa „{$p['name']}” (#{$cid}), miesiąc {$ym}");
    $max_zal = (int)(db_one("SELECT COALESCE(MAX(id),0) AS m FROM ezd_zalaczniki")['m'] ?? 0);
    $files = [];

    db()->beginTransaction();
    try {
        $prot = ti_protocol_get_or_create_for_month($cid, $ym);
        $pid  = (int)$prot['id'];
        $e = $throws(fn() => ti_protocol_approve($pid, $uid, 'Autotest'));
        $check('zatwierdzenie protokołu' . ($e ? " — {$e}" : ''), $e === '');
        $sid = ti_prot_ezd_sprawa_id($pid);
        $check('koszulka EZD założona (ref_type=ti_protocol)', (bool)$sid);
        if ($sid) {
            $s = db_one("SELECT s.znak_sprawy, t.symbol, t.rok, j.symbol AS jrwa, j.parent_id,
                                (SELECT symbol FROM ezd_jrwa WHERE id=j.parent_id) AS parent_sym
                           FROM ezd_sprawy s JOIN ezd_teczki t ON t.id=s.teczka_id
                           LEFT JOIN ezd_jrwa j ON j.id=t.jrwa_id WHERE s.id=?", [$sid]);
            $out('    koszulka ' . ($s['znak_sprawy'] ?? '?') . ', segregator ' . ($s['symbol'] ?? '?') . '/' . ($s['rok'] ?? '?'));
            $check('segregator i klasa JRWA 384', ($s['symbol'] ?? '') === '384' && ($s['jrwa'] ?? '') === '384');
            $check('klasa 384 pod 38 (jeśli 38 istnieje)', !db_one("SELECT 1 FROM ezd_jrwa WHERE symbol='38'") || ($s['parent_sym'] ?? '') === '38');
            $n_p = fn() => (int)db_one("SELECT COUNT(*) AS n FROM ezd_pisma WHERE sprawa_id=?", [$sid])['n'];
            $n_z = fn() => (int)db_one("SELECT COUNT(*) AS n FROM ezd_zalaczniki WHERE sprawa_id=? AND original_name LIKE '%.pdf'", [$sid])['n'];
            $check('pismo wewnętrzne w koszulce', $n_p() === 1);
            $check('PDF protokołu jako załącznik', $n_z() === 1);

            $e = $throws(fn() => ti_protocol_unlock($pid, $uid, 'Autotest', 'autotest'));
            $e = $e ?: $throws(fn() => ti_protocol_approve($pid, $uid, 'Autotest'));
            $check('odblokowanie i ponowne zatwierdzenie' . ($e ? " — {$e}" : ''), $e === '');
            $check('ta sama koszulka (bez duplikatu)', count(ezd_sprawy_by_ref('ti_protocol', $pid)) === 1);
            $check('drugie pismo + drugi PDF', $n_p() === 2 && $n_z() === 2);
        }
        foreach (db_all("SELECT sprawa_id, filename FROM ezd_zalaczniki WHERE id > ?", [$max_zal]) as $z) {
            $files[] = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'];
        }
    } finally {
        db()->rollBack();
        // Pliki zapisane na dysk nie cofają się z transakcją — sprzątamy je ręcznie
        foreach ($files as $f) {
            @unlink($f);
            $d = dirname($f);
            if (is_dir($d) && count(scandir($d)) <= 2) @rmdir($d);
        }
    }
    $check('pliki testowe usunięte z dysku (' . count($files) . ')', !array_filter($files, 'is_file'));
    $out($fail ? "BŁĘDY: {$fail}" : 'OK — wszystkie testy przeszły (transakcja wycofana, pliki usunięte, bez SharePoint).');
    cli_exit($fail ? 1 : 0);

default:
    $out('Użycie: php cli/ti_protocols.php selftest|ezd-selftest — szczegóły w nagłówku pliku.');
}
