<?php
declare(strict_types=1);

/**
 * modules/sso_logto/lib/LogtoApi.php — klient Management API Logto (konta użytkowników logowania jednokrotnego).
 *
 * Token: client_credentials aplikacji Machine-to-machine (Basic auth) na {logto}/oidc/token z resource
 * https://default.logto.app/api i scope „all” (Logto OSS, tenant default). Aplikacja potrzebuje roli
 * „Logto Management API access”, inaczej każde wywołanie kończy się 403.
 *
 * Testy: konstruktor przyjmuje dowolny adres (atrapa na http://127.0.0.1:…).
 */

class LogtoApiException extends RuntimeException
{
    public string $apiCode = '';
}

final class LogtoApi
{
    public const RESOURCE = 'https://default.logto.app/api';
    public int $lastHttp = 0;
    public string $lastRaw = '';
    private ?string $token = null;
    private int $tokenExp = 0;

    public function __construct(private string $base, private string $appId, private string $secret)
    {
        $this->base = rtrim($base, '/');
    }

    /** Sprawdzenie połączenia: token + odczyt jednej strony kont. Zwraca liczbę kont (nagłówek Total-Number) albo -1. */
    public function ping(): int
    {
        $headers = [];
        $this->request('GET', '/api/users', null, ['page' => 1, 'page_size' => 1], $headers);
        return isset($headers['total-number']) ? (int)$headers['total-number'] : -1;
    }

    public function get(string $id): ?array
    {
        try {
            return $this->request('GET', '/api/users/' . rawurlencode($id));
        } catch (LogtoApiException $e) {
            if ($this->lastHttp === 404) return null;
            throw $e;
        }
    }

    /** Konto o dokładnie tym adresie e-mail (bez względu na wielkość liter) albo null. */
    public function findByEmail(string $email): ?array
    {
        return $this->findExact('primaryEmail', strtolower(trim($email)));
    }

    public function findByUsername(string $username): ?array
    {
        return $this->findExact('username', $username);
    }

    private function findExact(string $field, string $value): ?array
    {
        if ($value === '') return null;
        $list = $this->request('GET', '/api/users', null, [
            'search.' . $field => $value, 'mode.' . $field => 'exact', 'isCaseSensitive' => 'false', 'page_size' => 20,
        ]);
        foreach ($list as $u) {
            if (is_array($u) && strcasecmp((string)($u[$field] ?? ''), $value) === 0) return $u;
        }
        return null;
    }

    /** Nowe konto: username, primaryEmail, name, password. Zwraca konto (z id). */
    public function create(array $data): array
    {
        return $this->request('POST', '/api/users', array_filter($data, fn($v) => $v !== null && $v !== ''));
    }

    public function setPassword(string $id, string $password): void
    {
        $this->request('PATCH', '/api/users/' . rawurlencode($id) . '/password', ['password' => $password]);
    }

    /** Częściowa zmiana customData konta (Logto scala klucze najwyższego poziomu). Zwraca customData po zmianie. */
    public function setCustomData(string $id, array $data): array
    {
        return $this->request('PATCH', '/api/users/' . rawurlencode($id) . '/custom-data', ['customData' => $data]);
    }

    // ── HTTP ───────────────────────────────────────────────────────────────────────────────

    public function request(string $method, string $path, ?array $json = null, array $query = [], ?array &$headersOut = null): array
    {
        for ($try = 0; $try < 2; $try++) {
            $url = $this->base . $path . ($query ? '?' . http_build_query($query) : '');
            $headers = ['Authorization: Bearer ' . $this->token(), 'Accept: application/json'];
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            ];
            if ($json !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            }
            $respHeaders = [];
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_HEADERFUNCTION] = function ($c, $h) use (&$respHeaders) {
                $p = strpos($h, ':');
                if ($p !== false) $respHeaders[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
                return strlen($h);
            };
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $this->lastHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            if ($raw === false) throw new LogtoApiException('Logto niedostępne: ' . $err);
            $this->lastRaw = mb_substr((string)$raw, 0, 2000);
            $headersOut = $respHeaders;
            if ($this->lastHttp === 401 && $try === 0) { $this->token = null; continue; }
            if ($this->lastHttp >= 200 && $this->lastHttp < 300) {
                if ($raw === '' || $this->lastHttp === 204) return [];
                $j = json_decode((string)$raw, true);
                return is_array($j) ? $j : [];
            }
            $this->fail((string)$raw);
        }
        $this->fail('');
    }

    private function fail(string $raw): never
    {
        $j = json_decode($raw, true);
        $code = is_array($j) ? (string)($j['code'] ?? '') : '';
        $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
        $e = new LogtoApiException(self::humanize($this->lastHttp, $code, $msg));
        $e->apiCode = $code;
        throw $e;
    }

    /** Komunikaty Logto po polsku dla najczęstszych przypadków (reszta: oryginał z kodem). */
    public static function humanize(int $http, string $code, string $msg): string
    {
        $known = [
            'user.username_already_in_use' => 'Ta nazwa użytkownika jest już zajęta w Logto.',
            'user.email_already_in_use'    => 'Ten adres e-mail ma już konto w Logto.',
            'password.rejected'            => 'Logto odrzuciło hasło: jest za krótkie, za proste, zawiera dane konta albo pojawiło się w znanych wyciekach haseł. Wybierz inne.',
            'password.unsupported_encryption_method' => 'Logto nie obsługuje tego sposobu zapisu hasła.',
            'entity.not_exists_with_id'    => 'Konto nie istnieje w Logto (mogło zostać usunięte).',
            'auth.forbidden'               => 'Logto odmówiło dostępu: aplikacja Machine-to-machine nie ma roli „Logto Management API access”.',
            'oidc.invalid_client'          => 'Logto nie rozpoznało aplikacji Machine-to-machine (sprawdź App ID i App secret).',
        ];
        if (isset($known[$code])) return $known[$code];
        if ($http === 403) return $known['auth.forbidden'];
        if ($http >= 500 || $http === 0) return 'Logto chwilowo niedostępne (HTTP ' . $http . '). Spróbuj za chwilę.';
        return 'Logto: ' . ($msg !== '' ? mb_substr($msg, 0, 300) : 'HTTP ' . $http) . ($code !== '' ? ' (' . $code . ')' : '');
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExp - 60) return $this->token;
        if ($this->appId === '' || $this->secret === '') throw new LogtoApiException('Brak App ID albo App secret aplikacji Machine-to-machine.');
        $ch = curl_init($this->base . '/oidc/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $this->appId . ':' . $this->secret,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials', 'resource' => self::RESOURCE, 'scope' => 'all']),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($raw === false) throw new LogtoApiException('Logto niedostępne: ' . $err);
        $j = json_decode((string)$raw, true);
        if (empty($j['access_token'])) {
            $this->lastHttp = $code;
            $this->lastRaw = mb_substr((string)$raw, 0, 2000);
            $c = (string)($j['error'] ?? '');
            throw new LogtoApiException($c === 'invalid_client'
                ? self::humanize($code, 'oidc.invalid_client', '')
                : 'Logto nie wydało tokenu (HTTP ' . $code . ($c !== '' ? ', ' . $c : '') . '): ' . mb_substr((string)($j['error_description'] ?? ''), 0, 200));
        }
        $this->token = (string)$j['access_token'];
        $this->tokenExp = time() + (int)($j['expires_in'] ?? 3600);
        return $this->token;
    }
}
