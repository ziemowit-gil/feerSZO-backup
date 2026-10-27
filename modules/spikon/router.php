<?php
/**
 * modules/spikon/router.php — telefonia Spikon w CRM: ustawienia API, książki telefoniczne z grup CRM, wybieranie numeru.
 *
 * Adresy: …/router.php (ustawienia + książki + przypisanie kont), POST ?_route=/zadzwon (JSON, z karty kontaktu).
 * Dostęp: ustawienia — uprawnienie CRM „Ustawienia” (zapis); /zadzwon — CRM „Kontakty” (odczyt) + dostęp do kontaktu.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/crm.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/SpikonController.php';

$r = new Router('/modules/spikon/router.php');
$r->aliasMiddleware('crmSettings', function (): void {
    require_module_enabled('crm_enabled', 'Moduł CRM');
    crm_require('settings', 'write');
});
$r->aliasMiddleware('crmContacts', function (): void {
    if (!module_enabled('crm_enabled') || !crm_can('contacts', 'read')) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => 'Brak dostępu do kontaktów CRM.'], JSON_UNESCAPED_UNICODE));
    }
});

$r->group(['auth', 'crmSettings'], function (Router $r) {
    $r->get('/',                    [SpikonController::class, 'index'])->name('spikon.index');
    $r->post('/ustawienia',         [SpikonController::class, 'saveSettings'])->name('spikon.settings');
    $r->post('/test',               [SpikonController::class, 'test'])->name('spikon.test');
    $r->post('/konta',              [SpikonController::class, 'saveAccounts'])->name('spikon.accounts');
    $r->post('/ksiazki',            [SpikonController::class, 'linkGroup'])->name('spikon.link');
    $r->post('/ksiazki/{id}/sync',  [SpikonController::class, 'sync'])->name('spikon.sync');
    $r->post('/ksiazki/{id}/usun',  [SpikonController::class, 'unlink'])->name('spikon.unlink');
});
$r->group(['auth', 'crmContacts'], function (Router $r) {
    $r->post('/zadzwon',            [SpikonController::class, 'call'])->name('spikon.call');
});

$r->dispatch();
