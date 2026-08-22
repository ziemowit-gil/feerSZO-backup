<?php
/**
 * install.php (kontroler) — kreator instalacji. Uruchamiany automatycznie,
 * dopóki brak bazy / tabeli settings / konta administratora.
 */
declare(strict_types=1);

require_once APP_ROOT . '/includes/installer.php';

/** Testy środowiska przed instalacją. */
function install_requirements(): array
{
    $rootWritable    = is_writable(APP_ROOT);
    $uploadsWritable = is_dir(UPLOAD_ROOT) ? is_writable(UPLOAD_ROOT) : $rootWritable;

    return [
        ['label' => 'PHP 8.1 lub nowsze',            'ok' => PHP_VERSION_ID >= 80100, 'hint' => 'Wersja: ' . PHP_VERSION],
        ['label' => 'Rozszerzenie pdo_sqlite',       'ok' => extension_loaded('pdo_sqlite'), 'hint' => 'Wymagane do bazy SQLite'],
        ['label' => 'Rozszerzenie fileinfo',        'ok' => extension_loaded('fileinfo'), 'hint' => 'Weryfikacja typów plików'],
        ['label' => 'Rozszerzenie mbstring',        'ok' => extension_loaded('mbstring'), 'hint' => 'Obsługa UTF-8'],
        ['label' => 'Katalog aplikacji zapisywalny','ok' => $rootWritable, 'hint' => APP_ROOT],
        ['label' => 'Katalog uploads zapisywalny',  'ok' => $uploadsWritable, 'hint' => UPLOAD_ROOT],
        ['label' => 'Plik schema.sql',              'ok' => is_readable(SCHEMA_FILE), 'hint' => basename(SCHEMA_FILE)],
    ];
}

/** Kontroler instalatora. */
function install_controller(string $path): void
{
    // Każdy adres przed instalacją prowadzi do instalatora
    if ($path !== '/install') {
        redirect('/install');
    }

    $requirements = install_requirements();
    $canInstall   = !in_array(false, array_column($requirements, 'ok'), true);
    $errors       = [];
    $in           = [
        'site_name'  => post('site_name'),
        'owner_name' => post('owner_name'),
        'tagline'    => post('tagline'),
        'email'      => post('email'),
        'preset'     => post('preset', 'coral') ?: 'coral',
        'seed'       => is_post() ? post_bool('seed') : true,
    ];

    if (is_post()) {
        csrf_check();
        if (!$canInstall) {
            $errors[] = 'Środowisko nie spełnia wymagań — popraw pozycje oznaczone na czerwono.';
        } else {
            $errors = install_run($in + [
                'password'  => (string)($_POST['password'] ?? ''),
                'password2' => (string)($_POST['password2'] ?? ''),
            ]);

            if (!$errors) {
                // Automatyczne zalogowanie świeżo utworzonego administratora
                attempt_login((string)$in['email'], (string)($_POST['password'] ?? ''));
                flash('success', 'Instalacja zakończona. Witaj w panelu!');
                redirect('/admin');
            }
        }
    }

    require APP_ROOT . '/views/install.php';
}
