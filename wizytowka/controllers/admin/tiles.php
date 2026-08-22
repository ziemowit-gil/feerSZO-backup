<?php
/**
 * tiles.php — menedżer kafelków Bento (CRUD, kolejność, włącz/wyłącz).
 */
declare(strict_types=1);

function admin_tiles_list(): void
{
    admin_render('tiles', [
        'page_title' => 'Kafelki',
        'tiles'      => tiles_all(),
    ]);
}

/** Formularz dodawania ($id = 0) lub edycji kafelka. */
function admin_tiles_form(int $id): void
{
    $tile = $id > 0
        ? q_one('SELECT * FROM bento_tiles WHERE id = :id', ['id' => $id])
        : [
            'id' => 0, 'type' => 'link', 'size' => 'sm', 'title' => '', 'subtitle' => '', 'body' => '',
            'url' => '', 'page_id' => null, 'gallery_id' => null, 'icon' => '', 'image' => '',
            'accent' => '', 'open_blank' => 1, 'position' => (int)q_val('SELECT COALESCE(MAX(position),0)+1 FROM bento_tiles', [], 1),
            'is_active' => 1,
        ];
    if (!$tile) { http_response_code(404); admin_render('404', ['page_title' => 'Nie znaleziono']); return; }

    $errors = [];

    if (is_post()) {
        csrf_check();
        $type = post('type', 'link');
        if (!array_key_exists($type, tile_types())) $errors[] = 'Nieznany typ kafelka.';
        $size = post('size', 'sm');
        if (!array_key_exists($size, tile_sizes())) $errors[] = 'Nieznany rozmiar kafelka.';

        // Wymagania zależne od typu
        if (in_array($type, ['link', 'social'], true)) {
            $u = post('url');
            if ($u === '')                                              $errors[] = 'Podaj adres URL.';
            elseif (!preg_match('~^(https?://|mailto:|tel:)~i', $u))     $errors[] = 'URL musi zaczynać się od http://, https://, mailto: lub tel:';
        }
        if ($type === 'page'    && post_int('page_id') <= 0)             $errors[] = 'Wybierz podstronę.';
        if ($type === 'gallery' && post_int('gallery_id') <= 0)          $errors[] = 'Wybierz galerię.';
        if (in_array($type, ['link','social','page','gallery','text','heading'], true) && post('title') === '' && $type !== 'text') {
            $errors[] = 'Podaj tytuł kafelka.';
        }
        if (post('accent') !== '' && !preg_match('~^#[0-9a-f]{6}$~i', post('accent'))) {
            $errors[] = 'Kolor akcentu musi mieć format #RRGGBB.';
        }

        // Obraz kafelka
        $image = (string)$tile['image'];
        if (!empty($_FILES['image']['name'])) {
            $res = save_uploaded_image($_FILES['image'], 'tiles');
            if (!$res['ok']) $errors[] = 'Obraz: ' . $res['error'];
            else {
                if ($image !== '') delete_upload($image);
                $image = 'tiles/' . $res['file'];
            }
        }
        if (post_bool('remove_image') && $image !== '') { delete_upload($image); $image = ''; }

        if (!$errors) {
            $data = [
                'type'       => $type,
                'size'       => $size,
                'title'      => post('title'),
                'subtitle'   => post('subtitle'),
                'body'       => (string)($_POST['body'] ?? ''),
                'url'        => post('url'),
                'page_id'    => $type === 'page'    ? post_int('page_id')    : null,
                'gallery_id' => $type === 'gallery' ? post_int('gallery_id') : null,
                'icon'       => post('icon'),
                'image'      => $image,
                'accent'     => post('accent'),
                'open_blank' => post_bool('open_blank') ? 1 : 0,
                'position'   => max(1, post_int('position', 1)),
                'is_active'  => post_bool('is_active') ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($id > 0) {
                db_update('bento_tiles', $id, $data);
                flash('success', 'Kafelek zapisany.');
            } else {
                db_insert('bento_tiles', $data);
                flash('success', 'Kafelek dodany.');
            }
            redirect('/admin/tiles');
        }

        // Przy błędzie pokaż wpisane wartości
        $tile = array_merge($tile, [
            'type' => $type, 'size' => $size, 'title' => post('title'), 'subtitle' => post('subtitle'),
            'body' => (string)($_POST['body'] ?? ''), 'url' => post('url'),
            'page_id' => post_int('page_id') ?: null, 'gallery_id' => post_int('gallery_id') ?: null,
            'icon' => post('icon'), 'image' => $image, 'accent' => post('accent'),
            'open_blank' => post_bool('open_blank') ? 1 : 0, 'position' => post_int('position', 1),
            'is_active' => post_bool('is_active') ? 1 : 0,
        ]);
    }

    admin_render('tile_form', [
        'page_title' => $id > 0 ? 'Edycja kafelka' : 'Nowy kafelek',
        'tile'       => $tile,
        'errors'     => $errors,
        'pages'      => pages_all(),
        'galleries'  => galleries_all(),
    ]);
}

function admin_tiles_delete(int $id): void
{
    admin_require_post();
    $tile = q_one('SELECT image FROM bento_tiles WHERE id = :id', ['id' => $id]);
    if ($tile && $tile['image'] !== '') delete_upload((string)$tile['image']);
    q('DELETE FROM bento_tiles WHERE id = :id', ['id' => $id]);
    flash('success', 'Kafelek usunięty.');
    redirect('/admin/tiles');
}

function admin_tiles_toggle(int $id): void
{
    admin_require_post();
    q("UPDATE bento_tiles SET is_active = 1 - is_active, updated_at = datetime('now') WHERE id = :id", ['id' => $id]);
    redirect('/admin/tiles');
}

function admin_tiles_move(int $id, string $dir): void
{
    admin_require_post();
    reorder_move('bento_tiles', $id, $dir);
    redirect('/admin/tiles');
}

/** Zapis kolejności z drag & drop (lista id w polu order[]). */
function admin_tiles_reorder(): void
{
    admin_require_post();
    $ids = array_map('intval', (array)($_POST['order'] ?? []));
    if ($ids) { reorder_set('bento_tiles', $ids); flash('success', 'Kolejność kafelków zapisana.'); }
    redirect('/admin/tiles');
}
