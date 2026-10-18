<?php
/**
 * modules/app_workflow/logic/AppWorkflowController.php — edytor etapów workflow per typ wniosku
 * oraz przejścia wniosków między etapami (wywoływane ze strony admin/application.php).
 */

require_once __DIR__ . '/appWorkflow.php';

final class AppWorkflowController
{
    private const VIEW = __DIR__ . '/../view/';

    public function index(): string {
        appWfMigrate();
        $types = app_types_all();
        $own = [];
        foreach (db_all("SELECT type_id, COUNT(*) AS n FROM app_wf_stages GROUP BY type_id") as $r) $own[(int)$r['type_id']] = (int)$r['n'];
        $open = [];
        foreach (db_all("SELECT type_id, COUNT(*) AS n FROM user_applications WHERE status IN ('nowy','w_trakcie') GROUP BY type_id") as $r) $open[(int)$r['type_id']] = (int)$r['n'];
        return $this->page('Workflow wniosków', Router::view(self::VIEW . 'index.php', ['types' => $types, 'own' => $own, 'open' => $open]));
    }

    public function edit(int $id): string {
        $type = app_type_get($id);
        if (!$type) { http_response_code(404); return 'Nie ma takiego typu wniosku.'; }
        $users = db_all("SELECT id, name FROM users WHERE COALESCE(is_active,1)=1 AND role IN ('admin','editor') ORDER BY name COLLATE NOCASE");
        return $this->page('Workflow: ' . $type['label'], Router::view(self::VIEW . 'edit.php', [
            'type' => $type, 'stages' => appWfStages($id), 'own' => appWfHasOwn($id), 'users' => $users,
            'canEdit' => can_write('admin'),
        ]));
    }

    public function save(int $id): ?string {
        if (!can_write('admin')) { http_response_code(403); return 'Edycja workflow wymaga uprawnień administratora.'; }
        if (!app_type_get($id)) { http_response_code(404); return 'Nie ma takiego typu wniosku.'; }
        try {
            if (!empty($_POST['reset'])) {
                db()->prepare("UPDATE user_applications SET wf_stage_id = NULL WHERE type_id = ?")->execute([$id]);
                db()->prepare("DELETE FROM app_wf_stages WHERE type_id = ?")->execute([$id]);
                flash_set('success', 'Przywrócono etapy domyślne.');
            } else {
                appWfSaveStages($id, array_values((array)($_POST['stages'] ?? [])));
                flash_set('success', 'Workflow zapisany.');
            }
        } catch (\InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('appwf.edit', ['id' => $id]));
    }

    public function move(int $id): ?string {
        if (!can_write('pisma') && !can_write('admin')) { http_response_code(403); return 'Brak uprawnień.'; }
        $back = APP_URL . '/admin/application.php?id=' . $id;
        try {
            $r = appWfMove($id, (int)($_POST['to'] ?? -1), (string)($_POST['note'] ?? ''), current_user());
            $msg = 'Wniosek przeszedł do etapu „' . $r['stage']['name'] . '”.';
            if ($r['upz'] !== null) $msg .= ' Wystawiono UPZ.';
            if ($r['mail'] === true) $msg .= ' Składający dostał e-mail.';
            if ($r['mail'] === false) $msg .= ' E-mail do składającego nie wyszedł od razu — kolejka ponowi.';
            flash_set('success', $msg);
        } catch (\InvalidArgumentException $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect($back);
    }

    /** Ręczne (ponowne) wystawienie UPZ; ?mail=1 wysyła e-mail ponownie. */
    public function upz(int $id): ?string {
        if (!can_write('pisma') && !can_write('admin')) { http_response_code(403); return 'Brak uprawnień.'; }
        require_once dirname(__DIR__, 2) . '/application_documents/logic/applicationDocuments.php';
        appDocsMigrateUpz();
        if (!empty($_POST['resend'])) db()->prepare("UPDATE user_applications SET upz_mailed_at = NULL WHERE id = ?")->execute([$id]);
        $rel = appDocsIssueReceipt($id, true);
        flash_set($rel ? 'success' : 'danger', $rel ? 'Potwierdzenie złożenia (UPZ) wystawione' . (!empty($_POST['resend']) ? ' i wysłane ponownie.' : '.') : 'Nie udało się wystawić UPZ.');
        return Router::redirect(APP_URL . '/admin/application.php?id=' . $id);
    }

    private function page(string $title, string $body): string {
        $PAGE_TITLE = $title;
        ob_start();
        include dirname(__DIR__, 3) . '/includes/header.php';
        echo $body;
        include dirname(__DIR__, 3) . '/includes/footer.php';
        return (string)ob_get_clean();
    }
}
