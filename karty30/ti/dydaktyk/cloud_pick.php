<?php
/**
 * AJAX endpoint — picker pliku w chmurze dla panelu dydaktyka TI.
 * Akcje: oc_list, oc_import, url_import.
 */
require_once __DIR__ . '/auth.php';

$s   = dyd_require();
$uid = (int)$s['user_id'];
dyd_token_check();

header('Content-Type: application/json; charset=utf-8');

$act = $_POST['act'] ?? '';

if ($act === 'oc_list') {
    if (!owncloud_admin_configured()) { echo json_encode(['ok'=>false,'err'=>'ownCloud nie skonfigurowany.']); exit; }
    $oc = owncloud_instructor_account($uid);
    if (!$oc) { echo json_encode(['ok'=>false,'err'=>'Brak konta ownCloud.']); exit; }
    $path = trim($_POST['path'] ?? '/');
    if (!preg_match('#^(/[^<>:\"\\\\|?*\x00-\x1f]*)?/?$#u', $path)) $path = '/';
    $items = owncloud_admin_list_files((string)$oc['owncloud_username'], $path);
    echo json_encode(['ok'=>true,'path'=>$path,'items'=>$items]);
    exit;
}

if ($act === 'oc_import') {
    if (!owncloud_admin_configured()) { echo json_encode(['ok'=>false,'err'=>'ownCloud nie skonfigurowany.']); exit; }
    $oc = owncloud_instructor_account($uid);
    if (!$oc) { echo json_encode(['ok'=>false,'err'=>'Brak konta ownCloud.']); exit; }
    $path = trim($_POST['path'] ?? '');
    if ($path === '' || !preg_match('#^(/[^<>:\"\\\\|?*\x00-\x1f]+)+$#u', $path)) {
        echo json_encode(['ok'=>false,'err'=>'Nieprawidłowa ścieżka.']); exit;
    }
    $result = owncloud_admin_import_file((string)$oc['owncloud_username'], $path);
    if (!$result) { echo json_encode(['ok'=>false,'err'=>'Nie udało się pobrać pliku. Sprawdź rozszerzenie pliku.']); exit; }
    echo json_encode(['ok'=>true,'name'=>$result['name'],'stored'=>$result['stored']]);
    exit;
}

if ($act === 'url_import') {
    $url      = trim($_POST['url'] ?? '');
    $filename = trim($_POST['filename'] ?? '');
    if ($url === '') { echo json_encode(['ok'=>false,'err'=>'Podaj URL.']); exit; }
    $result = k30_ti_import_from_url($url, $filename);
    if (!$result) { echo json_encode(['ok'=>false,'err'=>'Nie udało się pobrać pliku. Sprawdź URL i rozszerzenie.']); exit; }
    echo json_encode(['ok'=>true,'name'=>$result['name'],'stored'=>$result['stored']]);
    exit;
}

echo json_encode(['ok'=>false,'err'=>'Nieznana akcja.']);
