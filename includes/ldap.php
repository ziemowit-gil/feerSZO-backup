<?php
/**
 * Klient LDAP — jednokierunkowy eksport kont SZO do katalogu OpenLDAP.
 *
 * Analogia do includes/m365.php: klasa transportowa współdzielona przez GUI
 * (admin/ldap_sync.php) oraz CLI (cron/sync_ldap.php) + helpery ustawień.
 *
 * Kierunek zawsze SZO -> LDAP. Nie modyfikujemy tabeli `users`, nie usuwamy
 * wpisów w katalogu (synchronizacja addytywna). LDAP nie bierze udziału w
 * logowaniu do SZO — dlatego NIE eksportujemy haseł.
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

class LdapDirectory
{
    private string $host;
    private int $port;
    private bool $useTls;
    private string $bindDn;
    private string $bindPw;
    private string $usersOu;

    /** @var resource|\LDAP\Connection|null */
    private $conn = null;

    public function __construct(array $cfg = [])
    {
        $this->host    = $cfg['host']     ?? (defined('LDAP_HOST') ? LDAP_HOST : '');
        $this->port    = (int) ($cfg['port'] ?? (defined('LDAP_PORT') ? LDAP_PORT : 389));
        $this->useTls  = (bool) ($cfg['use_tls'] ?? (defined('LDAP_USE_TLS') ? LDAP_USE_TLS : false));
        $this->bindDn  = $cfg['bind_dn']  ?? (defined('LDAP_BIND_DN') ? LDAP_BIND_DN : '');
        $this->bindPw  = $cfg['bind_pw']  ?? (defined('LDAP_BIND_PW') ? LDAP_BIND_PW : '');
        $this->usersOu = $cfg['users_ou'] ?? (defined('LDAP_USERS_OU') ? LDAP_USERS_OU : '');
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
     * Utwórz lub zaktualizuj wpis użytkownika w katalogu.
     *
     * @param  array  $u  wiersz z tabeli `users` (opc. wzbogacony przez ldap_collect_users)
     * @return string 'created' albo 'updated'
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

        $dn = 'uid=' . self::escapeRdn($uid) . ',' . $this->usersOu;
        $attrs = $this->buildAttributes($u, $uid);

        if ($this->entryExists($dn)) {
            // Nie ruszamy atrybutu nazewniczego (uid) przy modyfikacji.
            unset($attrs['uid'], $attrs['objectClass']);
            if (!@ldap_modify($this->conn, $dn, $attrs)) {
                throw new RuntimeException(ldap_error($this->conn));
            }

            return 'updated';
        }

        if (!@ldap_add($this->conn, $dn, $attrs)) {
            throw new RuntimeException(ldap_error($this->conn));
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

    /** Escapowanie wartości RDN (RFC 4514) dla bezpiecznego DN. */
    private static function escapeRdn(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_DN);
        }

        return addcslashes($value, "\\,+\"<>;=#");
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
 * Zbiór aktywnych kont do eksportu, wzbogaconych o dane organizacyjne.
 *
 * @return array<int, array<string, mixed>>
 */
function ldap_collect_users(): array
{
    $rows = db_all('SELECT * FROM users WHERE is_active = 1 ORDER BY id');

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
