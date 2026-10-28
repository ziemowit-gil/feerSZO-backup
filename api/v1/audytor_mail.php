<?php
/**
 * REST API — wysyłka powiadomień e-mail przez SZO (Microsoft Graph) dla aplikacji „Tajemniczy Klient”.
 *
 *   POST /api/v1/audytor_mail.php   (Bearer, scope audytor:mail)
 *        {"recipients":[{"email":"…","name":"…"}],"subject":"…","message":"…"}
 *        → 200 {"ok":true,"sent":N,"queued":N,"skipped":[{"email":"…","reason":"…"}]}   (queued = sent, nazwa zostaje dla zgodności)
 *
 * Wiadomości są CZYSTYM TEKSTEM (bez HTML): `message` trafia jako gotowa treść text/plain (Graph: contentType Text, gdy
 * wersja HTML jest pusta). Wysyłka jest BEZPOŚREDNIA, bez kolejki SZO: odpowiedź 200 oznacza, że Graph/SMTP przyjął
 * wiadomość, a błąd wysyłki zwraca 502 (ponawia aplikacja Tajemniczy Klient ze swojej kolejki). Nadawca = systemowy
 * (context_type audytor_notify).
 *
 * Wyjątek dla użytkowników ZEWNĘTRZNYCH aplikacji (konta tylko w Tajemniczym Kliencie, bez konta SZO): adresat spoza SZO jest
 * dopuszczony wyłącznie dla dwóch tematów (ustawienie hasła: pierwsze konto / nowy link) i treści zgodnej ze sztywnym szablonem
 * z jednym linkiem https://audytor.feer.org.pl/ext/set-password?token=… (patrz audytor_mail_external_ok()); tylko 1 adresat
 * na żądanie, do 3 wiadomości na adres na godzinę i 30 na godzinę łącznie, w dzienniku tylko skrót adresu.
 *
 * To powiadomienia, nie dowolna korespondencja — zabezpieczenia: adresaci muszą być aktywnymi kontami SZO z uprawnieniem
 * do aplikacji (te same reguły co scope audytor:access), więc wyciek klucza nie pozwala wysyłać poczty na obce adresy;
 * do 50 adresatów na żądanie, do 200 wiadomości na godzinę (łącznie), adresy walidowane i bez duplikatów, każdy adresat
 * dostaje osobną wiadomość (bez ujawniania pozostałych), wpis w dzienniku audytu API. Treść do 5000 znaków.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/desktop_auth.php';
require_once dirname(__DIR__, 2) . '/includes/mail_queue.php';

api_auth_migrate();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_error('Method Not Allowed — użyj POST.', 405);
api_require('audytor:mail');

const AUDYTOR_MAIL_MAX_RECIPIENTS = 50;
const AUDYTOR_MAIL_MAX_PER_HOUR   = 200;
const AUDYTOR_MAIL_CONTEXT        = 'audytor_notify';
const AUDYTOR_EXT_PER_ADDR_HOUR   = 3;
const AUDYTOR_EXT_PER_HOUR        = 30;
const AUDYTOR_EXT_APP_URL         = 'https://audytor.feer.org.pl';
const AUDYTOR_EXT_SUBJECTS        = ['Konto w aplikacji Tajemniczy Klient: ustaw hasło', 'Nowy link do ustawienia hasła: Tajemniczy Klient'];

/**
 * Czy wiadomość do adresata spoza SZO jest dozwolonym mailem „ustaw hasło” (sztywny szablon, bez wolnego tekstu z linkami).
 * Warunki: jeden z dwóch tematów; treść zaczyna się od „Dzień dobry,” a kończy podpisem Fundacji; do 20 linii i 2000 znaków;
 * dokładnie jeden link …/ext/set-password?token=[A-Za-z0-9_-]{40,60}; linia „Adres aplikacji: <adres aplikacji>”;
 * linia „Login: <adres adresata>”; poza tymi elementami brak jakichkolwiek URL-i, adresów e-mail i nazw domen.
 */
function audytor_mail_external_ok(string $subject, string $message, string $email): bool
{
    if (!in_array($subject, AUDYTOR_EXT_SUBJECTS, true)) return false;
    if (mb_strlen($message) > 2000 || substr_count($message, "\n") > 20) return false;
    $lines = explode("\n", $message);
    if (($lines[0] ?? '') !== 'Dzień dobry,') return false;
    if (!preg_match('/^Fundacja Edukacji Empatii Rozwoju [„"]FEER[”"]$/u', rtrim((string)end($lines)))) return false;

    $linkRe = '~' . preg_quote(AUDYTOR_EXT_APP_URL, '~') . '/ext/set-password\?token=[A-Za-z0-9_-]{40,60}~';
    if (preg_match_all($linkRe, $message) !== 1) return false;
    if (substr_count($message, 'Adres aplikacji: ' . AUDYTOR_EXT_APP_URL . "\n") !== 1) return false;
    if (preg_match_all('/^Login: (\S+)$/mu', $message, $lm) !== 1 || strtolower($lm[1][0]) !== $email) return false;
    $loginLine = 'Login: ' . $lm[1][0];

    // Po usunięciu dozwolonych elementów nie może zostać żaden URL, adres e-mail ani nazwa domeny.
    $rest = preg_replace($linkRe, '', $message) ?? '';
    $rest = str_replace(['Adres aplikacji: ' . AUDYTOR_EXT_APP_URL, $loginLine], '', $rest);
    if (preg_match('~(?:https?:|www\.|@)~i', $rest)) return false;
    if (preg_match('/[\p{L}\p{N}-]+\.\p{L}{2,}/u', $rest)) return false;
    return true;
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) api_error('Wymagane ciało JSON.', 400);

$subject = trim(preg_replace('/[\r\n]+/', ' ', (string)($in['subject'] ?? '')) ?? '');
$message = trim(str_replace("\r\n", "\n", (string)($in['message'] ?? '')));
if ($subject === '' || mb_strlen($subject) > 150) api_error('Temat jest wymagany (do 150 znaków).', 422);
if ($message === '' || mb_strlen($message) > 5000) api_error('Treść jest wymagana (do 5000 znaków).', 422);
$rcpts = $in['recipients'] ?? null;
if (!is_array($rcpts) || !$rcpts) api_error('Brak adresatów.', 422);
if (count($rcpts) > AUDYTOR_MAIL_MAX_RECIPIENTS) api_error('Za dużo adresatów w jednym żądaniu (max ' . AUDYTOR_MAIL_MAX_RECIPIENTS . ').', 422);

// Uprawnieni adresaci: aktywne konta z efektywnym uprawnieniem do aplikacji (e-mail → imię i nazwisko).
$allowed = [];
foreach (db_all("SELECT name, first_name, last_name, email, role FROM users WHERE is_active = 1") as $u) {
    $e = strtolower(trim((string)$u['email']));
    if ($e === '' || !in_array('audytor:access', desktop_scopes_for_user($u), true)) continue;
    $n = trim(trim((string)$u['first_name']) . ' ' . trim((string)$u['last_name']));
    $allowed[$e] = $n !== '' ? $n : trim((string)$u['name']);
}

$valid = []; $external = []; $skipped = [];
foreach ($rcpts as $r) {
    $email = strtolower(trim((string)(is_array($r) ? ($r['email'] ?? '') : $r)));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) { $skipped[] = ['email' => $email, 'reason' => 'nieprawidłowy adres']; continue; }
    if (isset($valid[$email])) continue;
    if (!isset($allowed[$email])) {
        // Użytkownik zewnętrzny (bez konta SZO): tylko mail „ustaw hasło” z sztywnego szablonu, pojedynczo.
        if (count($rcpts) === 1 && audytor_mail_external_ok($subject, $message, $email)) { $external[$email] = ''; continue; }
        $skipped[] = ['email' => $email, 'reason' => 'adres spoza uprawnionych użytkowników']; continue;
    }
    $valid[$email] = mb_substr($allowed[$email], 0, 100);
}
if (!$valid && !$external) api_error('Brak adresatów uprawnionych do powiadomień.', 422);

// Licznik wysyłek (osobna tabela, bo wiadomości nie trafiają do kolejki poczty).
db()->exec("CREATE TABLE IF NOT EXISTS audytor_send_log (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, target TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT (datetime('now')))");
db()->exec("CREATE INDEX IF NOT EXISTS idx_audytor_send_log ON audytor_send_log(kind, created_at)");
$sentHour = (int)(db_one("SELECT COUNT(*) AS c FROM audytor_send_log WHERE kind='mail' AND created_at >= datetime('now','-1 hour')")['c'] ?? 0);
if ($sentHour + count($valid) > AUDYTOR_MAIL_MAX_PER_HOUR) api_error('Przekroczono limit wysyłek na godzinę. Spróbuj później.', 429);
if ($external) {
    $extHash = hash('sha256', (string)array_key_first($external));
    $extAddr = (int)(db_one("SELECT COUNT(*) AS c FROM audytor_send_log WHERE kind='mail_ext' AND target=? AND created_at >= datetime('now','-1 hour')", [$extHash])['c'] ?? 0);
    $extHour = (int)(db_one("SELECT COUNT(*) AS c FROM audytor_send_log WHERE kind='mail_ext' AND created_at >= datetime('now','-1 hour')")['c'] ?? 0);
    if ($extAddr >= AUDYTOR_EXT_PER_ADDR_HOUR) api_error('Limit wiadomości dla tego adresu. Spróbuj później.', 429);
    if ($extHour >= AUDYTOR_EXT_PER_HOUR) api_error('Przekroczono limit wiadomości do użytkowników zewnętrznych na godzinę.', 429);
}

$sent = 0; $errors = [];
foreach ($valid + $external as $email => $name) {
    $kind = isset($external[$email]) ? 'mail_ext' : 'mail';
    // Wysyłka bezpośrednia (bez mail_queue); pusta wersja HTML = czysty text/plain.
    $msg = [
        'id' => 0, 'to_email' => $email, 'to_name' => $name, 'subject' => $subject, 'body_html' => '', 'body_text' => $message,
        'context_type' => AUDYTOR_MAIL_CONTEXT, 'context_id' => null, 'attachments' => '[]', 'from_email' => '',
        'cc_emails' => '[]', 'bcc_emails' => '[]', 'headers' => null, 'retry_count' => 0,
    ];
    try {
        if (_mail_send($msg)) {
            $sent++;
            db()->prepare("INSERT INTO audytor_send_log (kind, target) VALUES (?, ?)")->execute([$kind, hash('sha256', $email)]);
        } else {
            $errors[] = ['email' => $email, 'reason' => 'serwer poczty nie przyjął wiadomości'];
        }
    } catch (\Throwable $e) {
        error_log('[audytor_mail] ' . $e->getMessage());
        $errors[] = ['email' => $email, 'reason' => 'błąd wysyłki'];
    }
}
api_audit('send', 'audytor_mail', null, ['recipients=' . count($valid), 'external=' . count($external), 'sent=' . $sent, 'errors=' . count($errors)]);
if ($sent === 0 && $errors) api_error('Nie udało się wysłać wiadomości (błąd poczty SZO). Spróbuj ponownie.', 502);
api_json(['ok' => true, 'sent' => $sent, 'queued' => $sent, 'skipped' => array_merge($skipped, $errors)]);
