<?php
/**
 * Czyszczenie bazy danych — współdzielona logika.
 * Używane przez cli/clean_db.php i admin/clean_db.php.
 */

function _db_clean_tables(): array {
    return [
        // Obieg dokumentów (najpierw tabele zależne)
        'contract_supervisors', 'contract_letters', 'certificate_requests',
        'contract_termination_requests', 'contract_edit_requests',
        'contract_amendments', 'contract_audit_log', 'contract_approvals',
        // Umowy
        'umowy_zlecenie', 'umowy_uslugi', 'umowy_wolontariat',
        'umowy_dzielo', 'umowy_praca', 'umowy_inne',
        // Wiadomości
        'messages',
        // Onboarding
        'onboarding_messages', 'onboarding_volunteers',
        // Zadania (kolejność: zależne przed głównymi)
        'task_notification_log', 'task_history', 'task_list_time',
        'task_files', 'task_subtasks', 'task_comments',
        'task_task_tags', 'task_assignments', 'tasks',
        'task_workspace_members', 'task_notification_prefs',
        'task_lists', 'task_tags', 'task_workspaces',
        // Inne
        'sms_login_tokens', 'm365_standalone_accounts',
    ];
}

/**
 * Pobiera liczbę rekordów w każdej tabeli (bez modyfikacji).
 * @return array  table => count
 */
function db_clean_counts(PDO $pdo): array {
    $out = [];
    foreach (_db_clean_tables() as $t) {
        try {
            $out[$t] = (int)$pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
        } catch (\Throwable $e) {
            $out[$t] = null; // tabela nie istnieje
        }
    }
    try {
        $all = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email != 'serwis@local'")->fetchColumn();
        $out['users'] = $all;
    } catch (\Throwable $e) {
        $out['users'] = null;
    }
    return $out;
}

/**
 * Czyści dane z bazy.
 *
 * @param PDO      $pdo
 * @param bool     $full           true = usuń też użytkowników (poza systemowymi)
 * @param int|null $keep_user_id   ID użytkownika do zachowania (aktualnie zalogowany)
 * @return array   [['table'=>string, 'deleted'=>int, 'status'=>'ok'|'skip'|'err', 'error'=>?string], ...]
 */
function db_clean(PDO $pdo, bool $full = false, ?int $keep_user_id = null): array {
    $results = [];
    $pdo->exec("PRAGMA foreign_keys=OFF");
    $pdo->beginTransaction();

    try {
        foreach (_db_clean_tables() as $t) {
            try {
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
                $pdo->exec("DELETE FROM \"$t\"");
                $results[] = ['table' => $t, 'deleted' => $cnt, 'status' => 'ok'];
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $results[] = [
                    'table'   => $t,
                    'deleted' => 0,
                    'status'  => str_contains($msg, 'no such table') ? 'skip' : 'err',
                    'error'   => $msg,
                ];
            }
        }

        if ($full) {
            try {
                $sql    = "DELETE FROM users WHERE email != 'serwis@local'";
                $params = [];
                if ($keep_user_id !== null) {
                    $sql    .= " AND id != ?";
                    $params[] = $keep_user_id;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results[] = ['table' => 'users', 'deleted' => $stmt->rowCount(), 'status' => 'ok'];
            } catch (\Throwable $e) {
                $results[] = ['table' => 'users', 'deleted' => 0, 'status' => 'err', 'error' => $e->getMessage()];
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec("PRAGMA foreign_keys=ON");
        throw $e;
    }

    $pdo->exec("PRAGMA foreign_keys=ON");
    try { $pdo->exec("VACUUM"); } catch (\Throwable $e) {}

    return $results;
}
