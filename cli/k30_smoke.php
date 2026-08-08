<?php
/**
 * cli/k30_smoke.php — Smoke-testy REST API Dydaktyka 3 (CRUD / 401 / 404 / 422 / 429 / OpenAPI).
 *
 * Użycie:
 *   php cli/k30_smoke.php                      # sam uruchamia php -S i testuje
 *   BASE_URL=https://host php cli/k30_smoke.php # testuje istniejący serwer
 *
 * Tworzy tymczasowe klucze API i rekord testowy, po czym je usuwa.
 * Kod wyjścia: 0 = wszystko OK, 1 = są błędy.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/api_auth.php';
api_auth_migrate();

$SRV_PID = null;
$KEYS    = [];   // utworzone klucze (plain) → posprzątamy po nazwie

// ── Sprzątanie zawsze na końcu ───────────────────────────────────────────────
register_shutdown_function(function () use (&$SRV_PID) {
    try {
        $ids = array_column(db_all("SELECT id FROM api_keys WHERE name LIKE '__SMOKE%'"), 'id');
        foreach ($ids as $id) {
            db()->prepare("DELETE FROM api_rate_limit WHERE key_id=?")->execute([$id]);
            db()->prepare("DELETE FROM api_audit_log  WHERE key_id=?")->execute([$id]);
        }
        db()->exec("DELETE FROM api_keys   WHERE name LIKE '__SMOKE%'");
        db()->exec("DELETE FROM k30_clients WHERE name LIKE '__SMOKE%'");
    } catch (\Throwable $e) {}
    if ($SRV_PID) { @exec('kill ' . (int)$SRV_PID . ' 2>/dev/null'); }
});

// ── Klucz testowy ────────────────────────────────────────────────────────────
function smoke_make_key(string $name, array $perms, ?int $rate = null): string {
    $plain = bin2hex(random_bytes(16));
    db_insert('api_keys', [
        'key_hash'    => hash('sha256', $plain),
        'name'        => $name,
        'permissions' => json_encode($perms),
        'rate_limit'  => $rate,
        'created_at'  => date('Y-m-d H:i:s'),
        'is_active'   => 1,
    ]);
    return $plain;
}

// ── Serwer ───────────────────────────────────────────────────────────────────
$base = getenv('BASE_URL') ? rtrim(getenv('BASE_URL'), '/') : '';
if ($base === '') {
    $port = 8769;
    $log  = sys_get_temp_dir() . '/k30_smoke_srv.log';
    $cmd  = 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root) . ' >' . escapeshellarg($log) . ' 2>&1 & echo $!';
    $SRV_PID = (int)trim((string)shell_exec($cmd));
    $base = 'http://127.0.0.1:' . $port;
    // poczekaj aż wstanie
    for ($i = 0; $i < 30; $i++) {
        $c = @file_get_contents($base . '/api/v1/openapi.php');
        if ($c !== false) break;
        usleep(150000);
    }
}
$API = $base . '/api/v1/karty30.php';

// ── HTTP helper ──────────────────────────────────────────────────────────────
function http(string $method, string $url, ?string $key = null, ?array $body = null): array {
    $ch = curl_init($url);
    $h  = ['Accept: application/json'];
    if ($key)  $h[] = 'Authorization: Bearer ' . $key;
    if ($body !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode((string)$resp, true)];
}

// ── Asercje ──────────────────────────────────────────────────────────────────
$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $extra = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \033[32m✓\033[0m $name\n"; }
    else     { $fail++; echo "  \033[31m✗\033[0m $name" . ($extra ? "  ($extra)" : '') . "\n"; }
}

echo "Smoke-testy API Dydaktyka 3 → $base\n\n";

$RW = smoke_make_key('__SMOKE_RW__', ['karty30:read', 'karty30:write']);

echo "Odczyt / błędy:\n";
[$c, $b] = http('GET', $API, $RW);
check('GET lista zasobów = 200', $c === 200 && !empty($b['data']['resources']), "code=$c");
[$c] = http('GET', $API . '?resource=clients&per_page=2', $RW);
check('GET clients = 200', $c === 200, "code=$c");
[$c] = http('GET', $API . '?resource=clients', null);
check('GET bez klucza = 401', $c === 401, "code=$c");
[$c] = http('GET', $API . '?resource=foo', $RW);
check('GET nieznany zasób = 404', $c === 404, "code=$c");

echo "\nCRUD (clients):\n";
[$c, $b] = http('POST', $API . '?resource=clients', $RW, ['name' => '__SMOKE__ Jan', 'email' => 'smoke@example.pl']);
$nid = (int)($b['data']['id'] ?? 0);
check('POST create = 201', $c === 201 && $nid > 0, "code=$c");
[$c, $b] = http('PATCH', $API . '?resource=clients&id=' . $nid, $RW, ['status' => 'learning']);
check('PATCH update = 200', $c === 200 && ($b['data']['status'] ?? '') === 'learning', "code=$c");
[$c] = http('POST', $API . '?resource=clients', $RW, ['email' => 'x@y.pl']); // brak name
check('POST bez wymaganego pola = 422', $c === 422, "code=$c");
[$c] = http('POST', $API . '?resource=schedules', $RW, ['client_id' => 999999999, 'start_time' => '2030-01-01 10:00']);
check('POST zły klucz obcy = 422', $c === 422, "code=$c");
[$c] = http('DELETE', $API . '?resource=clients&id=' . $nid, $RW);
check('DELETE = 200', $c === 200, "code=$c");
[$c] = http('GET', $API . '?resource=clients&id=' . $nid, $RW);
check('GET usuniętego = 404', $c === 404, "code=$c");

echo "\nUprawnienia / limit:\n";
$RO = smoke_make_key('__SMOKE_RO__', ['karty30:read']);
[$c] = http('POST', $API . '?resource=clients', $RO, ['name' => '__SMOKE__ X']);
check('Zapis kluczem read-only = 401', $c === 401, "code=$c");
$RL = smoke_make_key('__SMOKE_RL__', ['karty30:read'], 2); // limit 2/min
$codes = [];
for ($i = 0; $i < 3; $i++) { [$cc] = http('GET', $API . '?resource=clients&per_page=1', $RL); $codes[] = $cc; }
check('Rate-limit: 3. żądanie = 429', $codes[2] === 429, 'kody=' . implode(',', $codes));

echo "\nAudyt + OpenAPI:\n";
$kid = (int)(db_one("SELECT id FROM api_keys WHERE name='__SMOKE_RW__'")['id'] ?? 0);
$au  = (int)(db_one("SELECT COUNT(*) c FROM api_audit_log WHERE key_id=?", [$kid])['c'] ?? 0);
check('Audyt zapisał operacje (create/update/delete)', $au >= 3, "wpisów=$au");
[$c, $b] = http('GET', $base . '/api/v1/openapi.php', null);
check('OpenAPI = 200 i poprawny JSON', $c === 200 && ($b['openapi'] ?? '') !== '', "code=$c");
check('OpenAPI zawiera ścieżkę /karty30.php', isset($b['paths']['/karty30.php']));

echo "\n" . str_repeat('─', 40) . "\n";
echo ($fail === 0 ? "\033[32mWSZYSTKO OK" : "\033[31mBŁĘDY") . "\033[0m — $pass zaliczonych, $fail nieudanych.\n";
exit($fail === 0 ? 0 : 1);
