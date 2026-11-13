<?php
/**
 * tasks/logic/MailIntakeController.php — skrzynka obszaru: e-maile przychodzące → zadania (ustawienia lidera).
 * Od 2026-11-13 skrzynka IMAP obszaru (adres, serwer, login, hasło) zamiast skrzynki z modułu Poczta (Microsoft 365).
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
        $targets = tasksCreateTargets($uid);
        require_once dirname(__DIR__, 2) . '/modules/mailImap/lib/ImapAccount.php';
        $imapDefault = ImapAccount::defaultHost();
        $recent = $wsId ? db_all("SELECT s.*, t.title FROM task_mail_sources s JOIN tasks t ON t.id=s.task_id
                                  WHERE t.workspace_id=? AND s.source='mailbox' ORDER BY s.created_at DESC LIMIT 15", [$wsId]) : [];
        $skips = ($cfg && $wsId) ? db_all("SELECT reason, created_at, subject, from_email FROM task_mail_imap_skips
                                           WHERE workspace_id=? ORDER BY created_at DESC LIMIT 10", [$wsId]) : [];
        $body = Router::view(dirname(__DIR__) . '/view/mail_intake.php', compact('leaderWs', 'wsId', 'cfg', 'imapDefault', 'targets', 'recent', 'skips'));
        return tasksPlusPage('Skrzynka obszaru', $body, $wsId);
    }

    public function save(): ?string
    {
        $uid = (int)current_user()['id'];
        $wsId = (int)($_POST['ws'] ?? 0);
        if (!isset(tasksPlusLeaderWorkspaces($uid)[$wsId])) { http_response_code(403); return 'Skrzynkę obszaru ustawia lider obszaru.'; }
        try {
            $test = tasksMailIntakeSave($wsId, $_POST, $uid);
            if (trim((string)($_POST['address'] ?? '')) === '') flash_set('success', 'Odłączono skrzynkę obszaru.');
            elseif ($test !== null) flash_set('warning', 'Zapisano, ale nie udało się zalogować do skrzynki: ' . $test);
            else flash_set('success', 'Zapisano skrzynkę obszaru. Logowanie do skrzynki działa.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('intake.index', ['ws' => $wsId]));
    }
}
