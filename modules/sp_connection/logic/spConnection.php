<?php
/**
 * modules/sp_connection/logic/spConnection.php — stan połączenia z SharePointem, blokada jego zmiany i blokada funkcji bez połączenia.
 *
 * „Połączony” = integracja M365 skonfigurowana (Client ID / Secret), synchronizacja włączona (sp_enabled=1) i wskazana witryna
 * (sp_site_url). Bez zapytań do Graph — to stan KONFIGURACJI (taki sam jak SpFiles::canRestore() i ws_available()).
 *
 * BLOKADA ZMIANY POŁĄCZENIA. Pliki umów, pism i obszarów roboczych (moduł Pliki trzyma je wyłącznie na SharePoincie) leżą pod
 * ścieżką {witryna}/{biblioteka}/{folder bazowy}/…; zmiana któregokolwiek z tych trzech ustawień NIE przenosi istniejących plików —
 * aplikacja przestałaby je znajdować (przywracanie plików umów, moduł Pliki). Dlatego od chwili, gdy witryna jest zapisana,
 * zmiana witryny / biblioteki / folderu bazowego jest zablokowana — w kreatorach (sharepoint_settings.php, sp_onboarding.php)
 * i, co ważniejsze, w API (admin/api/sp_wizard.php, sp_save_setting.php), które wołają SpConnection::save(). Odblokowanie jest
 * świadome i czasowe (30 minut albo do pierwszego zapisu zmian), zapisuje się w audycie (sharepoint.unlocked / .relocked),
 * a każda zmiana ustawień też (sharepoint.settings_changed, przed → po). Przełącznik sp_enabled i folder backupów (sp_backup_folder)
 * nie wskazują miejsca plików aplikacji, więc blokada ich nie dotyczy. Pierwsza konfiguracja (brak witryny) jest swobodna.
 *
 * BLOKADA FUNKCJI BEZ POŁĄCZENIA. Strony zależne od SharePointa (Skany z SharePointa, przywracanie plików, moduł Pliki) zamiast
 * działać z błędami pokazują panel blokady z powodem i linkiem do ustawień: renderBlocked().
 */
require_once dirname(__DIR__, 3) . '/includes/m365.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class SpConnection
{
    /** Ustawienia wskazujące MIEJSCE plików — blokowane po połączeniu. */
    public const LOCATION_KEYS = ['sp_site_url', 'sp_library', 'sp_base_folder'];

    /** Wszystkie ustawienia, które wolno zapisać przez save() (reszta kluczy sp_* nie jest dopuszczona). */
    public const SAVEABLE_KEYS = ['sp_enabled', 'sp_site_url', 'sp_library', 'sp_base_folder', 'sp_backup_folder'];

    /** Czas, na jaki odblokowuje się edycję połączenia. */
    public const UNLOCK_SECONDS = 1800;

    private const UNLOCK_KEY = 'sp_conn_unlock_until';

    /** Ustawienie czytane wprost z bazy — m365_setting() ma pamięć podręczną na proces i po zapisie w tym samym żądaniu pokazywałoby stare. */
    public static function setting(string $key): string
    {
        $r = db_one("SELECT value FROM settings WHERE key_ = ?", [$key]);
        return (string)($r['value'] ?? '');
    }

    /**
     * Stan połączenia i blokady.
     *
     * @return array{m365:bool,enabled:bool,site:string,library:string,base_folder:string,connected:bool,locked:bool,unlock_until:int}
     *         unlock_until: znacznik czasu końca odblokowania albo 0
     */
    public static function state(): array
    {
        $site  = trim(self::setting('sp_site_url'));
        $m365  = (new M365Graph())->is_configured();
        $on    = self::setting('sp_enabled') === '1';
        $until = (int)self::setting(self::UNLOCK_KEY);
        return [
            'm365'         => $m365,
            'enabled'      => $on,
            'site'         => $site,
            'library'      => trim(self::setting('sp_library')),
            'base_folder'  => trim(self::setting('sp_base_folder'), '/ '),
            'connected'    => $m365 && $on && $site !== '',
            'locked'       => $site !== '' && time() >= $until,
            'unlock_until' => $until > time() ? $until : 0,
        ];
    }

    /** Czy SharePoint jest połączony (skonfigurowany i włączony) — warunek działania funkcji, które z niego korzystają. */
    public static function isConnected(): bool
    {
        return self::state()['connected'];
    }

    /** Czy zmiana witryny / biblioteki / folderu bazowego jest zablokowana. */
    public static function isLocked(): bool
    {
        return self::state()['locked'];
    }

    /** Powód, dla którego SharePoint nie jest połączony (po polsku) albo '' gdy jest połączony. */
    public static function blockedReason(): string
    {
        $s = self::state();
        if (!$s['m365'])    return 'Nie skonfigurowano integracji z Microsoft 365 (Client ID i Client Secret).';
        if ($s['site'] === '') return 'Nie wskazano witryny SharePoint.';
        if (!$s['enabled']) return 'Synchronizacja z SharePoint jest wyłączona.';
        return '';
    }

    // ── Odblokowanie ─────────────────────────────────────────────────────────

    /** Odblokowuje edycję połączenia na UNLOCK_SECONDS (do pierwszego zapisu zmian). Zwraca znacznik czasu końca odblokowania. */
    public static function unlock(int $userId = 0): int
    {
        $until = time() + self::UNLOCK_SECONDS;
        m365_save_setting(self::UNLOCK_KEY, (string)$until);
        audit_log('sharepoint.unlocked', ['until' => date('Y-m-d H:i:s', $until)], $userId);
        return $until;
    }

    /** Włącza blokadę z powrotem (ręcznie albo po zapisie zmian). */
    public static function relock(int $userId = 0, string $reason = 'ręcznie'): void
    {
        if ((int)self::setting(self::UNLOCK_KEY) === 0) return;
        m365_save_setting(self::UNLOCK_KEY, '0');
        audit_log('sharepoint.relocked', ['reason' => $reason], $userId);
    }

    // ── Zapis ustawień ───────────────────────────────────────────────────────

    /** Wartość ustawienia w postaci porównywalnej (przycięta; folder bez ukośników na brzegach; przełącznik 0/1). */
    private static function normalize(string $key, string $value): string
    {
        $v = trim($value);
        return match ($key) {
            'sp_enabled'                        => in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true) ? '1' : '0',
            'sp_base_folder', 'sp_backup_folder' => trim($v, '/ '),
            default                             => $v,
        };
    }

    /** Czy dwie wartości ustawienia to to samo (adresy witryny: bez znaczenia końcowy ukośnik). */
    private static function same(string $key, string $a, string $b): bool
    {
        return $key === 'sp_site_url' ? rtrim($a, '/') === rtrim($b, '/') : $a === $b;
    }

    /**
     * Jedyna droga zapisu ustawień połączenia (woła ją sp_wizard.php i sp_save_setting.php). Zmiana witryny / biblioteki / folderu
     * bazowego przy włączonej blokadzie jest ODRZUCANA (nic nie jest zapisywane). Zapisuje tylko to, co się zmieniło; zmiana
     * lokalizacji czyści pamięć podręczną identyfikatorów witryny i biblioteki; udany zapis kończy odblokowanie.
     *
     * @param array<string,string|bool> $changes klucz ustawienia (SAVEABLE_KEYS) → nowa wartość
     * @return array{ok:bool,error?:string,locked?:bool,moved?:list<string>,changed?:list<string>}
     */
    public static function save(array $changes, int $userId = 0): array
    {
        $new = [];
        foreach ($changes as $k => $v) {
            if (!in_array($k, self::SAVEABLE_KEYS, true)) return ['ok' => false, 'error' => 'Niedozwolony klucz ustawienia.'];
            $new[$k] = self::normalize($k, is_bool($v) ? ($v ? '1' : '0') : (string)$v);
        }
        $old = []; $moved = []; $diff = [];
        foreach ($new as $k => $v) {
            $old[$k] = self::normalize($k, self::setting($k));
            if (!self::same($k, $old[$k], $v)) {
                $diff[$k] = [$old[$k], $v];
                if (in_array($k, self::LOCATION_KEYS, true)) $moved[] = $k;
            }
        }
        $wasUnlocked = (int)self::setting(self::UNLOCK_KEY) > time();
        if ($moved && self::isLocked()) {
            return ['ok' => false, 'locked' => true, 'moved' => $moved,
                    'error' => 'Połączenie z SharePoint jest zablokowane — zmiana witryny, biblioteki lub folderu bazowego odcięłaby już wysłane pliki. '
                             . 'Jeśli to zamierzone, odblokuj edycję w Administracja → SharePoint.'];
        }
        foreach ($diff as $k => [$_, $v]) m365_save_setting($k, $v);
        if ($moved) { m365_save_setting('sp_cached_site_id', ''); m365_save_setting('sp_cached_drive_id', ''); }
        if ($diff) audit_log('sharepoint.settings_changed', ['changed' => $diff, 'unlocked' => $wasUnlocked], $userId);
        if ($diff && $wasUnlocked) self::relock($userId, 'po zapisie zmian');
        return ['ok' => true, 'changed' => array_keys($diff)];
    }

    // ── Blokada funkcji bez połączenia ───────────────────────────────────────

    /**
     * Wypisuje panel „funkcja zablokowana — SharePoint nie jest połączony” z powodem i linkiem do ustawień (administrator)
     * albo prośbą do administratora (pozostali). Wołający sam kończy stronę (zwykle: stopka + exit).
     *
     * @param string $feature nazwa zablokowanej funkcji (np. „Moduł Pliki”)
     * @param string $detail  dodatkowe zdanie: co przestaje działać
     */
    public static function renderBlocked(string $feature, string $detail = ''): void
    {
        $why      = self::blockedReason() ?: 'SharePoint nie jest połączony.';
        $isAdmin  = function_exists('is_admin') && is_admin();
        $baseUrl  = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
        $settings = $baseUrl . '/admin/sharepoint_settings.php';
        $m365Url  = $baseUrl . '/admin/m365_settings.php';
        $noM365   = !self::state()['m365'];
        include dirname(__DIR__) . '/views/notConnected.php';
    }
}
