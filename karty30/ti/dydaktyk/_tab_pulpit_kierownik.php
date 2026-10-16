<?php
/**
 * _tab_pulpit_kierownik.php — DANE części kierownika na Pulpicie (dyd_is_staff()).
 * Dołączany z _tab_pulpit.php, który je rysuje (od 2026-10-16 uproszczony pulpit):
 *   $_kp_attn  — sprawy instytucji z licznikiem > 0 → dopisywane do „Do zrobienia”,
 *   $_kp_ok    — sprawy „w porządku”, $_kp_counts, $_kp_leaves, $_kp_at → „Pokaż więcej”.
 *
 * Każde zapytanie w osobnym try — brak tabeli modułu (np. świeża instalacja)
 * nie może wywrócić pulpitu; taka pozycja po prostu się nie pokazuje.
 */
require_once dirname(__DIR__, 3) . '/includes/ti_protocols.php';
require_once dirname(__DIR__, 3) . '/includes/ti_leaves.php';

$_kp_try = static function (callable $f, $fallback = null) {
    try { return $f(); } catch (\Throwable $e) { return $fallback; }
};
$_kp_today = date('Y-m-d');

// ── Liczniki — w cache Redis na 2 min, gdy włączony (modules/redis_cache);
//    bez Redisa liczone przy każdym wejściu jak dotąd. Klucz per tenant (prefiks).
$_kp_data = szo_cache_remember('ti:pulpit_kierownik:v1', 120, function () use ($_kp_try, $_kp_today): array {
    // ── Liczniki ──────────────────────────────────────────────────────────────────
    $_kp_rows = [];   // [etykieta, liczba, opis, adres, ikona, wariant]

    $_kp_prot = $_kp_try(fn() => count(ti_protocols_overdue()) + count(ti_protocols_overdue_months()), null);
    if ($_kp_prot !== null) $_kp_rows[] = ['Zaległe protokoły', $_kp_prot,
        'okresy lub miesiące zakończone, protokół niezatwierdzony', 'protokoly.php', 'exclamation-octagon', 'danger'];

    $_kp_debt = $_kp_try(fn() => db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(amount + COALESCE(adjustment,0) - COALESCE(paid_amount,0)),0) AS s
           FROM k30_ti_billing
          WHERE status = 'issued' AND due_date IS NOT NULL AND due_date < ?
            AND amount + COALESCE(adjustment,0) - COALESCE(paid_amount,0) > 0.005", [$_kp_today]), null);
    if ($_kp_debt !== null) $_kp_rows[] = ['Rozliczenia po terminie', (int)$_kp_debt['n'],
        (int)$_kp_debt['n'] ? 'do zapłaty łącznie ' . number_format((float)$_kp_debt['s'], 2, ',', ' ') . ' zł' : 'wszystkie opłacone w terminie',
        'billing.php', 'receipt', 'danger'];

    $_kp_sc = $_kp_try(function () {
        require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerWorkflow.php';
        return sc_pending_count();
    }, null);
    if ($_kp_sc !== null) $_kp_rows[] = ['Zgłoszenia trenerów', $_kp_sc,
        'niedostępności i wnioski o zmianę terminu czekają na decyzję', 'zgloszenia.php', 'inbox', 'warning'];

    $_kp_defer = $_kp_try(fn() => (int)db_one("SELECT COUNT(*) AS n FROM k30_ti_payment_deferrals WHERE status = 'pending'")['n'], null);
    if ($_kp_defer !== null) $_kp_rows[] = ['Wnioski o odroczenie płatności', $_kp_defer,
        'czekają na decyzję', 'billing.php', 'hourglass-split', 'warning'];

    $_kp_unen = $_kp_try(fn() => (int)db_one("SELECT COUNT(*) AS n FROM k30_ti_unenroll_requests WHERE status = 'pending_admin'")['n'], null);
    if ($_kp_unen !== null) $_kp_rows[] = ['Wypisy z zajęć', $_kp_unen,
        'potwierdzone przez opiekuna, czekają na decyzję', 'klienci.php', 'person-dash', 'warning'];

    $_kp_gaps = $_kp_try(function () {
        require_once dirname(__DIR__, 3) . '/includes/ti_audit.php';
        $d = 0; $g = 0; $who = 0;
        foreach (ti_audit_gaps_by_instructor() as $x) {
            $d += (int)($x['docs_total'] ?? 0); $g += (int)($x['grades_total'] ?? 0);
            if (($x['docs_total'] ?? 0) || ($x['grades_total'] ?? 0)) $who++;
        }
        return ['docs' => $d, 'grades' => $g, 'who' => $who];
    }, null);
    if ($_kp_gaps !== null) $_kp_rows[] = ['Braki w dziennikach', $_kp_gaps['docs'] + $_kp_gaps['grades'],
        $_kp_gaps['who'] ? $_kp_gaps['docs'] . ' lekcji bez dokumentacji, ' . $_kp_gaps['grades'] . ' kursantów bez ocen — ' . $_kp_gaps['who'] . ' prowadz.' : 'dzienniki uzupełnione',
        'audyt_dziennikow.php', 'clipboard2-check', 'warning'];

    $_kp_overdue = $_kp_try(fn() => (int)db_one(
        "SELECT COUNT(*) AS n FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
          WHERE s.status = 'planned' AND s.lesson_date < ? AND c.is_active = 1
            AND c.status NOT IN ('cancelled','archived')", [$_kp_today])['n'], null);
    if ($_kp_overdue !== null) $_kp_rows[] = ['Niezamknięte lekcje z przeszłości', $_kp_overdue,
        'zaplanowane przed dziś, bez obecności ani statusu', 'index.php?tab=grupy', 'calendar-x', 'warning'];

    $_kp_noplan = $_kp_try(fn() => db_all(
        "SELECT c.id, c.name FROM k30_ti_courses c
          WHERE c.is_active = 1 AND c.status NOT IN ('cancelled','archived')
            AND NOT EXISTS (SELECT 1 FROM k30_ti_sessions s WHERE s.course_id = c.id
                             AND s.lesson_date >= ? AND s.status != 'cancelled')
          ORDER BY c.name COLLATE NOCASE", [$_kp_today]), null);
    if ($_kp_noplan !== null) $_kp_rows[] = ['Aktywne grupy bez zaplanowanych zajęć', count($_kp_noplan),
        $_kp_noplan ? implode(', ', array_map(fn($c) => (string)$c['name'], array_slice($_kp_noplan, 0, 3))) . (count($_kp_noplan) > 3 ? ' i ' . (count($_kp_noplan) - 3) . ' innych' : '') : 'każda grupa ma przyszłe terminy',
        'index.php?tab=kursy', 'calendar2-plus', 'secondary'];


    $_kp_leaves = $_kp_try(fn() => ti_leaves_current($_kp_today), []);
    $_kp_counts = $_kp_try(fn() => db_one(
        "SELECT (SELECT COUNT(*) FROM k30_ti_courses WHERE is_active = 1 AND status NOT IN ('cancelled','archived')) AS groups,
                (SELECT COUNT(DISTINCT e.client_id) FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id
                  WHERE e.status = 'active' AND c.is_active = 1 AND c.status NOT IN ('cancelled','archived')) AS students,
                (SELECT COUNT(DISTINCT COALESCE(s.instructor_id, c.instructor_id)) FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
                  WHERE s.lesson_date = ? AND s.status != 'cancelled') AS instr_today", [$_kp_today]), null);
    return ['rows' => $_kp_rows, 'leaves' => $_kp_leaves ?: [], 'counts' => $_kp_counts, 'at' => date('H:i')];
});
$_kp_rows   = $_kp_data['rows'];
$_kp_leaves = $_kp_data['leaves'];
$_kp_counts = $_kp_data['counts'];
$_kp_at     = $_kp_data['at'] ?? date('H:i');
$_kp_attn = array_values(array_filter($_kp_rows, fn($r) => (int)$r[1] > 0));
$_kp_ok   = array_values(array_filter($_kp_rows, fn($r) => (int)$r[1] === 0));
?>
