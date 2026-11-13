<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/lib/DhostingApi.php — klient API dhosting.pl (skrzynki e-mail).
 *
 * Dokumentacja: https://documenter.getpostman.com/view/5724061/TW74hPtU
 *  • adres bazowy https://api.dhosting.pl, HTTP Basic (login i hasło API wydaje dhosting.pl — to NIE są dane do panelu),
 *  • nagłówki Accept: application/json, Accept-Language: pl; treść żądania zawsze JSON (także przy odczytach, które są POST),
 *  • koperta odpowiedzi: {"OrderToken", "Status": error|warning|success, "Code", "Message", …}.
 *
 * Dokumentacja NIE pokazuje udanych odpowiedzi — dlatego listę skrzynek wyciągamy z dowolnego miejsca odpowiedzi
 * (extractMailboxes: każdy ciąg wyglądający na adres w danej domenie), a ostatnią surową odpowiedź trzymamy w $lastRaw
 * do podglądu w „Testuj połączenie”.
 *
 * Pułapki z dokumentacji: telefon w formacie +48.123123123 (z kropką), Quota w GB (1.0 = 1 GB, -1 = bez limitu),
 * mailboxDescription / mailboxQuotaUnlimited małą literą. SendSms zawsze false — hasło wysyła nasz łańcuch SMS.
 */
final class DhostingApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpCode = 0, public readonly string $apiCode = '')
    {
        parent::__construct($message);
    }
}

final class DhostingApi
{
    public const DEFAULT_URL = 'https://api.dhosting.pl';

    /** Kody błędów z dokumentacji (opisy po polsku) — per endpoint, bo te same numery znaczą tam co innego. */
    private const ERRORS = [
        '/email/createMailbox' => [
            '120450' => 'adres skrzynki jest niepoprawny',
            '120451' => 'numer telefonu jest niepoprawny',
            '120452' => 'hasło nie spełnia wymagań bezpieczeństwa dhosting',
            '120457' => 'przekroczono limit przestrzeni (Quota)',
            '120458' => 'parametr SendSms jest niepoprawny',
            '120460' => 'przekroczono limit skrzynek w usłudze',
            '120470' => 'nie udało się założyć skrzynki',
        ],
        '/email/changeMailboxPassword' => [
            '120450' => 'skrzynka nie istnieje albo nie należy do konta API',
            '120452' => 'hasło nie spełnia wymagań bezpieczeństwa dhosting',
            '120463' => 'na skrzynce trwają inne operacje — spróbuj za kilka minut',
            '120460' => 'nie udało się zmienić hasła',
        ],
        '/email/changeMailboxPhoneNumber' => [
            '120450' => 'skrzynka nie istnieje',
            '120466' => 'ten numer jest już zapisany',
            '120463' => 'na skrzynce trwają inne operacje — spróbuj później',
        ],
        '/email/getMailboxList' => [
            '120458' => 'niepoprawna domena',
            '120490' => 'domena nie należy do konta API dhosting',
            '120491' => 'nie udało się pobrać listy skrzynek',
        ],
        '/email/getMailboxInformation' => [
            '120450' => 'skrzynka nie istnieje',
            '120452' => 'nie udało się pobrać informacji o skrzynce',
        ],
    ];

    /** Ostatnia odpowiedź API (zdekodowana albo surowy tekst) — do diagnostyki w panelu admina. */
    public array|string|null $lastRaw = null;
    public int $lastHttp = 0;

    public function __construct(
        private string $baseUrl,
        private string $user,
        private string $pass,
        private int $timeout = 25,
    ) {
        $this->baseUrl = rtrim($this->baseUrl !== '' ? $this->baseUrl : self::DEFAULT_URL, '/');
    }

    public function configured(): bool
    {
        return $this->user !== '' && $this->pass !== '';
    }

    /** Wywołanie endpointu; zwraca zdekodowaną kopertę. Wyjątek przy błędzie sieci, HTTP ≥ 400 albo Status = error. */
    public function call(string $method, string $path, array $body = []): array
    {
        if (!$this->configured()) throw new DhostingApiException('Brak loginu lub hasła API dhosting — uzupełnij ustawienia migratora.');
        if (!function_exists('curl_init')) throw new DhostingApiException('Brak rozszerzenia PHP cURL — nie można połączyć się z API dhosting.');

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: pl', 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode((object)$body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,     // idzie hasło API — certyfikat sprawdzamy zawsze
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        unset($ch);

        $this->lastHttp = $http;
        if ($raw === false) {
            $this->lastRaw = null;
            $hint = stripos($err, 'certificate') !== false ? ' (brak pakietu CA na serwerze — ustaw openssl.cafile / curl.cainfo)' : '';
            throw new DhostingApiException('Brak połączenia z API dhosting: ' . $err . $hint);
        }
        $json = json_decode((string)$raw, true);
        $this->lastRaw = is_array($json) ? $json : mb_substr((string)$raw, 0, 2000);

        $status = is_array($json) ? strtolower((string)($json['Status'] ?? '')) : '';
        if ($http === 401) throw new DhostingApiException('dhosting odrzucił login lub hasło API (401).', 401, '401');
        if ($http >= 400 || $status === 'error' || !is_array($json)) {
            $code = is_array($json) ? (string)($json['Code'] ?? '') : '';
            $msg  = is_array($json) ? (string)($json['Message'] ?? '') : '';
            $known = self::ERRORS[$path][$code] ?? '';
            $text  = $known !== '' ? $known : ($msg !== '' ? $msg : 'nieoczekiwana odpowiedź (HTTP ' . $http . ')');
            throw new DhostingApiException('dhosting: ' . $text . ($code !== '' ? ' [kod ' . $code . ']' : ''), $http, $code);
        }
        return $json;
    }

    /** Adresy skrzynek w domenie (małe litery, posortowane). */
    public function getMailboxList(string $domain): array
    {
        $domain = strtolower(trim($domain));
        return self::extractMailboxes($this->call('POST', '/email/getMailboxList', ['Domain' => $domain]), $domain);
    }

    /** Informacje o skrzynce (quota, protokoły, status) — kształt danych jak zwróci dhosting. */
    public function getMailboxInformation(string $mailbox): array
    {
        return $this->call('POST', '/email/getMailboxInformation', ['MailboxName' => strtolower(trim($mailbox))]);
    }

    /**
     * Zakłada skrzynkę. $phone w dowolnym formacie (zapisany u dhosting do późniejszych zmian hasła),
     * $quotaGb: null = domyślna usługi, -1 = bez limitu, inaczej GB (min. 0.1).
     */
    public function createMailbox(string $mailbox, string $password, ?string $phone = null, ?float $quotaGb = null, string $description = ''): array
    {
        $body = ['MailboxName' => strtolower(trim($mailbox)), 'Password' => $password, 'SendSms' => false];
        $p = $phone !== null ? self::phoneForApi($phone) : null;
        if ($p !== null) $body['PhoneNumber'] = $p;
        if ($quotaGb !== null) {
            if ($quotaGb < 0) $body['mailboxQuotaUnlimited'] = true;
            else $body['Quota'] = number_format(max(0.1, $quotaGb), 1, '.', '');
        }
        if ($description !== '') $body['mailboxDescription'] = mb_substr($description, 0, 200);
        return $this->call('POST', '/email/createMailbox', $body);
    }

    public function changeMailboxPassword(string $mailbox, string $password): array
    {
        return $this->call('PUT', '/email/changeMailboxPassword', [
            'MailboxName' => strtolower(trim($mailbox)), 'NewPassword' => $password, 'SendSms' => false,
        ]);
    }

    /** Włącza (true) albo wyłącza (false) całą skrzynkę — PUT /email/changeMailboxStatus {MailboxName, NewStatus}. */
    public function changeMailboxStatus(string $mailbox, bool $enabled): array
    {
        return $this->call('PUT', '/email/changeMailboxStatus', ['MailboxName' => strtolower(trim($mailbox)), 'NewStatus' => $enabled]);
    }

    public function changeMailboxPhoneNumber(string $mailbox, string $phone): array
    {
        $p = self::phoneForApi($phone);
        if ($p === null) throw new DhostingApiException('Niepoprawny numer telefonu.');
        return $this->call('PUT', '/email/changeMailboxPhoneNumber', ['MailboxName' => strtolower(trim($mailbox)), 'PhoneNumber' => $p]);
    }

    /** Numer w formacie dhosting: 501 234 567 / +48501234567 → +48.501234567. null = nie da się ustalić. */
    public static function phoneForApi(string $phone): ?string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($d) === 9) $d = '48' . $d;
        if (strlen($d) < 10 || strlen($d) > 15) return null;
        $cc = str_starts_with($d, '48') ? '48' : substr($d, 0, 2);
        return '+' . $cc . '.' . substr($d, strlen($cc));
    }

    /** Wszystkie ciągi w odpowiedzi, które są adresami e-mail w $domain (kształt odpowiedzi nieudokumentowany). */
    public static function extractMailboxes(array $resp, string $domain): array
    {
        $out = [];
        $suffix = '@' . strtolower($domain);
        array_walk_recursive($resp, function ($v) use (&$out, $suffix) {
            if (!is_string($v)) return;
            $v = strtolower(trim($v));
            if (str_ends_with($v, $suffix) && filter_var($v, FILTER_VALIDATE_EMAIL)) $out[$v] = true;
        });
        $list = array_keys($out);
        sort($list);
        return $list;
    }
}
