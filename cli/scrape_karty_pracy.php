<?php
/**
 * Scraper: Karty pracy z feer-demo.2clicks.pl
 * Pobiera wszystkie artykuły z /aktualnosci/karty-pracy/ wraz z plikami PDF.
 *
 * Użycie:
 *   php cli/scrape_karty_pracy.php [--dry-run] [--no-files]
 *
 * Wynik:
 *   output/karty_pracy/articles.json  — dane do importu
 *   output/karty_pracy/files/         — pobrane PDF/DOC
 */

define('BASE_URL', 'https://feer-demo.2clicks.pl');
define('LIST_URL', BASE_URL . '/aktualnosci/karty-pracy/');
define('OUT_DIR',  __DIR__ . '/../output/karty_pracy');
define('FILES_DIR', OUT_DIR . '/files');

$dry_run   = in_array('--dry-run',  $argv ?? []);
$no_files  = in_array('--no-files', $argv ?? []);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function fetch(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; feerSZO-scraper/1.0)',
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false || $code >= 400) {
        throw new RuntimeException("HTTP $code for $url");
    }
    return $body;
}

function dom(string $html): DOMDocument {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING);
    libxml_clear_errors();
    return $doc;
}

function xpath(DOMDocument $doc, string $query, ?DOMNode $ctx = null): DOMNodeList {
    $xp = new DOMXPath($doc);
    return $xp->query($query, $ctx);
}

function text(DOMNodeList $list): string {
    return trim($list->item(0)?->textContent ?? '');
}

function attr(DOMNodeList $list, string $a): string {
    return trim($list->item(0)?->getAttribute($a) ?? '');
}

function inner_html(DOMNode $node): string {
    $html = '';
    foreach ($node->childNodes as $child) {
        $html .= $node->ownerDocument->saveHTML($child);
    }
    return trim($html);
}

/** Zmień datę dd-mm-yyyy / dd.mm.yyyy / ISO datetime → yyyy-mm-dd */
function parse_date(string $raw): string {
    $raw = trim($raw);
    // Obetnij czas jeśli jest (T lub spacja)
    $raw = preg_replace('/[T ](\d{2}:\d{2}(:\d{2})?).*/', '', $raw);
    // "28 - 04 - 2025" → "28-04-2025"
    $raw = preg_replace('/\s*-\s*/', '-', $raw);
    foreach (['d-m-Y', 'd.m.Y', 'Y-m-d'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt) return $dt->format('Y-m-d');
    }
    return $raw;
}

// ---------------------------------------------------------------------------
// 1. Zbierz linki z listingu (paginacja)
// ---------------------------------------------------------------------------

echo "=== Scraper: Karty pracy ===\n";

$article_urls = [];
$page = 1;

while (true) {
    $url  = LIST_URL . ($page > 1 ? "?page=$page" : '');
    echo "Listing: $url\n";
    $html = fetch($url);
    $doc  = dom($html);

    // Linki artykułów — <a href="/aktualnosci/karty-pracy/xxx.html">
    $links = xpath($doc, '//a[contains(@href, "/aktualnosci/karty-pracy/") and contains(@href, ".html")]');
    $found_this_page = [];
    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        if (str_ends_with($href, '/karty-pracy/')) continue;  // pomijaj link do kategorii
        $abs  = str_starts_with($href, 'http') ? $href : BASE_URL . $href;
        $found_this_page[$abs] = true;
    }

    $new = array_diff_key($found_this_page, $article_urls);
    if (empty($new)) {
        echo "  Brak nowych artykułów — koniec paginacji.\n";
        break;
    }
    $article_urls += $new;
    echo "  Znaleziono " . count($new) . " artykułów (łącznie: " . count($article_urls) . ")\n";

    // Sprawdź czy jest następna strona
    $next = xpath($doc, '//a[contains(@href,"page=' . ($page+1) . '")]');
    if ($next->length === 0) break;
    $page++;
}

echo "Razem artykułów: " . count($article_urls) . "\n\n";

// ---------------------------------------------------------------------------
// 2. Parsuj każdy artykuł
// ---------------------------------------------------------------------------

if (!$dry_run) {
    @mkdir(OUT_DIR,   0755, true);
    @mkdir(FILES_DIR, 0755, true);
}

$articles = [];

foreach (array_keys($article_urls) as $i => $url) {
    echo "[" . ($i+1) . "/" . count($article_urls) . "] $url\n";

    try {
        $html = fetch($url);
    } catch (RuntimeException $e) {
        echo "  BŁĄD: {$e->getMessage()}\n";
        continue;
    }

    $doc = dom($html);
    $xp  = new DOMXPath($doc);

    // --- Tytuł ---
    $title = text(xpath($doc, '//h1'));

    // --- Slug z URL ---
    preg_match('#/([^/]+)\.html$#', $url, $m);
    $slug = $m[1] ?? md5($url);

    // --- Data ---
    // Próbujemy <time>, <meta name="date">, tekst z klasy date/akt-date/itp.
    $date_raw = attr(xpath($doc, '//time'), 'datetime')
             ?: text(xpath($doc, '//time'))
             ?: text(xpath($doc, '//*[contains(@class,"date")]'))
             ?: text(xpath($doc, '//*[contains(@class,"data")]'));
    $date = parse_date($date_raw);

    // --- Autor ---
    $author = text(xpath($doc, '//*[contains(@class,"author")]'))
           ?: text(xpath($doc, '//*[contains(@class,"autor")]'));

    // --- Lead / excerpt ---
    $meta_desc = attr(xpath($doc, '//meta[@name="description"]'), 'content');
    $lead = $meta_desc ?: text(xpath($doc, '//article//p[1]'));

    // --- Cover image ---
    $og_img = attr(xpath($doc, '//meta[@property="og:image"]'), 'content');
    if (!$og_img) {
        $first_img = attr(xpath($doc, '//article//img[1]'), 'src');
        $og_img = $first_img ? (str_starts_with($first_img,'http') ? $first_img : BASE_URL.$first_img) : '';
    }

    // --- Treść HTML ---
    // Szukamy .wcag_content, article .content, .article-body itp.
    $content_html = '';
    foreach (['wcag_content','article-content','article-body','entry-content','akt-content','post-content'] as $cls) {
        $nodes = xpath($doc, "//*[contains(@class,'$cls')]");
        if ($nodes->length > 0) {
            $content_html = inner_html($nodes->item(0));
            break;
        }
    }
    if (!$content_html) {
        // Fallback: całe <article>
        $nodes = xpath($doc, '//article');
        if ($nodes->length > 0) {
            $content_html = inner_html($nodes->item(0));
        }
    }

    // --- Pliki do pobrania ---
    $downloads = [];
    $download_links = xpath($doc, '//a[contains(@href,"files/") or contains(@href,"download/") or contains(@href,".pdf") or contains(@href,".doc")]');
    foreach ($download_links as $link) {
        $href = $link->getAttribute('href');
        $abs  = str_starts_with($href,'http') ? $href : BASE_URL . '/' . ltrim($href, '/');
        $label = trim($link->textContent) ?: basename($href);
        $ext   = strtolower(pathinfo(parse_url($abs, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (!in_array($ext, ['pdf','doc','docx','ppt','pptx','xls','xlsx'])) continue;

        $local_name = $slug . '_' . basename(parse_url($abs, PHP_URL_PATH));
        $local_path = FILES_DIR . '/' . $local_name;

        $downloads[] = [
            'label'      => $label,
            'url'        => $abs,
            'filename'   => $local_name,
            'local_path' => 'output/karty_pracy/files/' . $local_name,
        ];

        // Pobierz plik
        if (!$dry_run && !$no_files && !file_exists($local_path)) {
            echo "  Pobieranie: $local_name …";
            try {
                $content = fetch($abs);
                file_put_contents($local_path, $content);
                echo " OK (" . number_format(strlen($content)/1024, 1) . " kB)\n";
            } catch (RuntimeException $e) {
                echo " BŁĄD: {$e->getMessage()}\n";
            }
        } elseif (file_exists($local_path)) {
            echo "  Plik istnieje: $local_name\n";
        }
    }

    $articles[] = [
        'title'        => $title,
        'slug'         => $slug,
        'date'         => $date,
        'author'       => $author,
        'lead'         => $lead,
        'cover_image'  => $og_img,
        'content_html' => $content_html,
        'downloads'    => $downloads,
        'source_url'   => $url,
    ];

    echo "  ✓ \"$title\" ($date)" . (count($downloads) ? " + " . count($downloads) . " plik(i)" : '') . "\n";
    usleep(300_000);  // 300ms pauzy między requestami
}

// ---------------------------------------------------------------------------
// 3. Zapis JSON
// ---------------------------------------------------------------------------

$json_path = OUT_DIR . '/articles.json';
if (!$dry_run) {
    file_put_contents($json_path, json_encode($articles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nZapisano: $json_path (" . count($articles) . " artykułów)\n";
} else {
    echo "\n[dry-run] Nie zapisano plików.\n";
    echo "Artykuły do pobrania:\n";
    foreach ($articles as $a) {
        echo "  - {$a['title']} ({$a['date']})\n";
    }
}

echo "\nGotowe!\n";
