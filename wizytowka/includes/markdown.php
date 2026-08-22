<?php
/**
 * markdown.php — minimalny, bezpieczny parser Markdown (bez zależności).
 * Obsługuje: nagłówki, pogrubienie, kursywę, kod, linki, obrazy, listy,
 * cytaty, linie poziome, akapity. HTML wejściowy jest escapowany.
 */
declare(strict_types=1);

/** Konwersja Markdown → HTML (wejście traktowane jako niezaufane). */
function md_to_html(string $md): string
{
    $md   = str_replace(["\r\n", "\r"], "\n", $md);
    $html = '';
    $lines = explode("\n", $md);
    $inList = null;      // 'ul' | 'ol' | null
    $inQuote = false;
    $inCode = false;
    $para   = [];

    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html .= '<p>' . md_inline(implode(' ', $para)) . "</p>\n";
            $para = [];
        }
    };
    $closeList = function () use (&$inList, &$html) {
        if ($inList) { $html .= "</{$inList}>\n"; $inList = null; }
    };
    $closeQuote = function () use (&$inQuote, &$html) {
        if ($inQuote) { $html .= "</blockquote>\n"; $inQuote = false; }
    };

    foreach ($lines as $line) {
        // Blok kodu ```
        if (preg_match('~^```~', $line)) {
            $flushPara(); $closeList(); $closeQuote();
            $html  .= $inCode ? "</code></pre>\n" : '<pre><code>';
            $inCode = !$inCode;
            continue;
        }
        if ($inCode) { $html .= e($line) . "\n"; continue; }

        // Pusta linia = koniec akapitu/listy
        if (trim($line) === '') { $flushPara(); $closeList(); $closeQuote(); continue; }

        // Linia pozioma
        if (preg_match('~^\s*(-{3,}|\*{3,}|_{3,})\s*$~', $line)) {
            $flushPara(); $closeList(); $closeQuote();
            $html .= "<hr>\n";
            continue;
        }
        // Nagłówki
        if (preg_match('~^(#{1,6})\s+(.*)$~', $line, $m)) {
            $flushPara(); $closeList(); $closeQuote();
            $lvl   = strlen($m[1]);
            $html .= "<h{$lvl}>" . md_inline($m[2]) . "</h{$lvl}>\n";
            continue;
        }
        // Cytat
        if (preg_match('~^>\s?(.*)$~', $line, $m)) {
            $flushPara(); $closeList();
            if (!$inQuote) { $html .= "<blockquote>\n"; $inQuote = true; }
            $html .= '<p>' . md_inline($m[1]) . "</p>\n";
            continue;
        }
        // Lista punktowana
        if (preg_match('~^\s*[-*+]\s+(.*)$~', $line, $m)) {
            $flushPara(); $closeQuote();
            if ($inList !== 'ul') { $closeList(); $html .= "<ul>\n"; $inList = 'ul'; }
            $html .= '<li>' . md_inline($m[1]) . "</li>\n";
            continue;
        }
        // Lista numerowana
        if (preg_match('~^\s*\d+[.)]\s+(.*)$~', $line, $m)) {
            $flushPara(); $closeQuote();
            if ($inList !== 'ol') { $closeList(); $html .= "<ol>\n"; $inList = 'ol'; }
            $html .= '<li>' . md_inline($m[1]) . "</li>\n";
            continue;
        }
        // Zwykły tekst → akapit
        $closeList(); $closeQuote();
        $para[] = trim($line);
    }

    $flushPara(); $closeList(); $closeQuote();
    if ($inCode) $html .= "</code></pre>\n";

    return $html;
}

/** Formatowanie w linii (escape → znaczniki). */
function md_inline(string $text): string
{
    $t = e($text);

    // Kod `...`
    $t = preg_replace_callback('~`([^`]+)`~', fn($m) => '<code>' . $m[1] . '</code>', $t) ?? $t;

    // Obrazy ![alt](src)
    $t = preg_replace_callback(
        '~!\[([^\]]*)\]\(([^)\s]+)\)~',
        fn($m) => '<img src="' . md_safe_url($m[2]) . '" alt="' . $m[1] . '" loading="lazy">',
        $t
    ) ?? $t;

    // Linki [tekst](url)
    $t = preg_replace_callback(
        '~\[([^\]]+)\]\(([^)\s]+)\)~',
        function ($m) {
            $url  = md_safe_url($m[2]);
            $ext  = preg_match('~^https?://~i', $url) ? ' target="_blank" rel="noopener noreferrer"' : '';
            return '<a href="' . $url . '"' . $ext . '>' . $m[1] . '</a>';
        },
        $t
    ) ?? $t;

    // **bold**, *italic*, ~~strike~~
    $t = preg_replace('~\*\*(?=\S)(.+?)(?<=\S)\*\*~s', '<strong>$1</strong>', $t) ?? $t;
    $t = preg_replace('~(?<!\*)\*(?=\S)([^*]+?)(?<=\S)\*(?!\*)~s', '<em>$1</em>', $t) ?? $t;
    $t = preg_replace('#~~(?=\S)(.+?)(?<=\S)~~#s', '<s>$1</s>', $t) ?? $t;

    // Ręczne złamanie linii (dwa spacje na końcu)
    return str_replace("  \n", "<br>\n", $t);
}

/** Dopuszczamy tylko http(s), mailto, tel oraz ścieżki wewnętrzne. */
function md_safe_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if (preg_match('~^(https?://|mailto:|tel:|/|\.\./|\./|#)~i', $url)) {
        return e($url);
    }
    return '#';
}

/**
 * Sanityzacja HTML wpisanego przez administratora.
 * Panel jest zaufany, ale wycinamy skrypty/zdarzenia na wypadek pomyłki.
 */
function sanitize_html(string $html): string
{
    $html = preg_replace('~<\s*(script|iframe|object|embed|form)\b.*?<\s*/\s*\1\s*>~is', '', $html) ?? $html;
    $html = preg_replace('~<\s*(script|iframe|object|embed|form)\b[^>]*/?>~is', '', $html) ?? $html;
    $html = preg_replace('~\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $html) ?? $html;
    $html = preg_replace('~(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2~i', '$1="#"', $html) ?? $html;
    return $html;
}

/** Wyrenderuj treść podstrony zgodnie z jej formatem. */
function render_content(string $content, string $format): string
{
    return $format === 'html' ? sanitize_html($content) : md_to_html($content);
}
