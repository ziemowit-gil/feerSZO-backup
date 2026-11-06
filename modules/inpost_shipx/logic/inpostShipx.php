<?php
/**
 * modules/inpost_shipx/logic/inpostShipx.php
 * Klient InPost ShipX (https://dokumentacja-inpost.atlassian.net/wiki/spaces/PL/pages/622754/API+ShipX)
 * + schemat tabeli inpost_shipments + ustawienia (tabela settings, klucze inpost_*).
 * Token organizacji trzymany WYŁĄCZNIE w ustawieniach (nie w kodzie).
 */
require_once dirname(__DIR__, 3) . '/includes/db.php';

const INPOST_SANDBOX_URL = 'https://sandbox-api-shipx-pl.easypack24.net';
const INPOST_PROD_URL    = 'https://api-shipx-pl.easypack24.net';

function inpostMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS inpost_shipments (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        shipx_id         TEXT,
        status           TEXT NOT NULL DEFAULT 'created',
        tracking_number  TEXT,
        service          TEXT,
        parcel_template  TEXT,
        sender_name      TEXT, sender_email TEXT, sender_phone TEXT,
        sender_street    TEXT, sender_building TEXT, sender_city TEXT, sender_post_code TEXT,
        receiver_name    TEXT, receiver_email TEXT, receiver_phone TEXT,
        target_point     TEXT,
        reference        TEXT,
        sandbox          INTEGER NOT NULL DEFAULT 1,
        client_ip        TEXT,
        error            TEXT,
        created_at       DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at       DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

function inpostSetting(string $key, string $default = ''): string {
    $r = db_one("SELECT value FROM settings WHERE key_=?", ['inpost_' . $key]);
    return ($r && $r['value'] !== null && $r['value'] !== '') ? (string)$r['value'] : $default;
}

function inpostSettingSave(string $key, string $value): void {
    db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?,?)")->execute(['inpost_' . $key, $value]);
}

function inpostBaseUrl(): string {
    return inpostSetting('sandbox', '1') === '1' ? INPOST_SANDBOX_URL : INPOST_PROD_URL;
}

function inpostConfigured(): bool {
    return inpostSetting('token') !== '' && inpostSetting('org_id') !== '';
}

/** Rozmiary gabarytów Paczkomat (A/B/C) — szablony ShipX. */
function inpostParcelSizes(): array {
    return [
        'small'  => 'Gabaryt A (do 8 × 38 × 64 cm, do 25 kg)',
        'medium' => 'Gabaryt B (do 19 × 38 × 64 cm, do 25 kg)',
        'large'  => 'Gabaryt C (do 41 × 38 × 64 cm, do 25 kg)',
    ];
}

/**
 * Wywołanie API ShipX. Zwraca [kod HTTP, treść (tablica lub surowy string dla PDF)].
 * @throws RuntimeException przy braku konfiguracji lub błędzie sieci
 */
function inpostRequest(string $method, string $path, ?array $body = null, bool $raw = false): array {
    $token = inpostSetting('token');
    if ($token === '') throw new RuntimeException('Brak tokenu InPost w ustawieniach.');
    $ch = curl_init(inpostBaseUrl() . $path);
    $headers = ['Authorization: Bearer ' . $token, 'Accept: ' . ($raw ? 'application/pdf' : 'application/json')];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $resp = curl_exec($ch);
    if ($resp === false) {
        $e = curl_error($ch); curl_close($ch);
        throw new RuntimeException('Brak połączenia z InPost: ' . $e);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw && $code < 300) return [$code, $resp];
    $j = json_decode((string)$resp, true);
    return [$code, is_array($j) ? $j : ['raw' => (string)$resp]];
}

/** Czytelny komunikat błędu z odpowiedzi ShipX (details bywa zagnieżdżone). */
function inpostErrorText(int $code, array $r): string {
    $msg = (string)($r['message'] ?? $r['error'] ?? '');
    $det = '';
    if (!empty($r['details'])) {
        $flat = [];
        array_walk_recursive($r['details'], function ($v, $k) use (&$flat) { $flat[] = $k . ': ' . $v; });
        $det = ' (' . implode('; ', $flat) . ')';
    }
    if ($code === 401 || $code === 403) return 'InPost odrzucił token lub brak uprawnień (HTTP ' . $code . ').';
    return 'InPost: ' . ($msg ?: ($r['raw'] ?? 'błąd HTTP ' . $code)) . $det;
}

/** Sprawdza istnienie punktu (Paczkomat) o danej nazwie, np. KRA010. */
function inpostPointExists(string $name): ?bool {
    try {
        [$c, $r] = inpostRequest('GET', '/v1/points/' . rawurlencode($name));
        if ($c === 200) return true;
        if ($c === 404) return false;
    } catch (Throwable $e) {}
    return null; // nie wiadomo — nie blokuj
}

/**
 * Tworzy przesyłkę Paczkomat→Paczkomat (inpost_locker_standard) lub Kurier (inpost_courier_standard).
 * $d: sender{name,email,phone,street,building,city,post_code}, receiver{...same + target_point?}, size, reference
 * @return array wiersz inpost_shipments (id lokalne)
 */
function inpostCreateShipment(array $d): int {
    inpostMigrate();
    $locker  = ($d['service'] ?? 'inpost_locker_standard') === 'inpost_locker_standard';
    $split = function (string $n): array { $p = preg_split('/\s+/', trim($n), 2); return [$p[0] ?? '', $p[1] ?? '-']; };
    $s = $d['sender']; $r = $d['receiver'];
    [$sf, $sl] = $split($s['name']); [$rf, $rl] = $split($r['name']);
    $addr = fn(array $x) => ['street' => $x['street'], 'building_number' => $x['building'],
        'city' => $x['city'], 'post_code' => $x['post_code'], 'country_code' => 'PL'];
    $payload = [
        'service'   => $d['service'],
        'reference' => mb_substr((string)($d['reference'] ?? ''), 0, 100),
        'sender'    => ['first_name' => $sf, 'last_name' => $sl, 'email' => $s['email'], 'phone' => $s['phone'], 'address' => $addr($s)],
        'receiver'  => ['first_name' => $rf, 'last_name' => $rl, 'email' => $r['email'], 'phone' => $r['phone']],
        'parcels'   => [$locker ? ['template' => $d['size']]
                                : ['dimensions' => ['length' => 380, 'width' => 640, 'height' => 80, 'unit' => 'mm'],
                                   'weight' => ['amount' => 1, 'unit' => 'kg']]],
    ];
    if ($locker) {
        $payload['custom_attributes'] = ['target_point' => $r['target_point'], 'sending_method' => 'parcel_locker'];
    } else {
        $payload['receiver']['address'] = $addr($r);
    }
    $org = rawurlencode(inpostSetting('org_id'));
    [$code, $res] = inpostRequest('POST', "/v1/organizations/$org/shipments", $payload);
    if ($code >= 300 || empty($res['id'])) {
        throw new RuntimeException(inpostErrorText($code, $res));
    }
    $id = (string)$res['id'];
    // Numer przesyłki nadawany asynchronicznie — krótkie odpytywanie.
    $track = (string)($res['tracking_number'] ?? '');
    for ($i = 0; $i < 5 && $track === ''; $i++) {
        usleep(700000);
        [$c2, $r2] = inpostRequest('GET', '/v1/shipments/' . rawurlencode($id));
        if ($c2 === 200) { $track = (string)($r2['tracking_number'] ?? ''); $res = $r2; }
    }
    db()->prepare("INSERT INTO inpost_shipments
        (shipx_id,status,tracking_number,service,parcel_template,sender_name,sender_email,sender_phone,sender_street,
         sender_building,sender_city,sender_post_code,receiver_name,receiver_email,receiver_phone,target_point,reference,sandbox,client_ip)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$id, (string)($res['status'] ?? 'created'), $track, $d['service'], $d['size'] ?? null,
        $s['name'], $s['email'], $s['phone'], $s['street'], $s['building'], $s['city'], $s['post_code'],
        $r['name'], $r['email'], $r['phone'], $locker ? $r['target_point'] : null,
        $d['reference'] ?? '', inpostSetting('sandbox', '1') === '1' ? 1 : 0, $d['client_ip'] ?? '']);
    return (int)db()->lastInsertId();
}

/** PDF etykiety dla lokalnego id przesyłki (status musi być confirmed). */
function inpostLabelPdf(string $shipxId): string {
    [$c, $pdf] = inpostRequest('GET', '/v1/shipments/' . rawurlencode($shipxId) . '/label?format=pdf', null, true);
    if ($c >= 300) {
        $t = is_array($pdf) ? inpostErrorText($c, $pdf) : 'Etykieta niedostępna (HTTP ' . $c . ').';
        throw new RuntimeException($t);
    }
    return $pdf;
}

/** Odświeża status/numer z ShipX do bazy. */
function inpostRefresh(int $localId): ?array {
    $row = db_one("SELECT * FROM inpost_shipments WHERE id=?", [$localId]);
    if (!$row) return null;
    [$c, $r] = inpostRequest('GET', '/v1/shipments/' . rawurlencode($row['shipx_id']));
    if ($c === 200) {
        db()->prepare("UPDATE inpost_shipments SET status=?, tracking_number=COALESCE(NULLIF(?,''),tracking_number), updated_at=datetime('now','localtime') WHERE id=?")
          ->execute([(string)($r['status'] ?? $row['status']), (string)($r['tracking_number'] ?? ''), $localId]);
    }
    return db_one("SELECT * FROM inpost_shipments WHERE id=?", [$localId]);
}
