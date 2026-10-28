<?php
/**
 * REST API — kody logowania SMS dla użytkowników zewnętrznych aplikacji „Tajemniczy Klient”.
 *
 *   POST /api/v1/audytor_sms.php   (Bearer, scope audytor:sms)
 *        {"phone":"+48600100200","message":"Tajemniczy Klient: kod logowania 123456. Wazny 5 min. Nie podawaj go nikomu."}
 *        → 200 {"ok":true}
 *
 * Wysyłka jest BEZPOŚREDNIA (bez kolejki): odpowiedź 200 oznacza, że dostawca SMS przyjął wiadomość; błąd dostawcy to 502.
 * Klucz API nie służy do wysyłania dowolnych SMS-ów: przyjmowany jest wyłącznie jeden sztywny szablon z 6-cyfrowym kodem
 * (ASCII, do 120 znaków), numer musi być w formacie E.164, a do tego obowiązują limity: 5 SMS-ów na numer na godzinę,
 * 60 na godzinę łącznie i 300 na dobę. Kod z treści nigdzie nie jest zapisywany (dziennik zawiera tylko skrót numeru).
 * Godziny ciszy nocnej SZO nie dotyczą kodów jednorazowych.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/sms.php';

api_auth_migrate();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_error('Method Not Allowed — użyj POST.', 405);
api_require('audytor:sms');

const AUDYTOR_SMS_PER_PHONE_HOUR = 5;
const AUDYTOR_SMS_PER_HOUR       = 60;
const AUDYTOR_SMS_PER_DAY        = 300;
const AUDYTOR_SMS_TEMPLATE       = '/^Tajemniczy Klient: kod logowania [0-9]{6}\. Wazny [0-9]{1,2} min\. Nie podawaj go nikomu\.$/';

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) api_error('Wymagane ciało JSON.', 400);

$phone   = trim((string)($in['phone'] ?? ''));
$message = (string)($in['message'] ?? '');
if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) api_error('Numer telefonu musi być w formacie międzynarodowym (+48…).', 422);
if (strlen($message) > 120 || !preg_match(AUDYTOR_SMS_TEMPLATE, $message)) api_error('Niedozwolona treść wiadomości (tylko kod logowania w ustalonym szablonie).', 422);
if (!sms_is_enabled()) api_error('Wysyłka SMS jest wyłączona w ustawieniach SZO.', 503);

db()->exec("CREATE TABLE IF NOT EXISTS audytor_send_log (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, target TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT (datetime('now')))");
db()->exec("CREATE INDEX IF NOT EXISTS idx_audytor_send_log ON audytor_send_log(kind, created_at)");
$hash = hash('sha256', $phone);
$count = static fn(string $sql, array $p = []): int => (int)(db_one($sql, $p)['c'] ?? 0);
if ($count("SELECT COUNT(*) AS c FROM audytor_send_log WHERE kind='sms' AND target=? AND created_at >= datetime('now','-1 hour')", [$hash]) >= AUDYTOR_SMS_PER_PHONE_HOUR) api_error('Limit SMS dla tego numeru. Spróbuj później.', 429);
if ($count("SELECT COUNT(*) AS c FROM audytor_send_log WHERE kind='sms' AND created_at >= datetime('now','-1 hour')") >= AUDYTOR_SMS_PER_HOUR) api_error('Przekroczono limit SMS na godzinę.', 429);
if ($count("SELECT COUNT(*) AS c FROM audytor_send_log WHERE kind='sms' AND created_at >= datetime('now','-1 day')") >= AUDYTOR_SMS_PER_DAY) api_error('Przekroczono dobowy limit SMS.', 429);

$GLOBALS['__sms_quiet_bypass'] = true;   // kody jednorazowe wychodzą także w nocy
try {
    $provider = sms_send($phone, $message, '');
} catch (\Throwable $e) {
    error_log('[audytor_sms] ' . sms_scrub($e->getMessage()));
    api_audit('send_failed', 'audytor_sms', null, ['phone=' . substr($hash, 0, 12)]);
    api_error('Nie udało się wysłać SMS (błąd dostawcy). Spróbuj ponownie.', 502);
}
db()->prepare("INSERT INTO audytor_send_log (kind, target) VALUES ('sms', ?)")->execute([$hash]);
api_audit('send', 'audytor_sms', null, ['phone=' . substr($hash, 0, 12), 'provider=' . ($provider ?: 'brak')]);
api_json(['ok' => true]);
