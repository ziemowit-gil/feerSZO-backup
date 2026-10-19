<?php
/**
 * modules/zlecenie_oswiadczenie/router.php — jedyne wejście modułu „Oświadczenia zleceniobiorcy”
 * (oświadczenie dla celów podatkowych i ubezpieczenia ZUS + oświadczenie o numerze rachunku bankowego ZLB).
 *
 * Zleceniobiorca:  …/router.php/umowa/{id}[/krok/{n}|/zloz|/popraw|/druk/{zus|zlb}/{pdf|docx}|/skan/{zus|zlb}] — zalogowany (panel);
 *                  …/router.php/t/{token}[…]  — te same ekrany z linku wygenerowanego przez pracownika albo z publicznego kreatora;
 *                  …/router.php/publiczne      — PUBLICZNY kreator (bez logowania; CSRF, pułapka na boty, limit prób z IP);
 *                  …/router.php/konto[…]       — konto oświadczeń zakładane po przesłaniu skanu (logowanie, potwierdzenie
 *                                                z e-maila, reset hasła); /konto/osw/{id}[…] — wyłącznie własne oświadczenia.
 * Pracownik:       …/router.php/ (lista + link dla nowej osoby), POST /nowe, /pakiet/{id}, POST /link/{id umowy},
 *                  POST /dokument/{id}/weryfikacja, /dokument/{id}/skan, /dokument/{id}/druk/{pdf|docx}.
 * Każdy POST przechodzi przez CSRF (Router).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/OswController.php';

$r = new Router('/modules/zlecenie_oswiadczenie/router.php');

/** Trasy kreatora dla jednego trybu ('p' — panel, 't' — link z tokenem). */
$wizard = function (Router $r, string $p, string $mode, string $key): void {
    $c = fn() => new OswController($mode);
    $r->get('/',                    fn($k) => $c()->home($k))->name("osw.{$p}.home");
    $r->get('/krok/{n}',            fn($k, $n) => $c()->step($k, (int)$n))->name("osw.{$p}.step");
    $r->post('/krok/{n}',           fn($k, $n) => $c()->saveStep($k, (int)$n));
    $r->post('/zloz',               fn($k) => $c()->submit($k))->name("osw.{$p}.submit");
    $r->post('/popraw',             fn($k) => $c()->reopen($k))->name("osw.{$p}.reopen");
    $r->get('/druk/{kind}/{fmt}',   fn($k, $kind, $fmt) => $c()->print($k, (string)$kind, (string)$fmt))->name("osw.{$p}.print");
    $r->post('/skan/{kind}',        fn($k, $kind) => $c()->upload($k, (string)$kind))->name("osw.{$p}.upload");
    $r->post('/konto-link',         fn($k) => $c()->resendAccount($k))->name("osw.{$p}.resend");
};

$r->group(['auth'], fn(Router $r) => $wizard($r, 'p', 'panel', 'cid'), '/umowa/{cid}');
$r->group([],       fn(Router $r) => $wizard($r, 't', 'token', 'token'), '/t/{token}');
$r->group([],       fn(Router $r) => $wizard($r, 'a', 'account', 'oid'), '/konto/osw/{oid}');

// Publiczny kreator i konto oświadczeń (osobna sesja, nie konto SZO)
$pub = fn() => new OswController('public');
$r->get('/publiczne',          fn() => $pub()->publicStart())->name('osw.public');
$r->post('/publiczne',         fn() => $pub()->publicCreate());
$r->get('/konto',              fn() => $pub()->accountHome())->name('osw.account');
$r->get('/konto/logowanie',    fn() => $pub()->loginForm())->name('osw.login');
$r->post('/konto/logowanie',   fn() => $pub()->login());
$r->post('/konto/wyloguj',     fn() => $pub()->logout())->name('osw.logout');
$r->get('/konto/potwierdz',    fn() => $pub()->confirmForm())->name('osw.confirm');
$r->post('/konto/potwierdz',   fn() => $pub()->confirm());
$r->get('/konto/reset',        fn() => $pub()->resetForm())->name('osw.reset');
$r->post('/konto/reset',       fn() => $pub()->reset());

$r->group(['role:admin,editor'], function (Router $r) {
    $r->get('/',                            [OswController::class, 'index'])->name('osw.index');
    $r->post('/nowe',                       [OswController::class, 'inviteNew'])->name('osw.new');
    $r->get('/pakiet/{id}',                 [OswController::class, 'show'])->name('osw.show');
    $r->post('/pakiet/{id}/umowa',          [OswController::class, 'staffLink'])->name('osw.staffLink');
    $r->post('/pakiet/{id}/projekt',        [OswController::class, 'staffProject'])->name('osw.staffProject');
    $r->post('/link/{cid}',                 [OswController::class, 'contractLink'])->name('osw.link');
    $r->post('/dokument/{id}/weryfikacja',  [OswController::class, 'verify'])->name('osw.verify');
    $r->get('/dokument/{id}/skan',          [OswController::class, 'scan'])->name('osw.scan');
    $r->get('/dokument/{id}/druk/{fmt}',    [OswController::class, 'staffPrint'])->name('osw.staffPrint');
});

$r->dispatch();
