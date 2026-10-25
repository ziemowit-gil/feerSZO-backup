<?php
/**
 * modules/rodo_register/router.php — jedyne wejście modułu Obsługa RODO (rejestr czynności, podmioty, wnioski osób,
 * naruszenia, retencja). Adresy: /modules/rodo_register/router.php, …?_route=/wnioski, …?_route=/naruszenia itd.
 * Dostęp: administrator.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/RodoRegisterController.php';

$r = new Router('/modules/rodo_register/router.php');
$r->aliasMiddleware('rodo.access', function (): void {
    require_login();
    if (is_admin()) return;
    http_response_code(403);
    require_once dirname(__DIR__, 2) . '/includes/header.php';
    echo '<div class="alert alert-danger m-4">Obsługa RODO: dostęp ma administrator.</div>';
    require_once dirname(__DIR__, 2) . '/includes/footer.php';
    exit;
});

$C = RodoRegisterController::class;
$r->group(['rodo.access'], function (Router $r) use ($C) {
    $r->get('/',                      [$C, 'index'])->name('rodo.index');
    $r->get('/czynnosci',             [$C, 'activities'])->name('rodo.activities');
    $r->get('/czynnosci/eksport',     [$C, 'activitiesCsv'])->name('rodo.activities.csv');
    $r->get('/czynnosci/{id}',        [$C, 'activityShow'])->name('rodo.activity');
    $r->post('/czynnosci/{id}',       [$C, 'activitySave'])->name('rodo.activity.save');
    $r->get('/raporty',               [$C, 'reports'])->name('rodo.reports');
    $r->get('/raporty/{typ}/pdf',     [$C, 'reportPdf'])->name('rodo.report.pdf');
    $r->get('/raporty/{typ}',         [$C, 'report'])->name('rodo.report');
    $r->get('/podmioty',              [$C, 'processors'])->name('rodo.processors');
    $r->post('/podmioty/{id}',        [$C, 'processorSave'])->name('rodo.processor.save');
    $r->get('/wnioski',               [$C, 'requests'])->name('rodo.requests');
    $r->get('/wnioski/nowy',          [$C, 'requestNew'])->name('rodo.request.new');
    $r->post('/wnioski/nowy',         [$C, 'requestCreate'])->name('rodo.request.create');
    $r->get('/wnioski/{id}',          [$C, 'requestShow'])->name('rodo.request');
    $r->post('/wnioski/{id}',         [$C, 'requestUpdate'])->name('rodo.request.update');
    $r->get('/wnioski/{id}/eksport',  [$C, 'requestExport'])->name('rodo.request.export');
    $r->post('/wnioski/{id}/usun',    [$C, 'requestErase'])->name('rodo.request.erase');
    $r->get('/naruszenia',            [$C, 'breaches'])->name('rodo.breaches');
    $r->get('/naruszenia/{id}',       [$C, 'breachShow'])->name('rodo.breach');
    $r->post('/naruszenia/{id}',      [$C, 'breachSave'])->name('rodo.breach.save');
    $r->get('/retencja',              [$C, 'retention'])->name('rodo.retention');
    $r->post('/retencja',             [$C, 'retentionSave'])->name('rodo.retention.save');
});

$r->dispatch();
