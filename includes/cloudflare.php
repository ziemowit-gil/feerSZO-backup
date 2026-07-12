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
