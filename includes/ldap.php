<?php
/**
 * Klient LDAP — jednokierunkowy eksport kont SZO do katalogu OpenLDAP.
 *
 * Analogia do includes/m365.php: klasa transportowa współdzielona przez GUI
 * (admin/ldap_sync.php, tozsamosc/ldap.php) oraz CLI (cron/sync_ldap.php) +
 * helpery ustawień.
 *
 * Kierunek zawsze SZO -> LDAP. Nie modyfikujemy tabeli `users`, nie usuwamy
 * wpisów w katalogu. LDAP nie bierze udziału w logowaniu do SZO — dlatego
 * NIE eksportujemy haseł.
 *
 * Status konta JEST propagowany: `is_active=0` przenosi wpis z LDAP_USERS_OU
 * do LDAP_DISABLED_OU (nie kasuje go), więc appki bindujące po prostym
 * filtrze na users_ou przestają widzieć dezaktywowane konto. Reaktywacja
 * przenosi wpis z powrotem.
 *
 * Mapowanie atrybutów (inetOrgPerson):
 *   uid, employeeNumber <- users.id      (w SZO „UID = users.id")
 *   cn, displayName     <- users.name
 *   givenName           <- users.first_name
 *   sn                  <- users.last_name (fallback: ostatni człon `name`)
 *   mail                <- users.email
 *   telephoneNumber     <- users.phone_number / telefon z org_members
 *   title               <- stanowisko z org_members
 *   ou                  <- jednostka organizacyjna (org_units.name)
 */

require_once __DIR__ . '/m365.php';

class LdapDirectory
{
    private string $host;
    private int $port;
    private bool $useTls;
    private string $bindDn;
    private string $bindPw;
    private string $baseDn;
    private string $usersOu;
    private string $disabledOu;

    /** @var resource|\LDAP\Connection|null */
    private $conn = null;

    /**
     * Kolejność pierwszeństwa dla każdego parametru: $cfg jawnie przekazany >
     * ustawienie zapisane przez Konfigurator (tabela `settings`, edytowalne
     * bez dostępu do serwera) > stała LDAP_* z config.local.php > domyślna.
     * Dzięki temu admin może skonfigurować LDAP wyłącznie przez GUI
     * (tozsamosc/ldap.php?tab=konfigurator), bez SSH i edycji plików.
     */
    public function __construct(array $cfg = [])
    {
        $this->host       = $cfg['host']        ?? (ldap_setting('ldap_host')     ?: (defined('LDAP_HOST') ? LDAP_HOST : ''));
        $this->port       = (int) ($cfg['port'] ?? (ldap_setting('ldap_port')     ?: (defined('LDAP_PORT') ? LDAP_PORT : 389)));
        $this->useTls     = (bool) ($cfg['use_tls'] ?? (ldap_setting('ldap_use_tls') !== '' ? ldap_setting('ldap_use_tls') === '1' : (defined('LDAP_USE_TLS') ? LDAP_USE_TLS : false)));
        $this->bindDn     = $cfg['bind_dn']     ?? (ldap_setting('ldap_bind_dn')  ?: (defined('LDAP_BIND_DN') ? LDAP_BIND_DN : ''));
        $this->bindPw     = $cfg['bind_pw']     ?? (ldap_setting('ldap_bind_pw')  ?: (defined('LDAP_BIND_PW') ? LDAP_BIND_PW : ''));
        $this->baseDn     = $cfg['base_dn']     ?? (ldap_setting('ldap_base_dn')  ?: (defined('LDAP_BASE_DN') ? LDAP_BASE_DN : ''));
        $this->usersOu    = $cfg['users_ou']    ?? (ldap_setting('ldap_users_ou') ?: (defined('LDAP_USERS_OU') ? LDAP_USERS_OU : ''));
        $this->disabledOu = $cfg['disabled_ou'] ?? (ldap_setting('ldap_disabled_ou') ?: (defined('LDAP_DISABLED_OU') ? LDAP_DISABLED_OU : ''));
    }

    /** Czy integracja ma komplet parametrów, by w ogóle próbować połączenia. */
    public function is_configured(): bool
    {
        return function_exists('ldap_connect')
            && $this->host !== ''
            && $this->bindDn !== ''
            && $this->bindPw !== ''
            && $this->usersOu !== '';
    }

    /** Nawiąż połączenie i zwiąż kontem serwisowym. Rzuca RuntimeException. */
    public function connect(): void
    {
        if (!function_exists('ldap_connect')) {
            throw new RuntimeException('Rozszerzenie PHP „ldap" nie jest załadowane.');
        }
        if (!$this->is_configured()) {
            throw new RuntimeException('LDAP nie jest skonfigurowany (uzupełnij stałe LDAP_* w config.local.php).');
        }

        // Forma URI (dwuargumentowe ldap_connect(host, port) jest deprecjonowane od PHP 8.3).
        $uri  = sprintf('ldap://%s:%d', $this->host, $this->port);
        $conn = ldap_connect($uri);
        if ($conn === false) {
            throw new RuntimeException('Nie udało się zainicjować połączenia z LDAP.');
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

        if ($this->useTls && !@ldap_start_tls($conn)) {
            throw new RuntimeException('STARTTLS nieudane: ' . ldap_error($conn));
        }

        if (!@ldap_bind($conn, $this->bindDn, $this->bindPw)) {
            throw new RuntimeException('Bind do LDAP nieudany: ' . ldap_error($conn));
        }

        $this->conn = $conn;
    }

    /**
     * Utwórz, zaktualizuj lub przenieś (aktywacja/dezaktywacja) wpis użytkownika.
     *
     * @param  array  $u  wiersz z tabeli `users` (opc. wzbogacony przez ldap_collect_users)
     * @return string 'created' | 'updated' | 'deactivated' | 'reactivated' | 'skipped'
     * @throws RuntimeException przy błędzie operacji LDAP
     */
    public function upsert_user(array $u): string
    {
        if ($this->conn === null) {
            $this->connect();
        }

        $uid = (string) ($u['id'] ?? '');
        if ($uid === '') {
            throw new RuntimeException('Rekord bez identyfikatora (users.id).');
        }

        $isActive   = !empty($u['is_active']);
        $existingDn = $this->find_existing_dn($uid);

        // Konto nieaktywne, którego nigdy nie było w katalogu — nic do zrobienia.
        if ($existingDn === null && !$isActive) {
            return 'skipped';
        }

        $targetOu = $isActive ? $this->usersOu : $this->disabledOu();
        $targetDn = 'uid=' . self::escapeRdn($uid) . ',' . $targetOu;
        $attrs    = $this->buildAttributes($u, $uid);

        if ($existingDn === null) {
            if (!@ldap_add($this->conn, $targetDn, $attrs)) {
                throw new RuntimeException(ldap_error($this->conn));
            }

            return 'created';
        }

        $moved = strcasecmp($existingDn, $targetDn) !== 0;
        if ($moved) {
            $rdn = 'uid=' . self::escapeRdn($uid);
            if (!@ldap_rename($this->conn, $existingDn, $rdn, $targetOu, true)) {
                throw new RuntimeException(
                    ($isActive ? 'Reaktywacja' : 'Dezaktywacja') . ' (przeniesienie wpisu) nieudana: '
                    . ldap_error($this->conn)
                );
            }
        }

        // Nie ruszamy atrybutu nazewniczego (uid) przy modyfikacji.
        unset($attrs['uid'], $attrs['objectClass']);
        if (!@ldap_modify($this->conn, $targetDn, $attrs)) {
            throw new RuntimeException(ldap_error($this->conn));
        }

        if ($moved) {
            return $isActive ? 'reactivated' : 'deactivated';
        }

        return 'updated';
    }

    /**
     * Zapewnij istnienie gałęzi (OU) kont dezaktywowanych. Idempotentne,
     * analogiczne do ensure_users_ou().
     *
     * @return string 'exists' albo 'created'
     * @throws RuntimeException przy błędzie LDAP
     */
    public function ensure_disabled_ou(): string
    {
        if ($this->conn === null) {
            $this->connect();
        }

        $dn = $this->disabledOu();
        if ($this->entryExists($dn)) {
            return 'exists';
        }

        $rdn = explode(',', $dn)[0] ?? '';
        $val = trim(explode('=', $rdn, 2)[1] ?? '');
        if ($val === '') {
            throw new RuntimeException('Nieprawidłowe LDAP_DISABLED_OU: ' . $dn);
        }

        $entry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou'          => $val,
        ];

        if (!@ldap_add($this->conn, $dn, $entry)) {
            throw new RuntimeException('Nie udało się utworzyć OU „' . $dn . '": ' . ldap_error($this->conn));
        }

        return 'created';
    }

    /**
     * Zapewnij istnienie gałęzi (OU) użytkowników — używane przez instalator
     * (cli/ldap_install.php). Idempotentne.
     *
     * @return string 'exists' albo 'created'
     * @throws RuntimeException przy błędzie LDAP
     */
    public function ensure_users_ou(): string
    {
        if ($this->conn === null) {
            $this->connect();
        }

        if ($this->entryExists($this->usersOu)) {
            return 'exists';
        }

        // Wyłuskaj wartość RDN (np. „users" z „ou=users,dc=...").
        $rdn = explode(',', $this->usersOu)[0] ?? '';
        $val = trim(explode('=', $rdn, 2)[1] ?? '');
        if ($val === '') {
            throw new RuntimeException('Nieprawidłowe LDAP_USERS_OU: ' . $this->usersOu);
        }

        $entry = [
            'objectClass' => ['top', 'organizationalUnit'],
            'ou'          => $val,
        ];

        if (!@ldap_add($this->conn, $this->usersOu, $entry)) {
            throw new RuntimeException('Nie udało się utworzyć OU „' . $this->usersOu . '": ' . ldap_error($this->conn));
        }

        return 'created';
    }

    public function close(): void
    {
        if ($this->conn !== null) {
            @ldap_unbind($this->conn);
            $this->conn = null;
        }
    }

    // ── Wewnętrzne ─────────────────────────────────────────────────────────────

    /**
     * Zbuduj komplet atrybutów LDAP z wiersza użytkownika. Puste wartości
     * pomijamy — migrator jest addytywny i nie kasuje istniejących atrybutów.
     */
    private function buildAttributes(array $u, string $uid): array
    {
        $name  = trim((string) ($u['name'] ?? ''));
        $first = trim((string) ($u['first_name'] ?? ''));
        $last  = trim((string) ($u['last_name'] ?? ''));

        if ($last === '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            $last = count($parts) > 1 ? (string) end($parts) : ($parts[0] ?? '');
        }

        $cn = $name !== '' ? $name : trim("$first $last");
        if ($cn === '') {
            $cn = $uid;
        }
        if ($last === '') {
            $last = $cn; // sn jest wymagane przez inetOrgPerson
        }

        $attrs = [
            'objectClass'     => ['top', 'person', 'organizationalPerson', 'inetOrgPerson'],
            'uid'             => $uid,
            'employeeNumber'  => $uid,
            'cn'              => $cn,
            'sn'              => $last,
            'givenName'       => $first,
            'displayName'     => $name,
            'mail'            => trim((string) ($u['email'] ?? '')),
            'telephoneNumber' => trim((string) ($u['phone'] ?? $u['phone_number'] ?? '')),
            'title'           => trim((string) ($u['position_name'] ?? '')),
            'ou'              => trim((string) ($u['unit_name'] ?? '')),
        ];

        // Odrzuć puste (poza objectClass i wymaganymi cn/sn/uid).
        $required = ['objectClass' => true, 'uid' => true, 'cn' => true, 'sn' => true];
        foreach ($attrs as $key => $value) {
            if (isset($required[$key])) {
                continue;
            }
            if ($value === '' || $value === null) {
                unset($attrs[$key]);
            }
        }

        return $attrs;
    }

    private function entryExists(string $dn): bool
    {
        $search = @ldap_read($this->conn, $dn, '(objectClass=*)', ['dn']);

        return $search !== false && @ldap_count_entries($this->conn, $search) > 0;
    }

    /**
     * Odnajdź DN istniejącego wpisu po uid, niezależnie od tego, w której
     * gałęzi (users/disabled) aktualnie się znajduje — potrzebne, żeby
     * wykryć zmianę statusu aktywności między przebiegami synchronizacji.
     */
    private function find_existing_dn(string $uid): ?string
    {
        $filter = '(&(objectClass=inetOrgPerson)(uid=' . self::escapeFilter($uid) . '))';
        $search = @ldap_search($this->conn, $this->baseDn(), $filter, ['dn'], 0, 1);
        if ($search === false) {
            return null;
        }

        $entries = @ldap_get_entries($this->conn, $search);
        if (!$entries || ($entries['count'] ?? 0) === 0) {
            return null;
        }

        return $entries[0]['dn'];
    }

    private function baseDn(): string
    {
        if ($this->baseDn !== '') {
            return $this->baseDn;
        }
        // Wyprowadź z usersOu, gdyby LDAP_BASE_DN nie był ustawiony osobno.
        $comma = strpos($this->usersOu, ',');

        return $comma !== false ? substr($this->usersOu, $comma + 1) : $this->usersOu;
    }

    private function disabledOu(): string
    {
        return $this->disabledOu !== '' ? $this->disabledOu : ('ou=disabled,' . $this->baseDn());
    }

    /** Escapowanie wartości RDN (RFC 4514) dla bezpiecznego DN. */
    private static function escapeRdn(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_DN);
        }

        return addcslashes($value, "\\,+\"<>;=#");
    }

    /** Escapowanie wartości w filtrze wyszukiwania (RFC 4515). */
    private static function escapeFilter(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
        }

        return addcslashes($value, "\\*()\0");
    }
}

// ── Helpery ustawień (tabela settings, wzór z includes/m365.php) ───────────────

function ldap_setting(string $key): string
{
    static $cache = [];
    if (!isset($cache[$key])) {
        $row = db_one('SELECT value FROM settings WHERE key_ = ?', [$key]);
        $cache[$key] = $row['value'] ?? '';
    }

    return $cache[$key];
}

function ldap_save_setting(string $key, string $value): void
{
    if (DB_TYPE === 'sqlite') {
        db()->prepare('INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)')->execute([$key, $value]);
    } else {
        db()->prepare('INSERT INTO settings (key_, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=?')
            ->execute([$key, $value, $value]);
    }
}

/**
 * Wzbogać wiersz użytkownika (best-effort) o podstawową jednostkę organizacyjną,
 * stanowisko i telefon (join org_members/org_units). Brak tych tabel/kolumn nie
 * przerywa działania.
 */
function ldap_enrich_row(array &$u): void
{
    $member = null;
    try {
        $member = db_one(
            "SELECT om.position_name, om.phone_direct, om.phone_mobile, ou.name AS unit_name
             FROM org_members om
             LEFT JOIN org_units ou ON ou.id = om.unit_id
             WHERE om.user_id = ? AND om.status = 'active'
             ORDER BY om.is_primary DESC, om.id DESC
             LIMIT 1",
            [(int) ($u['id'] ?? 0)]
        );
    } catch (\Throwable $e) {
        // org_members/org_units mogą nie istnieć — pomijamy wzbogacenie.
    }

    $u['unit_name']     = $member['unit_name'] ?? '';
    $u['position_name'] = $member['position_name'] ?? '';
    $u['phone']         = ($u['phone_number'] ?? '') ?: ($member['phone_direct'] ?? $member['phone_mobile'] ?? '');
}

/**
 * Zbiór WSZYSTKICH kont (aktywnych i nieaktywnych) do synchronizacji,
 * wzbogaconych o dane organizacyjne. Konta nieaktywne muszą tu być —
 * to jedyny sposób, żeby upsert_user() wykrył dezaktywację i przeniósł
 * wpis do LDAP_DISABLED_OU.
 *
 * @return array<int, array<string, mixed>>
 */
function ldap_collect_users(): array
{
    $rows = db_all('SELECT * FROM users ORDER BY id');

    foreach ($rows as &$u) {
        ldap_enrich_row($u);
    }
    unset($u);

    return $rows;
}

/**
 * Pojedynczy, wzbogacony wiersz użytkownika — dla samoobsługi (moduł Tożsamość).
 */
function ldap_user_row(int $id): ?array
{
    $u = db_one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        return null;
    }
    ldap_enrich_row($u);

    return $u;
}

// ── Kolejka ponowień (LDAP i M365 Graph) ────────────────────────────────────
//
// Błąd pojedynczego użytkownika (LDAP chwilowo padł, Graph rzucił 429/5xx)
// nie przerywa reszty przebiegu — trafia tutaj i jest ponawiany z rosnącym
// odstępem (exponential backoff) przy kolejnych uruchomieniach synchronizacji,
// aż do limitu prób. Po przekroczeniu limitu wpis zostaje jako "dead letter"
// do ręcznego przeglądu (ldap_queue_dead_letters()).

const LDAP_QUEUE_MAX_ATTEMPTS = 8;
const LDAP_QUEUE_BASE_BACKOFF = 30; // sekund; 30, 60, 120, ... maks. 3600

function ldap_queue_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS ldap_sync_queue (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        target          TEXT     NOT NULL,
        user_id         INTEGER  NOT NULL,
        attempts        INTEGER  NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NOT NULL,
        last_error      TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

function ldap_queue_enqueue(string $target, int $user_id, string $error): void
{
    ldap_queue_migrate();
    db_insert('ldap_sync_queue', [
        'target'          => $target,
        'user_id'         => $user_id,
        'attempts'        => 0,
        'next_attempt_at' => date('Y-m-d H:i:s'),
        'last_error'      => $error,
    ]);
}

/** Operacje gotowe do ponowienia (czas backoffu minął, limit prób nie przekroczony). */
function ldap_queue_due(string $target): array
{
    ldap_queue_migrate();

    return db_all(
        'SELECT * FROM ldap_sync_queue WHERE target = ? AND attempts < ? AND next_attempt_at <= ? ORDER BY id',
        [$target, LDAP_QUEUE_MAX_ATTEMPTS, date('Y-m-d H:i:s')]
    );
}

function ldap_queue_mark_success(int $id): void
{
    db_exec('DELETE FROM ldap_sync_queue WHERE id = ?', [$id]);
}

function ldap_queue_mark_failure(array $op, string $error): void
{
    $attempts = (int) $op['attempts'] + 1;
    $backoff  = min(LDAP_QUEUE_BASE_BACKOFF * (2 ** $attempts), 3600);
    db_exec(
        'UPDATE ldap_sync_queue SET attempts = ?, next_attempt_at = ?, last_error = ? WHERE id = ?',
        [$attempts, date('Y-m-d H:i:s', time() + $backoff), $error, (int) $op['id']]
    );
}

/** Operacje, które wyczerpały limit prób — wymagają ręcznego przeglądu. */
function ldap_queue_dead_letters(string $target = ''): array
{
    ldap_queue_migrate();
    if ($target !== '') {
        return db_all(
            'SELECT * FROM ldap_sync_queue WHERE target = ? AND attempts >= ? ORDER BY id',
            [$target, LDAP_QUEUE_MAX_ATTEMPTS]
        );
    }

    return db_all('SELECT * FROM ldap_sync_queue WHERE attempts >= ? ORDER BY id', [LDAP_QUEUE_MAX_ATTEMPTS]);
}

// ── Powiązanie z M365 Graph ──────────────────────────────────────────────────
//
// WYŁĄCZNIE dla kont już powiązanych z Entra ID (users.microsoft_id ustawiony
// przez istniejący, kontraktowy przepływ m365_auto_link_or_create_local() —
// ten moduł NIGDY nie zakłada nowych kont M365 samodzielnie). Synchronizujemy
// tylko to, co już eksportujemy do LDAP: status konta i wyświetlaną nazwę.

/**
 * @throws RuntimeException gdy Graph zwróci błąd (>=400) — wołający kolejkuje retry.
 */
function ldap_sync_graph_profile(M365Graph $graph, array $u): void
{
    $userId = (string) ($u['microsoft_id'] ?? '');
    if ($userId === '') {
        return;
    }

    $graph->set_enabled($userId, !empty($u['is_active']));
    if ($graph->last_status() >= 400) {
        throw new RuntimeException('Graph set_enabled: ' . json_encode($graph->last_error()));
    }

    $name = trim((string) ($u['name'] ?? ''));
    if ($name !== '') {
        $graph->update_profile($userId, $name);
        if ($graph->last_status() >= 400) {
            throw new RuntimeException('Graph update_profile: ' . json_encode($graph->last_error()));
        }
    }
}

// ── Orkiestrator: jeden pełny przebieg (LDAP + Graph + drenaż kolejki) ──────
//
// Współdzielony przez cron/sync_ldap.php, admin/ldap_sync.php i tozsamosc/ldap.php,
// żeby logika (kolejkowanie błędów, powiązanie z Graph) istniała w jednym miejscu.

/**
 * @return array{
 *   summary: array{ldap: array<string,int>, graph: array<string,int>, retried: array<string,int>},
 *   items: array<int, array<string, mixed>>
 * }
 * @throws RuntimeException gdy LDAP nie jest skonfigurowany/nieosiągalny (cały przebieg przerwany)
 */
function ldap_run_sync(): array
{
    $ldap = new LdapDirectory();
    if (!$ldap->is_configured()) {
        throw new RuntimeException('LDAP nie jest skonfigurowany (uzupełnij stałe LDAP_* w config.local.php).');
    }
    $ldap->connect();

    try {
        $ldap->ensure_disabled_ou();
    } catch (\Throwable $e) {
        // Nie przerywamy całego przebiegu — brak OU ujawni się jako błąd
        // pojedynczej dezaktywacji i trafi do kolejki retry.
        error_log('[LDAP sync] ensure_disabled_ou: ' . $e->getMessage());
    }

    $graph       = new M365Graph();
    $graphReady  = $graph->is_configured();

    $summary = [
        'ldap'    => ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'reactivated' => 0, 'skipped' => 0, 'error' => 0],
        'graph'   => ['updated' => 0, 'skipped' => 0, 'error' => 0],
        'retried' => ['ldap' => 0, 'graph' => 0],
    ];
    $items = [];

    foreach (ldap_collect_users() as $user) {
        $uid  = (int) ($user['id'] ?? 0);
        $item = ['user_id' => $uid, 'name' => $user['name'] ?? '', 'email' => $user['email'] ?? ''];

        try {
            $item['ldap_action'] = $ldap->upsert_user($user);
            $summary['ldap'][$item['ldap_action']]++;
        } catch (\Throwable $e) {
            $item['ldap_action'] = 'error';
            $item['ldap_error']  = $e->getMessage();
            $summary['ldap']['error']++;
            error_log('[LDAP sync] uid=' . $uid . ': ' . $e->getMessage());
            ldap_queue_enqueue('ldap', $uid, $e->getMessage());
        }

        if ($graphReady && !empty($user['microsoft_id'])) {
            try {
                ldap_sync_graph_profile($graph, $user);
                $item['graph_action'] = 'updated';
                $summary['graph']['updated']++;
            } catch (\Throwable $e) {
                $item['graph_action'] = 'error';
                $item['graph_error']  = $e->getMessage();
                $summary['graph']['error']++;
                error_log('[Graph sync] uid=' . $uid . ': ' . $e->getMessage());
                ldap_queue_enqueue('graph', $uid, $e->getMessage());
            }
        } else {
            $item['graph_action'] = null; // konto nigdy nie powiązane z M365 — nic do zrobienia
            $summary['graph']['skipped']++;
        }

        $items[] = $item;
    }

    $ldap->close();
    ldap_save_setting('ldap_last_sync', date('Y-m-d H:i:s'));

    // Drenaż zakolejkowanych wcześniej błędów, którym minął czas backoffu.
    foreach (ldap_queue_due('ldap') as $op) {
        $u = db_one('SELECT * FROM users WHERE id = ?', [(int) $op['user_id']]);
        if (!$u) {
            ldap_queue_mark_success((int) $op['id']);
            continue;
        }
        try {
            $ldap2 = new LdapDirectory();
            $ldap2->connect();
            $ldap2->upsert_user($u);
            $ldap2->close();
            ldap_queue_mark_success((int) $op['id']);
            $summary['retried']['ldap']++;
        } catch (\Throwable $e) {
            ldap_queue_mark_failure($op, $e->getMessage());
        }
    }

    if ($graphReady) {
        foreach (ldap_queue_due('graph') as $op) {
            $u = db_one('SELECT * FROM users WHERE id = ?', [(int) $op['user_id']]);
            if (!$u || empty($u['microsoft_id'])) {
                ldap_queue_mark_success((int) $op['id']);
                continue;
            }
            try {
                ldap_sync_graph_profile($graph, $u);
                ldap_queue_mark_success((int) $op['id']);
                $summary['retried']['graph']++;
            } catch (\Throwable $e) {
                ldap_queue_mark_failure($op, $e->getMessage());
            }
        }
    }

    return ['summary' => $summary, 'items' => $items];
}

/**
 * Zapisuje parametry połączenia (z formularza Konfiguratora) do tabeli
 * `settings`, zapewnia istnienie obu gałęzi katalogu i od razu wykonuje
 * pełną synchronizację — samoobsługowa konfiguracja bez SSH / edycji
 * config.local.php. Hasło nadpisywane TYLKO gdy podano nową wartość
 * (puste pole = zachowaj już zapisane), wzorem admin/m365_settings.php.
 *
 * @param array $params host, port, base_dn, users_ou, disabled_ou, bind_dn, bind_pw, use_tls
 * @return array{install: array{users_ou: string, disabled_ou: string}, sync: array}
 * @throws RuntimeException gdy brak wymaganych pól albo bind/połączenie nieudane —
 *         ustawienia SĄ już zapisane w tym momencie, więc admin poprawia tylko błędne pole
 */
function ldap_configure_and_run(array $params): array
{
    ldap_save_setting('ldap_host', trim((string) ($params['host'] ?? '')));
    ldap_save_setting('ldap_port', (string) (int) ($params['port'] ?? 389));
    ldap_save_setting('ldap_base_dn', trim((string) ($params['base_dn'] ?? '')));
    ldap_save_setting('ldap_users_ou', trim((string) ($params['users_ou'] ?? '')));
    ldap_save_setting('ldap_disabled_ou', trim((string) ($params['disabled_ou'] ?? '')));
    ldap_save_setting('ldap_bind_dn', trim((string) ($params['bind_dn'] ?? '')));
    ldap_save_setting('ldap_use_tls', !empty($params['use_tls']) ? '1' : '0');

    $bindPw = trim((string) ($params['bind_pw'] ?? ''));
    if ($bindPw !== '') {
        ldap_save_setting('ldap_bind_pw', $bindPw);
    }

    $ldap = new LdapDirectory();
    if (!$ldap->is_configured()) {
        throw new RuntimeException('Uzupełnij wszystkie wymagane pola (Host, Bind DN, Hasło, Users OU).');
    }
    $ldap->connect();

    $install = [
        'users_ou'    => $ldap->ensure_users_ou(),
        'disabled_ou' => $ldap->ensure_disabled_ou(),
    ];
    $ldap->close();

    return ['install' => $install, 'sync' => ldap_run_sync()];
}
