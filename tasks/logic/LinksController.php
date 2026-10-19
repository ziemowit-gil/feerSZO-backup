<?php
/**
 * tasks/logic/LinksController.php — dodawanie i usuwanie powiązań zadania z karty zadania (lider/edytor obszaru).
 * Dodanie: wklejony adres karty rekordu (koszulka EZD, kontakt CRM, umowa, konto uczestnika) — wymaga też
 * dostępu do tego rekordu. Po zapisie powrót na listę z otwartym zadaniem.
 */
require_once dirname(__DIR__) . '/lib/tasksLinks.php';

final class LinksController
{
    public function add(): ?string
    {
        [$task, $uid] = $this->task();
        if (!$task) return $this->deny();
        $p = tasksLinksParseUrl((string)($_POST['url'] ?? ''));
        if (!$p) {
            flash_set('danger', 'Nie rozpoznano adresu. Wklej adres karty koszulki EZD, kontaktu CRM, umowy albo konta uczestnika.');
        } else {
            $d = tasksLinksDescribe($p[0], $p[1]);
            if (!$d['exists']) flash_set('danger', 'Taki rekord nie istnieje.');
            elseif (!$d['can']) flash_set('danger', 'Nie masz dostępu do tego rekordu.');
            else {
                tasksLinksAdd((int)$task['id'], $p[0], $p[1], $uid);
                flash_set('success', 'Powiązano: ' . $d['label'] . ($d['title'] !== '' ? ' — ' . $d['title'] : '') . '.');
            }
        }
        return Router::redirect($this->back($task));
    }

    public function remove(): ?string
    {
        [$task, $uid] = $this->task();
        if (!$task) return $this->deny();
        $type = (string)($_POST['type'] ?? '');
        $ref = tasksLinksRef($type, (string)($_POST['ref'] ?? ''));
        if ($ref !== null) {
            tasksLinksRemove((int)$task['id'], $type, $ref, $uid);
            flash_set('success', 'Usunięto powiązanie.');
        }
        return Router::redirect($this->back($task));
    }

    /** Zadanie z formularza, gdy użytkownik jest liderem/edytorem jego obszaru. */
    private function task(): array
    {
        $uid = (int)current_user()['id'];
        $t = db_one("SELECT id, workspace_id FROM tasks WHERE id=? AND deleted_at IS NULL", [(int)($_POST['task_id'] ?? 0)]);
        if (!$t || !in_array(task_workspace_role((int)$t['workspace_id'], $uid), ['admin', 'editor'], true)) return [null, $uid];
        return [$t, $uid];
    }

    private function back(array $t): string
    {
        return APP_URL . '/tasks/index.php?ws=' . (int)$t['workspace_id'] . '&task=' . (int)$t['id'];
    }

    private function deny(): string
    {
        http_response_code(403);
        return 'Powiązania zadania zmienia lider albo edytor obszaru.';
    }
}
