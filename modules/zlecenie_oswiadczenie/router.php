<?php
/**
 * modules/zlecenie_oswiadczenie/router.php — jedyne wejście modułu „Oświadczenia zleceniobiorcy”
 * (oświadczenie dla celów podatkowych i ubezpieczenia ZUS + oświadczenie o numerze rachunku bankowego ZLB).
 *
 * Czyste adresy /zlecenie/… (.htaccess: ^zlecenie(/.*)?$ → ten plik; trasa ze ścieżki żądania, bez „router.php” w adresie):
 *   /zlecenie                  — PUBLICZNY kreator (bez logowania; CSRF, pułapka na boty, limit prób z IP);
 *   /zlecenie/t/{token}[…]     — kreator z linku (od pracownika albo z publicznego startu);
 *   /zlecenie/umowa/{id}[…]    — zalogowany zleceniobiorca swojej umowy (panel);
 *   /zlecenie/konto[…]         — konto oświadczeń po przesłaniu skanu (logowanie, potwierdzenie z e-maila, reset hasła);
 *                                /zlecenie/konto/osw/{id}[…] — wyłącznie własne oświadczenia;
 *     […] = /krok/{n} | /zloz | /popraw | /druk/{zus|zlb}/{pdf|docx} | /skan/{zus|zlb} | /konto-link;
 *   /zlecenie/obsluga[…]       — pracownik (admin/redaktor): lista, link dla nowej osoby, pakiet, powiązanie z umową,
 *                                projekt umowy, link do umowy, weryfikacja i pobieranie skanów oraz wydruków.
 * Dawne adresy …/modules/zlecenie_oswiadczenie/router.php/… (GET) przekierowują 301 na /zlecenie/…. Każdy POST — CSRF (Router).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/OswController.php';

// Dawny adres z „router.php” w ścieżce → czysty adres /zlecenie/…
$_oswUri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (str_contains($_oswUri, '/modules/zlecenie_oswiadczenie/router.php') && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $_old = '/' . trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
    $_new = match (true) {
        $_old === '/'                                        => '/obsluga',
        $_old === '/publiczne'                               => '',
        (bool)preg_match('#^/(pakiet|link|dokument|nowe)(/|$)#', $_old) => '/obsluga' . $_old,
        default                                              => $_old,
    };
    $_q = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    header('Location: ' . OswService::url($_new) . ($_q !== '' ? '?' . $_q : ''), true, 301);
    exit;
}

// Adresy tras budowane jako pełne (APP_URL + /zlecenie/…) — trafiają do e-maili, kodów QR i linków do skopiowania
$r = new Router('/modules/zlecenie_oswiadczenie/router.php', 'path', rtrim(APP_URL, '/'));

/** Trasy kreatora dla jednego trybu ('p' — panel, 't' — link z tokenem, 'a' — konto oświadczeń). */
$wizard = function (Router $r, string $p, string $mode): void {
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

$r->group(['auth'], fn(Router $r) => $wizard($r, 'p', 'panel'),   '/zlecenie/umowa/{cid}');
$r->group([],       fn(Router $r) => $wizard($r, 't', 'token'),   '/zlecenie/t/{token}');
$r->group([],       fn(Router $r) => $wizard($r, 'a', 'account'), '/zlecenie/konto/osw/{oid}');

// Publiczny kreator i konto oświadczeń (osobna sesja, nie konto SZO)
$pub = fn() => new OswController('public');
$r->get('/zlecenie',                  fn() => $pub()->publicStart())->name('osw.public');
$r->post('/zlecenie',                 fn() => $pub()->publicCreate());
// Podpowiedzi kodu zawodu (słownik kzis — dane publiczne, tylko odczyt)
$r->get('/zlecenie/zawody', function () {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode(OswForm::zawody((string)($_GET['q'] ?? '')), JSON_UNESCAPED_UNICODE);
})->name('osw.zawody');
$r->get('/zlecenie/konto',            fn() => $pub()->accountHome())->name('osw.account');
$r->get('/zlecenie/konto/logowanie',  fn() => $pub()->loginForm())->name('osw.login');
$r->post('/zlecenie/konto/logowanie', fn() => $pub()->login());
$r->post('/zlecenie/konto/wyloguj',   fn() => $pub()->logout())->name('osw.logout');
$r->get('/zlecenie/konto/potwierdz',  fn() => $pub()->confirmForm())->name('osw.confirm');
$r->post('/zlecenie/konto/potwierdz', fn() => $pub()->confirm());
$r->get('/zlecenie/konto/reset',      fn() => $pub()->resetForm())->name('osw.reset');
$r->post('/zlecenie/konto/reset',     fn() => $pub()->reset());

$r->group(['role:admin,editor'], function (Router $r) {
    $r->get('/',                            [OswController::class, 'index'])->name('osw.index');
    $r->post('/nowe',                       [OswController::class, 'inviteNew'])->name('osw.new');
    $r->get('/pakiet/{id}',                 [OswController::class, 'show'])->name('osw.show');
    $r->post('/pakiet/{id}/umowa',          [OswController::class, 'staffLink'])->name('osw.staffLink');
    $r->post('/pakiet/{id}/projekt',        [OswController::class, 'staffProject'])->name('osw.staffProject');
    $r->post('/link/{cid}',                 [OswController::class, 'contractLink'])->name('osw.link');
    $r->post('/dokument/{id}/weryfikacja',  [OswController::class, 'verify'])->name('osw.verify');
    $r->get('/dokument/{id}',               [OswController::class, 'docShow'])->name('osw.doc');
    $r->get('/dokument/{id}/skan',          [OswController::class, 'scan'])->name('osw.scan');
    $r->get('/dokument/{id}/druk/{fmt}',    [OswController::class, 'staffPrint'])->name('osw.staffPrint');
}, '/zlecenie/obsluga');

$r->dispatch();
