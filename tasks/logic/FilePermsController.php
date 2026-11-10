<?php
/**
 * tasks/logic/FilePermsController.php — macierz uprawnień do plików obszarów (moduł Pliki / Koszulki).
 * Tylko administrator systemu. Rdzeń (role, działania, typowa macierz, ws_can) w includes/workspaces.php.
 */
require_once dirname(__DIR__, 2) . '/includes/workspaces.php';

final class FilePermsController
{
    public function index(): ?string
    {
        if (!is_admin()) return $this->deny();
        $roles   = ws_file_roles();
        $actions = ws_file_actions();
        $matrix  = ws_file_matrix();
        $isTypical = true;
        foreach ($actions as $k => [, , $def]) {
            $a = $matrix[$k]; $b = $def; sort($a); sort($b);
            if ($a !== $b) { $isTypical = false; break; }
        }
        $body = Router::view(dirname(__DIR__) . '/view/file_perms.php', compact('roles', 'actions', 'matrix', 'isTypical'));
        return tasksPlusPage('Uprawnienia do plików', $body);
    }

    public function save(): ?string
    {
        if (!is_admin()) return $this->deny();
        if (!empty($_POST['typical'])) {
            $in = [];
            foreach (ws_file_actions() as $k => [, , $def]) $in[$k] = $def;
            ws_file_matrix_save($in);
            flash_set('success', 'Przywrócono typową macierz uprawnień do plików.');
        } else {
            ws_file_matrix_save((array)($_POST['perm'] ?? []));
            flash_set('success', 'Zapisano uprawnienia do plików.');
        }
        return Router::redirect(route('fileperms.index'));
    }

    private function deny(): ?string
    {
        flash_set('error', 'Uprawnienia do plików ustawia administrator systemu.');
        return Router::redirect(APP_URL . '/tasks/dashboard.php');
    }
}
