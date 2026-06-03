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
    if (in_array($status, ['zakończona','anulowana','rozwiązana','wygasła'], true)) return false;
    if ($start && $start > $today) return false;
    // Wyłącz konto w dniu wygaśnięcia umowy (włącznie) — nie dzień po
    if (!$bezterminowa && $end && $end <= $today) return false;
    return true;
}
