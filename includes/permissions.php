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
    'ezd'           => 'Wirtualne biurko',
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
    'karty30'          => 'Dydaktyka 3 — Dydaktyka',
    'wydarzenia'    => 'Moduł Wydarzeń',
    'szkolenia'     => 'Szkolenia (rezerwacja TidyCal)',
    'poczta'        => 'Moduł Poczty',
    'wsparcie_ou'   => 'Rozliczanie OU',
    'srs'           => 'System Rezerwacji Sal (SRS)',
    'admin'         => 'Administracja',
];

/**
 * Rejestr modułów dla launchera kont zawężonych (crm_only / ezd_only):
 * moduł uprawnień → punkt wejścia + prefiksy ścieżek (allow-lista require_login)
 * + prezentacja kafla. Tylko moduły mające samodzielny ekran.
 */
const MODULE_REGISTRY = [
    'crm'           => ['label'=>'CRM',            'url'=>'/crm/dashboard.php',               'paths'=>['/crm/'],          'icon'=>'bi-diagram-2-fill',     'grad'=>'linear-gradient(135deg,#14532D,#16A34A)'],
    'ezd'           => ['label'=>'Wirtualne biurko', 'url'=>'/ezd/index.php',                   'paths'=>['/ezd/'],          'icon'=>'bi-folder2-open',       'grad'=>'linear-gradient(135deg,#134E4A,#0D9488)'],
    'umowy'         => ['label'=>'Umowy',          'url'=>'/contracts/wolontariat/list.php',  'paths'=>['/contracts/'],    'icon'=>'bi-file-earmark-text',  'grad'=>'linear-gradient(135deg,#1E3A5F,#1D6EF9)'],
    'osoby'         => ['label'=>'Strony umów',    'url'=>'/persons/index.php',               'paths'=>['/persons/'],      'icon'=>'bi-people-fill',        'grad'=>'linear-gradient(135deg,#3730A3,#6366F1)'],
    'granty'        => ['label'=>'Granty',         'url'=>'/grants/index.php',                'paths'=>['/grants/'],       'icon'=>'bi-cash-coin',          'grad'=>'linear-gradient(135deg,#14532D,#15803D)'],
    'dzialania'     => ['label'=>'Działania',      'url'=>'/strategy/actions/index.php',      'paths'=>['/actions/','/strategy/actions/'], 'icon'=>'bi-lightning-charge-fill', 'grad'=>'linear-gradient(135deg,#155E75,#0891B2)'],
    'raporty'       => ['label'=>'Raporty',        'url'=>'/reports/index.php',               'paths'=>['/reports/'],      'icon'=>'bi-bar-chart-line-fill','grad'=>'linear-gradient(135deg,#0C4A6E,#0284C7)'],
    'org'           => ['label'=>'Struktura org.', 'url'=>'/org/index.php',                   'paths'=>['/org/'],          'icon'=>'bi-diagram-3-fill',     'grad'=>'linear-gradient(135deg,#0F766E,#14B8A6)'],
    'procedury'     => ['label'=>'Procedury',      'url'=>'/procedures/index.php',            'paths'=>['/procedures/'],   'icon'=>'bi-journal-text',       'grad'=>'linear-gradient(135deg,#374151,#6B7280)'],
    'zadania'       => ['label'=>'Zadania',        'url'=>'/tasks/dashboard.php',             'paths'=>['/tasks/'],        'icon'=>'bi-kanban-fill',        'grad'=>'linear-gradient(135deg,#9A3412,#EA580C)'],
    'zgloszenia'    => ['label'=>'Zgłoszenia',     'url'=>'/helpdesk/index.php',              'paths'=>['/helpdesk/'],     'icon'=>'bi-ticket-perforated-fill','grad'=>'linear-gradient(135deg,#92400E,#D97706)'],
    'zaswiadczenia' => ['label'=>'Zaświadczenia',  'url'=>'/certificates/issue.php',          'paths'=>['/certificates/'], 'icon'=>'bi-patch-check-fill',   'grad'=>'linear-gradient(135deg,#5B21B6,#8B5CF6)'],
    'rozwiazania'   => ['label'=>'Rozwiązania',    'url'=>'/resolutions/index.php',           'paths'=>['/resolutions/'],  'icon'=>'bi-file-earmark-break',  'grad'=>'linear-gradient(135deg,#7F1D1D,#DC2626)'],
    'wiadomosci'    => ['label'=>'Wiadomości',     'url'=>'/komunikaty/index.php',            'paths'=>['/komunikaty/'],   'icon'=>'bi-chat-dots-fill',     'grad'=>'linear-gradient(135deg,#0E7490,#06B6D4)'],
    'karty30'       => ['label'=>'Dydaktyka 3',       'url'=>'/karty30/index.php',               'paths'=>['/karty30/'],      'icon'=>'bi-card-checklist',     'grad'=>'linear-gradient(135deg,#581C87,#7C3AED)'],
    'wydarzenia'    => ['label'=>'Wydarzenia',     'url'=>'/events/index.php',                'paths'=>['/events/'],       'icon'=>'bi-calendar-event-fill','grad'=>'linear-gradient(135deg,#9D174D,#EC4899)'],
    'szkolenia'     => ['label'=>'Szkolenia',      'url'=>'/szkolenia/index.php',             'paths'=>['/szkolenia/'],    'icon'=>'bi-calendar2-check',    'grad'=>'linear-gradient(135deg,#7C3AED,#C084FC)'],
    'poczta'        => ['label'=>'Poczta',         'url'=>'/poczta/dashboard.php',            'paths'=>['/poczta/'],       'icon'=>'bi-envelope-fill',      'grad'=>'linear-gradient(135deg,#1D4ED8,#3B82F6)'],
];

// Moduły dostępne dla roli crm_only (tylko CRM — bez systemu głównego)
const CRM_ONLY_MODULES = ['crm'];

// Moduły dostępne dla roli ezd_only (tylko Wirtualne biurko — bez systemu głównego)
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
        ezd_rpw_only INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Migracja istniejących baz — dodaj kolumny flag jeśli nie istnieją
    try { $pdo->exec("ALTER TABLE roles ADD COLUMN crm_only INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE roles ADD COLUMN ezd_only INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    // ezd_rpw_only — zawężenie w obrębie ezd_only WYŁĄCZNIE do Rejestru Przesyłek
    // Wpływających (RPW, „Rejestr Przychodzących"); zob. rola 'ezd_biuro'.
    try { $pdo->exec("ALTER TABLE roles ADD COLUMN ezd_rpw_only INTEGER NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
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

    // Uprawnienia przyznane indywidualnie użytkownikowi — DODATKOWO ponad rolę.
    // Efektywny dostęp = uprawnienia roli ∪ uprawnienia użytkownika (suma).
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        module TEXT NOT NULL,
        can_read INTEGER NOT NULL DEFAULT 0,
        can_write INTEGER NOT NULL DEFAULT 0,
        can_delete INTEGER NOT NULL DEFAULT 0,
        granted_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, module)
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
            ('ezd_user', 'Użytkownik EZD','Dostęp wyłącznie do modułu Wirtualne biurko — bez systemu głównego', 1, 0, 1, 5)
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
                // EZD: dostęp tylko per-konto (user_permissions) albo rola ezd_user;
                // editor i viewer nie dostają EZD przez rolę.
                if ($mod !== 'ezd') {
                    $ins->execute([$editor_id, $mod, 1, 1, 0]);
                }
                $ins->execute([$viewer_id, $mod, $mod === 'ezd' ? 0 : 1, 0, 0]);
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
                    VALUES ('ezd_user','Użytkownik EZD','Dostęp wyłącznie do modułu Wirtualne biurko — bez systemu głównego',1,0,1,5)");
        $ezd_id = (int)$pdo->query("SELECT id FROM roles WHERE name='ezd_user'")->fetchColumn();
        $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,?,?,?)")
            ->execute([$ezd_id, 'ezd', 1, 1, 0]);
    }

    // Idempotentnie dodaj rolę ezd_biuro jeśli nie istnieje — biuro/kancelaria:
    // wgląd i rejestrowanie przesyłek WYŁĄCZNIE w Rejestrze Przesyłek Wpływających
    // (RPW, „Rejestr Przychodzących"); reszta modułu EZD (koszulki, sprawy, pisma,
    // archiwum, JRWA…) jest dla tej roli zablokowana (require_login() w auth.php).
    $ezd_biuro_exists = $pdo->query("SELECT COUNT(*) FROM roles WHERE name='ezd_biuro'")->fetchColumn();
    if (!$ezd_biuro_exists) {
        $pdo->exec("INSERT INTO roles (name, display_name, description, is_system, crm_only, ezd_only, ezd_rpw_only, sort_order)
                    VALUES ('ezd_biuro','EZD - biuro','Biuro/kancelaria — wgląd i rejestrowanie przesyłek wyłącznie w Rejestrze Przychodzących (RPW), bez dostępu do pozostałych sekcji Wirtualnego biurka',1,0,1,1,6)");
        $ezd_biuro_id = (int)$pdo->query("SELECT id FROM roles WHERE name='ezd_biuro'")->fetchColumn();
        $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,?,?,?)")
            ->execute([$ezd_biuro_id, 'ezd', 1, 1, 0]);
    }

    // EZD: wymuś brak dostępu przez rolę dla editor i viewer (dostęp tylko per-konto lub rola ezd_user)
    try {
        $pdo->exec("UPDATE role_permissions SET can_read=0, can_write=0, can_delete=0
                    WHERE module='ezd'
                    AND role_id IN (SELECT id FROM roles WHERE name IN ('editor','viewer'))");
    } catch (\Throwable $e) {}

    // Idempotentnie dodaj rolę dydaktyk_sekretariat jeśli nie istnieje
    if (!$pdo->query("SELECT COUNT(*) FROM roles WHERE name='dydaktyk_sekretariat'")->fetchColumn()) {
        $pdo->exec("INSERT INTO roles (name, display_name, description, is_system, sort_order)
                    VALUES ('dydaktyk_sekretariat','Dydaktyka: sekretariat','Sekretariat dydaktyki TI — odbiera wiadomości od kursantów',1,6)");
        $sek_id = (int)$pdo->query("SELECT id FROM roles WHERE name='dydaktyk_sekretariat'")->fetchColumn();
        $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,?,?,?)")
            ->execute([$sek_id, 'karty30', 1, 0, 0]);
    }

    // Idempotentnie dodaj rolę srs_koordynator — zarządza zasobami (salami)
    // i decyduje o rezerwacjach w Systemie Rezerwacji Sal (SRS), bez dostępu
    // do pozostałych modułów administracyjnych i bez pełnego is_admin().
    if (!$pdo->query("SELECT COUNT(*) FROM roles WHERE name='srs_koordynator'")->fetchColumn()) {
        $pdo->exec("INSERT INTO roles (name, display_name, description, is_system, sort_order)
                    VALUES ('srs_koordynator','SRS: koordynator sal','Zarządza zasobami (salami) i kategoriami oraz decyduje o wnioskach o rezerwację w Systemie Rezerwacji Sal',1,7)");
        $srs_id = (int)$pdo->query("SELECT id FROM roles WHERE name='srs_koordynator'")->fetchColumn();
        $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, module, can_read, can_write, can_delete) VALUES (?,?,?,?,?)")
            ->execute([$srs_id, 'srs', 1, 1, 0]);
    }

    // Idempotentnie dodaj rolę dydaktyk_ti — konto panelu dydaktyka TI (system
    // hybrydowy): tworzone i zarządzane WYŁĄCZNIE w panelu TI (Zespół i role),
    // bez logowania do SZO i bez uprawnień modułowych. Wiersz users istnieje
    // tylko dla FK (kursy/dostępności/wypłaty wskazują users.id).
    if (!$pdo->query("SELECT COUNT(*) FROM roles WHERE name='dydaktyk_ti'")->fetchColumn()) {
        $pdo->exec("INSERT INTO roles (name, display_name, description, is_system, sort_order)
                    VALUES ('dydaktyk_ti','Dydaktyk TI (konto panelu)','Konto panelu dydaktyka TI — zarządzane w panelu TI (Zespół i role), bez dostępu do systemu SZO',1,7)");
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

    // Idempotentnie upewnij się, że moduł 'poczta' istnieje w uprawnieniach
    foreach (['admin'=>[1,1,1],'editor'=>[1,1,0],'viewer'=>[1,0,0]] as $rname=>$perms) {
        try {
            $rid = (int)$pdo->query("SELECT id FROM roles WHERE name='$rname'")->fetchColumn();
            if ($rid) {
                $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id,module,can_read,can_write,can_delete) VALUES (?,?,?,?,?)")
                    ->execute([$rid,'poczta',...$perms]);
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

// ── Indywidualne uprawnienia użytkownika (dodatkowe moduły ponad rolę) ─────────

/** Mapa modułów przyznanych konkretnemu użytkownikowi: module => wiersz. */
function user_permissions(int $user_id): array {
    _permissions_init();
    static $cache = [];
    if ($user_id <= 0) return [];
    if (isset($cache[$user_id])) return $cache[$user_id];
    try {
        $rows = db_all("SELECT * FROM user_permissions WHERE user_id = ?", [$user_id]);
    } catch (\Throwable $e) {
        return $cache[$user_id] = [];
    }
    $map = [];
    foreach ($rows as $row) $map[$row['module']] = $row;
    return $cache[$user_id] = $map;
}

/** Lista nazw modułów przyznanych użytkownikowi indywidualnie. */
function user_extra_modules(int $user_id): array {
    return array_keys(user_permissions($user_id));
}

/** Bazowy moduł konta zawężonego (crm_only → 'crm', ezd_only → 'ezd') lub null. */
function scoped_base_module(): ?string {
    if (function_exists('is_crm_only') && is_crm_only()) return 'crm';
    if (function_exists('is_ezd_only') && is_ezd_only()) return 'ezd';
    return null;
}

/**
 * Moduły do pokazania w launcherze konta zawężonego: bazowy + indywidualnie
 * przyznane (tylko te z odczytem i obecne w MODULE_REGISTRY). Zwraca klucze.
 */
function scoped_launcher_module_keys(int $user_id): array {
    $base = scoped_base_module();
    if ($base === null) return [];
    $keys = [];
    foreach (user_permissions($user_id) as $mod => $row) {
        if ($mod === $base) continue;
        if (!empty($row['can_read']) && isset(MODULE_REGISTRY[$mod])) $keys[] = $mod;
    }
    return $keys; // bez bazowego — wołający dokłada bazowy na początek
}

/**
 * Wpis launchera zbudowany z MODULE_REGISTRY (top-level moduł aplikacji).
 * $primary — czy to moduł podstawowy konta (np. bazowy modul konta zawężonego).
 */
function module_entry(string $key, bool $primary = false): ?array {
    if (!isset(MODULE_REGISTRY[$key])) return null;
    $m = MODULE_REGISTRY[$key];
    return [
        'key'     => $key,
        'label'   => $m['label'],
        'desc'    => $primary ? 'Moduł podstawowy Twojego konta' : 'Dostęp przyznany dodatkowo',
        'icon'    => $m['icon'],
        'grad'    => $m['grad'],
        'url'     => APP_URL . $m['url'],
        'primary' => $primary,
    ];
}

/**
 * Wpis launchera dla panelu wolontariusza (traktowany jak osobny moduł).
 * Kieruje do panelu standalone, jeśli to wolontariusz bez umowy.
 */
function module_entry_volunteer_panel(): array {
    $u   = current_user();
    $url = APP_URL . '/panel/launcher.php';
    try {
        $sv = db_one("SELECT is_standalone_volunteer FROM users WHERE id=?", [(int)($u['id'] ?? 0)]);
        if (!empty($sv['is_standalone_volunteer'])) $url = APP_URL . '/panel/standalone.php';
    } catch (\Throwable $e) {}
    return [
        'key'     => 'panel',
        'label'   => 'Panel wolontariusza',
        'desc'    => 'Twoje umowy, zadania, dokumenty i dane',
        'icon'    => 'bi-person-heart',
        'grad'    => 'linear-gradient(135deg,#9D174D,#EC4899)',
        'url'     => $url,
        'primary' => true,
    ];
}

/** Liczba umów powiązanych z kontem (po e-mailu / Microsoft ID). */
function user_volunteer_contract_count(?array $u = null): int {
    $u = $u ?: current_user();
    if (!$u) return 0;
    $email = $u['email'] ?? '';
    $ms_id = $u['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return 0;
    $defs = [
        'zlecenie'    => ['m365_user_id', 'm365_login'],
        'wolontariat' => ['m365_user_id', 'm365_login', 'email', 'rodzic_email'],
        'dzielo'      => ['m365_user_id', 'm365_login'],
        'praca'       => ['email_login'],
    ];
    $n = 0;
    foreach ($defs as $type => $fields) {
        $conds = []; $params = [];
        foreach ($fields as $f) {
            if ($f === 'm365_user_id') { if (!$ms_id) continue; $conds[] = "$f = ?"; $params[] = $ms_id; }
            else                       { if (!$email) continue; $conds[] = "$f = ?"; $params[] = $email; }
        }
        if (!$conds) continue;
        try {
            $r = db_one("SELECT COUNT(*) AS c FROM umowy_{$type} WHERE " . implode(' OR ', $conds), $params);
            $n += (int)($r['c'] ?? 0);
        } catch (\Throwable $e) {}
    }
    return $n;
}

/**
 * Czy bieżący użytkownik ma „panel wolontariusza" jako dostępny moduł?
 * Dotyczy widzów (viewer), wolontariuszy bez umowy (standalone) oraz każdego,
 * kto ma powiązaną umowę (np. koordynator będący jednocześnie wolontariuszem).
 */
function user_has_volunteer_panel(): bool {
    $u = current_user();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'viewer') return true;
    try {
        $sv = db_one("SELECT is_standalone_volunteer FROM users WHERE id=?", [(int)$u['id']]);
        if (!empty($sv['is_standalone_volunteer'])) return true;
    } catch (\Throwable $e) {}
    return user_volunteer_contract_count($u) > 0;
}

/**
 * Punkty wejścia (top-level moduły) dostępne dla kont WOLONTARIUSZA i ZAWĘŻONYCH.
 * Panel wolontariusza liczy się jako osobny moduł. Zwraca [] dla kont zarządczych
 * (admin/editor/itp.) — te trafiają do pełnego portalu (który sam jest „wyborem").
 * Pierwszy element to moduł podstawowy (domyślny cel przy ≤2 modułach).
 */
function portal_entry_points(): array {
    $u = current_user();
    if (!$u) return [];
    $uid = (int)$u['id'];

    // Konta zawężone: bazowy moduł + indywidualnie przyznane. Panelu wolontariusza
    // NIE dodajemy — require_login tych kont blokuje ścieżkę /panel/.
    $base = scoped_base_module();
    if ($base !== null) {
        $eps = array_filter([module_entry($base, true)]);
        foreach (scoped_launcher_module_keys($uid) as $k) {
            if ($e = module_entry($k)) $eps[] = $e;
        }
        return array_values($eps);
    }

    // Widz / wolontariusz (rola viewer = panel wolontariusza i konta standalone):
    // panel wolontariusza (podstawowy) + indywidualnie przyznane moduły.
    // Uwaga: dla roli viewer NIE liczymy uprawnień roli (viewer ma odczyt wszędzie) —
    // tylko moduły przyznane konkretnemu użytkownikowi ponad rolę. Konta zarządcze
    // (admin/editor), które są też wolontariuszami, obsługuje pełny portal (kafel niżej).
    if (($u['role'] ?? '') === 'viewer') {
        $eps = [module_entry_volunteer_panel()];
        foreach (user_permissions($uid) as $mod => $row) {
            if (!empty($row['can_read']) && ($e = module_entry($mod))) $eps[] = $e;
        }
        return array_values($eps);
    }

    // Konta zarządcze — obsługiwane przez pełny portal.
    return [];
}

/**
 * Prefiksy ścieżek modułów przyznanych użytkownikowi indywidualnie — do
 * rozszerzenia allow-listy require_login dla kont zawężonych.
 */
function user_extra_module_paths(int $user_id): array {
    $paths = [];
    foreach (user_permissions($user_id) as $mod => $row) {
        if (!empty($row['can_read']) && isset(MODULE_REGISTRY[$mod])) {
            foreach (MODULE_REGISTRY[$mod]['paths'] as $p) $paths[] = $p;
        }
    }
    return array_values(array_unique($paths));
}

/**
 * Zapisz indywidualne uprawnienie użytkownika do modułu. Gdy wszystkie flagi
 * fałszywe — usuwa wpis. Pomija nieznane moduły.
 */
function user_permission_set(int $user_id, string $module, bool $r, bool $w, bool $d, int $by = 0): void {
    _permissions_init();
    if ($user_id <= 0 || !isset(PERMISSION_MODULES[$module])) return;
    try {
        if (!$r && !$w && !$d) {
            db()->prepare("DELETE FROM user_permissions WHERE user_id=? AND module=?")
                ->execute([$user_id, $module]);
            return;
        }
        db()->prepare(
            "INSERT OR REPLACE INTO user_permissions
                (user_id, module, can_read, can_write, can_delete, granted_by, created_at)
             VALUES (?,?,?,?,?,?,datetime('now'))"
        )->execute([$user_id, $module, $r ? 1 : 0, $w ? 1 : 0, $d ? 1 : 0, $by ?: null]);
    } catch (\Throwable $e) {}
}

// ── Permission check functions ────────────────────────────────────────────────
// Efektywny dostęp = uprawnienia roli ∪ uprawnienia indywidualne użytkownika.

function can_read(string $module): bool {
    _permissions_init();
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $perms = role_permissions($user['role']);
    if (!empty($perms[$module]['can_read'])) return true;
    $up = user_permissions((int)$user['id']);
    return !empty($up[$module]['can_read']);
}

function can_write(string $module): bool {
    _permissions_init();
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $perms = role_permissions($user['role']);
    if (!empty($perms[$module]['can_write'])) return true;
    $up = user_permissions((int)$user['id']);
    return !empty($up[$module]['can_write']);
}

function can_delete(string $module): bool {
    _permissions_init();
    $user = current_user();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $perms = role_permissions($user['role']);
    if (!empty($perms[$module]['can_delete'])) return true;
    $up = user_permissions((int)$user['id']);
    return !empty($up[$module]['can_delete']);
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
