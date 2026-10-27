<?php
/**
 * REST API — wysyłka powiadomień e-mail przez SZO (Microsoft Graph) dla aplikacji „Tajemniczy Klient”.
 *
 *   POST /api/v1/audytor_mail.php   (Bearer, scope audytor:mail)
 *        {"recipients":[{"email":"…","name":"…"}],"subject":"…","message":"…"}
 *        → 200 {"ok":true,"queued":N,"skipped":[{"email":"…","reason":"…"}]}
 *
 * Wiadomości są CZYSTYM TEKSTEM (bez HTML): `message` trafia jako gotowa treść text/plain (kolejka poczty wysyła ją
 * z contentType Text, gdy wersja HTML jest pusta). Nadawca = systemowy (context_type audytor_notify).
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

$valid = []; $skipped = [];
foreach ($rcpts as $r) {
    $email = strtolower(trim((string)(is_array($r) ? ($r['email'] ?? '') : $r)));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) { $skipped[] = ['email' => $email, 'reason' => 'nieprawidłowy adres']; continue; }
    if (isset($valid[$email])) continue;
    if (!isset($allowed[$email])) { $skipped[] = ['email' => $email, 'reason' => 'adres spoza uprawnionych użytkowników']; continue; }
    $valid[$email] = mb_substr($allowed[$email], 0, 100);
}
if (!$valid) api_error('Brak adresatów uprawnionych do powiadomień.', 422);

$sentHour = (int)(db_one("SELECT COUNT(*) AS c FROM mail_queue WHERE context_type=? AND created_at >= datetime('now','-1 hour')", [AUDYTOR_MAIL_CONTEXT])['c'] ?? 0);
if ($sentHour + count($valid) > AUDYTOR_MAIL_MAX_PER_HOUR) api_error('Przekroczono limit wysyłek na godzinę. Spróbuj później.', 429);

$queued = 0;
foreach ($valid as $email => $name) {
    // Pusta wersja HTML = kolejka wysyła czysty text/plain (Graph: contentType Text).
    if (mail_queue_add($email, $name, $subject, '', $message, AUDYTOR_MAIL_CONTEXT) > 0) $queued++;
    else $skipped[] = ['email' => $email, 'reason' => 'adres wyłączony z wysyłek'];
}
api_audit('send', 'audytor_mail', null, ['recipients=' . count($valid), 'queued=' . $queued]);
api_json(['ok' => true, 'queued' => $queued, 'skipped' => $skipped]);
