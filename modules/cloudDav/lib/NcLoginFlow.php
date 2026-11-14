<?php
declare(strict_types=1);

/**
 * modules/cloudDav/lib/NcLoginFlow.php — Login Flow v2 Nextcloud: SZO prosi chmurę o hasło aplikacji w imieniu użytkownika.
 *
 * Przebieg (https://docs.nextcloud.com/server/latest/developer_manual/client_apis/LoginFlow/index.html#login-flow-v2):
 *   1) start(): POST {chmura}/index.php/login/v2 z nagłówkiem User-Agent = nazwa urządzenia, którą użytkownik zobaczy w chmurze
 *      (Ustawienia osobiste → Bezpieczeństwo). Odpowiedź: adres strony logowania i token do odpytywania.
 *   2) Użytkownik otwiera stronę logowania, loguje się (logowanie jednokrotne) i klika „Udziel dostępu”.
 *   3) poll(): POST {endpoint} token=… → 404, dopóki użytkownik nie potwierdzi; potem 200 z server, loginName, appPassword.
 *      Token jest jednorazowy i ważny 20 minut.
 * revoke(): DELETE /ocs/v2.php/core/apppassword uwierzytelnione samym hasłem aplikacji — chmura usuwa to hasło.
 *
 * Hasła aplikacji SZO nigdzie nie zapisuje (tylko sesja użytkownika, zob. CloudDavController). Adresy z odpowiedzi chmury
 * sprawdzamy: endpoint i server muszą wskazywać ten sam host co adres chmury z ustawień (bez przekierowań gdzie indziej).
 */
final class NcLoginFlow
{
    /** Ile czasu chmura trzyma token logowania (Nextcloud: 20 minut). */
    public const TTL = 1200;

    /** Podmiana HTTP w testach: fn(string $method, string $url, array $headers, ?string $body, ?array $auth): array{0:int,1:string}. */
    public static ?\Closure $transport = null;

    private string $base;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/');
        if (!preg_match('~^https?://[^/\s]+~i', $this->base)) throw new InvalidArgumentException('Nieprawidłowy adres chmury.');
    }

    public function base(): string { return $this->base; }

    /** @return array{login:string, token:string, endpoint:string} */
    public function start(string $clientName): array
    {
        [$code, $raw] = self::http('POST', $this->base . '/index.php/login/v2', ['User-Agent: ' . self::clientName($clientName), 'Accept: application/json'], '', null);
        $j = json_decode($raw, true);
        if ($code !== 200 || !is_array($j) || empty($j['login']) || empty($j['poll']['token']) || empty($j['poll']['endpoint'])) {
            throw new RuntimeException('Chmura nie rozpoczęła logowania (HTTP ' . $code . ').');
        }
        $login = (string)$j['login'];
        $endpoint = (string)$j['poll']['endpoint'];
        if (!$this->sameHost($login) || !$this->sameHost($endpoint)) throw new RuntimeException('Chmura zwróciła adres na innym serwerze.');
        return ['login' => $login, 'token' => (string)$j['poll']['token'], 'endpoint' => $endpoint];
    }

    /**
     * null = użytkownik jeszcze nie potwierdził. Wyjątek NcLoginFlowExpired, gdy token wygasł albo został już użyty.
     * @return array{server:string, loginName:string, appPassword:string}|null
     */
    public function poll(string $endpoint, string $token): ?array
    {
        if (!$this->sameHost($endpoint)) throw new RuntimeException('Nieprawidłowy adres odpytywania.');
        [$code, $raw] = self::http('POST', $endpoint, ['Accept: application/json'], http_build_query(['token' => $token]), null);
        if ($code === 404) return null;
        if ($code === 0 || $code >= 500) throw new RuntimeException('Chmura nie odpowiada (HTTP ' . $code . ').');
        $j = json_decode($raw, true);
        if ($code !== 200 || !is_array($j) || empty($j['loginName']) || empty($j['appPassword'])) {
            throw new NcLoginFlowExpired('Logowanie wygasło albo zostało już użyte (HTTP ' . $code . ').');
        }
        if (!empty($j['server']) && !$this->sameHost((string)$j['server'])) throw new RuntimeException('Chmura zwróciła inny adres serwera.');
        return ['server' => $this->base, 'loginName' => (string)$j['loginName'], 'appPassword' => (string)$j['appPassword']];
    }

    /** Usuwa hasło aplikacji w chmurze (np. gdy ktoś zalogował się na cudze konto). true = usunięte. */
    public function revoke(string $loginName, string $appPassword): bool
    {
        [$code] = self::http('DELETE', $this->base . '/ocs/v2.php/core/apppassword?format=json', ['OCS-APIRequest: true', 'Accept: application/json'], null, [$loginName, $appPassword]);
        return $code === 200;
    }

    /** Nazwa urządzenia w chmurze: tylko bezpieczne znaki ASCII (nagłówek HTTP), najwyżej 64. */
    public static function clientName(string $name): string
    {
        $n = trim((string)preg_replace('/[^A-Za-z0-9 .,:()\/+\-]+/', ' ', $name));
        $n = (string)preg_replace('/\s{2,}/', ' ', $n);
        return substr($n !== '' ? $n : 'SZO', 0, 64);
    }

    private function sameHost(string $url): bool
    {
        $a = parse_url($this->base);
        $b = parse_url($url);
        if (!is_array($b) || empty($b['host']) || !in_array(strtolower((string)($b['scheme'] ?? '')), ['http', 'https'], true)) return false;
        return strtolower((string)$a['host']) === strtolower((string)$b['host'])
            && (int)($a['port'] ?? 0) === (int)($b['port'] ?? 0)
            && strtolower((string)$a['scheme']) === strtolower((string)$b['scheme']);
    }

    /** @return array{0:int,1:string} */
    public static function http(string $method, string $url, array $headers, ?string $body, ?array $auth, int $timeout = 20): array
    {
        if (self::$transport) return (self::$transport)($method, $url, $headers, $body, $auth);
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
                 CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false];
        if ($auth) $opts[CURLOPT_USERPWD] = $auth[0] . ':' . $auth[1];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
            if (!preg_grep('/^Content-Type:/i', $headers)) $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        return $raw === false ? [0, $err] : [$code, (string)$raw];
    }
}

final class NcLoginFlowExpired extends RuntimeException {}
