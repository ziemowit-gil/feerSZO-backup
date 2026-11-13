<?php
/**
 * REST API — kody SMS z Logto (łącznik „HTTP SMS”) wysyłane bramką SMS SZO.
 *
 *   POST /api/v1/logto_sms.php   (Authorization: Bearer <klucz API SZO>, zakres logto:sms)
 *        {"to":"48600100200","type":"SignIn","payload":{"code":"123456"}}
 *        → 200 {"ok":true}
 *
 * Treść składa SZO z rodzaju (type) i kodu — Logto nie może wysłać dowolnego tekstu. Kod: 4–8 cyfr. Numer: E.164
 * (Logto podaje go bez „+”, dopisujemy). Limity jak dla Tajemniczego Klienta: 5 SMS-ów na numer na godzinę, 60 na godzinę
 * i 300 na dobę łącznie. Kod nigdzie nie jest zapisywany (dziennik: skrót numeru). Kody jednorazowe wychodzą także w nocy.
 * Odpowiedź 2xx = dostawca SMS przyjął wiadomość (Logto przerywa logowanie przy błędzie).
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/sms.php';
require_once dirname(__DIR__, 2) . '/modules/sso_logto/lib/SsoConfig.php';

api_auth_migrate();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_error('Method Not Allowed — użyj POST.', 405);
api_require('logto:sms');

const LOGTO_SMS_PER_PHONE_HOUR = 5;
const LOGTO_SMS_PER_HOUR       = 60;
const LOGTO_SMS_PER_DAY        = 300;

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) api_error('Wymagane ciało JSON.', 400);

$phone = preg_replace('/[\s()-]/', '', (string)($in['to'] ?? ''));
if ($phone !== '' && $phone[0] !== '+') $phone = '+' . ltrim($phone, '0');
if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) api_error('Numer telefonu musi być w formacie międzynarodowym (np. 48600100200).', 422);
$code = (string)($in['payload']['code'] ?? '');
if (!preg_match('/^[0-9]{4,8}$/', $code)) api_error('Brak kodu w payload.code (4–8 cyfr) — SZO wysyła tylko kody.', 422);
$type = (string)($in['type'] ?? 'Generic');
if (!sms_channel_ready()) api_error('Brak skonfigurowanego dostawcy SMS w SZO.', 503);

$what = [
    'SignIn' => 'kod logowania', 'Register' => 'kod rejestracji', 'ForgotPassword' => 'kod do zmiany hasla',
    'BindNewIdentifier' => 'kod potwierdzenia numeru', 'MfaVerification' => 'kod weryfikacji', 'BindMfa' => 'kod weryfikacji',
    'UserPermissionValidation' => 'kod potwierdzenia', 'Generic' => 'kod weryfikacyjny', 'Test' => 'kod testowy',
][$type] ?? 'kod weryfikacyjny';
$prefix = preg_replace('/[^A-Za-z0-9 .-]/', '', SsoConfig::smsPrefix()) ?: 'FEER';
$message = sms_ascii($prefix . ': ' . $what . ' ' . $code . '. Nie podawaj go nikomu.');

db()->exec("CREATE TABLE IF NOT EXISTS logto_sms_log (id INTEGER PRIMARY KEY AUTOINCREMENT, target TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT (datetime('now')))");
db()->exec("CREATE INDEX IF NOT EXISTS idx_logto_sms_log ON logto_sms_log(target, created_at)");
$hash = hash('sha256', $phone);
$count = static fn(string $sql, array $p = []): int => (int)(db_one($sql, $p)['c'] ?? 0);
if ($count("SELECT COUNT(*) AS c FROM logto_sms_log WHERE target=? AND created_at >= datetime('now','-1 hour')", [$hash]) >= LOGTO_SMS_PER_PHONE_HOUR) api_error('Limit SMS dla tego numeru. Spróbuj później.', 429);
if ($count("SELECT COUNT(*) AS c FROM logto_sms_log WHERE created_at >= datetime('now','-1 hour')") >= LOGTO_SMS_PER_HOUR) api_error('Przekroczono limit SMS na godzinę.', 429);
if ($count("SELECT COUNT(*) AS c FROM logto_sms_log WHERE created_at >= datetime('now','-1 day')") >= LOGTO_SMS_PER_DAY) api_error('Przekroczono dobowy limit SMS.', 429);

$GLOBALS['__sms_quiet_bypass'] = true;   // kody jednorazowe wychodzą także w nocy
try {
    $provider = sms_send($phone, $message, 'logto_code');
} catch (\Throwable $e) {
    error_log('[logto_sms] ' . sms_scrub($e->getMessage()));
    api_audit('send_failed', 'logto_sms', null, ['phone=' . substr($hash, 0, 12), 'type=' . mb_substr($type, 0, 30)]);
    api_error('Nie udało się wysłać SMS (błąd dostawcy). Spróbuj ponownie.', 502);
}
db()->prepare("INSERT INTO logto_sms_log (target, type) VALUES (?, ?)")->execute([$hash, mb_substr($type, 0, 30)]);
api_audit('send', 'logto_sms', null, ['phone=' . substr($hash, 0, 12), 'type=' . mb_substr($type, 0, 30), 'provider=' . ($provider ?: 'brak')]);
api_json(['ok' => true]);
