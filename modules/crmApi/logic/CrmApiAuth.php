<?php
/**
 * modules/crmApi/logic/CrmApiAuth.php — uwierzytelnienie API CRM tokenem użytkownika (logowanie kontem SZO,
 * includes/desktop_auth.php) i kontekst „zalogowanego” użytkownika na czas jednego żądania.
 *
 * Token = wiersz api_keys z created_by = użytkownik i nazwą DESKTOP_TOKEN_PREFIX… (wydany przez desktop_token.php).
 * Klucze maszynowe (panel Zarządzaj API) tu NIE wchodzą — API działa zawsze w imieniu konkretnej osoby, więc
 * obowiązują jej uprawnienia CRM (crm_can, maski pól, grupy). Żeby istniejąca logika (CrmManager, crm_can…) widziała
 * tę osobę przez current_user(), startujemy sesję PHP w pamięci: bez ciasteczka, bez zapisu, bez odczytu sesji
 * przeglądarki — prawdziwa sesja SZO (ciasteczko na tej samej domenie) jest ignorowana i nietknięta.
 */

final class CrmApiNullSession implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
    public function validateId(string $id): bool { return true; }
    public function updateTimestamp(string $id, string $data): bool { return true; }
}

final class CrmApiAuth
{
    /** Bieżący wiersz api_keys (po authenticate()). */
    public static ?array $token = null;

    /** Middleware: token użytkownika ze scope crm:read, sesja w pamięci, moduł CRM włączony, VPN, prawo odczytu kontaktów. */
    public static function authenticate(): void
    {
        global $api_current_key;
        api_auth_migrate();
        api_require('crm:read');
        $key = (array)$api_current_key;
        if (!str_starts_with((string)($key['name'] ?? ''), DESKTOP_TOKEN_PREFIX) || (int)($key['created_by'] ?? 0) <= 0) {
            api_error('Ten adres przyjmuje tylko token użytkownika (logowanie kontem SZO).', 403);
        }
        $user = db_one("SELECT * FROM users WHERE id=? AND is_active=1", [(int)$key['created_by']]);
        if (!$user) api_error('Konto jest nieaktywne.', 401);

        // Administrator unieważnił weryfikacje IKA po wydaniu tokenu — token przestaje działać.
        $revoked = (string)($user['ika_revoked_at'] ?? '');
        if ($revoked !== '' && strtotime($revoked) > strtotime((string)$key['created_at'])) {
            db()->prepare("UPDATE api_keys SET is_active=0 WHERE id=?")->execute([(int)$key['id']]);
            api_error('Sesja została unieważniona. Zaloguj się ponownie.', 401);
        }
        self::$token = $key;
        self::actAs($user);

        if (!module_enabled('crm_enabled')) api_error('Moduł CRM jest wyłączony przez administratora.', 403);
        if (org_setting('crm_vpn_only') === '1' && ($user['email'] ?? '') !== 'serwis@local'
            && !ip_in_allowlist($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', org_setting('crm_vpn_allowlist'))) {
            api_error('CRM jest dostępny wyłącznie z sieci firmowej (VPN).', 403);
        }
        if (!crm_can('contacts', 'read')) api_error('Brak dostępu do CRM.', 403);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $granted = json_decode((string)($key['permissions'] ?? '[]'), true) ?: [];
            if (!in_array('crm:write', $granted, true)) api_error('Token nie pozwala na zapis.', 403);
            $_SERVER['HTTP_ACCEPT'] = 'application/json';   // system_block_writes() odpowie JSON-em
            system_block_writes();                           // tryb tylko do odczytu / przestój
        }
    }

    /** Sesja PHP w pamięci z użytkownikiem w kształcie login_user() — current_user() działa jak po zalogowaniu. */
    private static function actAs(array $user): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.use_trans_sid', '0');
            ini_set('session.use_strict_mode', '0');
            ini_set('session.cache_limiter', '');
            session_set_save_handler(new CrmApiNullSession(), true);
            session_id('crmapi' . bin2hex(random_bytes(12)));
            session_start();
        }
        $_SESSION = [
            'user' => [
                'id'           => (int)$user['id'],
                'name'         => (string)$user['name'],
                'email'        => (string)($user['email'] ?? ''),
                'role'         => (string)$user['role'],
                'microsoft_id' => (string)($user['microsoft_id'] ?? ''),
                'portal_scope' => $user['portal_scope'] ?? null,
            ],
            'ctx_decided' => 1,
        ];
    }

    public static function userId(): int
    {
        return (int)(current_user()['id'] ?? 0);
    }

    /** Wymóg uprawnienia obszaru CRM (403 JSON). */
    public static function need(string $area, string $op): void
    {
        if (!crm_can($area, $op)) api_error('Brak uprawnień: ' . $area . ' (' . $op . ').', 403);
    }

    /**
     * Ograniczenie listy do kontaktów z grup dostępnych dla użytkownika (konta tylko-CRM z przydziałem grup).
     * Zwraca [fragment SQL zaczynający się od " AND …", parametry]; pusty fragment = bez ograniczeń.
     */
    public static function contactAcl(string $col): array
    {
        $ids = crm_accessible_group_ids();
        if ($ids === null) return ['', []];
        if (!$ids) return [' AND 0', []];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return [" AND EXISTS (SELECT 1 FROM crm_group_members gm WHERE gm.contact_id={$col} AND gm.group_id IN ({$ph}))", $ids];
    }

    /** Wejście żądania: JSON (Content-Type: application/json) albo formularz. */
    public static function input(): array
    {
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
            $raw = (string)file_get_contents('php://input');
            $j = json_decode($raw, true);
            if ($raw !== '' && !is_array($j)) api_error('Nieprawidłowy JSON.', 400);
            return is_array($j) ? $j : [];
        }
        return $_POST;
    }
}
