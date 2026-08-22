<?php
/**
 * galleries.php — menedżer galerii oraz zdjęć (multi-upload, opisy, kolejność).
 */
declare(strict_types=1);

function admin_galleries_list(): void
{
    admin_render('galleries', ['page_title' => 'Galerie', 'galleries' => galleries_all()]);
}

function admin_galleries_form(int $id): void
{
    $gallery = $id > 0
        ? q_one('SELECT * FROM galleries WHERE id = :id', ['id' => $id])
        : [
            'id' => 0, 'slug' => '', 'title' => '', 'description' => '', 'cover_image_id' => null,
            'layout' => 'masonry', 'meta_title' => '', 'meta_description' => '', 'is_published' => 1,
            'position' => (int)q_val('SELECT COALESCE(MAX(position),0)+1 FROM galleries', [], 1), 'views' => 0,
        ];
    if (!$gallery) { http_response_code(404); admin_render('404', ['page_title' => 'Nie znaleziono']); return; }

    $errors = [];

    if (is_post()) {
        csrf_check();
        $title = post('title');
        if ($title === '') $errors[] = 'Tytuł galerii jest wymagany.';

        if (!$errors) {
            $slug = post('slug') !== '' ? slugify(post('slug')) : slugify($title, 'galeria');
            $slug = unique_slug('galleries', $slug, $id > 0 ? $id : null);

            $data = [
                'slug'             => $slug,
                'title'            => $title,
                'description'      => (string)($_POST['description'] ?? ''),
                'layout'           => post('layout', 'masonry') === 'grid' ? 'grid' : 'masonry',
                'meta_title'       => post('meta_title'),
                'meta_description' => post('meta_description'),
                'is_published'     => post_bool('is_published') ? 1 : 0,
                'position'         => max(1, post_int('position', 1)),
                'updated_at'       => date('Y-m-d H:i:s'),
            ];

            if ($id > 0) {
                db_update('galleries', $id, $data);
                flash('success', 'Galeria zapisana.');
                redirect('/admin/galleries');
            }
            $newId = db_insert('galleries', $data);
            flash('success', 'Galeria utworzona — teraz wgraj zdjęcia.');
            redirect('/admin/galleries/' . $newId . '/images');
        }

        $gallery = array_merge($gallery, [
            'title' => $title, 'slug' => post('slug'), 'description' => (string)($_POST['description'] ?? ''),
            'layout' => post('layout', 'masonry'), 'meta_title' => post('meta_title'),
            'meta_description' => post('meta_description'), 'is_published' => post_bool('is_published') ? 1 : 0,
            'position' => post_int('position', 1),
        ]);
    }

    admin_render('gallery_form', [
        'page_title' => $id > 0 ? 'Edycja galerii' : 'Nowa galeria',
        'gallery'    => $gallery,
        'errors'     => $errors,
    ]);
}

function admin_galleries_delete(int $id): void
{
    admin_require_post();
    foreach (gallery_images($id) as $img) {
        delete_upload('gallery/' . $img['filename']);
    }
    q('DELETE FROM galleries WHERE id = :id', ['id' => $id]);   // zdjęcia usuwa ON DELETE CASCADE
    flash('success', 'Galeria i jej zdjęcia usunięte.');
    redirect('/admin/galleries');
}

function admin_galleries_move(int $id, string $dir): void
{
    admin_require_post();
    reorder_move('galleries', $id, $dir);
    redirect('/admin/galleries');
}

/**
 * Zarządzanie zdjęciami galerii: multi-upload oraz zapis opisów/kolejności.
 */
function admin_images_manage(int $galleryId): void
{
    $gallery = q_one('SELECT * FROM galleries WHERE id = :id', ['id' => $galleryId]);
    if (!$gallery) { http_response_code(404); admin_render('404', ['page_title' => 'Nie znaleziono']); return; }

    $errors = [];

    if (is_post()) {
        csrf_check();

        // 1) Multi-upload nowych zdjęć
        if (!empty($_FILES['images']['name'][0] ?? '')) {
            $pos = (int)q_val('SELECT COALESCE(MAX(position),0) FROM gallery_images WHERE gallery_id = :g',
                              ['g' => $galleryId], 0);
            $ok = 0;
            foreach (normalize_files_array($_FILES['images']) as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $res = save_uploaded_image($file, 'gallery');
                if (!$res['ok']) { $errors[] = e((string)$file['name']) . ': ' . $res['error']; continue; }
                db_insert('gallery_images', [
                    'gallery_id' => $galleryId,
                    'filename'   => $res['file'],
                    'original'   => mb_substr((string)$file['name'], 0, 120),
                    'alt'        => '',
                    'caption'    => '',
                    'width'      => $res['width'],
                    'height'     => $res['height'],
                    'size_bytes' => $res['size'],
                    'position'   => ++$pos,
                ]);
                $ok++;
            }
            if ($ok)      flash('success', 'Dodano zdjęć: ' . $ok . '.');
            if ($errors)  flash('error', 'Część plików odrzucono — szczegóły poniżej.');
            if (!$errors) redirect('/admin/galleries/' . $galleryId . '/images');
        }

        // 2) Zapis opisów alt/podpisów/kolejności istniejących zdjęć
        if (isset($_POST['img']) && is_array($_POST['img'])) {
            foreach ($_POST['img'] as $imgId => $fields) {
                $imgId = (int)$imgId;
                $owned = q_one('SELECT id FROM gallery_images WHERE id = :i AND gallery_id = :g',
                               ['i' => $imgId, 'g' => $galleryId]);
                if (!$owned) continue;
                db_update('gallery_images', $imgId, [
                    'alt'      => trim((string)($fields['alt'] ?? '')),
                    'caption'  => trim((string)($fields['caption'] ?? '')),
                    'position' => max(1, (int)($fields['position'] ?? 1)),
                ]);
            }
            flash('success', 'Opisy i kolejność zapisane.');
            redirect('/admin/galleries/' . $galleryId . '/images');
        }
    }

    admin_render('gallery_images', [
        'page_title' => 'Zdjęcia: ' . $gallery['title'],
        'gallery'    => $gallery,
        'images'     => gallery_images($galleryId),
        'errors'     => $errors,
    ]);
}

function admin_images_delete(int $imageId): void
{
    admin_require_post();
    $img = q_one('SELECT * FROM gallery_images WHERE id = :i', ['i' => $imageId]);
    if (!$img) redirect('/admin/galleries');

    delete_upload('gallery/' . $img['filename']);
    q('DELETE FROM gallery_images WHERE id = :i', ['i' => $imageId]);
    q('UPDATE galleries SET cover_image_id = NULL WHERE cover_image_id = :i', ['i' => $imageId]);

    flash('success', 'Zdjęcie usunięte.');
    redirect('/admin/galleries/' . (int)$img['gallery_id'] . '/images');
}

function admin_images_move(int $imageId, string $dir): void
{
    admin_require_post();
    $img = q_one('SELECT gallery_id FROM gallery_images WHERE id = :i', ['i' => $imageId]);
    if (!$img) redirect('/admin/galleries');
    reorder_move('gallery_images', $imageId, $dir, 'gallery_id', (int)$img['gallery_id']);
    redirect('/admin/galleries/' . (int)$img['gallery_id'] . '/images');
}

/** Ustaw zdjęcie jako okładkę galerii. */
function admin_images_cover(int $imageId): void
{
    admin_require_post();
    $img = q_one('SELECT gallery_id FROM gallery_images WHERE id = :i', ['i' => $imageId]);
    if (!$img) redirect('/admin/galleries');
    db_update('galleries', (int)$img['gallery_id'], ['cover_image_id' => $imageId, 'updated_at' => date('Y-m-d H:i:s')]);
    flash('success', 'Okładka galerii ustawiona.');
    redirect('/admin/galleries/' . (int)$img['gallery_id'] . '/images');
}
