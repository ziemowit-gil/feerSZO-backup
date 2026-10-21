<?php
declare(strict_types=1);
/**
 * modules/dictionary/router.php — Słownik Centralny: panel zarządzania słownikami + JSON API.
 *
 * Panel:   …/router.php                 drzewo słowników (?q=, ?trash=1)
 *          …/router.php?_route=/d/{kod}         wpisy słownika; /edit, /history, /export?format=csv|json, /entries/…
 *          …/router.php?_route=/import          import CSV/JSON (z próbą na sucho)
 * JSON:    …/router.php?_route=/api             lista słowników (can_read)
 *          …/router.php?_route=/api/{kod}       aktywne wpisy (?locale=en&at=RRRR-MM-DD&leaves=1) — każdy zalogowany
 * Uprawnienia: moduł 'slowniki' (can_read/can_write/can_delete) — middleware perm:read|write|delete.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/lib/dictionaryApi.php';
require_once __DIR__ . '/logic/DictionaryController.php';

$r = new Router('/modules/dictionary/router.php');

$r->aliasMiddleware('perm', function (string $level): void {
    $ok = match ($level) {
        'write'  => can_write('slowniki'),
        'delete' => can_delete('slowniki'),
        default  => can_read('slowniki'),
    };
    if (!$ok) {
        http_response_code(403);
        echo 'Brak uprawnień do Słownika Centralnego (' . htmlspecialchars($level, ENT_QUOTES) . ').';
        exit;
    }
});

$C = DictionaryController::class;

$r->group(['auth'], function (Router $r) use ($C) {
    // JSON — opcje dla formularzy innych modułów (każdy zalogowany) i lista słowników
    $r->get('/api',        [$C, 'apiList'])->name('dict.api.list');
    $r->get('/api/{code}', [$C, 'apiOptions'])->name('dict.api.options');

    $r->group(['perm:read'], function (Router $r) use ($C) {
        $r->get('/',                    [$C, 'index'])->name('dict.index');
        $r->get('/export',              [$C, 'exportAll'])->name('dict.export.all');
        $r->get('/d/{code}',            [$C, 'show'])->name('dict.show');
        $r->get('/d/{code}/history',    [$C, 'history'])->name('dict.history');
        $r->get('/d/{code}/export',     [$C, 'export'])->name('dict.export');
    });

    $r->group(['perm:write'], function (Router $r) use ($C) {
        $r->get('/new',                         [$C, 'create'])->name('dict.create');
        $r->post('/new',                        [$C, 'store'])->name('dict.store');
        $r->get('/import',                      [$C, 'importForm'])->name('dict.import.form');
        $r->post('/import',                     [$C, 'import'])->name('dict.import');
        $r->get('/d/{code}/edit',               [$C, 'edit'])->name('dict.edit');
        $r->post('/d/{code}/edit',              [$C, 'update'])->name('dict.update');
        $r->post('/d/{code}/protect',           [$C, 'protect'])->name('dict.protect');
        $r->get('/d/{code}/entries/new',        [$C, 'entryCreate'])->name('dict.entry.create');
        $r->post('/d/{code}/entries/new',       [$C, 'entryStore'])->name('dict.entry.store');
        $r->get('/d/{code}/entries/{id}',       [$C, 'entryEdit'])->name('dict.entry.edit');
        $r->post('/d/{code}/entries/{id}',      [$C, 'entryUpdate'])->name('dict.entry.update');
    });

    $r->group(['perm:delete'], function (Router $r) use ($C) {
        $r->post('/d/{code}/delete',                 [$C, 'destroy'])->name('dict.destroy');
        $r->post('/d/{code}/restore',                [$C, 'restore'])->name('dict.restore');
        $r->post('/d/{code}/entries/{id}/delete',    [$C, 'entryDestroy'])->name('dict.entry.destroy');
        $r->post('/d/{code}/entries/{id}/restore',   [$C, 'entryRestore'])->name('dict.entry.restore');
    });
});

$r->dispatch();
