<?php
/**
 * modules/ezdStorage/router.php — kopie dokumentów EZD na FTP i w chmurze Nextcloud (administrator).
 *
 * Adresy (tryb query): /modules/ezdStorage/router.php?_route=/ezdkopie
 *   /ezdkopie              — GET: stan, ustawienia FTP, konto ezdmigracja w chmurze;
 *   /ezdkopie/ftp          — POST: zapis ustawień FTP;   /ezdkopie/test — POST: test FTP albo chmury;
 *   /ezdkopie/konto        — POST: załóż / odłącz konto w chmurze;   /ezdkopie/wyslij — POST: porcja zaległych kopii;
 *   /ezdkopie/sharepoint   — POST: migracja z SharePointa (porcja teraz, włącz / wyłącz).
 * Link: Ustawienia modułu Wirtualne biurko (admin/ezd_settings.php). CSRF dla POST sprawdza Router.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/EzdStorageController.php';

$r = new Router('/modules/ezdStorage/router.php');

$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/ezdkopie',         [EzdStorageController::class, 'index'])->name('ezdStore.index');
    $r->post('/ezdkopie/ftp',    [EzdStorageController::class, 'saveFtp'])->name('ezdStore.ftp');
    $r->post('/ezdkopie/test',   [EzdStorageController::class, 'test'])->name('ezdStore.test');
    $r->post('/ezdkopie/konto',  [EzdStorageController::class, 'account'])->name('ezdStore.account');
    $r->post('/ezdkopie/wyslij', [EzdStorageController::class, 'sync'])->name('ezdStore.sync');
    $r->post('/ezdkopie/sharepoint', [EzdStorageController::class, 'sharepoint'])->name('ezdStore.sharepoint');
});

$r->dispatch();
