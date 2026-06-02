<?php
/**
 * includes/apaczka.php — Apaczka.pl API v2 + moduł przesyłek
 */

// ── Schema DB ─────────────────────────────────────────────────────────────────
try {
    db()->exec("CREATE TABLE IF NOT EXISTS shipments (
        id                    INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id           INTEGER,
        contract_type         TEXT    DEFAULT 'wolontariat',
        user_id               INTEGER,
        direction             TEXT    NOT NULL DEFAULT 'out',
        purpose               TEXT    DEFAULT 'documents',
        status                TEXT    NOT NULL DEFAULT 'draft',
        apaczka_order_id      TEXT,
        waybill_number        TEXT,
        service_id            INTEGER,
        tracking_url          TEXT,
        sender_name           TEXT,
        sender_line1          TEXT,
        sender_line2          TEXT,
        sender_postal_code    TEXT,
        sender_city           TEXT,
        sender_country_code   TEXT    DEFAULT 'PL',
        sender_email          TEXT,
        sender_phone          TEXT,
        receiver_name         TEXT,
        receiver_line1        TEXT,
        receiver_line2        TEXT,
        receiver_postal_code  TEXT,
        receiver_city         TEXT,
        receiver_country_code TEXT    DEFAULT 'PL',
        receiver_email        TEXT,
        receiver_phone        TEXT,
        receiver_point_id     TEXT,
        receiver_point_type   TEXT,
        pickup_type           TEXT    DEFAULT 'SELF',
        pickup_date           TEXT,
        pickup_hours_from     TEXT,
        pickup_hours_to       TEXT,
        weight                REAL    DEFAULT 0.5,
        dimension1            INTEGER DEFAULT 25,
        dimension2            INTEGER DEFAULT 20,
        dimension3            INTEGER DEFAULT 5,
        content               TEXT,
        comment               TEXT,
        waybill_path          TEXT,
        notes                 TEXT,
        created_by            INTEGER,
        approved_by           INTEGER,
        ordered_at            DATETIME,
        created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at            DATETIME DEFAULT NULL
    )");
} catch (\Throwable $e) {}

// ── Constants ─────────────────────────────────────────────────────────────────
const SHIPMENT_STATUS = [
    'draft'      => ['label' => 'Szkic',         'class' => 'secondary'],
    'requested'  => ['label' => 'Wnioskowane',   'class' => 'warning'],
    'approved'   => ['label' => 'Zatwierdzone',  'class' => 'info'],
    'ordered'    => ['label' => 'Zamówione',      'class' => 'primary'],
    'in_transit' => ['label' => 'W dostawie',    'class' => 'info'],
    'delivered'  => ['label' => 'Dostarczone',   'class' => 'success'],
    'cancelled'  => ['label' => 'Anulowane',     'class' => 'danger'],
];

const SHIPMENT_PURPOSE = [
    'documents'   => 'Dokumenty (umowy, zaświadczenia)',
    'equipment'   => 'Materiały / ekwipunek',
    'return_docs' => 'Zwrot dokumentów',
    'other'       => 'Inne',
];

const SHIPMENT_DIRECTION = [
    'out'    => 'FEER → Wolontariusz',
    'return' => 'Wolontariusz → FEER',
];

// ── Settings helper ───────────────────────────────────────────────────────────
function apaczka_setting(string $key): string {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $r['value'] ?? '';
    }
    return $cache[$key];
}

function apaczka_save(string $key, string $value): void {
    if (DB_TYPE === 'sqlite') {
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?,?)")->execute([$key, $value]);
    } else {
        db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")->execute([$key, $value, $value]);
    }
    // Bust static cache
    $GLOBALS['_apaczka_cache_bust'] = true;
}

// ── Badges / helpers ──────────────────────────────────────────────────────────
function shipment_badge(string $status): string {
    $s = SHIPMENT_STATUS[$status] ?? ['label' => $status, 'class' => 'secondary'];
    return '<span class="badge bg-' . $s['class'] . '">' . h($s['label']) . '</span>';
}

function shipment_pending_count(): int {
    try {
        $r = db_one("SELECT COUNT(*) AS c FROM shipments WHERE status='requested'");
        return (int)($r['c'] ?? 0);
    } catch (\Throwable $e) { return 0; }
}

function shipments_for_contract(int $contract_id, string $type = 'wolontariat'): array {
    return db_all(
        "SELECT * FROM shipments WHERE contract_id=? AND contract_type=? ORDER BY created_at DESC",
        [$contract_id, $type]
    );
}

// Ścieżka do zapisu etykiet
function waybill_dir(): string {
    $dir = rtrim(UPLOAD_DIR, '/') . '/waybills/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

// Domyślne dane nadawcy z ustawień
function apaczka_sender_defaults(): array {
    return [
        'name'         => apaczka_setting('apaczka_sender_name')   ?: ORG_NAME,
        'line1'        => apaczka_setting('apaczka_sender_line1'),
        'line2'        => apaczka_setting('apaczka_sender_line2'),
        'postal_code'  => apaczka_setting('apaczka_sender_postal'),
        'city'         => apaczka_setting('apaczka_sender_city'),
        'country_code' => 'PL',
        'email'        => apaczka_setting('apaczka_sender_email'),
        'phone'        => apaczka_setting('apaczka_sender_phone'),
    ];
}

// ── API Client ────────────────────────────────────────────────────────────────
class Apaczka {
    private string $app_id;
    private string $app_secret;
    private string $base = 'https://www.apaczka.pl/api/v2/';

    public function __construct() {
        $this->app_id     = apaczka_setting('apaczka_app_id');
        $this->app_secret = apaczka_setting('apaczka_app_secret');
    }

    public function is_configured(): bool {
        return !empty($this->app_id) && !empty($this->app_secret);
    }

    // ── Podpisywanie requestu ────────────────────────────────────────────────
    private function sign(string $route, string $data, int $expires): array {
        $str = sprintf('%s:%s:%s:%s', $this->app_id, $route, $data, $expires);
        return [
            'app_id'    => $this->app_id,
            'request'   => $data,
            'expires'   => $expires,
            'signature' => hash_hmac('sha256', $str, $this->app_secret),
        ];
    }

    // ── POST ─────────────────────────────────────────────────────────────────
    private function post(string $route, array $data = []): array {
        if (!$this->is_configured()) {
            throw new RuntimeException('Apaczka API nie jest skonfigurowane.');
        }
        $expires = time() + 1700;
        $json    = empty($data) ? '[]' : json_encode($data);
        $payload = $this->sign($route, $json, $expires);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content'       => http_build_query($payload),
            'ignore_errors' => true,
            'timeout'       => 20,
        ]]);
        $resp = @file_get_contents($this->base . $route, false, $ctx);
        if ($resp === false) throw new RuntimeException('Brak odpowiedzi z Apaczka API.');
        $arr = json_decode($resp, true);
        if (!is_array($arr)) throw new RuntimeException('Nieprawidłowa odpowiedź z API: ' . substr($resp, 0, 200));
        return $arr;
    }

    // ── Endpointy ────────────────────────────────────────────────────────────
    public function service_structure(): array         { return $this->post('service_structure/'); }
    public function order_valuation(array $o): array   { return $this->post('order_valuation/', $o); }
    public function order_send(array $o): array        { return $this->post('order_send/', $o); }
    public function order(int $id): array              { return $this->post("order/{$id}/"); }
    public function orders(int $page = 1): array       { return $this->post('orders/', ['page' => $page]); }
    public function cancel(int $id): array             { return $this->post("cancel_order/{$id}/"); }
    public function waybill(int $id): array            { return $this->post("waybill/{$id}/"); }
    public function dispatch_code(int $id): array      { return $this->post("dispatch_code/{$id}/"); }
    public function tracking(string $wbn): array       { return $this->post("tracking/{$wbn}/"); }
    public function pickup_hours(string $pc, int $sid): array {
        return $this->post('pickup_hours/', ['postal_code' => $pc, 'service_id' => $sid]);
    }

    // Punkty — z cache plikowym (max 1/24h)
    public function points(string $type): array {
        $type  = strtoupper(preg_replace('/[^A-Z0-9]/', '', $type));
        $cache = rtrim(UPLOAD_DIR, '/') . "/../cache/apaczka_points_{$type}.json";
        $cache = realpath(dirname($cache)) . "/apaczka_points_{$type}.json";
        $dir   = dirname($cache);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if (is_file($cache) && (time() - filemtime($cache)) < 86400) {
            $arr = json_decode(file_get_contents($cache), true);
            if (!empty($arr)) return $arr;
        }
        $resp = $this->post("points/{$type}");
        if (!empty($resp['response']['points'])) {
            file_put_contents($cache, json_encode($resp['response']['points']));
            return $resp['response']['points'];
        }
        return [];
    }

    // ── Budowanie struktury zamówienia ───────────────────────────────────────
    public static function build_order(array $ship): array {
        return [
            'service_id' => (int)$ship['service_id'],
            'address'    => [
                'sender'   => [
                    'country_code'   => $ship['sender_country_code']  ?? 'PL',
                    'name'           => $ship['sender_name']          ?? '',
                    'line1'          => $ship['sender_line1']         ?? '',
                    'line2'          => $ship['sender_line2']         ?? '',
                    'postal_code'    => $ship['sender_postal_code']   ?? '',
                    'city'           => $ship['sender_city']          ?? '',
                    'is_residential' => 0,
                    'contact_person' => $ship['sender_name']          ?? '',
                    'email'          => $ship['sender_email']         ?? '',
                    'phone'          => preg_replace('/\D/', '', $ship['sender_phone'] ?? ''),
                ],
                'receiver' => [
                    'country_code'           => $ship['receiver_country_code']  ?? 'PL',
                    'name'                   => $ship['receiver_name']          ?? '',
                    'line1'                  => $ship['receiver_line1']         ?? '',
                    'line2'                  => $ship['receiver_line2']         ?? '',
                    'postal_code'            => $ship['receiver_postal_code']   ?? '',
                    'city'                   => $ship['receiver_city']          ?? '',
                    'is_residential'         => 1,
                    'contact_person'         => $ship['receiver_name']          ?? '',
                    'email'                  => $ship['receiver_email']         ?? '',
                    'phone'                  => preg_replace('/\D/', '', $ship['receiver_phone'] ?? ''),
                    'foreign_address_id'     => $ship['receiver_point_id']     ?? '',
                    'foreign_address_subtype'=> $ship['receiver_point_type']   ?? '',
                ],
            ],
            'pickup'     => [
                'type'       => $ship['pickup_type']       ?? 'SELF',
                'date'       => $ship['pickup_date']       ?? '',
                'hours_from' => $ship['pickup_hours_from'] ?? '',
                'hours_to'   => $ship['pickup_hours_to']   ?? '',
            ],
            'shipment'   => [[
                'dimension1'       => (int)($ship['dimension1'] ?? 25),
                'dimension2'       => (int)($ship['dimension2'] ?? 20),
                'dimension3'       => (int)($ship['dimension3'] ?? 5),
                'weight'           => (float)($ship['weight'] ?? 0.5),
                'is_nstd'          => 0,
                'shipment_type_code' => 'PACZKA',
            ]],
            'notification' => [
                'sent'      => ['isReceiverEmail' => 1, 'isReceiverSms' => 0, 'isSenderEmail' => 1, 'isSenderSms' => 0],
                'delivered' => ['isReceiverEmail' => 1, 'isReceiverSms' => 0, 'isSenderEmail' => 1, 'isSenderSms' => 0],
                'exception' => ['isReceiverEmail' => 1, 'isReceiverSms' => 0, 'isSenderEmail' => 1, 'isSenderSms' => 0],
            ],
            'content'    => $ship['content']  ?? SHIPMENT_PURPOSE[$ship['purpose'] ?? 'other'] ?? 'Przesyłka',
            'comment'    => $ship['comment']  ?? '',
            'is_zebra'   => 0,
        ];
    }

    // ── Złóż zamówienie i zapisz wynik ───────────────────────────────────────
    public function place_order(int $shipment_id): array {
        $ship = db_one("SELECT * FROM shipments WHERE id=?", [$shipment_id]);
        if (!$ship) throw new RuntimeException('Nie znaleziono przesyłki #' . $shipment_id);

        $order_data = self::build_order($ship);
        $resp       = $this->order_send($order_data);

        if (($resp['status'] ?? 0) !== 200) {
            $msg = $resp['message'] ?? json_encode($resp);
            throw new RuntimeException('Błąd Apaczka: ' . $msg);
        }

        $order         = $resp['response']['order'] ?? [];
        $apaczka_id    = $order['id']             ?? '';
        $waybill_num   = $order['waybill_number'] ?? '';
        $tracking_url  = $order['tracking_url']   ?? '';

        // Pobierz etykietę
        $waybill_path = '';
        if ($apaczka_id) {
            try {
                $wbr = $this->waybill((int)$apaczka_id);
                if (!empty($wbr['response']['waybill'])) {
                    $pdf = base64_decode($wbr['response']['waybill']);
                    $fn  = 'waybill_' . $shipment_id . '_' . time() . '.pdf';
                    file_put_contents(waybill_dir() . $fn, $pdf);
                    $waybill_path = 'waybills/' . $fn;
                }
            } catch (\Throwable $e) {}
        }

        db()->prepare(
            "UPDATE shipments SET
               apaczka_order_id=?, waybill_number=?, tracking_url=?,
               waybill_path=?, status='ordered', ordered_at=datetime('now'), updated_at=datetime('now')
             WHERE id=?"
        )->execute([$apaczka_id, $waybill_num, $tracking_url, $waybill_path, $shipment_id]);

        return ['apaczka_id' => $apaczka_id, 'waybill_number' => $waybill_num,
                'tracking_url' => $tracking_url, 'waybill_path' => $waybill_path];
    }

    // ── Refresh statusu z API ────────────────────────────────────────────────
    public function refresh_status(int $shipment_id): string {
        $ship = db_one("SELECT apaczka_order_id, waybill_number FROM shipments WHERE id=?", [$shipment_id]);
        if (!$ship || !$ship['waybill_number']) return '';

        $resp = $this->tracking($ship['waybill_number']);
        if (($resp['status'] ?? 0) !== 200) return '';

        $events = $resp['response']['data'] ?? [];
        if (!$events) return '';

        $last_status = strtolower($events[0]['status'] ?? '');
        $map = [
            'delivered' => 'delivered',
            'in_transit' => 'in_transit',
            'picked_up'  => 'in_transit',
            'out_for_delivery' => 'in_transit',
        ];
        $new_status = $map[$last_status] ?? '';
        if ($new_status) {
            db()->prepare("UPDATE shipments SET status=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$new_status, $shipment_id]);
        }
        return $new_status;
    }
}

// ── Wysyłka e-mail z etykietą ─────────────────────────────────────────────────
function apaczka_send_waybill_email(array $ship): bool {
    $to    = $ship['receiver_email'] ?? '';
    $wpath = $ship['waybill_path']   ?? '';
    if (!$to || !$wpath) return false;

    $full_path = rtrim(UPLOAD_DIR, '/') . '/' . $wpath;
    if (!is_file($full_path)) return false;

    $purpose = SHIPMENT_PURPOSE[$ship['purpose'] ?? ''] ?? 'Przesyłka';
    $subject = '=?UTF-8?B?' . base64_encode("List przewozowy — {$purpose}") . '?=';
    $org     = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';

    $boundary = 'APACZKA_' . uniqid();
    $pdf_data = base64_encode(file_get_contents($full_path));
    $fname    = 'etykieta_' . ($ship['waybill_number'] ?? $ship['id']) . '.pdf';

    $body  = "Drogi Wolontariuszu,\r\n\r\n";
    $body .= "W załączniku przesyłamy list przewozowy dla Twojej przesyłki.\r\n";
    if (!empty($ship['waybill_number'])) $body .= "Numer przesyłki: " . $ship['waybill_number'] . "\r\n";
    if (!empty($ship['tracking_url']))   $body .= "Śledzenie: "        . $ship['tracking_url']   . "\r\n";
    $body .= "\r\n-- \r\n{$org}\r\n";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: {$org} <" . apaczka_setting('apaczka_sender_email') . ">\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: application/pdf; name=\"{$fname}\"\r\n";
    $message .= "Content-Disposition: attachment; filename=\"{$fname}\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split($pdf_data) . "\r\n";
    $message .= "--{$boundary}--";

    return @mail($to, $subject, $message, $headers);
}
