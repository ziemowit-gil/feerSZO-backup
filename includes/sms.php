<?php
/**
 * SMS integration — obsługuje smsapi.pl (SDK OAuth), SerwerSMS.pl (HTTPS API v2), Twilio i HTTP Request (dowolna brama przez URL).
 * Dostawca główny: ustawienie `sms_provider` ('smsapi' | 'serwersms' | 'sendly' | 'twilio' | 'httprequest').
 * Dostawca zapasowy (inny operator): `sms_fallback_provider` — gdy główny zawiedzie, system
 *   automatycznie ponawia wysyłkę przez niego (np. główny smsapi.pl → zapasowy Twilio albo brama HTTP).
 * Łańcuch wysyłki (sms_provider_chain) składa się wyłącznie z dostawców z KOMPLETNĄ konfiguracją:
 *   dostawca bez konfiguracji jest pomijany po cichu, a kanał jest gotowy (sms_channel_ready), gdy
 *   skonfigurowany jest choć jeden z nich — także sam zapasowy.
 * Łańcuch przy wysyłce kodów: główny SMS → zapasowy SMS → e-mail (sms_send_with_fallback).
 * Każde przełączenie na zapasowego i każda całkowita porażka trafia do audytu (sms.fallback / sms.failed,
 * numer zamaskowany, adresy z kluczami API wycięte z komunikatów błędów).
 *
 * SMSAPI.pl: preferowany token OAuth (sms_api_token); fallback login+hasło MD5.
 * SerwerSMS.pl: token API (sms_serwersms_token) albo użytkownik API (login+hasło). Kanał: normalny (FULL, domyślny) z nazwą nadawcy
 *   sms_serwersms_sender — domyślnie „tte”, zarejestrowaną w panelu SerwerSMS — albo ECO (bez nazwy nadawcy): sms_serwersms_channel.
 * Sendly (sendly.link): token API (sms_sendly_token), POST {api}/api/sms JSON {to, body}, nagłówek Authorization: Bearer; nadawca (Sender ID
 *   do 11 znaków) jest przypisany do usługi w panelu Sendly — API go nie przyjmuje, więc nazwa nadawcy z Ustawień SMS go nie zmienia.
 * RODZAJ SMS ($kind, ostatni parametr sms_send*): klucz z katalogu modules/sms_senders (np. 'twofa_code', 'ti_lesson_added'). Dla każdego
 *   rodzaju można w Ustawieniach SMS wybrać nadawcę: nazwę albo ECO (bez nazwy nadawcy) — ustawienie sms_sender_kind_<rodzaj>,
 *   patrz sms_kind_sender(). Brak ustawienia = domyślny nadawca dostawcy. Twilio wysyła zawsze z numeru nadawcy z konfiguracji.
 * Limity czasu: SDK smsapi (cURL) 5 s połączenie / 20 s całość, HTTP 8 s połączenie / 15 s odpowiedź — bez nich
 * niedostępny operator blokowałby wysyłkę na minuty i do zapasowego nigdy by nie doszło.
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

/**
 * Przełącznik `sms_enabled` dotyczy LOGOWANIA SMS-em (zakładka „Kod SMS”
 * na stronie logowania i 2FA) — tak opisuje go admin/sms_settings.php.
 * Kanał WYSYŁKI powiadomień nie zależy od tego przełącznika: używaj
 * sms_channel_ready() — kanał jest zawsze włączony, o ile aktywny dostawca
 * ma kompletną konfigurację.
 */
function sms_is_enabled(): bool {
    return sms_setting('sms_enabled') === '1';
}

/** Obsługiwani dostawcy: klucz => etykieta. */
function sms_providers(): array {
    return ['smsapi' => 'smsapi.pl', 'serwersms' => 'SerwerSMS.pl', 'sendly' => 'Sendly', 'twilio' => 'Twilio', 'httprequest' => 'HTTP Request'];
}

function sms_provider_label(string $provider): string {
    return sms_providers()[$provider] ?? $provider;
}

/** Czy konfiguracja podanego dostawcy jest kompletna (da się przez niego wysłać SMS). */
function sms_provider_ready(string $provider): bool {
    return match ($provider) {
        'twilio'      => sms_setting('sms_twilio_sid') !== ''
                      && sms_setting('sms_twilio_token') !== ''
                      && sms_setting('sms_twilio_from') !== '',
        'httprequest' => sms_setting('sms_http_url') !== '',
        'smsapi'      => sms_setting('sms_api_token') !== ''
                      || (sms_setting('sms_api_login') !== '' && sms_setting('sms_api_password') !== ''),
        'sendly'      => sms_setting('sms_sendly_token') !== '',
        'serwersms'   => sms_setting('sms_serwersms_token') !== ''
                      || (sms_setting('sms_serwersms_login') !== '' && sms_setting('sms_serwersms_password') !== ''),
        default       => false,
    };
}

/**
 * Kanał wysyłki SMS gotowy: skonfigurowany jest główny ALBO zapasowy dostawca.
 * (Dawniej liczył się tylko główny — przy niekompletnym głównym powiadomienia nie szły wcale,
 * mimo gotowego zapasowego.)
 */
function sms_channel_ready(): bool {
    return sms_provider_chain() !== [];
}

/** Aktywny dostawca: dowolny z sms_providers(), domyślnie 'smsapi'. */
function sms_provider(): string {
    $p = sms_setting('sms_provider');
    return isset(sms_providers()[$p]) ? $p : 'smsapi';
}

/**
 * Zapasowy dostawca SMS używany, gdy główny zawiedzie. '' = brak (wyłączony).
 * W naszym wdrożeniu: główny smsapi.pl, zapasowy Twilio (albo brama HTTP innego operatora).
 */
function sms_provider_fallback(): string {
    $p = sms_setting('sms_fallback_provider');
    return isset(sms_providers()[$p]) ? $p : '';
}

/**
 * Kolejność wysyłki: [główny, zapasowy] — tylko dostawcy z kompletną konfiguracją, bez powtórzeń
 * (zapasowy taki sam jak główny jest ignorowany).
 *
 * @return list<string>
 */
function sms_provider_chain(): array {
    $chain = [];
    foreach ([sms_provider(), sms_provider_fallback()] as $p) {
        if ($p !== '' && !in_array($p, $chain, true) && sms_provider_ready($p)) $chain[] = $p;
    }
    return $chain;
}

/**
 * Kanał SerwerSMS (ustawienie `sms_serwersms_channel`): 'full' — normalny, z nazwą nadawcy (domyślnie) albo 'eco' — bez własnej
 * nazwy nadawcy.
 */
function sms_serwersms_channel(): string {
    return sms_setting('sms_serwersms_channel') === 'eco' ? 'eco' : 'full';
}

/**
 * Nadawca SerwerSMS wysyłki domyślnej: nazwa z ustawienia `sms_serwersms_sender` (puste → „tte”) w kanale normalnym,
 * w kanale ECO '' (bez nazwy nadawcy).
 */
function sms_serwersms_sender(): string {
    if (sms_serwersms_channel() === 'eco') return '';
    $s = trim(sms_setting('sms_serwersms_sender'));
    return $s !== '' ? $s : 'tte';
}

/** Klucz ustawienia z nadawcą dla rodzaju SMS albo '' gdy nazwa rodzaju jest niepoprawna (dozwolone: a-z, 0-9, _; do 40 znaków). */
function sms_kind_setting_key(string $kind): string {
    return preg_match('/^[a-z0-9_]{1,40}$/', $kind) ? 'sms_sender_kind_' . $kind : '';
}

/**
 * Nadawca ustawiony dla rodzaju SMS (Ustawienia SMS → „Nadawca wg rodzaju SMS”):
 *   null — nie ustawiono (obowiązuje domyślny nadawca dostawcy),
 *   ''   — ECO: bez nazwy nadawcy (SerwerSMS: kanał ECO; smsapi.pl i brama HTTP: domyślny nadawca konta u dostawcy),
 *   inaczej — nazwa nadawcy (SerwerSMS: kanał normalny z tą nazwą; smsapi.pl: pole from; brama HTTP: {sender}).
 */
function sms_kind_sender(string $kind): ?string {
    $key = sms_kind_setting_key($kind);
    if ($key === '') return null;
    $v = trim(sms_setting($key));
    if ($v === '') return null;
    return strcasecmp($v, 'ECO') === 0 ? '' : $v;
}

/**
 * Nadawca smsapi.pl: ustawiony dla rodzaju SMS albo wspólna „Nazwa nadawcy”. '' = bez pola from (domyślny nadawca konta);
 * stara metoda (login + hasło, $legacy) bez żadnej nazwy wysyła jako „INFO”, jak dotąd.
 */
function sms_smsapi_sender(string $kind, bool $legacy): string {
    return sms_kind_sender($kind) ?? (sms_setting('sms_sender_name') ?: ($legacy ? 'INFO' : ''));
}

/** Router: wyślij wiadomość przez wskazanego dostawcę. Rzuca wyjątek przy błędzie. */
function _sms_dispatch(string $provider, string $phone, string $message, string $kind = ''): void {
    if ($provider === 'twilio') {
        _sms_send_twilio($phone, $message);
    } elseif ($provider === 'serwersms') {
        _sms_send_serwersms($phone, $message, $kind);
    } elseif ($provider === 'sendly') {
        _sms_send_sendly($phone, $message, $kind);
    } elseif ($provider === 'httprequest') {
        _sms_send_httprequest($phone, $message, $kind);
    } else {
        _sms_send_smsapi($phone, $message, $kind);
    }
}

/**
 * Normalizuje numer do formatu 48XXXXXXXXX (cyfry, bez +).
 */
function sms_normalize_phone(string $phone): string {
    $p = preg_replace('/\D/', '', $phone);
    if (strlen($p) === 9) $p = '48' . $p;
    return $p;
}

/**
 * Tekst do SMS-a w samym ASCII: polskie litery → bez ogonków, typograficzne cudzysłowy/myślniki/wielokropek → zwykłe znaki,
 * reszta spoza ASCII przez iconv TRANSLIT, a to, czego nie da się przełożyć, jest pomijane lub zamieniane na „?”.
 * Dla kanałów bez UTF (SerwerSMS ECO) — i krótsze wiadomości (160 znaków w segmencie zamiast 70).
 */
function sms_ascii(string $s): string {
    $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                    'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
                    '„' => '"', '”' => '"', '“' => '"', '’' => "'", '‘' => "'", '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ']);
    if (preg_match('/[^\x00-\x7F]/', $s)) {
        $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;
        $s = $t !== false ? $t : $s;
        $s = (string)preg_replace('/[^\x00-\x7F]/', '?', $s);       // co zostało spoza ASCII — nie może wyjść do bramki
    }
    return $s;
}

// ─────────────────────────────────────────────────────────────────────────────
// Publiczne API
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Wycina z komunikatu błędu adresy URL (w zapytaniu bywają klucze API, numer i treść SMS) i skraca go —
 * komunikaty trafiają do audytu, logów i, przy kodach, do e-maila.
 */
function sms_scrub(string $text): string {
    $text = (string)preg_replace('~https?://[^\s)\'"]+~i', '[URL]', $text);
    return trim(mb_substr($text, 0, 300));
}

/**
 * Informacja o ostatniej wysyłce w tym żądaniu: ['provider' => kto wysłał, 'fallback' => czy przez zapasowego,
 * 'errors' => [dostawca => komunikat błędu]]. Pozwala ekranowi (np. testowi w ustawieniach) pokazać, że główny
 * zawiódł, choć SMS dotarł.
 */
function sms_last_send(?array $set = null): array {
    static $last = [];
    if ($set !== null) $last = $set;
    return $last;
}

/**
 * Zdarzenie wysyłki do dziennika: error_log + audyt (sms.fallback — przełączono na zapasowego, sms.failed — zawiodli
 * wszyscy). Numer zamaskowany, komunikaty oczyszczone z adresów. Nigdy nie przerywa wysyłki.
 */
function sms_log_event(string $event, string $phone, array $errors, string $used = '', string $kind = ''): void {
    try {
        $ctx = ['to' => $phone !== '' ? '***' . substr($phone, -3) : '', 'errors' => array_map('sms_scrub', $errors)];
        if ($used !== '') $ctx['used'] = $used;
        if ($kind !== '') $ctx['kind'] = $kind;
        error_log('[SMS] ' . $event . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE));
        if (!function_exists('audit_log')) require_once dirname(__DIR__) . '/modules/audit_logs/logic/audit_logs.php';
        audit_log('sms.' . $event, $ctx, 0);     // zdarzenie systemowe — bez current_user() (nie otwiera sesji w cronie, panelach i CLI)
    } catch (\Throwable $e) { /* dziennik nie może psuć wysyłki */ }
}

/**
 * Wyślij SMS: najpierw przez głównego dostawcę, a gdy zawiedzie — przez zapasowego (innego operatora).
 * Dostawcy bez kompletnej konfiguracji są pomijani. Zwraca klucz dostawcy, który wysłał
 * ('smsapi' | 'serwersms' | 'sendly' | 'twilio' | 'httprequest'), albo '' gdy SMS świadomie nie wysłano (kursant wirtualny).
 * Rzuca wyjątek dopiero, gdy zawiodą wszyscy: przy jednym dostawcy — jego oryginalny błąd, przy dwóch —
 * RuntimeException z komunikatami obu. Gdy nie skonfigurowano żadnego — błąd konfiguracji głównego.
 * $kind: rodzaj SMS z katalogu (decyduje o nadawcy — patrz sms_kind_sender()); '' = bez rodzaju, domyślny nadawca.
 */
function sms_send(string $phone, string $message, string $kind = ''): string {
    require_once __DIR__ . '/ti_virtual.php';
    if (ti_virtual_blocked('', $phone)) { sms_last_send(['provider' => '', 'fallback' => false, 'errors' => []]); return ''; }   // kursant wirtualny — bez SMS
    $phone = sms_normalize_phone($phone);

    // Godziny wysyłki 7:00–22:00 (inc/smsQuietHours): poza nimi SMS systemowy czeka w kolejce do 7:00.
    // Kody jednorazowe i wysyłki ręczne kierownika/administratora wychodzą zawsze.
    require_once dirname(__DIR__) . '/inc/smsQuietHours/smsQuietHours.php';
    if (empty($GLOBALS['__sms_quiet_bypass']) && !smsQuietAllowed($kind)) {
        smsQuietDefer($phone, $message, $kind);
        sms_last_send(['provider' => 'odlozony', 'fallback' => false, 'errors' => []]);
        return 'odlozony';   // niepusty wynik = „przyjęty do wysyłki” dla wywołujących
    }

    $chain = sms_provider_chain();
    if (!$chain) {
        _sms_dispatch(sms_provider(), $phone, $message, $kind);  // nic nie skonfigurowano → wyjątek z podpowiedzią, czego brakuje
        return sms_provider();
    }

    $errors = []; $first = null;
    foreach ($chain as $provider) {
        try {
            _sms_dispatch($provider, $phone, $message, $kind);
        } catch (\Throwable $e) {
            $errors[$provider] = $e->getMessage();
            $first ??= $e;
            continue;
        }
        $used_fallback = $errors !== [];
        sms_last_send(['provider' => $provider, 'fallback' => $used_fallback, 'errors' => array_map('sms_scrub', $errors)]);
        if ($used_fallback) sms_log_event('fallback', $phone, $errors, $provider, $kind);
        return $provider;
    }

    sms_last_send(['provider' => '', 'fallback' => false, 'errors' => array_map('sms_scrub', $errors)]);
    sms_log_event('failed', $phone, $errors, '', $kind);
    if (count($errors) === 1) throw $first;                     // jeden dostawca → oryginalny błąd
    $parts = [];
    foreach ($errors as $provider => $msg) {
        $parts[] = ($parts ? 'Zapasowy' : 'Główny') . ' (' . $provider . '): ' . sms_scrub($msg);
    }
    throw new RuntimeException('Wysyłka SMS nieudana u obu dostawców. ' . implode(' | ', $parts));
}

/**
 * Wysyła SMS WYŁĄCZNIE przez wskazanego dostawcę — bez łańcucha i bez pominięć (np. test w ustawieniach:
 * „czy zapasowy w ogóle działa”). Rzuca wyjątek przy błędzie, także gdy dostawca nie jest skonfigurowany.
 */
function sms_send_via(string $provider, string $phone, string $message, string $kind = ''): void {
    if (!isset(sms_providers()[$provider])) throw new InvalidArgumentException('Nieznany dostawca SMS.');
    _sms_dispatch($provider, sms_normalize_phone($phone), $message, $kind);
}

/**
 * Wysyła SMS; jeśli dostawca zgłosi błąd, wysyła kod e-mailem jako fallback.
 * Zwraca 'sms' gdy SMS dotarł, 'email' gdy użyto fallbacku.
 * Rzuca wyjątek tylko gdy obie metody zawiodą (brak adresu e-mail).
 *
 * @param string $email  Adres e-mail do fallbacku ('' = brak fallbacku, rzuci wyjątek jak sms_send).
 * @param string $kind   Rodzaj SMS (patrz sms_send).
 */
function sms_send_with_fallback(string $phone, string $message, string $email = '', string $kind = ''): string {
    try {
        sms_send($phone, $message, $kind);
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

/**
 * Pomocnik: wysyła żądanie HTTP i zwraca [body, http_code].
 * Limity: 8 s na połączenie (default_socket_timeout — strumienie HTTP nie mają osobnego limitu połączenia) i 15 s
 * na odpowiedź. Komunikat błędu nie zawiera adresu — w zapytaniu są klucze API, numer i treść SMS.
 * $verify_tls: sprawdza certyfikat serwera (łańcuch CA i nazwę hosta). Domyślnie wyłączone — tak działają od początku smsapi.pl,
 * Twilio i brama HTTP; włącza je SerwerSMS, do którego idzie token API.
 */
function _sms_http(string $url, string $method, array $headers, string $body = '', bool $verify_tls = false): array {
    $ctx = stream_context_create([
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'ignore_errors' => true,
            'timeout'       => 15,
        ],
        'ssl' => [
            'verify_peer'      => $verify_tls,
            'verify_peer_name' => $verify_tls,
        ],
    ]);
    $old_timeout = ini_set('default_socket_timeout', '8');
    // Tryb ścisły zbiera WSZYSTKIE ostrzeżenia strumienia: przy odrzuceniu certyfikatu PHP kończy ogólnym „Failed to open stream”,
    // a powód („certificate verify failed”) jest we wcześniejszym ostrzeżeniu, którego error_get_last() już nie widzi.
    $warns = [];
    if ($verify_tls) set_error_handler(function (int $no, string $str) use (&$warns): bool { $warns[] = $str; return true; });
    try {
        $resp = $verify_tls ? file_get_contents($url, false, $ctx) : @file_get_contents($url, false, $ctx);
        $err  = $resp === false ? ($verify_tls ? ['message' => implode(' ', $warns)] : error_get_last()) : null;
    } finally {
        if ($verify_tls) restore_error_handler();
        if ($old_timeout !== false) ini_set('default_socket_timeout', (string)$old_timeout);
    }
    if ($resp === false) {
        throw new RuntimeException('Błąd połączenia HTTP: ' . sms_scrub((string)($err['message'] ?? 'nieznany błąd')));
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

function _sms_send_smsapi(string $phone, string $message, string $kind = ''): void {
    $token  = sms_setting('sms_api_token');
    $sender = sms_smsapi_sender($kind, false);                                      // '' = bez pola from (domyślny nadawca konta)

    // Fallback: jeśli brak nowego tokenu OAuth — użyj starego API (login+hasło)
    if (!$token) {
        _sms_send_smsapi_legacy($phone, $message, $kind);
        return;
    }

    $factory = new Psr17Factory();
    // Limity czasu: domyślnie cURL czeka na połączenie ~5 min — niedostępny operator blokowałby wysyłkę i zapasowy
    // dostawca nigdy by nie dostał szansy.
    $client  = new SmsapiHttpClient(
        new \Http\Client\Curl\Client($factory, $factory, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20]),
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
function _sms_send_smsapi_legacy(string $phone, string $message, string $kind = ''): void {
    $login  = sms_setting('sms_api_login');
    $pass   = sms_setting('sms_api_password');
    $sender = sms_smsapi_sender($kind, true);

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
    // Adres API można przestawić ustawieniem `sms_twilio_api_base` (np. brzeg regionalny Twilio, serwer pośredniczący)
    $base = rtrim(sms_setting('sms_twilio_api_base') ?: 'https://api.twilio.com', '/');
    $url  = "{$base}/2010-04-01/Accounts/{$sid}/Messages.json";

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
 * SerwerSMS.pl — HTTPS API v2 (https://dev.serwersms.pl/https-api-v2/wprowadzenie): POST {api}/messages/send_sms.json.
 *
 * Autoryzacja: token API w nagłówku `Authorization: Bearer` (zalecany; panel SerwerSMS → Ustawienia interfejsów → HTTPS API →
 * Tokeny API) albo użytkownik API w polach username/password; token ma pierwszeństwo. Certyfikat serwera jest weryfikowany —
 * do SerwerSMS idzie token, a PHP bez pakietu CA zgłasza to osobnym, czytelnym błędem.
 *
 * Kanał domyślny: normalny, FULL — z nazwą nadawcy (sms_serwersms_sender(), domyślnie „tte”; musi być zarejestrowana i zatwierdzona
 * w panelu SerwerSMS); tekst z polskimi znakami wychodzi jako UTF (utf=1; SMS ma wtedy 70 znaków), czysty ASCII — zwykłym
 * kodowaniem (160). Kanał ECO (sms_serwersms_channel = eco albo „ECO” ustawione dla rodzaju SMS) idzie bez pola `sender`
 * i bez UTF — tekst zamieniamy na ASCII. Nadawcę dla rodzaju SMS ($kind) ustawia sms_kind_sender().
 * Nigdy nie wysyłamy `speed` (kanał jakościowy jest płatny dodatkowo).
 *
 * Powodzenie: {"success":true,"queued":N} z N ≥ 1. Błąd API ma postać {"error":{"code","type","message"}} i bywa zwracany
 * z HTTP 200, więc treść sprawdzamy zawsze (niezależnie od statusu). `details=1` daje powód odrzucenia numeru (np. 3103).
 * Adres API przestawia ustawienie `sms_serwersms_api_base` (dokumentacja podaje zapasowy host https://s1api2.serwersms.pl).
 */
function _sms_send_serwersms(string $phone, string $message, string $kind = ''): void {
    $token  = trim(sms_setting('sms_serwersms_token'));
    $login  = trim(sms_setting('sms_serwersms_login'));
    $pass   = sms_setting('sms_serwersms_password');
    $sender = sms_kind_sender($kind) ?? sms_serwersms_sender();          // '' = kanał ECO (bez nazwy nadawcy)

    if ($token === '' && ($login === '' || $pass === '')) {
        throw new RuntimeException('Brak tokenu API ani loginu i hasła użytkownika API SerwerSMS. Skonfiguruj w: Administracja → Ustawienia SMS.');
    }
    $base = rtrim(trim(sms_setting('sms_serwersms_api_base')) ?: 'https://api2.serwersms.pl', '/');

    $eco    = $sender === '';
    $text   = $message;
    $fields = ['phone' => '+' . ltrim($phone, '+'), 'details' => '1'];
    if (preg_match('/[^\x00-\x7F]/', $text)) {                   // znaki spoza ASCII (np. polskie litery): UTF tylko w FULL, ECO → ASCII
        if ($eco) $text = sms_ascii($text); else $fields['utf'] = '1';
    }
    $fields['text'] = $text;
    if (!$eco) $fields['sender'] = $sender;

    $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    } else {
        $fields['username'] = $login;
        $fields['password'] = $pass;
    }
    $body      = http_build_query($fields);
    $headers[] = 'Content-Length: ' . strlen($body);

    try {
        [$resp, $code] = _sms_http($base . '/messages/send_sms.json', 'POST', $headers, $body, true);
    } catch (RuntimeException $e) {
        if (stripos($e->getMessage(), 'SSL') !== false || stripos($e->getMessage(), 'certificate') !== false) {
            throw new RuntimeException('Błąd SerwerSMS: nie udało się zweryfikować certyfikatu TLS serwera API (PHP nie ma pakietu certyfikatów CA albo '
                . 'połączenie jest podmieniane) — ustaw openssl.cafile w php.ini. Szczegóły: ' . $e->getMessage());
        }
        throw $e;
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException("Błąd SerwerSMS: HTTP {$code}, odpowiedź: " . sms_scrub(mb_substr($resp, 0, 120)));
    }
    $err = $data['error'] ?? null;
    if ($err === null && !empty($data['success']) && (int)($data['queued'] ?? 0) >= 1) return;          // przyjęte do kolejki

    if ($err === null) {                                    // bez błędu API, ale nic nie weszło do kolejki — powód podaje pierwsza pozycja
        $item = is_array($data['items'][0] ?? null) ? $data['items'][0] : [];
        $err  = ['code' => $item['error_code'] ?? null, 'message' => $item['error_message'] ?? 'wiadomość nie trafiła do kolejki'];
    }
    $ecode = is_array($err) ? ($err['code'] ?? null) : null;
    $emsg  = trim((string)(is_array($err) ? ($err['message'] ?? '') : $err));
    $hint  = match ((int)$ecode) {
        1003, 1009 => ' Adres IP serwera nie jest dopuszczony — sprawdź ograniczenia IP w ustawieniach HTTPS API w panelu SerwerSMS.',
        3000, 3002 => ' Wyczerpany limit wiadomości na koncie SerwerSMS — doładuj konto.',
        4402       => " Nazwa nadawcy „{$sender}” nie jest zarejestrowana w SerwerSMS — zarejestruj ją w panelu albo wpisz inną, zarejestrowaną, w Ustawieniach SMS (albo przełącz kanał na ECO).",
        default    => '',
    };
    throw new RuntimeException('Błąd SerwerSMS: ' . ($emsg !== '' ? $emsg : "HTTP {$code}") . ($ecode !== null && $ecode !== '' ? " (kod {$ecode})" : '') . '.' . $hint);
}

/**
 * Sendly (https://sendly.link/pl/) — POST {api}/api/sms, JSON {"to": "48732129000", "body": "..."}, autoryzacja `Authorization: Bearer <token>`
 * (token z panelu app.sendly.link). Numer bez plusa. Odpowiedź zawiera `message_id`; raporty doręczeń (DELIVERED/ERROR) przychodzą webhookiem
 * skonfigurowanym w panelu Sendly (Bramka SMS → Ustawienia usługi) — tu nie są obsługiwane. Nadawca (Sender ID, do 11 znaków, weryfikacja w UKE)
 * jest przypisany do usługi w panelu, więc $kind nie zmienia nadawcy. Adres API przestawia `sms_sendly_api_base` (domyślnie https://api.sendly.link).
 * Powodzenie = HTTP 2xx; token leci w nagłówku, więc certyfikat serwera jest zawsze weryfikowany.
 */
function _sms_send_sendly(string $phone, string $message, string $kind = ''): void {
    $token = trim(sms_setting('sms_sendly_token'));
    if ($token === '') {
        throw new RuntimeException('Brak tokenu API Sendly. Skonfiguruj w: Administracja → Ustawienia SMS.');
    }
    $base = rtrim(trim(sms_setting('sms_sendly_api_base')) ?: 'https://api.sendly.link', '/');
    $body = json_encode(['to' => ltrim($phone, '+'), 'body' => $message], JSON_UNESCAPED_UNICODE);
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json', 'Content-Length: ' . strlen($body)];

    try {
        [$resp, $code] = _sms_http($base . '/api/sms', 'POST', $headers, $body, true);
    } catch (RuntimeException $e) {
        if (stripos($e->getMessage(), 'SSL') !== false || stripos($e->getMessage(), 'certificate') !== false) {
            throw new RuntimeException('Błąd Sendly: nie udało się zweryfikować certyfikatu TLS serwera API (PHP nie ma pakietu certyfikatów CA albo '
                . 'połączenie jest podmieniane) — ustaw openssl.cafile w php.ini. Szczegóły: ' . $e->getMessage());
        }
        throw $e;
    }
    if ($code >= 200 && $code < 300) return;

    $data = json_decode($resp, true);
    $emsg = is_array($data) ? trim((string)($data['message'] ?? $data['error'] ?? $data['detail'] ?? '')) : '';
    $hint = match (true) {
        $code === 401, $code === 403 => ' Sprawdź token API w Ustawieniach SMS (Sendly → panel → token).',
        $code === 402                => ' Brak środków na koncie Sendly — doładuj konto.',
        $code === 429                => ' Przekroczono limit zapytań — spróbuj za chwilę.',
        default                      => '',
    };
    throw new RuntimeException('Błąd Sendly: HTTP ' . $code . ($emsg !== '' ? ' — ' . sms_scrub($emsg) : ($data === null ? ' — ' . sms_scrub(mb_substr($resp, 0, 120)) : '')) . '.' . $hint);
}

/**
 * HTTP Request SMS — wysyła GET lub POST na skonfigurowany URL (dowolna brama SMS innego operatora).
 * URL może zawierać placeholdery: {phone} (48XXXXXXXXX), {phone9} (same 9 cyfr), {phone_plus} (+48XXXXXXXXX),
 * {message} (też {text}, {sms}) i {sender} (nazwa nadawcy z ustawień) — wszystkie url-encodowane.
 * Przykład: https://sms.brama.pl/send?apikey=XYZ&to={phone}&from={sender}&text={message}
 *
 * Powodzenie = HTTP 2xx. Bramy zwracające 200 także przy błędzie ("ERROR: brak środków") ustaw tekstem sukcesu
 * (`sms_http_ok_text`, np. "OK"): odpowiedź bez niego to porażka i system przechodzi na zapasowego dostawcę.
 * Bez tego ustawienia nie zgadujemy po treści — fałszywa porażka wysłałaby ten sam SMS drugi raz.
 */
function _sms_send_httprequest(string $phone, string $message, string $kind = ''): void {
    $url_tpl = sms_setting('sms_http_url');
    $method  = strtoupper(sms_setting('sms_http_method') ?: 'GET');

    if (!$url_tpl) {
        throw new RuntimeException('Brak URL dla dostawcy HTTP Request. Skonfiguruj w: Administracja → Ustawienia SMS.');
    }

    // Wstaw placeholdery w URL
    $sender = sms_kind_sender($kind) ?? (sms_setting('sms_sender_name') ?: 'INFO');     // '' (ECO) → {sender} puste
    $url = str_replace(
        ['{phone}', '{phone9}', '{phone_plus}', '{message}', '{text}', '{sms}', '{sender}'],
        [urlencode($phone), urlencode(substr($phone, -9)), urlencode('+' . $phone), urlencode($message), urlencode($message), urlencode($message), urlencode($sender)],
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
        $preview = sms_scrub(mb_substr($resp, 0, 120));
        throw new RuntimeException("HTTP SMS: serwer zwrócił HTTP {$code}. Odpowiedź: {$preview}");
    }
    $ok_text = trim(sms_setting('sms_http_ok_text'));
    if ($ok_text !== '' && stripos($resp, $ok_text) === false) {
        throw new RuntimeException('HTTP SMS: odpowiedź bramy nie zawiera oczekiwanego tekstu sukcesu „' . $ok_text . '”. Odpowiedź: ' . sms_scrub(mb_substr($resp, 0, 120)));
    }
}

/**
 * Czy numer jest komórkowy (polska numeracja).
 *
 * SMS na numer stacjonarny nie dojdzie — operator go odrzuci albo, co gorsza,
 * przyjmie i naliczy. Dlatego kanał SMS ma być proponowany tylko tam, gdzie
 * jest szansa, że wiadomość dotrze.
 *
 * Zasada: po odrzuceniu prefiksu kraju zostaje dziewięć cyfr, a dwie pierwsze
 * należą do zakresów przydzielonych sieciom komórkowym (UKE). Numery zagraniczne
 * (inny prefiks niż 48) przepuszczamy — nie znamy ich planów numeracji, a blokada
 * byłaby gorsza od wysłania: cudzoziemiec z komórką jest częstszy niż pomyłka.
 */
function sms_is_mobile(?string $phone): bool
{
    $d = preg_replace('/\D/', '', (string)$phone) ?? '';
    if ($d === '') return false;

    // Numer międzynarodowy spoza Polski — nie oceniamy
    if (strlen($d) > 11 || (strlen($d) === 11 && !str_starts_with($d, '48'))) return true;

    if (str_starts_with($d, '48') && strlen($d) === 11) $d = substr($d, 2);
    if (strlen($d) !== 9) return false;

    // Prefiksy komórkowe wg planu numeracji UKE. Lista zamknięta, bo zakresy
    // przeplatają się ze strefami stacjonarnymi: 57 to komórka, ale 58 to Gdańsk,
    // 60 komórka, a 61 Poznań — reguła „od 50 do 79" wysyłałaby SMS-y na telefony
    // stacjonarne całego Pomorza i Wielkopolski.
    return in_array(substr($d, 0, 2),
        ['45', '50', '51', '53', '57', '60', '66', '69', '72', '73', '78', '79', '88'], true);
}

/** Numer w formacie do pokazania: 501 234 567. */
function sms_format_phone(?string $phone): string
{
    $d = preg_replace('/\D/', '', (string)$phone) ?? '';
    if (str_starts_with($d, '48') && strlen($d) === 11) $d = substr($d, 2);
    if (strlen($d) !== 9) return trim((string)$phone);
    return substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6, 3);
}
