<?php
declare(strict_types=1);

/**
 * modules/sso_logto/lib/LogtoOidc.php — logowanie do SZO przez Logto (OpenID Connect, przepływ „authorization code” + PKCE S256).
 *
 * Polecenie 2026-11-13: „wyłącz logowanie MS365 do SZO, pozostaw je jedynie dla adminów, włącz logowanie przez Logto lub mailem”.
 * W Logto: aplikacja „Traditional web” — Redirect URI = {APP_URL}/sso/powrot, Post sign-out redirect URI = {APP_URL}/auth/login.php.
 * Ustawienia (SsoConfig): sso_szo_login ('1' = przycisk na ekranie logowania), sso_szo_app_id, sso_szo_app_secret (zaszyfrowany).
 *
 * Bezpieczeństwo: state (CSRF) + nonce + PKCE w sesji, ważne 10 min, jednorazowe. Token ID przychodzi bezpośrednio z endpointu
 * tokenów Logto po TLS z uwierzytelnieniem klienta (client_secret_basic) — wg OpenID Connect Core 1.0, 3.1.3.7 p. 6 wtedy
 * zamiast podpisu wystarcza walidacja TLS; sprawdzamy iss, aud, exp, iat i nonce. Certyfikat TLS weryfikowany zawsze.
 *
 * Testy: adres Logto z ustawień (atrapa http://127.0.0.1:…) — discovery z {logto}/oidc/.well-known/openid-configuration.
 */
require_once __DIR__ . '/SsoConfig.php';

final class LogtoOidcException extends RuntimeException {}

final class LogtoOidc
{
    private const K_STATE = 'logto_oidc';   // sesja: [state => [verifier, nonce, redirect, at]]
    private const TTL = 600;

    public static function configured(): bool
    {
        return SsoConfig::get('sso_szo_app_id') !== '' && SsoConfig::has('sso_szo_app_secret');
    }

    /** Przycisk „Zaloguj przez logowanie jednokrotne” — włączony przez administratora i skonfigurowany. */
    public static function enabled(): bool
    {
        return SsoConfig::get('sso_szo_login') === '1' && self::configured();
    }

    public static function redirectUri(): string
    {
        return rtrim(APP_URL, '/') . '/sso/powrot';
    }

    public static function postLogoutUri(): string
    {
        return rtrim(APP_URL, '/') . '/auth/login.php';
    }

    /** Konfiguracja OIDC z discovery (pamiętana w sesji na godzinę). */
    public static function discovery(): array
    {
        $base = SsoConfig::logtoUrl();
        $c = $_SESSION['logto_oidc_disc'] ?? null;
        if (is_array($c) && ($c['_base'] ?? '') === $base && (int)($c['_at'] ?? 0) > time() - 3600) return $c;
        $d = self::http('GET', $base . '/oidc/.well-known/openid-configuration');
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint'] as $k) {
            if (empty($d[$k]) || !is_string($d[$k])) throw new LogtoOidcException('Logto: niepełna konfiguracja OpenID (brak ' . $k . ').');
        }
        $d['_base'] = $base;
        $d['_at'] = time();
        $_SESSION['logto_oidc_disc'] = $d;
        return $d;
    }

    /** Adres strony logowania Logto; zapamiętuje state/nonce/PKCE i adres powrotu w sesji. */
    public static function authUrl(string $redirectAfter): string
    {
        $d = self::discovery();
        $state = self::b64url(random_bytes(24));
        $nonce = self::b64url(random_bytes(24));
        $verifier = self::b64url(random_bytes(48));
        $list = array_filter((array)($_SESSION[self::K_STATE] ?? []), fn($v) => is_array($v) && (int)($v['at'] ?? 0) > time() - self::TTL);
        $list = array_slice($list, -4, null, true);   // kilka kart naraz — ale bez puchnięcia sesji
        $list[$state] = ['verifier' => $verifier, 'nonce' => $nonce, 'redirect' => $redirectAfter, 'at' => time()];
        $_SESSION[self::K_STATE] = $list;
        return $d['authorization_endpoint'] . '?' . http_build_query([
            'client_id'             => SsoConfig::get('sso_szo_app_id'),
            'redirect_uri'          => self::redirectUri(),
            'response_type'         => 'code',
            'scope'                 => 'openid profile email',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Powrót z Logto (?code=&state= albo ?error=). Zwraca: sub, email, email_verified, name, username, id_token, redirect.
     * Wyjątek przy każdym błędzie (komunikat techniczny — do dziennika, nie dla użytkownika).
     */
    public static function callback(array $q): array
    {
        if (!empty($q['error'])) {
            throw new LogtoOidcException('Logto zwróciło błąd: ' . mb_substr((string)$q['error'] . ' ' . (string)($q['error_description'] ?? ''), 0, 300));
        }
        $state = (string)($q['state'] ?? '');
        $code  = (string)($q['code'] ?? '');
        $list  = (array)($_SESSION[self::K_STATE] ?? []);
        $st    = $state !== '' ? ($list[$state] ?? null) : null;
        unset($list[$state]);
        $_SESSION[self::K_STATE] = $list;   // state jednorazowy
        if (!is_array($st) || (int)($st['at'] ?? 0) < time() - self::TTL) throw new LogtoOidcException('Nieznany albo przeterminowany parametr state.');
        if ($code === '') throw new LogtoOidcException('Brak kodu autoryzacji.');

        $d = self::discovery();
        $tok = self::http('POST', $d['token_endpoint'], [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::redirectUri(),
            'code_verifier' => (string)$st['verifier'],
        ], true);
        $idt = (string)($tok['id_token'] ?? '');
        if ($idt === '') throw new LogtoOidcException('Logto nie zwróciło tokenu ID.');
        $c = self::claims($idt);

        $aud = is_array($c['aud'] ?? null) ? $c['aud'] : [(string)($c['aud'] ?? '')];
        if (($c['iss'] ?? '') !== $d['issuer'])                                   throw new LogtoOidcException('Token ID: nieprawidłowy wystawca.');
        if (!in_array(SsoConfig::get('sso_szo_app_id'), $aud, true))             throw new LogtoOidcException('Token ID: nieprawidłowy odbiorca.');
        if ((int)($c['exp'] ?? 0) < time() - 60)                                 throw new LogtoOidcException('Token ID wygasł.');
        if ((int)($c['iat'] ?? 0) > time() + 300)                                throw new LogtoOidcException('Token ID z przyszłości (zegar).');
        if (!hash_equals((string)$st['nonce'], (string)($c['nonce'] ?? '')))     throw new LogtoOidcException('Token ID: nieprawidłowy nonce.');
        if ((string)($c['sub'] ?? '') === '')                                    throw new LogtoOidcException('Token ID bez identyfikatora konta.');

        // E-mail bywa tylko w userinfo (zależnie od ustawień Logto) — dopytujemy, gdy go brak.
        if (empty($c['email']) && !empty($tok['access_token']) && !empty($d['userinfo_endpoint'])) {
            try {
                $ui = self::http('GET', $d['userinfo_endpoint'], null, false, (string)$tok['access_token']);
                if ((string)($ui['sub'] ?? '') === (string)$c['sub']) $c += $ui;
            } catch (\Throwable $e) { error_log('[sso] userinfo: ' . $e->getMessage()); }
        }
        return [
            'sub'            => (string)$c['sub'],
            'email'          => strtolower(trim((string)($c['email'] ?? ''))),
            'email_verified' => !empty($c['email_verified']),
            'name'           => (string)($c['name'] ?? ''),
            'username'       => (string)($c['username'] ?? ''),
            'id_token'       => $idt,
            'redirect'       => (string)($st['redirect'] ?? ''),
        ];
    }

    /** Adres wylogowania z Logto (koniec sesji logowania jednokrotnego) albo '' gdy Logto go nie podaje. */
    public static function endSessionUrl(string $idToken): string
    {
        try { $d = self::discovery(); } catch (\Throwable $e) { return ''; }
        if (empty($d['end_session_endpoint'])) return '';
        return $d['end_session_endpoint'] . '?' . http_build_query([
            'id_token_hint' => $idToken, 'post_logout_redirect_uri' => self::postLogoutUri(), 'client_id' => SsoConfig::get('sso_szo_app_id'),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Zawartość (payload) tokenu JWT — bez sprawdzania podpisu (zob. opis klasy). */
    public static function claims(string $jwt): array
    {
        $p = explode('.', $jwt);
        if (count($p) !== 3) throw new LogtoOidcException('Token ID w złym formacie.');
        $json = json_decode((string)self::b64urlDecode($p[1]), true);
        if (!is_array($json)) throw new LogtoOidcException('Token ID: nieczytelna zawartość.');
        return $json;
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Żądanie HTTP (JSON w odpowiedzi). $form = treść x-www-form-urlencoded; $basic = uwierzytelnienie klienta; $bearer = token. */
    private static function http(string $method, string $url, ?array $form = null, bool $basic = false, string $bearer = ''): array
    {
        if (!function_exists('curl_init')) throw new LogtoOidcException('Brak rozszerzenia PHP cURL.');
        $h = ['Accept: application/json'];
        if ($bearer !== '') $h[] = 'Authorization: Bearer ' . $bearer;
        $ch = curl_init($url);
        $opt = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($basic) {
            $opt[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opt[CURLOPT_USERPWD]  = rawurlencode(SsoConfig::get('sso_szo_app_id')) . ':' . rawurlencode(SsoConfig::szoAppSecret());
        }
        if ($form !== null) {
            $opt[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&');
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $opt[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opt);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        unset($ch);
        if ($raw === false) throw new LogtoOidcException('Logto niedostępne: ' . $err);
        $json = json_decode((string)$raw, true);
        if ($code >= 400 || !is_array($json)) {
            $msg = is_array($json) ? (string)($json['error_description'] ?? $json['error'] ?? $json['message'] ?? '') : '';
            throw new LogtoOidcException('Logto: HTTP ' . $code . ($msg !== '' ? ' — ' . mb_substr($msg, 0, 200) : ''));
        }
        return $json;
    }
}
