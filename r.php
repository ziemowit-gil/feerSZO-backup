<?php
/**
 * r.php — Router dynamicznych short linków.
 *
 * Uruchamiany przez .htaccess gdy żądany path nie pasuje
 * do żadnego pliku/katalogu ani hardcoded aliasu.
 *
 * Sprawdza tabelę short_url_routes w DB i:
 *  - jeśli found + redirect_type='redirect' → 301
 *  - jeśli found + redirect_type='internal' → include docelowego pliku
 *  - jeśli not found → 404
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Auto-migracja tabeli
try {
    db()->exec("CREATE TABLE IF NOT EXISTS short_url_routes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        slug        TEXT    NOT NULL UNIQUE,
        target_url  TEXT    NOT NULL,
        label       TEXT    NOT NULL DEFAULT '',
        redirect_type TEXT  NOT NULL DEFAULT 'redirect',
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_by  INTEGER,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        hits        INTEGER NOT NULL DEFAULT 0
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_surl_slug ON short_url_routes(slug)");
} catch (\Throwable $e) {}

$slug = preg_replace('/[^a-z0-9_-]/i', '', $_GET['_slug'] ?? '');

if (!$slug) {
    http_response_code(404);
    echo '404 — Not Found';
    exit;
}

// Szukaj w DB
$route = null;
try {
    $route = db_one(
        "SELECT * FROM short_url_routes WHERE slug=? AND is_active=1",
        [$slug]
    );
} catch (\Throwable $e) {}

if (!$route) {
    http_response_code(404);
    // Elegancka strona 404 z linkiem do strony głównej
    $app_url = defined('APP_URL') ? APP_URL : '';
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><title>404 — Nie znaleziono</title>'
       . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
       . '<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">'
       . '<div class="text-center"><h1 class="display-4 fw-bold text-muted">404</h1>'
       . '<p class="text-muted">Adres <code>/' . h($slug) . '</code> nie istnieje.</p>'
       . '<a href="' . h($app_url) . '/" class="btn btn-primary">Strona główna</a>'
       . '</div></body></html>';
    exit;
}

// Zlicz hit
try {
    db()->prepare("UPDATE short_url_routes SET hits=hits+1 WHERE id=?")->execute([$route['id']]);
} catch (\Throwable $e) {}

$target = $route['target_url'];

// Przekierowanie zewnętrzne / wewnętrzne 301
if ($route['redirect_type'] === 'redirect' || str_starts_with($target, 'http')) {
    // Jeśli target jest ścieżką względną — dodaj APP_URL
    if (!str_starts_with($target, 'http') && defined('APP_URL')) {
        $target = rtrim(APP_URL, '/') . '/' . ltrim($target, '/');
    }
    header('Location: ' . $target, true, 301);
    exit;
}

// Internal — sprawdź czy plik istnieje i go include
if ($route['redirect_type'] === 'internal') {
    $file_path = __DIR__ . '/' . ltrim($target, '/');
    if (file_exists($file_path) && str_ends_with($file_path, '.php')) {
        // Zmień REQUEST_URI żeby PHP myślało że jesteśmy na docelowej ścieżce
        $_SERVER['REQUEST_URI'] = '/' . ltrim($target, '/');
        include $file_path;
        exit;
    }
    // Fallback do redirect
    header('Location: ' . rtrim(APP_URL, '/') . '/' . ltrim($target, '/'), true, 301);
    exit;
}
