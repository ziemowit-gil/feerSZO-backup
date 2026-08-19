<?php
/**
 * AJAX — walidacja podpisu przez EU DSS (eIDAS).
 *
 * GET ?zal_id=N              — załącznik EZD (wymagany: ezd_enabled + dostęp do sprawy)
 * GET ?file=rel/path         — plik względem UPLOAD_DIR (wymagany: zalogowany)
 *     &name=original.pdf     — oryginalna nazwa pliku (wyświetlana w wynikach)
 *
 * Zwraca JSON dss_validate_file() z includes/dss_client.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/dss_client.php';

require_login();

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (!dss_is_enabled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Walidacja EU DSS nie jest włączona. Skonfiguruj ją w Ustawieniach EZD.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$path = '';
$name = '';

if ($zal_id = (int)($_GET['zal_id'] ?? 0)) {
    require_once dirname(__DIR__) . '/includes/ezd.php';
    require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
    ezd_require_access();

    $z = ezd_zal_get($zal_id);
    if (!$z) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Załącznik nie istnieje.']);
        exit;
    }

    // Sprawdź dostęp do koszulki
    $sprawa = ezd_sprawa_get((int)$z['sprawa_id']);
    if (!$sprawa || !ezd_sprawa_access($sprawa, (int)current_user()['id'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Brak dostępu do tej koszulki.']);
        exit;
    }

    $path = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'];
    $name = $z['original_name'];

} elseif ($file = trim($_GET['file'] ?? '')) {
    // Walidacja bezpieczeństwa: plik musi być wewnątrz UPLOAD_DIR
    $upload_real = realpath(UPLOAD_DIR);
    $abs         = realpath(UPLOAD_DIR . ltrim($file, '/\\'));
    if (!$abs || !$upload_real || !str_starts_with($abs, $upload_real . DIRECTORY_SEPARATOR)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Niedozwolona ścieżka pliku.']);
        exit;
    }
    $path = $abs;
    $name = trim($_GET['name'] ?? '') ?: basename($abs);

} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Wymagany parametr: zal_id lub file.']);
    exit;
}

$result = dss_validate_file($path, $name);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
