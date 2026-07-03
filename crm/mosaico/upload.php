<?php
/**
 * crm/mosaico/upload.php — odpowiednik `fileuploadConfig.url` z kontraktu Mosaico.
 *
 * GET  → lista wcześniej wgranych obrazów (JSON, protokół jQuery-file-upload).
 * POST → upload obrazów (multipart `files[]`), ten sam kształt odpowiedzi.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');

$dir = UPLOAD_DIR . 'crm_mosaico/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$allowed_ext  = ['png', 'jpg', 'jpeg', 'gif'];
$allowed_mime = ['image/png', 'image/jpeg', 'image/gif'];
$max_size     = 5 * 1024 * 1024;

function mosaico_upload_entry(string $name): array {
    $path = UPLOAD_DIR . 'crm_mosaico/' . $name;
    $url  = APP_URL . '/uploads/crm_mosaico/' . rawurlencode($name);
    return [
        'name'         => $name,
        'size'         => filesize($path) ?: 0,
        'url'          => $url,
        'thumbnailUrl' => APP_URL . '/crm/mosaico/img.php?src=' . rawurlencode($url) . '&method=resize&params=' . rawurlencode('160,160'),
    ];
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $files = [];
    foreach (scandir($dir) ?: [] as $name) {
        $full = $dir . $name;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) continue;
        $files[] = mosaico_upload_entry($name);
    }
    echo json_encode(['files' => $files]);
    exit;
}

// POST — upload
$files_out = [];
$uploaded = $_FILES['files'] ?? null;
if ($uploaded && is_array($uploaded['name'] ?? null)) {
    $count = count($uploaded['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($uploaded['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        if (($uploaded['size'][$i] ?? 0) > $max_size) continue;

        $tmp  = $uploaded['tmp_name'][$i];
        $info = @getimagesize($tmp);
        if (!$info || !in_array($info['mime'], $allowed_mime, true)) continue;

        $ext = strtolower(pathinfo($uploaded['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) continue;

        $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . $name)) continue;

        $files_out[] = mosaico_upload_entry($name);
    }
}

echo json_encode(['files' => $files_out]);
