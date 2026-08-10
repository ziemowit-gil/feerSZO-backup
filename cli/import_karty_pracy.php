<?php
/**
 * Import: Karty pracy → tabela content_articles
 * Wczytuje output/karty_pracy/articles.json i wstawia do SQLite.
 *
 * Użycie (ZAWSZE po scrape_karty_pracy.php):
 *   php cli/import_karty_pracy.php
 *
 * Tabela content_articles tworzona automatycznie jeśli nie istnieje.
 */

define('ROOT', __DIR__ . '/..');
define('JSON_FILE', ROOT . '/output/karty_pracy/articles.json');

require_once ROOT . '/includes/db.php';

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

$pdo->exec("
CREATE TABLE IF NOT EXISTS content_articles (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    category      TEXT    NOT NULL DEFAULT 'karty-pracy',
    slug          TEXT    NOT NULL UNIQUE,
    title         TEXT    NOT NULL,
    lead          TEXT,
    content_html  TEXT,
    cover_image   TEXT,
    author        TEXT,
    published_at  TEXT,
    source_url    TEXT,
    created_at    TEXT    DEFAULT (datetime('now')),
    updated_at    TEXT    DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS content_article_files (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    article_id INTEGER NOT NULL REFERENCES content_articles(id) ON DELETE CASCADE,
    label      TEXT,
    filename   TEXT    NOT NULL,
    url        TEXT,
    sort_order INTEGER DEFAULT 0
);
");

// ---------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------

if (!file_exists(JSON_FILE)) {
    die("Brak pliku: " . JSON_FILE . "\nUruchom najpierw: php cli/scrape_karty_pracy.php\n");
}

$articles = json_decode(file_get_contents(JSON_FILE), true);
if (!$articles) {
    die("Pusty lub uszkodzony JSON.\n");
}

$ins_art = $pdo->prepare("
    INSERT OR REPLACE INTO content_articles
        (category, slug, title, lead, content_html, cover_image, author, published_at, source_url, updated_at)
    VALUES
        ('karty-pracy', :slug, :title, :lead, :content_html, :cover_image, :author, :published_at, :source_url, datetime('now'))
");

$ins_file = $pdo->prepare("
    INSERT OR IGNORE INTO content_article_files (article_id, label, filename, url, sort_order)
    VALUES (:article_id, :label, :filename, :url, :sort_order)
");

$del_files = $pdo->prepare("DELETE FROM content_article_files WHERE article_id = :id");

$ok = 0;
foreach ($articles as $a) {
    $ins_art->execute([
        ':slug'         => $a['slug'],
        ':title'        => $a['title'],
        ':lead'         => $a['lead'],
        ':content_html' => $a['content_html'],
        ':cover_image'  => $a['cover_image'],
        ':author'       => $a['author'],
        ':published_at' => $a['date'],
        ':source_url'   => $a['source_url'],
    ]);
    $art_id = (int)$pdo->lastInsertId()
           ?: (int)$pdo->query("SELECT id FROM content_articles WHERE slug = " . $pdo->quote($a['slug']))->fetchColumn();

    $del_files->execute([':id' => $art_id]);

    foreach ($a['downloads'] as $i => $dl) {
        $ins_file->execute([
            ':article_id' => $art_id,
            ':label'      => $dl['label'],
            ':filename'   => $dl['filename'],
            ':url'        => $dl['url'],
            ':sort_order' => $i,
        ]);
    }

    echo "  ✓ [{$a['date']}] {$a['title']}\n";
    $ok++;
}

echo "\nZaimportowano: $ok artykułów.\n";
echo "Tabele: content_articles, content_article_files\n";
