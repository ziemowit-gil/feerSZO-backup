<?php
const TERMINATION_STATUSES = [
    'oczekuje'     => ['label' => 'Oczekuje',     'class' => 'warning'],
    'zaakceptowany'=> ['label' => 'Zaakceptowany','class' => 'success'],
    'odrzucony'    => ['label' => 'Odrzucony',    'class' => 'danger'],
];

// Statusy umów, dla których rozwiązanie ma sens
const TERMINABLE_STATUSES = ['podpisana', 'w realizacji', 'obowiązująca'];

function termination_status_badge(string $status): string {
    $s = TERMINATION_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

function get_termination_requests(string $type, int $id): array {
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_termination_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users d ON d.id = r.decided_by
         WHERE r.contract_type = ? AND r.contract_id = ?
         ORDER BY r.id DESC",
        [$type, $id]
    );
}

function get_pending_termination_for_contract(string $type, int $id): ?array {
    return db_one(
        "SELECT * FROM contract_termination_requests
         WHERE contract_type = ? AND contract_id = ? AND status = 'oczekuje'
         ORDER BY id DESC LIMIT 1",
        [$type, $id]
    );
}

function get_user_termination_requests(int $user_id): array {
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_termination_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users d ON d.id = r.decided_by
         WHERE r.requested_by = ?
         ORDER BY r.id DESC",
        [$user_id]
    );
}

function get_all_termination_requests(string $status = ''): array {
    $where = $status ? "WHERE r.status = ?" : "";
    $params = $status ? [$status] : [];
    return db_all(
        "SELECT r.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_termination_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users d ON d.id = r.decided_by
         {$where}
         ORDER BY r.id DESC",
        $params
    );
}

function get_pending_terminations_count(): int {
    try {
        return (int)(db_one(
            "SELECT COUNT(*) AS c FROM contract_termination_requests WHERE status='oczekuje'"
        )['c'] ?? 0);
    } catch (\Exception $e) {
        return 0;
    }
}

function create_termination_request(
    string $type, int $id, ?int $user_id,
    string $name, string $powod, ?string $proposed_date
): int {
    return db_insert('contract_termination_requests', [
        'contract_type'  => $type,
        'contract_id'    => $id,
        'requested_by'   => $user_id,
        'requester_name' => $name,
        'powod'          => $powod,
        'proposed_date'  => $proposed_date ?: null,
        'status'         => 'oczekuje',
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

function decide_termination(int $req_id, int $admin_id, string $decision, string $note): bool {
    $req = db_one(
        "SELECT r.*, u.name AS requested_by_name
         FROM contract_termination_requests r
         LEFT JOIN users u ON u.id = r.requested_by
         WHERE r.id = ?",
        [$req_id]
    );
    if (!$req || $req['status'] !== 'oczekuje') return false;

    db()->prepare(
        "UPDATE contract_termination_requests
         SET status=?, decided_by=?, decided_at=?, decision_note=? WHERE id=?"
    )->execute([$decision, $admin_id, date('Y-m-d H:i:s'), $note, $req_id]);

    require_once __DIR__ . '/approval.php';

    if ($decision === 'zaakceptowany') {
        // Zmień status umowy na "rozwiązana"
        $table = table_for_type($req['contract_type']);
        db()->prepare("UPDATE {$table} SET status='rozwiązana', updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), $req['contract_id']]);

        log_contract_action($req['contract_type'], $req['contract_id'], $admin_id,
            'termination_approved',
            'Zaakceptowano wniosek o rozwiązanie. Umowa rozwiązana.' . ($note ? ' ' . $note : '')
        );
    } else {
        log_contract_action($req['contract_type'], $req['contract_id'], $admin_id,
            'termination_rejected',
            'Odrzucono wniosek o rozwiązanie.' . ($note ? ' ' . $note : '')
        );
    }

    _termination_send_decision_email($req, $decision, $note);
    return true;
}

// ── Powiadomienia e-mail ───────────────────────────────────────────────────────

function _termination_notify_admins(string $type, array $row, string $name, string $powod, ?string $proposed_date): void {
    require_once __DIR__ . '/approval.php';
    $org       = defined('ORG_NAME') ? ORG_NAME : '';
    $admin_url = APP_URL . '/admin/terminations.php';
    $typ_label = CONTRACT_TYPES[$type] ?? $type;
    $nr        = $row['numer_umowy'] ?? '';

    $date_line = $proposed_date
        ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Proponowana data:</td><td>" . htmlspecialchars(date_pl($proposed_date)) . "</td></tr>"
        : '';

    $body = "
<p>Dzień dobry,</p>
<p>Złożono wniosek o rozwiązanie umowy w systemie Rejestru Umów <strong>" . htmlspecialchars($org) . "</strong>.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Umowa:</td><td><strong>" . htmlspecialchars("{$typ_label} {$nr}") . "</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Wnioskujący:</td><td>" . htmlspecialchars($name) . "</td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Powód:</td><td>" . htmlspecialchars($powod) . "</td></tr>
  {$date_line}
</table>
<p><a href='" . htmlspecialchars($admin_url) . "'
   style='background:#dc3545;color:#fff;padding:10px 22px;text-decoration:none;border-radius:4px;display:inline-block'>
  Rozpatrz wniosek →
</a></p>
";
    $admins = db_all("SELECT * FROM users WHERE role='admin' AND is_active=1");
    foreach ($admins as $admin) {
        approval_send_email($admin['email'], "Wniosek o rozwiązanie umowy {$nr} — {$org}", $body);
    }
}

function _termination_send_decision_email(array $req, string $decision, string $note): void {
    require_once __DIR__ . '/approval.php';
    if (empty($req['requested_by'])) return;
    $requester = db_one("SELECT * FROM users WHERE id=?", [$req['requested_by']]);
    if (!$requester || !$requester['email']) return;

    $org        = defined('ORG_NAME') ? ORG_NAME : '';
    $view_url   = APP_URL . '/contracts/' . $req['contract_type'] . '/view.php?id=' . $req['contract_id'];
    $is_ok      = $decision === 'zaakceptowany';
    $color      = $is_ok ? '#198754' : '#dc3545';
    $icon       = $is_ok ? '✓' : '✗';
    $label      = $is_ok ? 'Zaakceptowany — umowa została rozwiązana' : 'Odrzucony';

    $body = "
<p>Dzień dobry,</p>
<p>Wniosek o rozwiązanie umowy złożony w systemie Rejestru Umów <strong>" . htmlspecialchars($org) . "</strong> otrzymał decyzję.</p>
<table style='border-collapse:collapse;margin:12px 0'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Decyzja:</td>
      <td><strong style='color:{$color}'>{$icon} {$label}</strong></td></tr>
  " . ($note ? "<tr><td style='padding:4px 12px 4px 0;color:#555'>Uwaga:</td><td>" . htmlspecialchars($note) . "</td></tr>" : "") . "
</table>
<p><a href='" . htmlspecialchars($view_url) . "'>Otwórz umowę →</a></p>
";
    approval_send_email($requester['email'], "Decyzja ws. rozwiązania umowy — {$org}", $body);
}
