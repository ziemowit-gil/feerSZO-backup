<?php
/**
 * modules/gosposia/router.php — Gosposia: sprzątanie reklam i newsletterów ze skrzynki dPoczta (polecenie 2026-11-13).
 *
 * Adresy (czyste, .htaccess: ^pomocne/gosposia(/.*)?$ → ten plik; trasa ze ścieżki żądania, jak /nowehaslo):
 *   /pomocne/gosposia                 — strona; POST …/polacz, …/rozlacz (formularze), …/przeglad, …/biala-lista[/usun], …/kosz (fetch, JSON).
 * Bez przepisywania (np. php -S): /modules/gosposia/router.php?_route=/pomocne/gosposia. Tylko zalogowani; CSRF dla POST sprawdza Router.
 * Artykuł dla użytkowników: /uslugi-it/uslugi/moja-poczta/gosposia (modules/it_portal/lib/itPortalGosposiaSeed.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/GosposiaController.php';

$r = new Router('/modules/gosposia/router.php', 'path', rtrim(APP_URL, '/'));

// Dostęp: administrator i współpracownicy (osoba z umową, jak Panel współpracownika: user_volunteer_contract_count > 0).
$r->aliasMiddleware('gosposia.access', function (): void {
    require_login();
    if (is_admin() || user_volunteer_contract_count(current_user()) > 0) return;
    http_response_code(403);
    $isJson = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($_POST['password']);
    if ($isJson) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => 'Gosposia jest dla administratora i współpracowników Fundacji.'], JSON_UNESCAPED_UNICODE); exit; }
    echo '<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gosposia</title>'
       . '<main style="font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:3rem auto;padding:0 1rem"><h1>Gosposia</h1>'
       . '<p>To narzędzie jest dla administratora i współpracowników Fundacji (osób z umową).</p>'
       . '<p><a href="' . h(APP_URL . '/') . '">Wróć do SZO</a></p></main>';
    exit;
});

$r->group(['gosposia.access'], function (Router $r) {
    $r->get('/pomocne/gosposia',                   [GosposiaController::class, 'index'])->name('gosposia.index');
    $r->post('/pomocne/gosposia/polacz',           [GosposiaController::class, 'connect'])->name('gosposia.connect');
    $r->post('/pomocne/gosposia/rozlacz',          [GosposiaController::class, 'disconnect'])->name('gosposia.disconnect');
    $r->post('/pomocne/gosposia/przeglad',         [GosposiaController::class, 'scan'])->name('gosposia.scan');
    $r->post('/pomocne/gosposia/biala-lista',      [GosposiaController::class, 'whitelistAdd'])->name('gosposia.whitelist.add');
    $r->post('/pomocne/gosposia/biala-lista/usun', [GosposiaController::class, 'whitelistRemove'])->name('gosposia.whitelist.remove');
    $r->post('/pomocne/gosposia/kosz',             [GosposiaController::class, 'trash'])->name('gosposia.trash');
});

$r->dispatch();
