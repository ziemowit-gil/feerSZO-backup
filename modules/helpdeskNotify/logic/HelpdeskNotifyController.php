<?php
/**
 * modules/helpdeskNotify/logic/HelpdeskNotifyController.php — „Moje powiadomienia” operatora (trasy w router.php).
 */
require_once dirname(__DIR__) . '/lib/helpdeskNotify.php';

final class HelpdeskNotifyController
{
    public function index(): string
    {
        $uid = (int)(current_user()['id'] ?? 0);
        return Router::view(__DIR__ . '/../view/index.php', ['p' => hdNotifyPrefs($uid), 'email' => (string)(current_user()['email'] ?? '')]);
    }

    public function save(): ?string
    {
        $err = hdNotifySave((int)(current_user()['id'] ?? 0), $_POST);
        flash_set($err ? 'danger' : 'success', $err ?: 'Ustawienia powiadomień zapisane.');
        return Router::redirect(route('hdnotify.index'));
    }
}
