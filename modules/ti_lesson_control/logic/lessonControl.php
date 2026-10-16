<?php
/**
 * modules/ti_lesson_control/logic/lessonControl.php — punkt wejścia modułu Kontroli Lekcji i Wpisów (TI).
 *
 * Ładuje całą logikę i udostępnia lekkie odczyty dla innych ekranów panelu dydaktyka
 * (menu, pulpit prowadzącego, Audyt dzienników) — każdy odporny na brak tabel (try/catch → 0/[]).
 *
 * Architektura:
 *  lessonControlSchema.php    tiLcMigrate()                  k30_ti_lc_rules / _checks / _events
 *  lessonControlSettings.php  TiLcStatus, LessonControlSettings (settings: ti_lc_*)
 *  lessonEntryValidator.php   snapshot, repozytorium, polityka, termin, reguły, walidator
 *  lessonControlService.php   LessonControlService (automat), TiLcEventLog (ślad rewizyjny)
 *  reviewWorkflow.php         ReviewWorkflow (zatwierdź / do poprawy / zakwestionuj / cofnij)
 *  lessonControlNotifier.php  LessonControlNotifier + LessonControlMailTemplates (2 szablony)
 * Wejścia: karty30/ti/dydaktyk/kontrolaWpisow.php (panel), cron/ti_lesson_control.php (agent),
 *          cli/tiLessonControl.php (CLI z --dry-run).
 */

require_once __DIR__ . '/lessonControlNotifier.php';
require_once __DIR__ . '/reviewWorkflow.php';

/** Zaległe wpisy prowadzącego (widżet „Moje zaległe wpisy”): braki po terminie + zwrócone do poprawy. */
function tiLcInstructorPending(int $userId, int $limit = 20): array {
    if ($userId <= 0) return [];
    try {
        return db_all(
            "SELECT k.*, s.time_from, s.time_to, COALESCE(NULLIF(c.display_name,''), c.name) AS course_name
               FROM k30_ti_lc_checks k
               JOIN k30_ti_sessions s ON s.id = k.session_id
               JOIN k30_ti_courses c ON c.id = k.course_id
              WHERE k.instructor_id = ?
                AND (k.auto_status IN ('BRAK_WPISU','NIEPELNY_WPIS') OR k.review_status IN ('DO_POPRAWY','ZAKWESTIONOWANY'))
              ORDER BY (k.review_status = 'DO_POPRAWY') DESC, k.lesson_date ASC
              LIMIT " . max(1, $limit), [$userId]);
    } catch (\Throwable $e) { return []; }
}

function tiLcInstructorPendingCount(int $userId): int {
    if ($userId <= 0) return 0;
    try {
        return (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_lc_checks WHERE instructor_id = ?
                              AND (auto_status IN ('BRAK_WPISU','NIEPELNY_WPIS') OR review_status IN ('DO_POPRAWY','ZAKWESTIONOWANY'))", [$userId])['n'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/** Licznik dla audytora w menu: czeka na weryfikację (poprawione + zakwestionowane). */
function tiLcAuditorQueueCount(): int {
    try {
        return (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_lc_checks WHERE review_status IN ('POPRAWIONY','ZAKWESTIONOWANY')")['n'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

/** Dla Audytu dzienników: per prowadzący liczba wpisów po terminie z brakami i zwróconych do poprawy. */
function tiLcGapsByInstructor(): array {
    $out = [];
    try {
        foreach (db_all("SELECT instructor_id,
                                SUM(CASE WHEN auto_status IN ('BRAK_WPISU','NIEPELNY_WPIS') THEN 1 ELSE 0 END) AS late,
                                SUM(CASE WHEN review_status = 'DO_POPRAWY' THEN 1 ELSE 0 END) AS returned
                           FROM k30_ti_lc_checks WHERE instructor_id IS NOT NULL GROUP BY instructor_id") as $r) {
            if ((int)$r['late'] + (int)$r['returned'] > 0) $out[(int)$r['instructor_id']] = ['late' => (int)$r['late'], 'returned' => (int)$r['returned']];
        }
    } catch (\Throwable $e) {}
    return $out;
}
