<?php
/**
 * modules/tasks_plus/logic/WorkloadController.php — Obciążenie zespołu (raport + CSV).
 */
require_once dirname(__DIR__) . '/lib/tasksWorkload.php';

final class WorkloadController
{
    public function index(): ?string
    {
        $uid = (int)current_user()['id'];
        $f = $this->filters();
        $rep = tasksWorkloadReport($uid, $f);
        $workspaces = $rep['scope'] === 'team' ? tasksPlusLeaderWorkspaces($uid) : tasksPlusWorkspaces($uid);
        $body = Router::view(dirname(__DIR__) . '/view/workload.php', compact('rep', 'f', 'workspaces'));
        return tasksPlusPage('Obciążenie zespołu', $body, $f['ws']);
    }

    public function csv(): ?string
    {
        $f = $this->filters();
        $rep = tasksWorkloadReport((int)current_user()['id'], $f);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="obciazenie-zespolu-' . date('Y-m-d') . '.csv"');
        echo tasksWorkloadCsv($rep, $f);
        return null;
    }

    /** Filtry: obszar i okres (domyślnie ostatnie 30 dni). */
    private function filters(): array
    {
        $d = fn($k, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET[$k] ?? '')) ? (string)$_GET[$k] : $def;
        $from = $d('from', date('Y-m-d', strtotime('-29 days')));
        $to = $d('to', date('Y-m-d'));
        if ($from > $to) [$from, $to] = [$to, $from];
        return ['ws' => (int)($_GET['ws'] ?? 0), 'from' => $from, 'to' => $to];
    }
}
