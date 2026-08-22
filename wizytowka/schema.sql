-- =====================================================================
--  Wizytówka / Portfolio (Bento) — schemat bazy SQLite
--  Plik wykonywany JEDNORAZOWO przez instalator (/install).
--  Wszystkie zapytania są idempotentne (IF NOT EXISTS), więc ponowne
--  wykonanie schematu nie niszczy danych (przydatne przy aktualizacjach).
-- =====================================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------
-- Użytkownicy (administratorzy panelu)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    email          TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    password_hash  TEXT    NOT NULL,           -- password_hash(), PASSWORD_DEFAULT
    name           TEXT    NOT NULL DEFAULT '',
    role           TEXT    NOT NULL DEFAULT 'admin',
    is_active      INTEGER NOT NULL DEFAULT 1,
    last_login_at  TEXT,
    last_login_ip  TEXT,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------------
-- Ustawienia (klucz => wartość); jedno źródło prawdy dla konfiguracji
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------------
-- Kafelki Bento na stronie głównej
--   type: link | page | gallery | text | image | email | phone | map | embed
--   size: sm (1x1) | wide (2x1) | tall (1x2) | lg (2x2) | full (4x2)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bento_tiles (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    type        TEXT    NOT NULL DEFAULT 'link',
    size        TEXT    NOT NULL DEFAULT 'sm',
    title       TEXT    NOT NULL DEFAULT '',
    subtitle    TEXT    NOT NULL DEFAULT '',
    body        TEXT    NOT NULL DEFAULT '',   -- treść widżetu tekstowego / embed
    url         TEXT    NOT NULL DEFAULT '',   -- link zewnętrzny
    page_id     INTEGER,                       -- odnośnik do podstrony
    gallery_id  INTEGER,                       -- odnośnik do galerii
    icon        TEXT    NOT NULL DEFAULT '',   -- nazwa ikony SVG lub emoji
    image       TEXT    NOT NULL DEFAULT '',   -- plik tła (uploads/tiles/...)
    accent      TEXT    NOT NULL DEFAULT '',   -- kolor akcentu #RRGGBB
    open_blank  INTEGER NOT NULL DEFAULT 1,
    position    INTEGER NOT NULL DEFAULT 0,
    is_active   INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (page_id)    REFERENCES pages(id)     ON DELETE SET NULL,
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_tiles_order ON bento_tiles (is_active, position, id);

-- ---------------------------------------------------------------------
-- Podstrony (Pages Engine) — HTML lub Markdown + SEO
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pages (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    slug             TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    title            TEXT    NOT NULL,
    excerpt          TEXT    NOT NULL DEFAULT '',
    content          TEXT    NOT NULL DEFAULT '',
    format           TEXT    NOT NULL DEFAULT 'markdown',  -- markdown | html
    hero_image       TEXT    NOT NULL DEFAULT '',
    meta_title       TEXT    NOT NULL DEFAULT '',
    meta_description TEXT    NOT NULL DEFAULT '',
    meta_keywords    TEXT    NOT NULL DEFAULT '',
    noindex          INTEGER NOT NULL DEFAULT 0,
    is_published     INTEGER NOT NULL DEFAULT 1,
    position         INTEGER NOT NULL DEFAULT 0,
    views            INTEGER NOT NULL DEFAULT 0,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_pages_pub ON pages (is_published, position, id);

-- ---------------------------------------------------------------------
-- Galerie
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS galleries (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    slug             TEXT    NOT NULL UNIQUE COLLATE NOCASE,
    title            TEXT    NOT NULL,
    description      TEXT    NOT NULL DEFAULT '',
    cover_image_id   INTEGER,                        -- wybrana miniatura
    layout           TEXT    NOT NULL DEFAULT 'masonry', -- masonry | grid
    meta_title       TEXT    NOT NULL DEFAULT '',
    meta_description TEXT    NOT NULL DEFAULT '',
    is_published     INTEGER NOT NULL DEFAULT 1,
    position         INTEGER NOT NULL DEFAULT 0,
    views            INTEGER NOT NULL DEFAULT 0,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_galleries_pub ON galleries (is_published, position, id);

-- ---------------------------------------------------------------------
-- Zdjęcia w galeriach
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gallery_images (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    gallery_id  INTEGER NOT NULL,
    filename    TEXT    NOT NULL,               -- unikalna nazwa w uploads/gallery
    original    TEXT    NOT NULL DEFAULT '',    -- oryginalna nazwa pliku
    alt         TEXT    NOT NULL DEFAULT '',
    caption     TEXT    NOT NULL DEFAULT '',
    width       INTEGER NOT NULL DEFAULT 0,
    height      INTEGER NOT NULL DEFAULT 0,
    size_bytes  INTEGER NOT NULL DEFAULT 0,
    position    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_images_gallery ON gallery_images (gallery_id, position, id);
