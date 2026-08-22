<?php
/**
 * dashboard.php — pulpit panelu: skróty, statystyki, stan konfiguracji.
 */
declare(strict_types=1);

function admin_dashboard(): void
{
    $stats = [
        'tiles'     => (int)q_val('SELECT COUNT(*) FROM bento_tiles', [], 0),
        'tiles_on'  => (int)q_val('SELECT COUNT(*) FROM bento_tiles WHERE is_active = 1', [], 0),
        'pages'     => (int)q_val('SELECT COUNT(*) FROM pages', [], 0),
        'pages_on'  => (int)q_val('SELECT COUNT(*) FROM pages WHERE is_published = 1', [], 0),
        'galleries' => (int)q_val('SELECT COUNT(*) FROM galleries', [], 0),
        'images'    => (int)q_val('SELECT COUNT(*) FROM gallery_images', [], 0),
        'views'     => (int)q_val('SELECT COALESCE(SUM(views),0) FROM pages', [], 0)
                     + (int)q_val('SELECT COALESCE(SUM(views),0) FROM galleries', [], 0),
    ];

    // Podpowiedzi: czego jeszcze brakuje do kompletnej wizytówki
    $todo = [];
    if (setting('avatar') === '')   $todo[] = ['Dodaj zdjęcie / avatar', '/admin/settings'];
    if (setting('tagline') === '')  $todo[] = ['Uzupełnij tytuł lub krótki opis', '/admin/settings'];
    if (setting('bio') === '')      $todo[] = ['Napisz kilka słów o sobie (bio)', '/admin/settings'];
    if (setting('email') === '' && setting('phone') === '') $todo[] = ['Dodaj dane kontaktowe do vCard', '/admin/settings'];
    if ($stats['tiles_on'] === 0)   $todo[] = ['Dodaj pierwszy kafelek na stronę główną', '/admin/tiles'];
    if ($stats['images'] === 0)     $todo[] = ['Wgraj zdjęcia do galerii', '/admin/galleries'];

    $popular = q_all(
        "SELECT title, views, 'Podstrona' AS kind, '/p/' || slug AS href FROM pages WHERE views > 0
         UNION ALL
         SELECT title, views, 'Galeria' AS kind, '/g/' || slug AS href FROM galleries WHERE views > 0
         ORDER BY views DESC LIMIT 5"
    );

    admin_render('dashboard', [
        'page_title' => 'Pulpit',
        'stats'      => $stats,
        'todo'       => $todo,
        'popular'    => $popular,
    ]);
}
