<?php
/**
 * modules/seeders/router.php — jedyne wejście modułu Seedery (dane testowe). Tylko administrator.
 *
 * Adresy: /seeder-test (lista i formularze), /seeder-test/run (POST, uruchomienie akcji).
 * .htaccess: ^seeder-test(/.*)?$ → ten plik (trasa ze ścieżki żądania, jak /nabor).
 * Bez przepisywania (np. php -S): /modules/seeders/router.php?_route=/seeder-test.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/SeedersController.php';

$r = new Router('/modules/seeders/router.php', 'path', rtrim(APP_URL, '/'));
$r->aliasMiddleware('admin', function () {
    require_login();
    if (!is_admin()) { http_response_code(403); echo 'Seedery są dostępne tylko dla administratora.'; exit; }
});

$r->group(['admin'], function (Router $r) {
    $r->get('/seeder-test',      [SeedersController::class, 'index'])->name('seeders.index');
    $r->post('/seeder-test/run', [SeedersController::class, 'run'])->name('seeders.run');
});

$r->dispatch();
