<?php
/**
 * modules/helpdeskEmail/lib/helpdeskEmail.php — wspólny układ e-maili Helpdesku (spokojny, firmowy, zgodny z resztą interfejsu).
 *
 * hdMailLayout([...]) składa kompletny dokument HTML (tabele + style inline — działa w Outlooku, Gmailu, aplikacjach mobilnych):
 *   title      nagłówek wiadomości (wymagany)
 *   preheader  krótki podgląd w skrzynce (ukryty w treści)
 *   greeting   np. „Dzień dobry, Jan,” (opcjonalnie)
 *   intro      akapity HTML nad tabelą (opcjonalnie; wartości dynamiczne escapuje wołający)
 *   facts      [[etykieta, wartość | ['html' => …]], …] — tabela danych (Numer, Temat, Status…)
 *   quote      ['label' => autor, 'html' => treść] — cytowana wiadomość/notatka
 *   cta        ['label' => …, 'url' => …] — główny przycisk
 *   after      akapity HTML pod przyciskiem (np. link do podglądu bez logowania)
 *   footnote   dodatkowa uwaga w stopce (np. „Odpowiedz na tę wiadomość, zachowując numer w temacie”)
 *   accent     kolor akcentu (domyślnie niebieski #0f6cbd)
 *   org        nazwa organizacji w nagłówku i stopce (domyślnie ORG_NAME)
 * Zwraca string HTML. Wszystko poza polami '*html' jest escapowane.
 */
function hdMailLayout(array $o): string
{
    $org    = (string)($o['org'] ?? (defined('ORG_NAME') ? ORG_NAME : 'Helpdesk'));
    $accent = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($o['accent'] ?? '')) ? $o['accent'] : '#0f6cbd';
    $font   = "font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $rows = '';
    foreach ((array)($o['facts'] ?? []) as $i => $f) {
        $val = is_array($f[1]) ? (string)($f[1]['html'] ?? '') : $e($f[1]);
        if ($val === '') continue;
        $rows .= '<tr><td style="padding:8px 12px;width:130px;color:#605e5c;font-size:13px;border-top:1px solid #edebe9;vertical-align:top">' . $e($f[0])
              . '</td><td style="padding:8px 12px;color:#242424;font-size:14px;border-top:1px solid #edebe9">' . $val . '</td></tr>';
    }
    $facts = $rows !== '' ? '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;border-bottom:1px solid #edebe9;margin:18px 0">' . $rows . '</table>' : '';

    $quote = '';
    if (!empty($o['quote'])) {
        $quote = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:18px 0"><tr><td style="border-left:3px solid ' . $accent
            . ';background:#faf9f8;padding:12px 16px;color:#323130;font-size:14px;line-height:1.6">'
            . (!empty($o['quote']['label']) ? '<div style="font-size:12px;color:#605e5c;margin-bottom:6px">' . $e($o['quote']['label']) . '</div>' : '')
            . (string)($o['quote']['html'] ?? '') . '</td></tr></table>';
    }

    $cta = '';
    if (!empty($o['cta']['url'])) {
        $cta = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 6px"><tr><td bgcolor="' . $accent . '" style="background:' . $accent . ';border-radius:2px">'
            . '<a href="' . $e($o['cta']['url']) . '" style="display:inline-block;padding:11px 26px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;' . $font . '">'
            . $e($o['cta']['label'] ?? 'Otwórz') . '</a></td></tr></table>';
    }

    $pre = !empty($o['preheader'])
        ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f3f2f1;font-size:1px">' . $e($o['preheader']) . '</div>' : '';

    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($o['title'] ?? '') . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f3f2f1;' . $font . '">' . $pre
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" bgcolor="#f3f2f1" style="background:#f3f2f1"><tr><td align="center" style="padding:24px 12px">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e1dfdd;border-top:4px solid ' . $accent . '">'
        . '<tr><td style="padding:18px 28px 0;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#605e5c;' . $font . '">' . $e($org) . ' &middot; Helpdesk IT</td></tr>'
        . '<tr><td style="padding:6px 28px 0;font-size:21px;line-height:1.3;font-weight:600;color:#242424;' . $font . '">' . $e($o['title'] ?? '') . '</td></tr>'
        . '<tr><td style="padding:14px 28px 26px;font-size:14px;line-height:1.6;color:#323130;' . $font . '">'
        . (!empty($o['greeting']) ? '<p style="margin:0 0 10px">' . $e($o['greeting']) . '</p>' : '')
        . (string)($o['intro'] ?? '') . $facts . $quote . $cta . (string)($o['after'] ?? '')
        . '</td></tr>'
        . '<tr><td style="padding:14px 28px 20px;border-top:1px solid #edebe9;font-size:12px;line-height:1.5;color:#605e5c;' . $font . '">'
        . (!empty($o['footnote']) ? '<div style="margin-bottom:6px">' . (string)$o['footnote'] . '</div>' : '')
        . 'To automatyczna wiadomość systemu Helpdesk &middot; ' . $e($org) . '.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Akapit z tekstem użytkownika: escapowany, z zachowaniem podziałów linii. */
function hdMailText(string $text, int $max = 3000): string
{
    return nl2br(htmlspecialchars(mb_substr(trim(strip_tags($text)), 0, $max), ENT_QUOTES, 'UTF-8'));
}
