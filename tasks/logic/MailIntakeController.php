<?php
/**
 * tasks/logic/MailIntakeController.php — skrzynka obszaru: e-maile przychodzące → zadania (ustawienia lidera).
 */
require_once dirname(__DIR__) . '/lib/tasksMail.php';

final class MailIntakeController
{
    public function index(): ?string
    {
        $uid = (int)current_user()['id'];
        $leaderWs = tasksPlusLeaderWorkspaces($uid);
        $wsId = (int)($_GET['ws'] ?? 0);
        if (!isset($leaderWs[$wsId])) $wsId = (int)(array_key_first($leaderWs) ?? 0);
        $cfg = $wsId ? tasksMailIntakeFor($wsId) : null;
        $mailboxes = tasksMailMailboxesFor($uid);
        $targets = tasksCreateTargets($uid);
        $recent = $wsId ? db_all("SELECT s.*, t.title FROM task_mail_sources s JOIN tasks t ON t.id=s.task_id
                                  WHERE t.workspace_id=? AND s.source='mailbox' ORDER BY s.created_at DESC LIMIT 15", [$wsId]) : [];
        $skips = ($cfg && $wsId) ? db_all("SELECT k.reason, k.created_at, c.subject, c.from_email FROM task_mail_skips k
                                           JOIN crm_communications c ON c.id=k.comm_id WHERE c.mailbox_id=? ORDER BY k.created_at DESC LIMIT 10",
                                           [(int)$cfg['mailbox_id']]) : [];
        $body = Router::view(dirname(__DIR__) . '/view/mail_intake.php', compact('leaderWs', 'wsId', 'cfg', 'mailboxes', 'targets', 'recent', 'skips'));
        return tasksPlusPage('Skrzynka obszaru', $body, $wsId);
    }

    public function save(): ?string
    {
        $uid = (int)current_user()['id'];
        $wsId = (int)($_POST['ws'] ?? 0);
        if (!isset(tasksPlusLeaderWorkspaces($uid)[$wsId])) { http_response_code(403); return 'Skrzynkę obszaru ustawia lider obszaru.'; }
        try {
            tasksMailIntakeSave($wsId, $_POST, $uid);
            flash_set('success', (int)($_POST['mailbox_id'] ?? 0) ? 'Zapisano skrzynkę obszaru.' : 'Odłączono skrzynkę obszaru.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('intake.index') . '?ws=' . $wsId);
    }
}
