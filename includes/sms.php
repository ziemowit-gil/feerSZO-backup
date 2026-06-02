<?php
/**
 * SMS integration — obsługuje smsapi.pl i Twilio.
 * Dostawca wybierany przez ustawienie `sms_provider` ('smsapi' lub 'twilio').
 */

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
 * Wyszukuje konto lokalne powiązane z numerem telefonu przez umowę wolontariacką.
 */
function sms_find_user_by_phone(string $phone): ?array {
    $phone  = sms_normalize_phone($phone);
    $phone9 = substr($phone, -9);

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
    $login  = sms_setting('sms_api_login');
    $pass   = sms_setting('sms_api_password');
    $sender = sms_setting('sms_sender_name') ?: 'INFO';

    if (!$login || !$pass) {
        throw new RuntimeException('Brak loginu lub hasła smsapi.pl. Skonfiguruj w: Administracja → Ustawienia SMS.');
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
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($body),
        ],
        $body
    );

    $data = json_decode($resp, true) ?? [];
    if ($code !== 200 || isset($data['error'])) {
        $msg = $data['message'] ?? $data['invalid_number'] ?? "HTTP $code";
        throw new RuntimeException("Błąd smsapi.pl: $msg");
    }
    if (!empty($data['list'][0]['status'])
        && in_array($data['list'][0]['status'], ['ERROR', 'FAILED'], true)) {
        throw new RuntimeException('Wysyłka nie powiodła się (smsapi status: ' . $data['list'][0]['status'] . ')');
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
