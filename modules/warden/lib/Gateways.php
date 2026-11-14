<?php
declare(strict_types=1);

namespace Feer\Szo\Warden;

use DateTimeImmutable;
use DateTimeZone;

/**
 * modules/warden/lib/Gateways.php — interfejsy integracji WARDEN i ich adaptery do SZO.
 *
 *   LogtoGatewayInterface  → LogtoManagementGateway: Logto Management API (token M2M client_credentials trzymany w pamięci
 *                            na czas przebiegu, GET /api/users/{id} → lastSignInAt w ms, PATCH /api/users/{id}/is-suspended).
 *   MailerInterface        → SzoMailer: kolejka poczty SZO (mail_queue_add: UTF-8, text/html + text/plain, SMTP dPoczta/M365).
 *   SmsGatewayInterface    → SzoSmsGateway: łańcuch dostawców SMS SZO (sms_send, REST), zawsze ASCII i najwyżej 160 znaków.
 * Testy podstawiają własne implementacje interfejsów (atrapy), więc silnik nie zależy od sieci.
 */

final class LogtoUserState
{
    public function __construct(public readonly ?DateTimeImmutable $lastSignInAt, public readonly bool $isSuspended) {}
}

interface LogtoGatewayInterface
{
    /** null = konta nie ma w Logto (404). Rzuca wyjątek przy błędzie API. */
    public function user(string $logtoUserId): ?LogtoUserState;

    public function setSuspended(string $logtoUserId, bool $suspended): void;
}

interface MailerInterface
{
    /** Zwraca identyfikator wiadomości w kolejce (0 = odrzucona). */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text, string $context): int;
}

interface SmsGatewayInterface
{
    /** $message jest już przygotowany (ASCII, ≤160). Zwraca nazwę dostawcy, który wysłał. */
    public function send(string $phone, string $message): string;
}

final class LogtoManagementGateway implements LogtoGatewayInterface
{
    private ?string $token = null;
    private int $tokenExp = 0;

    public function __construct(private string $base, private string $appId, private string $secret)
    {
        $this->base = rtrim($base, '/');
    }

    /** Z ustawień logowania jednokrotnego SZO (modules/sso_logto). */
    public static function fromSzo(): self
    {
        require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';
        if (!\SsoConfig::logtoConfigured()) throw new \RuntimeException('Logto nie jest skonfigurowane (Ustawienia logowania jednokrotnego).');
        $id = \SsoConfig::get('sso_logto_m2m_id');
        $enc = \SsoConfig::get('sso_logto_m2m_secret');
        try { $secret = $enc === '' ? '' : (string)\fcDecrypt($enc, 'settings.sso_logto_m2m_secret'); } catch (\Throwable $e) { $secret = ''; }
        return new self(\SsoConfig::logtoUrl(), $id, $secret);
    }

    public function user(string $logtoUserId): ?LogtoUserState
    {
        [$code, $j] = $this->call('GET', '/api/users/' . rawurlencode($logtoUserId));
        if ($code === 404) return null;
        if ($code < 200 || $code >= 300) throw new \RuntimeException('Logto GET użytkownika: HTTP ' . $code . ' ' . (string)($j['message'] ?? ''));
        $ms = $j['lastSignInAt'] ?? null;
        $at = is_numeric($ms) && (int)$ms > 0
            ? (new DateTimeImmutable('@' . intdiv((int)$ms, 1000)))->setTimezone(new DateTimeZone('UTC'))
            : null;
        return new LogtoUserState($at, !empty($j['isSuspended']));
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

    /** Token M2M: POST /oidc/token (client_credentials), w pamięci do wygaśnięcia (minus minuta). */
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

final class SzoSmsGateway implements SmsGatewayInterface
{
    public const KIND = 'warden_inactivity';

    public function send(string $phone, string $message): string
    {
        require_once dirname(__DIR__, 3) . '/includes/sms.php';
        return sms_send($phone, AsciiSms::prepare($message), 'warden_inactivity');
    }
}

final class AsciiSms
{
    public const MAX = 160;

    /** Bez polskich znaków (ASCII) i najwyżej 160 znaków; za długi tekst ucina na granicy słowa. */
    public static function prepare(string $s): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
                '„' => '"', '”' => '"', '“' => '"', '’' => "'", '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' '];
        $s = strtr($s, $map);
        $s = (string)preg_replace('/[^\x20-\x7E]/', '', $s);
        $s = trim((string)preg_replace('/\s+/', ' ', $s));
        if (strlen($s) > self::MAX) {
            $cut = substr($s, 0, self::MAX);
            $sp = strrpos($cut, ' ');
            $s = rtrim($sp !== false && $sp > 100 ? substr($cut, 0, $sp) : $cut);
        }
        return $s;
    }
}
