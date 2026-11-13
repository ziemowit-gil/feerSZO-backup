<?php
/**
 * Webhook Logto → SZO (logowanie jednokrotne, modules/sso_logto).
 *
 *   POST /api/v1/logto_webhook.php   nagłówek logto-signature-sha-256 = HMAC-SHA256 (hex) surowego ciała, klucz = Signing key webhooka
 *
 * Zdarzenia: PostRegister / User.Created / User.Data.Updated — dowiązanie konta do osoby w SZO po adresie e-mail;
 * PostSignIn — data ostatniego logowania; PostResetPassword — zmiana hasła w Logto (chmura ma wtedy stare hasło);
 * User.Deleted — odpięcie konta. Odpowiedź 200 także dla zdarzeń bez skutku (Logto ponawia tylko błędy).
 * Powtórzone doręczenie (ten sam deliveryId) jest pomijane. Klucz w Ustawieniach → /ssoadmin (zaszyfrowany).
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/modules/sso_logto/logic/SsoAccounts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$reply = static function (int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $reply(405, ['error' => 'Użyj POST.']);
$key = SsoConfig::webhookKey();
if ($key === '') $reply(503, ['error' => 'Webhook Logto nie jest skonfigurowany w SZO.']);

$raw = (string)file_get_contents('php://input');
$sig = strtolower(trim((string)($_SERVER['HTTP_LOGTO_SIGNATURE_SHA_256'] ?? '')));
if ($sig === '' || !hash_equals(hash_hmac('sha256', $raw, $key), $sig)) $reply(401, ['error' => 'Niepoprawny podpis.']);

$in = json_decode($raw, true);
if (!is_array($in)) $reply(400, ['error' => 'Wymagane ciało JSON.']);

$event = (string)($in['event'] ?? '');
$user = is_array($in['user'] ?? null) ? $in['user'] : (is_array($in['data'] ?? null) ? $in['data'] : []);
$logtoId = (string)($in['userId'] ?? ($user['id'] ?? ($in['params']['userId'] ?? '')));
$email = '';
$note = 'bez zmian';

try {
    switch ($event) {
        case 'PostRegister':
        case 'User.Created':
        case 'User.Data.Updated':
            $email = SsoAccounts::linkFromLogto($user);
            $note = $email !== '' ? 'dowiązano do osoby w SZO' : 'brak osoby o tym e-mailu w SZO';
            break;
        case 'PostSignIn':
            $email = SsoAccounts::touchSignIn($logtoId);
            if ($email === '' && $user) { $email = SsoAccounts::linkFromLogto($user); if ($email !== '') SsoAccounts::touchSignIn($logtoId); }
            $note = $email !== '' ? 'logowanie' : 'konto spoza SZO';
            break;
        case 'PostResetPassword':
            $email = SsoAccounts::markPasswordResetInLogto($logtoId);
            $note = $email !== '' ? 'hasło zmienione w Logto — chmura ma stare' : 'konto spoza SZO';
            break;
        case 'User.Deleted':
            $email = SsoAccounts::unlinkLogto($logtoId);
            $note = $email !== '' ? 'konto usunięte w Logto — odpięto' : 'konto spoza SZO';
            break;
        default:
            $note = 'zdarzenie pominięte';
    }
} catch (\Throwable $e) {
    error_log('[logto_webhook] ' . $event . ': ' . $e->getMessage());
    $reply(500, ['error' => 'Błąd SZO przy obsłudze zdarzenia.']);
}

if (!SsoAccounts::logDelivery((string)($in['deliveryId'] ?? ''), $event, $logtoId, $email, $note)) $reply(200, ['ok' => true, 'duplicate' => true]);
$reply(200, ['ok' => true, 'result' => $note]);
