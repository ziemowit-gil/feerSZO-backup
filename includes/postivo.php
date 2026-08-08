<?php
/**
 * Integracja z Postivo.pl — wysyłka fizycznych listów pocztą.
 * API: https://api.postivo.pl/rest/v1/
 * Auth: Bearer token, generowany w ustawieniach konta Postivo.
 */

function postivo_setting(string $key): string {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $r['value'] ?? '';
    }
    return $cache[$key];
}

class PostivoClient
{
    private string $api_key;
    private string $base_url = 'https://api.postivo.pl/rest/v1';

    public function __construct()
    {
        $this->api_key = postivo_setting('postivo_api_key');
    }

    public function is_configured(): bool
    {
        return $this->api_key !== '';
    }

    /**
     * Testuje połączenie — GET /account. Zwraca saldo konta przy sukcesie.
     *
     * @return array ['ok' => bool, 'msg' => string, 'balance' => float|null]
     */
    public function ping(): array
    {
        if (!$this->is_configured()) {
            return ['ok' => false, 'msg' => 'Brak klucza API.', 'balance' => null];
        }
        try {
            $result  = $this->request('GET', '/account');
            $balance = isset($result['balance']) ? (float)$result['balance'] : null;
            $msg     = 'Połączenie nawiązane pomyślnie.';
            if ($balance !== null) {
                $msg .= ' Saldo konta: ' . number_format($balance, 2, ',', ' ') . ' zł.';
            }
            return ['ok' => true, 'msg' => $msg, 'balance' => $balance];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'msg' => $e->getMessage(), 'balance' => null];
        }
    }

    /**
     * Tworzy zlecenie wysyłki listu — POST /shipment.
     *
     * @param array $params Wymagane:
     *   recipient_name (string), address_line1 (string), city (string), postcode (string)
     *   Opcjonalne: address_line2 (string), country (string, domyślnie 'PL'), pdf_path (string)
     *
     * @return array ['id' => string]
     * @throws RuntimeException
     */
    public function send_letter(array $params): array
    {
        if (!$this->is_configured()) {
            throw new RuntimeException(
                'Brak klucza API Postivo.pl. Skonfiguruj w: Administracja → Postivo (poczta).'
            );
        }

        $config_id = (int)postivo_setting('postivo_config_id');
        if (!$config_id) {
            throw new RuntimeException(
                'Brak ID konfiguracji Postivo.pl. Ustaw "ID konfiguracji" w: Administracja → Postivo (poczta).'
            );
        }

        $pdf_path = $params['pdf_path'] ?? '';
        if (!$pdf_path || !file_exists($pdf_path)) {
            throw new RuntimeException('Plik PDF nie istnieje: ' . $pdf_path);
        }

        $recipient = [
            'name'      => $params['recipient_name'],
            'address'   => $params['address_line1'],
            'post_code' => $params['postcode'],
            'city'      => $params['city'],
            'country'   => $params['country'] ?? 'PL',
        ];
        if (!empty($params['address_line2'])) {
            $recipient['name2'] = $params['address_line2'];
        }

        $payload = [
            'recipients' => $recipient,
            'documents'  => [[
                'file_stream' => base64_encode(file_get_contents($pdf_path)),
                'file_name'   => basename($pdf_path),
            ]],
            'options' => [
                'predefined_config_id' => $config_id,
            ],
        ];

        $result   = $this->request('POST', '/shipment', $payload);
        $shipment = $result[0] ?? $result;
        $id       = $shipment['id'] ?? '';

        if (!$id) {
            throw new RuntimeException(
                'Postivo.pl nie zwróciło ID zlecenia. Odpowiedź: ' . json_encode($result)
            );
        }

        return ['id' => $id];
    }

    /**
     * Pobiera status zlecenia — GET /shipment/{id}.
     *
     * @param  string $postivo_id
     * @return array ['status' => string, 'tracking' => string, 'updated_at' => string]
     * @throws RuntimeException
     */
    public function get_status(string $postivo_id): array
    {
        $result = $this->request('GET', '/shipment/' . urlencode($postivo_id));

        // Response: [{ shipment_details: {...}, status_events: [...] }]
        $item   = $result[0] ?? $result;
        $detail = $item['shipment_details'] ?? $item;
        $code   = strtoupper($detail['status']['code'] ?? '');

        $status = match($code) {
            'ACCEPTED'   => 'processing',
            'PROCESSING' => 'processing',
            'SENT'       => 'sent',
            'DELIVERED'  => 'delivered',
            'FAILED'     => 'failed',
            default      => strtolower($code) ?: 'unknown',
        };

        return [
            'status'     => $status,
            'tracking'   => $detail['tracking_number']  ?? '',
            'updated_at' => $detail['status']['date']   ?? '',
        ];
    }

    /**
     * Sprawdza szacowaną cenę wysyłki — POST /shipment/price.
     *
     * @param  array $params — tak samo jak send_letter()
     * @return float|null Cena w PLN lub null przy błędzie
     */
    public function get_price(array $params): ?float
    {
        if (!$this->is_configured()) {
            return null;
        }

        $config_id = (int)postivo_setting('postivo_config_id');
        $pdf_path  = $params['pdf_path'] ?? '';
        if (!$config_id || !$pdf_path || !file_exists($pdf_path)) {
            return null;
        }

        try {
            $recipient = [
                'name'      => $params['recipient_name'] ?? 'Test',
                'address'   => $params['address_line1']  ?? '',
                'post_code' => $params['postcode']        ?? '',
                'city'      => $params['city']            ?? '',
                'country'   => $params['country']         ?? 'PL',
            ];

            $payload = [
                'recipients' => $recipient,
                'documents'  => [[
                    'file_stream' => base64_encode(file_get_contents($pdf_path)),
                    'file_name'   => basename($pdf_path),
                ]],
                'options' => [
                    'predefined_config_id' => $config_id,
                ],
            ];

            $result = $this->request('POST', '/shipment/price', $payload);
            $item   = $result[0] ?? $result;
            return isset($item['price']) ? (float)$item['price'] : null;
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /**
     * Wykonuje żądanie HTTP do API Postivo.pl.
     *
     * @throws RuntimeException przy błędzie cURL lub odpowiedzi HTTP 4xx/5xx
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $url     = $this->base_url . $path;
        $headers = [
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        } elseif ($method !== 'GET') {
            $opts[CURLOPT_CUSTOMREQUEST] = $method;
            if ($data) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($data);
            }
        }

        curl_setopt_array($ch, $opts);
        $body      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curl_err) {
            throw new RuntimeException('Błąd połączenia z Postivo.pl: ' . $curl_err);
        }

        $decoded = json_decode($body, true);
        if ($decoded === null) {
            throw new RuntimeException(
                'Postivo.pl zwróciło nieprawidłowy JSON (HTTP ' . $http_code . '): ' . substr($body, 0, 200)
            );
        }

        if ($http_code < 200 || $http_code >= 300) {
            // RFC 9457 error format: detail > title > message > fallback
            $msg = $decoded['detail'] ?? $decoded['title'] ?? $decoded['message'] ?? ('HTTP ' . $http_code);
            throw new RuntimeException('Błąd API Postivo.pl: ' . $msg);
        }

        return $decoded;
    }
}
