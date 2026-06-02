<?php
/**
 * Integracja z Postivo.pl — wysyłka fizycznych listów pocztą.
 *
 * TODO: Gdy otrzymasz dostęp do API Postivo.pl, zaktualizuj:
 *   - Endpointy w PostivoClient::request()
 *   - Strukturę payloadu w PostivoClient::send_letter()
 *   - Strukturę odpowiedzi w PostivoClient::get_status() i get_price()
 *   - Dokumentacja API (spekulatywny URL): https://postivo.pl/api-docs
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
    private string $base_url = 'https://api.postivo.pl/v1';

    public function __construct()
    {
        $this->api_key = postivo_setting('postivo_api_key');
    }

    /**
     * Sprawdza czy klucz API jest skonfigurowany.
     */
    public function is_configured(): bool
    {
        return $this->api_key !== '';
    }

    /**
     * Tworzy zlecenie wysyłki listu przez Postivo.pl.
     *
     * @param array $params Wymagane klucze:
     *   - recipient_name  (string)
     *   - address_line1   (string)
     *   - address_line2   (string, opcjonalny)
     *   - city            (string)
     *   - postcode        (string)
     *   - country         (string, domyślnie 'PL')
     *   - pdf_path        (string, lokalna ścieżka do pliku PDF)
     *
     * @return array Tablica z kluczem 'id' (identyfikator zlecenia w Postivo)
     * @throws RuntimeException przy błędzie API lub braku konfiguracji
     *
     * TODO: Dostosuj endpoint i strukturę payloadu po uzyskaniu dokumentacji API Postivo.pl
     * TODO: Postivo.pl API docs: https://postivo.pl/api-docs (spekulatywny URL)
     */
    public function send_letter(array $params): array
    {
        if (!$this->is_configured()) {
            throw new RuntimeException(
                'Brak klucza API Postivo.pl. Skonfiguruj w: Administracja → Postivo (poczta).'
            );
        }

        $pdf_path = $params['pdf_path'] ?? '';
        if (!$pdf_path || !file_exists($pdf_path)) {
            throw new RuntimeException('Plik PDF nie istnieje: ' . $pdf_path);
        }

        // TODO: Replace with actual Postivo.pl API endpoint and params when docs are available
        // TODO: Postivo.pl API docs: https://postivo.pl/api-docs (speculative URL)
        // TODO: Verify field names — 'recipient', 'content', 'sender', 'options' are speculative
        $payload = [
            'recipient' => [
                'name'     => $params['recipient_name'],
                'address1' => $params['address_line1'],
                'address2' => $params['address_line2'] ?? '',
                'city'     => $params['city'],
                'postcode' => $params['postcode'],
                'country'  => $params['country'] ?? 'PL',
            ],
            'content'   => [
                // TODO: Confirm whether Postivo expects 'pdf_base64', 'pdf_url', or another format
                'type' => 'pdf_base64',
                'data' => base64_encode(file_get_contents($pdf_path)),
            ],
            'sender'    => [
                'name'    => postivo_setting('postivo_sender_name'),
                'address' => postivo_setting('postivo_return_address'),
            ],
            // TODO: Confirm option names — 'registered' (list polecony), 'color' (druk kolorowy)
            'options'   => ['registered' => true, 'color' => false],
        ];

        // TODO: Confirm POST /letters endpoint path in Postivo.pl API
        $result = $this->request('POST', '/letters', $payload);

        // TODO: Adjust key name — Postivo may return 'id', 'letter_id', 'job_id', etc.
        if (empty($result['id'])) {
            throw new RuntimeException(
                'Postivo.pl nie zwróciło identyfikatora zlecenia. Odpowiedź: ' . json_encode($result)
            );
        }

        return $result;
    }

    /**
     * Pobiera status zlecenia wysyłki z Postivo.pl.
     *
     * @param string $postivo_id Identyfikator zlecenia w Postivo
     * @return array Tablica z kluczami: status, tracking, updated_at
     *
     * TODO: Dostosuj endpoint i klucze odpowiedzi po uzyskaniu dokumentacji API Postivo.pl
     */
    public function get_status(string $postivo_id): array
    {
        if (!$this->is_configured()) {
            return [
                'status'     => 'unknown',
                'tracking'   => '',
                'updated_at' => '',
            ];
        }

        // TODO: Confirm GET /letters/{id} endpoint in Postivo.pl API
        $result = $this->request('GET', '/letters/' . urlencode($postivo_id));

        return [
            // TODO: Adjust key names to match actual Postivo.pl API response
            // Possible statuses: draft, processing, sent, delivered, failed
            'status'     => $result['status']     ?? 'unknown',
            'tracking'   => $result['tracking']   ?? $result['tracking_number'] ?? '',
            'updated_at' => $result['updated_at'] ?? $result['modified_at']     ?? '',
        ];
    }

    /**
     * Pobiera szacowaną cenę wysyłki listu.
     *
     * @param string $postivo_id Identyfikator zlecenia w Postivo
     * @return float|null Cena w PLN lub null jeśli niedostępna
     *
     * TODO: Dostosuj endpoint i klucze odpowiedzi po uzyskaniu dokumentacji API Postivo.pl
     */
    public function get_price(string $postivo_id): ?float
    {
        if (!$this->is_configured()) {
            return null;
        }

        try {
            // TODO: Confirm GET /letters/{id}/price endpoint in Postivo.pl API
            $result = $this->request('GET', '/letters/' . urlencode($postivo_id) . '/price');
            // TODO: Adjust key name — may be 'price', 'amount', 'total', etc.
            return isset($result['price']) ? (float)$result['price'] : null;
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /**
     * Wykonuje żądanie HTTP do API Postivo.pl.
     *
     * @param string $method GET lub POST
     * @param string $path   Ścieżka API (np. '/letters')
     * @param array  $data   Dane do wysłania (dla POST)
     * @return array Zdekodowana odpowiedź JSON
     * @throws RuntimeException przy błędzie HTTP lub cURL
     *
     * TODO: Dostosuj nagłówki autoryzacji, Content-Type i obsługę błędów
     *       po uzyskaniu dokumentacji API Postivo.pl
     */
    private function request(string $method, string $path, array $data = []): array
    {
        $url = $this->base_url . $path;

        // TODO: Postivo.pl może wymagać innego nagłówka autoryzacji
        //       np. 'X-API-Key', 'Api-Key', 'Authorization: Basic ...', itp.
        $headers = [
            'Authorization: Bearer ' . $this->api_key,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init($url);
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

        // TODO: Adjust error detection — Postivo may use different HTTP codes or error fields
        if ($http_code < 200 || $http_code >= 300) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? $decoded['detail'] ?? 'HTTP ' . $http_code;
            throw new RuntimeException('Błąd API Postivo.pl: ' . $msg);
        }

        return $decoded;
    }
}
