<?php
/**
 * modules/whatsapp/router.php — panel administratora WhatsApp (bramka HTTP/JSON, szablony, test, dziennik).
 * Adres: …/router.php. Dostęp: tylko administrator.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/WhatsappController.php';

$r = new Router('/modules/whatsapp/router.php');
$r->group(['auth', 'role:admin'], function (Router $r) {
    $r->get('/',                    [WhatsappController::class, 'index'])->name('whatsapp.index');
    $r->post('/ustawienia',         [WhatsappController::class, 'saveSettings'])->name('whatsapp.settings');
    $r->post('/szablony',           [WhatsappController::class, 'saveTemplate'])->name('whatsapp.template.save');
    $r->post('/szablony/{id}/usun', [WhatsappController::class, 'deleteTemplate'])->name('whatsapp.template.delete');
    $r->post('/test',               [WhatsappController::class, 'test'])->name('whatsapp.test');
});

$r->dispatch();
