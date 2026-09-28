<?php
/**
 * includes/ti_audit.php — Audyt dzienników (panel kierownika, moduł TI).
 *
 * Zbiera dwa rodzaje braków po prowadzących: niekompletną dokumentację lekcji
 * (k30_ti_sessions.docs_complete) i kursantów bez żadnej oceny w e-dzienniku
 * (k30_ti_grades) — tylko dla kursów, w których e-dziennik jest włączony
 * (k30_ti_course_grades_enabled()). Osobne od protokołów miesięcznych
 * (includes/ti_protocols.php) — to jest wgląd "na bieżąco", nie zamykanie
 * okresu.
 */

require_once __DIR__ . '/karty30.php';

/**
 * Braki pogrupowane po prowadzącym (instructor_id kursu). Zwraca:
 * [instructor_id => ['docs' => [...], 'docs_total' => n, 'grades' => [...], 'grades_total' => n]]
 * Wpisy 'docs'/'grades' to listy ['course_id','course_name','n'].
 */
function ti_audit_gaps_by_instructor(): array {
    $by_instr = [];

    $docs = db_all(
        "SELECT c.instructor_id, c.id AS course_id, c.name AS course_name, COUNT(*) AS n
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
          WHERE s.status IN ('held','individual_change','remote_material')
            AND " . k30_ti_docs_complete_sql() . " = 0
            AND c.instructor_id IS NOT NULL
          GROUP BY c.instructor_id, c.id
          ORDER BY c.name COLLATE NOCASE"
    );
    foreach ($docs as $r) {
        $iid = (int)$r['instructor_id'];
        $by_instr[$iid]['docs'][] = $r;
        $by_instr[$iid]['docs_total'] = ($by_instr[$iid]['docs_total'] ?? 0) + (int)$r['n'];
    }

    $grades = db_all(
        "SELECT c.instructor_id, c.id AS course_id, c.name AS course_name, COUNT(*) AS n
           FROM k30_ti_enrollments e
           JOIN k30_ti_courses c ON c.id = e.course_id
          WHERE e.status = 'active'
            AND c.instructor_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM k30_ti_grades g WHERE g.course_id = e.course_id AND g.client_id = e.client_id)
          GROUP BY c.instructor_id, c.id
          ORDER BY c.name COLLATE NOCASE"
    );
    foreach ($grades as $r) {
        if (function_exists('k30_ti_course_grades_enabled') && !k30_ti_course_grades_enabled((int)$r['course_id'])) continue;
        $iid = (int)$r['instructor_id'];
        $by_instr[$iid]['grades'][] = $r;
        $by_instr[$iid]['grades_total'] = ($by_instr[$iid]['grades_total'] ?? 0) + (int)$r['n'];
    }

    return $by_instr;
}

/** Treść i wysyłka e-maila z przypomnieniem o brakach do jednego prowadzącego. */
function ti_audit_notify_instructor(array $instructor, array $gap): bool {
    if (empty($instructor['email'])) return false;
    require_once __DIR__ . '/mail_queue.php';
    $org = defined('ORG_NAME') ? ORG_NAME : 'System';

    $lines = '';
    if (!empty($gap['docs'])) {
        $lines .= '<p><strong>Niekompletna dokumentacja lekcji:</strong></p><ul>';
        foreach ($gap['docs'] as $d) {
            $n = (int)$d['n'];
            $lines .= '<li>' . h((string)$d['course_name']) . ' — ' . $n . ' ' . ($n === 1 ? 'lekcja' : 'lekcje/lekcji') . '</li>';
        }
        $lines .= '</ul>';
    }
    if (!empty($gap['grades'])) {
        $lines .= '<p><strong>Kursanci bez oceny w e-dzienniku:</strong></p><ul>';
        foreach ($gap['grades'] as $g) {
            $lines .= '<li>' . h((string)$g['course_name']) . ' — ' . (int)$g['n'] . ' os.</li>';
        }
        $lines .= '</ul>';
    }
    if ($lines === '') return false;

    $html = '<p>Dzień dobry,</p>'
          . '<p>W panelu dydaktyka znaleziono poniższe braki, które prosimy uzupełnić:</p>'
          . $lines
          . '<p style="color:#888;font-size:12px">Wiadomość automatyczna z systemu ' . h($org) . '.</p>';

    try {
        mail_queue_add((string)$instructor['email'], (string)($instructor['name'] ?? ''),
            'Przypomnienie: braki w dokumentacji i ocenach', $html, '', 'ti_audit', null, '', false);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}
