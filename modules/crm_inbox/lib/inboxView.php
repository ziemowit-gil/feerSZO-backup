<?php
/**
 * modules/crm_inbox/lib/inboxView.php — pomocnicze funkcje widoku Skrzynki CRM.
 *
 * Strona crm/inbox.php zostaje pod swoim adresem (zakładki, linki z e-maili,
 * licznik w menu CRM), a nowe kawałki widoku żyją tutaj:
 *   • crmInboxDayLabel()   — nagłówek grupy dat na liście,
 *   • crmInboxFrameDoc()   — pełny dokument HTML wiadomości do <iframe srcdoc>,
 *   • crmInboxHasRemote()  — czy treść ciągnie obrazy z zewnętrznych serwerów.
 *
 * Dlaczego iframe: newsletter przynosi własne <style>, tabele o stałej szerokości
 * i klasy kolidujące z CRM. Wklejony w stronę rozjeżdżał układ albo — po
 * wycięciu stylów — gubił połowę treści. W ramce sandbox (bez allow-scripts)
 * zachowuje wygląd nadawcy i nie może niczego wykonać.
 */

/** „Dziś”, „Wczoraj”, dzień tygodnia w bieżącym tygodniu, dalej pełna data. */
function crmInboxDayLabel(string $ts): string
{
    $t = strtotime($ts);
    if ($t === false) return '';
    $d     = date('Y-m-d', $t);
    $today = date('Y-m-d');
    if ($d === $today) return 'Dziś';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Wczoraj';

    $days   = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
    $months = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca',
               'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    $label = (int)date('j', $t) . ' ' . $months[(int)date('n', $t)];
    if (date('Y', $t) !== date('Y')) $label .= ' ' . date('Y', $t);

    // W ostatnim tygodniu dzień tygodnia mówi więcej niż sama data
    if ($t >= strtotime('-6 days', strtotime($today))) {
        return mb_convert_case($days[(int)date('w', $t)], MB_CASE_TITLE, 'UTF-8') . ', ' . $label;
    }
    return $label;
}

/** Czy HTML wiadomości odwołuje się do obrazów spoza wiadomości (http/https). */
function crmInboxHasRemote(string $html): bool
{
    return (bool)preg_match('~(?:src|background)\s*=\s*["\']?\s*(?:https?:)?//|url\(\s*["\']?\s*(?:https?:)?//~i', $html);
}

/**
 * HTML wiadomości oczyszczony do ramki. W odróżnieniu od crm_mail_safe_html()
 * zostawia <style> — w ramce style nadawcy nie dotkną CRM, a bez nich newsletter
 * wygląda na rozsypany. Wycinamy wszystko, co wykonywalne albo wychodzi poza
 * ramkę (skrypty, ramki, formularze, <base>, <meta refresh>, handlery on*).
 */
function crmInboxFrameHtml(string $html): string
{
    $s = $html;
    $s = preg_replace('#<(script|iframe|object|embed|form|noscript|template)\b[^>]*>.*?</\1\s*>#is', '', $s) ?? '';
    $s = preg_replace('#<(script|iframe|object|embed|form|link|meta|base|frame|frameset)\b[^>]*/?>#is', '', $s) ?? '';
    $s = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $s) ?? '';
    $s = preg_replace('#(href|src|action|background)\s*=\s*("|\')?\s*(javascript|vbscript|data:text/html)[^"\'\s>]*\2?#i', '$1="#"', $s) ?? '';
    // Szkielet dokumentu dokłada crmInboxFrameDoc() — zostawiamy sam <head>-owy
    // <style> i treść <body>, żeby nie było dwóch <html> w jednej ramce.
    if (preg_match('#<body\b[^>]*>(.*)</body>#is', $s, $m)) {
        preg_match_all('#<style\b[^>]*>.*?</style>#is', $s, $st);
        $s = implode("\n", $st[0] ?? []) . "\n" . $m[1];
    } else {
        $s = preg_replace('#</?(html|head|body)\b[^>]*>#i', '', $s) ?? '';
    }
    return $s;
}

/**
 * Pełny dokument do <iframe srcdoc> z treścią wiadomości.
 *
 * Obrazy z zewnątrz są domyślnie zablokowane polityką CSP (piksele śledzące
 * potwierdzają nadawcy, że ktoś otworzył list). JS strony podmienia znacznik
 * CRM_IMG_SRC na zezwolenie, gdy użytkownik kliknie „Pokaż obrazy”.
 */
function crmInboxFrameDoc(array $msg): string
{
    $html = trim((string)($msg['body_html'] ?? ''));
    $body = (string)($msg['body'] ?? '');
    if ($html === '' && crm_mail_looks_html($body)) $html = $body;

    if ($html !== '') {
        $content = crmInboxFrameHtml($html);
    } elseif (trim($body) !== '') {
        // Zwykły tekst: zachowujemy łamanie wierszy, cytaty „> ” wyszarzamy,
        // adresy WWW zamieniamy na linki — w tekście nie da się ich kliknąć.
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) as $ln) {
            $e = htmlspecialchars($ln, ENT_QUOTES, 'UTF-8');
            $e = preg_replace('~\bhttps?://[^\s<>"\']+~i', '<a href="$0">$0</a>', $e) ?? $e;
            $lines[] = str_starts_with(ltrim($ln), '>') ? '<span class="q">' . $e . '</span>' : $e;
        }
        $content = '<div class="plain">' . implode("<br>\n", $lines) . '</div>';
    } else {
        $content = '<p class="empty">Wiadomość bez treści.</p>';
    }

    $csp = "default-src 'none'; style-src 'unsafe-inline'; img-src data: cid: CRM_IMG_SRC; font-src data: CRM_IMG_SRC; media-src 'none'";
    return '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8">'
        . '<meta http-equiv="Content-Security-Policy" content="' . $csp . '">'
        . '<meta name="referrer" content="no-referrer">'
        . '<base target="_blank">'
        . '<style>'
        . 'html{color-scheme:light}'
        . 'body{margin:0;padding:18px 22px 26px;font:15px/1.6 "Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;'
        . 'color:#1d1d1d;background:#fff;overflow-wrap:anywhere;word-break:normal}'
        . 'img{max-width:100%;height:auto}table{max-width:100%!important}'
        . 'a{color:#005e5c}blockquote{margin:.6rem 0;padding:.2rem .9rem;border-left:3px solid #d0d0d0;color:#4a4a4a}'
        . 'pre{white-space:pre-wrap}.plain{white-space:normal}.q{color:#5f5f5f}.empty{color:#5f5f5f;font-style:italic}'
        . '</style></head><body>' . $content . '</body></html>';
}
