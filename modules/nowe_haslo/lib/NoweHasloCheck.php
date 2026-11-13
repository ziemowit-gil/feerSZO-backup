<?php
declare(strict_types=1);

/**
 * modules/nowe_haslo/lib/NoweHasloCheck.php — czy nowe hasło nie jest takie samo jak hasło w drugim systemie
 * (polecenie 2026-11-13: „hasło do poczty zawsze musi być inne” niż wspólne hasło logowania jednokrotnego i chmury).
 *
 * SZO nie przechowuje haseł, więc sprawdzamy na żywo, logując się kandydatem:
 *  • poczta — logowanie IMAP do skrzynki (DhostingCopy::checkPassword);
 *  • logowanie jednokrotne — Logto Management API POST /api/users/{id}/password/verify (2xx = to samo hasło, 422 = inne);
 *    konto bez Logto (tryb samej chmury) — logowanie do chmury: OCS GET /ocs/v2.php/cloud/user (200 = to samo, 401 = inne).
 * Wynik: true = to samo hasło, false = inne albo nie ma z czym porównać, null = nie da się teraz sprawdzić
 * (wtedy własnego hasła nie przyjmujemy — osoba może poczekać albo wybrać hasło losowe).
 *
 * Nieudane logowanie z adresu SZO to dla serwera poczty i chmury „błędne hasło”. Żeby nie wpaść w ich blokady (fail2ban,
 * ochrona przed atakami siłowymi w Nextcloud), robimy najwyżej BUSY_MAX sprawdzeń na system w BUSY_WINDOW sekund.
 * W Nextcloud adres serwera SZO warto dodać do wyjątków ochrony przed atakami siłowymi (aplikacja bruteforcesettings).
 * Hasła losowe (14 znaków) nie wymagają sprawdzania — kontroler woła te funkcje tylko dla haseł wpisanych przez osobę.
 */
require_once __DIR__ . '/NoweHasloLimit.php';
require_once dirname(__DIR__, 2) . '/dhosting_migrator/logic/DhostingCopy.php';
require_once dirname(__DIR__, 2) . '/sso_logto/lib/SsoConfig.php';

final class NoweHasloCheck
{
    public const BUSY_MAX = 5;
    public const BUSY_WINDOW = 600;

    /** Atrapa w testach: fn(string $mailbox, string $password): ?bool. */
    public static ?\Closure $imap = null;
    /** Powód ostatniego null: 'busy' (za dużo sprawdzeń naraz) albo 'unavailable' (system nie odpowiada). */
    public static string $why = '';

    public static function sameAsMail(string $mailbox, string $password): ?bool
    {
        self::$why = '';
        if (NoweHasloLimit::count('chk:imap', self::BUSY_WINDOW) >= self::BUSY_MAX) { self::$why = 'busy'; return null; }
        NoweHasloLimit::hit('chk:imap');
        $r = self::$imap ? (self::$imap)($mailbox, $password) : DhostingCopy::checkPassword($mailbox, $password);
        if ($r === null) self::$why = 'unavailable';
        return $r;
    }

    /** $acc — wiersz sso_accounts osoby (logto_id, cloud_uid). */
    public static function sameAsSso(array $acc, string $password): ?bool
    {
        self::$why = '';
        $logtoId = (string)($acc['logto_id'] ?? '');
        if ($logtoId !== '' && SsoConfig::logtoConfigured()) {
            $api = SsoConfig::logto();
            try {
                $api->request('POST', '/api/users/' . rawurlencode($logtoId) . '/password/verify', ['password' => $password]);
                return true;
            } catch (\Throwable $e) {
                if (in_array($api->lastHttp, [400, 422], true)) return false;   // session.invalid_credentials albo konto bez hasła
                // Inny błąd Logto (brak uprawnienia, 5xx, sieć) albo 404: nie poddajemy się — hasło chmury jest to samo co Logto,
                // więc sprawdzamy logowaniem do chmury (2026-11-14: „Nie możemy teraz sprawdzić…” blokowało zmianę hasła poczty).
                if ($api->lastHttp !== 404) error_log('[nowehaslo] Logto verify ' . $logtoId . ' HTTP ' . $api->lastHttp . ': ' . $e->getMessage() . ' — sprawdzam chmurę');
            }
        }
        $uid = (string)($acc['cloud_uid'] ?? '');
        if ($uid === '' || !SsoConfig::cloudConfigured()) return false;
        if (NoweHasloLimit::count('chk:cloud', self::BUSY_WINDOW) >= self::BUSY_MAX) { self::$why = 'busy'; return null; }
        NoweHasloLimit::hit('chk:cloud');
        $r = self::cloudLogin(SsoConfig::cloud()->base(), $uid, $password);
        if ($r === null) self::$why = 'unavailable';
        return $r;
    }

    /** Logowanie do chmury loginem osoby i kandydatem (tylko odczyt danych własnego konta). */
    private static function cloudLogin(string $base, string $uid, string $password): ?bool
    {
        $ch = curl_init(rtrim($base, '/') . '/ocs/v2.php/cloud/user?format=json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
            CURLOPT_USERPWD => $uid . ':' . $password,
            CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($raw === false || $code === 0 || $code === 429 || $code >= 500) return null;
        if ($code === 200) return true;
        if ($code === 401 || $code === 403) return false;
        return null;
    }
}
