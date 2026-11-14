<?php
declare(strict_types=1);

namespace Feer\Szo\LoginSentry;

use DateTimeImmutable;
use DateTimeZone;

/**
 * modules/login_sentry/lib/Gateways.php — integracje LoginSentry („Staszek”) i ich adaptery do SZO.
 *
 *   LogtoGatewayInterface → LogtoManagementGateway: Logto Management API (token M2M client_credentials w pamięci na czas
 *                           przebiegu). GET /api/logs?logKey=… (dziennik logowań z IP i przeglądarką), PATCH is-suspended.
 *   MailerInterface       → SzoMailer: kolejka poczty SZO (mail_queue_add, SMTP dPoczta/M365, HTML + tekst).
 *   GeoLookupInterface    → MmdbGeoLookup (GeoLite2-Country, jeśli jest czytnik) albo NullGeoLookup (bez krajów).
 * SMS-ów Staszek nie wysyła (decyzja 2026-11-15). Testy podstawiają atrapy interfejsów.
 */

/** Jedno logowanie z dowolnego źródła, już w ujednoliconym kształcie. */
final class LoginEvent
{
    public function __construct(
        public readonly string $source,      // 'szo' | 'logto'
        public readonly string $sourceId,
        public readonly ?int $userId,        // konto SZO (dla Logto po dopasowaniu logto_id)
        public readonly string $logtoId,
        public readonly string $email,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly bool $success,
        public readonly DateTimeImmutable $at,
    ) {}
}

interface LogtoGatewayInterface
{
    /** @param list<string> $logKeys  @return list<array{id:string,user_id:string,ip:string,ua:string,success:bool,at:DateTimeImmutable}> */
    public function signIns(DateTimeImmutable $since, array $logKeys): array;

    public function setSuspended(string $logtoUserId, bool $suspended): void;
}

interface MailerInterface
{
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text, string $context): int;
}

interface GeoLookupInterface
{
    /** Dwuliterowy kod kraju albo '' (nie wiadomo). */
    public function country(string $ip): string;
}

final class NullGeoLookup implements GeoLookupInterface
{
    public function country(string $ip): string { return ''; }
}

final class MmdbGeoLookup implements GeoLookupInterface
{
    private object $reader;

    private function __construct(object $reader) { $this->reader = $reader; }

    public static function tryCreate(string $path): GeoLookupInterface
    {
        if ($path === '' || !is_file($path) || !class_exists('\\MaxMind\\Db\\Reader')) return new NullGeoLookup();
        try { return new self(new \MaxMind\Db\Reader($path)); } catch (\Throwable $e) { return new NullGeoLookup(); }
    }

    public function country(string $ip): string
    {
        try {
            $r = $this->reader->get($ip);
            return strtoupper((string)($r['country']['iso_code'] ?? $r['registered_country']['iso_code'] ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }
}

final class LogtoManagementGateway implements LogtoGatewayInterface
{
    private const MAX_PAGES = 20;
    private const PAGE_SIZE = 100;

    private ?string $token = null;
    private int $tokenExp = 0;

    public function __construct(private string $base, private string $appId, private string $secret)
    {
        $this->base = rtrim($base, '/');
    }

    public static function fromSzo(): self
    {
        require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';
        if (!\SsoConfig::logtoConfigured()) throw new \RuntimeException('Logto nie jest skonfigurowane (Ustawienia logowania jednokrotnego).');
        $id = \SsoConfig::get('sso_logto_m2m_id');
        $enc = \SsoConfig::get('sso_logto_m2m_secret');
        try { $secret = $enc === '' ? '' : (string)\fcDecrypt($enc, 'settings.sso_logto_m2m_secret'); } catch (\Throwable $e) { $secret = ''; }
        return new self(\SsoConfig::logtoUrl(), $id, $secret);
    }

    public function signIns(DateTimeImmutable $since, array $logKeys): array
    {
        $out = [];
        $sinceMs = $since->getTimestamp() * 1000;
        foreach ($logKeys as $key) {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                [$code, $rows] = $this->call('GET', '/api/logs?' . http_build_query(['logKey' => $key, 'page' => $page, 'page_size' => self::PAGE_SIZE]));
                if ($code < 200 || $code >= 300) throw new \RuntimeException('Logto GET /api/logs: HTTP ' . $code);
                if (!$rows) break;
                $older = false;
                foreach ($rows as $r) {
                    $ms = (int)($r['createdAt'] ?? 0);
                    if ($ms < $sinceMs) { $older = true; continue; }
                    $p = is_array($r['payload'] ?? null) ? $r['payload'] : [];
                    $uid = (string)($p['userId'] ?? '');
                    if ($uid === '') continue;   // logowanie bez rozpoznanej osoby (np. zły login) — nie ma kogo oceniać
                    $out[] = [
                        'id' => (string)($r['id'] ?? ''),
                        'user_id' => $uid,
                        'ip' => (string)($p['ip'] ?? ''),
                        'ua' => (string)($p['userAgent'] ?? ''),
                        'success' => strcasecmp((string)($p['result'] ?? ''), 'Success') === 0,
                        'at' => (new DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new DateTimeZone('UTC')),
                    ];
                }
                if ($older || count($rows) < self::PAGE_SIZE) break;   // dziennik jest od najnowszych, dalej już starsze
            }
        }
        return $out;
    }

    public function setSuspended(string $logtoUserId, bool $suspended): void
    {
        [$code, $j] = $this->call('PATCH', '/api/users/' . rawurlencode($logtoUserId) . '/is-suspended', ['isSuspended' => $suspended]);
        if ($code < 200 || $code >= 300) throw new \RuntimeException('Logto is-suspended: HTTP ' . $code . ' ' . (string)($j['message'] ?? ''));
    }

    /** @return array{0:int,1:array} */
    private function call(string $method, string $path, ?array $json = null): array
    {
        for ($try = 0; $try < 2; $try++) {
            $headers = ['Authorization: Bearer ' . $this->token(), 'Accept: application/json'];
            $ch = curl_init($this->base . $path);
            $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30];
            if ($json !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($json);
                $headers[] = 'Content-Type: application/json';
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            if ($raw === false) throw new \RuntimeException('Logto niedostępne: ' . $err);
            if ($code === 401 && $try === 0) { $this->token = null; continue; }
            $j = json_decode((string)$raw, true);
            return [$code, is_array($j) ? $j : []];
        }
        return [401, []];
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExp - 60) return $this->token;
        if ($this->appId === '' || $this->secret === '') throw new \RuntimeException('Brak App ID albo App secret aplikacji Machine-to-machine w Logto.');
        $ch = curl_init($this->base . '/oidc/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_USERPWD => $this->appId . ':' . $this->secret,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials', 'resource' => 'https://default.logto.app/api', 'scope' => 'all']),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        $j = json_decode((string)$raw, true);
        if (empty($j['access_token'])) throw new \RuntimeException('Logto nie wydało tokenu M2M (HTTP ' . $code . ').');
        $this->token = (string)$j['access_token'];
        $this->tokenExp = time() + (int)($j['expires_in'] ?? 3600);
        return $this->token;
    }
}

final class SzoMailer implements MailerInterface
{
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text, string $context): int
    {
        require_once dirname(__DIR__, 3) . '/includes/mail_queue.php';
        return \mail_queue_add($toEmail, $toName, $subject, $html, $text, $context);
    }
}
