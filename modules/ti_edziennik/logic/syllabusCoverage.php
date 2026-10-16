<?php
/**
 * modules/ti_edziennik/logic/syllabusCoverage.php — postęp kursanta na tle sylabusa (planu nauczania grupy).
 *
 * Łączy realizację sylabusa (k30_ti_session_curriculum: lekcja ↔ punkt planu) z obecnością kursanta
 * (k30_ti_attendance) — bez nowych tabel. Dla każdego punktu planu:
 *   done      — przerobił: był obecny na co najmniej jednej odbytej lekcji z tym punktem
 *               (albo punkt zrealizowano jako pracę własną — remote_material, bez listy obecności);
 *   missed    — opuścił: punkt zrealizowano, a kursant był nieobecny (także usprawiedliwiony) na każdej takiej lekcji;
 *   before    — zrealizowano przed zapisem kursanta (brak wpisu obecności);
 *   planned   — punkt jest dopiero w zaplanowanych lekcjach;
 *   none      — punkt nie jest jeszcze przypisany do żadnej lekcji.
 * „Do nadrobienia” = missed + before, chyba że prowadzący oznaczył moduł jako opanowany (k30_ti_student_modules).
 * Widok: kolumna „Na zajęciach” w karcie kursanta (modules/ti_dydaktyka/views/_moduly.php).
 */

const TI_COVERAGE_LABELS = [
    'done'    => ['przerobił na zajęciach', 'check-circle', 'dk-ok'],
    'missed'  => ['opuścił — do nadrobienia', 'exclamation-circle', 'dk-bad'],
    'before'  => ['przed zapisem — do nadrobienia', 'skip-backward-circle', 'dk-warn'],
    'planned' => ['zaplanowany', 'calendar-event', 'dk-info'],
    'none'    => ['bez lekcji', 'dash-circle', 'dk-muted'],
];

/**
 * @return array<int, array{state:string, held:int, present:int, last:?string, next:?string}> [curriculum_id => …]
 */
function tiSyllabusCoverage(int $clientId, int $courseId): array
{
    $held  = defined('K30_TI_HELD_STATUSES') ? K30_TI_HELD_STATUSES : ['held', 'individual_change', 'remote_material'];
    $today = date('Y-m-d');
    $out = [];
    foreach (db_all("SELECT id FROM k30_ti_curriculum WHERE course_id=? AND is_active=1", [$courseId]) as $c) {
        $out[(int)$c['id']] = ['state' => 'none', 'held' => 0, 'present' => 0, 'last' => null, 'next' => null,
                               '_remote' => 0, '_rows' => 0];
    }
    if (!$out) return [];
    $rows = db_all(
        "SELECT sc.curriculum_id, s.lesson_date, s.status, a.id AS att_id, COALESCE(a.attended,0) AS attended, COALESCE(a.cancelled,0) AS cancelled
           FROM k30_ti_session_curriculum sc
           JOIN k30_ti_sessions s ON s.id = sc.session_id
           LEFT JOIN k30_ti_attendance a ON a.session_id = s.id AND a.client_id = ?
          WHERE s.course_id = ? AND s.status NOT IN ('cancelled','draft')",
        [$clientId, $courseId]
    );
    foreach ($rows as $r) {
        $cid = (int)$r['curriculum_id'];
        if (!isset($out[$cid])) continue;
        $o = &$out[$cid];
        if (in_array($r['status'], $held, true)) {
            $o['held']++;
            $o['last'] = max((string)$o['last'], (string)$r['lesson_date']);
            if ($r['status'] === 'remote_material') $o['_remote']++;
            if ($r['att_id'] !== null) $o['_rows']++;
            if ((int)$r['attended'] === 1 && (int)$r['cancelled'] === 0) $o['present']++;
        } elseif ((string)$r['lesson_date'] >= $today) {
            $o['next'] = $o['next'] === null ? (string)$r['lesson_date'] : min($o['next'], (string)$r['lesson_date']);
        }
        unset($o);
    }
    foreach ($out as &$o) {
        if ($o['held'] > 0) {
            $o['state'] = ($o['present'] > 0 || $o['_remote'] > 0) ? 'done' : ($o['_rows'] > 0 ? 'missed' : 'before');
        } elseif ($o['next'] !== null) {
            $o['state'] = 'planned';
        }
        unset($o['_remote'], $o['_rows']);
    }
    unset($o);
    return $out;
}

/** Liczba punktów do nadrobienia: missed/before bez statusu „opanowany”. $modules — wiersze TiDydaktykaManager::modules(). */
function tiSyllabusToCatchUp(array $coverage, array $modules): int
{
    $n = 0;
    foreach ($modules as $m) {
        $st = $coverage[(int)$m['id']]['state'] ?? 'none';
        if (($st === 'missed' || $st === 'before') && ($m['status'] ?? '') !== 'mastered') $n++;
    }
    return $n;
}
