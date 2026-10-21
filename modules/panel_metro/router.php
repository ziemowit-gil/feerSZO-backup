<?php
/**
 * modules/panel_metro/router.php — ustawienia pulpitu METRO „Mój panel”.
 *
 * Adresy: POST …/router.php?_route=/uklad (grouped=0|1 — układ kafli „Bez grupowania” / „Grupuj wg funkcji”,
 * preferencja per użytkownik w user_prefs; odpowiedź JSON dla fetch, inaczej powrót na stronę).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/PanelMetroController.php';

$r = new Router('/modules/panel_metro/router.php');

$r->group(['auth'], function (Router $r) {
    $r->post('/uklad', [PanelMetroController::class, 'saveGrouping'])->name('panelMetro.grouping');
});

$r->dispatch();
