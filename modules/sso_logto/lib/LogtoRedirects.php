<?php
declare(strict_types=1);

/**
 * modules/sso_logto/lib/LogtoRedirects.php — adresy powrotu aplikacji „Traditional web” (logowanie do SZO) w Logto.
 *
 * LogtoOidc::redirectUri() składa adres z hosta żądania (APP_URL), więc logowanie z aliasu (np. ezd.feer.org.pl)
 * wymaga, żeby Logto znało także https://{alias}/sso/powrot. Bez tego Logto zwraca „invalid_redirect_uri”.
 * Ten plik porównuje adresy z Management API (GET /api/applications/{id}) i dopisuje brakujące (PATCH).
 * Nigdy niczego nie usuwa. Lista hostów: host_aliases() w includes/hostAliases.php + host z APP_URL.
 */
require_once __DIR__ . '/SsoConfig.php';
require_once __DIR__ . '/LogtoApi.php';
require_once __DIR__ . '/LogtoOidc.php';
require_once dirname(__DIR__, 3) . '/includes/hostAliases.php';

final class LogtoRedirects
{
    /** Hosty, z których można się logować: główny (APP_URL) i aliasy serwujące strony. */
    public static function hosts(): array
    {
        $hosts = [];
        $main = strtolower((string)parse_url(APP_URL, PHP_URL_HOST));
        if ($main !== '') $hosts[] = $main;
        foreach (host_alias_serving_hosts() as $h) $hosts[] = $h;
        return array_values(array_unique($hosts));
    }

    /** @return array{redirect:string, logout:string} */
    public static function wanted(string $host): array
    {
        return ['redirect' => 'https://' . $host . '/sso/powrot', 'logout' => 'https://' . $host . '/auth/login.php'];
    }

    /**
     * Stan rejestracji: [app => odpowiedź Logto, rows => [host, redirect(bool), logout(bool)]]. Rzuca LogtoApiException.
     * @return array{app:array, rows:list<array{host:string, redirect:bool, logout:bool}>}
     */
    public static function status(?LogtoApi $api = null): array
    {
        $api ??= SsoConfig::logto();
        $app = self::app($api);
        $meta = (array)($app['oidcClientMetadata'] ?? []);
        $have = array_map('strval', (array)($meta['redirectUris'] ?? []));
        $haveOut = array_map('strval', (array)($meta['postLogoutRedirectUris'] ?? []));
        $rows = [];
        foreach (self::hosts() as $host) {
            $w = self::wanted($host);
            $rows[] = ['host' => $host, 'redirect' => in_array($w['redirect'], $have, true), 'logout' => in_array($w['logout'], $haveOut, true)];
        }
        return ['app' => $app, 'rows' => $rows];
    }

    /**
     * Dopisuje brakujące adresy. Zwraca listę dopisanych (pustą, gdy wszystko już jest — wtedy nie wołamy PATCH).
     * @return list<string>
     */
    public static function apply(?LogtoApi $api = null): array
    {
        $api ??= SsoConfig::logto();
        $st = self::status($api);
        $meta = (array)($st['app']['oidcClientMetadata'] ?? []);
        $redirects = array_values(array_map('strval', (array)($meta['redirectUris'] ?? [])));
        $logouts = array_values(array_map('strval', (array)($meta['postLogoutRedirectUris'] ?? [])));
        $added = [];
        foreach (self::hosts() as $host) {
            $w = self::wanted($host);
            if (!in_array($w['redirect'], $redirects, true)) { $redirects[] = $w['redirect']; $added[] = $w['redirect']; }
            if (!in_array($w['logout'], $logouts, true)) { $logouts[] = $w['logout']; $added[] = $w['logout']; }
        }
        if (!$added) return [];
        // PATCH podmienia cały obiekt oidcClientMetadata, więc wysyłamy go w całości z dopisanymi adresami (nic nie ginie).
        $meta['redirectUris'] = $redirects;
        $meta['postLogoutRedirectUris'] = $logouts;
        $api->request('PATCH', '/api/applications/' . rawurlencode(self::appId()), ['oidcClientMetadata' => $meta]);
        return $added;
    }

    private static function appId(): string
    {
        $id = SsoConfig::get('sso_szo_app_id');
        if ($id === '') throw new LogtoApiException('Brak App ID aplikacji logowania do SZO (zakładka Logto → logowanie do SZO).');
        return $id;
    }

    private static function app(LogtoApi $api): array
    {
        return $api->request('GET', '/api/applications/' . rawurlencode(self::appId()));
    }
}
