<?php
/**
 * cli/owncloud_reconfigure.php — Podgląd i naprawa konfiguracji integracji ownCloud.
 *
 * Integracja ma DWA niezależne konta:
 *   - główne (owncloud_username/password)        — magazyn plików (WebDAV), materiały/zadania TI
 *   - administratora (owncloud_admin_username/password) — OCS Provisioning API,
 *     zakładanie kont „Mój dysk” dla kursantów/prowadzących (karty30/ti/kursant, .../dydaktyk)
 *
 * Jeśli konto administratora nie jest ustawione, konta „Mój dysk” zgłaszają
 * „nie jest jeszcze skonfigurowane”, nawet gdy główne konto (pliki) działa.
 *
 * Użycie:
 *   php cli/owncloud_reconfigure.php --status
 *   php cli/owncloud_reconfigure.php --test
 *   php cli/owncloud_reconfigure.php --copy-main-to-admin
 *   php cli/owncloud_reconfigure.php --url=https://cloud.example.org \
 *       --username=integracja --password=... \
 *       --admin-username=admin --admin-password=...
 *   php cli/owncloud_reconfigure.php --enabled=1
 *   php cli/owncloud_reconfigure.php --student-quota=2048 --instructor-quota=5120
 *   php cli/owncloud_reconfigure.php --help
 *
 * Kody wyjścia: 0 = OK, 1 = błąd walidacji/zapisu, 2 = brak config.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Tylko CLI.\n");
}

$root = dirname(__DIR__);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "[BLAD] Brak config.php.\n");
    exit(2);
}
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/owncloud.php';
owncloud_migrate();

function oc_line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function oc_mask(string $s): string { return $s === '' ? '(puste)' : '••••••••'; }

$opts = getopt('', [
    'status', 'test', 'copy-main-to-admin', 'help',
    'url:', 'username:', 'password:', 'admin-username:', 'admin-password:',
    'enabled:', 'student-quota:', 'instructor-quota:', 'base-folder:',
]);

if (isset($opts['help']) || $opts === []) {
    oc_line("Podgląd i naprawa konfiguracji integracji ownCloud (WebDAV + OCS Provisioning API).");
    oc_line("");
    oc_line("  php cli/owncloud_reconfigure.php --status");
    oc_line("  php cli/owncloud_reconfigure.php --test");
    oc_line("  php cli/owncloud_reconfigure.php --copy-main-to-admin");
    oc_line("  php cli/owncloud_reconfigure.php --url=... --username=... --password=...");
    oc_line("  php cli/owncloud_reconfigure.php --admin-username=... --admin-password=...");
    oc_line("  php cli/owncloud_reconfigure.php --enabled=1|0");
    oc_line("  php cli/owncloud_reconfigure.php --student-quota=2048 --instructor-quota=5120");
    exit(0);
}

// ── Status ─────────────────────────────────────────────────────────────────────
function oc_print_status(): void {
    $main_ok  = owncloud_configured();
    $admin_ok = owncloud_admin_configured();
    oc_line("┌─ Konfiguracja ownCloud ────────────────────────────────────────────");
    oc_line("│ Włączona:            " . (owncloud_setting('enabled') === '1' ? 'TAK' : 'nie'));
    oc_line("│ URL:                 " . (owncloud_setting('url') ?: '(puste)'));
    oc_line("│ Katalog bazowy:      " . owncloud_setting('base_folder', 'feerszo-pliki-lekcji'));
    oc_line("│");
    oc_line("│ Konto główne (pliki, WebDAV):");
    oc_line("│   Login:             " . (owncloud_setting('username') ?: '(puste)'));
    oc_line("│   Hasło:             " . oc_mask(owncloud_setting('password')));
    oc_line("│   Skompletowane:     " . ($main_ok ? 'TAK' : 'NIE'));
    oc_line("│");
    oc_line("│ Konto administratora (OCS Provisioning API, konta „Mój dysk”):");
    oc_line("│   Login:             " . (owncloud_setting('admin_username') ?: '(puste)'));
    oc_line("│   Hasło:             " . oc_mask(owncloud_setting('admin_password')));
    oc_line("│   Skompletowane:     " . ($admin_ok ? 'TAK' : 'NIE'));
    oc_line("│");
    oc_line("│ Limit kursanta:      " . owncloud_setting('student_quota_mb', '2048') . " MB");
    oc_line("│ Limit prowadzącego:  " . owncloud_setting('instructor_quota_mb', '5120') . " MB");
    oc_line("└────────────────────────────────────────────────────────────────────");
    if ($main_ok && !$admin_ok) {
        oc_line("");
        oc_line("[UWAGA] Konto główne jest skonfigurowane, ale konto administratora — nie.");
        oc_line("        Konta „Mój dysk” kursantów/prowadzących zgłaszają błąd konfiguracji,");
        oc_line("        mimo że magazyn plików działa. Jeśli konto główne ma uprawnienia");
        oc_line("        administratora na Twoim serwerze ownCloud, uruchom:");
        oc_line("          php cli/owncloud_reconfigure.php --copy-main-to-admin");
    }
}

if (isset($opts['status'])) {
    oc_print_status();
    exit(0);
}

// ── Test połączenia (bez zapisu) ───────────────────────────────────────────────
if (isset($opts['test'])) {
    oc_line("[INFO] Test konta głównego (WebDAV)...");
    $main_cfg = owncloud_config();
    $r_main = owncloud_test_connection($main_cfg);
    oc_line(($r_main['ok'] ? "[OK]   " : "[BLAD] ") . $r_main['msg']);

    oc_line("");
    oc_line("[INFO] Test konta administratora (OCS Provisioning API)...");
    if (!owncloud_admin_configured()) {
        oc_line("[BLAD] Konto administratora nie jest skonfigurowane (brak loginu/hasła).");
        exit(1);
    }
    $admin_cfg = owncloud_admin_config();
    $exists = owncloud_user_exists($admin_cfg, $admin_cfg['username']);
    if ($exists) {
        oc_line("[OK]   Połączono jako „{$admin_cfg['username']}” — konto istnieje i odpowiada Provisioning API.");
    } else {
        oc_line("[BLAD] Nie udało się potwierdzić konta „{$admin_cfg['username']}” przez Provisioning API.");
        oc_line("       Sprawdź: login/hasło, czy konto ma uprawnienia administratora,");
        oc_line("       oraz czy w ownCloud włączona jest aplikacja „Provisioning API”");
        oc_line("       (occ app:enable provisioning_api).");
        exit(1);
    }
    exit(0);
}

// ── Kopiowanie głównych danych do konta administratora ────────────────────────
if (isset($opts['copy-main-to-admin'])) {
    $user = owncloud_setting('username');
    $pass = owncloud_setting('password');
    if ($user === '' || $pass === '') {
        fwrite(STDERR, "[BLAD] Konto główne nie jest skonfigurowane — nie ma czego kopiować.\n");
        exit(1);
    }
    owncloud_save_setting('admin_username', $user);
    owncloud_save_setting('admin_password', $pass);
    oc_line("[OK] Skopiowano login i hasło konta głównego do konta administratora.");
    oc_line("     Zweryfikuj uprawnienia: php cli/owncloud_reconfigure.php --test");
    exit(0);
}

// ── Ustawienie pojedynczych wartości ────────────────────────────────────────────
$changed = false;

if (isset($opts['url'])) {
    owncloud_save_setting('url', rtrim((string)$opts['url'], '/'));
    oc_line("[OK] URL zapisany.");
    $changed = true;
}
if (isset($opts['username'])) {
    owncloud_save_setting('username', (string)$opts['username']);
    oc_line("[OK] Login konta głównego zapisany.");
    $changed = true;
}
if (isset($opts['password'])) {
    owncloud_save_setting('password', (string)$opts['password']);
    oc_line("[OK] Hasło konta głównego zapisane.");
    $changed = true;
}
if (isset($opts['admin-username'])) {
    owncloud_save_setting('admin_username', (string)$opts['admin-username']);
    oc_line("[OK] Login konta administratora zapisany.");
    $changed = true;
}
if (isset($opts['admin-password'])) {
    owncloud_save_setting('admin_password', (string)$opts['admin-password']);
    oc_line("[OK] Hasło konta administratora zapisane.");
    $changed = true;
}
if (isset($opts['base-folder'])) {
    owncloud_save_setting('base_folder', trim((string)$opts['base-folder'], '/'));
    oc_line("[OK] Katalog bazowy zapisany.");
    $changed = true;
}
if (isset($opts['enabled'])) {
    $val = ((string)$opts['enabled'] === '1') ? '1' : '0';
    owncloud_save_setting('enabled', $val);
    oc_line("[OK] Integracja " . ($val === '1' ? 'włączona' : 'wyłączona') . ".");
    $changed = true;
}
if (isset($opts['student-quota'])) {
    if (!ctype_digit((string)$opts['student-quota'])) {
        fwrite(STDERR, "[BLAD] --student-quota musi być liczbą (MB).\n"); exit(1);
    }
    owncloud_save_setting('student_quota_mb', (string)$opts['student-quota']);
    oc_line("[OK] Limit kursanta zapisany.");
    $changed = true;
}
if (isset($opts['instructor-quota'])) {
    if (!ctype_digit((string)$opts['instructor-quota'])) {
        fwrite(STDERR, "[BLAD] --instructor-quota musi być liczbą (MB).\n"); exit(1);
    }
    owncloud_save_setting('instructor_quota_mb', (string)$opts['instructor-quota']);
    oc_line("[OK] Limit prowadzącego zapisany.");
    $changed = true;
}

if ($changed) {
    oc_line("");
    oc_print_status();
    exit(0);
}

fwrite(STDERR, "[BLAD] Brak rozpoznanych opcji. Użyj --help.\n");
exit(1);
