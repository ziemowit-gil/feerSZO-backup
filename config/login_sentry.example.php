<?php
/**
 * config/login_sentry.example.php — wzór konfiguracji LoginSentry („Staszek”).
 *
 * Skopiuj jako config/login_sentry.php (plik jest w .gitignore) i zmień tylko to, czego potrzebujesz.
 * Brakujące klucze biorą wartości domyślne z modules/login_sentry/lib/LoginSentryConfig.php.
 * Klucze mode, warn_score, suspend_score, fail_threshold, many_networks, admin_emails, suspend_admins
 * i max_suspend_per_run można też zmienić w panelu; panel wygrywa z tym plikiem.
 * Sekretów tu nie trzymamy: dostęp do Logto (M2M) Staszek bierze z Ustawień logowania jednokrotnego SZO.
 *
 * Crontab (gdy nie używasz dyspozytora CRON SZO, który ma wpis „login_sentry” co 6 h):
 *   15 0,6,12,18 * * *  cd /ścieżka/do/feerSZO && php bin/login_sentry.php >> storage/login_sentry/cron.log 2>&1
 * Ręcznie:
 *   php bin/login_sentry.php --dry-run       pełny raport bez maili i blokad
 *   php bin/login_sentry.php --force-id=12   jeszcze raz konto SZO #12
 *   php bin/login_sentry.php --stats         statystyki
 */
return [
    'mode' => 'preview',                 // off | preview | live; zacznij od preview i obejrzyj panel przez kilka dni
    'window_hours' => 7,
    'warn_score' => 40,
    'suspend_score' => 70,
    'fail_threshold' => 5,
    'fail_window_min' => 60,
    'many_networks' => 3,
    'baseline_min_logins' => 3,
    'baseline_days' => 90,
    'max_suspend_per_run' => 5,
    'suspend_admins' => false,
    'admin_emails' => [
        // 'it@example.org',
    ],
    'trusted_cidrs' => [
        // '203.0.113.0/24',             // biuro
    ],
    'blocked_cidrs' => [],
    'allowed_countries' => ['PL'],       // działa tylko z geoip_mmdb
    'geoip_mmdb' => '',                  // np. __DIR__ . '/../storage/login_sentry/GeoLite2-Country.mmdb'
    'logto_enabled' => true,
    'logto_log_keys' => ['Interaction.SignIn.Submit'],
    'panel_url' => 'https://szo.feer.org.pl/modules/login_sentry/router.php',
    // 'storage_dir' => '/inna/ścieżka/poza/www',
    // 'points' => ['new_network' => 25, 'new_browser' => 15],
];
