<?php
/**
 * REST API — wysyłka powiadomień e-mail do koordynatorów przez SZO (dla aplikacji Wdrożenie MSUES).
 *
 *   POST /api/v1/msues_mail.php   (Bearer, scope msues:write)
 *        {"recipients":[{"email":"…","name":"…"}],"subject":"…","message":"…","link":"https://…"}
 *        → 200 {"ok":true,"queued":N,"skipped":[…]}
 *
 * To powiadomienia/przypomnienia, nie dowolna korespondencja: treść jest zwykłym tekstem (znaki HTML są
 * escapowane) w stałym szablonie, nadawca = systemowy (context_type msues_notify). Zabezpieczenia: do 50 adresatów
 * na żądanie, do 200 wiadomości na godzinę (łącznie), adresy walidowane i bez duplikatów, każdy adresat dostaje
 * osobną wiadomość (bez ujawniania pozostałych), wpis w dzienniku audytu API. Link tylko https.
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/mail_queue.php';

api_auth_migrate();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_error('Method Not Allowed — użyj POST.', 405);
api_require('msues:write');

const MSUES_MAIL_MAX_RECIPIENTS = 50;
const MSUES_MAIL_MAX_PER_HOUR   = 200;
const MSUES_MAIL_CONTEXT        = 'msues_notify';

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) api_error('Wymagane ciało JSON.', 400);

$subject = trim(preg_replace('/[\r\n]+/', ' ', (string)($in['subject'] ?? '')) ?? '');
$message = trim((string)($in['message'] ?? ''));
$link    = trim((string)($in['link'] ?? ''));
if ($subject === '' || mb_strlen($subject) > 150) api_error('Temat jest wymagany (do 150 znaków).', 422);
if ($message === '' || mb_strlen($message) > 5000) api_error('Treść jest wymagana (do 5000 znaków).', 422);
if ($link !== '' && (!preg_match('#^https://[^\s"<>]+$#', $link) || strlen($link) > 500)) api_error('Link musi zaczynać się od https://.', 422);
$rcpts = $in['recipients'] ?? null;
if (!is_array($rcpts) || !$rcpts) api_error('Brak adresatów.', 422);
if (count($rcpts) > MSUES_MAIL_MAX_RECIPIENTS) api_error('Za dużo adresatów w jednym żądaniu (max ' . MSUES_MAIL_MAX_RECIPIENTS . ').', 422);

$valid = []; $skipped = [];
foreach ($rcpts as $r) {
    $email = strtolower(trim((string)(is_array($r) ? ($r['email'] ?? '') : $r)));
    $name  = is_array($r) ? trim(preg_replace('/[\r\n<>"]+/', ' ', (string)($r['name'] ?? '')) ?? '') : '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) { $skipped[] = ['email' => $email, 'reason' => 'nieprawidłowy adres']; continue; }
    if (isset($valid[$email])) continue;
    $valid[$email] = mb_substr($name, 0, 100);
}
if (!$valid) api_error('Brak prawidłowych adresów e-mail.', 422);

$sentHour = (int)(db_one("SELECT COUNT(*) AS c FROM mail_queue WHERE context_type=? AND created_at >= datetime('now','-1 hour')", [MSUES_MAIL_CONTEXT])['c'] ?? 0);
if ($sentHour + count($valid) > MSUES_MAIL_MAX_PER_HOUR) api_error('Przekroczono limit wysyłek na godzinę. Spróbuj później.', 429);

$org  = defined('ORG_NAME') ? (string)ORG_NAME : 'Fundacja FEER';
$msgH = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), false);
$btn  = $link !== '' ? '<p style="margin:24px 0"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8')
      . '" style="background:#1d1d1d;color:#fff;padding:12px 22px;text-decoration:none;font-weight:600">Otwórz Wdrożenie MSUES</a></p>' : '';
$html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:16px;color:#111;line-height:1.5">'
      . '<p style="margin:0 0 16px">' . $msgH . '</p>' . $btn
      . '<p style="color:#555;font-size:13px;margin-top:28px">Wiadomość automatyczna z systemu ' . htmlspecialchars($org, ENT_QUOTES, 'UTF-8') . ' — Wdrożenie MSUES.</p></div>';

$queued = 0;
foreach ($valid as $email => $name) {
    if (mail_queue_add($email, $name, $subject, $html, '', MSUES_MAIL_CONTEXT) > 0) $queued++;
    else $skipped[] = ['email' => $email, 'reason' => 'adres wyłączony z wysyłek'];
}
api_audit('send', 'msues_mail', null, ['recipients=' . count($valid), 'queued=' . $queued]);
api_json(['ok' => true, 'queued' => $queued, 'skipped' => $skipped]);
