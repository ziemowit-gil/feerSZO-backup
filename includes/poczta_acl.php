<?php
/**
 * includes/poczta_acl.php — Dostęp do skrzynek modułu Poczta.
 *
 * Moduł jest dostępny dla każdego zalogowanego (także wolontariusza), ale to,
 * KTÓRE skrzynki widzi, wynika z uprawnień:
 *
 *   • skrzynka WSPÓŁDZIELONA (kind='shared', np. fundacja@feer.org.pl)
 *     — tylko dla osób z wpisem w poczta_mailbox_acl (albo dla administratora),
 *   • skrzynka OSOBISTA (kind='personal', poczta współpracownika)
 *     — dla właściciela (owner_user_id) oraz osób, którym właściciel/admin
 *       nadał dostęp (delegacja, np. asysta w zastępstwie).
 *
 * Uprawnienia w ACL:
 *   can_read   — widzi wiadomości skrzynki (Poczta, Skrzynka CRM, Inbox EZD),
 *   can_manage — może edytować konfigurację skrzynki i uruchamiać skanowanie.
 *
 * Administrator ma dostęp do wszystkiego (i tylko on może dodawać skrzynki),
 * bo konfiguracja skrzynek to decyzja organizacyjna, nie użytkownika.
 */

require_once __DIR__ . '/poczta.php';

const POCZTA_KINDS = [
    'shared'   => ['label' => 'Współdzielona', 'icon' => 'bi-people-fill'],
    'personal' => ['label' => 'Osobista',      'icon' => 'bi-person-fill'],
];

function poczta_acl_migrate(): bool {
    static $done = null;
    if ($done !== null) return $done;
    $done = false;
    try {
        $pdo = db();
        foreach ([
            "ALTER TABLE poczta_mailboxes ADD COLUMN kind TEXT NOT NULL DEFAULT 'shared'",
            "ALTER TABLE poczta_mailboxes ADD COLUMN owner_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (\Throwable $e) {}
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS poczta_mailbox_acl (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            mailbox_id INTEGER NOT NULL REFERENCES poczta_mailboxes(id) ON DELETE CASCADE,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            can_read   INTEGER NOT NULL DEFAULT 1,
            can_manage INTEGER NOT NULL DEFAULT 0,
            granted_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            granted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(mailbox_id, user_id)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_poczta_acl_user ON poczta_mailbox_acl(user_id)");
        $done = true;
    } catch (\Throwable $e) {
        error_log('[poczta_acl_migrate] ' . $e->getMessage());
    }
    return $done;
}

/**
 * Unieważnia pamięciowy cache ACL. Wywoływane po nadaniu/odebraniu dostępu —
 * bez tego kolejne sprawdzenie w tym samym procesie (CLI, cron, seria akcji)
 * widziałoby stan sprzed zmiany.
 */
function poczta_acl_cache_bump(): void {
    $GLOBALS['_poczta_acl_ver'] = (int)($GLOBALS['_poczta_acl_ver'] ?? 0) + 1;
}

/** Wpisy ACL bieżącego (lub wskazanego) użytkownika: [mailbox_id => ['read','manage']]. */
function poczta_acl_for_user(?int $user_id = null): array {
    poczta_acl_migrate();
    $uid = $user_id ?? (int)(current_user()['id'] ?? 0);
    $ver = (int)($GLOBALS['_poczta_acl_ver'] ?? 0);
    static $cache = [];
    if (isset($cache[$uid]) && ($cache[$uid]['_ver'] ?? -1) === $ver) return $cache[$uid]['acl'];
    $out = [];
    if ($uid) {
        try {
            foreach (db_all("SELECT mailbox_id, can_read, can_manage FROM poczta_mailbox_acl WHERE user_id=?", [$uid]) as $r) {
                $out[(int)$r['mailbox_id']] = [
                    'read'   => (int)$r['can_read'] === 1,
                    'manage' => (int)$r['can_manage'] === 1,
                ];
            }
        } catch (\Throwable $e) {}
    }
    $cache[$uid] = ['_ver' => $ver, 'acl' => $out];
    return $out;
}

/**
 * Czy użytkownik ma dostęp do skrzynki. $perm: 'read' | 'manage'.
 * Admin — zawsze. Właściciel skrzynki osobistej — czyta i zarządza swoją.
 */
function poczta_can_access(int $mailbox_id, string $perm = 'read', ?int $user_id = null): bool {
    poczta_acl_migrate();
    $uid = $user_id ?? (int)(current_user()['id'] ?? 0);
    if (!$uid) return false;
    if (function_exists('is_admin') && is_admin() && $user_id === null) return true;

    try {
        $mb = db_one("SELECT id, kind, owner_user_id FROM poczta_mailboxes WHERE id=?", [$mailbox_id]);
    } catch (\Throwable $e) { return false; }
    if (!$mb) return false;

    if ((string)($mb['kind'] ?? 'shared') === 'personal' && (int)($mb['owner_user_id'] ?? 0) === $uid) {
        return true;   // własna skrzynka
    }
    $acl = poczta_acl_for_user($uid);
    if (!isset($acl[$mailbox_id])) return false;
    return $perm === 'manage' ? $acl[$mailbox_id]['manage'] : $acl[$mailbox_id]['read'];
}

/** Identyfikatory skrzynek, których wiadomości użytkownik może czytać. */
function poczta_allowed_mailbox_ids(?int $user_id = null): array {
    poczta_acl_migrate();
    $uid = $user_id ?? (int)(current_user()['id'] ?? 0);
    if (!$uid) return [];
    try {
        if (function_exists('is_admin') && is_admin() && $user_id === null) {
            return array_map('intval', array_column(db_all("SELECT id FROM poczta_mailboxes"), 'id'));
        }
        $rows = db_all(
            "SELECT m.id FROM poczta_mailboxes m
             LEFT JOIN poczta_mailbox_acl a ON a.mailbox_id = m.id AND a.user_id = ?
             WHERE (m.kind='personal' AND m.owner_user_id = ?) OR (a.can_read = 1)",
            [$uid, $uid]
        );
        return array_map('intval', array_column($rows, 'id'));
    } catch (\Throwable $e) { return []; }
}

/** Skrzynki widoczne dla użytkownika (pełne wiersze + flaga zarządzania). */
function poczta_mailboxes_for_user(?int $user_id = null): array {
    $ids = poczta_allowed_mailbox_ids($user_id);
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    try {
        $rows = db_all(
            "SELECT m.*, u.name AS owner_name
             FROM poczta_mailboxes m
             LEFT JOIN users u ON u.id = m.owner_user_id
             WHERE m.id IN ($in)
             ORDER BY (m.status != 'ok') DESC, m.kind, m.mailbox"
        );
    } catch (\Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $r['can_manage'] = poczta_can_access((int)$r['id'], 'manage', $user_id);
    }
    return $rows;
}

/**
 * Fragment SQL ograniczający wiadomości do skrzynek dozwolonych dla użytkownika.
 * Administrator widzi dodatkowo wpisy bez przypisanej skrzynki (starsza
 * korespondencja z crm_inbox_watch, gdzie mailbox_id nie było ustawiane).
 * Brak jakiegokolwiek dostępu → warunek fałszywy, czyli pusta lista.
 */
function poczta_scope_sql(string $col = 'c.mailbox_id'): string {
    $admin = function_exists('is_admin') && is_admin();
    $ids   = poczta_allowed_mailbox_ids();
    $in    = $ids ? implode(',', array_map('intval', $ids)) : '';

    if ($admin) {
        return $in !== '' ? "($col IN ($in) OR $col IS NULL)" : "1=1";
    }
    return $in !== '' ? "$col IN ($in)" : '1=0';
}

// ── Zarządzanie ACL ─────────────────────────────────────────────────────────

function poczta_acl_list(int $mailbox_id): array {
    poczta_acl_migrate();
    try {
        return db_all(
            "SELECT a.*, u.name AS user_name, u.email AS user_email
             FROM poczta_mailbox_acl a JOIN users u ON u.id = a.user_id
             WHERE a.mailbox_id=? ORDER BY u.name", [$mailbox_id]
        );
    } catch (\Throwable $e) { return []; }
}

function poczta_acl_grant(int $mailbox_id, int $user_id, bool $can_manage = false): void {
    poczta_acl_migrate();
    poczta_acl_cache_bump();
    $by = (int)(current_user()['id'] ?? 0) ?: null;
    try {
        db()->prepare(
            "INSERT INTO poczta_mailbox_acl (mailbox_id, user_id, can_read, can_manage, granted_by)
             VALUES (?,?,1,?,?)
             ON CONFLICT(mailbox_id, user_id) DO UPDATE SET can_read=1, can_manage=excluded.can_manage"
        )->execute([$mailbox_id, $user_id, $can_manage ? 1 : 0, $by]);
    } catch (\Throwable $e) {
        // Starsze SQLite bez UPSERT — awaryjnie usuń i wstaw
        try {
            db()->prepare("DELETE FROM poczta_mailbox_acl WHERE mailbox_id=? AND user_id=?")->execute([$mailbox_id, $user_id]);
            db_insert('poczta_mailbox_acl', [
                'mailbox_id' => $mailbox_id, 'user_id' => $user_id,
                'can_read' => 1, 'can_manage' => $can_manage ? 1 : 0, 'granted_by' => $by,
            ]);
        } catch (\Throwable $e2) { error_log('[poczta_acl_grant] ' . $e2->getMessage()); }
    }
}

function poczta_acl_revoke(int $mailbox_id, int $user_id): void {
    poczta_acl_migrate();
    poczta_acl_cache_bump();
    try {
        db()->prepare("DELETE FROM poczta_mailbox_acl WHERE mailbox_id=? AND user_id=?")->execute([$mailbox_id, $user_id]);
    } catch (\Throwable $e) {}
}

// ── Webmail: z czego wygodniej korzystać ────────────────────────────────────

/**
 * Adresy webmaili do podpowiedzi. Moduł Poczta służy do skanowania korespondencji
 * do CRM/EZD — do codziennego czytania i pisania wygodniej użyć webmaila.
 *
 * @return array<int, array{key:string,label:string,url:string,hint:string,icon:string}>
 */
function poczta_webmail_options(): array {
    $out = [];
    $owa = org_setting('poczta_owa_url') ?: 'https://outlook.office.com/mail/';
    $rc  = org_setting('poczta_webmail_url') ?: (function_exists('crm_setting') ? crm_setting('roundcube_url') : '');
    if ($rc === '') $rc = 'https://rc.feer.org.pl';

    if ($owa !== '') $out[] = [
        'key' => 'owa', 'label' => 'Outlook w przeglądarce', 'url' => rtrim($owa, '/'),
        'icon' => 'bi-microsoft',
        'hint' => 'Pełny klient Microsoft 365: kalendarz, kontakty, reguły, skrzynki współdzielone '
                . 'dodane przez administratora. Zalecany, gdy pracujesz na koncie @feer.org.pl.',
    ];
    if ($rc !== '') $out[] = [
        'key' => 'rc', 'label' => 'Roundcube (rc.feer.org.pl)', 'url' => rtrim($rc, '/'),
        'icon' => 'bi-envelope-open',
        'hint' => 'Lżejszy i szybszy przy słabym łączu, wygodny na starszym sprzęcie '
                . 'i przy jednorazowym zajrzeniu do skrzynki.',
    ];
    return $out;
}
