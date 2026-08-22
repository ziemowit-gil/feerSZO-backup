<?php
/**
 * content.php — warstwa odczytu treści (kafelki, podstrony, galerie)
 * używana zarówno przez frontend, jak i panel.
 */
declare(strict_types=1);

/** Typy kafelków: klucz => etykieta w panelu. */
function tile_types(): array
{
    return [
        'link'    => 'Link zewnętrzny',
        'page'    => 'Podstrona',
        'gallery' => 'Galeria',
        'social'  => 'Ikona social media',
        'text'    => 'Widżet tekstowy',
        'image'   => 'Obraz / grafika',
        'email'   => 'E-mail',
        'phone'   => 'Telefon',
        'heading' => 'Nagłówek sekcji',
        'divider' => 'Linia rozdzielająca',
        'embed'   => 'Kod HTML / embed',
    ];
}

/** Rozmiary kafelków w siatce Bento. */
function tile_sizes(): array
{
    return [
        'sm'   => 'Mały (1×1)',
        'wide' => 'Szeroki (2×1)',
        'tall' => 'Wysoki (1×2)',
        'lg'   => 'Duży (2×2)',
        'full' => 'Pełna szerokość',
    ];
}

/** Klasy CSS siatki dla rozmiaru kafelka. */
function tile_size_class(string $size): string
{
    return 'tile--' . (array_key_exists($size, tile_sizes()) ? $size : 'sm');
}

/** Aktywne kafelki strony głównej (z dołączonymi slugami celów). */
function tiles_active(): array
{
    return q_all(
        'SELECT t.*, p.slug AS page_slug, p.is_published AS page_pub,
                g.slug AS gallery_slug, g.is_published AS gallery_pub
           FROM bento_tiles t
           LEFT JOIN pages     p ON p.id = t.page_id
           LEFT JOIN galleries g ON g.id = t.gallery_id
          WHERE t.is_active = 1
          ORDER BY t.position ASC, t.id ASC'
    );
}

/** Wszystkie kafelki (panel). */
function tiles_all(): array
{
    return q_all(
        'SELECT t.*, p.title AS page_title, g.title AS gallery_title
           FROM bento_tiles t
           LEFT JOIN pages p ON p.id = t.page_id
           LEFT JOIN galleries g ON g.id = t.gallery_id
          ORDER BY t.position ASC, t.id ASC'
    );
}

/** Docelowy URL kafelka (albo '' jeśli kafelek nie jest klikalny). */
function tile_href(array $t): string
{
    return match ($t['type']) {
        'link', 'social' => trim((string)$t['url']),
        'page'    => !empty($t['page_slug']) && (int)($t['page_pub'] ?? 1) === 1 ? url('/p/' . $t['page_slug']) : '',
        'gallery' => !empty($t['gallery_slug']) && (int)($t['gallery_pub'] ?? 1) === 1 ? url('/g/' . $t['gallery_slug']) : '',
        'email'   => $t['url'] !== '' ? 'mailto:' . $t['url'] : (setting('email') ? 'mailto:' . setting('email') : ''),
        'phone'   => $t['url'] !== '' ? 'tel:' . preg_replace('~[^0-9+]~', '', $t['url'])
                                      : (setting('phone') ? 'tel:' . preg_replace('~[^0-9+]~', '', setting('phone')) : ''),
        default   => '',
    };
}

/** Czy kafelek otwiera się w nowej karcie? */
function tile_blank(array $t): bool
{
    return (int)$t['open_blank'] === 1 && in_array($t['type'], ['link', 'social', 'embed'], true);
}

// ── Podstrony ─────────────────────────────────────────────────────────

function page_by_slug(string $slug): ?array
{
    return q_one('SELECT * FROM pages WHERE slug = :s AND is_published = 1', ['s' => $slug]);
}

function pages_all(): array
{
    return q_all('SELECT * FROM pages ORDER BY position ASC, id ASC');
}

function pages_published(): array
{
    return q_all('SELECT * FROM pages WHERE is_published = 1 ORDER BY position ASC, id ASC');
}

// ── Galerie ───────────────────────────────────────────────────────────

function gallery_by_slug(string $slug): ?array
{
    return q_one('SELECT * FROM galleries WHERE slug = :s AND is_published = 1', ['s' => $slug]);
}

function galleries_all(): array
{
    return q_all(
        'SELECT g.*, (SELECT COUNT(*) FROM gallery_images i WHERE i.gallery_id = g.id) AS images_count
           FROM galleries g ORDER BY g.position ASC, g.id ASC'
    );
}

function galleries_published(): array
{
    return q_all(
        'SELECT g.*, (SELECT COUNT(*) FROM gallery_images i WHERE i.gallery_id = g.id) AS images_count
           FROM galleries g WHERE g.is_published = 1 ORDER BY g.position ASC, g.id ASC'
    );
}

function gallery_images(int $galleryId, int $limit = 0): array
{
    $sql = 'SELECT * FROM gallery_images WHERE gallery_id = :g ORDER BY position ASC, id ASC';
    if ($limit > 0) $sql .= ' LIMIT ' . $limit;
    return q_all($sql, ['g' => $galleryId]);
}

/** Miniatura galerii: wskazana okładka albo pierwsze zdjęcie. */
function gallery_cover(array $gallery): ?array
{
    if (!empty($gallery['cover_image_id'])) {
        $img = q_one('SELECT * FROM gallery_images WHERE id = :i AND gallery_id = :g',
            ['i' => (int)$gallery['cover_image_id'], 'g' => (int)$gallery['id']]);
        if ($img) return $img;
    }
    return q_one('SELECT * FROM gallery_images WHERE gallery_id = :g ORDER BY position ASC, id ASC LIMIT 1',
        ['g' => (int)$gallery['id']]);
}

/** Zwiększ licznik odsłon (bez blokowania renderu przy błędzie). */
function bump_views(string $table, int $id): void
{
    try { q("UPDATE {$table} SET views = views + 1 WHERE id = :id", ['id' => $id]); } catch (Throwable) {}
}

/** Kolejność: przesuń element w górę/w dół w obrębie tabeli (opcjonalnie w grupie). */
function reorder_move(string $table, int $id, string $dir, string $groupCol = '', int $groupVal = 0): void
{
    $where = $groupCol !== '' ? " AND {$groupCol} = :gv" : '';
    $par   = $groupCol !== '' ? ['gv' => $groupVal] : [];
    $rows  = q_all("SELECT id, position FROM {$table} WHERE 1=1{$where} ORDER BY position ASC, id ASC", $par);

    $idx = null;
    foreach ($rows as $i => $r) if ((int)$r['id'] === $id) $idx = $i;
    if ($idx === null) return;

    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($swap < 0 || $swap >= count($rows)) return;

    [$rows[$idx], $rows[$swap]] = [$rows[$swap], $rows[$idx]];
    foreach ($rows as $i => $r) {
        q("UPDATE {$table} SET position = :p WHERE id = :id", ['p' => $i + 1, 'id' => (int)$r['id']]);
    }
}

/** Ustaw kolejne pozycje 1..n zgodnie z podaną listą id (drag & drop). */
function reorder_set(string $table, array $ids): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $i = 1;
        foreach ($ids as $id) {
            q("UPDATE {$table} SET position = :p WHERE id = :id", ['p' => $i++, 'id' => (int)$id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
