<?php
/**
 * directory/avatar_upload.php — wgrywanie zdjęcia profilowego (POST only).
 * Zdjęcie trafia do uploads/avatars/pending/ i czeka na akceptację admina.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';

require_login();
directory_migrate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/directory/profile_edit.php');
    exit;
}

csrf_check();

$cu        = current_user();
$target_id = (int)($cu['id'] ?? 0);

// Admin może zarządzać cudzym profilem
if (is_admin() && !empty($_POST['user_id'])) {
    $target_id = (int)$_POST['user_id'];
}
if ($target_id !== (int)$cu['id'] && !is_admin()) {
    flash_set('danger', 'Brak uprawnień.');
    header('Location: ' . APP_URL . '/directory/profile_edit.php');
    exit;
}

$back = APP_URL . '/directory/profile_edit.php' . ($target_id !== (int)$cu['id'] ? '?id=' . $target_id : '');

// ── Usunięcie aktualnego / oczekującego zdjęcia ─────────────────────────────
if (!empty($_POST['_remove_avatar'])) {
    $row = db_one(
        "SELECT avatar_file, avatar_pending_file FROM user_profiles WHERE user_id=?",
        [$target_id]
    );
    if ($row) {
        if ($row['avatar_file']) {
            $path = dirname(__DIR__) . '/uploads/avatars/' . $row['avatar_file'];
            if (file_exists($path)) @unlink($path);
        }
        if ($row['avatar_pending_file']) {
            $path = dirname(__DIR__) . '/uploads/avatars/pending/' . $row['avatar_pending_file'];
            if (file_exists($path)) @unlink($path);
        }
        db()->prepare(
            "UPDATE user_profiles
             SET avatar_file='', avatar_pending_file='', avatar_pending_at=NULL, avatar_status=''
             WHERE user_id=?"
        )->execute([$target_id]);
    }
    flash_set('success', 'Zdjęcie profilowe zostało usunięte.');
    header('Location: ' . $back);
    exit;
}

// ── Wgrywanie nowego zdjęcia ────────────────────────────────────────────────
if (empty($_FILES['avatar']['tmp_name'])) {
    flash_set('danger', 'Nie wybrano pliku.');
    header('Location: ' . $back);
    exit;
}

$file     = $_FILES['avatar'];
$max_size = 5 * 1024 * 1024; // 5 MB
$allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $err_map = [
        UPLOAD_ERR_INI_SIZE   => 'Plik przekracza limit serwera.',
        UPLOAD_ERR_FORM_SIZE  => 'Plik przekracza limit formularza.',
        UPLOAD_ERR_PARTIAL    => 'Plik wgrany tylko częściowo.',
        UPLOAD_ERR_NO_FILE    => 'Nie wybrano pliku.',
        UPLOAD_ERR_NO_TMP_DIR => 'Brak katalogu tymczasowego.',
        UPLOAD_ERR_CANT_WRITE => 'Błąd zapisu na dysku.',
    ];
    flash_set('danger', $err_map[$file['error']] ?? 'Błąd wgrywania pliku.');
    header('Location: ' . $back);
    exit;
}

if ($file['size'] > $max_size) {
    flash_set('danger', 'Plik jest zbyt duży. Maksymalny rozmiar: 5 MB.');
    header('Location: ' . $back);
    exit;
}

// Walidacja przez getimagesize (nie ufamy rozszerzeniu)
$img_info = @getimagesize($file['tmp_name']);
if (!$img_info || !in_array($img_info['mime'], $allowed, true)) {
    flash_set('danger', 'Niedozwolony format pliku. Akceptowane: JPG, PNG, WebP, GIF.');
    header('Location: ' . $back);
    exit;
}

$ext_map = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
$ext      = $ext_map[$img_info['mime']] ?? 'jpg';
$filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

$pending_dir = dirname(__DIR__) . '/uploads/avatars/pending/';
if (!is_dir($pending_dir)) {
    mkdir($pending_dir, 0775, true);
    // Zablokuj dostęp bezpośredni
    file_put_contents($pending_dir . '.htaccess',
        "Options -Indexes\nDeny from all\n");
}

if (!move_uploaded_file($file['tmp_name'], $pending_dir . $filename)) {
    flash_set('danger', 'Nie udało się zapisać pliku. Spróbuj ponownie.');
    header('Location: ' . $back);
    exit;
}

// Jeśli było poprzednie oczekujące — usuń stary plik
$old = db_one("SELECT avatar_pending_file FROM user_profiles WHERE user_id=?", [$target_id]);
if ($old && $old['avatar_pending_file']) {
    $old_path = $pending_dir . $old['avatar_pending_file'];
    if (file_exists($old_path)) @unlink($old_path);
}

directory_avatar_set_pending($target_id, $filename);

flash_set('success', 'Zdjęcie zostało przesłane i oczekuje na akceptację administratora.');
header('Location: ' . $back);
exit;
