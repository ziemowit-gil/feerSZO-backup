<?php
/**
 * pages.php — menedżer podstron (CRUD + SEO).
 */
declare(strict_types=1);

function admin_pages_list(): void
{
    admin_render('pages', ['page_title' => 'Podstrony', 'pages' => pages_all()]);
}

function admin_pages_form(int $id): void
{
    $page = $id > 0
        ? q_one('SELECT * FROM pages WHERE id = :id', ['id' => $id])
        : [
            'id' => 0, 'slug' => '', 'title' => '', 'excerpt' => '', 'content' => '', 'format' => 'markdown',
            'hero_image' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keywords' => '',
            'noindex' => 0, 'is_published' => 1,
            'position' => (int)q_val('SELECT COALESCE(MAX(position),0)+1 FROM pages', [], 1), 'views' => 0,
        ];
    if (!$page) { http_response_code(404); admin_render('404', ['page_title' => 'Nie znaleziono']); return; }

    $errors = [];

    if (is_post()) {
        csrf_check();
        $title  = post('title');
        $format = post('format', 'markdown') === 'html' ? 'html' : 'markdown';
        if ($title === '') $errors[] = 'Tytuł podstrony jest wymagany.';

        $slug = post('slug') !== '' ? slugify(post('slug')) : slugify($title);
        $slug = unique_slug('pages', $slug, $id > 0 ? $id : null);

        // Obraz nagłówkowy
        $hero = (string)$page['hero_image'];
        if (!empty($_FILES['hero_image']['name'])) {
            $res = save_uploaded_image($_FILES['hero_image'], 'tiles');
            if (!$res['ok']) $errors[] = 'Obraz nagłówka: ' . $res['error'];
            else { if ($hero !== '') delete_upload($hero); $hero = 'tiles/' . $res['file']; }
        }
        if (post_bool('remove_hero_image') && $hero !== '') { delete_upload($hero); $hero = ''; }

        if (!$errors) {
            $data = [
                'slug'             => $slug,
                'title'            => $title,
                'excerpt'          => post('excerpt'),
                'content'          => (string)($_POST['content'] ?? ''),
                'format'           => $format,
                'hero_image'       => $hero,
                'meta_title'       => post('meta_title'),
                'meta_description' => post('meta_description'),
                'meta_keywords'    => post('meta_keywords'),
                'noindex'          => post_bool('noindex') ? 1 : 0,
                'is_published'     => post_bool('is_published') ? 1 : 0,
                'position'         => max(1, post_int('position', 1)),
                'updated_at'       => date('Y-m-d H:i:s'),
            ];
            if ($id > 0) { db_update('pages', $id, $data); flash('success', 'Podstrona zapisana.'); }
            else         { $id = db_insert('pages', $data); flash('success', 'Podstrona utworzona.'); }
            redirect('/admin/pages');
        }

        $page = array_merge($page, $data ?? [], [
            'title' => $title, 'slug' => $slug, 'format' => $format, 'hero_image' => $hero,
            'excerpt' => post('excerpt'), 'content' => (string)($_POST['content'] ?? ''),
            'meta_title' => post('meta_title'), 'meta_description' => post('meta_description'),
            'meta_keywords' => post('meta_keywords'), 'noindex' => post_bool('noindex') ? 1 : 0,
            'is_published' => post_bool('is_published') ? 1 : 0, 'position' => post_int('position', 1),
        ]);
    }

    admin_render('page_form', [
        'page_title' => $id > 0 ? 'Edycja podstrony' : 'Nowa podstrona',
        'page'       => $page,
        'errors'     => $errors,
    ]);
}

function admin_pages_delete(int $id): void
{
    admin_require_post();
    $page = q_one('SELECT hero_image FROM pages WHERE id = :id', ['id' => $id]);
    if ($page && $page['hero_image'] !== '') delete_upload((string)$page['hero_image']);
    q('DELETE FROM pages WHERE id = :id', ['id' => $id]);   // kafelki dostają page_id = NULL (FK)
    flash('success', 'Podstrona usunięta.');
    redirect('/admin/pages');
}

function admin_pages_move(int $id, string $dir): void
{
    admin_require_post();
    reorder_move('pages', $id, $dir);
    redirect('/admin/pages');
}
