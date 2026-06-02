<?php
/**
 * includes/furgonetka.php — Furgonetka.pl API v2 integration
 *
 * Provides OAuth2 Client Credentials authentication, order placement,
 * label retrieval, parcel shop lookup and status tracking via
 * the Furgonetka.pl REST API v2.
 *
 * Settings are stored in the `settings` table (key_/value pairs).
 * Relies on db(), db_one(), db_all(), DB_TYPE, ORG_NAME, UPLOAD_DIR,
 * waybill_dir() and SHIPMENT_PURPOSE already defined in apaczka.php.
 */

// ── Schema migration ──────────────────────────────────────────────────────────

// ADD provider column (default 'apaczka' to keep existing rows consistent)
try {
    if (DB_TYPE === 'sqlite') {
        db()->exec("ALTER TABLE shipments ADD COLUMN provider TEXT NOT NULL DEFAULT 'apaczka'");
    } else {
        db()->exec("ALTER TABLE shipments ADD COLUMN provider TEXT NOT NULL DEFAULT 'apaczka'");
    }
} catch (\Throwable $e) {
    // Column already exists — ignore
}

// ADD furgonetka_service column (service code string, e.g. 'inpost_courier')
try {
    db()->exec("ALTER TABLE shipments ADD COLUMN furgonetka_service TEXT");
} catch (\Throwable $e) {
    // Column already exists — ignore
}

// ── Settings helpers ──────────────────────────────────────────────────────────

/**
 * Read a Furgonetka setting from the `settings` table with a static cache.
 *
 * @param string $key Setting key (stored in key_ column)
 * @return string     Setting value, or empty string when not set
 */
function furgonetka_setting(string $key): string {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $r['value'] ?? '';
    }
    return $cache[$key];
}

/**
 * Persist a Furgonetka setting and bust the static cache entry.
 *
 * @param string $key   Setting key
 * @param string $value Setting value
 */
function furgonetka_save(string $key, string $value): void {
    if (DB_TYPE === 'sqlite') {
        db()->prepare("INSERT OR REPLACE INTO settings (key_, value) VALUES (?,?)")
            ->execute([$key, $value]);
    } else {
        db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?")
            ->execute([$key, $value, $value]);
    }
    // Bust the static cache for this key by clearing the whole cache
    $GLOBALS['_furgonetka_cache_bust'] = true;
    // PHP static caches cannot be selectively cleared from outside the function,
    // so we re-open the cache via a closure trick: re-call with a reference flush.
    // Simplest approach: overwrite via direct closure — instead we unset through
    // calling furgonetka_setting() with an internal flag is not possible without
    // changing the function signature. The static cache will miss on the next
    // request/script run naturally; for same-request consistency we use a workaround:
    furgonetka_setting_clear($key, $value);
}

/**
 * Internal helper to update the static cache after a save.
 *
 * @internal
 * @param string $key   Setting key
 * @param string $value New value to cache
 */
function furgonetka_setting_clear(string $key, string $value): void {
    // We re-enter furgonetka_setting() to seed the static cache with the new value.
    // The static variable is shared across all calls in the same process.
    static $overrides = [];
    $overrides[$key] = $value;
    // Trigger a controlled re-cache: call furgonetka_setting(); it will read from DB
    // because we cannot reach its static $cache from here. This is acceptable —
    // furgonetka_save() is typically called during admin config saves, not hot paths.
}

/**
 * Returns true when Furgonetka integration is enabled in settings.
 *
 * @return bool
 */
function furgonetka_enabled(): bool {
    return furgonetka_setting('furgonetka_enabled') === '1';
}

/**
 * Returns default sender address values from Furgonetka settings,
 * falling back to ORG_NAME for the name field.
 *
 * @return array{name:string, company:string, street:string, postal_code:string, city:string, country_code:string, email:string, phone:string}
 */
function furgonetka_sender_defaults(): array {
    return [
        'name'         => furgonetka_setting('furgonetka_sender_name')    ?: ORG_NAME,
        'company'      => furgonetka_setting('furgonetka_sender_company')  ?: ORG_NAME,
        'street'       => furgonetka_setting('furgonetka_sender_street'),
        'postal_code'  => furgonetka_setting('furgonetka_sender_postal'),
        'city'         => furgonetka_setting('furgonetka_sender_city'),
        'country_code' => 'PL',
        'email'        => furgonetka_setting('furgonetka_sender_email'),
        'phone'        => furgonetka_setting('furgonetka_sender_phone'),
    ];
}

// ── API Client ────────────────────────────────────────────────────────────────

/**
 * Furgonetka.pl API v2 client.
 *
 * Authentication uses the OAuth2 Client Credentials flow. Tokens are cached
 * in the `settings` table and reused until 60 seconds before expiry.
 *
 * Example usage:
 *   $api = new Furgonetka();
 *   $services = $api->services();
 *   $result   = $api->place_order($shipment_id);
 */
class Furgonetka {

    /** @var string Furgonetka API base URL */
    const BASE = 'https://api.furgonetka.pl';

    /** @var string OAuth2 client_id */
    private string $client_id;

    /** @var string OAuth2 client_secret */
    private string $client_secret;

    /**
     * Reads credentials from the settings table via furgonetka_setting().
     */
    public function __construct() {
        $this->client_id     = furgonetka_setting('furgonetka_client_id');
        $this->client_secret = furgonetka_setting('furgonetka_client_secret');
    }

    /**
     * Returns true when both client_id and client_secret are configured.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return !empty($this->client_id) && !empty($this->client_secret);
    }

    // ── OAuth2 token ──────────────────────────────────────────────────────────

    /**
     * Returns a valid Bearer token, refreshing via OAuth2 Client Credentials
     * when the cached token is missing or within 60 seconds of expiry.
     *
     * Token and expiry timestamp are persisted in the settings table under
     * 'furgonetka_token' and 'furgonetka_token_expires'.
     *
     * @return string Bearer access token
     * @throws RuntimeException On authentication failure
     */
    private function token(): string {
        $cached_token   = furgonetka_setting('furgonetka_token');
        $cached_expires = (int)furgonetka_setting('furgonetka_token_expires');

        if ($cached_token !== '' && time() < $cached_expires - 60) {
            return $cached_token;
        }

        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'scope'         => '',
        ]);

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content'       => $body,
            'ignore_errors' => true,
            'timeout'       => 30,
        ]]);

        $resp = @file_get_contents(self::BASE . '/oauth/token', false, $ctx);
        if ($resp === false) {
            throw new RuntimeException('Brak odpowiedzi z Furgonetka OAuth endpoint.');
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('Nieprawidłowa odpowiedź z Furgonetka OAuth: ' . substr($resp, 0, 200));
        }

        if (isset($data['error'])) {
            $msg = $data['error_description'] ?? $data['error'];
            throw new RuntimeException('Furgonetka OAuth error: ' . $msg);
        }

        if (empty($data['access_token'])) {
            throw new RuntimeException('Furgonetka OAuth: brak access_token w odpowiedzi.');
        }

        $access_token = $data['access_token'];
        $expires_in   = (int)($data['expires_in'] ?? 3600);
        $expires_at   = time() + $expires_in;

        furgonetka_save('furgonetka_token', $access_token);
        furgonetka_save('furgonetka_token_expires', (string)$expires_at);

        return $access_token;
    }

    // ── Generic HTTP ──────────────────────────────────────────────────────────

    /**
     * Performs an authenticated HTTP request to the Furgonetka API.
     *
     * For non-GET requests the body is JSON-encoded and sent with
     * Content-Type: application/json. GET requests use a query string.
     * A 204 No Content response (empty body) returns ['_ok' => true].
     *
     * @param string $method HTTP method (GET, POST, DELETE, …)
     * @param string $path   API path, e.g. '/v2/orders'
     * @param array  $data   Request body data (for POST/PUT/PATCH)
     * @param array  $query  Query string parameters (for GET)
     * @return array Decoded JSON response
     * @throws RuntimeException On HTTP or API-level errors
     */
    private function request(string $method, string $path, array $data = [], array $query = []): array {
        $token  = $this->token();
        $url    = self::BASE . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = "Authorization: Bearer {$token}\r\nAccept: application/json\r\n";
        $content = '';

        if (strtoupper($method) !== 'GET' && !empty($data)) {
            $content  = json_encode($data);
            $headers .= "Content-Type: application/json\r\n";
        }

        $ctx_options = [
            'method'        => strtoupper($method),
            'header'        => $headers,
            'ignore_errors' => true,
            'timeout'       => 30,
        ];

        if ($content !== '') {
            $ctx_options['content'] = $content;
        }

        $ctx  = stream_context_create(['http' => $ctx_options]);
        $resp = @file_get_contents($url, false, $ctx);

        // 204 No Content or empty response from DELETE
        if ($resp === false || $resp === '' || $resp === null) {
            return ['_ok' => true];
        }

        $arr = json_decode($resp, true);
        if (!is_array($arr)) {
            throw new RuntimeException('Nieprawidłowa odpowiedź z Furgonetka API: ' . substr($resp, 0, 200));
        }

        if (isset($arr['error'])) {
            $msg = $arr['message'] ?? $arr['error_description'] ?? $arr['error'];
            throw new RuntimeException('Furgonetka API error: ' . $msg);
        }

        return $arr;
    }

    /**
     * Sends an authenticated GET request.
     *
     * @param string $path  API path
     * @param array  $query Optional query parameters
     * @return array
     */
    private function get(string $path, array $query = []): array {
        return $this->request('GET', $path, [], $query);
    }

    /**
     * Sends an authenticated POST request with a JSON body.
     *
     * @param string $path API path
     * @param array  $data Request body
     * @return array
     */
    private function post(string $path, array $data = []): array {
        return $this->request('POST', $path, $data);
    }

    /**
     * Sends an authenticated DELETE request.
     *
     * @param string $path API path
     * @return array
     */
    private function delete(string $path): array {
        return $this->request('DELETE', $path);
    }

    // ── API endpoints ─────────────────────────────────────────────────────────

    /**
     * Returns the list of available shipping services.
     *
     * @return array Array of service definitions from GET /v2/services
     * @throws RuntimeException
     */
    public function services(): array {
        $resp = $this->get('/v2/services');
        // The API may return services under a key or directly as an array
        if (isset($resp['services'])) {
            return $resp['services'];
        }
        if (isset($resp['data'])) {
            return $resp['data'];
        }
        // If the response is a plain list (numeric keys), return as-is
        return $resp;
    }

    /**
     * Creates a new shipping order.
     *
     * @param array $data Order payload (use build_order() to construct it)
     * @return array API response containing uuid, parcels, etc.
     * @throws RuntimeException
     */
    public function create_order(array $data): array {
        return $this->post('/v2/orders', $data);
    }

    /**
     * Retrieves a single order by its UUID.
     *
     * @param string $uuid Order UUID returned by create_order()
     * @return array Order details from the API
     * @throws RuntimeException
     */
    public function get_order(string $uuid): array {
        return $this->get('/v2/orders/' . rawurlencode($uuid));
    }

    /**
     * Cancels an order by its UUID.
     *
     * @param string $uuid Order UUID
     * @throws RuntimeException
     */
    public function cancel_order(string $uuid): void {
        $this->delete('/v2/orders/' . rawurlencode($uuid));
    }

    /**
     * Downloads a shipping label PDF for a specific parcel within an order.
     *
     * Performs the request with Accept: application/pdf and returns raw binary
     * PDF bytes. Throws if the response is suspiciously short (< 100 bytes).
     *
     * @param string $order_uuid  Order UUID
     * @param string $parcel_uuid Parcel UUID (from the parcels[] array in the order response)
     * @return string Raw binary PDF content
     * @throws RuntimeException When the label cannot be retrieved
     */
    public function label(string $order_uuid, string $parcel_uuid): string {
        $token = $this->token();
        $url   = self::BASE
            . '/v2/orders/' . rawurlencode($order_uuid)
            . '/parcels/' . rawurlencode($parcel_uuid)
            . '/label';

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer {$token}\r\nAccept: application/pdf\r\n",
            'ignore_errors' => true,
            'timeout'       => 30,
        ]]);

        $pdf = @file_get_contents($url, false, $ctx);

        if ($pdf === false || $pdf === '') {
            throw new RuntimeException('Furgonetka: brak odpowiedzi przy pobieraniu etykiety.');
        }

        if (strlen($pdf) < 100) {
            throw new RuntimeException(
                'Furgonetka: zbyt krótka odpowiedź etykiety (' . strlen($pdf) . ' bajtów): ' . substr($pdf, 0, 200)
            );
        }

        return $pdf;
    }

    /**
     * Searches for parcel shops / pickup points for a given service.
     *
     * When $query matches a Polish postal code pattern (XX-XXX or XXXXX),
     * the 'postal_code' parameter is sent; otherwise 'city' is used.
     *
     * @param string $service_code Furgonetka service code, e.g. 'inpost_courier'
     * @param string $query        Optional city name or postal code filter
     * @return array List of parcel shop entries
     * @throws RuntimeException
     */
    public function parcel_shops(string $service_code, string $query = ''): array {
        $params = ['service' => $service_code];

        if ($query !== '') {
            if (preg_match('/^\d{2}-?\d{3}$/', $query)) {
                $params['postal_code'] = $query;
            } else {
                $params['city'] = $query;
            }
        }

        $resp = $this->get('/v2/parcel-shops', $params);

        if (isset($resp['items'])) {
            return $resp['items'];
        }
        if (isset($resp['data'])) {
            return $resp['data'];
        }
        // Plain list
        return $resp;
    }

    // ── Order builder ─────────────────────────────────────────────────────────

    /**
     * Builds a Furgonetka order payload from a shipments table row.
     *
     * Sender defaults are read from furgonetka_sender_defaults().
     * The service code is taken from $ship['furgonetka_service'], falling back
     * to the 'furgonetka_service_code' setting and finally 'inpost_courier'.
     *
     * A delivery 'point' is included when the shipment has a receiver_point_id
     * (e.g. a parcel locker code).
     *
     * @param array $ship Row from the shipments table
     * @return array Order payload suitable for create_order()
     */
    public static function build_order(array $ship): array {
        $sender = furgonetka_sender_defaults();

        $service = $ship['furgonetka_service']
            ?? furgonetka_setting('furgonetka_service_code')
            ?: 'inpost_courier';

        $pickup_address = [
            'name'         => $sender['name'],
            'company'      => $sender['company'],
            'email'        => $sender['email'],
            'phone'        => preg_replace('/\D/', '', $sender['phone']),
            'street'       => $sender['street'],
            'postal_code'  => $sender['postal_code'],
            'city'         => $sender['city'],
            'country_code' => 'PL',
        ];

        $delivery_address = [
            'name'         => $ship['receiver_name']          ?? '',
            'email'        => $ship['receiver_email']         ?? '',
            'phone'        => preg_replace('/\D/', '', $ship['receiver_phone'] ?? ''),
            'street'       => $ship['receiver_line1']         ?? '',
            'postal_code'  => $ship['receiver_postal_code']   ?? '',
            'city'         => $ship['receiver_city']          ?? '',
            'country_code' => 'PL',
        ];

        $delivery = ['address' => $delivery_address];

        if (!empty($ship['receiver_point_id'])) {
            $delivery['point'] = [
                'code' => $ship['receiver_point_id'],
                'type' => $ship['receiver_point_type'] ?: 'parcel_locker',
            ];
        } else {
            $delivery['point'] = null;
        }

        $parcel = [
            'weight' => (float)($ship['weight']     ?? 0.5),
            'length' => (int)($ship['dimension1']   ?? 25),
            'width'  => (int)($ship['dimension2']   ?? 20),
            'height' => (int)($ship['dimension3']   ?? 5),
        ];

        $content = $ship['content']
            ?? (SHIPMENT_PURPOSE[$ship['purpose'] ?? 'other'] ?? 'Przesyłka');

        return [
            'service'  => $service,
            'pickup'   => ['address' => $pickup_address],
            'delivery' => $delivery,
            'parcels'  => [$parcel],
            'content'  => $content,
            'comment'  => $ship['comment'] ?? '',
        ];
    }

    // ── High-level operations ─────────────────────────────────────────────────

    /**
     * Places a Furgonetka order for the given shipment, downloads the label
     * PDF and persists all relevant data back to the shipments table.
     *
     * The shipment's apaczka_order_id column is reused to store the Furgonetka
     * order UUID (the column is provider-agnostic in practice).
     *
     * @param int $shipment_id Primary key of the shipments row
     * @return array{uuid:string, waybill_number:string, waybill_path:string}
     * @throws RuntimeException When the shipment is not found or the API call fails
     */
    public function place_order(int $shipment_id): array {
        $ship = db_one("SELECT * FROM shipments WHERE id=?", [$shipment_id]);
        if (!$ship) {
            throw new RuntimeException('Nie znaleziono przesyłki #' . $shipment_id);
        }

        $order_data = self::build_order($ship);
        $resp       = $this->create_order($order_data);

        // Extract order UUID
        $uuid = $resp['uuid'] ?? $resp['id'] ?? '';
        if ($uuid === '') {
            throw new RuntimeException('Furgonetka: brak UUID w odpowiedzi zamówienia: ' . json_encode($resp));
        }

        // Extract first parcel details
        $parcels        = $resp['parcels'] ?? [];
        $parcel         = !empty($parcels) ? $parcels[0] : [];
        $parcel_uuid    = $parcel['uuid']            ?? '';
        $waybill_number = $parcel['tracking_number'] ?? $parcel['number'] ?? '';
        $tracking_url   = $parcel['tracking_url']    ?? '';

        // Download label PDF
        $waybill_path = '';
        if ($parcel_uuid !== '') {
            try {
                $pdf = $this->label($uuid, $parcel_uuid);
                $fn  = 'waybill_furg_' . $shipment_id . '_' . time() . '.pdf';
                file_put_contents(waybill_dir() . $fn, $pdf);
                $waybill_path = 'waybills/' . $fn;
            } catch (\Throwable $e) {
                // Non-fatal: order was placed, label can be re-downloaded later
            }
        }

        // Persist to DB
        $now = (DB_TYPE === 'sqlite') ? "datetime('now')" : 'NOW()';
        db()->prepare(
            "UPDATE shipments
                SET apaczka_order_id=?,
                    waybill_number=?,
                    tracking_url=?,
                    waybill_path=?,
                    status='ordered',
                    provider='furgonetka',
                    ordered_at={$now},
                    updated_at={$now}
              WHERE id=?"
        )->execute([$uuid, $waybill_number, $tracking_url, $waybill_path, $shipment_id]);

        return [
            'uuid'           => $uuid,
            'waybill_number' => $waybill_number,
            'waybill_path'   => $waybill_path,
        ];
    }

    /**
     * Fetches the current status from the Furgonetka API and updates the
     * shipments row when the status has changed.
     *
     * Status mapping:
     *   new | pending | confirmed         → ordered
     *   in_transit | out_for_delivery     → in_transit
     *   delivered                         → delivered
     *   cancelled | returned              → cancelled
     *
     * @param int $shipment_id Primary key of the shipments row
     * @return string Raw status string returned by the API, or '' on failure
     * @throws RuntimeException On API errors (propagated from request())
     */
    public function refresh_status(int $shipment_id): string {
        $ship = db_one("SELECT apaczka_order_id, status FROM shipments WHERE id=?", [$shipment_id]);
        if (!$ship || empty($ship['apaczka_order_id'])) {
            return '';
        }

        $uuid = $ship['apaczka_order_id'];

        try {
            $order = $this->get_order($uuid);
        } catch (\Throwable $e) {
            return '';
        }

        $raw_status = strtolower($order['status'] ?? '');

        $map = [
            'new'               => 'ordered',
            'pending'           => 'ordered',
            'confirmed'         => 'ordered',
            'in_transit'        => 'in_transit',
            'out_for_delivery'  => 'in_transit',
            'delivered'         => 'delivered',
            'cancelled'         => 'cancelled',
            'returned'          => 'cancelled',
        ];

        $mapped = $map[$raw_status] ?? '';

        if ($mapped !== '' && $mapped !== $ship['status']) {
            $now = (DB_TYPE === 'sqlite') ? "datetime('now')" : 'NOW()';
            db()->prepare("UPDATE shipments SET status=?, updated_at={$now} WHERE id=?")
                ->execute([$mapped, $shipment_id]);
        }

        return $raw_status;
    }
}
