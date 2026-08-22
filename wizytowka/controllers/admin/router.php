<?php
/**
 * router.php — routing panelu administratora (/admin/...).
 * Wszystkie akcje zapisujące wymagają POST + tokenu CSRF.
 */
declare(strict_types=1);

require_once APP_ROOT . '/controllers/admin/auth.php';
require_once APP_ROOT . '/controllers/admin/dashboard.php';
require_once APP_ROOT . '/controllers/admin/settings.php';
require_once APP_ROOT . '/controllers/admin/tiles.php';
require_once APP_ROOT . '/controllers/admin/pages.php';
require_once APP_ROOT . '/controllers/admin/galleries.php';

/** Wyrenderuj widok panelu w layoucie administratora. */
function admin_render(string $view, array $data = []): void
{
    $data['content_view'] = APP_ROOT . '/views/admin/' . $view . '.php';
    $data['user']         = current_user();
    extract($data, EXTR_SKIP);
    require APP_ROOT . '/views/admin/layout.php';
}

/** Akcja zmieniająca dane — wymagaj POST i CSRF. */
function admin_require_post(): void
{
    if (!is_post()) { http_response_code(405); exit('Metoda niedozwolona.'); }
    csrf_check();
}

/**
 * Dispatcher panelu. $path to część adresu po „/admin”.
 */
function admin_router(string $path): void
{
    $path = '/' . trim($path, '/');

    // ── Trasy publiczne panelu (logowanie) ────────────────────────────
    if ($path === '/login')  { admin_login();  return; }
    if ($path === '/logout') { admin_logout(); return; }

    // ── Od tego miejsca wymagamy zalogowania ──────────────────────────
    require_admin();

    // Dashboard
    if ($path === '/' || $path === '/dashboard') { admin_dashboard(); return; }

    // Ustawienia i konto
    if ($path === '/settings') { admin_settings(); return; }
    if ($path === '/account')  { admin_account();  return; }

    // ── Kafelki Bento ─────────────────────────────────────────────────
    if ($path === '/tiles')                                            { admin_tiles_list();       return; }
    if ($path === '/tiles/new')                                        { admin_tiles_form(0);      return; }
    if ($path === '/tiles/reorder')                                    { admin_tiles_reorder();    return; }
    if (preg_match('~^/tiles/(\d+)$~', $path, $m))                     { admin_tiles_form((int)$m[1]); return; }
    if (preg_match('~^/tiles/(\d+)/delete$~', $path, $m))              { admin_tiles_delete((int)$m[1]); return; }
    if (preg_match('~^/tiles/(\d+)/toggle$~', $path, $m))              { admin_tiles_toggle((int)$m[1]); return; }
    if (preg_match('~^/tiles/(\d+)/move/(up|down)$~', $path, $m))      { admin_tiles_move((int)$m[1], $m[2]); return; }

    // ── Podstrony ─────────────────────────────────────────────────────
    if ($path === '/pages')                                            { admin_pages_list();      return; }
    if ($path === '/pages/new')                                        { admin_pages_form(0);     return; }
    if (preg_match('~^/pages/(\d+)$~', $path, $m))                     { admin_pages_form((int)$m[1]); return; }
    if (preg_match('~^/pages/(\d+)/delete$~', $path, $m))              { admin_pages_delete((int)$m[1]); return; }
    if (preg_match('~^/pages/(\d+)/move/(up|down)$~', $path, $m))      { admin_pages_move((int)$m[1], $m[2]); return; }

    // ── Galerie i zdjęcia ─────────────────────────────────────────────
    if ($path === '/galleries')                                        { admin_galleries_list();  return; }
    if ($path === '/galleries/new')                                    { admin_galleries_form(0); return; }
    if (preg_match('~^/galleries/(\d+)$~', $path, $m))                 { admin_galleries_form((int)$m[1]); return; }
    if (preg_match('~^/galleries/(\d+)/delete$~', $path, $m))          { admin_galleries_delete((int)$m[1]); return; }
    if (preg_match('~^/galleries/(\d+)/move/(up|down)$~', $path, $m))  { admin_galleries_move((int)$m[1], $m[2]); return; }
    if (preg_match('~^/galleries/(\d+)/images$~', $path, $m))          { admin_images_manage((int)$m[1]); return; }
    if (preg_match('~^/images/(\d+)/delete$~', $path, $m))             { admin_images_delete((int)$m[1]); return; }
    if (preg_match('~^/images/(\d+)/move/(up|down)$~', $path, $m))     { admin_images_move((int)$m[1], $m[2]); return; }
    if (preg_match('~^/images/(\d+)/cover$~', $path, $m))              { admin_images_cover((int)$m[1]); return; }

    http_response_code(404);
    admin_render('404', ['page_title' => 'Nie znaleziono']);
}
