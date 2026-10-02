<?php
/**
 * modules/ti_pdf/logic/lessonList.php — lista lekcji grupy do wydruków PDF/DOCX.
 *
 * Wszystkie lekcje grupy: odbyte, ZAPLANOWANE i odwołane (z oznaczeniem). Pomija tylko
 * wersje robocze i rezerwacje — to robocze ustalenia wewnętrzne, nie plan dla kursanta.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';

const TI_LESSON_LIST_DAYS = [1 => 'Pn', 2 => 'Wt', 3 => 'Śr', 4 => 'Czw', 5 => 'Pt', 6 => 'Sob', 7 => 'Nd'];
const TI_LESSON_LIST_METHODS = ['stacjonarna' => 'stacjonarnie', 'zdalna_zoom' => 'zdalnie (Zoom)', 'zdalna_inne' => 'zdalnie'];

/**
 * @return list<array{n:int,date:string,dow:string,time:string,method:string,topic:string,status:string,status_label:string,upcoming:bool}>
 */
function ti_course_lesson_list(int $course_id): array {
    $today = date('Y-m-d');
    $rows = db_all(
        "SELECT lesson_date, time_from, time_to, status, topic, lesson_method FROM k30_ti_sessions
          WHERE course_id=? AND draft_id IS NULL AND status NOT IN ('draft','reserved')
          ORDER BY lesson_date, time_from", [$course_id]);
    $out = []; $n = 0;
    foreach ($rows as $r) {
        $n++;
        $st = (string)$r['status'];
        $label = $st === 'planned' ? 'Zaplanowana' : (K30_TI_SESSION_STATUSES[$st]['label'] ?? $st);
        $out[] = [
            'n' => $n, 'date' => date('d.m.Y', strtotime((string)$r['lesson_date'])),
            'dow' => TI_LESSON_LIST_DAYS[(int)date('N', strtotime((string)$r['lesson_date']))] ?? '',
            'time' => $r['time_from'] !== '' && $r['time_from'] !== null ? substr((string)$r['time_from'], 0, 5) . '–' . substr((string)$r['time_to'], 0, 5) : '—',
            'method' => TI_LESSON_LIST_METHODS[(string)$r['lesson_method']] ?? '',
            'topic' => (string)($r['topic'] ?? ''), 'status' => $st, 'status_label' => $label,
            'upcoming' => (string)$r['lesson_date'] >= $today && $st !== 'cancelled' && !in_array($st, K30_TI_HELD_STATUSES, true),
        ];
    }
    return $out;
}
