<?php
/**
 * modules/helpdeskRules/logic/HelpdeskRulesController.php — zarządzanie regułami Helpdesku (tylko administrator; trasy w router.php).
 */
require_once dirname(__DIR__) . '/lib/helpdeskRules.php';

final class HelpdeskRulesController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string
    {
        $edit = (int)($_GET['edit'] ?? 0);
        hdRulesMigrate();
        $row = $edit ? (db_one("SELECT * FROM helpdesk_rules WHERE id=?", [$edit]) ?: null) : null;
        return Router::view(self::VIEW . 'index.php', ['rules' => hdRulesList(), 'row' => $row]);
    }

    public function save(): ?string
    {
        $id = (int)($_POST['id'] ?? 0);
        $errors = hdRulesSave($id, $_POST);
        if ($errors) flash_set('danger', implode(' ', $errors));
        else flash_set('success', 'Reguła zapisana.');
        return Router::redirect(route('hdrules.index') . ($errors && $id ? '&edit=' . $id : ''));
    }

    public function toggle(int $id): ?string
    {
        hdRulesToggle($id);
        return Router::redirect(route('hdrules.index'));
    }

    public function delete(int $id): ?string
    {
        hdRulesDelete($id);
        flash_set('success', 'Reguła usunięta.');
        return Router::redirect(route('hdrules.index'));
    }
}
