<?php
/**
 * modules/ezd_bulk/router.php — akcje masowe na zaznaczonych koszulkach EZD (pasek na /ezd/sprawy/index.php).
 *
 * Adresy (tryb query, bo formularze lecą z listy koszulek): …/router.php?_route=/close itd. Tylko POST z CSRF;
 * wymaga modułu EZD, dostępu do Wirtualnego biurka i prawa edycji (jak checkboksy na liście). Uprawnienia
 * do każdej koszulki sprawdza logika akcji — te same funkcje co akcja pojedyncza.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/EzdBulkController.php';

$r = new Router('/modules/ezd_bulk/router.php', 'query');

$r->aliasMiddleware('ezd', function () {
    require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
    ezd_require_access();
    if (!can_edit()) { flash_set('error', 'Brak uprawnień.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }
});

$r->group(['auth', 'ezd'], function (Router $r) {
    $r->post('/close', [EzdBulkController::class, 'close'])->name('ezdBulk.close');
    $r->post('/owner', [EzdBulkController::class, 'owner'])->name('ezdBulk.owner');
    $r->post('/deadline', [EzdBulkController::class, 'deadline'])->name('ezdBulk.deadline');
    $r->post('/urgent', [EzdBulkController::class, 'urgent'])->name('ezdBulk.urgent');
});

$r->dispatch();
