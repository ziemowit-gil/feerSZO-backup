<?php
/**
 * modules/tasks_plus/logic/SlaController.php — SLA i eskalacje (dla liderów obszarów).
 */
require_once dirname(__DIR__) . '/lib/tasksSla.php';

final class SlaController
{
    public function index(): ?string
    {
        $uid = (int)current_user()['id'];
        $leaderWs = tasksPlusLeaderWorkspaces($uid);
        $wsId = (int)($_GET['ws'] ?? 0);
        if ($wsId && !isset($leaderWs[$wsId])) $wsId = 0;
        $state = in_array($_GET['state'] ?? '', ['risk', 'breach', 'escalated', 'ok', 'all'], true) ? $_GET['state'] : '';

        $board = tasksSlaBoard($wsId ? [$wsId] : array_keys($leaderWs));
        $counts = ['ok' => 0, 'risk' => 0, 'breach' => 0, 'escalated' => 0];
        foreach ($board as $t) $counts[$t['sla_state']]++;
        $rows = array_values(array_filter($board, fn($t) => $state === 'all' ? true
            : ($state ? $t['sla_state'] === $state : $t['sla_state'] !== 'ok')));

        $polWs = $wsId ?: (int)(array_key_first($leaderWs) ?? 0);
        $policies = $polWs ? tasksSlaPolicies($polWs) : [];
        $people = $polWs ? $this->people($polWs) : [];

        $body = Router::view(dirname(__DIR__) . '/view/sla.php',
            compact('leaderWs', 'wsId', 'state', 'rows', 'counts', 'polWs', 'policies', 'people'));
        return tasksPlusPage('SLA i eskalacje', $body, $wsId);
    }

    public function save(): ?string
    {
        $uid = (int)current_user()['id'];
        $wsId = (int)($_POST['ws'] ?? 0);
        if (!isset(tasksPlusLeaderWorkspaces($uid)[$wsId])) {
            http_response_code(403);
            return 'Ustawienia SLA może zmieniać tylko lider obszaru.';
        }
        try {
            tasksSlaSavePolicies($wsId, (array)($_POST['p'] ?? []), $uid);
            flash_set('success', 'Zapisano zasady SLA obszaru.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('sla.index') . '?ws=' . $wsId . '#zasady');
    }

    public function runNow(): ?string
    {
        $uid = (int)current_user()['id'];
        $ids = array_keys(tasksPlusLeaderWorkspaces($uid));
        $wsId = (int)($_POST['ws'] ?? 0);
        if ($wsId) $ids = array_values(array_intersect($ids, [$wsId]));
        $done = $ids ? tasksSlaRun($ids) : [];
        flash_set('success', $done ? 'Wysłano eskalacji: ' . count($done) . '.' : 'Brak zadań do eskalacji.');
        return Router::redirect(route('sla.index') . ($wsId ? '?ws=' . $wsId : ''));
    }

    /** Kandydaci na adresata poziomu 2: członkowie obszaru (także przez zespoły) i administratorzy. */
    private function people(int $wsId): array
    {
        task_teams_migrate();
        return db_all(
            "SELECT id, name FROM users WHERE is_active=1 AND (role='admin' OR id IN (
                 SELECT user_id FROM task_workspace_members WHERE workspace_id=?
                 UNION SELECT tm.user_id FROM task_workspace_teams wt JOIN task_team_members tm ON tm.team_id=wt.team_id WHERE wt.workspace_id=?
             )) ORDER BY name",
            [$wsId, $wsId]
        );
    }
}
