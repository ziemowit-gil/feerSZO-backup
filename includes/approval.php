<?php
const APPROVAL_STATUSES = [
    'oczekuje'      => ['label' => 'Oczekuje na akceptację', 'class' => 'warning'],
    'zaakceptowana' => ['label' => 'Zaakceptowana',          'class' => 'success'],
    'odrzucona'     => ['label' => 'Odrzucona',              'class' => 'danger'],
    'wycofana'      => ['label' => 'Wycofana',               'class' => 'secondary'],
];

function approval_badge(string $status): string {
    $s = APPROVAL_STATUSES[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . htmlspecialchars($s['label']) . '</span>';
}

/**
 * Porządkuje przeterminowane wpisy „oczekuje" w contract_approvals: żaden
 * edit.php nie wycofuje wpisu w tej tabeli, gdy ktoś ręcznie zmieni status
 * umowy w edycji (z pominięciem przycisków akceptuj/odrzuć na tej liście) —
 * wpis zostaje wtedy osierocony i wisi jako „oczekująca" na zawsze, mimo że
 * umowa dawno przestała być projektem. Ta funkcja oznacza takie wpisy jako
 * 'wycofana', jeśli powiązana umowa ma już status inny niż 'projekt' albo
 * została usunięta. Bezpieczna do wywołania przy każdym wejściu na listę
 * akceptacji (self-healing, jak samonaprawa schematu w innych modułach).
 *
 * Pomija typ 'canva_request' — to osobny cykl życia (zgoda na dostęp do
 * Canva), niezwiązany ze statusem umowy 'projekt'.
 */
function approval_sync_pending(): void {
    try {
        $types = db_all(
            "SELECT DISTINCT contract_type FROM contract_approvals WHERE status='oczekuje'"
        );
    } catch (\Throwable $e) {
        return;
    }
    foreach ($types as $t) {
        $type = $t['contract_type'];
        if ($type === 'canva_request' || $type === 'crm_case') continue;
        $tbl = table_for_type($type);
        try {
            db()->prepare(
                "UPDATE contract_approvals SET status='wycofana'
                 WHERE status='oczekuje' AND contract_type=?
                   AND contract_id NOT IN (SELECT id FROM {$tbl} WHERE status='projekt')"
            )->execute([$type]);
        } catch (\Throwable $e) {
            // Nietypowa tabela / brak kolumny status — pomiń ten typ, nie przerywaj reszty.
        }
    }
}

function get_current_approval(string $type, int $id): ?array {
    return db_one(
        "SELECT a.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_approvals a
         LEFT JOIN users u ON u.id = a.requested_by
         LEFT JOIN users d ON d.id = a.decided_by
         WHERE a.contract_type = ? AND a.contract_id = ?
         ORDER BY a.id DESC LIMIT 1",
        [$type, $id]
    );
}

function get_all_approvals(string $type, int $id): array {
    return db_all(
        "SELECT a.*, u.name AS requested_by_name, d.name AS decided_by_name
         FROM contract_approvals a
         LEFT JOIN users u ON u.id = a.requested_by
         LEFT JOIN users d ON d.id = a.decided_by
         WHERE a.contract_type = ? AND a.contract_id = ?
         ORDER BY a.id DESC",
        [$type, $id]
    );
}

function submit_for_approval(string $type, int $id, int $user_id, string $contract_numer): array {
    // Deleguj do systemu wielostopniowego, jeśli skonfigurowano workflow
    static $awf_checked = false;
    if (!$awf_checked) {
        $awf_checked = true;
        $awf_file = __DIR__ . '/approval_workflow.php';
        if (file_exists($awf_file)) {
            require_once $awf_file;
        }
    }
    if (function_exists('awf_get_workflow_for_type') && awf_get_workflow_for_type($type)) {
        return awf_submit($type, $id, $user_id, $contract_numer);
    }

    // Wycofaj poprzednie oczekujące
    db()->prepare(
        "UPDATE contract_approvals SET status='wycofana' WHERE contract_type=? AND contract_id=? AND status='oczekuje'"
    )->execute([$type, $id]);

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

    $appr_id = db_insert('contract_approvals', [
        'contract_type' => $type,
        'contract_id'   => $id,
        'requested_by'  => $user_id,
        'token'         => $token,
        'token_expires' => $expires,
        'status'        => 'oczekuje',
    ]);

    log_contract_action($type, $id, $user_id, 'submit_approval', 'Złożono do akceptacji: ' . $contract_numer);

    // Wyślij mail do wszystkich adminów
    $admins = db_all("SELECT * FROM users WHERE role='admin' AND is_active=1");
    $sent   = 0;
    foreach ($admins as $admin) {
        if (approval_send_request_email($admin, $type, $id, $contract_numer, $token)) $sent++;
    }

    db()->prepare("UPDATE contract_approvals SET email_sent=? WHERE id=?")->execute([$sent, $appr_id]);

    return ['id' => $appr_id, 'token' => $token, 'emails_sent' => $sent];
}

function decide_approval(int $appr_id, string $decision, string $note, ?int $user_id, bool $via_email = false): bool {
    $appr = db_one("SELECT * FROM contract_approvals WHERE id=?", [$appr_id]);
    if (!$appr || $appr['status'] !== 'oczekuje') return false;

    db()->prepare(
        "UPDATE contract_approvals SET status=?, decided_by=?, decided_at=?, decision_note=?, via_email=? WHERE id=?"
    )->execute([$decision, $user_id, date('Y-m-d H:i:s'), $note, $via_email ? 1 : 0, $appr_id]);

    $action = $decision === 'zaakceptowana' ? 'approve' : 'reject';
    $by = $user_id ?? 0;
    log_contract_action($appr['contract_type'], $appr['contract_id'], $by,
        $action, ($via_email ? '[via e-mail] ' : '') . $note);

    // Powiadom zgłaszającego
    $requester = db_one("SELECT * FROM users WHERE id=?", [$appr['requested_by']]);
    if ($requester) {
        $contract = db_one("SELECT numer_umowy FROM " . table_for_type($appr['contract_type'])
                         . " WHERE id=?", [$appr['contract_id']]);
        approval_send_decision_email($requester, $appr['contract_type'], $appr['contract_id'],
            $contract['numer_umowy'] ?? '—', $decision, $note);
    }
    return true;
}

function log_contract_action(string $type, int $id, int $user_id, string $action, string $note = ''): void {
    try {
        $user = $user_id ? db_one("SELECT name FROM users WHERE id=?", [$user_id]) : null;
        db_insert('contract_audit_log', [
            'contract_type'  => $type,
            'contract_id'    => $id,
            'user_id'        => $user_id ?: null,
            'user_snapshot'  => $user['name'] ?? 'System',
            'action'         => $action,
            'note'           => $note,
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? '',
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('[log_contract_action] ' . $type . '/' . $id . ': ' . $e->getMessage());
    }
}

function get_audit_log(string $type, int $id): array {
    return db_all(
        "SELECT * FROM contract_audit_log WHERE contract_type=? AND contract_id=? ORDER BY id DESC",
        [$type, $id]
    );
}

// ── Pomocnicze wrappery do logowania zdarzeń systemowych ──────────────────────

/** Loguje zdarzenie uwierzytelniania (logowanie, błąd logowania). */
function log_auth_action(int $user_id, string $action, string $note = ''): void {
    log_contract_action('auth', $user_id, $user_id, $action, $note);
}

/** Loguje zmianę na koncie użytkownika (reset hasła, zmiana roli itp.). */
function log_user_action(int $affected_uid, int $by_uid, string $action, string $note = ''): void {
    log_contract_action('user', $affected_uid, $by_uid, $action, $note);
}

/** Loguje zdarzenie systemowe (ustawienia, konfiguracja). */
function log_system_action(int $user_id, string $action, string $note = ''): void {
    log_contract_action('system', 0, $user_id, $action, $note);
}

// ── Wysyłka maili ─────────────────────────────────────────────────────────────


/**
 * Sprawdź rate-limit: max $max wiadomości do tego samego adresu w ciągu $window sekund.
 * Zwraca true jeśli wysyłka jest dozwolona.
 */
function email_rate_limit_ok(string $to, int $max = 3, int $window = 86400): bool {
    try {
        $since = date('Y-m-d H:i:s', time() - $window);
        $r = db_one(
            "SELECT COUNT(*) AS c FROM email_send_log WHERE to_email=? AND sent_at >= ? AND result='sent'",
            [$to, $since]
        );
        return ((int)($r['c'] ?? 0)) < $max;
    } catch (\Throwable $e) {
        return true; // tabela może nie istnieć w starych instalacjach
    }
}

/**
 * Zapisz wysyłkę w logu.
 */
function email_log(string $to, string $subject, string $ctx_type = '', ?int $ctx_id = null, string $result = 'sent'): void {
    try {
        db()->prepare(
            "INSERT INTO email_send_log (to_email, subject, context_type, context_id, sent_by, result)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$to, $subject, $ctx_type, $ctx_id, current_user()['id'] ?? null, $result]);
    } catch (\Throwable $e) {}
}

function approval_send_email(string $to, string $subject, string $html_body, string $ctx_type = '', ?int $ctx_id = null): bool {
    // Rate-limit: max 5 maili do tego samego adresu na dobę (systemowe/akceptacyjne)
    if (!email_rate_limit_ok($to, 5)) {
        error_log("[approval_send_email] Rate limit osiągnięty dla: {$to}");
        email_log($to, $subject, $ctx_type, $ctx_id, 'rate_limited');
        return false;
    }

    // Próbuj przez Graph API (M365) — używa publicznej metody
    if (function_exists('m365_setting')) {
        require_once __DIR__ . '/m365.php';
        $sender = m365_setting('m365_sender_user_id');
        if ($sender && m365_setting('m365_enabled') === '1') {
            try {
                $graph = new M365Graph();
                if ($graph->is_configured()) {
                    $graph->send_raw_email($sender, $to, $subject, $html_body);
                    email_log($to, $subject, $ctx_type, $ctx_id, 'sent');
                    return true;
                }
            } catch (\Exception $e) {
                error_log("[approval_send_email] Graph API failed: " . $e->getMessage());
            }
        }
    }

    // Fallback: mail_queue (SMTP lub PHP mail)
    try {
        require_once __DIR__ . '/mail_queue.php';
        mail_queue_add($to, '', $subject, $html_body, '', $ctx_type, $ctx_id, '', true);
        email_log($to, $subject, $ctx_type, $ctx_id, 'sent');
        return true;
    } catch (\Throwable $e) {}

    // Ostateczny fallback: PHP mail()
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html_body, $headers);
    email_log($to, $subject, $ctx_type, $ctx_id, $ok ? 'sent' : 'failed');
    return $ok;
}

function approval_send_request_email(array $admin, string $type, int $id, string $numer, string $token): bool {
    $approve_url = APP_URL . '/contracts/approvals/approve.php?token=' . $token . '&action=zaakceptowana';
    $reject_url  = APP_URL . '/contracts/approvals/approve.php?token=' . $token . '&action=odrzucona';
    $view_url    = APP_URL . '/contracts/' . $type . '/view.php?id=' . $id;
    $type_label  = CONTRACT_TYPES[$type] ?? $type;
    $org         = defined('ORG_NAME') ? ORG_NAME : '';

    $body = '<p style="margin:0 0 14px">Dzień dobry,</p>'
          . '<p style="margin:0 0 16px">Wpłynął wniosek o akceptację umowy w systemie <strong>' . htmlspecialchars($org) . '</strong>.</p>'
          . '<table cellpadding="0" cellspacing="0" style="margin:0 0 18px;font-size:14px">'
          . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;white-space:nowrap">Typ:</td>'
          . '<td><strong>' . htmlspecialchars($type_label) . '</strong></td></tr>'
          . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;white-space:nowrap">Numer:</td>'
          . '<td><strong>' . htmlspecialchars($numer) . '</strong></td></tr>'
          . '</table>'
          . '<p style="margin:0 0 16px">Zatwierdź lub odrzuć umowę:</p>'
          . '<p style="margin:0 0 20px">'
          . '<a href="' . htmlspecialchars($approve_url) . '" style="background:#16a34a;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;margin-right:8px;display:inline-block;font-weight:600;font-size:14px">✓ Zatwierdź</a>'
          . '<a href="' . htmlspecialchars($reject_url) . '" style="background:#dc2626;color:#fff;padding:10px 22px;text-decoration:none;border-radius:6px;display:inline-block;font-weight:600;font-size:14px">✗ Odrzuć</a>'
          . '</p>'
          . '<p style="margin:0"><a href="' . htmlspecialchars($view_url) . '" style="color:#2563eb;font-size:14px">Otwórz umowę w systemie →</a></p>'
          . '<p style="margin:20px 0 0;color:#94a3b8;font-size:12px">Link jest ważny 30 dni.</p>';

    $html = _feer_email_tpl($body, "Do akceptacji: {$numer}");
    return approval_send_email($admin['email'], "Do akceptacji: {$numer} — {$org}", $html);
}

function approval_send_decision_email(array $user, string $type, int $id, string $numer, string $decision, string $note): bool {
    $view_url       = APP_URL . '/contracts/' . $type . '/view.php?id=' . $id;
    $org            = defined('ORG_NAME') ? ORG_NAME : '';
    $decision_label = $decision === 'zaakceptowana' ? '✓ Zaakceptowana' : '✗ Odrzucona';
    $color          = $decision === 'zaakceptowana' ? '#16a34a' : '#dc2626';

    $body = '<p style="margin:0 0 14px">Dzień dobry,</p>'
          . '<p style="margin:0 0 16px">Twoja umowa otrzymała decyzję w systemie <strong>' . htmlspecialchars($org) . '</strong>.</p>'
          . '<table cellpadding="0" cellspacing="0" style="margin:0 0 18px;font-size:14px">'
          . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;white-space:nowrap">Umowa:</td>'
          . '<td><strong>' . htmlspecialchars($numer) . '</strong></td></tr>'
          . '<tr><td style="padding:4px 12px 4px 0;color:#64748b;white-space:nowrap">Decyzja:</td>'
          . '<td><strong style="color:' . $color . '">' . $decision_label . '</strong></td></tr>'
          . ($note ? '<tr><td style="padding:4px 12px 4px 0;color:#64748b;white-space:nowrap">Uwaga:</td><td>' . htmlspecialchars($note) . '</td></tr>' : '')
          . '</table>'
          . '<p style="margin:0"><a href="' . htmlspecialchars($view_url) . '" style="color:#2563eb;font-size:14px">Otwórz umowę →</a></p>';

    $html = _feer_email_tpl($body, "Decyzja: {$numer} — {$decision_label}");
    return approval_send_email($user['email'], "Decyzja: {$numer} — {$decision_label}", $html);
}

// ── Akcja etykiety ─────────────────────────────────────────────────────────────

function action_label(string $action): string {
    return match($action) {
        'submit_approval'  => 'Złożono do akceptacji',
        'approve'          => 'Zaakceptowano',
        'reject'           => 'Odrzucono',
        'delete'           => 'Usunięto',
        'create'           => 'Utworzono',
        'edit'             => 'Edytowano',
        'withdraw'         => 'Wycofano z akceptacji',
        'amendment_submit' => 'Złożono aneks',
        'amendment_approve'=> 'Zaakceptowano aneks',
        'amendment_reject' => 'Odrzucono aneks',
        'edit_request'     => 'Wniosek o edycję',
        'edit_approve'       => 'Edycja zatwierdzona',
        'edit_reject'        => 'Edycja odrzucona',
        'certificate_request' => 'Wniosek o zaświadczenie',
        'certificate_issued'  => 'Wydano zaświadczenie',
        'certificate_rejected'=> 'Odrzucono wniosek o zaświadczenie',
        'termination_request' => 'Wniosek o rozwiązanie',
        'termination_approved'=> 'Rozwiązano umowę',
        'termination_rejected'=> 'Odrzucono wniosek o rozwiązanie',
        'letter_added'        => 'Dodano pismo',
        // Rozliczenia
        'rozliczenie_create'  => 'Utworzono rozliczenie',
        'rozliczenie_sent'    => 'Wysłano rozliczenie do księgowego',
        'rozliczenie_settled' => 'Rozliczenie rozliczone',
        // Autentykacja
        'login'               => 'Zalogowano',
        'login_fail'          => 'Błąd logowania',
        'login_sms'           => 'Zalogowano SMS',
        'login_ms'            => 'Zalogowano Microsoft',
        'login_2fa'           => 'Zalogowano 2FA',
        // Zarządzanie użytkownikami
        'user_create'         => 'Dodano użytkownika',
        'user_password_reset' => 'Reset hasła',
        'user_password_start' => 'Hasło startowe',
        'user_role_change'    => 'Zmiana roli',
        'user_toggle'         => 'Zmiana statusu konta',
        // Ustawienia systemowe
        'settings_save'       => 'Zapisano ustawienia',
        // 2FA
        '2fa_enabled'         => 'Włączono 2FA',
        '2fa_disabled'        => 'Wyłączono 2FA',
        // M365
        'm365_password_reset' => 'Reset hasła M365',
        default               => $action,
    };
}

function action_badge(string $action): string {
    $cls = match($action) {
        'approve','amendment_approve','edit_approve' => 'success',
        'reject','amendment_reject','edit_reject','delete' => 'danger',
        'submit_approval','amendment_submit','edit_request' => 'warning',
        'create'             => 'primary',
        'edit'               => 'info',
        'certificate_issued'  => 'success',
        'certificate_rejected'=> 'danger',
        'certificate_request' => 'warning',
        'termination_approved'=> 'success',
        'termination_rejected'=> 'danger',
        'termination_request' => 'warning',
        'letter_added'        => 'primary',
        // Rozliczenia
        'rozliczenie_create'  => 'warning',
        'rozliczenie_sent'    => 'info',
        'rozliczenie_settled' => 'success',
        // Autentykacja
        'login','login_sms','login_ms','login_2fa' => 'success',
        'login_fail'          => 'danger',
        // Zarządzanie użytkownikami
        'user_create'         => 'primary',
        'user_password_reset','user_password_start' => 'warning',
        'user_role_change'    => 'info',
        'user_toggle'         => 'secondary',
        // Ustawienia
        'settings_save'       => 'info',
        // 2FA
        '2fa_enabled'         => 'success',
        '2fa_disabled'        => 'warning',
        // M365
        'm365_password_reset' => 'warning',
        default               => 'secondary',
    };
    return '<span class="badge bg-' . $cls . '">' . htmlspecialchars(action_label($action)) . '</span>';
}
