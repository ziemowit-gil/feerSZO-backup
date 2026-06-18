<?php
/**
 * Role-based permissions system
 * Auto-migrates tables and seeds default roles on first load.
 */

const PERMISSION_MODULES = [
    'umowy'         => 'Umowy',
    'osoby'         => 'Strony umów',
    'granty'        => 'Granty',
    'dzialania'     => 'Działania',
    'pisma'         => 'Pisma umów',
    'akceptacje'    => 'Akceptacje',
    'raporty'       => 'Raporty umów',
    'org'           => 'Struktura organizacyjna',
    'ezd'           => 'Kancelaria EZD',
    'procedury'     => 'Procedury',
    'zadania'       => 'Zadania',
    'zgloszenia'    => 'Zgłoszenia',
    'zaswiadczenia' => 'Zaświadczenia',
    'rozwiazania'   => 'Rozwiązania',
    'wiadomosci'    => 'Wiadomości',
    'crm'              => 'CRM — Kontakty (dostęp bazowy)',
    'crm_eksport'      => 'CRM — Eksport kontaktów',
    'crm_import'       => 'CRM — Import kontaktów',
    'crm_mailing'      => 'CRM — Mailing masowy i komunikacja',
    'crm_ustawienia'   => 'CRM — Ustawienia (statusy, pola, role)',
    'karty30'          => 'Karty 30 — TyfloKonsultacje',
    'wydarzenia'    => 'Moduł Wydarzeń',
    'admin'         => 'Administracja',
];

// Moduły dostępne dla roli crm_only (tylko CRM — bez systemu głównego)
const CRM_ONLY_MODULES = ['crm'];

// Moduły dostępne dla roli ezd_only (tylko Kancelaria EZD — bez systemu głównego)
const EZD_ONLY_MODULES = ['ezd'];

// ── Auto-migration (lazy — runs on first permissions function call) ───────────
function _permissions_init(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        display_name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        is_system INTEGER NOT NULL DEFAULT 0,
        crm_only  INTEGER NOT NULL DEFAULT 0,
        ezd_only  INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Migracja istniejących baz — dodaj kolumny flag jeśli nie istnieją
    try { $pdo->exec("ALTER TABLE roles ADD COLUMN crm_only INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE roles ADD COLUMN ezd_only INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    // wbudowane role z ograniczonym zakresem
    try { $pdo->exec("UPDATE roles SET crm_only=1 WHERE name='crm_user'"); } catch (\Throwable $e) {}
    try { $pdo->exec("UPDATE roles SET ezd_only=1 WHERE name='ezd_user'"); } catch (\Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
        module TEXT NOT NULL,
        can_read INTEGER NOT NULL DEFAULT 0,
        can_write INTEGER NOT NULL DEFAULT 0,
        can_delete INTEGER NOT NULL DEFAULT 0,
        UNIQUE(role_id, module)
    )");

    // Seed default roles if table is empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    if ($count === 0) {
        $modules = array_keys(PERMISSION_MODULES);

        $pdo->exec("INSERT INTO roles (name, display_name, description, is_system, crm_only, ezd_only, sort_order) VALUES
            ('admin',    'Administrator', 'Pełen dostęp do wszystkich modułów i ustawień', 1, 0, 0, 1),
            ('editor',   'Edytor',        'Odczyt i zapis we wszystkich modułach (bez administracji)', 1, 0, 0, 2),
            ('viewer',   'Widz',          'Tylko odczyt (bez administracji)', 1, 0, 0, 3),
            ('crm_user', 'Użytkownik CRM','Dostęp wyłącznie do modułu CRM — bez systemu głównego', 1, 1, 0, 4),
            ('ezd_user', 'Użytkownik EZD','Dostęp wyłącznie do modułu Kancelaria EZD — bez systemu głównego', 1, 0, 1, 5)
        ");

        $admin_id    = (int)$pdo->query("SELECT id FROM roles WHERE name='admin'")->fetchColumn();
        $editor_id   = (int)$pdo->query("SELECT id FROM roles WHERE name='editor'")->fetchColumn();
        $viewer_id   = (int)$pdo->query("SELECT id FROM roles WHERE name='viewer'")->fetchColumn();
        $crm_user_id = (int)$pdo->query("SELECT id FROM roles WHERE name='crm_user'")->fetchColumn();
        $ezd_user_id = (int)$pdo->query("SELECT id FROM roles WHERE name='ezd_user'")->fetchColumn();

        $ins = $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,?,?,?)");

        foreach ($modules as $mod) {
            $ins->execute([$admin_id, $mod, 1, 1, 1]);
            if ($mod !== 'admin') {
                $ins->execute([$editor_id, $mod, 1, 1, 0]);
                $ins->execute([$viewer_id, $mod, 1, 0, 0]);
            }
        }
        // crm_user: tylko crm read+write
        $ins->execute([$crm_user_id, 'crm', 1, 1, 0]);
        // ezd_user: tylko ezd read+write
        $ins->execute([$ezd_user_id, 'ezd', 1, 1, 0]);
    }

    // Idempotentnie dodaj rolę ezd_user jeśli nie istnieje (dla istniejących baz)
    $ezd_user_exists = $pdo->query("SELECT COUNT(*) FROM roles WHERE name='ezd_user'")->fetchColumn();
    if (!$ezd_user_exists) {
        $pdo->exec("INSERT INTO roles (name, display_name, description, is_system, crm_only, ezd_only, sort_order)
                    VALUES ('ezd_user','Użytkownik EZD','Dostęp wyłącznie do modułu Kancelaria EZD — bez systemu głównego',1,0,1,5)");
        $ezd_id = (int)$pdo->query("SELECT id FROM roles WHERE name='ezd_user'")->fetchColumn();
        $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,?,?,?)")
            ->execute([$ezd_id, 'ezd', 1, 1, 0]);
    }

    // Idempotentnie dodaj rolę crm_user jeśli nie istnieje (dla istniejących baz)
    $crm_user_exists = $pdo->query("SELECT COUNT(*) FROM roles WHERE name='crm_user'")->fetchColumn();
    if (!$crm_user_exists) {
        $pdo->exec("INSERT INTO roles (name, display_name, description, is_system, sort_order)
                    VALUES ('crm_user','Użytkownik CRM','Dostęp wyłącznie do modułu CRM — bez systemu głównego',1,4)");
        $crm_id = (int)$pdo->query("SELECT id FROM roles WHERE name='crm_user'")->fetchColumn();
        $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,?,?,?)")
            ->execute([$crm_id, 'crm', 1, 1, 0]);
    }

    // Idempotentnie upewnij się, że moduł 'crm' istnieje w uprawnieniach admin i editor
    foreach (['admin'=>[1,1,1],'editor'=>[1,1,0],'viewer'=>[1,0,0]] as $rname=>$perms) {
        try {
            $rid = (int)$pdo->query("SELECT id FROM roles WHERE name='$rname'")->fetchColumn();
            if ($rid) {
                $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id,module,can_read,can_write,can_delete) VALUES (?,?,?,?,?)")
                    ->execute([$rid,'crm',...$perms]);
            }
        } catch (\Throwable $e) {}
    }

    // Idempotentnie upewnij się, że moduł 'wydarzenia' istnieje w uprawnieniach
    foreach (['admin'=>[1,1,1],'editor'=>[1,1,0],'viewer'=>[1,0,0]] as $rname=>$perms) {
        try {
            $rid = (int)$pdo->query("SELECT id FROM roles WHERE name='$rname'")->fetchColumn();
            if ($rid) {
                $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id,module,can_read,can_write,can_delete) VALUES (?,?,?,?,?)")
                    ->execute([$rid,'wydarzenia',...$perms]);
            }
        } catch (\Throwable $e) {}
    }

    // ── CRM sub-moduły — seed dla istniejących ról ───────────────────────────
    // admin: pełen dostęp; editor: eksport+import+mailing (bez ustawień); viewer: nic; crm_user: mailing
    $crm_sub_defaults = [
        'crm_eksport'    => ['admin'=>[1,1,1],'editor'=>[1,1,0],'viewer'=>[0,0,0],'crm_user'=>[0,0,0]],
        'crm_import'     => ['admin'=>[1,1,1],'editor'=>[1,1,0],'viewer'=>[0,0,0],'crm_user'=>[0,0,0]],
        'crm_mailing'    => ['admin'=>[1,1,1],'editor'=>[1,1,0],'viewer'=>[0,0,0],'crm_user'=>[1,1,0]],
        'crm_ustawienia' => ['admin'=>[1,1,1],'editor'=>[0,0,0],'viewer'=>[0,0,0],'crm_user'=>[0,0,0]],
    ];
    foreach ($crm_sub_defaults as $mod => $role_map) {
        foreach ($role_map as $rname => $perms) {
            try {
                $rid = $pdo->query("SELECT id FROM roles WHERE name=" . $pdo->quote($rname))->fetchColumn();
                if ($rid) {
                    $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id,module,can_read,can_write,can_delete) VALUES (?,?,?,?,?)")
                        ->execute([(int)$rid, $mod, ...$perms]);
                }
            } catch (\Throwable $e) {}
        }
    }
}

// ── Role helpers ──────────────────────────────────────────────────────────────

function roles_all(): array {
    _permissions_init();
    return db_all(
        "SELECT r.*, (SELECT COUNT(*) FROM users WHERE role = r.name) AS user_count
         FROM roles r ORDER BY sort_order, id"
    );
}

function role_permissions(string $role_name): array {
    _permissions_init();
    static $cache = [];
    if (isset($cache[$role_name])) return $cache[$role_name];
    try {
        $rows = db_all(
            "SELECT rp.* FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.name = ?",
            [$role_name]
        );
    } catch (\Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        $map[$row['module']] = $row;
    }
    return $cache[$role_name] = $map;
}

// ── Permission check functions ────────────────────────────────────────────────

function can_read(string $module): bool {
    _permissions_init();
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $perms = role_permissions($user['role']);
    return !empty($perms[$module]['can_read']);
}

function can_write(string $module): bool {
    _permissions_init();
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $perms = role_permissions($user['role']);
    return !empty($perms[$module]['can_write']);
}

function can_delete(string $module): bool {
    _permissions_init();
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $perms = role_permissions($user['role']);
    return !empty($perms[$module]['can_delete']);
}

/**
 * Backward-compat wrapper: can_edit() = can write umowy OR granty OR ezd.
 * (EZD dodane, by rola ezd_user mogła zakładać sprawy/pisma/dokumenty —
 *  strony EZD bramkują akcje przez can_edit(); rola jest i tak zamknięta do /ezd/.)
 * Overrides the function defined in auth.php (auth.php must require_once this file first).
 */
if (!function_exists('can_edit')) {
    function can_edit(): bool {
        return can_write('umowy') || can_write('granty') || can_write('ezd');
    }
}
