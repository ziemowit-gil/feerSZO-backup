<?php
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/context.php';

function auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Osobna nazwa sesji na każdy kontekst — izolacja FEER vs. tenantów.
        $org  = defined('ORG_NAME') && ORG_NAME !== '' ? ORG_NAME : '';
        $safe = strtolower(preg_replace('/\s+/', '_', trim(preg_replace('/[^a-zA-Z0-9\s]/u', '', $org))));
        $safe = substr(trim($safe, '_'), 0, 40);
        if ($safe === '') {
            $safe = defined('TENANT_SLUG') && TENANT_SLUG !== '' ? TENANT_SLUG : 'feer';
        }
        session_name('umowy_' . $safe);

        // ── Sesje w bazie SQLite/MySQL — bez zależności od Redis ──────────────
        static $_db_handler_set = false;
        if (!$_db_handler_set) {
            $_db_handler_set = true;
            try {
                require_once __DIR__ . '/session_db_handler.php';
                session_set_save_handler(new DbSessionHandler(db()), true);
            } catch (\Throwable $e) {
                error_log('[auth_start] session handler fallback: ' . $e->getMessage());
                // Fallback: sesje plikowe
                ini_set('session.save_handler', 'files');
                ini_set('session.save_path', sys_get_temp_dir());
            }
        }

        // SameSite=Lax wymagane przy OAuth (cross-site top-level GET po redirect MS)
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function current_user(): ?array {
    auth_start();
    $u = $_SESSION['user'] ?? null;
    if (!$u) return null;
    // Nakładka kontekstu: admin „wcielony" w użytkownika / podgląd roli, albo
    // opiekun wchodzący na konto swojego dziecka. Prawdziwy użytkownik zawsze
    // pozostaje w $_SESSION['user']; uprawnienia egzekwuje ctx_overlay()
    // (zob. includes/context.php).
    if (!empty($_SESSION['ctx']) && function_exists('ctx_overlay')) {
        $ov = ctx_overlay($u);
        if ($ov) return $ov;
    }
    return $u;
}

function is_crm_only(): bool {
    $u = current_user();
    if (!$u) return false;
    if (($u['portal_scope'] ?? '') === 'crm_only') return true;
    if ($u['role'] === 'crm_user') return true;
    try {
        $r = db_one("SELECT crm_only FROM roles WHERE name=?", [$u['role']]);
        return !empty($r['crm_only']);
    } catch (\Throwable $e) { return false; }
}

function is_ezd_only(): bool {
    $u = current_user();
    if (!$u) return false;
    if (($u['portal_scope'] ?? '') === 'ezd_only') return true;
    if ($u['role'] === 'ezd_user') return true;
    try {
        $r = db_one("SELECT ezd_only FROM roles WHERE name=?", [$u['role']]);
        return !empty($r['ezd_only']);
    } catch (\Throwable $e) { return false; }
}

function require_login(): void {
    if (!current_user()) {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
        if ($base !== '' && $base !== '/' && str_starts_with($uri, $base . '/')) {
            $uri = substr($uri, strlen($base));
        }
        $full_uri = APP_URL . $uri;
        header('Location: ' . APP_URL . '/auth/login.php?redirect=' . urlencode($full_uri));
        exit;
    }

    // Wymuszenie aktywnej sesji w rejestrze — pozwala zdalnie wylogować użytkownika
    // (admin → admin/users.php, albo samodzielnie → panel/sessions.php). Gdy token
    // sesji został usunięty z user_sessions, kończymy sesję PHP i kierujemy do logowania.
    $__stk = $_SESSION['_session_token'] ?? '';
    if ($__stk !== '') {
        require_once __DIR__ . '/auth_security.php';
        if (!session_token_alive($__stk)) {
            logout_user();
            header('Location: ' . APP_URL . '/auth/login.php?ended=1');
            exit;
        }
        // Odśwież last_active, ale nie częściej niż co 2 minuty (oszczędność zapisów)
        $__now = time();
        if (($_SESSION['_session_touched'] ?? 0) < $__now - 120) {
            session_touch($__stk);
            $_SESSION['_session_touched'] = $__now;
        }
    }

    // Użytkownicy crm_user / crm_only — ograniczone ścieżki
    if (is_crm_only()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
        $rel = ($base && $base !== '/') ? substr($uri, strlen($base)) : $uri;
        $rel = strtolower(preg_replace('/\?.*/', '', $rel));

        // Podstawowe ścieżki zawsze dostępne
        $allowed_prefixes = [
            '/crm/', '/auth/', '/api/krs', '/api/ceidg',
            '/user/first_login_consent',
            '/contracts/ika_gate',  // wymagane dla CRM-only + IKA
        ];

        // Opcjonalne rozszerzenia — konfigurowane w Adminie → Dostęp CRM
        try {
            $extra = db_one("SELECT value FROM settings WHERE key_='crm_extra_modules'");
            $modules = $extra ? array_filter(explode(',', $extra['value'])) : [];
            foreach ($modules as $m) {
                $m = trim($m);
                if ($m === 'actions')   $allowed_prefixes[] = '/actions/';
                if ($m === 'grants')    $allowed_prefixes[] = '/grants/';
                if ($m === 'persons')   $allowed_prefixes[] = '/persons/';
                if ($m === 'reports')   $allowed_prefixes[] = '/reports/';
                if ($m === 'directory') $allowed_prefixes[] = '/directory/';
            }
        } catch (\Throwable $e) {}

        // Launcher portalu + moduły przyznane indywidualnie (ponad rolę)
        $allowed_prefixes[] = '/portal.php';
        if (function_exists('user_extra_module_paths')) {
            foreach (user_extra_module_paths((int)current_user()['id']) as $p) $allowed_prefixes[] = $p;
        }

        $is_allowed = false;
        foreach ($allowed_prefixes as $p) {
            if (str_starts_with($rel, $p)) { $is_allowed = true; break; }
        }
        if (!$is_allowed && $rel !== '/crm' && $rel !== '/crm/') {
            header('Location: ' . APP_URL . '/crm/dashboard.php');
            exit;
        }
    }

    // Użytkownicy ezd_user / ezd_only — dostęp wyłącznie do Kancelarii EZD
    if (is_ezd_only()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
        $rel = ($base && $base !== '/') ? substr($uri, strlen($base)) : $uri;
        $rel = strtolower(preg_replace('/\?.*/', '', $rel));

        $allowed_prefixes = [
            '/ezd/', '/auth/',
            '/user/first_login_consent',
            '/panel/password', '/panel/2fa', '/panel/profile_edit', '/panel/sessions',
        ];

        // Launcher portalu + moduły przyznane indywidualnie (ponad rolę)
        $allowed_prefixes[] = '/portal.php';
        if (function_exists('user_extra_module_paths')) {
            foreach (user_extra_module_paths((int)current_user()['id']) as $p) $allowed_prefixes[] = $p;
        }

        $is_allowed = false;
        foreach ($allowed_prefixes as $p) {
            if (str_starts_with($rel, $p)) { $is_allowed = true; break; }
        }
        if (!$is_allowed) {
            header('Location: ' . APP_URL . '/ezd/index.php');
            exit;
        }
    }
}

function require_role(string ...$roles): void {
    require_login();
    $user = current_user();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/header.php';
        echo '<div class="container mt-5"><div class="alert alert-danger">Brak uprawnień do tej strony.</div></div>';
        include __DIR__ . '/footer.php';
        exit;
    }
    if (in_array('admin', $roles, true)) {
        _admin_ip_guard();
    }
}

// ── Ograniczenie dostępu do admina wg adresu IP ──────────────────────────────

function _ip_in_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
        filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if ($bits === 0) return true;
        $mask = ~0 << (32 - $bits);
        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
        filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $ip_bin     = inet_pton($ip);
        $subnet_bin = inet_pton($subnet);
        for ($i = 0; $i < 16; $i++) {
            $byte_bits = max(0, min(8, $bits - $i * 8));
            $mask = $byte_bits === 0 ? 0x00 : ((0xFF << (8 - $byte_bits)) & 0xFF);
            if ((ord($ip_bin[$i]) & $mask) !== (ord($subnet_bin[$i]) & $mask)) return false;
        }
        return true;
    }
    return false;
}

function _admin_ip_guard(): void {
    $enabled      = false;
    $whitelist_raw = '';
    try {
        $rows = db_all("SELECT key_, value FROM settings WHERE key_ IN ('admin_ip_restrict','admin_ip_whitelist')");
        foreach ($rows as $r) {
            if ($r['key_'] === 'admin_ip_restrict')  $enabled       = $r['value'] === '1';
            if ($r['key_'] === 'admin_ip_whitelist')  $whitelist_raw = $r['value'];
        }
    } catch (\Throwable $e) { return; }

    if (!$enabled) return;

    // Konto serwisowe jest zawsze wykluczone z ograniczenia IP
    $user = current_user();
    if ($user && ($user['email'] ?? '') === 'serwis@local') return;

    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $entries   = array_filter(array_map('trim', explode("\n", $whitelist_raw)));

    foreach ($entries as $entry) {
        if ($entry === '' || str_starts_with($entry, '#')) continue;
        // Zamień wildcard 192.168.1.* → 192.168.1.0/24
        if (str_ends_with($entry, '.*')) {
            $base  = rtrim($entry, '.*');
            $parts = explode('.', $base);
            while (count($parts) < 4) $parts[] = '0';
            $entry = implode('.', $parts) . '/' . (count(explode('.', $base)) * 8);
        }
        if (_ip_in_cidr($client_ip, $entry)) return;
    }

    http_response_code(403);
    include __DIR__ . '/header.php';
    echo '<div class="container mt-5 mb-5">'
       . '<div class="alert alert-danger">'
       . '<strong><i class="bi bi-shield-lock me-2"></i>Dostęp zablokowany</strong><br>'
       . 'Twój adres IP (<code>' . htmlspecialchars($client_ip, ENT_QUOTES) . '</code>) nie należy do listy adresów dozwolonych dla panelu administratora.'
       . '</div></div>';
    include __DIR__ . '/footer.php';
    exit;
}

function can_edit(): bool {
    return can_write('umowy') || can_write('granty');
}

function is_admin(): bool {
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function is_viewer(): bool {
    $u = current_user();
    return $u && $u['role'] === 'viewer';
}

/**
 * Konta służbowe @feer.org.pl (koordynatorzy/administracja) logują się WYŁĄCZNIE
 * przez Microsoft 365 (Office). Wszystkie pozostałe metody (hasło lokalne, SMS,
 * kod jednorazowy, certyfikat X.509) są dla nich zablokowane. Wyjątek: konto
 * awaryjne serwis@local zachowuje logowanie lokalne (break-glass).
 */
function account_is_office_only(?string $email): bool {
    $email = strtolower(trim((string)$email));
    if ($email === '' || $email === 'serwis@local') return false;
    return str_ends_with($email, '@feer.org.pl');
}

// Zwraca true jeśli zalogowany użytkownik jest właścicielem umowy (lub ma uprawnienia edytora/admina).
// Używane w view.php do blokowania viewer-ów przed cudzymi umowami.
function viewer_owns_contract(string $type, array $row): bool {
    if (can_edit()) return true;
    $user  = current_user();
    if (!$user) return false;
    $email = $user['email'] ?? '';
    $ms_id = $user['microsoft_id'] ?? '';
    switch ($type) {
        case 'wolontariat':
            if ($ms_id && ($row['m365_user_id'] ?? '') === $ms_id) return true;
            if ($email && (($row['m365_login'] ?? '') === $email || ($row['email'] ?? '') === $email)) return true;
            if ($email && ($row['rodzic_email'] ?? '') === $email) return true; // rodzic/opiekun prawny
            break;
        case 'zlecenie':
        case 'dzielo':
            if ($ms_id && ($row['m365_user_id'] ?? '') === $ms_id) return true;
            if ($email && ($row['m365_login'] ?? '') === $email) return true;
            break;
        case 'praca':
            if ($email && ($row['email_login'] ?? '') === $email) return true;
            break;
    }
    return false;
}

/**
 * Generuje jednorazowy token aktywacyjny do ustawienia hasła.
 * Otwórz: APP_URL . '/auth/set_password.php?token=' . $token
 */
function auth_generate_setup_token(int $user_id): string {
    try { db()->exec("ALTER TABLE users ADD COLUMN activation_token TEXT NULL"); } catch (\Throwable $e) {}
    try { db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_activation_token ON users(activation_token) WHERE activation_token IS NOT NULL"); } catch (\Throwable $e) {}
    $token = bin2hex(random_bytes(32));
    db()->prepare("UPDATE users SET activation_token = ? WHERE id = ?")->execute([$token, $user_id]);
    return $token;
}

function login_user(array $user): void {
    auth_start();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'           => $user['id'],
        'name'         => $user['name'],
        'email'        => $user['email'],
        'role'         => $user['role'],
        'microsoft_id' => $user['microsoft_id'] ?? '',
        'portal_scope' => $user['portal_scope'] ?? null,
    ];
    // Zapisz aktywną sesję w DB
    try {
        require_once __DIR__ . '/auth_security.php';
        $token = session_create_token((int)$user['id']);
        $_SESSION['_session_token'] = $token;
    } catch (\Throwable $e) {}
}

function logout_user(): void {
    auth_start();
    // Usuń token sesji z DB
    try {
        require_once __DIR__ . '/auth_security.php';
        $token = $_SESSION['_session_token'] ?? '';
        if ($token) session_destroy_token($token);
        $uid = $_SESSION['user']['id'] ?? null;
        if ($uid) authlog_write((int)$uid, 'logout', $_SESSION['user']['email'] ?? '', 'Wylogowanie');
    } catch (\Throwable $e) {}

    // Wyczyść dane sesji
    $_SESSION = [];

    // Usuń cookie sesji z przeglądarki — session_destroy() tego NIE robi automatycznie
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 86400,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

// ── Microsoft OAuth helpers ──────────────────────────────────────────────────

function _ms_creds(): array {
    $tid = $cid = $secret = '';
    try {
        $rows = db_all(
            "SELECT key_, value FROM settings WHERE key_ IN ('m365_tenant_id','m365_graph_client_id','m365_graph_client_secret')"
        );
        foreach ($rows as $r) {
            if ($r['key_'] === 'm365_tenant_id')            $tid    = $r['value'];
            if ($r['key_'] === 'm365_graph_client_id')      $cid    = $r['value'];
            if ($r['key_'] === 'm365_graph_client_secret')  $secret = $r['value'];
        }
    } catch (\Exception $e) {}

    // Fallback do stałych z config.php (stara konfiguracja)
    if (!$tid)    $tid    = defined('MS_TENANT_ID')     ? MS_TENANT_ID     : '';
    if (!$cid)    $cid    = defined('MS_CLIENT_ID')     ? MS_CLIENT_ID     : '';
    if (!$secret) $secret = defined('MS_CLIENT_SECRET') ? MS_CLIENT_SECRET : '';

    return [$tid ?: 'common', $cid, $secret];
}

function ms_login_available(): bool {
    [$tid, $cid] = _ms_creds();
    return $cid !== '' && $tid !== 'common';
}

// ── Tabela oauth_states — backup dla sesji (odporna na utratę sesji) ──────────
function _ms_states_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS oauth_states (
            state       TEXT PRIMARY KEY,
            verifier    TEXT NOT NULL DEFAULT '',
            redirect_to TEXT NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL
        )");
        // Usuń stare (> 15 minut)
        db()->exec("DELETE FROM oauth_states WHERE created_at < " . (time() - 900));
    } catch (\Throwable $e) {}
}

function ms_auth_url(string $redirect_after = ''): string {
    auth_start();
    [$tenant_id, $client_id, ] = _ms_creds();
    _ms_states_migrate();

    $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $state     = bin2hex(random_bytes(16));

    // 1. Sesja (pierwotny mechanizm)
    $_SESSION['ms_login_state']     = $state;
    $_SESSION['ms_login_verifier']  = $verifier;
    $_SESSION['ms_login_client_id'] = $client_id;
    if ($redirect_after) $_SESSION['ms_login_redirect'] = $redirect_after;

    // 2. DB backup — odporna na utratę sesji przy przekierowaniu OAuth
    try {
        db()->prepare(
            "INSERT OR REPLACE INTO oauth_states (state, verifier, redirect_to, created_at) VALUES (?,?,?,?)"
        )->execute([$state, $verifier, $redirect_after, time()]);
    } catch (\Throwable $e) {}

    $params = http_build_query([
        'client_id'             => $client_id,
        'response_type'         => 'code',
        'redirect_uri'          => APP_URL . '/auth/microsoft.php',
        'scope'                 => 'openid email profile User.Read',
        'response_mode'         => 'query',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    return "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/authorize?" . $params;
}

function ms_exchange_code(string $code): ?array {
    auth_start();
    [$tenant_id, $client_id, $client_secret] = _ms_creds();
    // Verifier z sesji; fallback z DB oauth_states (przez state z callback)
    $verifier     = $_SESSION['ms_login_verifier'] ?? '';
    $redirect_uri = APP_URL . '/auth/microsoft.php';

    $data = [
        'client_id'    => $client_id,
        'code'         => $code,
        'redirect_uri' => $redirect_uri,
        'grant_type'   => 'authorization_code',
    ];
    // Azure AD confidential client wymaga client_secret ZAWSZE
    // (nawet gdy PKCE jest używany — oba parametry mogą współistnieć)
    if ($client_secret) {
        $data['client_secret'] = $client_secret;
    }
    // PKCE code_verifier — jeśli dostępny (dodatkowa warstwa bezpieczeństwa)
    if ($verifier) {
        $data['code_verifier'] = $verifier;
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => http_build_query($data),
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents(
        "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token",
        false, $ctx
    );
    return $response ? json_decode($response, true) : null;
}

function ms_get_user(string $access_token): ?array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Authorization: Bearer {$access_token}\r\n",
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents('https://graph.microsoft.com/v1.0/me', false, $ctx);
    return $response ? json_decode($response, true) : null;
}

// ── Migracja: kolumna login_code w tabeli users ──────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("ALTER TABLE users ADD COLUMN login_code TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
    try { db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_login_code ON users(login_code) WHERE login_code IS NOT NULL"); } catch (\Throwable $e) {}
})();

/**
 * Loguje użytkownika kodem dostępu nadanym przez admina.
 */
function auth_login_by_code(string $code): ?array {
    $code = trim($code);
    if ($code === '') return null;
    try {
        $user = db_one("SELECT * FROM users WHERE login_code=? AND is_active=1", [$code]);
        return $user ?: null;
    } catch (\Throwable $e) { return null; }
}

/**
 * Loguje użytkownika kodem dostępu + weryfikacją ostatnich 5 cyfr PESEL.
 * Kod identyfikuje użytkownika, PESEL potwierdza tożsamość.
 * Nie kasuje kodu — to robi wywołujący (logika jednorazowości w login.php).
 */
function auth_login_by_pesel_and_code(string $code, string $pesel5): ?array {
    $code   = trim($code);
    $pesel5 = preg_replace('/\D/', '', $pesel5);
    if ($code === '' || strlen($pesel5) !== 5) return null;
    // Znajdź użytkownika po kodzie
    try {
        $user = db_one("SELECT * FROM users WHERE login_code=? AND is_active=1", [$code]);
        if (!$user) return null;
    } catch (\Throwable $e) { return null; }
    // Zweryfikuj PESEL we wszystkich tabelach umów (po emailu użytkownika)
    $email = strtolower($user['email'] ?? '');
    $queries = [
        ["SELECT pesel FROM umowy_wolontariat WHERE LOWER(COALESCE(email,''))=?   AND pesel IS NOT NULL AND pesel!=''", [$email]],
        ["SELECT pesel FROM umowy_zlecenie    WHERE LOWER(COALESCE(email,''))=?   AND pesel IS NOT NULL AND pesel!=''", [$email]],
        ["SELECT pesel FROM umowy_dzielo      WHERE LOWER(COALESCE(email,''))=?   AND pesel IS NOT NULL AND pesel!=''", [$email]],
        ["SELECT pesel FROM umowy_praca       WHERE LOWER(COALESCE(NULLIF(email,''),NULLIF(email_login,''),''))=? AND pesel IS NOT NULL AND pesel!=''", [$email]],
    ];
    foreach ($queries as [$sql, $params]) {
        try {
            $rows = db_all($sql, $params);
            foreach ($rows as $row) {
                $p = preg_replace('/\D/', '', $row['pesel'] ?? '');
                if (strlen($p) === 11 && substr($p, -5) === $pesel5) return $user;
            }
        } catch (\Throwable $e) {}
    }
    return null;
}

function csrf_token(): string {
    auth_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    auth_start(); // upewnij się, że sesja jest uruchomiona
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(403);
        die('Błąd CSRF. Odśwież stronę i spróbuj ponownie.');
    }
    // Globalny status systemu — w trybie tylko do odczytu / przestoju blokuj zapis
    // (administrator może zapisywać zawsze; niezalogowani nie są blokowani — np. logowanie)
    if (function_exists('system_block_writes')) {
        system_block_writes();
    }
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}
