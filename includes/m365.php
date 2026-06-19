<?php
class M365Graph {
    private string $tenant_id;
    private string $client_id;
    private string $client_secret;
    private string $domain;
    private string $token = '';
    private int    $token_expires = 0;

    public function __construct(array $creds = []) {
        $this->tenant_id     = $creds['tenant_id']     ?? m365_setting('m365_tenant_id') ?: MS_TENANT_ID;
        $this->client_id     = $creds['client_id']     ?? m365_setting('m365_graph_client_id');
        $this->client_secret = $creds['client_secret'] ?? m365_setting('m365_graph_client_secret');
        $this->domain        = $creds['domain']        ?? m365_setting('m365_domain') ?: 'feer.org.pl';
        // Delegowany token (z OAuth login admina) — nadpisuje client credentials
        if (!empty($creds['access_token'])) {
            $this->token         = $creds['access_token'];
            $this->token_expires = time() + ($creds['expires_in'] ?? 3600);
        }
    }

    // Dekoduj JWT i zwróć claims (bez weryfikacji podpisu — tylko do odczytu danych)
    public static function decode_jwt(string $token): array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return [];
        $pad     = strlen($parts[1]) % 4;
        $payload = base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) + ($pad ? 4 - $pad : 0), '='));
        return json_decode($payload ?: '{}', true) ?? [];
    }

    public function is_configured(): bool {
        return !empty($this->client_id) && !empty($this->client_secret) && !empty($this->tenant_id);
    }

    // ── Token ────────────────────────────────────────────────────────────────

    private function token(): string {
        if ($this->token && time() < $this->token_expires - 60) return $this->token;
        $resp = $this->http_post(
            "https://login.microsoftonline.com/{$this->tenant_id}/oauth2/v2.0/token",
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'scope'         => 'https://graph.microsoft.com/.default',
            ],
            'form'
        );
        if (empty($resp['access_token'])) {
            throw new RuntimeException('Nie udało się pobrać tokenu Graph API: ' . json_encode($resp));
        }
        $this->token = $resp['access_token'];
        $this->token_expires = time() + ($resp['expires_in'] ?? 3600);
        return $this->token;
    }

    // ── Generowanie loginu ────────────────────────────────────────────────────

    public static function generate_login(string $imie_nazwisko, string $domain): string {
        $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z',
                'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ź'=>'Z','Ż'=>'Z'];
        $clean = strtr($imie_nazwisko, $map);
        $clean = preg_replace('/[^a-zA-Z\s\-]/', '', $clean);
        $parts = array_filter(explode(' ', trim($clean)));
        if (count($parts) < 2) return strtolower(reset($parts)) . '@' . $domain;
        $first = strtolower(reset($parts));
        $last  = strtolower(end($parts));
        return "{$first}.{$last}@{$domain}";
    }

    public function login_exists(string $login): bool {
        try {
            $r = $this->http_get("https://graph.microsoft.com/v1.0/users/" . urlencode($login));
            return isset($r['id']);
        } catch (\Exception $e) {
            return false;
        }
    }

    /** „Naturalny" login imie.nazwisko@domena dla domeny tej instancji (bez sufiksu liczbowego). */
    public function natural_login(string $imie_nazwisko): string {
        return self::generate_login($imie_nazwisko, $this->domain);
    }

    public function unique_login(string $imie_nazwisko): string {
        $base = self::generate_login($imie_nazwisko, $this->domain);
        if (!$this->login_exists($base)) return $base;
        [$local, $dom] = explode('@', $base, 2);
        for ($i = 1; $i <= 99; $i++) {
            $candidate = "{$local}{$i}@{$dom}";
            if (!$this->login_exists($candidate)) return $candidate;
        }
        return $base;
    }

    // ── Generowanie hasła ────────────────────────────────────────────────────

    public static function generate_password(int $len = 16): string {
        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower   = 'abcdefghjkmnpqrstuvwxyz';
        $digits  = '23456789';
        $special = '!@#$%^&*()-_=+';
        $all     = $upper . $lower . $digits . $special;
        // Gwarantuj po jednym z każdej grupy
        $pass  = $upper[random_int(0, strlen($upper)-1)];
        $pass .= $lower[random_int(0, strlen($lower)-1)];
        $pass .= $digits[random_int(0, strlen($digits)-1)];
        $pass .= $special[random_int(0, strlen($special)-1)];
        for ($i = 4; $i < $len; $i++) $pass .= $all[random_int(0, strlen($all)-1)];
        return str_shuffle($pass);
    }

    // ── Tworzenie użytkownika ────────────────────────────────────────────────

    public function create_user(string $login, string $display_name, string $password, bool $enabled = false): array {
        [$first, $rest] = array_pad(explode(' ', $display_name, 2), 2, '');
        if ($rest === '') $rest = $display_name;
        $body = [
            'accountEnabled'    => $enabled,
            'displayName'       => $display_name,
            'givenName'         => $first ?: $display_name,
            'surname'           => $rest,
            'mailNickname'      => explode('@', $login)[0],
            'userPrincipalName' => $login,
            'passwordProfile'   => [
                'forceChangePasswordNextSignIn' => true,
                'password' => $password,
            ],
            'usageLocation' => 'PL',
        ];
        $resp = $this->http_post('https://graph.microsoft.com/v1.0/users', $body);
        if (empty($resp['id'])) {
            throw new RuntimeException('Błąd tworzenia użytkownika: ' . json_encode($resp));
        }
        return $resp;
    }

    /** Tworzy użytkownika z opcjonalnym employeeId (dla K30). */
    public function create_user_with_employee_id(string $login, string $display_name, string $password, string $employee_id = ''): array {
        [$first, $rest] = array_pad(explode(' ', $display_name, 2), 2, '');
        // Graph API wymaga surname >= 1 znak — użyj display_name jeśli brak nazwiska
        if ($rest === '') $rest = $display_name;
        $body = [
            'accountEnabled'    => true,
            'displayName'       => $display_name,
            'givenName'         => $first ?: $display_name,
            'surname'           => $rest,
            'mailNickname'      => explode('@', $login)[0],
            'userPrincipalName' => $login,
            'passwordProfile'   => [
                'forceChangePasswordNextSignIn' => false,
                'password' => $password,
            ],
            'usageLocation' => 'PL',
        ];
        if ($employee_id !== '') $body['employeeId'] = $employee_id;
        $resp = $this->http_post('https://graph.microsoft.com/v1.0/users', $body);
        if (empty($resp['id'])) {
            throw new RuntimeException('Błąd tworzenia użytkownika: ' . json_encode($resp));
        }
        return $resp;
    }

    // ── Włącz / wyłącz konto ────────────────────────────────────────────────

    public function set_enabled(string $user_id, bool $enabled): void {
        $this->http_patch(
            "https://graph.microsoft.com/v1.0/users/{$user_id}",
            ['accountEnabled' => $enabled]
        );
    }

    // ── Reset hasła ──────────────────────────────────────────────────────────

    public function set_password(string $user_id, string $password): void {
        $this->http_patch(
            "https://graph.microsoft.com/v1.0/users/{$user_id}",
            ['passwordProfile' => ['forceChangePasswordNextSignIn' => true, 'password' => $password]]
        );
    }

    // ── Przypisz licencję ────────────────────────────────────────────────────

    public function assign_license(string $user_id, string $sku_id): void {
        $this->http_post(
            "https://graph.microsoft.com/v1.0/users/{$user_id}/assignLicense",
            ['addLicenses' => [['skuId' => $sku_id, 'disabledPlans' => []]], 'removeLicenses' => []]
        );
    }

    // ── Usuń użytkownika ─────────────────────────────────────────────────────

    public function delete_user(string $user_id): void {
        $ctx = stream_context_create(['http' => [
            'method'        => 'DELETE',
            'header'        => "Authorization: Bearer {$this->token()}\r\nContent-Length: 0\r\n",
            'ignore_errors' => true,
        ]]);
        @file_get_contents("https://graph.microsoft.com/v1.0/users/" . urlencode($user_id), false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) { $status = (int)$m[1]; break; }
        }
        if ($status && $status !== 204 && $status !== 404) {
            throw new RuntimeException("Nie udało się usunąć konta Azure AD (HTTP {$status}).");
        }
    }

    // ── Wyślij maila powitalnego ─────────────────────────────────────────────

    public function send_raw_email(string $sender_user_id, string $to, string $subject, string $html_body): void {
        $this->http_post(
            "https://graph.microsoft.com/v1.0/users/{$sender_user_id}/sendMail",
            [
                'message' => [
                    'subject' => $subject,
                    'body'    => ['contentType' => 'HTML', 'content' => $html_body],
                    'toRecipients' => [['emailAddress' => ['address' => $to]]],
                ],
                'saveToSentItems' => false,
            ]
        );
    }

    public function send_welcome_email(string $sender_user_id, string $to_email, string $display_name, string $login, string $password): void {
        $body = [
            'message' => [
                'subject' => 'Twoje konto Microsoft 365 — ' . m365_setting('m365_domain'),
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $this->welcome_html($display_name, $login, $password),
                ],
                'toRecipients' => [
                    ['emailAddress' => ['address' => $to_email, 'name' => $display_name]],
                ],
            ],
            'saveToSentItems' => false,
        ];
        $this->http_post(
            "https://graph.microsoft.com/v1.0/users/{$sender_user_id}/sendMail",
            $body
        );
    }

    private function welcome_html(string $name, string $login, string $password): string {
        $org = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
        return "
<p>Witaj {$name},</p>
<p>Zostało dla Ciebie utworzone konto Microsoft 365 w organizacji <strong>{$org}</strong>.</p>
<table style='border-collapse:collapse;font-family:monospace'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Login:</td><td><strong>{$login}</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Hasło tymczasowe:</td><td><strong>{$password}</strong></td></tr>
</table>
<p>Przy pierwszym logowaniu zostaniesz poproszony/a o zmianę hasła.</p>
<p>Zaloguj się na: <a href='https://portal.office.com'>https://portal.office.com</a></p>
<p style='color:#888;font-size:.9em'>Wiadomość wygenerowana automatycznie przez system Rejestru Umów.</p>
";
    }

    // ── Auto-konfiguracja ────────────────────────────────────────────────────

    public function test_connection(): array {
        try {
            $resp = $this->http_get("https://graph.microsoft.com/v1.0/organization?\$select=displayName,verifiedDomains");
            $org  = $resp['value'][0] ?? [];
            $domains = array_column($org['verifiedDomains'] ?? [], 'name');
            return ['ok' => true, 'org_name' => $org['displayName'] ?? '?', 'domains' => $domains];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function get_subscribed_skus(): array {
        try {
            $resp = $this->http_get("https://graph.microsoft.com/v1.0/subscribedSkus?\$select=skuId,skuPartNumber,prepaidUnits,consumedUnits,capabilityStatus");
            return array_filter($resp['value'] ?? [], fn($s) => $s['capabilityStatus'] === 'Enabled');
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Zwraca listę Security Groups z Microsoft 365.
     * Każdy element: ['id' => '...', 'displayName' => '...']
     */
    /**
     * Zwraca Security Groups z Azure AD / Entra ID.
     * Wymaga nagłówka ConsistencyLevel: eventual przy użyciu $filter.
     */
    public function get_security_groups(int $top = 200): array {
        try {
            $url = "https://graph.microsoft.com/v1.0/groups"
                 . "?\$filter=" . urlencode("securityEnabled eq true and mailEnabled eq false")
                 . "&\$select=id,displayName"
                 . "&\$count=true"
                 . "&\$top={$top}";
            $ctx = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$this->token()}\r\n"
                          . "Content-Type: application/json\r\n"
                          . "ConsistencyLevel: eventual\r\n",
                'ignore_errors' => true,
            ]]);
            $resp = json_decode(@file_get_contents($url, false, $ctx) ?: '{}', true) ?? [];
            $groups = $resp['value'] ?? [];
            usort($groups, fn($a, $b) => strcasecmp($a['displayName'] ?? '', $b['displayName'] ?? ''));
            return $groups;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function get_users(int $top = 100): array {
        try {
            $resp = $this->http_get("https://graph.microsoft.com/v1.0/users?\$select=id,displayName,userPrincipalName,mail&\$top={$top}&\$orderby=displayName");
            return $resp['value'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function get_user_by_id(string $id): array {
        try {
            return $this->http_get("https://graph.microsoft.com/v1.0/users/" . urlencode($id) . "?\$select=id,displayName,userPrincipalName,mail");
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Szuka użytkownika M365 po adresie e-mail lub UPN.
     * Próbuje najpierw bezpośredniego lookup (email jako UPN),
     * a potem filtru po polu mail (dla kont gdzie UPN ≠ mail).
     * Zwraca tablicę z kluczem 'id' jeśli znaleziono, inaczej [].
     */
    public function find_by_email_or_upn(string $email): array {
        // 1. Próba bezpośredniego UPN lookup
        try {
            $u = $this->http_get(
                "https://graph.microsoft.com/v1.0/users/" . urlencode($email)
                . "?\$select=id,displayName,userPrincipalName,mail"
            );
            if (!empty($u['id'])) return $u;
        } catch (\Exception $e) {}

        // 2. Filtr po polu mail (potrzebny nagłówek ConsistencyLevel)
        try {
            $url = "https://graph.microsoft.com/v1.0/users"
                 . "?\$filter=" . urlencode("mail eq '{$email}'")
                 . "&\$select=id,displayName,userPrincipalName,mail";
            $ctx = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$this->token()}\r\n"
                          . "Content-Type: application/json\r\n"
                          . "ConsistencyLevel: eventual\r\n",
                'ignore_errors' => true,
            ]]);
            $resp = json_decode(@file_get_contents($url, false, $ctx) ?: '{}', true) ?? [];
            if (!empty($resp['value'][0]['id'])) return $resp['value'][0];
        } catch (\Exception $e) {}

        return [];
    }

    // ── Aliasy e-mail (proxyAddresses) ────────────────────────────────────────

    /**
     * Czy adres jest już zajęty w tenancie — jako UPN, mail lub alias (proxyAddresses).
     * Używane przy walidacji wniosku o alias.
     */
    public function email_in_use(string $email): bool {
        $email = strtolower(trim($email));
        if ($email === '') return true;

        // 1. UPN / mail — reużyj istniejącej logiki
        if (!empty($this->find_by_email_or_upn($email)['id'])) return true;

        // 2. proxyAddresses (alias) — filtr wymaga ConsistencyLevel: eventual
        try {
            $url = "https://graph.microsoft.com/v1.0/users"
                 . "?\$filter=" . urlencode("proxyAddresses/any(x:x eq 'smtp:{$email}')")
                 . "&\$select=id&\$count=true&\$top=1";
            $ctx = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$this->token()}\r\n"
                          . "Content-Type: application/json\r\n"
                          . "ConsistencyLevel: eventual\r\n",
                'ignore_errors' => true,
            ]]);
            $resp = json_decode(@file_get_contents($url, false, $ctx) ?: '{}', true) ?? [];
            if (!empty($resp['value'][0]['id'])) return true;
        } catch (\Exception $e) {}

        return false;
    }

    /** Zwraca tablicę proxyAddresses użytkownika (np. ['SMTP:a@x','smtp:b@x']). */
    public function get_user_proxy_addresses(string $user_id): array {
        $r = $this->http_get(
            "https://graph.microsoft.com/v1.0/users/" . urlencode($user_id) . "?\$select=proxyAddresses"
        );
        return $r['proxyAddresses'] ?? [];
    }

    /**
     * Dopisuje alias (smtp: — drugorzędny) do proxyAddresses, zachowując istniejące.
     * UPN/primary SMTP pozostają bez zmian. Po PATCH weryfikuje obecność aliasu
     * (http_patch jest „fire-and-forget"). Zwraca true, jeśli alias jest ustawiony.
     */
    public function add_proxy_alias(string $user_id, string $alias): bool {
        $alias  = strtolower(trim($alias));
        $target = 'smtp:' . $alias;

        $current = $this->get_user_proxy_addresses($user_id);

        // Już istnieje (dowolna wielkość liter prefiksu)?
        foreach ($current as $pa) {
            if (strcasecmp($pa, $target) === 0 || strcasecmp($pa, 'SMTP:' . $alias) === 0) {
                return true;
            }
        }

        $updated   = $current;
        $updated[] = $target;

        $this->http_patch(
            "https://graph.microsoft.com/v1.0/users/" . urlencode($user_id),
            ['proxyAddresses' => array_values($updated)]
        );

        // Weryfikacja odczytem
        foreach ($this->get_user_proxy_addresses($user_id) as $pa) {
            if (strcasecmp($pa, $target) === 0) return true;
        }
        return false;
    }

    // ── HTTP helpers ─────────────────────────────────────────────────────────

    private function http_get(string $url): array {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$this->token()}\r\nContent-Type: application/json\r\n",
            'ignore_errors' => true,
        ]]);
        return json_decode(@file_get_contents($url, false, $ctx) ?: '{}', true) ?? [];
    }

    private function http_post(string $url, array $data, string $type = 'json'): array {
        if ($type === 'form') {
            $body    = http_build_query($data);
            $headers = "Content-Type: application/x-www-form-urlencoded\r\n";
        } else {
            $body    = json_encode($data);
            $headers = "Content-Type: application/json\r\nAuthorization: Bearer {$this->token()}\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => $headers,
            'content' => $body,
            'ignore_errors' => true,
        ]]);
        return json_decode(@file_get_contents($url, false, $ctx) ?: '{}', true) ?? [];
    }

    private function http_patch(string $url, array $data): void {
        $ctx = stream_context_create(['http' => [
            'method'  => 'PATCH',
            'header'  => "Content-Type: application/json\r\nAuthorization: Bearer {$this->token()}\r\n",
            'content' => json_encode($data),
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
    }

    /**
     * Dodaje użytkownika do grupy (Security Group lub Microsoft 365 Group).
     * Graph API: POST /groups/{group_id}/members/$ref
     *
     * @param string $user_id   Azure AD User ID (GUID)
     * @param string $group_id  Azure AD Group ID (GUID)
     * @throws \RuntimeException gdy odpowiedź API sygnalizuje błąd (poza 204 i 409 — 409 = już w grupie)
     */
    public function add_to_group(string $user_id, string $group_id): void {
        $url  = "https://graph.microsoft.com/v1.0/groups/{$group_id}/members/\$ref";
        $body = json_encode([
            '@odata.id' => "https://graph.microsoft.com/v1.0/directoryObjects/{$user_id}",
        ]);
        $ctx  = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$this->token()}\r\n",
            'content'       => $body,
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
        $code = (int)explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1];
        // 204 = OK, 409 = Conflict (already member) — both are acceptable
        if ($code !== 204 && $code !== 409 && $code !== 0) {
            throw new \RuntimeException("Błąd dodawania do grupy M365 (HTTP {$code}).");
        }
    }

    /**
     * Usuwa użytkownika z grupy.
     * Graph API: DELETE /groups/{group_id}/members/{user_id}/$ref
     */
    public function remove_from_group(string $user_id, string $group_id): void {
        $url = "https://graph.microsoft.com/v1.0/groups/{$group_id}/members/{$user_id}/\$ref";
        $ctx = stream_context_create(['http' => [
            'method'        => 'DELETE',
            'header'        => "Authorization: Bearer {$this->token()}\r\n",
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
    }

    // ── SharePoint ───────────────────────────────────────────────────────────

    /**
     * Zwraca listę witryn SharePoint w tenancie.
     * Wymaga Sites.Read.All (Application).
     * Używa wyszukiwania Graph (?search=*) — standardowy endpoint v1.0.
     *
     * @param  string $search  Fraza wyszukiwania (domyślnie '*' = wszystkie)
     * @param  int    $top     Max wyników
     * @return array  [{id, displayName, webUrl, name, description}, ...]
     */
    public function sp_list_sites(string $search = '*', int $top = 50): array {
        $url = 'https://graph.microsoft.com/v1.0/sites?' . http_build_query([
            'search'  => $search,
            '$top'    => $top,
            '$select' => 'id,displayName,webUrl,name,description,siteCollection',
        ]);
        $resp = $this->http_get($url);
        return $resp['value'] ?? [];
    }

    /**
     * Zwraca ID witryny SharePoint na podstawie jej pełnego URL.
     * Wymaga uprawnienia Sites.ReadWrite.All (Application).
     * Cache w static — jedno zapytanie na request nawet przy wielu uploadach.
     */
    public function sp_site_id(string $site_url): string {
        static $cache = [];
        if (isset($cache[$site_url])) return $cache[$site_url];
        $parts = parse_url(rtrim($site_url, '/'));
        $host  = $parts['host'] ?? '';
        $path  = ltrim($parts['path'] ?? '', '/');
        $r = $this->http_get("https://graph.microsoft.com/v1.0/sites/{$host}:/{$path}");
        if (empty($r['id'])) {
            throw new \RuntimeException("Nie znaleziono witryny SharePoint: {$site_url}. Błąd: " . json_encode($r));
        }
        return $cache[$site_url] = $r['id'];
    }

    /**
     * Zwraca ID biblioteki dokumentów (drive) w witrynie.
     * Jeśli $library_name puste — zwraca domyślny drive (defaultDrive).
     */
    public function sp_drive_id(string $site_id, string $library_name = ''): string {
        static $cache = [];
        $key = $site_id . '|' . $library_name;
        if (isset($cache[$key])) return $cache[$key];

        if ($library_name === '') {
            $r = $this->http_get("https://graph.microsoft.com/v1.0/sites/{$site_id}/drive");
            if (empty($r['id'])) {
                throw new \RuntimeException("Brak domyślnej biblioteki w witrynie: {$site_id}");
            }
            return $cache[$key] = $r['id'];
        }

        $resp = $this->http_get("https://graph.microsoft.com/v1.0/sites/{$site_id}/drives");
        foreach ($resp['value'] ?? [] as $d) {
            if (strcasecmp($d['name'] ?? '', $library_name) === 0) {
                return $cache[$key] = $d['id'];
            }
        }
        throw new \RuntimeException("Nie znaleziono biblioteki \"{$library_name}\" w witrynie {$site_id}.");
    }

    /**
     * Zwraca listę bibliotek dokumentów (drives) w witrynie — do podglądu w ustawieniach.
     */
    public function sp_list_drives(string $site_id): array {
        try {
            $resp = $this->http_get("https://graph.microsoft.com/v1.0/sites/{$site_id}/drives");
            return $resp['value'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Wgrywa plik na SharePoint.
     * Pliki ≤ 4 MB — prosty PUT. Większe — upload session (chunked).
     * $sp_path — ścieżka wewnątrz biblioteki łącznie z nazwą pliku,
     *            np. "Rejestr Umów/dzielo/20250601_abc.pdf"
     */
    public function sp_upload_file(string $site_id, string $drive_id, string $sp_path, string $local_path): array {
        if (!file_exists($local_path)) {
            throw new \RuntimeException("Plik lokalny nie istnieje: {$local_path}");
        }
        $size        = filesize($local_path);
        $encoded     = implode('/', array_map('rawurlencode', explode('/', $sp_path)));
        $item_url    = "https://graph.microsoft.com/v1.0/sites/{$site_id}/drives/{$drive_id}/root:/{$encoded}";

        if ($size <= 4 * 1024 * 1024) {
            $ext  = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
            $mime = $this->sp_mime($ext);
            $ctx  = stream_context_create(['http' => [
                'method'        => 'PUT',
                'header'        => "Authorization: Bearer {$this->token()}\r\nContent-Type: {$mime}\r\n",
                'content'       => file_get_contents($local_path),
                'ignore_errors' => true,
            ]]);
            return json_decode(@file_get_contents("{$item_url}:/content", false, $ctx) ?: '{}', true) ?? [];
        }

        return $this->sp_upload_large("{$item_url}:/createUploadSession", $local_path, $size);
    }

    private function sp_upload_large(string $session_url, string $local_path, int $size): array {
        $session = $this->http_post($session_url, ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]);
        $upload_url = $session['uploadUrl'] ?? '';
        if (!$upload_url) {
            throw new \RuntimeException('Nie udało się utworzyć sesji uploadu na SharePoint.');
        }
        $chunk  = 320 * 1024 * 10; // 3,2 MB — musi być wielokrotność 320 KB
        $fp     = fopen($local_path, 'rb');
        $offset = 0;
        $result = [];
        while (!feof($fp)) {
            $data = fread($fp, $chunk);
            $len  = strlen($data);
            $end  = $offset + $len - 1;
            $ctx  = stream_context_create(['http' => [
                'method'        => 'PUT',
                'header'        => "Content-Length: {$len}\r\nContent-Range: bytes {$offset}-{$end}/{$size}\r\n",
                'content'       => $data,
                'ignore_errors' => true,
            ]]);
            $resp   = json_decode(@file_get_contents($upload_url, false, $ctx) ?: '{}', true) ?? [];
            $offset += $len;
            if ($offset >= $size) $result = $resp;
        }
        fclose($fp);
        return $result;
    }

    private function sp_mime(string $ext): string {
        return match($ext) {
            'pdf'         => 'application/pdf',
            'docx'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            default       => 'application/octet-stream',
        };
    }

    // ══ OUTLOOK CONTACTS (delta sync) ════════════════════════════════════════

    /**
     * Pobiera kontakty użytkownika z Outlooka (pełna lista lub delta).
     * Wymaga uprawnienia aplikacji: Contacts.Read
     *
     * @param string      $user_id    Azure AD User ID (GUID) lub UPN
     * @param string|null $delta_link URL z poprzedniej synch (null = pełna lista)
     * @return array ['contacts' => [...], 'delta_link' => '...', 'next_link' => '...']
     */
    public function get_outlook_contacts(string $user_id, ?string $delta_link = null): array
    {
        $select = implode(',', [
            'id','displayName','givenName','surname',
            'emailAddresses','mobilePhone','businessPhones',
            'jobTitle','companyName',
            'homeAddress','businessAddress','otherAddress',
            'birthday','personalNotes','lastModifiedDateTime',
        ]);

        $url = $delta_link
            ?? "https://graph.microsoft.com/v1.0/users/{$user_id}/contacts/delta?\$select={$select}&\$top=100";

        $all_contacts = [];
        $next_link    = null;
        $final_delta  = null;

        while ($url) {
            $resp = $this->http_get($url);
            foreach ($resp['value'] ?? [] as $c) {
                $all_contacts[] = $c;
            }
            if (!empty($resp['@odata.nextLink'])) {
                $url = $resp['@odata.nextLink'];
            } elseif (!empty($resp['@odata.deltaLink'])) {
                $final_delta = $resp['@odata.deltaLink'];
                $url = null;
            } else {
                $url = null;
            }
        }

        return [
            'contacts'   => $all_contacts,
            'delta_link' => $final_delta,
        ];
    }

    /**
     * Delta-sync wiadomości e-mail z wybranego folderu skrzynki użytkownika.
     * Za pierwszym razem pobiera całą historię folderu, potem tylko zmiany.
     * Wymaga: Mail.Read (Application).
     *
     * @param string $user_id   Azure AD User ID lub UPN
     * @param string $folder     Well-known folder: 'inbox' (przychodzące) | 'sentitems' (wychodzące)
     * @param string|null $delta_link  Zapisany @odata.deltaLink z poprzedniej synchronizacji
     * @return array{messages: array, delta_link: ?string}
     */
    public function get_messages_delta(string $user_id, string $folder = 'inbox', ?string $delta_link = null): array
    {
        $select = implode(',', [
            'id','subject','bodyPreview',
            'from','toRecipients','ccRecipients',
            'receivedDateTime','sentDateTime',
            'conversationId','webLink',
        ]);

        $url = $delta_link
            ?? "https://graph.microsoft.com/v1.0/users/{$user_id}/mailFolders/{$folder}/messages/delta?\$select={$select}&\$top=50";

        $all_messages = [];
        $final_delta  = null;

        while ($url) {
            $resp = $this->http_get($url);
            foreach ($resp['value'] ?? [] as $m) {
                $all_messages[] = $m;
            }
            if (!empty($resp['@odata.nextLink'])) {
                $url = $resp['@odata.nextLink'];
            } elseif (!empty($resp['@odata.deltaLink'])) {
                $final_delta = $resp['@odata.deltaLink'];
                $url = null;
            } else {
                $url = null;
            }
        }

        return [
            'messages'   => $all_messages,
            'delta_link' => $final_delta,
        ];
    }

    // ══ OUTLOOK CALENDARS ════════════════════════════════════════════════════

    /**
     * Pobiera listę kalendarzy użytkownika.
     * Wymaga: Calendars.Read
     */
    public function get_calendars(string $user_id): array
    {
        $url  = "https://graph.microsoft.com/v1.0/users/{$user_id}/calendars?\$select=id,name,color,isDefaultCalendar&\$top=50";
        $resp = $this->http_get($url);
        return $resp['value'] ?? [];
    }

    /**
     * Pobiera eventy kalendarza użytkownika w danym zakresie dat.
     * Wymaga: Calendars.Read
     *
     * @param string $user_id     Azure AD User ID lub UPN
     * @param string $start       ISO8601 np. "2025-01-01T00:00:00"
     * @param string $end         ISO8601 np. "2026-12-31T23:59:59"
     * @param string $calendar_id Puste = domyślny kalendarz
     * @return array ['events' => [...], 'delta_link' => '...']
     */
    public function get_calendar_events(string $user_id, string $start, string $end, string $calendar_id = ''): array
    {
        $select = implode(',', [
            'id','subject','body','start','end','location',
            'isAllDay','isCancelled','sensitivity',
            'organizer','attendees','categories',
            'recurrence','seriesMasterId','type',
            'lastModifiedDateTime','createdDateTime',
        ]);

        $filter = urlencode("start/dateTime ge '{$start}' and end/dateTime le '{$end}'");

        $base = $calendar_id
            ? "https://graph.microsoft.com/v1.0/users/{$user_id}/calendars/{$calendar_id}/events"
            : "https://graph.microsoft.com/v1.0/users/{$user_id}/calendar/events";

        $url = "{$base}?\$select={$select}&\$filter={$filter}&\$top=100&\$orderby=start/dateTime";

        $all_events = [];
        while ($url) {
            $resp = $this->http_get($url);
            foreach ($resp['value'] ?? [] as $ev) {
                $all_events[] = $ev;
            }
            $url = $resp['@odata.nextLink'] ?? null;
        }

        return ['events' => $all_events];
    }

    /**
     * Nadchodzące spotkania online z kalendarza użytkownika (Teams) — z linkami „dołącz".
     * Zwraca listę [{subject,start,end,join_url}] tylko dla zdarzeń mających onlineMeeting/joinUrl.
     * Wymaga: Calendars.Read (Application).
     */
    public function get_online_calendar_events(string $user_id, string $start, string $end): array
    {
        $select = 'subject,start,end,isCancelled,isOnlineMeeting,onlineMeeting,onlineMeetingUrl,webLink';
        $filter = urlencode("start/dateTime ge '{$start}' and end/dateTime le '{$end}'");
        $url = "https://graph.microsoft.com/v1.0/users/" . urlencode($user_id)
             . "/calendar/events?\$select={$select}&\$filter={$filter}&\$top=50&\$orderby=start/dateTime";

        $out = [];
        while ($url) {
            $resp = $this->http_get($url);
            foreach ($resp['value'] ?? [] as $ev) {
                if (!empty($ev['isCancelled'])) continue;
                $join = $ev['onlineMeeting']['joinUrl'] ?? ($ev['onlineMeetingUrl'] ?? '');
                if (!$join) continue;
                $out[] = [
                    'subject'  => $ev['subject'] ?? 'Spotkanie',
                    'start'    => $ev['start']['dateTime'] ?? '',
                    'end'      => $ev['end']['dateTime'] ?? '',
                    'join_url' => $join,
                ];
            }
            $url = $resp['@odata.nextLink'] ?? null;
        }
        return $out;
    }

    /**
     * Delta-sync kalendarza (pełna lista lub tylko zmiany od ostatniej synch).
     * Wymaga: Calendars.Read
     */
    public function get_calendar_events_delta(string $user_id, string $calendar_id = '', ?string $delta_link = null): array
    {
        $select = implode(',', [
            'id','subject','body','start','end','location',
            'isAllDay','isCancelled','categories',
            'recurrence','seriesMasterId','type',
            'lastModifiedDateTime',
        ]);

        if ($delta_link) {
            $url = $delta_link;
        } else {
            $base = $calendar_id
                ? "https://graph.microsoft.com/v1.0/users/{$user_id}/calendarView/delta"
                : "https://graph.microsoft.com/v1.0/users/{$user_id}/calendarView/delta";
            // calendarView/delta wymaga startDateTime i endDateTime
            $start = date('Y-m-d\T00:00:00', strtotime('-90 days'));
            $end   = date('Y-m-d\T23:59:59', strtotime('+365 days'));
            $url   = "{$base}?startDateTime={$start}&endDateTime={$end}&\$select={$select}&\$top=100";
        }

        $all_events  = [];
        $final_delta = null;

        while ($url) {
            $resp = $this->http_get($url);
            foreach ($resp['value'] ?? [] as $ev) {
                $all_events[] = $ev;
            }
            if (!empty($resp['@odata.nextLink'])) {
                $url = $resp['@odata.nextLink'];
            } elseif (!empty($resp['@odata.deltaLink'])) {
                $final_delta = $resp['@odata.deltaLink'];
                $url = null;
            } else {
                $url = null;
            }
        }

        return [
            'events'     => $all_events,
            'delta_link' => $final_delta,
        ];
    }

    // ── Weryfikacja uprawnień Graph ──────────────────────────────────────────

    /**
     * Zwraca listę aktualnie nadanych uprawnień (Application i Delegated)
     * dla tej aplikacji w Azure AD.
     *
     * Wymaga co najmniej jednego z: Directory.ReadWrite.All, Application.Read.All
     *
     * @return array{
     *   application: string[],
     *   delegated: string[],
     *   sp_id: string,
     *   sp_display_name: string,
     *   error: string
     * }
     */
    public function get_granted_permissions(): array
    {
        $result = [
            'application'    => [],
            'delegated'      => [],
            'sp_id'          => '',
            'sp_display_name'=> '',
            'error'          => '',
        ];

        // 1. Znajdź service principal naszej aplikacji
        $sp_url  = 'https://graph.microsoft.com/v1.0/servicePrincipals'
                 . '?$filter=' . rawurlencode("appId eq '{$this->client_id}'")
                 . '&$select=id,displayName,appId';
        $sp_resp = $this->http_get($sp_url);

        if (!empty($sp_resp['error'])) {
            $result['error'] = $sp_resp['error']['message'] ?? json_encode($sp_resp['error']);
            return $result;
        }
        if (empty($sp_resp['value'][0]['id'])) {
            $result['error'] = 'Nie znaleziono service principal dla podanego Client ID. '
                             . 'Upewnij się, że aplikacja jest zarejestrowana w tym tenancie.';
            return $result;
        }

        $sp = $sp_resp['value'][0];
        $result['sp_id']           = $sp['id'];
        $result['sp_display_name'] = $sp['displayName'] ?? '';

        // 2. Pobierz listę ról z Microsoft Graph SP (potrzebna do mapowania GUID → nazwa)
        $graph_sp_url  = 'https://graph.microsoft.com/v1.0/servicePrincipals'
                       . '?$filter=' . rawurlencode("displayName eq 'Microsoft Graph'")
                       . '&$select=id,appRoles,oauth2PermissionScopes&$top=1';
        $graph_sp_resp = $this->http_get($graph_sp_url);
        $role_map = [];   // appRole GUID → value (np. "User.ReadWrite.All")
        $scope_map = [];  // oauth2 scope GUID → value (np. "offline_access")
        if (!empty($graph_sp_resp['value'][0])) {
            $gsp = $graph_sp_resp['value'][0];
            foreach ($gsp['appRoles'] ?? [] as $r) {
                $role_map[$r['id']] = $r['value'];
            }
            foreach ($gsp['oauth2PermissionScopes'] ?? [] as $s) {
                $scope_map[$s['id']] = $s['value'];
            }
        }

        // 3. appRoleAssignments = Application permissions z admin consent
        $ars_url  = "https://graph.microsoft.com/v1.0/servicePrincipals/{$result['sp_id']}/appRoleAssignments";
        $ars_resp = $this->http_get($ars_url);
        foreach ($ars_resp['value'] ?? [] as $a) {
            $name = $role_map[$a['appRoleId']] ?? $a['appRoleId'];
            $result['application'][] = $name;
        }

        // 4. oauth2PermissionGrants = Delegated permissions (scope strings space-separated)
        $og_url  = "https://graph.microsoft.com/v1.0/servicePrincipals/{$result['sp_id']}/oauth2PermissionGrants";
        $og_resp = $this->http_get($og_url);
        foreach ($og_resp['value'] ?? [] as $grant) {
            foreach (array_filter(explode(' ', $grant['scope'] ?? '')) as $scope) {
                if (!in_array($scope, $result['delegated'], true)) {
                    $result['delegated'][] = $scope;
                }
            }
        }

        return $result;
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function m365_setting(string $key): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $row = db_one("SELECT value FROM settings WHERE key_ = ?", [$key]);
        $cache[$key] = $row['value'] ?? '';
    }
    return $cache[$key];
}

function m365_save_setting(string $key, string $value): void {
    if (DB_TYPE === 'sqlite') {
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?, ?)")->execute([$key, $value]);
    } else {
        db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=?")->execute([$key, $value, $value]);
    }
}

function m365_should_be_active(array $row): bool {
    $today = date('Y-m-d');
    $start = $row['data_rozpoczecia'] ?? $row['data_zawarcia'] ?? null;
    $end   = $row['data_zakonczenia'] ?? $row['termin_oddania'] ?? null;
    $status = $row['status'] ?? '';
    $bezterminowa = !empty($row['bezterminowa']);
    // Flaga „nie wyłączaj dostępu po wygaśnięciu umowy" — konto pozostaje aktywne
    // mimo upływu daty zakończenia (np. wolontariusz kontynuujący współpracę)
    $nie_wylaczaj = !empty($row['m365_nie_wylaczaj']);
    if (!$nie_wylaczaj && in_array($status, ['zakończona','anulowana','rozwiązana','wygasła'], true)) return false;
    if ($start && $start > $today) return false;
    // Wyłącz konto w dniu wygaśnięcia umowy (włącznie) — nie dzień po
    if (!$bezterminowa && !$nie_wylaczaj && $end && $end <= $today) return false;
    return true;
}

// ── SharePoint helpers ────────────────────────────────────────────────────────

/**
 * Synchronizuje lokalny plik (ścieżka względna od UPLOAD_DIR) na SharePoint.
 * Wywoływana z handle_upload() — błędy są logowane, nigdy nie blokują uploadu.
 */
function sp_sync_upload(string $rel_path): void {
    if (m365_setting('sp_enabled') !== '1') return;

    $site_url = m365_setting('sp_site_url');
    if (!$site_url) return;

    $local_path = UPLOAD_DIR . $rel_path;
    if (!file_exists($local_path)) return;

    $graph = new M365Graph();
    if (!$graph->is_configured()) return;

    $library     = m365_setting('sp_library') ?: '';
    $base_folder = trim(m365_setting('sp_base_folder'), '/');
    $sp_path     = $base_folder !== '' ? $base_folder . '/' . $rel_path : $rel_path;

    $site_id  = $graph->sp_site_id($site_url);
    $drive_id = $graph->sp_drive_id($site_id, $library);
    $graph->sp_upload_file($site_id, $drive_id, $sp_path, $local_path);
}

/**
 * Tworzy kopię zapasową bazy SQLite (VACUUM INTO + gzip) i wysyła na SharePoint.
 * Wymaga: sp_enabled=1, sp_site_url, M365 credentials.
 * Folder na SP: sp_backup_folder / YYYY-MM / umowy_TIMESTAMP.db.gz
 */
function sp_backup_db(): array {
    if (m365_setting('sp_enabled') !== '1') {
        return ['ok' => false, 'error' => 'SharePoint nie jest włączony. Włącz synchronizację SharePoint w ustawieniach.'];
    }

    $site_url = m365_setting('sp_site_url');
    if (!$site_url) {
        return ['ok' => false, 'error' => 'Brak URL witryny SharePoint w ustawieniach.'];
    }

    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        return ['ok' => false, 'error' => 'Brak konfiguracji Microsoft 365 (Client ID / Secret).'];
    }

    $db_src = defined('DB_PATH') ? DB_PATH : (dirname(__DIR__) . '/umowy.db');
    if (!file_exists($db_src)) {
        return ['ok' => false, 'error' => "Baza danych nie istnieje: {$db_src}"];
    }

    $stamp  = date('Ymd_His');
    $tmp_db = sys_get_temp_dir() . '/feer_bak_' . $stamp . '.db';
    $tmp_gz = $tmp_db . '.gz';

    try {
        $pdo = new \PDO('sqlite:' . $db_src);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp_db));
        $pdo = null;

        $gz = gzopen($tmp_gz, 'wb9');
        $fh = fopen($tmp_db, 'rb');
        while (!feof($fh)) gzwrite($gz, fread($fh, 65536));
        fclose($fh);
        gzclose($gz);
        @unlink($tmp_db);

        $gz_size = filesize($tmp_gz);
        $size_h  = $gz_size > 1048576
            ? round($gz_size / 1048576, 1) . ' MB'
            : round($gz_size / 1024) . ' KB';

        $library    = m365_setting('sp_library') ?: '';
        $bak_folder = trim(m365_setting('sp_backup_folder') ?: 'Backup', '/');
        $sp_path    = $bak_folder . '/' . date('Y-m') . '/umowy_' . $stamp . '.db.gz';

        $site_id  = $graph->sp_site_id($site_url);
        $drive_id = $graph->sp_drive_id($site_id, $library);
        $resp     = $graph->sp_upload_file($site_id, $drive_id, $sp_path, $tmp_gz);
        @unlink($tmp_gz);

        return [
            'ok'      => true,
            'sp_path' => $sp_path,
            'web_url' => $resp['webUrl'] ?? '',
            'size_h'  => $size_h,
        ];
    } catch (\Throwable $e) {
        @unlink($tmp_db);
        @unlink($tmp_gz);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Automatycznie powiąż lub utwórz konto lokalne dla konta M365.
 * Szuka po microsoft_id, potem po e-mail; jeśli nie znajdzie — tworzy nowe.
 * Zwraca ['action' => 'exists'|'linked'|'created', 'user_id' => int, 'msg' => string]
 */
function m365_auto_link_or_create_local(string $email, string $name, string $microsoft_id): array {
    if ($microsoft_id) {
        $u = db_one("SELECT id FROM users WHERE microsoft_id=?", [$microsoft_id]);
        if ($u) return ['action' => 'exists', 'user_id' => (int)$u['id'], 'msg' => ''];
    }
    if ($email) {
        $u = db_one("SELECT id FROM users WHERE LOWER(email)=LOWER(?)", [$email]);
        if ($u) {
            db()->prepare("UPDATE users SET microsoft_id=?, is_active=1 WHERE id=?")
                ->execute([$microsoft_id ?: null, (int)$u['id']]);
            return ['action' => 'linked', 'user_id' => (int)$u['id'],
                    'msg' => 'Powiązano konto lokalne (' . $email . ') z M365.'];
        }
    }
    $uid = db_insert('users', [
        'name'         => $name ?: $email,
        'email'        => $email ?: null,
        'password'     => null,
        'microsoft_id' => $microsoft_id ?: null,
        'role'         => 'viewer',
        'is_active'    => 1,
        'created_at'   => date('Y-m-d H:i:s'),
    ]);
    return ['action' => 'created', 'user_id' => (int)$uid,
            'msg' => 'Utworzono konto lokalne dla ' . ($email ?: $name) . '.'];
}
