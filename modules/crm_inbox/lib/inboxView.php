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
 * Zbija nadmiarowe odstępy w treści HTML wiadomości: serie <br>, puste akapity i puste <div> (typowe dla Worda/Outlooka —
 * „<p>&nbsp;</p>” po każdym wierszu) dawały w podglądzie wielocentymetrowe dziury. Zostaje najwyżej jedna pusta linia.
 */
function crmInboxCollapseBlank(string $html): string
{
    $empty = '(?:&nbsp;|&#160;|\xc2\xa0|<br\s*/?>|<span\b[^>]*>\s*</span>|\s)*';
    // kilka pustych bloków pod rząd → jeden
    $html = preg_replace('~(?:<(p|div)\b[^>]*>' . $empty . '</\1>\s*){2,}~iu', '<p class="sp">&nbsp;</p>', $html) ?? $html;
    // serie <br> (z białymi znakami pomiędzy) → najwyżej dwa
    $html = preg_replace('#(?:<br\s*/?>\s*){3,}#i', '<br><br>', $html) ?? $html;
    return $html;
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
        $content = crmInboxCollapseBlank(crmInboxFrameHtml($html));
    } elseif (trim($body) !== '') {
        // Zwykły tekst: akapity rozdzielone pustą linią → <p>, łamanie wierszy wewnątrz akapitu → <br>; serie pustych linii
        // zbijamy do jednej, cytaty „> ” wyszarzamy, adresy WWW zamieniamy na linki — w tekście nie da się ich kliknąć.
        $paras = preg_split('/(?:\r\n|\r|\n)[ \t\xc2\xa0]*(?:(?:\r\n|\r|\n)[ \t\xc2\xa0]*)+/u', trim($body)) ?: [];
        $out = [];
        foreach ($paras as $para) {
            $lines = [];
            foreach (preg_split('/\r\n|\r|\n/', rtrim($para)) as $ln) {
                $e = htmlspecialchars($ln, ENT_QUOTES, 'UTF-8');
                $e = preg_replace('~\bhttps?://[^\s<>"\']+~i', '<a href="$0">$0</a>', $e) ?? $e;
                $lines[] = str_starts_with(ltrim($ln), '>') ? '<span class="q">' . $e . '</span>' : $e;
            }
            $out[] = '<p>' . implode("<br>\n", $lines) . '</p>';
        }
        $content = '<div class="plain">' . implode("\n", $out) . '</div>';
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
        . 'body{margin:0;padding:18px 22px 26px;font:15px/1.5 "Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;'
        . 'color:#1d1d1d;background:#fff;overflow-wrap:anywhere;word-break:normal}'
        . 'img{max-width:100%;height:auto}table{max-width:100%!important}'
        . 'a{color:#005e5c}blockquote{margin:.6rem 0;padding:.2rem .9rem;border-left:3px solid #d0d0d0;color:#4a4a4a}'
        . 'p{margin:0 0 .7em}p.MsoNormal{margin:0 0 .5em}p:empty{display:none}p.sp{margin:0;height:.7em;line-height:0;overflow:hidden}.plain p:last-child{margin-bottom:0}pre{white-space:pre-wrap}.plain{white-space:normal}.q{color:#5f5f5f}.empty{color:#5f5f5f;font-style:italic}'
        . '</style></head><body>' . $content . '</body></html>';
}

/**
 * Czy w bazie jest najpewniej tylko skrót treści. Skaner zapisuje pełny HTML
 * wyłącznie przy włączonym poczta_store_html — inaczej zostaje bodyPreview
 * z Graph (≈255 znaków), czyli „kawałek treści, a pod nim pustka”.
 */
function crmInboxBodyIsPreview(array $msg): bool
{
    return trim((string)($msg['body_html'] ?? '')) === ''
        && !empty($msg['outlook_message_id']) && !empty($msg['mailbox_name'])
        && ($msg['direction'] ?? 'in') === 'in';
}

/**
 * Dociąga pełną treść wiadomości ze skrzynki (Graph) i zapisuje ją przy
 * wiadomości — kolejne otwarcia, lista i EZD mają już całość.
 *
 * @return array{ok:bool,error?:string}
 */
function crmInboxFetchFullBody(int $id): array
{
    $m = crm_mailbox_message($id);   // sprawdza też dostęp do skrzynki
    if (!$m) return ['ok' => false, 'error' => 'Nie znaleziono wiadomości.'];
    if (!crmInboxBodyIsPreview($m)) return ['ok' => true];
    try {
        require_once dirname(__DIR__, 3) . '/includes/m365.php';
        $b = (new M365Graph())->get_message_body((string)$m['mailbox_name'], (string)$m['outlook_message_id']);
    } catch (\Throwable $e) {
        error_log('[crm inbox fetch body] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Skrzynka pocztowa nie oddała treści (wiadomość usunięta albo brak uprawnienia Mail.Read).'];
    }
    $html = $b['type'] === 'html' ? $b['content'] : '';
    $text = $b['type'] === 'html' ? crm_mail_html_to_text($b['content']) : $b['content'];
    $norm = crm_mail_normalize($text, $html);
    if ($norm !== null) { $html = $norm['body_html']; $text = $norm['body']; }
    if ($html === '' && $text !== '') {
        $html = '<div style="white-space:pre-wrap">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    if ($html === '') return ['ok' => false, 'error' => 'Wiadomość w skrzynce jest pusta.'];
    db()->prepare("UPDATE crm_communications SET body=?, body_html=? WHERE id=?")
        ->execute([trim($text) ?: (string)$m['body'], $html, $id]);
    return ['ok' => true];
}
