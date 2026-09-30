<?php
class M365Graph {
    private string $tenant_id;
    private string $client_id;
    private string $client_secret;
    private string $domain;
    private string $token = '';
    private int    $token_expires = 0;
    private int    $last_status = 0;
    private ?array $last_error  = null;

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

    /** Client ID (appId) rejestracji aplikacji — do komunikatów o błędach. */
    public function client_id(): string { return $this->client_id; }

    /** Tenant ID — do komunikatów o błędach. */
    public function tenant_id(): string { return $this->tenant_id; }

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
        throw new \RuntimeException("Nie można wygenerować unikalnego loginu dla \"{$imie_nazwisko}\" — wszystkie warianty ({$local}1–{$local}99) są zajęte.");
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

    /** Aktualizuje wyświetlaną nazwę konta (np. przy zmianie imienia/nazwiska w SZO). */
    public function update_profile(string $user_id, string $display_name): void {
        $this->http_patch(
            "https://graph.microsoft.com/v1.0/users/{$user_id}",
            ['displayName' => $display_name]
        );
    }

    // ── Reset hasła ──────────────────────────────────────────────────────────

    public function set_password(string $user_id, string $password, bool $force_change = true): void {
        $this->http_patch(
            "https://graph.microsoft.com/v1.0/users/{$user_id}",
            ['passwordProfile' => ['forceChangePasswordNextSignIn' => $force_change, 'password' => $password]]
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
        // Graph zwraca 202 bez body przy sukcesie; błąd to {'error':{...}}
        if ($this->last_error !== null) {
            throw new \RuntimeException(
                'Graph sendMail error: ' . json_encode($this->last_error, JSON_UNESCAPED_UNICODE)
            );
        }
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

        // Do poczty kierujemy na WŁASNY adres organizacji (strona wyboru
        // klienta), nie na outlook.office.com — jeden adres do zapamiętania,
        // własna domena (a nie „dziwny" adres, który wygląda jak phishing).
        // portal.office.com zostaje niżej, bo tam faktycznie zmienia się hasło
        // i instaluje aplikacje Microsoftu.
        require_once __DIR__ . '/webmail_clients.php';
        $mail_url   = webmail_chooser_url();
        $mail_label = webmail_chooser_label();

        return "
<p>Witaj {$name},</p>
<p>Zostało dla Ciebie utworzone konto Microsoft 365 w organizacji <strong>{$org}</strong>.</p>
<table style='border-collapse:collapse;font-family:monospace'>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Login:</td><td><strong>{$login}</strong></td></tr>
  <tr><td style='padding:4px 12px 4px 0;color:#555'>Hasło tymczasowe:</td><td><strong>{$password}</strong></td></tr>
</table>
<p>Przy pierwszym logowaniu zostaniesz poproszony/a o zmianę hasła.</p>
<p><strong>Poczta:</strong> <a href='{$mail_url}'>{$mail_label}</a> — to jeden adres do zapamiętania, sam kieruje dalej.</p>
<p style='font-size:.9em;color:#555'>Hasło i aplikacje Microsoft 365 (Word, Teams): <a href='https://portal.office.com'>portal.office.com</a></p>
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
            return $this->http_get("https://graph.microsoft.com/v1.0/users/" . urlencode($id) . "?\$select=id,displayName,userPrincipalName,mail,accountEnabled,assignedLicenses");
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

    /**
     * Wiadomości ze skrzynki odbiorczej (Inbox) danej skrzynki/użytkownika.
     * Wymaga uprawnienia aplikacji Mail.Read (client credentials).
     *
     * @param string $mailbox UPN/adres skrzynki (np. fundacja@feer.org.pl)
     * @param string $since   ISO 8601 UTC (np. 2026-06-01T00:00:00Z); '' = bez filtra
     * @param int    $top     maks. liczba wiadomości (rosnąco wg daty)
     * @return array lista wiadomości Graph (value[])
     */
    public function inbox_messages(string $mailbox, string $since = '', int $top = 50): array {
        $select = 'id,receivedDateTime,subject,bodyPreview,from,sender';
        $url = "https://graph.microsoft.com/v1.0/users/" . urlencode($mailbox)
             . "/mailFolders/inbox/messages?\$select={$select}"
             . "&\$top=" . max(1, min(200, (int)$top))
             . "&\$orderby=" . rawurlencode('receivedDateTime asc');
        if ($since !== '') {
            $url .= "&\$filter=" . rawurlencode("receivedDateTime ge {$since}");
        }
        $resp = $this->http_get($url);
        if (isset($resp['error'])) {
            $msg = $resp['error']['message'] ?? json_encode($resp['error']);
            throw new \RuntimeException("Graph API (inbox_messages): {$msg}");
        }
        return $resp['value'] ?? [];
    }

    /**
     * Nieprzeczytane wiadomości ze skrzynki odbiorczej (opcjonalnie od daty) — do automatycznego
     * przyjmowania załączników (np. kolejka EODoK). Wymaga Mail.Read (Application).
     *
     * @param string $since ISO 8601 UTC, '' = bez filtra daty
     */
    public function unread_inbox_messages(string $mailbox, string $since = '', int $top = 50): array {
        $filter = 'isRead eq false' . ($since !== '' ? " and receivedDateTime ge {$since}" : '');
        $url = "https://graph.microsoft.com/v1.0/users/" . urlencode($mailbox)
             . "/mailFolders/inbox/messages?\$select=" . rawurlencode('id,subject,from,receivedDateTime,hasAttachments')
             . "&\$top=" . max(1, min(100, $top))
             . "&\$filter=" . rawurlencode($filter);
        $resp = $this->http_get($url);
        if (isset($resp['error'])) {
            $msg = $resp['error']['message'] ?? json_encode($resp['error']);
            throw new \RuntimeException("Graph API (unread_inbox_messages): {$msg}");
        }
        return $resp['value'] ?? [];
    }

    /** Oznacza wiadomość jako przeczytaną (wymaga Mail.ReadWrite; brak uprawnienia = cichy brak efektu). */
    public function mark_message_read(string $mailbox, string $message_id): void {
        $this->http_patch("https://graph.microsoft.com/v1.0/users/" . urlencode($mailbox) . "/messages/" . urlencode($message_id), ['isRead' => true]);
    }

    private function http_get(string $url): array {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$this->token()}\r\nContent-Type: application/json\r\n",
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $this->capture_status($http_response_header ?? []);
        $decoded = json_decode($resp ?: '{}', true) ?? [];
        $this->capture_error($decoded);
        return $decoded;
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
        $resp = @file_get_contents($url, false, $ctx);
        $this->capture_status($http_response_header ?? []);
        $decoded = json_decode($resp ?: '{}', true) ?? [];
        $this->capture_error($decoded);
        return $decoded;
    }

    private function http_patch(string $url, array $data): void {
        $ctx = stream_context_create(['http' => [
            'method'  => 'PATCH',
            'header'  => "Content-Type: application/json\r\nAuthorization: Bearer {$this->token()}\r\n",
            'content' => json_encode($data),
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
        $this->capture_status($http_response_header ?? []);
    }

    /** Zapamiętuje status HTTP ostatniej odpowiedzi (do wykrycia 429/401/403 przez wołających). */
    private function http_delete(string $url): void {
        $ctx = stream_context_create(['http' => [
            'method' => 'DELETE',
            'header' => "Authorization: Bearer {$this->token()}\r\n",
            'ignore_errors' => true,
        ]]);
        @file_get_contents($url, false, $ctx);
        $this->capture_status($http_response_header ?? []);
    }

    private function capture_status(array $headers): void {
        $this->last_status = 0;
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $this->last_status = (int)$m[1]; break; }
        }
    }

    /** Status HTTP ostatniego wywołania Graph (0 = nieznany/brak wywołania). */
    public function last_status(): int {
        return $this->last_status;
    }

    /** Zapamiętuje treść błędu Graph API z ostatniej odpowiedzi (jeśli wystąpił). */
    private function capture_error(array $decoded): void {
        $this->last_error = $decoded['error'] ?? null;
    }

    /** Treść błędu Graph API ostatniego wywołania (null = brak błędu/nieznany). */
    public function last_error(): ?array {
        return $this->last_error;
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
     * Tworzy nową grupę zabezpieczeń (Security Group) w Azure AD / Entra ID.
     * Graph API: POST /v1.0/groups (securityEnabled=true, mailEnabled=false).
     *
     * @param  string $display_name   Nazwa wyświetlana grupy
     * @param  string $mail_nickname  Alias (bez spacji); pusty = wygenerowany z nazwy
     * @param  string $description     Opis (opcjonalnie)
     * @return array  Odpowiedź API z kluczem 'id' (GUID grupy)
     * @throws \RuntimeException gdy odpowiedź nie zawiera id
     */
    public function create_group(string $display_name, string $mail_nickname = '', string $description = ''): array {
        $nick = $mail_nickname !== '' ? $mail_nickname : preg_replace('/[^a-zA-Z0-9._-]/', '', $display_name);
        if ($nick === '') $nick = 'grp' . substr(bin2hex(random_bytes(4)), 0, 6);
        $body = [
            'displayName'     => $display_name,
            'mailEnabled'     => false,
            'mailNickname'    => $nick,
            'securityEnabled' => true,
        ];
        if ($description !== '') $body['description'] = $description;
        $resp = $this->http_post('https://graph.microsoft.com/v1.0/groups', $body);
        if (empty($resp['id'])) {
            throw new \RuntimeException('Błąd tworzenia grupy M365: ' . json_encode($resp));
        }
        return $resp;
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

    /** Pobiera zawartość pliku z SharePoint/OneDrive (surowe bajty). */
    public function sp_download_file(string $drive_id, string $item_id): string {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer {$this->token()}\r\n",
            'ignore_errors' => true,
        ]]);
        $data = @file_get_contents("https://graph.microsoft.com/v1.0/drives/{$drive_id}/items/{$item_id}/content", false, $ctx);
        if ($data === false || $data === '') {
            throw new \RuntimeException('Nie udało się pobrać pliku z SharePoint.');
        }
        return $data;
    }

    /** Pobiera plik Word/Excel z SharePoint przekonwertowany do PDF (Graph ?format=pdf). */
    public function sp_download_file_as_pdf(string $drive_id, string $item_id): string {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer {$this->token()}\r\n",
            'ignore_errors' => true,
        ]]);
        $data = @file_get_contents("https://graph.microsoft.com/v1.0/drives/{$drive_id}/items/{$item_id}/content?format=pdf", false, $ctx);
        if ($data === false || $data === '') {
            throw new \RuntimeException('Nie udało się pobrać wersji PDF z SharePoint.');
        }
        return $data;
    }

    /** Usuwa plik z SharePoint (best-effort — do porządkowania tymczasowych elementów, np. po konwersji „Spinacz"). */
    public function sp_delete_item(string $drive_id, string $item_id): void {
        $ctx = stream_context_create(['http' => [
            'method'        => 'DELETE',
            'header'        => "Authorization: Bearer {$this->token()}\r\n",
            'ignore_errors' => true,
        ]]);
        @file_get_contents("https://graph.microsoft.com/v1.0/drives/{$drive_id}/items/{$item_id}", false, $ctx);
        $this->capture_status($http_response_header ?? []);
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

    // ── SharePoint folder/file helpers (Workspaces module) ───────────────────

    /**
     * Tworzy folder na SharePoint lub zwraca dane istniejącego (idempotentne).
     *
     * @param  string $drive_id    Drive ID biblioteki SP
     * @param  string $parent_path Ścieżka rodzica od korzenia ('' = korzeń), bez / na obu końcach
     * @param  string $folder_name Nazwa nowego folderu
     * @return array  ['id'=>string, 'name'=>string, 'webUrl'=>string]
     * @throws \RuntimeException gdy SP zwróci nieoczekiwany błąd
     */
    public function sp_create_folder(string $drive_id, string $parent_path, string $folder_name): array {
        $folder_name = trim($folder_name);
        $parent_path = trim($parent_path, '/');

        $url = $parent_path === ''
            ? "https://graph.microsoft.com/v1.0/drives/{$drive_id}/root/children"
            : "https://graph.microsoft.com/v1.0/drives/{$drive_id}/root:/"
              . implode('/', array_map('rawurlencode', explode('/', $parent_path)))
              . ":/children";

        $resp = $this->http_post($url, [
            'name'                              => $folder_name,
            'folder'                            => (object)[],
            '@microsoft.graph.conflictBehavior' => 'fail',
        ]);

        if (!empty($resp['id'])) {
            return ['id' => $resp['id'], 'name' => $resp['name'] ?? $folder_name, 'webUrl' => $resp['webUrl'] ?? ''];
        }

        // 409 nameAlreadyExists — pobierz istniejący folder
        $code = $resp['error']['code'] ?? '';
        if ($this->last_status() === 409 || $code === 'nameAlreadyExists') {
            $full = $parent_path ? "{$parent_path}/{$folder_name}" : $folder_name;
            $enc  = implode('/', array_map('rawurlencode', explode('/', $full)));
            $item = $this->http_get("https://graph.microsoft.com/v1.0/drives/{$drive_id}/root:/{$enc}");
            if (!empty($item['id'])) {
                return ['id' => $item['id'], 'name' => $item['name'] ?? $folder_name, 'webUrl' => $item['webUrl'] ?? ''];
            }
        }

        throw new \RuntimeException(
            "Nie udało się utworzyć folderu \"{$folder_name}\". Błąd: "
            . json_encode($resp['error'] ?? $resp)
        );
    }

    /**
     * Wgrywa plik do folderu identyfikowanego przez item ID (bez znajomości pełnej ścieżki).
     * Małe pliki (≤4 MB) — prosty PUT; większe — upload session (chunked).
     *
     * @param  string $drive_id       Drive ID
     * @param  string $folder_item_id Item ID folderu (z sp_create_folder)
     * @param  string $filename       Docelowa nazwa pliku w SP
     * @param  string $local_path     Ścieżka lokalnego pliku
     * @return array  Metadane z Graph (['id', 'name', 'size', 'webUrl', ...])
     */
    public function sp_upload_to_folder(string $drive_id, string $folder_item_id, string $filename, string $local_path): array {
        if (!file_exists($local_path)) {
            throw new \RuntimeException("Plik lokalny nie istnieje: {$local_path}");
        }
        $size     = filesize($local_path);
        $enc_name = rawurlencode($filename);
        $base_url = "https://graph.microsoft.com/v1.0/drives/{$drive_id}/items/{$folder_item_id}:/{$enc_name}";

        if ($size <= 4 * 1024 * 1024) {
            $mime = $this->sp_mime(strtolower(pathinfo($filename, PATHINFO_EXTENSION)));
            $ctx  = stream_context_create(['http' => [
                'method'        => 'PUT',
                'header'        => "Authorization: Bearer {$this->token()}\r\nContent-Type: {$mime}\r\n",
                'content'       => file_get_contents($local_path),
                'ignore_errors' => true,
            ]]);
            $resp = json_decode(@file_get_contents("{$base_url}:/content", false, $ctx) ?: '{}', true) ?? [];
            $this->capture_status($http_response_header ?? []);
            return $resp;
        }

        return $this->sp_upload_large("{$base_url}:/createUploadSession", $local_path, $size);
    }

    /**
     * Listuje zawartość folderu (pliki i podfoldery).
     *
     * @param  string $drive_id Drive ID
     * @param  string $item_id  Item ID folderu
     * @return array  [{id, name, size, file, folder, webUrl, lastModifiedDateTime}, ...]
     */
    public function sp_list_folder(string $drive_id, string $item_id): array {
        $url  = "https://graph.microsoft.com/v1.0/drives/{$drive_id}/items/{$item_id}/children";
        $url .= '?' . http_build_query([
            '$select' => 'id,name,size,file,folder,webUrl,lastModifiedDateTime,createdDateTime',
            '$top'    => 200,
        ]);
        $resp = $this->http_get($url);
        return $resp['value'] ?? [];
    }

    /** Listuje zawartość folderu wskazanego ŚCIEŻKĄ (względem korzenia biblioteki). Pusta lista, gdy brak folderu. */
    public function sp_list_children_by_path(string $site_id, string $drive_id, string $path): array {
        $path = trim($path, '/');
        $enc  = $path === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $path)));
        $url  = $enc === ''
            ? "https://graph.microsoft.com/v1.0/sites/{$site_id}/drives/{$drive_id}/root/children"
            : "https://graph.microsoft.com/v1.0/sites/{$site_id}/drives/{$drive_id}/root:/{$enc}:/children";
        $url .= '?' . http_build_query([
            '$select' => 'id,name,size,file,folder,webUrl,lastModifiedDateTime',
            '$top'    => 200,
        ]);
        $resp = $this->http_get($url);
        return $resp['value'] ?? [];
    }

    // ══ ONEDRIVE UŻYTKOWNIKA ═════════════════════════════════════════════════
    // Uprawnienie aplikacji: Files.Read.All (odczyt OneDrive wskazanego usera).
    // Pobieranie treści pliku idzie przez sp_download_file() — /drives/{id}/items/{id}/content.

    /**
     * Zwraca OneDrive użytkownika (UPN lub GUID).
     *
     * @return array ['id'=>..., 'name'=>..., 'driveType'=>...] albo [] gdy brak dostępu
     */
    public function od_drive(string $user): array {
        $r = $this->http_get(
            "https://graph.microsoft.com/v1.0/users/" . urlencode($user) . "/drive?\$select=id,name,driveType,webUrl"
        );
        return !empty($r['id']) ? $r : [];
    }

    /**
     * Listuje zawartość OneDrive: korzeń (pusty $item_id) albo wskazany folder.
     *
     * @return array [{id, name, size, file, folder, webUrl, lastModifiedDateTime}, ...]
     */
    public function od_list(string $drive_id, string $item_id = '', int $top = 200): array {
        $base = $item_id === ''
            ? "https://graph.microsoft.com/v1.0/drives/{$drive_id}/root/children"
            : "https://graph.microsoft.com/v1.0/drives/{$drive_id}/items/" . urlencode($item_id) . "/children";
        $url  = $base . '?' . http_build_query([
            '$select'  => 'id,name,size,file,folder,webUrl,lastModifiedDateTime',
            '$orderby' => 'folder,name',
            '$top'     => $top,
        ]);
        $resp = $this->http_get($url);
        return $resp['value'] ?? [];
    }

    /** Wyszukuje pliki w OneDrive użytkownika (Graph search(q)). */
    public function od_search(string $drive_id, string $q, int $top = 50): array {
        $q   = str_replace("'", "''", $q);
        $url = "https://graph.microsoft.com/v1.0/drives/{$drive_id}/root/search(q='" . rawurlencode($q) . "')"
             . '?' . http_build_query(['$select' => 'id,name,size,file,folder,webUrl,lastModifiedDateTime', '$top' => $top]);
        $resp = $this->http_get($url);
        return $resp['value'] ?? [];
    }

    /** Metadane pojedynczego elementu drive'a (do walidacji przed pobraniem). */
    public function od_item(string $drive_id, string $item_id): array {
        $r = $this->http_get(
            "https://graph.microsoft.com/v1.0/drives/{$drive_id}/items/" . urlencode($item_id)
            . "?\$select=id,name,size,file,folder,webUrl"
        );
        return !empty($r['id']) ? $r : [];
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
     * Tworzy kontakt w książce adresowej Outlooka (folder domyślny lub wskazany).
     * Wymaga: Contacts.ReadWrite (Application).
     *
     * @param string $user_id   Azure AD User ID lub UPN właściciela książki
     * @param array  $contact   Ładunek zgodny z zasobem Graph `contact`
     * @param string $folder_id Opcjonalny folder kontaktów
     * @return array Utworzony kontakt (z 'id') albo tablica z 'error'
     */
    public function create_outlook_contact(string $user_id, array $contact, string $folder_id = ''): array
    {
        $url = $folder_id !== ''
            ? "https://graph.microsoft.com/v1.0/users/{$user_id}/contactFolders/{$folder_id}/contacts"
            : "https://graph.microsoft.com/v1.0/users/{$user_id}/contacts";
        return $this->http_post($url, $contact);
    }

    /** Aktualizuje kontakt w książce adresowej Outlooka. Wymaga: Contacts.ReadWrite. */
    public function update_outlook_contact(string $user_id, string $contact_id, array $contact): void
    {
        $this->http_patch(
            "https://graph.microsoft.com/v1.0/users/{$user_id}/contacts/" . rawurlencode($contact_id),
            $contact
        );
    }

    /** Pobiera jeden kontakt z książki adresowej (do weryfikacji, czy jeszcze istnieje). */
    public function get_outlook_contact(string $user_id, string $contact_id): array
    {
        return $this->http_get(
            "https://graph.microsoft.com/v1.0/users/{$user_id}/contacts/" . rawurlencode($contact_id)
        );
    }

    /** Usuwa kontakt z książki adresowej Outlooka. Wymaga: Contacts.ReadWrite. */
    public function delete_outlook_contact(string $user_id, string $contact_id): void
    {
        $this->http_delete(
            "https://graph.microsoft.com/v1.0/users/{$user_id}/contacts/" . rawurlencode($contact_id)
        );
    }

    /** Lista folderów kontaktów skrzynki (do wyboru, gdzie zapisywać kontakty CRM). */
    public function get_contact_folders(string $user_id): array
    {
        $r = $this->http_get("https://graph.microsoft.com/v1.0/users/{$user_id}/contactFolders?\$top=50");
        return $r['value'] ?? [];
    }

    /**
     * Szuka w skrzynce korespondencji z danym adresem (w obie strony).
     * Graph nie pozwala filtrować po odbiorcach, więc używamy `$search`
     * z operatorem `participants:` — obejmuje From, To, Cc i Bcc.
     * `$search` wyklucza `$filter`/`$orderby`, więc zakres dat odcinamy po stronie PHP.
     * Wymaga: Mail.Read (Application).
     *
     * @return array lista zasobów `message`
     */
    public function search_messages_participant(string $mailbox, string $email, int $top = 50): array
    {
        $email = trim($email);
        if ($email === '') return [];
        $select = implode(',', [
            'id','subject','bodyPreview','from','toRecipients','ccRecipients',
            'receivedDateTime','sentDateTime','conversationId','webLink','hasAttachments',
        ]);
        $top = max(1, min(200, $top));
        $url = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($mailbox) . "/messages"
             . "?\$search=" . rawurlencode('"participants:' . $email . '"')
             . "&\$select={$select}&\$top={$top}";
        $r = $this->http_get($url);
        return $r['value'] ?? [];
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
            'id','subject','bodyPreview','body','hasAttachments','internetMessageId',
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

    /**
     * Metadane załączników wiadomości (bez zawartości — lekkie, bezpieczne do zawsze-wołania).
     * Wymaga: Mail.Read (Application).
     *
     * @return array lista [{id, name, contentType, size}, ...]
     */
    public function get_message_attachments(string $mailbox, string $message_id): array {
        $url = "https://graph.microsoft.com/v1.0/users/" . urlencode($mailbox)
             . "/messages/" . urlencode($message_id) . "/attachments"
             . "?\$select=id,name,contentType,size";
        $resp = $this->http_get($url);
        if (isset($resp['error'])) {
            $msg = $resp['error']['message'] ?? json_encode($resp['error']);
            throw new \RuntimeException("Graph API (get_message_attachments): {$msg}");
        }
        return $resp['value'] ?? [];
    }

    /**
     * Zawartość jednego załącznika (base64 → surowe bajty). Wołać tylko dla małych
     * plików (fileAttachment, do ok. 3 MB) — większe wymagają osobnej sesji uploadu.
     */
    public function get_attachment_content(string $mailbox, string $message_id, string $attachment_id): string {
        $url = "https://graph.microsoft.com/v1.0/users/" . urlencode($mailbox)
             . "/messages/" . urlencode($message_id) . "/attachments/" . urlencode($attachment_id)
             . "?\$select=contentBytes";
        $resp = $this->http_get($url);
        if (isset($resp['error']) || empty($resp['contentBytes'])) {
            $msg = $resp['error']['message'] ?? 'brak contentBytes w odpowiedzi';
            throw new \RuntimeException("Graph API (get_attachment_content): {$msg}");
        }
        return base64_decode($resp['contentBytes']);
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
    // Okres ochronny po akceptacji wniosku o rozwiązanie umowy
    // (includes/termination.php::decide_termination) — konto zostaje aktywne
    // jeszcze 4h od decyzji, dopóki nie minie m365_deactivate_after.
    $grace_until = $row['m365_deactivate_after'] ?? null;
    $in_grace    = $grace_until && strtotime($grace_until) > time();
    if (!$nie_wylaczaj && !$in_grace && in_array($status, ['zakończona','anulowana','rozwiązana','wygasła'], true)) return false;
    if ($start && $start > $today) return false;
    // Wyłącz konto w dniu wygaśnięcia umowy (włącznie) — nie dzień po
    if (!$bezterminowa && !$nie_wylaczaj && !$in_grace && $end && $end <= $today) return false;
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

/** Wspólny preflight dla automatycznych backupów SP — zwraca [graph, site_id, drive_id] albo rzuca wyjątek z komunikatem. */
function sp_backup_preflight(): array {
    if (m365_setting('sp_enabled') !== '1') {
        throw new \RuntimeException('SharePoint nie jest włączony.');
    }
    $site_url = m365_setting('sp_site_url');
    if (!$site_url) {
        throw new \RuntimeException('Brak URL witryny SharePoint w ustawieniach.');
    }
    $graph = new M365Graph();
    if (!$graph->is_configured()) {
        throw new \RuntimeException('Brak konfiguracji Microsoft 365 (Client ID / Secret).');
    }
    $library  = m365_setting('sp_library') ?: '';
    $site_id  = $graph->sp_site_id($site_url);
    $drive_id = $graph->sp_drive_id($site_id, $library);
    return [$graph, $site_id, $drive_id];
}

/** Gzipuje plik lokalny (poziom 9) i zwraca ścieżkę tymczasową do wynikowego .gz. */
function sp_gzip_temp(string $src_path, string $tmp_prefix): string {
    $tmp_gz = sys_get_temp_dir() . '/' . $tmp_prefix . '.gz';
    $gz = gzopen($tmp_gz, 'wb9');
    $fh = fopen($src_path, 'rb');
    while (!feof($fh)) gzwrite($gz, fread($fh, 65536));
    fclose($fh);
    gzclose($gz);
    return $tmp_gz;
}

/**
 * Przyrostowy backup na SharePoint (baza + zmienione pliki uploads).
 * Wysyła tylko to, co zmieniło się od ostatniej synchronizacji SP — znaczniki
 * BASE_DIR/backups/.last_sp_db_mtime i .last_sp_uploads_ts, NIEZALEŻNE od
 * znaczników lokalnej rotacji przyrostowej (cron/agents/backup.php).
 * Folder na SP: sp_backup_folder / incremental / YYYY-MM / ...
 */
/**
 * Przygotowuje plik tymczasowy do wysyłki na SP: szyfruje go (AES-256), jeśli
 * szyfrowanie kopii jest włączone (spójnie z backupem lokalnym, includes/backup.php).
 * @return array{0:string,1:string} [ścieżka_do_wysłania, sufiks_nazwy_na_SP ('' lub '.enc')]
 */
function _sp_backup_prepare(string $tmpPath): array {
    if (!function_exists('backup_encryption_enabled') && is_file(__DIR__ . '/backup.php')) {
        require_once __DIR__ . '/backup.php';
    }
    if (function_exists('backup_encryption_enabled') && backup_encryption_enabled()) {
        $enc = backup_encrypt_file($tmpPath, null, true); // usuwa plaintext po zaszyfrowaniu
        if ($enc) return [$enc, '.enc'];
    }
    return [$tmpPath, ''];
}

function sp_backup_incremental(): array {
    try {
        [$graph, $site_id, $drive_id] = sp_backup_preflight();
    } catch (\Throwable $e) {
        return ['ok' => false, 'skipped' => true, 'error' => $e->getMessage()];
    }

    $base        = dirname(__DIR__);
    $marker_db   = $base . '/backups/.last_sp_db_mtime';
    $marker_up   = $base . '/backups/.last_sp_uploads_ts';
    $marker_cert = $base . '/backups/.last_sp_certs_ts';
    $bak_folder = trim(m365_setting('sp_backup_folder') ?: 'Backup', '/') . '/incremental';
    $stamp      = date('Ymd_His');
    $sent       = [];

    // ── Baza — tylko jeśli zmieniona od ostatniej wysyłki na SP ───────────
    $db_src = defined('DB_PATH') ? DB_PATH : ($base . '/umowy.db');
    if (file_exists($db_src)) {
        $db_mtime      = filemtime($db_src);
        $last_db_mtime = file_exists($marker_db) ? (int)file_get_contents($marker_db) : 0;

        if ($db_mtime > $last_db_mtime) {
            $tmp_db = sys_get_temp_dir() . '/feer_spinc_' . $stamp . '.db';
            $pdo = new \PDO('sqlite:' . $db_src);
            $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp_db));
            $pdo = null;
            $tmp_gz = sp_gzip_temp($tmp_db, 'feer_spinc_' . $stamp);
            @unlink($tmp_db);

            [$send, $suf] = _sp_backup_prepare($tmp_gz);
            $sp_path = $bak_folder . '/' . date('Y-m') . '/umowy_' . $stamp . '.db.gz' . $suf;
            $graph->sp_upload_file($site_id, $drive_id, $sp_path, $send);
            @unlink($send);
            file_put_contents($marker_db, $db_mtime);
            $sent[] = $sp_path;
        }
    }

    // ── Uploads — tylko pliki zmienione od ostatniej wysyłki na SP ────────
    $uploads_src = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/') : ($base . '/uploads');
    $last_up_ts  = file_exists($marker_up) ? (int)file_get_contents($marker_up) : 0;

    if (is_dir($uploads_src)) {
        $changed = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploads_src, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getMTime() > $last_up_ts) {
                $changed[] = $file->getPathname();
            }
        }

        if ($changed) {
            $list_file = tempnam(sys_get_temp_dir(), 'spinc_list_');
            $prefix    = basename($uploads_src) . '/';
            $rel_paths = array_map(
                fn($f) => $prefix . ltrim(substr($f, strlen($uploads_src)), '/'),
                $changed
            );
            file_put_contents($list_file, implode("\n", $rel_paths));

            $tmp_tar = sys_get_temp_dir() . '/feer_spinc_uploads_' . $stamp . '.tar.gz';
            $cmd = 'tar -czf ' . escapeshellarg($tmp_tar)
                 . ' -C ' . escapeshellarg(dirname($uploads_src))
                 . ' -T ' . escapeshellarg($list_file)
                 . ' 2>&1';
            $output = [];
            $ret    = 0;
            exec($cmd, $output, $ret);
            unlink($list_file);

            if ($ret === 0) {
                [$send, $suf] = _sp_backup_prepare($tmp_tar);
                $sp_path = $bak_folder . '/' . date('Y-m') . '/uploads_' . $stamp . '.tar.gz' . $suf;
                $graph->sp_upload_file($site_id, $drive_id, $sp_path, $send);
                @unlink($send);
                file_put_contents($marker_up, time());
                $sent[] = $sp_path;
            } else {
                @unlink($tmp_tar);
                return ['ok' => false, 'error' => 'tar: ' . implode(' ', $output)];
            }
        }
    }

    // ── Certs — tylko gdy katalog zmienił się od ostatniej wysyłki na SP ──────
    $certs_src  = $base . '/certs';
    $last_ct_ts = file_exists($marker_cert) ? (int)file_get_contents($marker_cert) : 0;
    if (is_dir($certs_src)) {
        $certs_changed = false;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($certs_src, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getMTime() > $last_ct_ts) { $certs_changed = true; break; }
        }
        if ($certs_changed) {
            $tmp_tar = sys_get_temp_dir() . '/feer_spinc_certs_' . $stamp . '.tar.gz';
            $cmd = 'tar -czf ' . escapeshellarg($tmp_tar) . ' -C ' . escapeshellarg($base) . ' certs 2>&1';
            $output = []; $ret = 0; exec($cmd, $output, $ret);
            if ($ret === 0) {
                [$send, $suf] = _sp_backup_prepare($tmp_tar);
                $sp_path = $bak_folder . '/' . date('Y-m') . '/certs_' . $stamp . '.tar.gz' . $suf;
                $graph->sp_upload_file($site_id, $drive_id, $sp_path, $send);
                @unlink($send);
                file_put_contents($marker_cert, time());
                $sent[] = $sp_path;
            } else {
                @unlink($tmp_tar);
                return ['ok' => false, 'error' => 'tar (certs): ' . implode(' ', $output)];
            }
        }
    }

    return ['ok' => true, 'sent' => $sent];
}

/**
 * Pełny backup całego systemu (baza + wszystkie uploads, bez pomijania) na SharePoint.
 * Uruchamiany raz na dobę (w nocy) — niezależnie resetuje znaczniki przyrostowe SP,
 * żeby kolejny przyrostowy backup nie duplikował właśnie wysłanych danych.
 * Folder na SP: sp_backup_folder / full / YYYY-MM / ...
 */
function sp_backup_full(): array {
    try {
        [$graph, $site_id, $drive_id] = sp_backup_preflight();
    } catch (\Throwable $e) {
        return ['ok' => false, 'skipped' => true, 'error' => $e->getMessage()];
    }

    $base       = dirname(__DIR__);
    $bak_folder = trim(m365_setting('sp_backup_folder') ?: 'Backup', '/') . '/full';
    $stamp      = date('Ymd_His');
    $sent       = [];

    // ── Baza — pełny VACUUM INTO + gzip ────────────────────────────────────
    $db_src = defined('DB_PATH') ? DB_PATH : ($base . '/umowy.db');
    if (file_exists($db_src)) {
        $tmp_db = sys_get_temp_dir() . '/feer_spfull_' . $stamp . '.db';
        $pdo = new \PDO('sqlite:' . $db_src);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp_db));
        $pdo = null;
        $tmp_gz = sp_gzip_temp($tmp_db, 'feer_spfull_' . $stamp);
        @unlink($tmp_db);

        [$send, $suf] = _sp_backup_prepare($tmp_gz);
        $sp_path = $bak_folder . '/' . date('Y-m') . '/umowy_' . $stamp . '.db.gz' . $suf;
        $graph->sp_upload_file($site_id, $drive_id, $sp_path, $send);
        @unlink($send);
        $sent[] = $sp_path;
    }

    // ── Uploads — pełne archiwum całego katalogu ───────────────────────────
    $uploads_src = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/') : ($base . '/uploads');
    if (is_dir($uploads_src)) {
        $tmp_tar = sys_get_temp_dir() . '/feer_spfull_uploads_' . $stamp . '.tar.gz';
        $cmd = 'tar -czf ' . escapeshellarg($tmp_tar)
             . ' -C ' . escapeshellarg(dirname($uploads_src))
             . ' ' . escapeshellarg(basename($uploads_src))
             . ' 2>&1';
        $output = [];
        $ret    = 0;
        exec($cmd, $output, $ret);
        if ($ret === 0) {
            [$send, $suf] = _sp_backup_prepare($tmp_tar);
            $sp_path = $bak_folder . '/' . date('Y-m') . '/uploads_' . $stamp . '.tar.gz' . $suf;
            $graph->sp_upload_file($site_id, $drive_id, $sp_path, $send);
            @unlink($send);
            $sent[] = $sp_path;
        } else {
            @unlink($tmp_tar);
            return ['ok' => false, 'error' => 'tar: ' . implode(' ', $output)];
        }
    }

    // ── Certs — pełny tar całego katalogu (klucze prywatne) ────────────────
    $certs_src = $base . '/certs';
    if (is_dir($certs_src)) {
        $tmp_tar = sys_get_temp_dir() . '/feer_spfull_certs_' . $stamp . '.tar.gz';
        $cmd = 'tar -czf ' . escapeshellarg($tmp_tar) . ' -C ' . escapeshellarg($base) . ' certs 2>&1';
        $output = []; $ret = 0; exec($cmd, $output, $ret);
        if ($ret === 0) {
            [$send, $suf] = _sp_backup_prepare($tmp_tar);
            $sp_path = $bak_folder . '/' . date('Y-m') . '/certs_' . $stamp . '.tar.gz' . $suf;
            $graph->sp_upload_file($site_id, $drive_id, $sp_path, $send);
            @unlink($send);
            $sent[] = $sp_path;
        } else {
            @unlink($tmp_tar);
            return ['ok' => false, 'error' => 'tar (certs): ' . implode(' ', $output)];
        }
    }

    // Po pełnym backupie zresetuj znaczniki przyrostowe SP, żeby kolejny
    // przyrostowy backup wysyłał tylko zmiany od TEJ chwili.
    @file_put_contents($base . '/backups/.last_sp_db_mtime', file_exists($db_src) ? filemtime($db_src) : time());
    @file_put_contents($base . '/backups/.last_sp_uploads_ts', time());
    @file_put_contents($base . '/backups/.last_sp_certs_ts', time());

    return ['ok' => true, 'sent' => $sent];
}

/**
 * Retencja kopii na SharePoint — w folderach incremental/ i full/ zachowuje
 * min. $keepMin najnowszych kopii KAŻDEGO typu (db/uploads/certs) w danym kanale,
 * a starsze niż $maxDays dni usuwa. Cicho pomija, gdy SP wyłączony.
 * @return array{ok:bool,skipped?:bool,deleted?:int,scanned?:int,error?:string}
 */
function sp_backup_retention(int $keepMin = 3, ?int $maxDays = null): array {
    try {
        [$graph, $site_id, $drive_id] = sp_backup_preflight();
    } catch (\Throwable $e) {
        return ['ok' => false, 'skipped' => true, 'error' => $e->getMessage()];
    }
    if (!function_exists('backup_kind') && is_file(__DIR__ . '/backup.php')) require_once __DIR__ . '/backup.php';
    $maxDays = $maxDays ?? (int)(org_setting('sp_backup_retention_days') ?: 90);
    $root    = trim(m365_setting('sp_backup_folder') ?: 'Backup', '/');

    $files = [];
    foreach (['incremental', 'full'] as $chan) {
        try { $months = $graph->sp_list_children_by_path($site_id, $drive_id, "{$root}/{$chan}"); }
        catch (\Throwable $e) { continue; }
        foreach ($months as $mf) {
            if (empty($mf['folder'])) continue;
            try { $items = $graph->sp_list_children_by_path($site_id, $drive_id, "{$root}/{$chan}/{$mf['name']}"); }
            catch (\Throwable $e) { continue; }
            foreach ($items as $it) {
                if (empty($it['file'])) continue;
                $kind = function_exists('backup_kind') ? backup_kind($it['name']) : null;
                $files[] = [
                    'id'   => $it['id'],
                    'chan' => $chan,
                    'kind' => $kind ?: 'other',
                    'ts'   => strtotime($it['lastModifiedDateTime'] ?? '') ?: 0,
                ];
            }
        }
    }

    // Grupuj po kanał+typ; zachowaj keepMin najnowszych, usuń starsze niż maxDays.
    $groups = [];
    foreach ($files as $f) $groups[$f['chan'] . '|' . $f['kind']][] = $f;
    $deleted = 0; $now = time();
    foreach ($groups as $g) {
        usort($g, fn($a, $b) => $b['ts'] <=> $a['ts']);
        foreach (array_slice($g, $keepMin) as $f) {
            if ($maxDays > 0 && ($now - $f['ts']) > $maxDays * 86400) {
                try { $graph->sp_delete_item($drive_id, $f['id']); $deleted++; } catch (\Throwable $e) {}
            }
        }
    }
    return ['ok' => true, 'deleted' => $deleted, 'scanned' => count($files)];
}

/** Znacznik ostatniej udanej wysyłki na SharePoint (unix ts) lub 0. */
function sp_backup_last_ok_ts(): int {
    $p = dirname(__DIR__) . '/backups/.last_sp_ok';
    return is_file($p) ? (int)file_get_contents($p) : 0;
}

function sp_backup_mark_ok(): void {
    @file_put_contents(dirname(__DIR__) . '/backups/.last_sp_ok', (string)time());
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
