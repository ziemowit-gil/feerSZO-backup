<?php
/**
 * modules/app_workflow/router.php — workflow pism i wniosków użytkowników.
 *
 * Adresy: …/router.php (typy wniosków i liczba etapów), …?_route=/typ/{id} (edytor etapów typu),
 * POST …?_route=/wniosek/{id} (przejście wniosku do etapu — z admin/application.php).
 * Dostęp: odczyt/przejścia — can_read('admin') lub can_read('pisma'); edycja etapów — can_write('admin').
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/applications.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/AppWorkflowController.php';

$r = new Router('/modules/app_workflow/router.php');
$r->aliasMiddleware('pisma', function (): void {
    if (!can_read('admin') && !can_read('pisma')) { http_response_code(403); exit('Brak dostępu.'); }
});

$r->group(['auth', 'pisma'], function (Router $r) {
    $r->get('/',               [AppWorkflowController::class, 'index'])->name('appwf.index');
    $r->get('/typ/{id}',       [AppWorkflowController::class, 'edit'])->name('appwf.edit');
    $r->post('/typ/{id}',      [AppWorkflowController::class, 'save'])->name('appwf.save');
    $r->post('/wniosek/{id}',  [AppWorkflowController::class, 'move'])->name('appwf.move');
    $r->post('/wniosek/{id}/upz', [AppWorkflowController::class, 'upz'])->name('appwf.upz');
});

$r->dispatch();
