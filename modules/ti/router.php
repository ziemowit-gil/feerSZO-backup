<?php
/**
 * modules/ti/router.php — trasy modułu TI (na wzór Laravela). Na razie: Nabór do grup.
 *
 * Dwa wejścia, jedna tablica tras:
 *   • publiczne  — /modules/ti/router.php/<trasa> (sesja SZO, CSRF _csrf): formularz naboru, kod SMS, potwierdzenie e-mail;
 *   • panel      — /karty30/ti/dydaktyk/nabor.php?_route=<trasa> (definiuje TI_ROUTER_PANEL i ładuje sesję panelu
 *                  dydaktyka przed tym plikiem; CSRF dyd_token/_token): obieg zgłoszeń kierownika i widok prowadzącego.
 * Trasy panelu bez wejścia panelu dają 404, trasy publiczne w panelu też — sesje się nie mieszają.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/nabor/nabor.php';

$panel = defined('TI_ROUTER_PANEL');

if (!$panel) {
    require_once __DIR__ . '/logic/nabor/NaborPublicController.php';
    require_once __DIR__ . '/logic/nabor/NaborCandidateController.php';
    $r = new Router(NABOR_ENTRY, 'path', '');   // czyste adresy /nabor/… (.htaccess ^nabor(/.*)?$ → ten plik)
    // Konto kandydata (IRK): niezalogowany → logowanie z powrotem na żądaną stronę
    $r->aliasMiddleware('cand', function () {
        if (!naborAccountCurrent()) {
            $path = Router::current()->currentPath();
            header('Location: ' . naborUrl('/logowanie', ['next' => preg_replace('#^/nabor#', '', $path)]), true, 302);
            exit;
        }
    });
    $r->group([], function (Router $r) {
        $r->get('/',           [NaborPublicController::class, 'form']);                    // krótki adres /nabor (.htaccess)
        $r->get('/zapisy',     [NaborPublicController::class, 'form'])->name('nabor.form');
        $r->post('/zapisy',    [NaborPublicController::class, 'submit']);
        $r->post('/zapisy/sms', [NaborPublicController::class, 'sms'])->name('nabor.sms');
        $r->get('/potwierdz',  [NaborPublicController::class, 'confirmForm'])->name('nabor.confirm');
        $r->post('/potwierdz', [NaborPublicController::class, 'confirm']);

        $r->get('/logowanie',   [NaborCandidateController::class, 'loginForm'])->name('nabor.login');
        $r->post('/logowanie',  [NaborCandidateController::class, 'login']);
        $r->post('/wyloguj',    [NaborCandidateController::class, 'logout'])->name('nabor.logout');
        $r->get('/haslo',       [NaborCandidateController::class, 'passwordForm'])->name('nabor.password');
        $r->post('/haslo',      [NaborCandidateController::class, 'passwordSave']);
        $r->get('/rejestracja',      [NaborCandidateController::class, 'registerForm'])->name('nabor.register');
        $r->post('/rejestracja',     [NaborCandidateController::class, 'register']);
        $r->post('/rejestracja/sms', [NaborCandidateController::class, 'registerSms'])->name('nabor.register.sms');
        $r->get('/aktywuj',          [NaborCandidateController::class, 'activate'])->name('nabor.activate');
        $r->get('/przypomnij',  [NaborCandidateController::class, 'forgotForm'])->name('nabor.forgot');
        $r->post('/przypomnij', [NaborCandidateController::class, 'forgot']);
        $r->group(['cand'], function (Router $r) {
            $r->get('/konto',                              [NaborCandidateController::class, 'dashboard'])->name('nabor.account');
            $r->get('/konto/zgloszenia/{id}',              [NaborCandidateController::class, 'show'])->name('nabor.account.show');
            $r->post('/konto/zgloszenia/{id}',             [NaborCandidateController::class, 'update'])->name('nabor.account.update');
            $r->get('/konto/zgloszenia/{id}/dokument/{doc}', [NaborCandidateController::class, 'document'])->name('nabor.account.doc');
        });
    }, '/nabor');
} else {
    require_once __DIR__ . '/logic/nabor/NaborPanelController.php';
    $r = new Router(NABOR_PANEL_ENTRY, 'query');
    $r->csrfUsing(fn() => dyd_token_check(), fn() => dyd_token(), '_token');
    // Sesja panelu dydaktyka: każdy zalogowany (prowadzący) / tylko kierownik (dyd_is_staff)
    $r->aliasMiddleware('dyd', fn() => dyd_require());
    $r->aliasMiddleware('dyd.staff', function () {
        dyd_require();
        if (!dyd_is_staff()) { header('Location: ' . Router::current()->route('nabor.instructor')); exit; }
    });

    $r->get('/prowadzacy', [NaborPanelController::class, 'instructor'])->name('nabor.instructor')->middleware('dyd');
    $r->group(['dyd.staff'], function (Router $r) {
        $r->get('/',                     [NaborPanelController::class, 'index'])->name('nabor.index');
        $r->post('/zgloszenia/masowo',   [NaborPanelController::class, 'bulk'])->name('nabor.bulk');
        $r->post('/zgloszenia/przenies', [NaborPanelController::class, 'move'])->name('nabor.move');
        $r->post('/przelacz',            [NaborPanelController::class, 'toggleOpen'])->name('nabor.toggle');
        $r->post('/zgloszenia/testowe',  [NaborPanelController::class, 'createTest'])->name('nabor.test');
        $r->get('/zgloszenia/{id}',      [NaborPanelController::class, 'show'])->name('nabor.show');
        $r->post('/zgloszenia/{id}',     [NaborPanelController::class, 'update'])->name('nabor.update');
        $r->get('/zgloszenia/{id}/pdf',  [NaborPanelController::class, 'pdf'])->name('nabor.pdf');
        $r->get('/zgloszenia/{id}/dokument/{doc}', [NaborPanelController::class, 'document'])->name('nabor.doc');
        $r->get('/formularz',            [NaborPanelController::class, 'formEditor'])->name('nabor.form.edit');
        $r->post('/formularz',           [NaborPanelController::class, 'formSave'])->name('nabor.form.save');
        $r->get('/grupy',                [NaborPanelController::class, 'courses'])->name('nabor.courses');
        $r->post('/grupy/{id}',          [NaborPanelController::class, 'courseSave'])->name('nabor.courses.save');
    });
}

$r->dispatch();
