<?php
/**
 * includes/cloudflare.php — Integracja z Cloudflare API v4 (zarządzanie DNS).
 *
 * Konfiguracja w settings (prefix cloudflare_): api_token.
 * Autoryzacja przez API Token (Bearer) — NIE Global API Key (bezpieczniejsze,
 * token można ograniczyć uprawnieniami Zone:Read + DNS:Edit).
 *
 * Wywołania API przez stream_context (bez zależności curl) — spójnie z payu.php/stripe.php.
 */

const CLOUDFLARE_DNS_TYPES       = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS', 'SRV', 'CAA'];
const CLOUDFLARE_PROXIABLE_TYPES = ['A', 'AAAA', 'CNAME'];
const CLOUDFLARE_PRIORITY_TYPES  = ['MX', 'SRV'];

// ── Ustawienia ────────────────────────────────────────────────────────────────
function cloudflare_setting(string $key, string $default = ''): string {
    static $cache = [];
    $fk = 'cloudflare_' . $key;
    if (!array_key_exists($fk, $cache)) {
        try {
            $r = db_one("SELECT value FROM settings WHERE key_=?", [$fk]);
            $cache[$fk] = $r['value'] ?? $default;
        } catch (\Throwable $e) { $cache[$fk] = $default; }
    }
    return $cache[$fk] !== '' ? $cache[$fk] : $default;
}

function cloudflare_save_setting(string $key, string $value): void {
    $fk = 'cloudflare_' . $key;
    try {
        db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
           ->execute([$fk, $value]);
    } catch (\Throwable $e) {
        $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$fk]);
        if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $fk]);
        else     db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?)")->execute([$fk, $value]);
    }
}

function cloudflare_configured(): bool {
    return cloudflare_setting('api_token') !== '';
}

function cloudflare_enabled(): bool {
    return module_enabled('cloudflare_enabled') && cloudflare_configured();
}

// ── Wywołania API ───────────────────────────────────────────────────────────────
/** @throws RuntimeException gdy API zwróci błąd lub token nie jest skonfigurowany. */
function cloudflare_request(string $method, string $path, ?array $body = null, array $query = []): array {
    $token = cloudflare_setting('api_token');
    if ($token === '') throw new RuntimeException('Brak skonfigurowanego tokenu API Cloudflare.');

    $url = 'https://api.cloudflare.com/client/v4' . $path;
    if ($query) $url .= '?' . http_build_query($query);

    $opts = [
        'method'        => $method,
        'header'        => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
        'ignore_errors' => true,
        'timeout'       => 20,
    ];
    if ($body !== null) $opts['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);

    $ctx  = stream_context_create(['http' => $opts]);
    $raw  = @file_get_contents($url, false, $ctx);
    $resp = json_decode($raw ?: '{}', true);
    if (!is_array($resp)) throw new RuntimeException('Cloudflare: nieprawidłowa odpowiedź API.');

    if (empty($resp['success'])) {
        $msgs = array_map(fn($e) => (string)($e['message'] ?? 'błąd'), $resp['errors'] ?? []);
        throw new RuntimeException('Cloudflare: ' . ($msgs ? implode('; ', $msgs) : 'nieznany błąd API'));
    }
    return $resp;
}

/** Test połączenia — weryfikuje token API. */
function cloudflare_test_connection(): array {
    try {
        $r = cloudflare_request('GET', '/user/tokens/verify');
        $status = (string)($r['result']['status'] ?? '?');
        return ['ok' => true, 'msg' => 'Token aktywny (status: ' . $status . ').'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
}

/** Lista stref (domen) widocznych dla tokenu. */
function cloudflare_list_zones(): array {
    $r = cloudflare_request('GET', '/zones', null, ['per_page' => 50, 'order' => 'name']);
    return $r['result'] ?? [];
}

function cloudflare_zone(string $zone_id): ?array {
    $r = cloudflare_request('GET', "/zones/{$zone_id}");
    return $r['result'] ?? null;
}

// ── Rekordy DNS ─────────────────────────────────────────────────────────────────
function cloudflare_list_dns_records(string $zone_id, string $type = ''): array {
    $q = ['per_page' => 100, 'order' => 'type'];
    if ($type !== '') $q['type'] = $type;
    $r = cloudflare_request('GET', "/zones/{$zone_id}/dns_records", null, $q);
    return $r['result'] ?? [];
}

function cloudflare_get_dns_record(string $zone_id, string $record_id): ?array {
    $r = cloudflare_request('GET', "/zones/{$zone_id}/dns_records/{$record_id}");
    return $r['result'] ?? null;
}

function cloudflare_create_dns_record(string $zone_id, array $data): array {
    $r = cloudflare_request('POST', "/zones/{$zone_id}/dns_records", $data);
    return $r['result'];
}

function cloudflare_update_dns_record(string $zone_id, string $record_id, array $data): array {
    $r = cloudflare_request('PUT', "/zones/{$zone_id}/dns_records/{$record_id}", $data);
    return $r['result'];
}

function cloudflare_delete_dns_record(string $zone_id, string $record_id): void {
    cloudflare_request('DELETE', "/zones/{$zone_id}/dns_records/{$record_id}");
}

// ── Autokonfigurator Microsoft 365 ───────────────────────────────────────────────
/**
 * Standardowy zestaw rekordów DNS wymaganych przez Microsoft 365 (Exchange Online,
 * Autodiscover, Teams/Skype for Business, rejestracja urządzeń) dla danej domeny.
 * Wartości zgodne z oficjalną listą Microsoft „Dodaj rekordy DNS, aby połączyć domenę".
 */
function cloudflare_m365_template(string $domain): array {
    return [
        ['key' => 'mx', 'type' => 'MX', 'name' => $domain,
            'content' => $domain . '.mail.protection.outlook.com', 'priority' => 0, 'ttl' => 3600,
            'purpose' => 'Poczta — serwer przychodzący (Exchange Online)'],
        ['key' => 'spf', 'type' => 'TXT', 'name' => $domain, 'spf' => true,
            'content' => 'v=spf1 include:spf.protection.outlook.com -all', 'ttl' => 3600,
            'purpose' => 'Poczta — weryfikacja nadawcy (SPF)'],
        ['key' => 'autodiscover', 'type' => 'CNAME', 'name' => 'autodiscover.' . $domain,
            'content' => 'autodiscover.outlook.com', 'ttl' => 3600, 'proxied' => false,
            'purpose' => 'Poczta — automatyczna konfiguracja klienta (Autodiscover)'],
        ['key' => 'sip', 'type' => 'CNAME', 'name' => 'sip.' . $domain,
            'content' => 'sipdir.online.lync.com', 'ttl' => 3600, 'proxied' => false,
            'purpose' => 'Teams/Skype — adresowanie SIP'],
        ['key' => 'lyncdiscover', 'type' => 'CNAME', 'name' => 'lyncdiscover.' . $domain,
            'content' => 'webdir.online.lync.com', 'ttl' => 3600, 'proxied' => false,
            'purpose' => 'Teams/Skype — wykrywanie usługi'],
        ['key' => 'enterpriseregistration', 'type' => 'CNAME', 'name' => 'enterpriseregistration.' . $domain,
            'content' => 'enterpriseregistration.windows.net', 'ttl' => 3600, 'proxied' => false,
            'purpose' => 'Zarządzanie urządzeniami — rejestracja (Azure AD)'],
        ['key' => 'enterpriseenrollment', 'type' => 'CNAME', 'name' => 'enterpriseenrollment.' . $domain,
            'content' => 'enterpriseenrollment.manage.microsoft.com', 'ttl' => 3600, 'proxied' => false,
            'purpose' => 'Zarządzanie urządzeniami — zapisywanie (MDM/Intune)'],
        ['key' => 'msoid', 'type' => 'CNAME', 'name' => 'msoid.' . $domain,
            'content' => 'clientconfig.microsoftonline-p.net', 'ttl' => 3600, 'proxied' => false,
            'purpose' => 'Logowanie federacyjne — starsze klienty Skype for Business'],
        ['key' => 'srv_sip_tls', 'type' => 'SRV', 'name' => '_sip._tls.' . $domain, 'ttl' => 3600,
            'data' => ['service' => '_sip', 'proto' => '_tls', 'name' => $domain,
                       'priority' => 100, 'weight' => 1, 'port' => 443, 'target' => 'sipdir.online.lync.com'],
            'purpose' => 'Teams/Skype — lokalizacja usługi SIP (TLS)'],
        ['key' => 'srv_sipfed_tcp', 'type' => 'SRV', 'name' => '_sipfederationtls._tcp.' . $domain, 'ttl' => 3600,
            'data' => ['service' => '_sipfederationtls', 'proto' => '_tcp', 'name' => $domain,
                       'priority' => 100, 'weight' => 1, 'port' => 5061, 'target' => 'sipfed.online.lync.com'],
            'purpose' => 'Teams/Skype — federacja SIP'],
    ];
}

/**
 * Porównuje szablon M365 z aktualnym stanem strefy.
 * @return array Lista ['tpl'=>szablon, 'status'=>'ok'|'missing'|'conflict', 'existing'=>?rekord]
 */
function cloudflare_m365_plan(string $zone_id, string $domain): array {
    $existing = cloudflare_list_dns_records($zone_id);
    $plan = [];
    foreach (cloudflare_m365_template($domain) as $tpl) {
        $status = 'missing';
        $found   = null;

        if (!empty($tpl['spf'])) {
            foreach ($existing as $e) {
                if ($e['type'] === 'TXT' && $e['name'] === $tpl['name'] && str_starts_with(trim($e['content'] ?? ''), 'v=spf1')) {
                    $found  = $e;
                    $status = (trim($e['content']) === $tpl['content']) ? 'ok' : 'conflict';
                    break;
                }
            }
        } else {
            foreach ($existing as $e) {
                if ($e['type'] !== $tpl['type'] || $e['name'] !== $tpl['name']) continue;
                $found = $e;
                if ($tpl['type'] === 'SRV') {
                    $d = $e['data'] ?? [];
                    $status = ((int)($d['port'] ?? -1) === $tpl['data']['port']
                            && (int)($d['priority'] ?? -1) === $tpl['data']['priority']
                            && (int)($d['weight'] ?? -1) === $tpl['data']['weight']
                            && (string)($d['target'] ?? '') === $tpl['data']['target']) ? 'ok' : 'conflict';
                } elseif ($tpl['type'] === 'MX') {
                    $status = ($e['content'] === $tpl['content'] && (int)($e['priority'] ?? -1) === $tpl['priority']) ? 'ok' : 'conflict';
                } else {
                    // proxied (chmurka Cloudflare) łamie usługi Microsoft — traktuj jak konflikt mimo zgodnej treści.
                    $status = ($e['content'] === $tpl['content'] && (!array_key_exists('proxied', $tpl) || !empty($e['proxied']) === $tpl['proxied']))
                        ? 'ok' : 'conflict';
                }
                break;
            }
        }
        $plan[] = ['tpl' => $tpl, 'status' => $status, 'existing' => $found];
    }
    return $plan;
}

/**
 * Tworzy w Cloudflare rekordy o podanych kluczach szablonu, o ile faktycznie
 * wciąż brakuje ich w strefie (ponowna weryfikacja tuż przed zapisem — nigdy
 * nie nadpisuje istniejących rekordów).
 * @return array ['created'=>string[] etykiety, 'errors'=>string[] etykieta: komunikat]
 */
function cloudflare_m365_apply(string $zone_id, string $domain, array $keys): array {
    $plan = cloudflare_m365_plan($zone_id, $domain);
    $created = []; $errors = [];
    foreach ($plan as $row) {
        $tpl = $row['tpl'];
        if (!in_array($tpl['key'], $keys, true)) continue;
        if ($row['status'] !== 'missing') continue;

        $payload = ['type' => $tpl['type'], 'name' => $tpl['name'], 'ttl' => $tpl['ttl']];
        if (isset($tpl['content']))            $payload['content']  = $tpl['content'];
        if (isset($tpl['priority']))           $payload['priority'] = $tpl['priority'];
        if (isset($tpl['data']))               $payload['data']     = $tpl['data'];
        if (array_key_exists('proxied', $tpl)) $payload['proxied']  = $tpl['proxied'];

        try {
            cloudflare_create_dns_record($zone_id, $payload);
            $created[] = $tpl['purpose'];
        } catch (\Throwable $e) {
            $errors[] = $tpl['purpose'] . ': ' . $e->getMessage();
        }
    }
    return ['created' => $created, 'errors' => $errors];
}
