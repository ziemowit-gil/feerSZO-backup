<?php
/**
 * includes/user_delete.php — Współdzielona logika trwałego usuwania użytkownika
 *
 * Używana przez: admin/users.php, cli/delete_user.php
 *
 * Zasady:
 *  - Nie można usunąć serwis@local (konto systemowe)
 *  - Nie można usunąć samego siebie (admin web)
 *  - Nie można usunąć ostatniego aktywnego administratora
 *  - Konta M365 — nie są usuwane z Azure AD, tylko rekord lokalny
 *  - Dane powiązane z ON DELETE CASCADE usuwane automatycznie przez SQLite
 *  - Dane z ON DELETE SET NULL — zostają, user_id = NULL
 */

/**
 * Sprawdza czy użytkownika można bezpiecznie usunąć.
 *
 * @param int $uid     ID usuwanego użytkownika
 * @param int $by_uid  ID wykonującego (0 = CLI)
 * @return array{ok:bool, msg:string}
 */
function user_delete_preflight(int $uid, int $by_uid = 0): array
{
    $user = db_one("SELECT id, name, email, role, is_active FROM users WHERE id = ?", [$uid]);

    if (!$user) {
        return ['ok' => false, 'msg' => "Użytkownik #$uid nie istnieje."];
    }
    if ($user['email'] === 'serwis@local') {
        return ['ok' => false, 'msg' => 'Konto systemowe (serwis@local) jest chronione i nie może być usunięte.'];
    }
    if ($by_uid && $uid === $by_uid) {
        return ['ok' => false, 'msg' => 'Nie możesz usunąć własnego konta.'];
    }
    if ($user['role'] === 'admin') {
        $other_admins = (int)(db_one(
            "SELECT COUNT(*) AS c FROM users WHERE role='admin' AND is_active=1 AND id != ?",
            [$uid]
        )['c'] ?? 0);
        if ($other_admins === 0) {
            return ['ok' => false, 'msg' => 'Nie można usunąć ostatniego aktywnego administratora systemu.'];
        }
    }
    return ['ok' => true, 'msg' => '', 'user' => $user];
}

/**
 * Zwraca podsumowanie powiązanych danych które zostaną CASCADE-usunięte lub SET NULL.
 *
 * @return array{cascade: array<string,int>, set_null: array<string,int>}
 */
function user_delete_impact(int $uid): array
{
    $cascade  = [];
    $set_null = [];

    // Tabele z ON DELETE CASCADE (zostaną usunięte)
    $cascade_tables = [
        'user_sessions'             => ['user_id', 'Sesje logowania'],
        'webauthn_credentials'      => ['user_id', 'Klucze WebAuthn'],
        'totp_tokens'               => ['user_id', 'Tokeny TOTP'],
        'ika_codes'                 => ['user_id', 'Kody IKA'],
        'user_consents'             => ['user_id', 'Zgody RODO'],
        'org_position_assignments'  => ['user_id', 'Przypisania stanowisk'],
        'approval_step_assignees'   => ['user_id', 'Przypisania kroków akceptacji'],
        'moodle_enrollments'        => ['user_id', 'Zapisy Moodle'],
        'crm_group_members'         => ['user_id', 'Przynależność grup CRM'],
        'crm_user_calendar_prefs'   => ['user_id', 'Preferencje kalendarza Outlook'],
        'user_resource_reservations'=> ['user_id', 'Rezerwacje zasobów'],
        'org_rules_user'            => ['user_id', 'Zasady org. (user)'],
        'k30_timesheets'            => ['user_id', 'Ewidencja godzin'],
        'user_applications'         => ['user_id', 'Wnioski'],
    ];

    foreach ($cascade_tables as $table => [$col, $label]) {
        try {
            $count = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE {$col} = ?", [$uid])['c'] ?? 0);
            if ($count > 0) $cascade[$label] = $count;
        } catch (\Throwable $e) { /* tabela może nie istnieć */ }
    }

    // Tabele z ON DELETE SET NULL (rekordy zostają, user_id = NULL)
    $set_null_tables = [
        'crm_events'          => ['created_by',  'Zdarzenia CRM'],
        'crm_communications'  => ['sent_by',      'Komunikacja CRM'],
        'crm_activities'      => ['created_by',   'Aktywności CRM'],
        'crm_notes'           => ['created_by',   'Notatki CRM'],
        'contract_audit_log'  => ['user_id',      'Log akcji'],
        'helpdesk_tickets'    => ['requester_id', 'Zgłoszenia helpdesk'],
        'rodo_authorizations' => ['signed_by_id', 'Upoważnienia RODO'],
    ];

    foreach ($set_null_tables as $table => [$col, $label]) {
        try {
            $count = (int)(db_one("SELECT COUNT(*) AS c FROM {$table} WHERE {$col} = ?", [$uid])['c'] ?? 0);
            if ($count > 0) $set_null[$label] = $count;
        } catch (\Throwable $e) {}
    }

    // Aktywne umowy (ostrzeżenie)
    $contracts = [];
    $active_statuses = ['projekt', 'podpisana', 'w realizacji', 'obowiązująca'];
    $ph = implode(',', array_fill(0, count($active_statuses), '?'));
    foreach (['wolontariat', 'zlecenie', 'dzielo', 'praca'] as $tbl) {
        try {
            $u = db_one("SELECT id, email, m365_login FROM users WHERE id = ?", [$uid]);
            if (!$u) continue;
            $email  = $u['email']      ?? '';
            $ms_log = $u['m365_login'] ?? '';
            if (!$email && !$ms_log) continue;
            $count  = 0;
            foreach (['email', 'm365_login'] as $f) {
                $val = ($f === 'email') ? $email : $ms_log;
                if (!$val) continue;
                try {
                    $r = db_one(
                        "SELECT COUNT(*) AS c FROM {$tbl} WHERE {$f}=? AND status IN ({$ph})",
                        array_merge([$val], $active_statuses)
                    );
                    $count += (int)($r['c'] ?? 0);
                } catch (\Throwable $e2) {}
            }
            if ($count > 0) $contracts[$tbl] = $count;
        } catch (\Throwable $e) {}
    }

    return ['cascade' => $cascade, 'set_null' => $set_null, 'contracts' => $contracts];
}

/**
 * Trwale usuwa użytkownika z bazy.
 * Przed wywołaniem NALEŻY sprawdzić user_delete_preflight().
 *
 * @param int    $uid     ID usuwanego
 * @param int    $by_uid  ID wykonującego (0 = CLI)
 * @param string $reason  Powód usunięcia (opcjonalny)
 * @return array{ok:bool, msg:string}
 */
function user_delete_execute(int $uid, int $by_uid = 0, string $reason = ''): array
{
    $user = db_one("SELECT id, name, email, role FROM users WHERE id = ?", [$uid]);
    if (!$user) {
        return ['ok' => false, 'msg' => "Użytkownik #$uid nie istnieje (już usunięty?)."];
    }

    // Unieważnij wszystkie sesje
    try {
        require_once __DIR__ . '/auth_security.php';
        session_destroy_all($uid);
    } catch (\Throwable $e) { /* może nie istnieć */ }

    // Zaloguj PRZED usunięciem (żeby log przetrwał)
    try {
        $note = "Usunięto konto: {$user['name']} ({$user['email']}), rola: {$user['role']}";
        if ($reason) $note .= ". Powód: {$reason}";
        if ($by_uid) {
            require_once __DIR__ . '/approval.php';
            log_user_action($uid, $by_uid, 'user_delete', $note);
        } else {
            // CLI — zaloguj bez kontekstu użytkownika
            try {
                db_insert('contract_audit_log', [
                    'contract_type' => 'user',
                    'contract_id'   => $uid,
                    'user_id'       => 0,
                    'action'        => 'user_delete',
                    'note'          => '[CLI] ' . $note,
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e2) {}
        }
    } catch (\Throwable $e) {}

    // Usuń — SQLite FK cascades obsługują powiązane rekordy
    try {
        db()->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Błąd usuwania: ' . $e->getMessage()];
    }

    return [
        'ok'  => true,
        'msg' => "Użytkownik {$user['name']} ({$user['email']}) został trwale usunięty.",
    ];
}
