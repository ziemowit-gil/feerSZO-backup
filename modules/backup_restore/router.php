<?php
/**
 * modules/backup_restore/router.php — przywracanie bazy SQL z lokalnej kopii zapasowej (tylko administrator).
 *
 * Adresy: …/router.php?file=RRRR-MM/umowy_….db (wybór kopii, porównanie tabel, formularz przywracania),
 * POST …?_route=/przywroc (przywrócenie całej bazy albo wybranych tabel; CSRF sprawdza Router).
 * Wejście z panelu: admin/backups.php → karta „Przywróć bazę SQL z wczoraj”.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/BackupRestoreController.php';

$r = new Router('/modules/backup_restore/router.php');

$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/',         [BackupRestoreController::class, 'index'])->name('backupRestore.index');
    $r->post('/przywroc', [BackupRestoreController::class, 'restore'])->name('backupRestore.restore');
});

$r->dispatch();
