<?php
/**
 * includes/mime_text.php — ratunek dla wiadomości, które trafiły do bazy jako
 * SUROWY MIME.
 *
 * Część nadawców (autorespondery, bramki helpdeskowe) dostarcza treść tak, że
 * zamiast tekstu zapisuje się cały multipart: granice `--boundary`, nagłówki
 * części i quoted-printable. Bez rozpakowania użytkownik widzi sieczkę.
 *
 * Plik jest CELOWO samodzielny (tylko funkcje, żadnych zależności), bo używa go
 * i warstwa pobierania poczty (includes/poczta.php), i Skrzynka CRM
 * (includes/crm_mailbox.php) — a te wymagają się nawzajem.
 */

/** Czy treść wygląda na nierozpakowany multipart MIME? */
function crm_mail_is_raw_mime(string $body): bool {
    if ($body === '' || !str_contains($body, 'Content-Type:')) return false;
    return (bool)preg_match('/^--\S{4,70}\s*$/m', $body)
        && (bool)preg_match('/Content-Type:\s*text\//i', $body);
}

/** Dekoduje jedną część wg Content-Transfer-Encoding i charsetu. */
function _crm_mime_decode_body(string $body, string $cte, string $charset): string {
    $out = match (strtolower(trim($cte))) {
        'quoted-printable' => quoted_printable_decode($body),
        'base64'           => (string)base64_decode($body, true),
        default            => $body,
    };
    $charset = strtolower(trim($charset)) ?: 'utf-8';
    if ($charset !== 'utf-8' && $charset !== 'us-ascii' && function_exists('mb_convert_encoding')) {
        $conv = @mb_convert_encoding($out, 'UTF-8', $charset);
        if ($conv !== false) $out = $conv;
    }
    return $out;
}

/**
 * Rozkłada surowy multipart na części tekstowe.
 *
 * @return array ['text' => string, 'html' => string]
 */
function crm_mail_decode_mime(string $raw, int $depth = 0): array {
    $res = ['text' => '', 'html' => ''];
    if ($depth > 3 || !preg_match('/^--(\S{4,70}?)(--)?\s*$/m', $raw, $m)) return $res;

    $boundary = $m[1];
    $parts = preg_split('/^--' . preg_quote($boundary, '/') . '(--)?[ \t]*\R?/m', $raw);
    if (!$parts) return $res;
    array_shift($parts);                       // preambuła przed pierwszą granicą

    foreach ($parts as $part) {
        $part = ltrim($part, "\r\n");
        if (trim($part) === '') continue;
        if (!preg_match('/\R[ \t]*\R/', $part, $mm, PREG_OFFSET_CAPTURE)) continue;

        $hdr  = substr($part, 0, $mm[0][1]);
        $body = substr($part, $mm[0][1] + strlen($mm[0][0]));

        preg_match('/Content-Type:\s*([^;\s]+)/i', $hdr, $ct);
        preg_match('/charset\s*=\s*"?([^";\s]+)/i', $hdr, $cs);
        preg_match('/Content-Transfer-Encoding:\s*(\S+)/i', $hdr, $cte);
        $type = strtolower($ct[1] ?? '');

        if (str_starts_with($type, 'multipart/')) {          // np. alternative w mixed
            $inner = crm_mail_decode_mime($part, $depth + 1);
            if ($res['text'] === '') $res['text'] = $inner['text'];
            if ($res['html'] === '') $res['html'] = $inner['html'];
            continue;
        }
        if ($type !== 'text/plain' && $type !== 'text/html') continue;

        $decoded = _crm_mime_decode_body($body, $cte[1] ?? '', $cs[1] ?? 'utf-8');
        if ($type === 'text/plain' && $res['text'] === '') $res['text'] = trim($decoded);
        if ($type === 'text/html'  && $res['html'] === '') $res['html'] = trim($decoded);
    }
    return $res;
}

/**
 * Zwraca czytelny TEKST wiadomości, jeśli w bazie siedzi surowy MIME.
 * null = treść jest w porządku, nie ma czego naprawiać.
 */
function crm_mail_plaintext(?string $body): ?string {
    $body = (string)$body;
    if (!crm_mail_is_raw_mime($body)) return null;

    $d    = crm_mail_decode_mime($body);
    $text = $d['text'] !== '' ? $d['text'] : crm_mail_html_to_text($d['html']);
    if ($text === '') return null;

    return trim((string)preg_replace("/\n{3,}/", "\n\n", $text));
}


/**
 * Naprawia parę (treść, treść HTML), jeśli w którymkolwiek polu siedzi surowy MIME.
 *
 * Zwraca ['body' => tekst, 'body_html' => HTML albo ''] — HTML zostaje, gdy
 * wiadomość faktycznie miała część text/html (wtedy jest po prostu czytelna),
 * a tekst zawsze, bo z niego robi się podgląd na liście i wersja dla EZD.
 *
 * null = nie ma czego naprawiać.
 */
function crm_mail_normalize(?string $body, ?string $body_html = null): ?array {
    $b  = (string)$body;
    $bh = (string)$body_html;

    $raw = crm_mail_is_raw_mime($bh) ? $bh : (crm_mail_is_raw_mime($b) ? $b : '');
    if ($raw === '') return null;

    $d    = crm_mail_decode_mime($raw);
    $html = $d['html'];
    $text = $d['text'];

    if ($text === '' && $html !== '') $text = crm_mail_html_to_text($html);
    if ($text === '' && $html === '') return null;

    return ['body' => $text, 'body_html' => $html];
}

/** HTML → czytelny tekst (podgląd na liście, wersja tekstowa wiadomości). */
function crm_mail_html_to_text(string $html): string {
    $t = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $t = preg_replace('#<br\s*/?>#i', "\n", (string)$t) ?? $t;
    $t = preg_replace('#</(p|div|li|tr|h[1-6])>#i', "\n", (string)$t) ?? $t;
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\xc2\xa0", ' ', $t);
    $t = preg_replace("/[ \t]+\n/", "\n", $t) ?? $t;
    $t = preg_replace("/\n{3,}/", "\n\n", (string)$t) ?? $t;
    return trim((string)$t);
}
