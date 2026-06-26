<?php
/**
 * SMS integration — obsługuje smsapi.pl (SDK OAuth) i Twilio.
 * Dostawca wybierany przez ustawienie `sms_provider` ('smsapi' lub 'twilio').
 *
 * SMSAPI.pl: preferowany token OAuth (sms_api_token).
 * Fallback:  login + hasło MD5 (sms_api_login + sms_api_password).
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use Smsapi\Client\SmsapiHttpClient;
use Smsapi\Client\Feature\Sms\Bag\SendSmsBag;

function sms_setting(string $key): string {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
        $cache[$key] = $r['value'] ?? '';
    }
    return $cache[$key];
}

function sms_is_enabled(): bool {
    return sms_setting('sms_enabled') === '1';
}

/** Aktywny dostawca: 'smsapi' (domyślnie), 'twilio' lub 'httprequest' */
function sms_provider(): string {
    $p = sms_setting('sms_provider');
    return in_array($p, ['smsapi', 'twilio', 'httprequest'], true) ? $p : 'smsapi';
}

/**
 * Normalizuje numer do formatu 48XXXXXXXXX (cyfry, bez +).
 */
function sms_normalize_phone(string $phone): string {
    $p = preg_replace('/\D/', '', $phone);
    if (strlen($p) === 9) $p = '48' . $p;
    return $p;
}

// ─────────────────────────────────────────────────────────────────────────────
// Publiczne API
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Wyślij SMS przez aktywnego dostawcę.
 * Rzuca RuntimeException przy błędzie.
 */
function sms_send(string $phone, string $message): void {
    $phone = sms_normalize_phone($phone);
    $prov  = sms_provider();
    if ($prov === 'twilio') {
        _sms_send_twilio($phone, $message);
    } elseif ($prov === 'httprequest') {
        _sms_send_httprequest($phone, $message);
    } else {
        _sms_send_smsapi($phone, $message);
    }
}

/**
 * Wysyła SMS; jeśli dostawca zgłosi błąd, wysyła kod e-mailem jako fallback.
 * Zwraca 'sms' gdy SMS dotarł, 'email' gdy użyto fallbacku.
 * Rzuca wyjątek tylko gdy obie metody zawiodą (brak adresu e-mail).
 *
 * @param string $email  Adres e-mail do fallbacku ('' = brak fallbacku, rzuci wyjątek jak sms_send).
 */
function sms_send_with_fallback(string $phone, string $message, string $email = ''): string {
    try {
        sms_send($phone, $message);
        return 'sms';
    } catch (\Throwable $sms_err) {
        if ($email === '') throw $sms_err;

        // Wytnij kod z wiadomości SMS — zakładamy, że jest 6-cyfrowy ciąg
        preg_match('/\b(\d{6})\b/', $message, $m);
        $code_display = $m[1] ?? '(patrz wyżej)';
        $org  = defined('ORG_NAME') ? ORG_NAME : 'System';
        $subj = "[{$org}] Kod weryfikacyjny (fallback e-mail)";
        $body = '<p>Próba wysłania kodu SMS nie powiodła się (<em>' . htmlspecialchars($sms_err->getMessage(), ENT_QUOTES) . '</em>).</p>'
              . '<p>Twój kod jednorazowy: <strong style="font-size:1.5em;letter-spacing:.15em">' . htmlspecialchars($code_display, ENT_QUOTES) . '</strong></p>'
              . '<p>Kod jest ważny 5 minut. Nie udostępniaj go nikomu.</p>'
              . '<hr><p style="font-size:.85em;color:#666">Wiadomość wygenerowana automatycznie — ' . htmlspecialchars($org, ENT_QUOTES) . '</p>';

        if (function_exists('mail_queue_add')) {
            mail_queue_add($email, '', $subj, $body);
        } else {
            mail($email, $subj, strip_tags($body));
        }
        return 'email';
    }
}

/**
 * Generuje i zapisuje 6-cyfrowy OTP (ważny 5 min).
 * Zwraca kod do wklejenia w wiadomość.
 */
function sms_generate_otp(string $phone, int $user_id): string {
    $phone = sms_normalize_phone($phone);
    db()->prepare("DELETE FROM sms_login_tokens WHERE phone=?")->execute([$phone]);
    db()->prepare("DELETE FROM sms_login_tokens WHERE expires_at < datetime('now')")->execute();

    $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires = date('Y-m-d H:i:s', time() + 300);

    db_insert('sms_login_tokens', [
        'phone'      => $phone,
        'code'       => $code,
        'user_id'    => $user_id,
        'expires_at' => $expires,
    ]);
    return $code;
}

/**
 * Weryfikuje OTP; oznacza jako użyty i zwraca rekord użytkownika lub null.
 */
function sms_verify_otp(string $phone, string $code): ?array {
    $phone = sms_normalize_phone($phone);
    $token = db_one(
        "SELECT * FROM sms_login_tokens
         WHERE phone=? AND code=? AND used_at IS NULL AND expires_at > datetime('now')",
        [$phone, $code]
    );
    if (!$token) return null;

    db()->prepare("UPDATE sms_login_tokens SET used_at=datetime('now') WHERE id=?")->execute([$token['id']]);
    return db_one("SELECT * FROM users WHERE id=? AND is_active=1", [$token['user_id']]);
}

/**
 * Wyszukuje konto lokalne powiązane z numerem telefonu.
 * Sprawdza kolejno:
 *   1. pole phone_number w tabeli users (konto CPC)
 *   2. pole telefon w umowach wolontariackich
 */
function sms_find_user_by_phone(string $phone): ?array {
    $phone  = sms_normalize_phone($phone);
    $phone9 = substr($phone, -9);

    // 1. Bezpośrednie pole phone_number na koncie użytkownika
    $user = db_one(
        "SELECT * FROM users WHERE is_active=1
         AND REPLACE(REPLACE(REPLACE(REPLACE(phone_number,' ',''),'-',''),'+',''),'(','') LIKE ?
         LIMIT 1",
        ['%' . $phone9]
    );
    if ($user) return $user;

    // 2. Fallback: numer telefonu z umowy wolontariackie
    $contract = db_one(
        "SELECT email FROM umowy_wolontariat
         WHERE REPLACE(REPLACE(REPLACE(REPLACE(telefon,' ',''),'-',''),'+',''),'(','') LIKE ?
            OR REPLACE(REPLACE(REPLACE(REPLACE(telefon,' ',''),'-',''),'+',''),'(','') = ?
         ORDER BY created_at DESC LIMIT 1",
        ['%' . $phone9, $phone]
    );
    if (!$contract || !$contract['email']) return null;

    return db_one("SELECT * FROM users WHERE email=? AND is_active=1", [$contract['email']]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Implementacje dostawców (prywatne) — używają file_get_contents + stream_context
// ─────────────────────────────────────────────────────────────────────────────

/** Pomocnik: wysyła żądanie HTTP i zwraca [body, http_code]. */
function _sms_http(string $url, string $method, array $headers, string $body = ''): array {
    $ctx = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'ignore_errors' => true,
            'timeout'       => 15,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        $err = error_get_last();
        throw new RuntimeException('Błąd połączenia HTTP: ' . ($err['message'] ?? 'nieznany błąd'));
    }
    // Pobierz status HTTP z nagłówków odpowiedzi (PHP 8.4+ deprecates $http_response_header)
    $status  = 0;
    $hdrs    = function_exists('http_get_last_response_headers')
               ? (http_get_last_response_headers() ?? [])
               : ($GLOBALS['http_response_header'] ?? []);
    foreach ($hdrs as $h) {
        if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) { $status = (int)$m[1]; break; }
    }
    return [$resp, $status];
}

function _sms_send_smsapi(string $phone, string $message): void {
    $token  = sms_setting('sms_api_token');
    $sender = sms_setting('sms_sender_name') ?: '';

    // Fallback: jeśli brak nowego tokenu OAuth — użyj starego API (login+hasło)
    if (!$token) {
        _sms_send_smsapi_legacy($phone, $message);
        return;
    }

    $factory = new Psr17Factory();
    $client  = new SmsapiHttpClient(
        new \Http\Client\Curl\Client($factory, $factory),
        $factory,
        $factory
    );

    $service = $client->smsapiPlService($token);

    $bag = SendSmsBag::withMessage($phone, $message);
    if ($sender) $bag->from = $sender;

    $result = $service->smsFeature()->sendSms($bag);

    // SDK rzuca wyjątek przy błędzie — jeśli doszło tutaj, wysyłka OK
}

/** Stara metoda login+hasło MD5 — jako fallback gdy brak tokenu OAuth. */
function _sms_send_smsapi_legacy(string $phone, string $message): void {
    $login  = sms_setting('sms_api_login');
    $pass   = sms_setting('sms_api_password');
    $sender = sms_setting('sms_sender_name') ?: 'INFO';

    if (!$login || !$pass) {
        throw new RuntimeException('Brak tokenu OAuth lub loginu/hasła smsapi.pl. Skonfiguruj w: Administracja → Ustawienia SMS.');
    }

    $body = http_build_query([
        'username' => $login,
        'password' => md5($pass),
        'to'       => $phone,
        'message'  => $message,
        'from'     => $sender,
        'format'   => 'json',
    ]);

    [$resp, $code] = _sms_http(
        'https://ssl.smsapi.pl/sms.do',
        'POST',
        ['Content-Type: application/x-www-form-urlencoded', 'Content-Length: ' . strlen($body)],
        $body
    );

    $data = json_decode($resp, true) ?? [];
    if ($code !== 200 || isset($data['error'])) {
        throw new RuntimeException('Błąd smsapi.pl: ' . ($data['message'] ?? "HTTP $code"));
    }
    if (!empty($data['list'][0]['status']) && in_array($data['list'][0]['status'], ['ERROR', 'FAILED'], true)) {
        throw new RuntimeException('smsapi: wysyłka nie powiodła się (status: ' . $data['list'][0]['status'] . ')');
    }
}

function _sms_send_twilio(string $phone, string $message): void {
    $sid   = sms_setting('sms_twilio_sid');
    $token = sms_setting('sms_twilio_token');
    $from  = sms_setting('sms_twilio_from');

    if (!$sid || !$token) {
        throw new RuntimeException('Brak Account SID lub Auth Token Twilio. Skonfiguruj w: Administracja → Ustawienia SMS.');
    }
    if (!$from) {
        throw new RuntimeException('Brak numeru nadawcy Twilio. Skonfiguruj w: Administracja → Ustawienia SMS.');
    }

    // Twilio wymaga E.164: +48XXXXXXXXX
    $to  = '+' . ltrim($phone, '+');
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

    $body = http_build_query(['To' => $to, 'From' => $from, 'Body' => $message]);
    $auth = base64_encode($sid . ':' . $token);

    [$resp, $code] = _sms_http(
        $url,
        'POST',
        [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($body),
        ],
        $body
    );

    $data = json_decode($resp, true) ?? [];

    if ($code !== 201) {
        $msg = $data['message'] ?? $data['code'] ?? "HTTP $code";
        throw new RuntimeException("Błąd Twilio: $msg");
    }
    if (!empty($data['status']) && in_array($data['status'], ['failed', 'undelivered'], true)) {
        throw new RuntimeException('Twilio: wiadomość niedostarczona (status: ' . $data['status'] . ')');
    }
}

/**
 * HTTP Request SMS — wysyła GET lub POST na skonfigurowany URL.
 * URL może zawierać {phone} i {message} jako placeholdery.
 * Przykład: https://sms.brama.pl/send?apikey=XYZ&to={phone}&text={message}
 */
function _sms_send_httprequest(string $phone, string $message): void {
    $url_tpl = sms_setting('sms_http_url');
    $method  = strtoupper(sms_setting('sms_http_method') ?: 'GET');

    if (!$url_tpl) {
        throw new RuntimeException('Brak URL dla dostawcy HTTP Request. Skonfiguruj w: Administracja → Ustawienia SMS.');
    }

    // Wstaw {phone} i {message} w URL
    $url = str_replace(
        ['{phone}', '{message}', '{text}', '{sms}'],
        [urlencode($phone), urlencode($message), urlencode($message), urlencode($message)],
        $url_tpl
    );

    if ($method === 'POST') {
        $body = http_build_query(['phone' => $phone, 'message' => $message, 'text' => $message]);
        [$resp, $code] = _sms_http($url, 'POST', [
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($body),
        ], $body);
    } else {
        [$resp, $code] = _sms_http($url, 'GET', []);
    }

    if ($code < 200 || $code >= 300) {
        $preview = mb_substr($resp, 0, 120);
        throw new RuntimeException("HTTP SMS: serwer zwrócił HTTP {$code}. Odpowiedź: {$preview}");
    }
}
