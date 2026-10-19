<?php
/**
 * tasks/logic/RulesController.php — Reguły automatyzacji obszaru (tylko liderzy obszaru).
 */
require_once dirname(__DIR__) . '/lib/tasksRules.php';

final class RulesController
{
    public function index(): ?string
    {
        $uid = (int)current_user()['id'];
        $leaderWs = tasksPlusLeaderWorkspaces($uid);
        $wsId = (int)($_GET['ws'] ?? 0);
        if (!isset($leaderWs[$wsId])) $wsId = (int)(array_key_first($leaderWs) ?? 0);
        $rules = $wsId ? tasksRulesList($wsId) : [];
        $opt = $wsId ? tasksRulesOptions($wsId) : ['lists' => [], 'people' => [], 'tags' => []];
        $body = Router::view(dirname(__DIR__) . '/view/rules.php', compact('leaderWs', 'wsId', 'rules', 'opt'));
        return tasksPlusPage('Reguły automatyzacji', $body, $wsId);
    }

    public function store(): ?string
    {
        $wsId = $this->leaderWs((int)($_POST['ws'] ?? 0));
        if ($wsId === null) return $this->deny();
        try {
            tasksRulesCreate($wsId, $_POST, (int)current_user()['id']);
            flash_set('success', 'Dodano regułę.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('rules.index') . '?ws=' . $wsId);
    }

    public function toggle(int $id): ?string
    {
        $rule = $this->rule($id);
        if (!$rule) return $this->deny();
        db()->prepare("UPDATE task_rules SET is_active=1-is_active WHERE id=?")->execute([$id]);
        flash_set('success', (int)$rule['is_active'] ? 'Reguła wyłączona.' : 'Reguła włączona.');
        return Router::redirect(route('rules.index') . '?ws=' . (int)$rule['workspace_id']);
    }

    public function destroy(int $id): ?string
    {
        $rule = $this->rule($id);
        if (!$rule) return $this->deny();
        db()->prepare("DELETE FROM task_rules WHERE id=?")->execute([$id]);
        flash_set('success', 'Usunięto regułę „' . $rule['name'] . '”.');
        return Router::redirect(route('rules.index') . '?ws=' . (int)$rule['workspace_id']);
    }

    /** Reguła z obszaru, w którym bieżący użytkownik jest liderem. */
    private function rule(int $id): ?array
    {
        tasksRulesMigrate();
        $r = db_one("SELECT * FROM task_rules WHERE id=?", [$id]);
        return $r && $this->leaderWs((int)$r['workspace_id']) !== null ? $r : null;
    }

    private function leaderWs(int $wsId): ?int
    {
        return isset(tasksPlusLeaderWorkspaces((int)current_user()['id'])[$wsId]) ? $wsId : null;
    }

    private function deny(): string
    {
        http_response_code(403);
        return 'Reguły automatyzacji może zmieniać tylko lider obszaru.';
    }
}
