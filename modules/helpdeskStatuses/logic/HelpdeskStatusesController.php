<?php
/**
 * modules/helpdeskStatuses/logic/HelpdeskStatusesController.php — zarządzanie własnymi statusami kategorii (administrator).
 */
require_once dirname(__DIR__) . '/lib/helpdeskStatuses.php';

final class HelpdeskStatusesController
{
    public function index(): string
    {
        $rows = hdSubstatusAdminList();
        $edit = (int)($_GET['edit'] ?? 0);
        $row = $edit ? (current(array_filter($rows, fn($r) => $r['id'] === $edit)) ?: null) : null;
        return Router::view(__DIR__ . '/../view/index.php', ['rows' => $rows, 'row' => $row]);
    }

    public function save(): ?string
    {
        $id = (int)($_POST['id'] ?? 0);
        $errors = hdSubstatusAdminSave($id, $_POST);
        flash_set($errors ? 'danger' : 'success', $errors ? implode(' ', $errors) : 'Status zapisany.');
        return Router::redirect(route('hdstatuses.index') . ($errors && $id ? '&edit=' . $id : ''));
    }

    public function toggle(int $id): ?string
    {
        $err = hdSubstatusAdminToggle($id);
        flash_set($err ? 'danger' : 'success', $err ?: 'Zmieniono dostępność statusu.');
        return Router::redirect(route('hdstatuses.index'));
    }
}
