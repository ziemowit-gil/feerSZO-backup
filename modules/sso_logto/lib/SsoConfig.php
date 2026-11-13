<?php
declare(strict_types=1);

/**
 * modules/sso_logto/lib/SsoConfig.php — ustawienia logowania jednokrotnego (Logto) i chmury plików (Nextcloud / ownCloud).
 *
 * Klucze w tabeli settings (org_setting); sekrety szyfrowane fcEncrypt (kontekst settings.<klucz>):
 *   sso_logto_url          — adres Logto (np. https://logto.feer.org.pl)
 *   sso_logto_m2m_id       — App ID aplikacji Machine-to-machine w Logto (rola „Logto Management API access”)
 *   sso_logto_m2m_secret   — App secret tej aplikacji (zaszyfrowany)
 *   sso_logto_webhook_key  — Signing key webhooka Logto → SZO (zaszyfrowany)
 *   sso_self_enabled       — '1' = użytkownik sam zakłada konto w Tożsamości (domyślnie tak)
 *   sso_login_prompt       — '1' = po każdym zalogowaniu prośba o konto (lib/ssoPrompt.php, /sso/start); domyślnie tak
 *   sso_szo_login          — '1' = logowanie do SZO przez Logto (przycisk na ekranie logowania, lib/LogtoOidc.php)
 *   sso_szo_app_id         — App ID aplikacji „Traditional web” w Logto (SZO jako klient OpenID Connect)
 *   sso_szo_app_secret     — App secret tej aplikacji (zaszyfrowany)
 *   sso_cloud_kind         — 'nextcloud' | 'owncloud'
 *   sso_cloud_url          — adres chmury (inny serwer, np. https://chmura.feer.org.pl)
 *   sso_cloud_user         — login administratora chmury
 *   sso_cloud_pass         — hasło aplikacji administratora chmury (zaszyfrowane)
 *   sso_cloud_group        — grupa dla nowych kont ('' = bez grupy)
 *   sso_cloud_quota        — limit miejsca nowych kont, np. „5 GB” ('' = domyślny chmury)
 *   sso_cloud_self         — '1' = przy samodzielnym założeniu konta Logto od razu zakładaj też konto w chmurze
 *   sso_sms_prefix         — początek SMS-a z kodem z Logto (domyślnie „FEER”)
 */
require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';
require_once __DIR__ . '/LogtoApi.php';
require_once __DIR__ . '/CloudApi.php';

final class SsoConfig
{
    public const DEFAULT_LOGTO_URL = 'https://logto.feer.org.pl';
    public const CLOUD_KINDS = ['nextcloud' => 'Nextcloud', 'owncloud' => 'ownCloud'];
    private const SECRETS = ['sso_logto_m2m_secret', 'sso_logto_webhook_key', 'sso_cloud_pass', 'sso_szo_app_secret'];

    public static function get(string $key, string $default = ''): string
    {
        $v = function_exists('org_setting') ? org_setting($key) : '';
        return $v !== '' ? $v : $default;
    }

    private static function secret(string $key): string
    {
        $enc = self::get($key);
        if ($enc === '') return '';
        try { return (string)fcDecrypt($enc, 'settings.' . $key); } catch (\Throwable $e) { return ''; }
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== '';
    }

    // ── Logto ──────────────────────────────────────────────────────────────────────────────

    public static function logtoUrl(): string
    {
        return rtrim(self::get('sso_logto_url', self::DEFAULT_LOGTO_URL), '/');
    }

    public static function logtoConfigured(): bool
    {
        return self::get('sso_logto_m2m_id') !== '' && self::has('sso_logto_m2m_secret');
    }

    public static function logto(): LogtoApi
    {
        if (!self::logtoConfigured()) throw new LogtoApiException('Logowanie jednokrotne nie jest skonfigurowane (brak aplikacji Machine-to-machine w ustawieniach).');
        return new LogtoApi(self::logtoUrl(), self::get('sso_logto_m2m_id'), self::secret('sso_logto_m2m_secret'));
    }

    /** App secret aplikacji SZO (logowanie przez Logto, lib/LogtoOidc.php). */
    public static function szoAppSecret(): string
    {
        return self::secret('sso_szo_app_secret');
    }

    public static function webhookKey(): string
    {
        return self::secret('sso_logto_webhook_key');
    }

    public static function selfEnabled(): bool
    {
        return self::get('sso_self_enabled', '1') === '1';
    }

    public static function smsPrefix(): string
    {
        return self::get('sso_sms_prefix', 'FEER');
    }

    // ── Chmura ─────────────────────────────────────────────────────────────────────────────

    public static function cloudKind(): string
    {
        $k = self::get('sso_cloud_kind', 'nextcloud');
        return isset(self::CLOUD_KINDS[$k]) ? $k : 'nextcloud';
    }

    public static function cloudLabel(): string
    {
        return self::CLOUD_KINDS[self::cloudKind()];
    }

    public static function cloudUrl(): string
    {
        return rtrim(self::get('sso_cloud_url'), '/');
    }

    public static function cloudConfigured(): bool
    {
        return self::cloudUrl() !== '' && self::get('sso_cloud_user') !== '' && self::has('sso_cloud_pass');
    }

    public static function cloud(): CloudApi
    {
        if (!self::cloudConfigured()) throw new CloudApiException('Chmura plików nie jest skonfigurowana.');
        return new CloudApi(self::cloudUrl(), self::get('sso_cloud_user'), self::secret('sso_cloud_pass'), self::cloudKind());
    }

    public static function cloudGroup(): string
    {
        return self::get('sso_cloud_group');
    }

    public static function cloudQuota(): string
    {
        return self::get('sso_cloud_quota');
    }

    public static function cloudSelf(): bool
    {
        return self::get('sso_cloud_self') === '1';
    }

    // ── Zapis z formularza admina (puste pola sekretów = bez zmiany) ─────────────────────────

    public static function save(array $in): void
    {
        $url = rtrim(trim((string)($in['logto_url'] ?? '')), '/');
        if ($url !== '' && !preg_match('~^https://[a-z0-9.-]+(:\d+)?$~i', $url)) {
            throw new InvalidArgumentException('Adres Logto: sam adres https://, bez ścieżki (np. https://logto.feer.org.pl).');
        }
        $m2m = trim((string)($in['logto_m2m_id'] ?? ''));
        if ($m2m !== '' && !preg_match('/^[a-z0-9]{6,64}$/i', $m2m)) throw new InvalidArgumentException('App ID aplikacji Machine-to-machine: same litery i cyfry.');
        $szoApp = trim((string)($in['szo_app_id'] ?? ''));
        if ($szoApp !== '' && !preg_match('/^[a-z0-9]{6,64}$/i', $szoApp)) throw new InvalidArgumentException('App ID aplikacji SZO (Traditional web): same litery i cyfry.');

        $kind = (string)($in['cloud_kind'] ?? 'nextcloud');
        if (!isset(self::CLOUD_KINDS[$kind])) $kind = 'nextcloud';
        $cloudUrl = rtrim(trim((string)($in['cloud_url'] ?? '')), '/');
        if ($cloudUrl !== '' && !preg_match('~^https://[a-z0-9.-]+(:\d+)?(/[^\s]*)?$~i', $cloudUrl)) {
            throw new InvalidArgumentException('Adres chmury musi zaczynać się od https:// (np. https://chmura.feer.org.pl).');
        }
        $quota = trim((string)($in['cloud_quota'] ?? ''));
        if ($quota !== '' && !preg_match('/^(none|\d+(\.\d+)?\s*(B|KB|MB|GB|TB))$/i', $quota)) {
            throw new InvalidArgumentException('Limit miejsca: np. „5 GB”, „500 MB” albo „none” (bez limitu); puste = domyślny chmury.');
        }
        $group = trim((string)($in['cloud_group'] ?? ''));
        if ($group !== '' && !preg_match('/^[\w .@-]{1,64}$/u', $group)) throw new InvalidArgumentException('Nazwa grupy w chmurze: litery, cyfry, spacja, kropka, @, - i _.');
        $prefix = trim((string)($in['sms_prefix'] ?? ''));
        if ($prefix !== '' && !preg_match('/^[A-Za-z0-9 .-]{1,20}$/', $prefix)) throw new InvalidArgumentException('Początek SMS-a: do 20 znaków bez polskich liter (np. FEER).');

        org_setting_set('sso_logto_url', $url);
        org_setting_set('sso_logto_m2m_id', $m2m);
        org_setting_set('sso_self_enabled', !empty($in['self_enabled']) ? '1' : '0');
        org_setting_set('sso_login_prompt', !empty($in['login_prompt']) ? '1' : '0');
        org_setting_set('sso_szo_login', !empty($in['szo_login']) ? '1' : '0');
        org_setting_set('sso_szo_app_id', $szoApp);
        org_setting_set('sso_sms_prefix', $prefix);
        org_setting_set('sso_cloud_kind', $kind);
        org_setting_set('sso_cloud_url', $cloudUrl);
        org_setting_set('sso_cloud_user', trim((string)($in['cloud_user'] ?? '')));
        org_setting_set('sso_cloud_group', $group);
        org_setting_set('sso_cloud_quota', $quota);
        org_setting_set('sso_cloud_self', !empty($in['cloud_self']) ? '1' : '0');

        $map = ['logto_m2m_secret' => 'sso_logto_m2m_secret', 'webhook_key' => 'sso_logto_webhook_key', 'cloud_pass' => 'sso_cloud_pass',
                'szo_app_secret' => 'sso_szo_app_secret'];
        foreach ($map as $field => $key) {
            $v = trim((string)($in[$field] ?? ''));
            if (!empty($in[$field . '_clear'])) { org_setting_set($key, ''); continue; }
            if ($v !== '') org_setting_set($key, (string)fcEncrypt($v, 'settings.' . $key));
        }
    }

    /** Hasło do konta Logto/chmury nadawane przez administratora: 14 znaków bez łatwych do pomylenia, każda klasa znaków. */
    public static function generatePassword(int $len = 14): string
    {
        $sets = ['abcdefghijkmnpqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '#%+=?!'];
        $all = implode('', $sets);
        $chars = [];
        foreach ($sets as $s) $chars[] = $s[random_int(0, strlen($s) - 1)];
        while (count($chars) < $len) $chars[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /** Publiczne adresy SZO dla Logto (pokazywane adminowi do wklejenia w konsoli Logto). */
    public static function webhookUrl(): string
    {
        return rtrim(APP_URL, '/') . '/api/v1/logto_webhook.php';
    }

    public static function smsUrl(): string
    {
        return rtrim(APP_URL, '/') . '/api/v1/logto_sms.php';
    }
}
