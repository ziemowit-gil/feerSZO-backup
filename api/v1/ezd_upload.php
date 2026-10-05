<?php
/**
 * REST API — EZD: przyjmowanie skanów z aplikacji desktopowej (EZD CRM Desktop).
 * Bearer token (Authorization: Bearer <key>). Uprawnienie: ezd:upload.
 *
 * Osobne od ezd.php (tylko odczyt) i od ezd:read — klucz skanera ma móc WYŁĄCZNIE
 * dołączać pliki, bez odczytu i edycji koszulek. Plik przechodzi ten sam proces co
 * upload w UI (ezd_upload → _ezd_upload_process: limit 25 MB, whitelista rozszerzeń,
 * prefiks znaku sprawy, log w metryce, sync SharePoint), a autorem jest właściciel klucza.
 *
 *   POST multipart/form-data  /api/v1/ezd_upload.php
 *     file        — plik (wymagane)
 *     target      — "sprawa" (domyślnie) | "rpw" (skan do dziennika podawczego)
 *     sprawa_id   — dla target=sprawa (koszulka nie może być zamknięta)
 *     pismo_id    — opcjonalnie: załącznik do pisma tej koszulki
 *     grupa_id    — opcjonalnie: grupa plików w koszulce
 *     name        — opcjonalnie: własna nazwa pliku
 *     rpw_id      — dla target=rpw
 *   → 201 {"ok":true,"zalacznik_id":N}  |  201 {"ok":true,"rpw_id":N}
 */

declare(strict_types=1);
define('SKIP_CONSENT_CHECK', true);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/api_auth.php';
require_once dirname(__DIR__, 2) . '/includes/ezd.php';

api_auth_migrate();

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    api_error('Method Not Allowed — użyj POST (multipart/form-data).', 405);
}
api_require('ezd:upload');

global $api_current_key;
$user_id = (int)($api_current_key['created_by'] ?? 0);
if ($user_id <= 0) api_error('Klucz nie ma przypisanego użytkownika (created_by).', 403);

if (empty($_FILES['file']['tmp_name'])) api_error('Brak pliku (pole "file").', 400);

$target = (string)($_POST['target'] ?? 'sprawa');

if ($target === 'rpw') {
    $rpw_id = (int)($_POST['rpw_id'] ?? 0);
    if ($rpw_id <= 0 || !ezd_rpw_get($rpw_id)) api_error('Nie znaleziono przesyłki RPW.', 404);
    $err = ezd_rpw_scan_upload($rpw_id, 'file', $user_id);
    if ($err !== null) api_error($err, 422);
    api_audit('upload', 'ezd_rpw', $rpw_id);
    api_json(['ok' => true, 'rpw_id' => $rpw_id], 201);
}

if ($target !== 'sprawa') api_error('Nieznany target (sprawa|rpw).', 400);

$sprawa_id = (int)($_POST['sprawa_id'] ?? 0);
$sprawa    = $sprawa_id > 0 ? db_one("SELECT id, status FROM ezd_sprawy WHERE id=?", [$sprawa_id]) : null;
if (!$sprawa) api_error('Nie znaleziono koszulki.', 404);
if (($sprawa['status'] ?? '') === 'closed') api_error('Koszulka jest zamknięta.', 409);

$pismo_id = (int)($_POST['pismo_id'] ?? 0) ?: null;
if ($pismo_id !== null && !db_one("SELECT id FROM ezd_pisma WHERE id=? AND sprawa_id=?", [$pismo_id, $sprawa_id])) {
    api_error('Pismo nie należy do tej koszulki.', 422);
}
$grupa_id = (int)($_POST['grupa_id'] ?? 0) ?: null;
if ($grupa_id !== null && !db_one("SELECT id FROM ezd_grupy_plikow WHERE id=? AND sprawa_id=?", [$grupa_id, $sprawa_id])) {
    api_error('Grupa plików nie należy do tej koszulki.', 422);
}
$name = isset($_POST['name']) ? (string)$_POST['name'] : null;

$new_id = null;
$err = ezd_upload('file', $sprawa_id, $user_id, $pismo_id, null, null, null, $grupa_id, $name, $new_id);
if ($err !== null) api_error($err, 422);

api_audit('upload', 'ezd_zalaczniki', (int)$new_id);
api_json(['ok' => true, 'zalacznik_id' => (int)$new_id], 201);
