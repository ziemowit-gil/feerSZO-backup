<?php
declare(strict_types=1);

/**
 * modules/sso_logto/lib/ssoPrompt.php — prośba po zalogowaniu: „Załóż konto logowania jednokrotnego i w chmurze plików”.
 *
 * Polecenie 2026-11-13: każdego nowo zalogowanego użytkownika poprosić o założenie konta w chmurze (Nextcloud) i w logowaniu
 * jednokrotnym (Logto). Ekran: GET /sso/start (SsoController::prompt, view/loginPrompt.php), formularz idzie na istniejące
 * POST /sso/konto (SsoAccounts::provision — to samo hasło w Logto i w chmurze).
 *
 * Kiedy: raz po każdym zalogowaniu (flaga sesji K_CHECKED, kasowana w login_user()), dopiero po obowiązkowych krokach
 * pierwszego logowania (hasło, regulamin, kreator, RODO — ssoPromptGate() woła koniec firstLoginGate()), tylko gdy osoba
 * czegoś nie ma: konta Logto albo (gdy zakładanie chmury przy samoobsłudze jest włączone) konta w chmurze.
 * „Nie teraz” = do następnego logowania. Wyłącznik administratora: sso_login_prompt = '0' (/ssoadmin).
 */
require_once __DIR__ . '/SsoConfig.php';

const SSO_PROMPT_K_CHECKED = 'sso_prompt_checked';
const SSO_PROMPT_PATH      = '/sso/start';

function ssoPromptEnabled(): bool
{
    return SsoConfig::get('sso_login_prompt', '1') !== '0' && SsoConfig::selfEnabled() && SsoConfig::logtoConfigured();
}

/** Czy przy samodzielnym zakładaniu konta powstaje też konto w chmurze (to samo co SsoController::selfCreate). */
function ssoPromptWantsCloud(): bool
{
    return SsoConfig::cloudSelf() && SsoConfig::cloudConfigured();
}

/**
 * Czego brakuje osobie: 'both' (Logto + chmura), 'logto' (samo Logto, chmura nie jest zakładana), 'cloud' (ma Logto,
 * nie ma chmury) albo null (nic — albo osoba nie ma poprawnego adresu e-mail, którym byłoby konto).
 */
function ssoPromptNeed(string $email): ?string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    require_once dirname(__DIR__) . '/logic/SsoAccounts.php';
    $acc   = SsoAccounts::get($email);
    $cloud = ssoPromptWantsCloud();
    if (!$acc || empty($acc['logto_id'])) return $cloud ? 'both' : 'logto';
    if ($cloud && empty($acc['cloud_uid'])) return 'cloud';
    return null;
}

/** Ścieżka powrotu — tylko lokalna i nie sam ekran prośby. */
function ssoPromptSafeNext(string $next): string
{
    return ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//') && !str_contains($next, SSO_PROMPT_PATH)) ? $next : '';
}

/**
 * Przekierowuje na ekran prośby (raz na zalogowanie). Wołać przed wysłaniem treści (koniec firstLoginGate()).
 * Pomija: CLI, nie-GET, AJAX, podgląd/impersonację, serwis@local, strony logowania/API/Tożsamości/kreatora/migracji poczty.
 */
function ssoPromptGate(?array $user): void
{
    if (!$user || PHP_SAPI === 'cli' || headers_sent()) return;
    if (!empty($_SESSION[SSO_PROMPT_K_CHECKED]) || !empty($_SESSION['_admin_original'])) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_GET['_ajax']) || isset($_GET['ajax'])) return;
    if (($user['email'] ?? '') === 'serwis@local') return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    foreach (['/sso', '/auth/', '/api/', '/tozsamosc/', '/modules/first_run/', '/user/first_login_consent', '/user/verify_reset',
              '/panel/password.php', '/panel/gdpr_statement.php', '/migracjapoczty', '/logout'] as $p) {
        if (str_contains($path, $p)) return;
    }
    $_SESSION[SSO_PROMPT_K_CHECKED] = 1;   // jedno sprawdzenie na zalogowanie — także gdy niczego nie brakuje
    try {
        if (!ssoPromptEnabled()) return;
        $db = db_one("SELECT email FROM users WHERE id=?", [(int)$user['id']]);
        if (ssoPromptNeed((string)($db['email'] ?? '')) === null) return;
    } catch (\Throwable $e) {
        error_log('[sso] prośba po zalogowaniu: ' . $e->getMessage());
        return;   // błąd prośby nigdy nie blokuje pracy
    }
    header('Location: ' . rtrim(APP_URL, '/') . SSO_PROMPT_PATH . '?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
    exit;
}
