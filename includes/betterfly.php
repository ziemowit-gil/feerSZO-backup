<?php
/**
 * includes/betterfly.php — klient API Comarch Betterfly.
 *
 * Cienki, obiektowy klient HTTP (cURL) do REST API Comarch Betterfly.
 * Warstwa biznesowa (mapowanie CRM/TI → faktura, rozliczanie per kurs,
 * synchronizacja statusów) mieszka w includes/betterfly_invoices.php.
 *
 * ── Autoryzacja (OAuth 2.0, grant_type=client_credentials) ──────────────────
 *   [POST] {base}/api2/public/token
 *   Nagłówki: Authorization: Basic base64(client_id:client_secret)
 *             Content-Type: application/x-www-form-urlencoded
 *   Body:     grant_type=client_credentials
 *   Odpowiedź: { "access_token": "...", "token_type": "bearer", "expires": 600 }
 *   Token jest ważny 600 s (10 min) — cache'ujemy go w tabeli settings, żeby
 *   nie prosić o nowy przy każdym żądaniu, i odświeżamy z 60 s zapasem.
 *   Kolejne żądania: Authorization: Bearer {access_token}
 *
 * ── Zasoby wykorzystywane przez integrację ──────────────────────────────────
 *   Kontrahenci:  {base}/api2/public/v1.2/customers        (GET ?nip=, POST, DELETE)
 *   Faktury sprz.:{base}/api2/public/v1.7/invoices          (GET, POST, PUT)
 *   Zatwierdzenie:{base}/api2/public/v1.7/invoices/confirm  (PUT)
 *
 * ── Konfiguracja (tabela settings, prefiks betterfly_*) ─────────────────────
 *   betterfly_enabled            '1' = integracja aktywna
 *   betterfly_base_url           domyślnie https://app.comarchbetterfly.pl
 *   betterfly_client_id          Client ID z Betterfly (Moje konto → Zarządzanie kontem)
 *   betterfly_client_secret      Client Secret
 *   betterfly_api_ver_customers  domyślnie v1.2
 *   betterfly_api_ver_invoices   domyślnie v1.7
 *   betterfly_timeout            domyślnie 20 (s)
 *   betterfly_token              (runtime) zbuforowany access_token
 *   betterfly_token_expires      (runtime) unix timestamp wygaśnięcia tokenu
 *
 * Wzorowane na includes/nozbe.php (klasowy klient + settings) oraz
 * includes/s3_client.php (wzorzec cURL). Logowanie: error_log('[betterfly] ...').
 */

require_once __DIR__ . '/db.php';

/** Błąd komunikacji / walidacji po stronie API Betterfly. */
class BetterFlyException extends \RuntimeException
{
    /** Kod HTTP odpowiedzi (0 gdy błąd połączenia/transportu). */
    public int $httpStatus;
    /** Zdekodowane ciało odpowiedzi (o ile było JSON-em). */
    public ?array $responseBody;

    public function __construct(string $message, int $httpStatus = 0, ?array $responseBody = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus   = $httpStatus;
        $this->responseBody = $responseBody;
    }
}

class BetterFlyClient
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private int    $timeout;
    private string $verCustomers;
    private string $verInvoices;

    /** Cache tokenu w pamięci procesu (obok cache w tabeli settings). */
    private ?string $token = null;
    private int     $tokenExpiresAt = 0;

    /** Gdy true, token jest współdzielony między żądaniami przez tabelę settings. */
    private bool $persistToken;

    /** Opcjonalny logger: callable(string $message): void. */
    private $logger;

    /**
     * @param array{
     *   base_url?:string, client_id:string, client_secret:string,
     *   timeout?:int, ver_customers?:string, ver_invoices?:string,
     *   persist_token?:bool, logger?:callable
     * } $cfg
     */
    public function __construct(array $cfg)
    {
        $this->baseUrl      = rtrim($cfg['base_url'] ?? 'https://app.comarchbetterfly.pl', '/');
        $this->clientId     = trim((string)($cfg['client_id'] ?? ''));
        $this->clientSecret = trim((string)($cfg['client_secret'] ?? ''));
        $this->timeout      = (int)($cfg['timeout'] ?? 20) ?: 20;
        $this->verCustomers = trim((string)($cfg['ver_customers'] ?? 'v1.2')) ?: 'v1.2';
        $this->verInvoices  = trim((string)($cfg['ver_invoices'] ?? 'v1.7')) ?: 'v1.7';
        $this->persistToken = (bool)($cfg['persist_token'] ?? true);
        $this->logger       = $cfg['logger'] ?? null;

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new BetterFlyException('Brak client_id / client_secret dla API Betterfly.');
        }
    }

    /**
     * Fabryka czytająca konfigurację z tabeli settings (org_setting).
     * Rzuca BetterFlyException, gdy integracja nie jest skonfigurowana.
     */
    public static function fromSettings(): self
    {
        return new self([
            'base_url'      => org_setting('betterfly_base_url') ?: 'https://app.comarchbetterfly.pl',
            'client_id'     => org_setting('betterfly_client_id'),
            'client_secret' => org_setting('betterfly_client_secret'),
            'timeout'       => (int)(org_setting('betterfly_timeout') ?: 20),
            'ver_customers' => org_setting('betterfly_api_ver_customers') ?: 'v1.2',
            'ver_invoices'  => org_setting('betterfly_api_ver_invoices') ?: 'v1.7',
            'persist_token' => true,
        ]);
    }

    /** Czy integracja jest aktywna (flaga admina). */
    public static function isEnabled(): bool
    {
        return org_setting('betterfly_enabled') === '1';
    }

    // ── Autoryzacja ─────────────────────────────────────────────────────────

    /**
     * Zwraca ważny access_token (odświeża w razie potrzeby).
     * Kolejność: cache w pamięci → cache w settings → nowe żądanie o token.
     */
    public function getToken(): string
    {
        $now = time();

        if ($this->token !== null && $this->tokenExpiresAt > $now + 60) {
            return $this->token;
        }

        if ($this->persistToken) {
            $cached  = org_setting('betterfly_token');
            $expires = (int)org_setting('betterfly_token_expires');
            if ($cached !== '' && $expires > $now + 60) {
                $this->token          = $cached;
                $this->tokenExpiresAt = $expires;
                return $this->token;
            }
        }

        return $this->authorize();
    }

    /** Wykonuje żądanie o nowy token OAuth i cache'uje go. */
    private function authorize(): string
    {
        $url  = $this->baseUrl . '/api2/public/token';
        $body = http_build_query(['grant_type' => 'client_credentials']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log('Błąd połączenia przy autoryzacji: ' . $err);
            throw new BetterFlyException('Błąd połączenia z API Betterfly (token): ' . $err);
        }

        $data = json_decode((string)$raw, true);
        if ($http < 200 || $http >= 300 || !is_array($data) || empty($data['access_token'])) {
            $this->log("Autoryzacja nieudana (HTTP {$http}): " . substr((string)$raw, 0, 500));
            throw new BetterFlyException(
                'Autoryzacja w API Betterfly nie powiodła się (HTTP ' . $http . ').',
                $http,
                is_array($data) ? $data : null
            );
        }

        $this->token          = (string)$data['access_token'];
        $this->tokenExpiresAt = time() + (int)($data['expires'] ?? 600);

        if ($this->persistToken) {
            org_setting_set('betterfly_token', $this->token);
            org_setting_set('betterfly_token_expires', (string)$this->tokenExpiresAt);
        }

        return $this->token;
    }

    // ── Rdzeń HTTP ──────────────────────────────────────────────────────────

    /**
     * Wykonuje żądanie do API. Ponawia raz po odświeżeniu tokenu przy 401.
     *
     * @param string     $method GET|POST|PUT|DELETE
     * @param string     $path   pełna ścieżka względem base (np. /api2/public/v1.7/invoices)
     * @param array|null $body   ciało żądania (zostanie zserializowane do JSON)
     * @param array      $query  parametry query string
     * @return mixed Zdekodowany JSON (tablica lub skalar, np. samo Id) albo null (204 No Content).
     */
    private function request(string $method, string $path, ?array $body = null, array $query = [], bool $isRetry = false)
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->getToken(),
            'Accept: application/json',
        ];
        $payload = null;
        if ($body !== null) {
            $payload    = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[]  = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log("Błąd połączenia {$method} {$path}: {$err}");
            throw new BetterFlyException("Błąd połączenia z API Betterfly ({$method} {$path}): {$err}");
        }

        // Token wygasł mimo zapasu — odśwież i ponów raz.
        if ($http === 401 && !$isRetry) {
            $this->log('Otrzymano 401 — odświeżam token i ponawiam żądanie.');
            $this->token          = null;
            $this->tokenExpiresAt = 0;
            if ($this->persistToken) {
                org_setting_set('betterfly_token', '');
                org_setting_set('betterfly_token_expires', '0');
            }
            return $this->request($method, $path, $body, $query, true);
        }

        $data = ($raw === '' ) ? null : json_decode((string)$raw, true);

        if ($http < 200 || $http >= 300) {
            $msg = $this->extractError($data) ?: ('HTTP ' . $http);
            $this->log("Błąd API {$method} {$path} (HTTP {$http}): " . substr((string)$raw, 0, 800));
            throw new BetterFlyException(
                "Żądanie do API Betterfly nie powiodło się ({$method} {$path}): {$msg}",
                $http,
                is_array($data) ? $data : null
            );
        }

        return $data; // tablica, skalar (np. samo Id) lub null (puste ciało)
    }

    /** Wyciąga czytelny komunikat błędu z odpowiedzi API (różne konwencje pól). */
    private function extractError($data): string
    {
        if (!is_array($data)) return '';
        foreach (['Message', 'message', 'error_description', 'error', 'Error'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) return $data[$k];
        }
        // Betterfly potrafi zwracać tablicę błędów walidacji.
        if (!empty($data['ModelState']) && is_array($data['ModelState'])) {
            $msgs = [];
            foreach ($data['ModelState'] as $field => $errs) {
                $msgs[] = $field . ': ' . implode('; ', (array)$errs);
            }
            return implode(' | ', $msgs);
        }
        return '';
    }

    private function log(string $message): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($message);
        } else {
            error_log('[betterfly] ' . $message);
        }
    }

    // ── Kontrahenci (customers) ───────────────────────────────────────────────

    /**
     * Szuka kontrahenta po NIP. Zwraca pierwsze dopasowanie albo null.
     * @return array|null rekord kontrahenta z polem 'Id'
     */
    public function findCustomerByNip(string $nip): ?array
    {
        $nip = preg_replace('/\D+/', '', $nip) ?? '';
        if ($nip === '') return null;

        $res = $this->request('GET', '/api2/public/' . $this->verCustomers . '/customers/', null, ['nip' => $nip]);
        if (is_array($res) && isset($res[0]) && is_array($res[0])) {
            return $res[0];
        }
        return null;
    }

    /**
     * Tworzy kontrahenta. Zwraca jego Id.
     * @param array $data pola zgodne z API (Name wymagane; CustomerTaxNumber, Mail,
     *              CustomerType 0=os. fizyczna/1=firma, Address{Street,BuildingNumber,
     *              FlatNumber,PostalCode,City}, CountryCode, PhoneNumber, ...)
     */
    public function createCustomer(array $data): int
    {
        if (trim((string)($data['Name'] ?? '')) === '') {
            throw new BetterFlyException('Kontrahent wymaga pola Name.');
        }
        $res = $this->request('POST', '/api2/public/' . $this->verCustomers . '/customers', $data);
        $id  = $this->extractId($res);
        if ($id <= 0) {
            // Fallback: dociągnij po NIP, jeśli API zwróciło obiekt bez czytelnego Id.
            if (!empty($data['CustomerTaxNumber'])) {
                $found = $this->findCustomerByNip((string)$data['CustomerTaxNumber']);
                if ($found && isset($found['Id'])) return (int)$found['Id'];
            }
            throw new BetterFlyException('API nie zwróciło Id utworzonego kontrahenta.', 0, is_array($res) ? $res : null);
        }
        return $id;
    }

    /**
     * Zapewnia istnienie kontrahenta: znajduje po NIP lub tworzy nowego.
     * Zwraca PurchasingPartyId do użycia na fakturze.
     *
     * @param array $buyer znormalizowane dane nabywcy (patrz betterfly_customer_payload)
     */
    public function ensureCustomer(array $buyer): int
    {
        $nip = preg_replace('/\D+/', '', (string)($buyer['CustomerTaxNumber'] ?? '')) ?? '';
        if ($nip !== '') {
            $found = $this->findCustomerByNip($nip);
            if ($found && isset($found['Id'])) {
                return (int)$found['Id'];
            }
        }
        // Bez NIP (osoba fizyczna) nie da się deduplikować po stronie API — tworzymy nowego.
        return $this->createCustomer($buyer);
    }

    // ── Faktury sprzedaży (invoices) ──────────────────────────────────────────

    /**
     * Tworzy fakturę sprzedaży (w buforze). Zwraca Id dokumentu.
     * @param array $payload patrz betterfly_invoice_payload()
     */
    public function createInvoice(array $payload): int
    {
        $res = $this->request('POST', '/api2/public/' . $this->verInvoices . '/invoices', $payload);
        $id  = $this->extractId($res);
        if ($id <= 0) {
            throw new BetterFlyException('API nie zwróciło Id utworzonej faktury.', 0, is_array($res) ? $res : null);
        }
        return $id;
    }

    /**
     * Zatwierdza fakturę (wyprowadza z bufora). Zwraca odpowiedź API.
     * Uwaga: kształt body endpointu /invoices/confirm potwierdź w dokumentacji
     * swojej wersji API — tu wysyłamy {"Id": id}.
     */
    public function confirmInvoice(int $invoiceId): ?array
    {
        $res = $this->request('PUT', '/api2/public/' . $this->verInvoices . '/invoices/confirm', ['Id' => $invoiceId]);
        return is_array($res) ? $res : null;
    }

    /** Pobiera fakturę po Id. */
    public function getInvoice(int $invoiceId): ?array
    {
        $res = $this->request('GET', '/api2/public/' . $this->verInvoices . '/invoices/' . $invoiceId);
        return is_array($res) ? $res : null;
    }

    /** Pobiera fakturę po numerze (np. "FS/23/4/5"). Zwraca pierwsze dopasowanie. */
    public function getInvoiceByNumber(string $number): ?array
    {
        $res = $this->request('GET', '/api2/public/' . $this->verInvoices . '/invoices', null, ['number' => $number]);
        if (is_array($res) && isset($res[0]) && is_array($res[0])) return $res[0];
        if (is_array($res) && isset($res['Number'])) return $res; // pojedynczy obiekt
        return null;
    }

    /**
     * Lista faktur wg filtrów API (np. ['dateFrom'=>..., 'dateTo'=>...]).
     * @return array lista rekordów faktur
     */
    public function listInvoices(array $query = []): array
    {
        $res = $this->request('GET', '/api2/public/' . $this->verInvoices . '/invoices', null, $query);
        if (is_array($res)) {
            return isset($res['Number']) ? [$res] : $res;
        }
        return [];
    }

    /**
     * Wyciąga liczbowe Id z odpowiedzi API, która bywa:
     *  - liczbą (POST invoices zwraca samo Id),
     *  - obiektem {"Id": N},
     *  - stringiem numerycznym.
     */
    private function extractId($res): int
    {
        if (is_int($res)) return $res;
        if (is_numeric($res)) return (int)$res;
        if (is_array($res)) {
            foreach (['Id', 'id', 'InvoiceId', 'DocumentId', 'CustomerId'] as $k) {
                if (isset($res[$k]) && is_numeric($res[$k])) return (int)$res[$k];
            }
        }
        return 0;
    }
}
