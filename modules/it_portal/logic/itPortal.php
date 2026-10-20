<?php
/**
 * modules/it_portal/logic/itPortal.php — Portal Usług IT (publiczna baza wiedzy + komunikaty o awariach).
 *
 * Publiczne strony (bez logowania) pod /uslugi-it/… (reguły w .htaccess → modules/it_portal/public/),
 * linkowane z it.feer.org.pl. Edycja treści w panelu: modules/it_portal/index.php (admin/editor).
 *
 * Model:
 *   itp_categories  — usługi (kafle na „Usługi”): ikona, wstęp, dostępność dla grup, powiązane tematy
 *   itp_articles    — artykuły/instrukcje w usłudze (lista z osią po lewej), powiązane tematy
 *   itp_audiences   — grupy odbiorców (pracownik, wolontariusz, uczestnik, IT…): opis, adres /dla/{grupa};
 *                     usługa wskazuje grupy w itp_categories.audiences, katalog „Usługi” filtruje po grupie
 *   itp_notices     — komunikaty: status zgłoszone / w toku / naprawione / informacja, podpis działu
 *   itp_contacts    — tabela „Kontakt”: jednostka, kontakt, zespół
 *   itp_searches    — dziennik wyszukiwań (statystyka w panelu)
 * Ustawienia (baner ostrzegawczy, nagłówek, wstęp „Kontakt”) — org_setting('itp_*').
 * Powiązania (dostępność, powiązane tematy) trzymane jako JSON z listą id.
 */
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once __DIR__ . '/itPortalBlocks.php';

const ITP_NOTICE_STATUSES = [
    'zgloszone'  => ['label' => 'Zgłoszone',  'color' => '#d0312d'],
    'w_toku'     => ['label' => 'W toku',     'color' => '#f2a516'],
    'naprawione' => ['label' => 'Naprawione', 'color' => '#7aa52e'],
    'info'       => ['label' => 'Informacja', 'color' => '#3a5fae'],
];

function itPortalMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('it_portal_migrate', __FILE__)) return;

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS itp_categories (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        slug         TEXT    NOT NULL UNIQUE,
        name         TEXT    NOT NULL,
        icon         TEXT    NOT NULL DEFAULT 'help',      -- klucz z itPortalIconKeys()
        intro_html   TEXT    NOT NULL DEFAULT '',          -- strona usługi bez wybranego artykułu
        intro_plain  TEXT    NOT NULL DEFAULT '',
        external_url TEXT    NOT NULL DEFAULT '',          -- kafel prowadzi poza portal (np. Helpdesk)
        audiences    TEXT    NOT NULL DEFAULT '[]',        -- JSON: id grup, dla których usługa jest dostępna
        related      TEXT    NOT NULL DEFAULT '[]',        -- JSON: id artykułów „Powiązane tematy”
        sort_order   INTEGER NOT NULL DEFAULT 0,
        is_active    INTEGER NOT NULL DEFAULT 1,
        created_at   DATETIME NOT NULL DEFAULT (datetime('now')),
        updated_at   DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS itp_articles (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id  INTEGER NOT NULL REFERENCES itp_categories(id) ON DELETE CASCADE,
        slug         TEXT    NOT NULL,
        title        TEXT    NOT NULL,
        body_html    TEXT    NOT NULL DEFAULT '',
        body_plain   TEXT    NOT NULL DEFAULT '',          -- do wyszukiwarki
        keywords     TEXT    NOT NULL DEFAULT '',          -- dodatkowe słowa dla wyszukiwarki
        related      TEXT    NOT NULL DEFAULT '[]',
        is_pinned    INTEGER NOT NULL DEFAULT 0,           -- „Popularne instrukcje” na Start
        is_published INTEGER NOT NULL DEFAULT 1,
        sort_order   INTEGER NOT NULL DEFAULT 0,
        views        INTEGER NOT NULL DEFAULT 0,
        created_by   INTEGER,
        created_at   DATETIME NOT NULL DEFAULT (datetime('now')),
        updated_at   DATETIME NOT NULL DEFAULT (datetime('now')),
        UNIQUE (category_id, slug)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS itp_audiences (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL,
        icon       TEXT    NOT NULL DEFAULT 'user',
        sort_order INTEGER NOT NULL DEFAULT 0
    )");
    // Grupy jako pełnoprawne filtry katalogu: adres, opis, ukrywanie (2026-10-18)
    $cols = array_column(db_all("PRAGMA table_info(itp_audiences)"), 'name');
    foreach (['slug' => "TEXT NOT NULL DEFAULT ''", 'description' => "TEXT NOT NULL DEFAULT ''", 'is_active' => "INTEGER NOT NULL DEFAULT 1"] as $c => $def) {
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE itp_audiences ADD COLUMN {$c} {$def}");
    }
    foreach (db_all("SELECT id, name FROM itp_audiences WHERE slug = ''") as $a) {
        $slug = itPortalSlug($a['name']) ?: 'grupa-' . $a['id'];
        if (db_one("SELECT 1 FROM itp_audiences WHERE slug = ? AND id <> ?", [$slug, $a['id']])) $slug .= '-' . $a['id'];
        db_exec("UPDATE itp_audiences SET slug = ? WHERE id = ?", [$slug, $a['id']]);
    }
    // Edytor blokowy podstron: JSON bloków (tekst / kreator krok po kroku / obraz / wyróżnienie), 2026-10-20
    foreach (['itp_articles' => 'blocks', 'itp_categories' => 'intro_blocks'] as $t => $c) {
        if (!in_array($c, array_column(db_all("PRAGMA table_info({$t})"), 'name'), true)) {
            $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$c} TEXT NOT NULL DEFAULT ''");
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS itp_notices (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        title        TEXT    NOT NULL,
        body_html    TEXT    NOT NULL DEFAULT '',
        body_plain   TEXT    NOT NULL DEFAULT '',
        status       TEXT    NOT NULL DEFAULT 'info',
        signature    TEXT    NOT NULL DEFAULT '',          -- np. „Dział IT FEER”
        notice_date  TEXT    NOT NULL DEFAULT (date('now')),
        is_published INTEGER NOT NULL DEFAULT 1,
        created_by   INTEGER,
        created_at   DATETIME NOT NULL DEFAULT (datetime('now')),
        updated_at   DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_itp_notices_date ON itp_notices(is_published, notice_date)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS itp_contacts (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        unit         TEXT    NOT NULL,
        contact_html TEXT    NOT NULL DEFAULT '',
        team         TEXT    NOT NULL DEFAULT '',          -- wiersze „Imię Nazwisko | e-mail lub adres”
        sort_order   INTEGER NOT NULL DEFAULT 0,
        created_at   DATETIME NOT NULL DEFAULT (datetime('now')),
        updated_at   DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS itp_searches (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        q          TEXT    NOT NULL,
        hits       INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT (datetime('now'))
    )");
}

// ── Ustawienia ──────────────────────────────────────────────────────────────

const ITP_SETTING_DEFAULTS = [
    'title'          => 'Usługi informatyczne',
    'subtitle'       => '',                       // pusty → ORG_NAME
    'logo_url'       => '',
    'banner_on'      => '0',
    'banner_html'    => '',
    'contact_intro'  => '',
    'footer_links'   => '',                       // wiersze „Etykieta | adres”
];

function itPortalSetting(string $key): string {
    $v = org_setting('itp_' . $key);
    return $v !== '' ? $v : (ITP_SETTING_DEFAULTS[$key] ?? '');
}

function itPortalSettingSet(string $key, string $value): void {
    org_setting_set('itp_' . $key, $value);
}

// ── Pomocnicze ──────────────────────────────────────────────────────────────

/** Bazowy adres publicznej części: <APP_URL>/uslugi-it */
function itPortalUrl(string $path = ''): string {
    return rtrim(APP_URL, '/') . '/uslugi-it' . ($path !== '' ? '/' . ltrim($path, '/') : '/');
}

function itPortalServiceUrl(array $cat, ?array $art = null): string {
    if (($cat['external_url'] ?? '') !== '' && $art === null) return (string)$cat['external_url'];
    return itPortalUrl('uslugi/' . rawurlencode($cat['slug']) . ($art ? '/' . rawurlencode($art['slug']) : ''));
}

function itPortalSlug(string $s): string {
    $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z'];
    $s = strtr(mb_strtolower(trim($s), 'UTF-8'), $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim(substr($s, 0, 80), '-');
}

/** Whitelista tagów z edytora (TinyMCE). Czyścimy przy zapisie i — dla pewności — przy renderze. */
function itPortalSanitizeHtml(string $html): string {
    if (trim($html) === '') return '';
    $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = preg_replace('#<(script|style|iframe|object|embed|form|input|button)\b[^>]*/?>#i', '', $html) ?? '';
    $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li><blockquote><a><span><h2><h3><h4><hr>'
             . '<table><thead><tbody><tr><th><td><code><pre><img>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/\s+(on[a-z]+|style|class|id)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
    $clean = preg_replace('/\s+(href|src)\s*=\s*("\s*(javascript|data|vbscript):[^"]*"|\'\s*(javascript|data|vbscript):[^\']*\'|(javascript|data|vbscript):[^\s>]*)/i', '', $clean) ?? '';
    // linki zewnętrzne otwieramy w tej samej karcie, ale bez przekazywania referrera do obcych stron
    $clean = preg_replace('/<a\s+(?![^>]*\brel=)/i', '<a rel="noopener" ', $clean) ?? $clean;
    return itPortalPlain($clean) === '' && !preg_match('/<img\b/i', $clean) ? '' : trim($clean);
}

function itPortalPlain(string $html): string {
    $v = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6]|blockquote)>#i', "\n", $html) ?? $html;
    $v = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $v = str_replace("\xc2\xa0", ' ', $v);
    return trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", $v) ?? $v) ?? $v);
}

/** @return int[] */
function itPortalIds(mixed $json): array {
    $a = is_array($json) ? $json : json_decode((string)$json, true);
    return is_array($a) ? array_values(array_unique(array_filter(array_map('intval', $a)))) : [];
}

function itPortalExcerpt(string $plain, int $len = 220): string {
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
    return mb_strlen($plain) > $len ? rtrim(mb_substr($plain, 0, $len - 1)) . '…' : $plain;
}

// ── Usługi (kategorie) ──────────────────────────────────────────────────────

function itPortalCategories(bool $onlyActive = true): array {
    return db_all("SELECT c.*, (SELECT COUNT(*) FROM itp_articles a WHERE a.category_id = c.id AND a.is_published = 1) AS n_articles
                   FROM itp_categories c " . ($onlyActive ? "WHERE c.is_active = 1 " : "") . "ORDER BY c.sort_order, c.name");
}

function itPortalCategory(int $id): ?array {
    return db_one("SELECT * FROM itp_categories WHERE id = ?", [$id]);
}

function itPortalCategoryBySlug(string $slug): ?array {
    return db_one("SELECT * FROM itp_categories WHERE slug = ? AND is_active = 1", [$slug]);
}

/** @return int id */
function itPortalCategorySave(array $in, int $id = 0): int {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Podaj nazwę usługi.');
    $slug = itPortalSlug((string)($in['slug'] ?? '') ?: $name);
    if ($slug === '') throw new InvalidArgumentException('Nie da się utworzyć adresu z tej nazwy — wpisz adres ręcznie.');
    if (db_one("SELECT 1 FROM itp_categories WHERE slug = ? AND id <> ?", [$slug, $id])) {
        throw new InvalidArgumentException("Adres „{$slug}” zajmuje już inna usługa.");
    }
    $url = trim((string)($in['external_url'] ?? ''));
    if ($url !== '' && !preg_match('#^(https?://|/)#i', $url)) throw new InvalidArgumentException('Link zewnętrzny musi zaczynać się od http(s):// albo /.');
    [$blocksJson, $intro] = itPortalBlocksFromInput($in, 'intro_blocks', 'intro_html');
    $data = [
        'slug'         => $slug,
        'name'         => $name,
        'icon'         => in_array($in['icon'] ?? '', itPortalIconKeys(), true) ? $in['icon'] : 'help',
        'intro_html'   => $intro,
        'intro_blocks' => $blocksJson,
        'intro_plain'  => itPortalBlocksPlain($blocksJson, $intro),
        'external_url' => $url,
        'audiences'    => json_encode(itPortalIds($in['audiences'] ?? [])),
        'related'      => json_encode(itPortalIds($in['related'] ?? [])),
        'sort_order'   => (int)($in['sort_order'] ?? 0),
        'is_active'    => !empty($in['is_active']) ? 1 : 0,
    ];
    if ($id > 0) { db_update('itp_categories', $data, $id); return $id; }
    return db_insert('itp_categories', $data);
}

function itPortalCategoryDelete(int $id): void {
    db_exec("DELETE FROM itp_articles WHERE category_id = ?", [$id]);
    db_exec("DELETE FROM itp_categories WHERE id = ?", [$id]);
}

// ── Artykuły ────────────────────────────────────────────────────────────────

function itPortalArticles(int $categoryId, bool $onlyPublished = true): array {
    return db_all("SELECT * FROM itp_articles WHERE category_id = ?" . ($onlyPublished ? " AND is_published = 1" : "")
                . " ORDER BY sort_order, title", [$categoryId]);
}

/** Wszystkie artykuły z nazwą usługi (panel, wybór „Powiązanych tematów”). */
function itPortalAllArticles(bool $onlyPublished = false): array {
    return db_all("SELECT a.*, c.name AS category_name, c.slug AS category_slug, c.is_active AS category_active
                   FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id"
                . ($onlyPublished ? " WHERE a.is_published = 1 AND c.is_active = 1" : "")
                . " ORDER BY c.sort_order, c.name, a.sort_order, a.title");
}

function itPortalArticle(int $id): ?array {
    return db_one("SELECT * FROM itp_articles WHERE id = ?", [$id]);
}

function itPortalArticleBySlug(int $categoryId, string $slug): ?array {
    return db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = ? AND is_published = 1", [$categoryId, $slug]);
}

/** @return int id */
function itPortalArticleSave(array $in, int $id = 0, int $userId = 0): int {
    $title = trim((string)($in['title'] ?? ''));
    $catId = (int)($in['category_id'] ?? 0);
    if ($title === '') throw new InvalidArgumentException('Podaj tytuł artykułu.');
    if (!itPortalCategory($catId)) throw new InvalidArgumentException('Wybierz usługę, do której należy artykuł.');
    $slug = itPortalSlug((string)($in['slug'] ?? '') ?: $title);
    if ($slug === '') throw new InvalidArgumentException('Nie da się utworzyć adresu z tego tytułu — wpisz adres ręcznie.');
    if (db_one("SELECT 1 FROM itp_articles WHERE category_id = ? AND slug = ? AND id <> ?", [$catId, $slug, $id])) {
        throw new InvalidArgumentException("W tej usłudze jest już artykuł z adresem „{$slug}”.");
    }
    [$blocksJson, $body] = itPortalBlocksFromInput($in, 'blocks', 'body_html');
    $data = [
        'category_id'  => $catId,
        'slug'         => $slug,
        'title'        => $title,
        'body_html'    => $body,
        'blocks'       => $blocksJson,
        'body_plain'   => itPortalBlocksPlain($blocksJson, $body),
        'keywords'     => trim((string)($in['keywords'] ?? '')),
        'related'      => json_encode(array_values(array_diff(itPortalIds($in['related'] ?? []), [$id]))),
        'is_pinned'    => !empty($in['is_pinned']) ? 1 : 0,
        'is_published' => !empty($in['is_published']) ? 1 : 0,
        'sort_order'   => (int)($in['sort_order'] ?? 0),
    ];
    if ($id > 0) { db_update('itp_articles', $data, $id); return $id; }
    $data['created_by'] = $userId ?: null;
    return db_insert('itp_articles', $data);
}

function itPortalArticleDelete(int $id): void {
    db_exec("DELETE FROM itp_articles WHERE id = ?", [$id]);
}

function itPortalArticleTouch(int $id): void {
    db_exec("UPDATE itp_articles SET views = views + 1 WHERE id = ?", [$id]);
}

/** Artykuły o podanych id (tylko opublikowane, w aktywnych usługach), w kolejności listy. */
function itPortalArticlesByIds(array $ids): array {
    $ids = itPortalIds($ids);
    if (!$ids) return [];
    $rows = db_all("SELECT a.id, a.slug, a.title, c.slug AS category_slug, c.name AS category_name
                    FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id
                    WHERE a.is_published = 1 AND c.is_active = 1 AND a.id IN (" . implode(',', $ids) . ")");
    $byId = array_column($rows, null, 'id');
    return array_values(array_filter(array_map(fn($i) => $byId[$i] ?? null, $ids)));
}

/** „Popularne instrukcje” na Start: najpierw przypięte, potem najczęściej czytane. */
function itPortalPopular(int $limit = 12): array {
    return db_all("SELECT a.id, a.slug, a.title, a.is_pinned, a.views, c.slug AS category_slug
                   FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id
                   WHERE a.is_published = 1 AND c.is_active = 1
                   ORDER BY a.is_pinned DESC, a.views DESC, a.sort_order, a.title LIMIT " . max(1, $limit));
}

// ── Grupy odbiorców („Dla kogo”) ────────────────────────────────────────────

/** Sylwetki grup (klucz → etykieta); rysunki w itPortalAudienceIcon(). */
const ITP_AUDIENCE_ICONS = [
    'user'      => 'Pracownik',
    'volunteer' => 'Wolontariusz',
    'student'   => 'Uczestnik / uczeń',
    'it'        => 'IT / administrator',
    'manager'   => 'Kierownik / zarząd',
    'teacher'   => 'Prowadzący / trener',
    'group'     => 'Zespół / jednostka',
    'guest'     => 'Gość / partner',
];

function itPortalAudiences(bool $onlyActive = false): array {
    return db_all("SELECT * FROM itp_audiences" . ($onlyActive ? " WHERE is_active = 1" : "") . " ORDER BY sort_order, id");
}

function itPortalAudience(int $id): ?array {
    return db_one("SELECT * FROM itp_audiences WHERE id = ?", [$id]);
}

function itPortalAudienceBySlug(string $slug): ?array {
    return db_one("SELECT * FROM itp_audiences WHERE slug = ? AND is_active = 1", [$slug]);
}

function itPortalAudienceUrl(array $aud): string {
    return itPortalUrl('dla/' . rawurlencode($aud['slug']));
}

/** @return int id */
function itPortalAudienceSave(array $in, int $id = 0): int {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Podaj nazwę grupy.');
    $slug = itPortalSlug((string)($in['slug'] ?? '') ?: $name);
    if ($slug === '') throw new InvalidArgumentException('Nie da się utworzyć adresu z tej nazwy — wpisz adres ręcznie.');
    if (db_one("SELECT 1 FROM itp_audiences WHERE slug = ? AND id <> ?", [$slug, $id])) {
        throw new InvalidArgumentException("Adres „{$slug}” zajmuje już inna grupa.");
    }
    $data = [
        'name'        => $name,
        'slug'        => $slug,
        'description' => trim(str_replace("\r", '', (string)($in['description'] ?? ''))),
        'icon'        => isset(ITP_AUDIENCE_ICONS[$in['icon'] ?? '']) ? $in['icon'] : 'user',
        'sort_order'  => (int)($in['sort_order'] ?? 0),
        'is_active'   => !empty($in['is_active']) ? 1 : 0,
    ];
    if ($id > 0) db_update('itp_audiences', $data, $id);
    else $id = db_insert('itp_audiences', $data);
    // Formularz grupy niesie też listę usług — wtedy przypisanie ustawiamy od strony grupy
    if (!empty($in['services_sent'])) {
        itPortalAudienceAssign($id, itPortalIds($in['services'] ?? []));
    }
    return $id;
}

/** Usuwa grupę i jej id z „Dla kogo” wszystkich usług. */
function itPortalAudienceDelete(int $id): void {
    itPortalAudienceAssign($id, []);
    db_exec("DELETE FROM itp_audiences WHERE id = ?", [$id]);
}

/** Ustawia, które usługi są dostępne dla grupy (pozostałe tracą tę grupę). */
function itPortalAudienceAssign(int $audId, array $categoryIds): void {
    $want = itPortalIds($categoryIds);
    foreach (db_all("SELECT id, audiences FROM itp_categories") as $c) {
        $ids = itPortalIds($c['audiences']);
        $has = in_array($audId, $ids, true);
        $should = in_array((int)$c['id'], $want, true);
        if ($has === $should) continue;
        $ids = $should ? array_merge($ids, [$audId]) : array_values(array_diff($ids, [$audId]));
        db_exec("UPDATE itp_categories SET audiences = ? WHERE id = ?", [json_encode(array_values($ids)), $c['id']]);
    }
}

/**
 * Macierz usługa × grupa z panelu: $matrix[catId][audId] = 1. Usługi spoza $categoryIds nie są ruszane,
 * grupy spoza $audienceIds zostają w usłudze bez zmian (np. ukryte, niewyświetlone w macierzy).
 */
function itPortalAudienceMatrixSave(array $matrix, array $categoryIds, array $audienceIds): void {
    $audienceIds = itPortalIds($audienceIds);
    foreach (itPortalIds($categoryIds) as $cid) {
        $c = itPortalCategory($cid);
        if (!$c) continue;
        $keep = array_values(array_diff(itPortalIds($c['audiences']), $audienceIds));
        $on = array_values(array_intersect($audienceIds, itPortalIds(array_keys((array)($matrix[$cid] ?? [])))));
        db_exec("UPDATE itp_categories SET audiences = ? WHERE id = ?", [json_encode(array_values(array_unique(array_merge($keep, $on)))), $cid]);
    }
}

/** Liczba usług (wszystkich, także ukrytych) przypisanych do każdej grupy: [audId => n]. */
function itPortalAudienceCounts(): array {
    $n = [];
    foreach (db_all("SELECT audiences FROM itp_categories") as $c) {
        foreach (itPortalIds($c['audiences']) as $aid) $n[$aid] = ($n[$aid] ?? 0) + 1;
    }
    return $n;
}

/** Usługi dostępne dla grupy (aktywne, w kolejności katalogu). */
function itPortalCategoriesFor(int $audId): array {
    return array_values(array_filter(itPortalCategories(), fn($c) => in_array($audId, itPortalIds($c['audiences']), true)));
}

// ── Komunikaty ──────────────────────────────────────────────────────────────

function itPortalNotices(int $limit = 0, bool $onlyPublished = true): array {
    return db_all("SELECT * FROM itp_notices" . ($onlyPublished ? " WHERE is_published = 1" : "")
                . " ORDER BY notice_date DESC, id DESC" . ($limit > 0 ? " LIMIT " . $limit : ""));
}

function itPortalNotice(int $id): ?array {
    return db_one("SELECT * FROM itp_notices WHERE id = ?", [$id]);
}

function itPortalNoticeSave(array $in, int $id = 0, int $userId = 0): int {
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') throw new InvalidArgumentException('Podaj tytuł komunikatu.');
    $date = (string)($in['notice_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $body = itPortalSanitizeHtml((string)($in['body_html'] ?? ''));
    $data = [
        'title'        => $title,
        'body_html'    => $body,
        'body_plain'   => itPortalPlain($body),
        'status'       => isset(ITP_NOTICE_STATUSES[$in['status'] ?? '']) ? $in['status'] : 'info',
        'signature'    => trim((string)($in['signature'] ?? '')),
        'notice_date'  => $date,
        'is_published' => !empty($in['is_published']) ? 1 : 0,
    ];
    if ($id > 0) { db_update('itp_notices', $data, $id); return $id; }
    $data['created_by'] = $userId ?: null;
    return db_insert('itp_notices', $data);
}

function itPortalNoticeDelete(int $id): void {
    db_exec("DELETE FROM itp_notices WHERE id = ?", [$id]);
}

// ── Kontakt ─────────────────────────────────────────────────────────────────

function itPortalContacts(): array {
    return db_all("SELECT * FROM itp_contacts ORDER BY sort_order, unit");
}

function itPortalContact(int $id): ?array {
    return db_one("SELECT * FROM itp_contacts WHERE id = ?", [$id]);
}

function itPortalContactSave(array $in, int $id = 0): int {
    $unit = trim((string)($in['unit'] ?? ''));
    if ($unit === '') throw new InvalidArgumentException('Podaj nazwę jednostki.');
    $data = [
        'unit'         => $unit,
        'contact_html' => itPortalSanitizeHtml((string)($in['contact_html'] ?? '')),
        'team'         => trim(str_replace("\r", '', (string)($in['team'] ?? ''))),
        'sort_order'   => (int)($in['sort_order'] ?? 0),
    ];
    if ($id > 0) { db_update('itp_contacts', $data, $id); return $id; }
    return db_insert('itp_contacts', $data);
}

function itPortalContactDelete(int $id): void {
    db_exec("DELETE FROM itp_contacts WHERE id = ?", [$id]);
}

/**
 * Wiersze „Etykieta | adres” (zespół w Kontakcie, linki w stopce) → [['label'=>…, 'href'=>…|null]].
 * Adres e-mail zamieniany na mailto:, numer telefonu na tel:.
 */
function itPortalLinkLines(string $text): array {
    $out = [];
    foreach (preg_split('/\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') continue;
        [$label, $target] = array_map('trim', array_pad(explode('|', $line, 2), 2, ''));
        $href = null;
        if (filter_var($target, FILTER_VALIDATE_EMAIL)) $href = 'mailto:' . $target;
        elseif (preg_match('#^(https?://|/)#i', $target)) $href = $target;
        elseif (preg_match('/^\+?[\d\s-]{7,}$/', $target)) $href = 'tel:' . preg_replace('/[^\d+]/', '', $target);
        $out[] = ['label' => $label !== '' ? $label : $target, 'href' => $href];
    }
    return $out;
}

// ── Wyszukiwarka ────────────────────────────────────────────────────────────

/**
 * Szuka w artykułach (tytuł, treść, słowa kluczowe), usługach i komunikatach.
 * Wszystkie słowa zapytania muszą wystąpić (AND); tytuł waży więcej niż treść.
 * Dopasowanie w PHP (mb_strtolower), bo LIKE/LOWER w SQLite nie znają polskich liter;
 * słowo porównujemy po rdzeniu (bez 1–2 ostatnich liter), żeby „hasło” trafiało w „hasła”.
 * Treści portalu to setki, nie tysiące wierszy — pełny przegląd jest tani.
 * @return array{articles: array, categories: array, notices: array}
 */
function itPortalSearch(string $q, bool $log = true): array {
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
    $res = ['articles' => [], 'categories' => [], 'notices' => []];
    $words = [];
    foreach (explode(' ', mb_strtolower($q, 'UTF-8')) as $w) {
        $w = trim($w, " \t.,;:!?\"'()„”");
        $len = mb_strlen($w);
        if ($len < 2) continue;
        $words[] = $len >= 6 ? mb_substr($w, 0, $len - 2) : ($len >= 5 ? mb_substr($w, 0, $len - 1) : $w);
    }
    $words = array_slice(array_unique($words), 0, 6);
    if (!$words) return $res;

    // -1 = nie pasuje; inaczej punkty: słowo w tytule 10, w pozostałym tekście 1
    $score = function (string $title, string $rest) use ($words): int {
        $t = mb_strtolower($title, 'UTF-8'); $r = mb_strtolower($rest, 'UTF-8'); $s = 0;
        foreach ($words as $w) {
            if (str_contains($t, $w)) $s += 10; elseif (str_contains($r, $w)) $s += 1; else return -1;
        }
        return $s;
    };

    $rows = db_all("SELECT a.id, a.slug, a.title, a.body_plain, a.keywords, a.views, c.slug AS category_slug, c.name AS category_name
                    FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id
                    WHERE a.is_published = 1 AND c.is_active = 1");
    foreach ($rows as $r) {
        $s = $score($r['title'], $r['keywords'] . ' ' . $r['body_plain'] . ' ' . $r['category_name']);
        if ($s < 0) continue;
        $r['score'] = $s * 100000 + (int)$r['views'];
        $res['articles'][] = $r;
    }
    usort($res['articles'], fn($a, $b) => $b['score'] <=> $a['score']);
    $res['articles'] = array_slice($res['articles'], 0, 50);

    foreach (itPortalCategories() as $c) {
        if ($score($c['name'], $c['intro_plain']) >= 0) $res['categories'][] = $c;
    }
    foreach (itPortalNotices(200) as $n) {
        if ($score($n['title'], $n['body_plain']) >= 0) $res['notices'][] = $n;
        if (count($res['notices']) >= 20) break;
    }

    if ($log) {
        try {
            db_insert('itp_searches', ['q' => mb_substr($q, 0, 120), 'hits' => count($res['articles']) + count($res['categories']) + count($res['notices'])]);
        } catch (Throwable) { /* statystyka nie może psuć wyszukiwania */ }
    }
    return $res;
}

/** Najczęstsze zapytania z ostatnich $days dni (panel). */
function itPortalTopSearches(int $days = 30, int $limit = 15): array {
    return db_all("SELECT LOWER(q) AS q, COUNT(*) AS n, MIN(hits) AS min_hits FROM itp_searches
                   WHERE created_at >= datetime('now', ?) GROUP BY LOWER(q) ORDER BY n DESC LIMIT " . max(1, $limit),
                  ['-' . max(1, $days) . ' days']);
}

// ── Ikony (kreskowe SVG w stylu „szkicu”, kolor = currentColor) ────────────

function itPortalIconKeys(): array {
    return array_keys(itPortalIconPaths());
}

function itPortalIconLabels(): array {
    return [
        'user' => 'Osoba / login', 'mail' => 'Poczta', 'help' => 'Pomoc', 'wifi' => 'Internet / sieć', 'cloud' => 'Chmura',
        'service' => 'Servicedesk', 'monitor' => 'Komputer', 'graduation' => 'Nauka / szkolenia', 'document' => 'Dokument',
        'folder' => 'Teczka / EZD', 'globe' => 'Strony www / projekty', 'box' => 'Zakupy / sprzęt', 'key' => 'Hasła / dostęp',
        'shield' => 'Bezpieczeństwo', 'phone' => 'Telefon', 'printer' => 'Drukarka', 'server' => 'Serwer',
        'calendar' => 'Kalendarz', 'chat' => 'Komunikator', 'lock' => 'VPN',
    ];
}

function itPortalIconPaths(): array {
    return [
        'user'       => '<circle cx="24" cy="15" r="8"/><path d="M9 43c0-9 6.5-15 15-15s15 6 15 15"/><path d="M17 31c2 2 4.5 3 7 3s5-1 7-3"/>',
        'mail'       => '<rect x="6" y="11" width="36" height="26" rx="2"/><path d="M6.5 12.5 24 27l17.5-14.5"/><path d="M7 36l12-12M41 36 29 24"/>',
        'help'       => '<path d="M10 7h22l6 6v28H10z"/><path d="M18 18c0-4 2.5-6 6-6s6 2 6 5.5c0 4.5-6 4.5-6 9.5"/><circle cx="24" cy="33" r=".9"/>',
        'service'    => '<path d="M10 7h22l6 6v28H10z"/><path d="M18 18c0-4 2.5-6 6-6s6 2 6 5.5c0 4.5-6 4.5-6 9.5"/><circle cx="24" cy="33" r=".9"/><path d="M14 38h20"/>',
        'wifi'       => '<path d="M5 19c11-8 27-8 38 0"/><path d="M10 25c8-6 20-6 28 0"/><path d="M15 31c5-4 13-4 18 0"/><circle cx="24" cy="37" r="2"/>',
        'cloud'      => '<path d="M13 37h23a8 8 0 0 0 1-16 11 11 0 0 0-21-3 9 9 0 0 0-3 19z"/>',
        'monitor'    => '<rect x="6" y="8" width="36" height="25" rx="2"/><rect x="10" y="12" width="28" height="17"/><path d="M20 33l-2 7h12l-2-7M15 40h18"/>',
        'graduation' => '<path d="M3 19 24 10l21 9-21 9z"/><path d="M12 23v9c4 4 20 4 24 0v-9"/><path d="M44 19v11"/>',
        'document'   => '<path d="M13 5h15l8 8v30H13z"/><path d="M28 5v8h8"/><path d="M18 22h13M18 28h13M18 34h9"/>',
        'folder'     => '<path d="M4 14h14l3 4h23v22H4z"/><path d="M4 22h40"/>',
        'globe'      => '<circle cx="24" cy="24" r="17"/><ellipse cx="24" cy="24" rx="7" ry="17"/><path d="M7 24h34M10 15h28M10 33h28"/>',
        'box'        => '<path d="M6 15 24 7l18 8v19l-18 8-18-8z"/><path d="M6 15l18 8 18-8M24 23v19"/><path d="M15 11l18 8"/>',
        'key'        => '<circle cx="15" cy="30" r="8"/><path d="M21 24 40 6M33 13l4 4M29 17l3 3"/><circle cx="15" cy="30" r="2"/>',
        'shield'     => '<path d="M24 5 8 11v11c0 10 7 17 16 21 9-4 16-11 16-21V11z"/><path d="M17 24l5 5 10-10"/>',
        'phone'      => '<rect x="14" y="4" width="20" height="40" rx="3"/><path d="M14 10h20M14 37h20"/><circle cx="24" cy="40.5" r=".8"/>',
        'printer'    => '<path d="M14 18V6h20v12"/><rect x="6" y="18" width="36" height="16" rx="2"/><path d="M14 28h20v14H14z"/><path d="M18 33h12M18 37h8"/>',
        'server'     => '<rect x="8" y="6" width="32" height="10" rx="1.5"/><rect x="8" y="19" width="32" height="10" rx="1.5"/><rect x="8" y="32" width="32" height="10" rx="1.5"/><path d="M13 11h2M13 24h2M13 37h2M30 11h6M30 24h6M30 37h6"/>',
        'calendar'   => '<rect x="6" y="9" width="36" height="32" rx="2"/><path d="M6 18h36M15 5v8M33 5v8"/><path d="M13 25h4M22 25h4M31 25h4M13 33h4M22 33h4"/>',
        'chat'       => '<path d="M6 9h36v24H22l-9 7v-7H6z"/><path d="M13 18h22M13 25h14"/>',
        'lock'       => '<rect x="10" y="21" width="28" height="21" rx="2"/><path d="M16 21v-6a8 8 0 0 1 16 0v6"/><circle cx="24" cy="30" r="2.5"/><path d="M24 32.5V36"/>',
    ];
}

function itPortalIcon(string $key, string $class = ''): string {
    $p = itPortalIconPaths()[$key] ?? itPortalIconPaths()['help'];
    return '<svg class="' . h($class) . '" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.6" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

/** Sylwetki do „Dostępność usługi” (wypełnione). */
function itPortalAudienceIcon(string $key): string {
    $base = '<circle cx="24" cy="14" r="8"/><path d="M8 46c0-12 7-19 16-19s16 7 16 19z"/>';
    $extra = match ($key) {
        'student'   => '<path d="M10 7 24 2l14 5-14 5z"/><path d="M36 8v6"/>',
        'it'        => '<path d="M15 12h18" stroke="#fff" stroke-width="1.6"/><circle cx="20" cy="13" r="2.6" fill="none" stroke="#fff" stroke-width="1.4"/><circle cx="28" cy="13" r="2.6" fill="none" stroke="#fff" stroke-width="1.4"/>',
        'volunteer' => '<path d="M24 41l-5-5a3 3 0 0 1 5-4 3 3 0 0 1 5 4z" fill="#fff"/>',
        'manager'   => '<path d="M21 27h6l-1.5 3 2 11L24 44l-3.5-3 2-11z" fill="#fff"/>',
        'teacher'   => '<path d="M34 30l9-12" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/><rect x="16" y="34" width="16" height="5" fill="#fff"/>',
        'group'     => '<circle cx="9" cy="20" r="5"/><circle cx="39" cy="20" r="5"/><path d="M0 44c0-8 4-13 9-13s6 2 6 2M48 44c0-8-4-13-9-13s-6 2-6 2"/>',
        'guest'     => '<path d="M17 36h14" stroke="#fff" stroke-width="2" stroke-linecap="round"/><path d="M24 30v12" stroke="#fff" stroke-width="2" stroke-linecap="round"/>',
        default     => '<path d="M24 27l-3 4 3 13 3-13z" fill="#fff"/>',
    };
    return '<svg viewBox="0 0 48 48" fill="currentColor" aria-hidden="true" focusable="false">' . $base . $extra . '</svg>';
}
