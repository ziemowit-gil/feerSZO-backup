<?php
/**
 * Wielostopniowy, wieloosobowy obieg akceptacji umów.
 * Wymaga: db.php, functions.php, approval.php (dla approval_send_email).
 */

// ── Inicjalizacja tabel ───────────────────────────────────────────────────────

function _awf_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS approval_workflows (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        is_active   INTEGER NOT NULL DEFAULT 1,
        is_default  INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS approval_workflow_steps (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        workflow_id INTEGER NOT NULL REFERENCES approval_workflows(id) ON DELETE CASCADE,
        step_order  INTEGER NOT NULL DEFAULT 1,
        name        TEXT    NOT NULL,
        require_all INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS approval_workflow_approvers (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        step_id INTEGER NOT NULL REFERENCES approval_workflow_steps(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS approval_workflow_contracts (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        workflow_id INTEGER NOT NULL REFERENCES approval_workflows(id) ON DELETE CASCADE,
        contract_type TEXT NOT NULL UNIQUE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS approval_requests (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        workflow_id       INTEGER NOT NULL,
        contract_type     TEXT    NOT NULL,
        contract_id       INTEGER NOT NULL,
        requested_by      INTEGER NOT NULL,
        requested_at      TEXT    NOT NULL DEFAULT (datetime('now')),
        current_step_order INTEGER NOT NULL DEFAULT 1,
        status            TEXT    NOT NULL DEFAULT 'oczekuje',
        completed_at      TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS approval_step_decisions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id    INTEGER NOT NULL REFERENCES approval_requests(id) ON DELETE CASCADE,
        step_id       INTEGER NOT NULL,
        step_order    INTEGER NOT NULL,
        step_name     TEXT    NOT NULL,
        approver_id   INTEGER NOT NULL,
        token         TEXT    NOT NULL UNIQUE,
        token_expires TEXT    NOT NULL,
        status        TEXT    NOT NULL DEFAULT 'oczekuje',
        decision_note TEXT    NOT NULL DEFAULT '',
        decided_at    TEXT,
        via_email     INTEGER NOT NULL DEFAULT 0
    )");
}

// ── Helpery zapytań ───────────────────────────────────────────────────────────

function awf_get_workflow_for_type(string $type): ?array {
    _awf_init();
    // Najpierw szukaj dedykowanego workflow dla tego typu
    $row = db_one(
        "SELECT w.* FROM approval_workflows w
         JOIN approval_workflow_contracts c ON c.workflow_id = w.id
         WHERE c.contract_type = ? AND w.is_active = 1",
        [$type]
    );
    if ($row) return $row;

    // Fallback: domyślny aktywny workflow
    return db_one(
        "SELECT * FROM approval_workflows WHERE is_default = 1 AND is_active = 1 LIMIT 1"
    );
}

function awf_get_steps(int $workflow_id): array {
    _awf_init();
    $steps = db_all(
        "SELECT * FROM approval_workflow_steps WHERE workflow_id = ? ORDER BY step_order ASC",
        [$workflow_id]
    );
    foreach ($steps as &$step) {
        $step['approvers'] = db_all(
            "SELECT a.*, u.name, u.email FROM approval_workflow_approvers a
             JOIN users u ON u.id = a.user_id
             WHERE a.step_id = ? ORDER BY u.name",
            [$step['id']]
        );
    }
    unset($step);
    return $steps;
}

function awf_get_active_request(string $type, int $id): ?array {
    _awf_init();
    return db_one(
        "SELECT r.*, u.name AS requested_by_name
         FROM approval_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         WHERE r.contract_type = ? AND r.contract_id = ? AND r.status = 'oczekuje'
         ORDER BY r.id DESC LIMIT 1",
        [$type, $id]
    );
}

function awf_get_request_steps_status(int $request_id): array {
    _awf_init();
    $decisions = db_all(
        "SELECT d.*, u.name AS approver_name, u.email AS approver_email
         FROM approval_step_decisions d
         JOIN users u ON u.id = d.approver_id
         WHERE d.request_id = ?
         ORDER BY d.step_order ASC, u.name ASC",
        [$request_id]
    );

    // Zgrupuj wg kroku
    $steps = [];
    foreach ($decisions as $d) {
        $key = $d['step_order'];
        if (!isset($steps[$key])) {
            $steps[$key] = [
                'step_order' => $d['step_order'],
                'step_name'  => $d['step_name'],
                'step_id'    => $d['step_id'],
                'decisions'  => [],
            ];
        }
        $steps[$key]['decisions'][] = $d;
    }

    return array_values($steps);
}

function awf_pending_for_user(int $user_id): array {
    _awf_init();
    return db_all(
        "SELECT d.*, r.contract_type, r.contract_id, r.requested_by, r.workflow_id,
                u.name AS requester_name
         FROM approval_step_decisions d
         JOIN approval_requests r ON r.id = d.request_id
         LEFT JOIN users u ON u.id = r.requested_by
         WHERE d.approver_id = ? AND d.status = 'oczekuje' AND r.status = 'oczekuje'
         ORDER BY d.id DESC",
        [$user_id]
    );
}

// ── Submit ─────────────────────────────────────────────────────────────────────

function awf_submit(string $type, int $id, int $user_id, string $numer): array {
    _awf_init();

    $workflow = awf_get_workflow_for_type($type);
    if (!$workflow) {
        // Fallback do starego systemu
        return submit_for_approval($type, $id, $user_id, $numer);
    }

    $steps = awf_get_steps($workflow['id']);
    if (!$steps) {
        return submit_for_approval($type, $id, $user_id, $numer);
    }

    // Wycofaj poprzednie aktywne wnioski wielostopniowe
    db()->prepare(
        "UPDATE approval_requests SET status='wycofana', completed_at=? WHERE contract_type=? AND contract_id=? AND status='oczekuje'"
    )->execute([date('Y-m-d H:i:s'), $type, $id]);

    // Utwórz nowy wniosek
    $request_id = db_insert('approval_requests', [
        'workflow_id'       => $workflow['id'],
        'contract_type'     => $type,
        'contract_id'       => $id,
        'requested_by'      => $user_id,
        'requested_at'      => date('Y-m-d H:i:s'),
        'current_step_order' => $steps[0]['step_order'],
        'status'            => 'oczekuje',
    ]);

    // Utwórz decyzje dla kroku 1
    $first_step = $steps[0];
    _awf_create_step_decisions($request_id, $first_step);

    // Wyślij maile do akceptorów kroku 1
    $sent = 0;
    foreach ($first_step['approvers'] as $approver) {
        $decision = db_one(
            "SELECT * FROM approval_step_decisions WHERE request_id=? AND step_id=? AND approver_id=?",
            [$request_id, $first_step['id'], $approver['user_id']]
        );
        if ($decision) {
            awf_email_step($decision, $numer, $type, $id);
            $sent++;
        }
    }

    log_contract_action($type, $id, $user_id, 'submit_approval',
        'Złożono do akceptacji (workflow: ' . $workflow['name'] . '): ' . $numer);

    return ['id' => $request_id, 'workflow' => $workflow['name'], 'emails_sent' => $sent];
}

function _awf_create_step_decisions(int $request_id, array $step): void {
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    foreach ($step['approvers'] as $approver) {
        $token = bin2hex(random_bytes(32));
        db_insert('approval_step_decisions', [
            'request_id'    => $request_id,
            'step_id'       => $step['id'],
            'step_order'    => $step['step_order'],
            'step_name'     => $step['name'],
            'approver_id'   => $approver['user_id'],
            'token'         => $token,
            'token_expires' => $expires,
            'status'        => 'oczekuje',
            'decision_note' => '',
            'via_email'     => 0,
        ]);
    }
}

// ── Decyzja ───────────────────────────────────────────────────────────────────

function awf_decide(int $decision_id, string $status, string $note, int $user_id, bool $via_email): bool {
    _awf_init();

    $dec = db_one("SELECT * FROM approval_step_decisions WHERE id=?", [$decision_id]);
    if (!$dec || $dec['status'] !== 'oczekuje') return false;

    $request = db_one("SELECT * FROM approval_requests WHERE id=?", [$dec['request_id']]);
    if (!$request || $request['status'] !== 'oczekuje') return false;

    // Zapisz decyzję
    db()->prepare(
        "UPDATE approval_step_decisions SET status=?, decision_note=?, decided_at=?, via_email=? WHERE id=?"
    )->execute([$status, $note, date('Y-m-d H:i:s'), $via_email ? 1 : 0, $decision_id]);

    log_contract_action($request['contract_type'], $request['contract_id'], $user_id,
        $status === 'zaakceptowana' ? 'approve' : 'reject',
        ($via_email ? '[via e-mail] ' : '') . '[Krok ' . $dec['step_order'] . ': ' . $dec['step_name'] . '] ' . $note);

    if ($status === 'odrzucona') {
        // Odrzucono — cały wniosek odrzucony
        db()->prepare(
            "UPDATE approval_requests SET status='odrzucona', completed_at=? WHERE id=?"
        )->execute([date('Y-m-d H:i:s'), $request['id']]);

        _awf_get_contract_numer_and_notify($request, 'odrzucona');
        return true;
    }

    // Sprawdź czy krok jest kompletny
    $step_id = $dec['step_id'];
    $step = db_one("SELECT * FROM approval_workflow_steps WHERE id=?", [$step_id]);

    $all_decisions = db_all(
        "SELECT * FROM approval_step_decisions WHERE request_id=? AND step_id=?",
        [$request['id'], $step_id]
    );

    $step_complete = false;
    if ($step && $step['require_all']) {
        // Wszyscy muszą zaakceptować
        $step_complete = !array_filter($all_decisions, fn($d) => $d['status'] !== 'zaakceptowana');
    } else {
        // Wystarczy jeden
        $step_complete = (bool) array_filter($all_decisions, fn($d) => $d['status'] === 'zaakceptowana');
    }

    if (!$step_complete) return true;

    // Krok kompletny — sprawdź kolejny krok
    $next_step = db_one(
        "SELECT s.*, GROUP_CONCAT(a.user_id) AS approver_ids
         FROM approval_workflow_steps s
         LEFT JOIN approval_workflow_approvers a ON a.step_id = s.id
         WHERE s.workflow_id = ? AND s.step_order > ?
         GROUP BY s.id
         ORDER BY s.step_order ASC LIMIT 1",
        [$request['workflow_id'], $dec['step_order']]
    );

    if ($next_step) {
        // Przejdź do kolejnego kroku
        db()->prepare(
            "UPDATE approval_requests SET current_step_order=? WHERE id=?"
        )->execute([$next_step['step_order'], $request['id']]);

        // Załaduj pełny krok z akceptorami
        $next_step_full = awf_get_steps($request['workflow_id']);
        $next_step_full = array_values(array_filter(
            $next_step_full,
            fn($s) => $s['step_order'] == $next_step['step_order']
        ));

        if ($next_step_full) {
            $ns = $next_step_full[0];
            _awf_create_step_decisions($request['id'], $ns);

            // Pobierz numer umowy
            $table = table_for_type($request['contract_type']);
            $contract = db_one("SELECT numer_umowy FROM {$table} WHERE id=?", [$request['contract_id']]);
            $numer = $contract['numer_umowy'] ?? '—';

            foreach ($ns['approvers'] as $approver) {
                $new_dec = db_one(
                    "SELECT * FROM approval_step_decisions WHERE request_id=? AND step_id=? AND approver_id=?",
                    [$request['id'], $ns['id'], $approver['user_id']]
                );
                if ($new_dec) awf_email_step($new_dec, $numer, $request['contract_type'], $request['contract_id']);
            }
        }
    } else {
        // Ostatni krok — wniosek zaakceptowany
        db()->prepare(
            "UPDATE approval_requests SET status='zaakceptowana', completed_at=? WHERE id=?"
        )->execute([date('Y-m-d H:i:s'), $request['id']]);

        log_contract_action($request['contract_type'], $request['contract_id'], $user_id,
            'approve', 'Wniosek zaakceptowany przez wszystkie etapy workflow.');

        _awf_get_contract_numer_and_notify($request, 'zaakceptowana');
    }

    return true;
}

function _awf_get_contract_numer_and_notify(array $request, string $decision): void {
    $table = table_for_type($request['contract_type']);
    $contract = db_one("SELECT numer_umowy FROM {$table} WHERE id=?", [$request['contract_id']]);
    $numer = $contract['numer_umowy'] ?? '—';

    $requester = db_one("SELECT * FROM users WHERE id=?", [$request['requested_by']]);
    if ($requester) {
        awf_email_decision_complete($request['id'], $numer, $decision, $requester,
            $request['contract_type'], $request['contract_id']);
    }
}

// ── Wycofanie ─────────────────────────────────────────────────────────────────

function awf_withdraw(string $type, int $id): void {
    _awf_init();
    db()->prepare(
        "UPDATE approval_requests SET status='wycofana', completed_at=? WHERE contract_type=? AND contract_id=? AND status='oczekuje'"
    )->execute([date('Y-m-d H:i:s'), $type, $id]);
}

// ── E-maile ───────────────────────────────────────────────────────────────────

function awf_email_step(array $decision, string $numer, string $type, int $contract_id): void {
    $approver = db_one("SELECT * FROM users WHERE id=?", [$decision['approver_id']]);
    if (!$approver) return;

    $approve_url = APP_URL . '/contracts/approvals/approve.php?token=' . $decision['token'] . '&action=zaakceptowana';
    $reject_url  = APP_URL . '/contracts/approvals/approve.php?token=' . $decision['token'] . '&action=odrzucona';
    $view_url    = APP_URL . '/contracts/' . $type . '/view.php?id=' . $contract_id;
    $type_label  = CONTRACT_TYPES[$type] ?? $type;
    $org         = defined('ORG_NAME') ? ORG_NAME : '';

    $body = "
<p>Dzień dobry,</p>
<p>Proszono Cię o akceptację umowy w systemie Rejestru Umów <strong>" . h($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Etap:</td><td><strong>" . h($decision['step_name']) . "</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Typ umowy:</td><td><strong>" . h($type_label) . "</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Numer:</td><td><strong>" . h($numer) . "</strong></td></tr>
</table>
<p>Możesz zaakceptować lub odrzucić tę umowę klikając poniższe przyciski:</p>
<p>
  <a href='" . h($approve_url) . "' style='background:#198754;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;margin-right:8px;display:inline-block'>✓ Zatwierdź</a>
  <a href='" . h($reject_url) . "' style='background:#dc3545;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>✗ Odrzuć</a>
</p>
<p><a href='" . h($view_url) . "'>Otwórz umowę w systemie →</a></p>
<p style='color:#888;font-size:.85em'>Link jest ważny 30 dni. Wygenerowany automatycznie przez system Rejestru Umów.</p>
";
    approval_send_email($approver['email'], "Do akceptacji [{$decision['step_name']}]: {$numer} — {$org}", $body);
}

function awf_email_decision_complete(int $request_id, string $numer, string $decision, array $requester, string $type, int $contract_id): void {
    $view_url      = APP_URL . '/contracts/' . $type . '/view.php?id=' . $contract_id;
    $type_label    = CONTRACT_TYPES[$type] ?? $type;
    $org           = defined('ORG_NAME') ? ORG_NAME : '';
    $decision_label = $decision === 'zaakceptowana' ? '✓ Zaakceptowana' : '✗ Odrzucona';
    $color         = $decision === 'zaakceptowana' ? '#198754' : '#dc3545';

    $body = "
<p>Dzień dobry,</p>
<p>Umowa złożona przez Ciebie do akceptacji wielostopniowej otrzymała ostateczną decyzję w systemie Rejestru Umów <strong>" . h($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Umowa:</td><td><strong>" . h($numer) . "</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Decyzja:</td><td><strong style='color:{$color}'>{$decision_label}</strong></td></tr>
</table>
<p><a href='" . h($view_url) . "'>Otwórz umowę →</a></p>
";
    approval_send_email($requester['email'], "Decyzja workflow: {$numer} — {$decision_label}", $body);
}

// ── Progress HTML ─────────────────────────────────────────────────────────────

function awf_step_progress_html(int $request_id): string {
    _awf_init();
    $request = db_one("SELECT * FROM approval_requests WHERE id=?", [$request_id]);
    if (!$request) return '';

    $steps = awf_get_request_steps_status($request_id);
    if (!$steps) return '';

    $current_step = (int) $request['current_step_order'];
    $req_status   = $request['status'];

    $html = '<div class="awf-timeline d-flex flex-wrap gap-3 my-2">';

    foreach ($steps as $step) {
        $sorder   = (int) $step['step_order'];
        $decisions = $step['decisions'];

        // Ustal status etapu
        if ($req_status === 'odrzucona' && $sorder === $current_step) {
            $step_status = 'odrzucona';
        } elseif ($sorder < $current_step || ($req_status === 'zaakceptowana')) {
            $step_status = 'zaakceptowana';
        } elseif ($sorder === $current_step && $req_status === 'oczekuje') {
            $step_status = 'aktywny';
        } else {
            $step_status = 'oczekuje';
        }

        $border_cls = match($step_status) {
            'zaakceptowana' => 'border-success',
            'odrzucona'     => 'border-danger',
            'aktywny'       => 'border-warning',
            default         => 'border-secondary',
        };
        $badge_cls = match($step_status) {
            'zaakceptowana' => 'bg-success',
            'odrzucona'     => 'bg-danger',
            'aktywny'       => 'bg-warning text-dark',
            default         => 'bg-secondary',
        };
        $step_label = match($step_status) {
            'zaakceptowana' => 'Zatwierdzone',
            'odrzucona'     => 'Odrzucone',
            'aktywny'       => 'Oczekuje',
            default         => 'Nie rozpoczęty',
        };

        $html .= '<div class="card ' . $border_cls . ' flex-fill" style="min-width:180px;border-width:2px">';
        $html .= '<div class="card-header py-1 px-2 d-flex justify-content-between align-items-center" style="font-size:.85em">';
        $html .= '<span><strong>Krok ' . $sorder . ':</strong> ' . h($step['step_name']) . '</span>';
        $html .= '<span class="badge ' . $badge_cls . '">' . h($step_label) . '</span>';
        $html .= '</div>';
        $html .= '<ul class="list-unstyled mb-0 p-2" style="font-size:.82em">';

        foreach ($decisions as $d) {
            if ($d['status'] === 'zaakceptowana') {
                $icon  = '<i class="bi bi-check-circle-fill text-success"></i>';
                $extra = ' <small class="text-muted">' . date_pl($d['decided_at']) . '</small>';
            } elseif ($d['status'] === 'odrzucona') {
                $icon  = '<i class="bi bi-x-circle-fill text-danger"></i>';
                $extra = ' <small class="text-muted">' . date_pl($d['decided_at']) . '</small>';
            } else {
                $icon  = '<i class="bi bi-hourglass-split text-warning"></i>';
                $extra = '';
            }
            $html .= '<li>' . $icon . ' ' . h($d['approver_name']) . $extra . '</li>';
        }

        $html .= '</ul></div>';
    }

    $html .= '</div>';
    return $html;
}
