<?php
/**
 * tasks/router.php — wejście nowych ekranów modułu Zadania (kalendarz, SLA, obciążenie, reguły).
 *
 * Adresy (…/tasks/router.php/…); stare /modules/tasks_plus/router.php/… → alias w .htaccess:
 *   /kalendarz            — kalendarz terminów (miesiąc / lista), filtry ?ws, ?mine, ?done, ?m=RRRR-MM
 *   /kalendarz/ical       — POST: nowy prywatny link iCal; /kalendarz/ical/wylacz — POST: wyłączenie
 *   /ical/{token}.ics     — publiczny kanał iCal (autoryzacja tokenem)
 *   /sla                  — SLA i eskalacje (liderzy obszarów); POST /sla/zasady, POST /sla/sprawdz
 *   /obciazenie           — obciążenie zespołu (lider: wykonawcy jego obszarów, inni: własne); /obciazenie.csv
 *   /reguly               — reguły automatyzacji obszaru (liderzy); POST /reguly, /reguly/{id}/przelacz, /reguly/{id}/usun
 * Dostęp: zalogowani z włączonym modułem Zadań; uprawnienia per obszar sprawdza logika.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/router/lib/Router.php';
require_once __DIR__ . '/lib/tasksPlus.php';
require_once __DIR__ . '/logic/CalendarController.php';
require_once __DIR__ . '/logic/SlaController.php';
require_once __DIR__ . '/logic/WorkloadController.php';
require_once __DIR__ . '/logic/RulesController.php';

$r = new Router('/tasks/router.php');
$r->aliasMiddleware('tasks', function () {
    require_login();
    require_module_enabled('tasks_enabled', 'Moduł zadań');
});

$r->get('/ical/{token}', [CalendarController::class, 'feed'])->name('cal.feed');

$r->group(['tasks'], function (Router $r) {
    $r->get('/', fn() => Router::redirect(route('cal.index')));
    $r->get('/kalendarz', [CalendarController::class, 'index'])->name('cal.index');
    $r->post('/kalendarz/ical', [CalendarController::class, 'regenerate'])->name('cal.token');
    $r->post('/kalendarz/ical/wylacz', [CalendarController::class, 'revoke'])->name('cal.revoke');
    $r->get('/sla', [SlaController::class, 'index'])->name('sla.index');
    $r->post('/sla/zasady', [SlaController::class, 'save'])->name('sla.save');
    $r->post('/sla/sprawdz', [SlaController::class, 'runNow'])->name('sla.run');
    $r->get('/obciazenie', [WorkloadController::class, 'index'])->name('work.index');
    $r->get('/obciazenie.csv', [WorkloadController::class, 'csv'])->name('work.csv');
    $r->get('/reguly', [RulesController::class, 'index'])->name('rules.index');
    $r->post('/reguly', [RulesController::class, 'store'])->name('rules.store');
    $r->post('/reguly/{id}/przelacz', [RulesController::class, 'toggle'])->name('rules.toggle');
    $r->post('/reguly/{id}/usun', [RulesController::class, 'destroy'])->name('rules.delete');
});

$r->dispatch();
