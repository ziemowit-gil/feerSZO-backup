<?php
/**
 * tasks/logic/NewTaskController.php — formularz „Nowe zadanie” poza listą zadań:
 * z wiadomości e-mail (?comm=ID&src=crm|ezd) — temat, treść, załączniki, link zwrotny do maila;
 * z karty powiązanego rekordu (?link=typ:ref&back=adres) — powiązanie zapisane w task_links.
 * Zakładać zadania może lider/edytor obszaru (jak w tasks/api/task.php).
 */
require_once dirname(__DIR__) . '/lib/tasksMail.php';
require_once dirname(__DIR__) . '/lib/tasksLinks.php';

final class NewTaskController
{
    public function form(): ?string
    {
        $uid = (int)current_user()['id'];
        $targets = tasksCreateTargets($uid);
        $src = ($_GET['src'] ?? '') === 'ezd' ? 'ezd' : 'crm';
        $mail = null;
        $existing = null;
        $atts = [];
        $prefill = ['title' => '', 'description' => '', 'ws' => (int)($_GET['ws'] ?? 0)];
        if ($commId = (int)($_GET['comm'] ?? 0)) {
            $mail = tasksMailComm($commId);
            if (!$mail || !tasksMailCanRead($mail)) { http_response_code(403); return tasksPlusPage('Nowe zadanie', '<div class="tp-empty">Nie masz dostępu do tej wiadomości.</div>'); }
            $existing = tasksMailExistingTask($mail);
            $atts = tasksMailAttachments($commId);
            $prefill['title'] = trim((string)$mail['subject']) ?: '(bez tematu)';
            $prefill['description'] = tasksMailDescription($mail, $src);
        }
        [$linkSpec, $linkLabel] = $this->link((string)($_GET['link'] ?? ''));
        if ($linkSpec !== '' && $linkLabel === '') { http_response_code(403); return tasksPlusPage('Nowe zadanie', '<div class="tp-empty">Nie masz dostępu do rekordu, z którym chcesz powiązać zadanie.</div>'); }
        $back = $mail ? tasksMailBackUrl((int)$mail['id'], $src) : self::safeBack((string)($_GET['back'] ?? ''));
        $body = Router::view(dirname(__DIR__) . '/view/task_new.php', compact('targets', 'mail', 'existing', 'atts', 'prefill', 'src', 'back', 'linkSpec', 'linkLabel'));
        return tasksPlusPage('Nowe zadanie', $body, $prefill['ws']);
    }

    public function store(): ?string
    {
        $uid = (int)current_user()['id'];
        $wsId = (int)($_POST['workspace_id'] ?? 0);
        $targets = tasksCreateTargets($uid);
        $back = route('new.form') . '?' . http_build_query(array_filter(['comm' => (int)($_POST['comm'] ?? 0) ?: null,
            'src' => $_POST['src'] ?? null, 'ws' => $wsId ?: null, 'link' => $_POST['link'] ?? null]));
        [$linkSpec, $linkLabel] = $this->link((string)($_POST['link'] ?? ''));
        if ($linkSpec !== '' && $linkLabel === '') { http_response_code(403); return 'Nie masz dostępu do rekordu, z którym chcesz powiązać zadanie.'; }
        if (!isset($targets[$wsId])) {
            flash_set('danger', 'Zadania w tym obszarze może zakładać tylko lider obszaru.');
            return Router::redirect($back);
        }
        $people = array_map('intval', array_column($targets[$wsId]['people'], 'id'));
        $assignees = array_values(array_intersect(array_map('intval', (array)($_POST['assignees'] ?? [])), $people));

        $mail = null;
        if ($commId = (int)($_POST['comm'] ?? 0)) {
            $mail = tasksMailComm($commId);
            if (!$mail || !tasksMailCanRead($mail)) { http_response_code(403); return 'Nie masz dostępu do tej wiadomości.'; }
            if ($ex = tasksMailExistingTask($mail)) {
                flash_set('warning', 'Z tej wiadomości utworzono już zadanie #' . $ex['task_id'] . '.');
                return Router::redirect(APP_URL . '/tasks/index.php?ws=' . (int)$ex['workspace_id'] . '&task=' . (int)$ex['task_id']);
            }
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $taskId = tasksCreate([
                'workspace_id' => $wsId, 'list_id' => (int)($_POST['list_id'] ?? 0),
                'title' => (string)($_POST['title'] ?? ''), 'description' => (string)($_POST['description'] ?? ''),
                'priority' => (int)($_POST['priority'] ?? 2), 'due_date' => (string)($_POST['due_date'] ?? ''),
                'assignees' => $assignees, 'notify' => false,
            ], $uid);
            $warn = $mail ? tasksMailAttachSource($taskId, $mail, ($_POST['src'] ?? '') === 'ezd' ? 'ezd' : 'crm', $uid,
                array_map('intval', (array)($_POST['att'] ?? []))) : [];
            if ($linkSpec !== '') { [$lt, $lr] = explode(':', $linkSpec, 2); tasksLinksAdd($taskId, $lt, $lr, $uid); }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash_set('danger', $e->getMessage());
            return Router::redirect($back);
        }
        // Powiadomienia po zatwierdzeniu zapisu (nie w transakcji — wysyłka nie może jej blokować)
        require_once dirname(__DIR__, 2) . '/includes/task_notify.php';
        foreach ($assignees as $a) { try { task_notify_assigned($taskId, $a, $uid); } catch (\Throwable $e) {} }
        try { task_notify_created($taskId, $uid); } catch (\Throwable $e) {}

        flash_set($warn ? 'warning' : 'success', 'Utworzono zadanie #' . $taskId . '.' . ($warn ? ' Pominięte pliki: ' . implode('; ', $warn) : ''));
        return Router::redirect(APP_URL . '/tasks/index.php?ws=' . $wsId . '&task=' . $taskId);
    }

    /** „typ:ref” z adresu → [spec, etykieta]; etykieta '' = brak dostępu albo rekord nie istnieje. */
    private function link(string $spec): array
    {
        if ($spec === '') return ['', ''];
        [$type, $ref] = explode(':', $spec, 2) + [1 => ''];
        $ref = tasksLinksRef($type, $ref);
        if ($ref === null) return [$spec, ''];
        $d = tasksLinksDescribe($type, $ref);
        return [$type . ':' . $ref, $d['exists'] && $d['can'] ? $d['label'] . ($d['title'] !== '' ? ': ' . $d['title'] : '') : ''];
    }

    /** Powrót tylko w obrębie systemu (ścieżka względna albo APP_URL). */
    public static function safeBack(string $u): string
    {
        if ($u === '') return '';
        if (str_starts_with($u, APP_URL . '/')) return $u;
        return str_starts_with($u, '/') && !str_starts_with($u, '//') && !str_contains($u, '\\') ? $u : '';
    }
}
