<?php
declare(strict_types=1);

namespace Feer\Szo\LoginSentry;

/**
 * modules/login_sentry/lib/LoginSentryConfig.php — ustawienia LoginSentry („Staszek”).
 *
 * Kolejność (późniejsze wygrywa): wartości domyślne niżej → plik config/login_sentry.php (wzór: config/login_sentry.example.php,
 * poza repozytorium dzięki .gitignore) → ustawienia zapisane w panelu (tabela ls_settings, tylko klucze z PANEL_KEYS).
 * Sekretów tu nie ma: dostęp do Logto bierzemy z ustawień logowania jednokrotnego SZO (modules/sso_logto), jak Mietek.
 */
final class LoginSentryConfig
{
    public const MODES = ['off', 'preview', 'live'];

    public const DEFAULTS = [
        'mode' => 'preview',              // off = nic; preview = ocena i raport w panelu, bez maili i blokad; live = maile i blokady
        'window_hours' => 7,              // ile godzin wstecz czytamy logowania (przebieg co 6 h + zapas)
        'warn_score' => 40,               // od tylu punktów: e-mail do administratora
        'suspend_score' => 70,            // od tylu punktów: zawieszenie konta w Logto i blokada w SZO + e-mail do administratora
        'fail_threshold' => 5,            // tyle nieudanych prób przed udanym logowaniem = „zgadywanie hasła”
        'fail_window_min' => 60,          // w ilu minutach przed udanym logowaniem liczymy nieudane próby
        'many_networks' => 3,             // tyle różnych sieci w jednym oknie = konto używane z wielu miejsc naraz
        'baseline_min_logins' => 3,       // dopiero od tylu znanych logowań oceniamy „nową sieć” i „nową przeglądarkę”
        'baseline_days' => 90,            // z ilu dni historii SZO budujemy wzorzec przy pierwszym uruchomieniu
        'allowed_countries' => ['PL'],    // działa tylko z bazą GeoIP (geoip_mmdb)
        'night_from' => 1,                // logowanie między 1:00 a 5:00 (Europe/Warsaw) dodaje trochę punktów
        'night_to' => 5,
        'max_suspend_per_run' => 5,       // bezpiecznik: więcej blokad naraz = raczej awaria niż atak, wtedy tylko e-mail
        'suspend_admins' => false,        // konta z rolą admin w SZO: domyślnie tylko e-mail, bez blokady (żeby nie odciąć IT)
        'admin_emails' => [],             // pusto = wszyscy aktywni administratorzy SZO
        'trusted_cidrs' => [],            // zaufane sieci z pliku (biuro, VPN); w panelu dochodzą wyjątki sieci
        'blocked_cidrs' => [],            // sieci zawsze podejrzane (np. znane VPS-y atakujących)
        'logto_enabled' => true,
        'logto_log_keys' => ['Interaction.SignIn.Submit'],
        'geoip_mmdb' => '',               // ścieżka do GeoLite2-Country.mmdb; działa, gdy jest rozszerzenie maxminddb albo pakiet maxmind-db/reader
        'panel_url' => 'https://szo.feer.org.pl/modules/login_sentry/router.php',
        'points' => [
            'blocked_net' => 100,
            'fail_burst' => 45,
            'country_hop' => 50,
            'many_networks' => 40,
            'foreign_country' => 40,
            'new_network' => 25,
            'new_browser' => 15,
            'script_client' => 20,
            'night' => 10,
        ],
    ];

    /** Klucze, które administrator może zmienić w panelu. */
    public const PANEL_KEYS = ['mode', 'warn_score', 'suspend_score', 'fail_threshold', 'many_networks', 'admin_emails', 'suspend_admins', 'max_suspend_per_run'];

    private static ?array $file = null;
    private static ?array $override = null;

    /** Testy: wymuszenie konfiguracji bez pliku i bez bazy. */
    public static function override(?array $cfg): void
    {
        self::$override = $cfg;
    }

    public static function file(): array
    {
        if (self::$file === null) {
            $path = dirname(__DIR__, 3) . '/config/login_sentry.php';
            $cfg = is_file($path) ? (include $path) : [];
            self::$file = is_array($cfg) ? $cfg : [];
        }
        return self::$file;
    }

    public static function all(): array
    {
        if (self::$override !== null) return array_replace_recursive(self::DEFAULTS, self::$override);
        $cfg = array_replace_recursive(self::DEFAULTS, self::file());
        foreach (self::PANEL_KEYS as $k) {
            $v = LoginSentryDatabase::setting('cfg_' . $k, "\0");
            if ($v === "\0") continue;
            $cfg[$k] = is_array(self::DEFAULTS[$k]) ? (json_decode($v, true) ?: []) : (is_bool(self::DEFAULTS[$k]) ? $v === '1' : (is_int(self::DEFAULTS[$k]) ? (int)$v : $v));
        }
        return $cfg;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function mode(): string
    {
        $m = (string)self::get('mode');
        return in_array($m, self::MODES, true) ? $m : 'off';
    }

    public static function save(string $key, mixed $value): void
    {
        if (!in_array($key, self::PANEL_KEYS, true)) throw new \InvalidArgumentException('Nieznane ustawienie: ' . $key);
        $v = is_array($value) ? json_encode(array_values($value)) : (is_bool($value) ? ($value ? '1' : '0') : (string)$value);
        LoginSentryDatabase::setSetting('cfg_' . $key, (string)$v);
    }
}
