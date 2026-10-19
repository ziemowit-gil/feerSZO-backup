<?php
/**
 * modules/onedrive_picker/router.php — okno wyboru plików z OneDrive (funkcjonalność współdzielona).
 *
 * POST …/router.php/list — zawartość folderu / wyszukiwanie w OneDrive zalogowanego użytkownika (JSON).
 * Okno: modules/onedrive_picker/view/picker.js (window.OneDrivePicker.open({...})).
 * Pobranie wybranych plików: OneDrivePicker::fetch() w module docelowym, np. tasks/api/import_url.php
 * (od_item_id) i crm/cases/api/od_import.php (item_id).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__) . '/router/lib/Router.php';
require_once __DIR__ . '/logic/OneDrivePickerController.php';

$r = new Router('/modules/onedrive_picker/router.php');

$r->group(['auth'], function (Router $r) {
    $r->post('/list', [OneDrivePickerController::class, 'list'])->name('odp.list');
});

$r->dispatch();
