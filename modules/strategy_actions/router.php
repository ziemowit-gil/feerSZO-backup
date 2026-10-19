<?php
/**
 * modules/strategy_actions/router.php — jedyne wejście ekranu Działań (moduł Strategia, tabela actions).
 *
 * Czyste adresy /dzialania/… (.htaccess: ^dzialania(/.*)?$ → ten plik; trasa ze ścieżki żądania):
 *   /dzialania                 — lista i kalendarz działań (filtry, wyszukiwanie);
 *   /dzialania/nowe            — nowe działanie (?grant_id= wstępnie wybiera grant);
 *   /dzialania/{id}            — karta działania (finansowanie, wskaźniki, uczestnicy CRM);
 *   /dzialania/{id}/edycja     — edycja; POST …/usun — usunięcie (admin albo autor);
 *   /dzialania/kalendarz.json  — zdarzenia kalendarza.
 * Dawne adresy strategy/actions/*.php przekierowują tutaj. Dostęp: admin i redaktor. Każdy POST — CSRF (Router).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/ActionsController.php';

// Adres z „router.php” w ścieżce albo dawny plik pod /dzialania/*.php → czysty adres /dzialania/…
$_dzUri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $_dzTo = null;
    if (str_contains($_dzUri, '/modules/strategy_actions/router.php')) {
        $_dzTo = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
        $_dzTo = preg_replace('#^dzialania/?#', '', $_dzTo);
    } elseif (preg_match('#/dzialania/(index|view|add|edit|calendar)\.php$#', $_dzUri, $_dzM)) {
        $_dzId = (int)($_GET['id'] ?? 0);
        $_dzTo = match ($_dzM[1]) {
            'view'     => $_dzId ? (string)$_dzId : '',
            'edit'     => $_dzId ? $_dzId . '/edycja' : '',
            'add'      => 'nowe',
            'calendar' => 'kalendarz.json',
            default    => '',
        };
    }
    if ($_dzTo !== null) {
        $_dzQ = $_GET; unset($_dzQ['id']);
        header('Location: ' . ActionsService::url((string)$_dzTo, $_dzQ), true, 302);
        exit;
    }
}

$r = new Router('/modules/strategy_actions/router.php', 'path', rtrim(APP_URL, '/'));

$r->group(['role:admin,editor'], function (Router $r) {
    $r->get('/',                        [ActionsController::class, 'index'])->name('dz.index');
    $r->get('/kalendarz.json',          [ActionsController::class, 'calendarFeed'])->name('dz.calendar');
    $r->get('/nowe',                    [ActionsController::class, 'create'])->name('dz.create');
    $r->post('/nowe',                   [ActionsController::class, 'store']);
    $r->get('/{id}',                    [ActionsController::class, 'show'])->name('dz.show');
    $r->get('/{id}/edycja',             [ActionsController::class, 'edit'])->name('dz.edit');
    $r->post('/{id}/edycja',            [ActionsController::class, 'update']);
    $r->post('/{id}/usun',              [ActionsController::class, 'destroy'])->name('dz.delete');
    $r->post('/{id}/uczestnicy',        [ActionsController::class, 'participantAdd'])->name('dz.crm.add');
    $r->post('/{id}/uczestnicy/usun',   [ActionsController::class, 'participantRemove'])->name('dz.crm.remove');
}, '/dzialania');

$r->dispatch();
