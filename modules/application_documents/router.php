<?php
/**
 * modules/application_documents/router.php — dokumenty wniosków złożonych elektronicznie (ZSW-1, ZSW-2).
 *
 * Adresy: …/router.php?_route=/{id} (podgląd do druku), …/{id}/pdf (PDF), …/{id}/potwierdzenie[/pdf]
 * (potwierdzenie złożenia w układzie formularza z sumą kontrolną). Zalogowani; dostęp
 * do konkretnego wniosku sprawdza kontroler (autor albo admin/redaktor).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/applications.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/ApplicationDocumentController.php';

$r = new Router('/modules/application_documents/router.php');

$r->group(['auth'], function (Router $r) {
    $r->get('/{id}',     [ApplicationDocumentController::class, 'show'])->name('appdoc.show');
    $r->get('/{id}/pdf', [ApplicationDocumentController::class, 'pdf'])->name('appdoc.pdf');
    $r->get('/{id}/potwierdzenie',     [ApplicationDocumentController::class, 'receipt'])->name('appdoc.receipt');
    $r->get('/{id}/potwierdzenie/pdf', [ApplicationDocumentController::class, 'receiptPdf'])->name('appdoc.receipt.pdf');
});

$r->dispatch();
