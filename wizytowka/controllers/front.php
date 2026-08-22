<?php
/**
 * front.php — kontroler części publicznej: strona główna, podstrony,
 * galerie, vCard, sitemap, robots.
 */
declare(strict_types=1);

/** Wyrenderuj widok w layoucie publicznym. */
function render(string $view, array $data = []): void
{
    $data['content_view'] = APP_ROOT . '/views/' . $view . '.php';
    extract($data, EXTR_SKIP);
    require APP_ROOT . '/views/layout.php';
}

/** Strona główna — układ Bento albo pojedyncza kolumna (styl wizytówki). */
function front_home(): void
{
    $tiles  = tiles_active();
    $layout = in_array(setting('site_layout', 'stack'), ['bento', 'split'], true)
        ? setting('site_layout')
        : 'stack';

    render('home', [
        'tiles'       => $tiles,
        'layout'      => $layout,
        'page_title'  => setting('meta_title', setting('site_name')),
        'page_desc'   => setting('meta_description', mb_substr(strip_tags(setting('bio')), 0, 160)),
        'canonical'   => abs_url('/'),
        'is_home'     => true,
    ]);
}

/** Podstrona /p/{slug}. */
function front_page(string $slug): void
{
    $page = page_by_slug($slug);
    if (!$page) { front_not_found(); return; }
    bump_views('pages', (int)$page['id']);

    render('page', [
        'page'       => $page,
        'html'       => render_content((string)$page['content'], (string)$page['format']),
        'page_title' => $page['meta_title'] !== '' ? $page['meta_title'] : $page['title'],
        'page_desc'  => $page['meta_description'] !== '' ? $page['meta_description'] : $page['excerpt'],
        'page_keys'  => $page['meta_keywords'],
        'noindex'    => (int)$page['noindex'] === 1,
        'canonical'  => abs_url('/p/' . $page['slug']),
    ]);
}

/** Galeria /g/{slug} z Lightboxem. */
function front_gallery(string $slug): void
{
    $gallery = gallery_by_slug($slug);
    if (!$gallery) { front_not_found(); return; }
    bump_views('galleries', (int)$gallery['id']);

    render('gallery', [
        'gallery'    => $gallery,
        'images'     => gallery_images((int)$gallery['id']),
        'page_title' => $gallery['meta_title'] !== '' ? $gallery['meta_title'] : $gallery['title'],
        'page_desc'  => $gallery['meta_description'] !== '' ? $gallery['meta_description']
                                                            : mb_substr(strip_tags((string)$gallery['description']), 0, 160),
        'canonical'  => abs_url('/g/' . $gallery['slug']),
    ]);
}

/** Pobranie wizytówki .vcf. */
function front_vcard(): void
{
    if (!setting_bool('vcard_enabled', true)) { front_not_found(); return; }

    $who  = trim(setting('owner_name') . ' ' . setting('owner_name_bold')) ?: setting('site_name', 'kontakt');
    $file = slugify($who, 'kontakt') . '.vcf';
    $body = build_vcard();

    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    echo $body;
}

/** Mapa strony dla wyszukiwarek. */
function front_sitemap(): void
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    $add = function (string $loc, ?string $lastmod = null): void {
        echo "  <url><loc>" . e($loc) . "</loc>";
        if ($lastmod) echo "<lastmod>" . e(substr($lastmod, 0, 10)) . "</lastmod>";
        echo "</url>\n";
    };
    $add(abs_url('/'));
    foreach (pages_published() as $p)     if ((int)$p['noindex'] === 0) $add(abs_url('/p/' . $p['slug']), $p['updated_at']);
    foreach (galleries_published() as $g) $add(abs_url('/g/' . $g['slug']), $g['updated_at']);
    echo "</urlset>\n";
}

function front_robots(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\n";
    echo "Disallow: /admin\n";
    echo "Disallow: /install\n";
    echo "Sitemap: " . abs_url('/sitemap.xml') . "\n";
}

/** 404. */
function front_not_found(): void
{
    http_response_code(404);
    render('404', [
        'page_title' => 'Nie znaleziono strony',
        'page_desc'  => '',
        'noindex'    => true,
    ]);
}
