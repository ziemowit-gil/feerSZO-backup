<?php
/**
 * modules/helpdeskEmail/lib/helpdeskEmail.php — wspólny układ e-maili Helpdesku (spokojny, firmowy, zgodny z resztą interfejsu).
 *
 * hdMailLayout([...]) składa prosty, kompletny dokument HTML bez ozdobników (styl inline — działa w Outlooku, Gmailu, aplikacjach mobilnych):
 *   title      nagłówek wiadomości (wymagany)
 *   preheader  krótki podgląd w skrzynce (ukryty w treści)
 *   greeting   np. „Dzień dobry, Jan,” (opcjonalnie)
 *   intro      akapity HTML nad tabelą (opcjonalnie; wartości dynamiczne escapuje wołający)
 *   facts      [[etykieta, wartość | ['html' => …]], …] — tabela danych (Numer, Temat, Status…)
 *   quote      ['label' => autor, 'html' => treść] — cytowana wiadomość/notatka
 *   cta        ['label' => …, 'url' => …] — zwykły link pod treścią
 *   after      akapity HTML pod przyciskiem (np. link do podglądu bez logowania)
 *   footnote   dodatkowa uwaga w stopce (np. „Odpowiedz na tę wiadomość, zachowując numer w temacie”)
 *   org        nazwa organizacji w nagłówku i stopce (domyślnie ORG_NAME)
 * Zwraca string HTML. Wszystko poza polami '*html' jest escapowane.
 */
function hdMailLayout(array $o): string
{
    $org  = (string)($o['org'] ?? (defined('ORG_NAME') ? ORG_NAME : 'Helpdesk'));
    $font = "font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // Dane: zwykłe wiersze „etykieta: wartość” (bez ramek i kolorów)
    $rows = '';
    foreach ((array)($o['facts'] ?? []) as $f) {
        $val = is_array($f[1]) ? (string)($f[1]['html'] ?? '') : $e($f[1]);
        if ($val === '') continue;
        $rows .= '<tr><td style="padding:2px 14px 2px 0;color:#555;vertical-align:top;white-space:nowrap">' . $e($f[0]) . '</td><td style="padding:2px 0">' . $val . '</td></tr>';
    }
    $facts = $rows !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0;font-size:14px">' . $rows . '</table>' : '';

    // Cytat: lewa kreska, bez tła
    $quote = '';
    if (!empty($o['quote'])) {
        $quote = '<div style="margin:12px 0;padding:2px 0 2px 12px;border-left:2px solid #bbb">'
            . (!empty($o['quote']['label']) ? '<div style="font-size:12px;color:#555;margin-bottom:4px">' . $e($o['quote']['label']) . '</div>' : '')
            . (string)($o['quote']['html'] ?? '') . '</div>';
    }

    // Link zamiast przycisku
    $cta = '';
    if (!empty($o['cta']['url'])) {
        $cta = '<p style="margin:14px 0"><a href="' . $e($o['cta']['url']) . '" style="color:#0f6cbd">' . $e($o['cta']['label'] ?? 'Otwórz') . '</a></p>';
    }

    $pre = !empty($o['preheader'])
        ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;color:#ffffff">' . $e($o['preheader']) . '</div>' : '';

    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($o['title'] ?? '') . '</title></head>'
        . '<body style="margin:0;padding:16px;background:#ffffff;color:#222;font-size:14px;line-height:1.55;' . $font . '">' . $pre
        . '<div style="max-width:600px">'
        . '<p style="margin:0 0 12px;font-size:16px;font-weight:600">' . $e($o['title'] ?? '') . '</p>'
        . (!empty($o['greeting']) ? '<p style="margin:0 0 10px">' . $e($o['greeting']) . '</p>' : '')
        . (string)($o['intro'] ?? '') . $facts . $quote . $cta . (string)($o['after'] ?? '')
        . '<hr style="border:0;border-top:1px solid #ddd;margin:20px 0 8px">'
        . '<div style="font-size:12px;color:#666">'
        . (!empty($o['footnote']) ? '<div style="margin-bottom:4px">' . (string)$o['footnote'] . '</div>' : '')
        . $e($org) . ' &middot; Helpdesk IT &middot; wiadomość automatyczna</div>'
        . '</div></body></html>';
}

/** HTML → tekst z zachowaniem akapitów, podziałów linii i punktów listy. */
function hdMailHtmlToText(string $html): string
{
    $html = preg_replace('~<(script|style)\b.*?</\1\s*>~is', '', $html);
    $t = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $html);
    $t = preg_replace('~<\s*li\b[^>]*>~i', "\n- ", $t);
    $t = preg_replace('~</\s*(p|div|li|ul|ol|h[1-6]|blockquote|tr|table)\s*>~i', "\n", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t]+\n/", "\n", str_replace("\r", '', $t));
    $t = preg_replace("/\n{2,}- /", "\n- ", $t);          // punkty listy bez pustych linii między nimi
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

/**
 * Treść wiadomości do e-maila: HTML z edytora zostaje czytelny (akapity, listy, pogrubienia, bezpieczne linki),
 * zwykły tekst dostaje łamanie linii; zbyt długie treści skracamy do tekstu. Wynik jest bezpiecznym HTML-em.
 */
function hdMailHtml(string $in, int $max = 3000): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    if (!preg_match('~<\s*/?\s*[a-z][^>]*>~i', $in)) {                     // zwykły tekst
        $txt = trim(str_replace("\r", '', $in));
        return nl2br($e(mb_substr($txt, 0, $max))) . (mb_strlen($txt) > $max ? '…' : '');
    }
    $plain = hdMailHtmlToText($in);
    if (mb_strlen($plain) > $max) return nl2br($e(mb_substr($plain, 0, $max))) . '…';
    $in = preg_replace('~<(script|style)\b.*?</\1\s*>~is', '', $in);
    $h = strip_tags($in, '<p><br><strong><b><em><i><u><ul><ol><li><a><blockquote>');
    $h = preg_replace('/&(?!#?\w+;)/', '&amp;', $h);                  // goły „&” jako encja
    // linki: tylko http(s)/mailto, bez pozostałych atrybutów
    $h = preg_replace_callback('~<a\b[^>]*>~i', function ($m) {
        if (preg_match('~href\s*=\s*(["\'])\s*((?:https?:|mailto:)[^"\']+)\1~i', $m[0], $mm)) {
            return '<a href="' . htmlspecialchars(html_entity_decode($mm[2], ENT_QUOTES | ENT_HTML5), ENT_QUOTES, 'UTF-8') . '" style="color:#0f6cbd">';
        }
        return '<a>';
    }, $h);
    $h = preg_replace('~<(strong|b|em|i|u|ul|ol|li|blockquote|br)\b[^>]*?(/?)>~i', '<$1$2>', $h);
    $h = preg_replace('~<p\b[^>]*>~i', '<p style="margin:0 0 10px">', $h);
    $h = preg_replace('~<(ul|ol)>~i', '<$1 style="margin:0 0 10px;padding-left:20px">', $h);
    $h = preg_replace('~<blockquote>~i', '<blockquote style="margin:0 0 10px;padding-left:12px;border-left:3px solid #c8c6c4;color:#605e5c">', $h);
    return trim($h);
}

/** Tekst użytkownika do wstawienia w e-mail (zachowuje akapity i formatowanie — zob. hdMailHtml). */
function hdMailText(string $text, int $max = 3000): string
{
    return hdMailHtml($text, $max);
}
