<?php
/**
 * modules/user_merge/lib/userMergeRelations.php — powiązania kont użytkowników z resztą bazy (scalanie kont).
 *
 * Kolumny wskazujące na users.id są wyszukiwane dynamicznie (schemat rośnie z każdym modułem):
 *   1. zadeklarowane klucze obce do users(id) — PRAGMA foreign_key_list / INFORMATION_SCHEMA;
 *   2. kolumny całkowite bez deklaracji o nazwach typowych dla odwołań do konta
 *      (user_id, *_user_id, *_by, *_by_id, owner_id, author_id, actor_id, instructor_id…);
 *   3. odwołania polimorficzne znane z nazwy: contract_audit_log (contract_type = 'user', contract_id = konto).
 * Pomijamy: tabelę users (osobna obsługa), dzienniki bezpieczeństwa (niezmienne — wpis o scaleniu mapuje stare id na
 * nowe), sesje, tokeny i czynniki logowania (zostają przy zarchiwizowanym, nieaktywnym koncie) oraz kolumny, które
 * mimo nazwy wskazują inne byty (USER_MERGE_SKIP_COLUMNS).
 *
 * Umowy (umowy_*) nie mają users.id — wiążą się z kontem przez e-mail (email / email_login) i m365_login.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

/** Tabele pomijane przy przepinaniu: tabela => powód (pokazywany w podsumowaniu). */
const USER_MERGE_SKIP_TABLES = [
    'users'                         => 'konto (osobna obsługa)',
    'audit_logs'                    => 'dziennik audytu — niezmienny',
    'admin_audit_log'               => 'dziennik administratora — niezmienny',
    'login_log'                     => 'dziennik logowań — niezmienny',
    'saml_sso_log'                  => 'dziennik SSO — niezmienny',
    'impersonation_requests'        => 'dziennik wejść na konto — niezmienny',
    'user_sessions'                 => 'sesje — kończone przy scaleniu',
    'saml_active_sessions'          => 'sesje SSO',
    'trusted_logins'                => 'zaufane urządzenia',
    'webauthn_credentials'          => 'klucze bezpieczeństwa',
    'sms_login_tokens'              => 'kody logowania',
    'desktop_auth_codes'            => 'kody logowania',
    'ika_codes'                     => 'kody IKA',
    'totp_tokens'                   => 'tokeny TOTP',
    'k30_imp_tokens'                => 'tokeny wejścia',
    'k30_ti_api_tokens'             => 'tokeny API',
    'k30_ti_instructor_api_tokens'  => 'tokeny API',
    'k30_ti_instructor_2fa_pending' => 'oczekujące kody 2FA',
];

/** Kolumny o „kontowej” nazwie, które wskazują inne byty (polimorficzne albo kontakty/kursanci). */
const USER_MERGE_SKIP_COLUMNS = [
    'messages.sender_id',               // sender_type: admin | wolontariusz
    'crm_campaign_events.recipient_id', // odbiorca kampanii CRM, nie konto
    'k30_ti_admin_msgs.user_id',        // nadawca — konto kursanta
];

/** Nazwy kolumn bez deklaracji FK traktowane jako odwołanie do users.id (oprócz user_id, *_user_id, *_by, *_by_id). */
const USER_MERGE_NAME_COLUMNS = ['owner_id', 'author_id', 'actor_id', 'instructor_id', 'admin_id', 'assigned_to',
    'assignee_id', 'consultant_id', 'requester_id', 'interviewer_id', 'trainer_id', 'koordynator_id', 'approver_id',
    'sender_id', 'recipient_id', 'supervisor_id'];

/** Czytelne nazwy najczęstszych powiązań (reszta: tabela.kolumna). */
const USER_MERGE_LABELS = [
    'contract_audit_log.contract_id'  => 'Historia konta',
    'contract_audit_log.user_id'      => 'Wpisy w historii umów (jako wykonujący)',
    'user_permissions.user_id'        => 'Uprawnienia do modułów',
    'tasks.created_by'                => 'Zadania (utworzone)',
    'task_assignments.user_id'        => 'Zadania (przypisane)',
    'ezd_sprawy.owner_id'             => 'Sprawy EZD (prowadzący)',
    'ezd_dokumenty.owner_id'          => 'Dokumenty EZD',
    'helpdesk_tickets.requester_id'   => 'Zgłoszenia helpdesk',
    'crm_contacts.owner_id'           => 'Kontakty CRM (opiekun)',
    'k30_ti_courses.instructor_id'    => 'Kursy TI (prowadzący)',
    'k30_ti_sessions.instructor_id'   => 'Lekcje TI (prowadzący)',
    'notifications.user_id'           => 'Powiadomienia',
    'user_prefs.user_id'              => 'Ustawienia konta',
    'org_members.user_id'             => 'Członkostwo w strukturze',
    'vpn_access.user_id'              => 'Dostęp VPN',
    'canva_user_access.user_id'       => 'Dostęp do Canva',
];

function userMergeIsMysql(): bool {
    return defined('DB_TYPE') && strtolower((string)DB_TYPE) === 'mysql';
}

/** Kolumny tabeli (nazwy). */
function userMergeTableColumns(string $table): array {
    try {
        if (!userMergeIsMysql()) return array_column(db()->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        return db()->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (\Throwable $e) { return []; }
}

function userMergeNameLooksLikeUser(string $col): bool {
    return $col === 'user_id' || str_ends_with($col, '_user_id') || (bool)preg_match('/_by(_id)?$/', $col)
        || in_array($col, USER_MERGE_NAME_COLUMNS, true);
}

/**
 * Wszystkie kolumny odwołujące się do users.id.
 * @return list<array{table:string, column:string, kind:string, where:string, label:string}>  kind: fk | name | poly
 */
function userMergeRelationColumns(bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;

    $fk = [];      // "t.c" => true
    $cols = [];    // [t, c, typ]
    if (userMergeIsMysql()) {
        foreach (db_all("SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
                         WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'users' AND k.REFERENCED_COLUMN_NAME = 'id'") as $r) {
            $fk[$r['t'] . '.' . $r['c']] = true;
        }
        foreach (db_all("SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c, c.DATA_TYPE AS typ FROM INFORMATION_SCHEMA.COLUMNS c
                         JOIN INFORMATION_SCHEMA.TABLES tb ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
                         WHERE c.TABLE_SCHEMA = DATABASE() AND tb.TABLE_TYPE = 'BASE TABLE'") as $r) {
            $cols[] = [$r['t'], $r['c'], (string)$r['typ']];
        }
    } else {
        foreach (db_all("SELECT m.name AS t, p.\"from\" AS c FROM sqlite_master m JOIN pragma_foreign_key_list(m.name) p
                         WHERE m.type = 'table' AND p.\"table\" = 'users' AND (p.\"to\" = 'id' OR p.\"to\" IS NULL)") as $r) {
            $fk[$r['t'] . '.' . $r['c']] = true;
        }
        foreach (db_all("SELECT m.name AS t, c.name AS c, c.type AS typ FROM sqlite_master m JOIN pragma_table_info(m.name) c
                         WHERE m.type = 'table' AND m.name NOT LIKE 'sqlite\\_%' ESCAPE '\\'") as $r) {
            $cols[] = [$r['t'], $r['c'], (string)$r['typ']];
        }
    }

    $out = [];
    foreach ($cols as [$t, $c, $typ]) {
        $key = $t . '.' . $c;
        if (isset(USER_MERGE_SKIP_TABLES[$t]) || in_array($key, USER_MERGE_SKIP_COLUMNS, true)) continue;
        $isFk = isset($fk[$key]);
        $isInt = stripos($typ, 'int') !== false;
        if (!$isFk && !($isInt && userMergeNameLooksLikeUser($c))) continue;
        $out[] = ['table' => $t, 'column' => $c, 'kind' => $isFk ? 'fk' : 'name', 'where' => '',
                  'label' => USER_MERGE_LABELS[$key] ?? $key];
    }
    if (in_array('contract_type', userMergeTableColumns('contract_audit_log'), true)) {
        $out[] = ['table' => 'contract_audit_log', 'column' => 'contract_id', 'kind' => 'poly', 'where' => "contract_type = 'user'",
                  'label' => USER_MERGE_LABELS['contract_audit_log.contract_id']];
    }
    return $cache = $out;
}

/**
 * Liczba powiązanych rekordów na konto (wszystkie tabele) — jedno zapytanie GROUP BY na kolumnę, niezależnie od liczby kont.
 * @param int[] $ids
 * @return array<int, array{total:int, tables:int, items:list<array{i:int,key:string,label:string,n:int}>}>  i = indeks w userMergeRelationColumns()
 */
function userMergeRelationCounts(array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $res = [];
    foreach ($ids as $id) $res[$id] = ['total' => 0, 'tables' => 0, 'items' => []];
    if (!$ids) return $res;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (userMergeRelationColumns() as $i => $rc) {
        $w = $rc['where'] !== '' ? ' AND ' . $rc['where'] : '';
        try {
            $rows = db_all("SELECT `{$rc['column']}` AS uid, COUNT(*) AS n FROM `{$rc['table']}` WHERE `{$rc['column']}` IN ({$ph}){$w} GROUP BY `{$rc['column']}`", $ids);
        } catch (\Throwable $e) { continue; }
        foreach ($rows as $r) {
            $uid = (int)$r['uid']; $n = (int)$r['n'];
            if (!isset($res[$uid]) || $n < 1) continue;
            $res[$uid]['total'] += $n;
            $res[$uid]['tables']++;
            $res[$uid]['items'][] = ['i' => $i, 'key' => $rc['table'] . '.' . $rc['column'], 'label' => $rc['label'], 'n' => $n];
        }
    }
    foreach ($res as &$r) usort($r['items'], fn($a, $b) => $b['n'] <=> $a['n']);
    unset($r);
    return $res;
}

// ───────────────────────── Umowy (wiązane e-mailem) ─────────────────────────

/** Kolumny e-mail w tabelach umów: [['table','column','type']] (type = rodzaj umowy do historii, np. 'wolontariat'). */
function userMergeContractEmailColumns(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $tables = userMergeIsMysql()
        ? db()->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE 'umowy\\_%'")->fetchAll(PDO::FETCH_COLUMN)
        : db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'umowy\\_%' ESCAPE '\\'")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($tables as $t) {
        $cols = userMergeTableColumns($t);
        foreach (['email', 'email_login'] as $c) {
            if (in_array($c, $cols, true)) $out[] = ['table' => $t, 'column' => $c, 'type' => substr($t, 6), 'has_m365' => in_array('m365_login', $cols, true)];
        }
    }
    return $cache = $out;
}

/** Etykiety rodzajów umów. */
function userMergeContractTypeLabel(string $type): string {
    return ['wolontariat' => 'Wolontariat', 'zlecenie' => 'Zlecenie', 'praca' => 'Umowa o pracę', 'dzielo' => 'Dzieło',
            'uslugi' => 'Usługi', 'inne' => 'Inne', 'powierzenie' => 'Powierzenie', 'szk' => 'Szkoleniowa'][$type] ?? ucfirst($type);
}

/**
 * Liczba umów powiązanych z kontem (e-mail lub m365_login konta) per rodzaj umowy.
 * @return array<string,int> rodzaj => liczba
 */
function userMergeContractCounts(array $user): array {
    $email = mb_strtolower(trim((string)($user['email'] ?? '')));
    $login = mb_strtolower(trim((string)($user['m365_login'] ?? '')));
    $byType = [];   // rodzaj => [id => true] (umowa liczona raz, choć pasuje kilka kolumn)
    foreach (userMergeContractEmailColumns() as $cc) {
        $conds = []; $p = [];
        if ($email !== '') { $conds[] = "LOWER(`{$cc['column']}`) = ?"; $p[] = $email; }
        if ($login !== '' && $cc['has_m365']) { $conds[] = 'LOWER(`m365_login`) = ?'; $p[] = $login; }
        if (!$conds) continue;
        try {
            foreach (db_all("SELECT id FROM `{$cc['table']}` WHERE " . implode(' OR ', $conds), $p) as $r) $byType[$cc['type']][(int)$r['id']] = true;
        } catch (\Throwable $e) {}
    }
    return array_map('count', $byType);
}

/**
 * Przepisuje e-mail w umowach duplikatu na e-mail konta głównego (decyzja: umowy osoby widoczne z jednego konta).
 * Każda zmieniona umowa dostaje wpis w swojej historii. Wywoływać w transakcji scalania.
 * @return array<string,int> "tabela.kolumna" => liczba zmienionych umów
 */
function userMergeRewriteContractEmails(string $fromEmail, string $toEmail, int $byUid, int $fromUid, int $toUid): array {
    $from = mb_strtolower(trim($fromEmail));
    $to   = trim($toEmail);
    if ($from === '' || $to === '' || $from === mb_strtolower($to)) return [];
    require_once dirname(__DIR__, 3) . '/includes/approval.php';
    $out = [];
    foreach (userMergeContractEmailColumns() as $cc) {
        $rows = db_all("SELECT id FROM `{$cc['table']}` WHERE LOWER(`{$cc['column']}`) = ?", [$from]);
        if (!$rows) continue;
        $st = db()->prepare("UPDATE `{$cc['table']}` SET `{$cc['column']}` = ? WHERE id = ?");
        foreach ($rows as $r) {
            $st->execute([$to, (int)$r['id']]);
            log_contract_action($cc['type'], (int)$r['id'], $byUid, 'note',
                "Scalenie kont #{$fromUid} → #{$toUid}: {$cc['column']} umowy zmieniony z {$fromEmail} na {$to}");
        }
        $out[$cc['table'] . '.' . $cc['column']] = count($rows);
    }
    return $out;
}

// ───────────────────────── Przepinanie ─────────────────────────

/**
 * Przepina wszystkie powiązania z konta $from na $to. Wiersze, które złamałyby klucz unikalny (np. to samo uprawnienie
 * już na koncie głównym), zostają przy archiwum — są liczone w 'left'. Wywoływać w transakcji scalania.
 * @return array{moved: array<string,int>, left: array<string,int>}
 */
function userMergeRepoint(int $from, int $to): array {
    $verb = userMergeIsMysql() ? 'UPDATE IGNORE' : 'UPDATE OR IGNORE';
    $moved = []; $left = [];
    $before = [];
    foreach (userMergeRelationCounts([$from])[$from]['items'] ?? [] as $it) $before[$it['i']] = $it['n'];
    $cols = userMergeRelationColumns();
    foreach ($before as $i => $n) {
        $rc = $cols[$i];
        $w = $rc['where'] !== '' ? ' AND ' . $rc['where'] : '';
        $st = db()->prepare("{$verb} `{$rc['table']}` SET `{$rc['column']}` = ? WHERE `{$rc['column']}` = ?{$w}");
        $st->execute([$to, $from]);
        $done = $st->rowCount();
        $label = $rc['table'] . '.' . $rc['column'] . ($rc['where'] !== '' ? ' [' . $rc['where'] . ']' : '');
        if ($done > 0) $moved[$label] = $done;
        if ($n - $done > 0) $left[$label] = $n - $done;
    }
    // Odwołania wewnątrz users: opiekun (guardian_user_id) i wcześniejsze scalenia do konta $from (łańcuch spłaszczony).
    foreach (['guardian_user_id', 'merged_into_id'] as $c) {
        if (!in_array($c, userMergeTableColumns('users'), true)) continue;
        $st = db()->prepare("UPDATE users SET `{$c}` = ? WHERE `{$c}` = ? AND id <> ?");
        $st->execute([$to, $from, $to]);
        if ($st->rowCount() > 0) $moved['users.' . $c] = $st->rowCount();
    }
    return ['moved' => $moved, 'left' => $left];
}
