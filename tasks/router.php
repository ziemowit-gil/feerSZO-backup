<?php
/**
 * tasks/router.php — wejście nowych ekranów modułu Zadania (kalendarz, SLA, obciążenie, reguły).
 *
 * Adresy (…/tasks/router.php?_route=/…, tryb query); stare /modules/tasks_plus/router.php/… → alias w .htaccess:
 *   /kalendarz            — kalendarz terminów (miesiąc / lista), filtry ?ws, ?mine, ?done, ?m=RRRR-MM
 *   /kalendarz/ical       — POST: nowy prywatny link iCal; /kalendarz/ical/wylacz — POST: wyłączenie
 *   /ical/{token}.ics     — publiczny kanał iCal (autoryzacja tokenem)
 *   /sla                  — SLA i eskalacje (liderzy obszarów); POST /sla/zasady, POST /sla/sprawdz
 *   /obciazenie           — obciążenie zespołu (lider: wykonawcy jego obszarów, inni: własne); /obciazenie.csv
 *   /reguly               — reguły automatyzacji obszaru (liderzy); POST /reguly, /reguly/{id}/przelacz, /reguly/{id}/usun
 *   /nowe                 — formularz „Nowe zadanie” (?comm=ID&src=crm|ezd — z e-maila; ?link=typ:ref&back= — z karty rekordu)
 *   /powiazania           — POST: powiązanie zadania z rekordem po wklejonym adresie; /powiazania/usun — POST
 *   /skrzynka-obszaru     — e-maile na skrzynkę obszaru → zadania (liderzy); POST zapisuje
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
require_once __DIR__ . '/logic/NewTaskController.php';
require_once __DIR__ . '/logic/MailIntakeController.php';
require_once __DIR__ . '/logic/LinksController.php';

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
    $r->get('/nowe', [NewTaskController::class, 'form'])->name('new.form');
    $r->post('/nowe', [NewTaskController::class, 'store'])->name('new.store');
    $r->get('/skrzynka-obszaru', [MailIntakeController::class, 'index'])->name('intake.index');
    $r->post('/skrzynka-obszaru', [MailIntakeController::class, 'save'])->name('intake.save');
    $r->post('/powiazania', [LinksController::class, 'add'])->name('links.add');
    $r->post('/powiazania/usun', [LinksController::class, 'remove'])->name('links.remove');
});

$r->dispatch();
