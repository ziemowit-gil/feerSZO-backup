<?php
/**
 * modules/user_merge/router.php — scalanie zduplikowanych kont użytkowników (System Tożsamości).
 *
 * Adresy: POST …/modules/user_merge/router.php?_route=/scal — scalenie z okna porównania na liście kont
 * (/tozsamosc/uzytkownicy.php). Dostęp: administrator (role:admin) + ważna sesja IKA (sprawdza kontroler).
 * Wykrywanie i okno: lib/userMergeDetect.php, view/mergeBadge.php, view/mergeModal.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/UserMergeController.php';

$r = new Router('/modules/user_merge/router.php');
$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->post('/scal', [UserMergeController::class, 'merge'])->name('user_merge.merge');
});
$r->dispatch();
