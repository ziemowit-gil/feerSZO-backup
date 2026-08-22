<?php
/**
 * index.php — front controller (jedno wejście do aplikacji).
 * Ładne adresy zapewnia .htaccess; bez mod_rewrite działa też ?r=/sciezka.
 */
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

// ── Ustal ścieżkę żądania (bez katalogu bazowego i query string) ───────
$path = (string)($_GET['r'] ?? '');
if ($path === '') {
    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $uri  = explode('?', $uri, 2)[0];
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    $path = $uri;
}
$path = '/' . trim(rawurldecode($path), '/');
$path = preg_replace('~/{2,}~', '/', $path) ?? '/';

// ── Brak instalacji → instalator ──────────────────────────────────────
if (!app_installed()) {
    require APP_ROOT . '/controllers/install.php';
    install_controller($path);
    exit;
}
// Zainstalowane: /install jest zablokowane
if ($path === '/install') {
    flash('info', 'Aplikacja jest już zainstalowana.');
    redirect('/admin');
}

// ── Routing ───────────────────────────────────────────────────────────
try {
    if (str_starts_with($path, '/admin')) {
        require APP_ROOT . '/controllers/admin/router.php';
        admin_router(substr($path, strlen('/admin')));
        exit;
    }

    require APP_ROOT . '/controllers/front.php';

    if ($path === '/' )                                        { front_home(); exit; }
    if ($path === '/vcard.vcf' || $path === '/vcard')           { front_vcard(); exit; }
    if (preg_match('~^/p/([a-z0-9\-]+)$~i', $path, $m))         { front_page($m[1]); exit; }
    if (preg_match('~^/g/([a-z0-9\-]+)$~i', $path, $m))         { front_gallery($m[1]); exit; }
    if ($path === '/sitemap.xml')                               { front_sitemap(); exit; }
    if ($path === '/robots.txt')                                { front_robots(); exit; }

    front_not_found();
} catch (Throwable $e) {
    error_log('[wizytowka] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    echo '<h1>Błąd serwera</h1>';
    if ($isLocal) {
        echo '<pre>' . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
    }
}
