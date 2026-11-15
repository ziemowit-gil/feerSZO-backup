<?php
/**
 * modules/vcardy/router.php — kontakty z Outlooka (Microsoft 365) do pliku VCF albo CSV, przed wyłączeniem Microsoft 365 (20.11.2026).
 *
 * Adresy (czyste, .htaccess: ^pomocne/vcardy(/.*)?$ → ten plik; trasa ze ścieżki żądania, jak /pomocne/gosposia):
 *   GET  /pomocne/vcardy          — strona z wyborem i instrukcją;
 *   POST /pomocne/vcardy/pobierz  — plik .vcf albo .csv.
 * Bez przepisywania (np. php -S): /modules/vcardy/router.php?_route=/pomocne/vcardy. Tylko zalogowani; CSRF dla POST sprawdza Router.
 * Artykuł dla użytkowników: /uslugi-it/uslugi/moja-poczta/kontakty-z-outlooka (modules/it_portal/lib/itPortalVcardySeed.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once dirname(__DIR__) . '/audit_logs/logic/audit_logs.php';
require_once __DIR__ . '/logic/VcardyController.php';

$r = new Router('/modules/vcardy/router.php', 'path', rtrim(APP_URL, '/'));

$r->group(['auth'], function (Router $r) {
    $r->get('/pomocne/vcardy',          [VcardyController::class, 'index'])->name('vcardy.index');
    $r->post('/pomocne/vcardy/pobierz', [VcardyController::class, 'download'])->name('vcardy.download');
});

$r->dispatch();
