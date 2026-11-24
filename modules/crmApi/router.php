<?php
/**
 * modules/crmApi/router.php — API SZO dla aplikacji CRM (crmApp/, Nuxt SPA). Wejście publiczne: api/v1/crmApp.php.
 *
 * Adresy (tryb query Routera): /api/v1/crmApp.php?_route=/contacts/12 …  — lista tras niżej.
 * Uwierzytelnienie: Authorization: Bearer <token użytkownika> z logowania kontem SZO (auth/desktop_login.php →
 * api/v1/desktop_token.php, scope crm:read [crm:write]). Bez ciasteczek, więc bez CSRF; zapis (POST) wymaga crm:write.
 * CORS: źródła z desktop_cors_origins() (SZO + domeny z ustawienia desktop_allowed_redirects).
 * Odpowiedzi: {data, meta?} albo {error, fields?} z kodem HTTP 4xx (api_error / api_json).
 */
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/desktop_auth.php';
require_once dirname(__DIR__, 2) . '/includes/crm.php';
require_once dirname(__DIR__, 2) . '/includes/teryt.php';
require_once dirname(__DIR__, 2) . '/includes/crm_case_extras.php';
require_once dirname(__DIR__, 2) . '/includes/crm_case_parties.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/CrmApiAuth.php';
require_once __DIR__ . '/logic/CrmApiPresenter.php';
require_once __DIR__ . '/logic/CrmApiMetaController.php';
require_once __DIR__ . '/logic/CrmApiContactsController.php';
require_once __DIR__ . '/logic/CrmApiCasesController.php';
require_once __DIR__ . '/logic/CrmApiInboxController.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
desktop_cors();          // CORS + odpowiedź na OPTIONS
crm_migrate();
crm_case_parties_schema();   // fakty kartoteki i sprawy kontaktu czytają crm_case_parties

$r = new Router('/api/v1/crmApp.php');
$r->aliasMiddleware('crmApi.auth', [CrmApiAuth::class, 'authenticate']);

$r->group(['crmApi.auth'], function (Router $r) {
    // Bearer bez ciasteczek — CSRF nie dotyczy (Router domyślnie żąda tokenu formularza dla POST)
    $post = static fn(string $path, array $action) => $r->post($path, $action)->withoutCsrf();

    $r->get('/me',           [CrmApiMetaController::class, 'me']);
    $r->get('/dictionaries', [CrmApiMetaController::class, 'dictionaries']);
    $r->get('/dashboard',    [CrmApiMetaController::class, 'dashboard']);

    $r->get('/contacts',                [CrmApiContactsController::class, 'index']);
    $post('/contacts',                  [CrmApiContactsController::class, 'store']);
    $post('/contacts/bulk',             [CrmApiContactsController::class, 'bulk']);
    $r->get('/contacts/{id}',           [CrmApiContactsController::class, 'show']);
    $post('/contacts/{id}',             [CrmApiContactsController::class, 'update']);
    $post('/contacts/{id}/delete',      [CrmApiContactsController::class, 'destroy']);
    $r->get('/contacts/{id}/notes',     [CrmApiContactsController::class, 'notes']);
    $post('/contacts/{id}/notes',       [CrmApiContactsController::class, 'addNote']);
    $post('/notes/{id}/delete',         [CrmApiContactsController::class, 'deleteNote']);
    $r->get('/contacts/{id}/activity',  [CrmApiContactsController::class, 'activity']);
    $r->get('/contacts/{id}/cases',     [CrmApiContactsController::class, 'cases']);
    $post('/contacts/{id}/tags',        [CrmApiContactsController::class, 'addTag']);
    $post('/contacts/{id}/tags/delete', [CrmApiContactsController::class, 'removeTag']);

    $r->get('/cases',              [CrmApiCasesController::class, 'index']);
    $post('/cases',                [CrmApiCasesController::class, 'store']);
    $r->get('/cases/{id}',         [CrmApiCasesController::class, 'show']);
    $post('/cases/{id}/notes',     [CrmApiCasesController::class, 'addNote']);
    $post('/cases/{id}/status',    [CrmApiCasesController::class, 'setStatus']);

    // Skrzynka CRM (odpowiednik crm/inbox.php)
    $r->get('/inbox/meta',              [CrmApiInboxController::class, 'meta']);
    $r->get('/inbox/poll',              [CrmApiInboxController::class, 'poll']);
    $post('/inbox/bulk',                [CrmApiInboxController::class, 'bulk']);
    $post('/inbox/mark-all-read',       [CrmApiInboxController::class, 'markAllRead']);
    $r->get('/inbox/attachments/{id}',  [CrmApiInboxController::class, 'attachment']);
    $r->get('/inbox/ezd-options',       [CrmApiInboxController::class, 'ezdOptions']);
    $post('/inbox/ooo-back',            [CrmApiInboxController::class, 'oooBack']);
    $post('/inbox/admin/scan',          [CrmApiInboxController::class, 'adminScan']);
    $post('/inbox/admin/autocreate',    [CrmApiInboxController::class, 'adminAutocreate']);
    $r->get('/inbox',                   [CrmApiInboxController::class, 'index']);
    $r->get('/inbox/{id}',              [CrmApiInboxController::class, 'show']);
    $post('/inbox/{id}/action',         [CrmApiInboxController::class, 'action']);
    $post('/inbox/{id}/reply',          [CrmApiInboxController::class, 'reply']);
    $post('/inbox/{id}/assist',         [CrmApiInboxController::class, 'assist']);
});

try {
    $r->dispatch();
} catch (\Throwable $e) {
    error_log('[crmApi] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) api_error('Błąd serwera. Spróbuj ponownie; jeśli się powtarza, zgłoś to w Helpdesku.', 500);
}
