<?php
/**
 * tasks/includes/linked_tasks_box.php — blok „Zadania” na kartach powiązanych rekordów
 * (koszulka EZD, kontakt/firma CRM, umowa, konto uczestnika w panelu dydaktyka).
 *
 *   require_once …/tasks/includes/linked_tasks_box.php;
 *   echo tasksLinksBox('crm_contact', (string)$id, (int)current_user()['id']);           // Bootstrap (karta)
 *   echo tasksLinksBox('ti_client', (string)$clientId, $uid, 'kk', $backUrl);            // sekcja kk-sec panelu
 *
 * Pokazuje tylko zadania z obszarów, w których użytkownik ma rolę; „Nowe zadanie” — gdy jest liderem jakiegoś obszaru.
 * Moduł Zadań wyłączony → pusty ciąg.
 */
require_once dirname(__DIR__) . '/lib/tasksLinks.php';

function tasksLinksBox(string $type, string $ref, int $uid, string $variant = 'bs', string $back = '', string $title = 'Zadania'): string
{
    if (!$uid || !module_enabled('tasks_enabled')) return '';
    try {
        $ref = (string)tasksLinksRef($type, $ref);
        if ($ref === '') return '';
        $tasks = tasksLinksTasksFor($type, $ref, $uid)['tasks'];
        $canNew = (bool)tasksPlusLeaderWorkspaces($uid);
    } catch (\Throwable $e) {
        error_log('[tasksLinksBox] ' . $e->getMessage());
        return '';
    }
    if ($back === '') $back = (string)($_SERVER['REQUEST_URI'] ?? '');
    $newUrl = tasksLinksNewUrl($type, $ref, $back);
    $hid = 'tlb-' . preg_replace('/[^a-z0-9]/', '-', $type . '-' . $ref);

    $items = '';
    foreach ($tasks as $t) {
        $done = $t['completed_at'] || (int)$t['is_done_state'];
        $state = $done ? '<span class="badge" style="background:#008a00">ukończone</span>'
            : ($t['overdue'] ? '<span class="badge" style="background:#a20025">po terminie</span>' : '<span class="badge" style="background:#647687">' . h($t['list_name']) . '</span>');
        $items .= '<li style="display:flex;gap:.5rem;align-items:baseline;flex-wrap:wrap;padding:.3rem 0;border-top:1px solid #e1e1e1">'
            . '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . h($t['ws_color']) . '" aria-hidden="true"></span>'
            . '<a href="' . h(APP_URL . '/tasks/index.php?ws=' . (int)$t['workspace_id'] . '&task=' . (int)$t['id']) . '"'
            . ($done ? ' style="text-decoration:line-through;color:#5f5f5f"' : '') . '>' . h($t['title']) . '</a> '
            . $state . ' <span style="color:#5f5f5f;font-size:.8rem">' . h($t['ws_name'])
            . ($t['due_date'] ? ' · termin ' . h(task_due_label($t, 'd.m.Y')) : '') . '</span></li>';
    }
    $list = $tasks ? '<ul style="list-style:none;margin:0;padding:0;font-size:.9rem">' . $items . '</ul>'
                   : '<p style="margin:0;color:#5f5f5f;font-size:.88rem">Brak zadań' . ($canNew ? ' — dodaj pierwsze.' : '.') . '</p>';
    $btn = $canNew ? '<a class="btn btn-sm btn-outline-success" href="' . h($newUrl) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowe zadanie</a>' : '';

    if ($variant === 'kk') {
        return '<section class="kk-sec" aria-labelledby="' . $hid . '"><h3 id="' . $hid . '"><i class="bi bi-kanban" aria-hidden="true"></i>' . h($title)
            . ($tasks ? ' <span class="badge bg-secondary">' . count($tasks) . '</span>' : '') . '</h3>'
            . '<div class="kk-row"><div class="kk-row-info">' . $list . '</div><div class="kk-row-ctl">' . $btn . '</div></div></section>';
    }
    return '<section class="card mb-3" aria-labelledby="' . $hid . '"><div class="card-header d-flex align-items-center justify-content-between gap-2">'
        . '<h2 class="h6 mb-0 fw-semibold" id="' . $hid . '"><i class="bi bi-kanban me-1" aria-hidden="true"></i>' . h($title)
        . ($tasks ? ' <span class="badge bg-secondary">' . count($tasks) . '</span>' : '') . '</h2>' . $btn . '</div>'
        . '<div class="card-body py-2">' . $list . '</div></section>';
}
