<?php
/**
 * includes/crm_email_render.php — renderer newsletterów: dokument bloków (JSON)
 * → HTML bezpieczny dla klientów pocztowych + wersja text/plain.
 *
 * DLACZEGO PO STRONIE SERWERA, A NIE W PRZEGLĄDARCE:
 * podgląd w edytorze i realna wysyłka MUSZĄ pochodzić z jednej implementacji.
 * Drugi renderer w JS rozjeżdża się z pierwszym po pierwszym refaktorze i wtedy
 * podgląd zaczyna kłamać. Edytor (crm/campaign/editor.php) woła więc render przez
 * XHR i wstawia zwrócony HTML do iframe — jedna prawda o wyglądzie wiadomości.
 *
 * ZASADY HTML-a, które ten plik realizuje (i po co):
 *   - layout tabelami role="presentation" — Outlook używa silnika Worda i nie zna
 *     flexa ani grida;
 *   - styl INLINE na elementach, <style> tylko na media queries — Gmail obcina
 *     <style> w części widoków;
 *   - mso-line-height-rule:exactly + line-height w px — Outlook inaczej liczy
 *     wysokość wiersza w jednostkach relatywnych;
 *   - ghost table <!--[if mso]> wokół kontenera — Outlook ignoruje max-width;
 *   - VML roundrect na przycisku — Outlook nie renderuje border-radius;
 *   - preheader jako ukryty div — kontrola nad tekstem podglądu w skrzynce;
 *   - obrazki: display:block + border:0 + width w atrybucie ORAZ w stylu.
 *
 * BEZPIECZEŃSTWO: treść pochodzi od użytkownika CRM, więc każdy tekst jest
 * escapowany, a URL-e przepuszczane przez białą listę schematów (http, https,
 * mailto, tel). Bogaty tekst przechodzi przez allowlistę tagów.
 *
 * Wymaga includes/db.php (dla crm_email_*_design) i config.php (APP_URL).
 */

require_once __DIR__ . '/crm_newsletter_schema.php';

// ─────────────────────────────────────────────────────────────────────────────
// Rejestr bloków — JEDNO źródło prawdy dla renderera i dla UI edytora.
// Edytor serializuje tę tablicę do JSON i z niej buduje paletę oraz inspektor,
// więc nowy typ bloku dodaje się w jednym miejscu.
// ─────────────────────────────────────────────────────────────────────────────

/** Domyślne style globalne wiadomości. */
function crm_email_default_settings(): array {
    return [
        'containerWidth'    => 600,
        'backgroundColor'   => '#F1F5F9',
        'contentBackground' => '#FFFFFF',
        'fontFamily'        => 'Helvetica Neue, Helvetica, Arial, sans-serif',
        'textColor'         => '#1F2937',
        'headingColor'      => '#111827',
        'linkColor'         => '#0176D3',
        'baseFontSize'      => 16,
        'borderRadius'      => 8,
    ];
}

/** Lista krojów bezpiecznych w poczcie (font web-safe, bez @font-face). */
function crm_email_font_stacks(): array {
    return [
        ['value' => 'Helvetica Neue, Helvetica, Arial, sans-serif', 'label' => 'Helvetica / Arial'],
        ['value' => 'Arial, Helvetica, sans-serif',                 'label' => 'Arial'],
        ['value' => 'Georgia, Times New Roman, serif',              'label' => 'Georgia (szeryfowy)'],
        ['value' => 'Verdana, Geneva, sans-serif',                  'label' => 'Verdana'],
        ['value' => 'Trebuchet MS, Tahoma, sans-serif',             'label' => 'Trebuchet MS'],
        ['value' => 'Tahoma, Verdana, sans-serif',                  'label' => 'Tahoma'],
    ];
}

/** Zestaw ikon społecznościowych dostępny lokalnie (bez zewnętrznego CDN-u). */
function crm_email_social_networks(): array {
    return [
        'fb'   => 'Facebook',
        'inst' => 'Instagram',
        'in'   => 'LinkedIn',
        'tw'   => 'X / Twitter',
        'you'  => 'YouTube',
        'tg'   => 'Telegram',
        'wa'   => 'WhatsApp',
        'web'  => 'Strona www',
    ];
}

/** Zmienne personalizacji dostępne w treści (spójne z CrmManager::renderTemplate). */
function crm_email_merge_tokens(): array {
    return [
        '{imie}'          => 'Imię odbiorcy',
        '{imie_nazwisko}' => 'Imię i nazwisko',
        '{email}'         => 'Adres e-mail',
        '{organizacja}'   => 'Organizacja',
        '{stanowisko}'    => 'Stanowisko',
        '{telefon}'       => 'Telefon',
        '{wojewodztwo}'   => 'Województwo',
        '{data}'          => 'Dzisiejsza data',
    ];
}

/**
 * Definicje bloków: domyślne wartości + opis pól dla inspektora.
 *
 * `locked` => true oznacza blok, którego nie da się usunąć z kanwy. Dotyczy
 * stopki, bo wysyłka masowa bez linku wypisania jest niedopuszczalna — lepiej
 * uniemożliwić usunięcie, niż wykrywać brak dopiero w walidacji przed wysyłką.
 */
function crm_email_block_defs(): array {
    $align = ['key' => 'align', 'label' => 'Wyrównanie', 'kind' => 'select', 'options' => [
        ['value' => 'left', 'label' => 'Lewo'], ['value' => 'center', 'label' => 'Środek'], ['value' => 'right', 'label' => 'Prawo'],
    ]];
    $pad = ['key' => 'paddingY', 'label' => 'Odstęp pionowy (px)', 'kind' => 'number', 'min' => 0, 'max' => 80];

    return [
        'heading' => [
            'label' => 'Nagłówek', 'icon' => 'bi-type-h1', 'group' => 'Treść',
            'defaults' => ['text' => 'Nowy nagłówek', 'level' => 2, 'align' => 'left', 'color' => '', 'paddingY' => 14],
            'fields' => [
                ['key' => 'text', 'label' => 'Treść', 'kind' => 'text'],
                ['key' => 'level', 'label' => 'Poziom', 'kind' => 'select', 'options' => [
                    ['value' => '1', 'label' => 'H1 — największy'], ['value' => '2', 'label' => 'H2'], ['value' => '3', 'label' => 'H3'],
                ]],
                $align,
                ['key' => 'color', 'label' => 'Kolor tekstu', 'kind' => 'color'],
                $pad,
            ],
        ],
        'text' => [
            'label' => 'Tekst', 'icon' => 'bi-text-paragraph', 'group' => 'Treść',
            'defaults' => ['html' => '<p>Wpisz treść wiadomości. Możesz użyć zmiennych, np. {imie} albo {organizacja}.</p>', 'align' => 'left', 'paddingY' => 12],
            'fields' => [
                ['key' => 'html', 'label' => 'Treść', 'kind' => 'richtext'],
                $align, $pad,
            ],
        ],
        'button' => [
            'label' => 'Przycisk CTA', 'icon' => 'bi-hand-index-thumb', 'group' => 'Treść',
            'defaults' => ['label' => 'Dowiedz się więcej', 'href' => 'https://', 'bg' => '#0176D3', 'color' => '#FFFFFF',
                           'align' => 'center', 'fullWidthMobile' => true, 'paddingY' => 18],
            'fields' => [
                ['key' => 'label', 'label' => 'Etykieta', 'kind' => 'text'],
                ['key' => 'href', 'label' => 'Adres URL', 'kind' => 'url'],
                ['key' => 'bg', 'label' => 'Tło przycisku', 'kind' => 'color'],
                ['key' => 'color', 'label' => 'Kolor tekstu', 'kind' => 'color'],
                $align,
                ['key' => 'fullWidthMobile', 'label' => 'Pełna szerokość na telefonie', 'kind' => 'toggle'],
                $pad,
            ],
        ],
        'image' => [
            'label' => 'Obrazek', 'icon' => 'bi-image', 'group' => 'Media',
            'defaults' => ['src' => '', 'alt' => '', 'width' => 0, 'align' => 'center', 'href' => '', 'paddingY' => 8],
            'fields' => [
                ['key' => 'src', 'label' => 'Plik lub adres URL', 'kind' => 'image'],
                ['key' => 'alt', 'label' => 'Tekst alternatywny', 'kind' => 'text',
                 'hint' => 'Wyświetlany, gdy klient poczty blokuje obrazki — a blokuje domyślnie.'],
                ['key' => 'width', 'label' => 'Szerokość w px (0 = pełna)', 'kind' => 'number', 'min' => 0, 'max' => 720],
                $align,
                ['key' => 'href', 'label' => 'Link po kliknięciu', 'kind' => 'url'],
                $pad,
            ],
        ],
        'product_grid' => [
            'label' => 'Siatka kart', 'icon' => 'bi-grid-3x2-gap', 'group' => 'Treść',
            'defaults' => ['columns' => 2, 'gap' => 16, 'paddingY' => 12, 'items' => [
                ['image' => '', 'title' => 'Pierwsza pozycja', 'desc' => 'Krótki opis', 'price' => '', 'href' => 'https://'],
                ['image' => '', 'title' => 'Druga pozycja',    'desc' => 'Krótki opis', 'price' => '', 'href' => 'https://'],
            ]],
            'fields' => [
                ['key' => 'columns', 'label' => 'Kolumny', 'kind' => 'select', 'options' => [
                    ['value' => '2', 'label' => '2 w rzędzie'], ['value' => '3', 'label' => '3 w rzędzie'],
                ]],
                ['key' => 'gap', 'label' => 'Odstęp między kartami (px)', 'kind' => 'number', 'min' => 0, 'max' => 40],
                ['key' => 'items', 'label' => 'Pozycje', 'kind' => 'items'],
                $pad,
            ],
        ],
        'social' => [
            'label' => 'Ikony społecznościowe', 'icon' => 'bi-share', 'group' => 'Treść',
            'defaults' => ['align' => 'center', 'size' => 32, 'style' => 'rdcol', 'paddingY' => 16, 'networks' => [
                ['name' => 'fb', 'url' => ''], ['name' => 'inst', 'url' => ''], ['name' => 'in', 'url' => ''],
            ]],
            'fields' => [
                ['key' => 'networks', 'label' => 'Serwisy', 'kind' => 'networks'],
                ['key' => 'style', 'label' => 'Styl ikon', 'kind' => 'select', 'options' => [
                    ['value' => 'rdcol', 'label' => 'Okrągłe, kolorowe'], ['value' => 'sqrdcol', 'label' => 'Kwadratowe, kolorowe'],
                    ['value' => 'black', 'label' => 'Czarne'], ['value' => 'bw', 'label' => 'Szare'],
                ]],
                ['key' => 'size', 'label' => 'Rozmiar (px)', 'kind' => 'number', 'min' => 16, 'max' => 48],
                $align, $pad,
            ],
        ],
        'divider' => [
            'label' => 'Separator', 'icon' => 'bi-dash-lg', 'group' => 'Struktura',
            'defaults' => ['color' => '#E5E7EB', 'thickness' => 1, 'paddingY' => 16],
            'fields' => [
                ['key' => 'color', 'label' => 'Kolor', 'kind' => 'color'],
                ['key' => 'thickness', 'label' => 'Grubość (px)', 'kind' => 'number', 'min' => 1, 'max' => 8],
                $pad,
            ],
        ],
        'spacer' => [
            'label' => 'Odstęp', 'icon' => 'bi-arrows-expand', 'group' => 'Struktura',
            'defaults' => ['height' => 24],
            'fields' => [['key' => 'height', 'label' => 'Wysokość (px)', 'kind' => 'number', 'min' => 4, 'max' => 120]],
        ],
        'footer' => [
            'label' => 'Stopka', 'icon' => 'bi-file-earmark-text', 'group' => 'Struktura', 'locked' => true,
            'defaults' => ['org' => '', 'address' => '', 'reason' => 'Otrzymujesz tę wiadomość, ponieważ zapisałaś/eś się do naszych powiadomień.',
                           'unsubscribeLabel' => 'Wypisz się', 'fontSize' => 12, 'color' => '#6B7280', 'paddingY' => 24],
            'fields' => [
                ['key' => 'org', 'label' => 'Nadawca', 'kind' => 'text'],
                ['key' => 'address', 'label' => 'Adres', 'kind' => 'textarea'],
                ['key' => 'reason', 'label' => 'Podstawa wysyłki', 'kind' => 'textarea',
                 'hint' => 'Odbiorca ma prawo wiedzieć, skąd mamy jego adres.'],
                ['key' => 'unsubscribeLabel', 'label' => 'Etykieta linku wypisania', 'kind' => 'text'],
                ['key' => 'color', 'label' => 'Kolor tekstu', 'kind' => 'color'],
                $pad,
            ],
        ],
    ];
}

/** Świeży, gotowy do edycji dokument startowy (nagłówek + tekst + CTA + stopka). */
function crm_email_starter_design(): array {
    $defs = crm_email_block_defs();
    $blk = function (string $type, array $override = []) use ($defs): array {
        return [
            'id'    => 'b' . bin2hex(random_bytes(4)),
            'type'  => $type,
            'props' => array_merge($defs[$type]['defaults'], $override),
        ];
    };
    $org = '';
    try { $org = (string)(db_one("SELECT value FROM settings WHERE key_='org_name'")['value'] ?? ''); } catch (\Throwable $e) {}

    return [
        'version'  => 2,
        'settings' => crm_email_default_settings(),
        'blocks'   => [
            $blk('heading', ['text' => 'Cześć {imie}!', 'level' => 1]),
            $blk('text'),
            $blk('button'),
            $blk('divider'),
            $blk('footer', ['org' => $org ?: 'Fundacja FEER']),
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Pomocniki bezpieczeństwa i stylu
// ─────────────────────────────────────────────────────────────────────────────

function _cem_esc(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Biała lista schematów URL. Wszystko inne → '#', żeby javascript:/data: nie
 * przeszło do wiadomości. Placeholdery systemowe ({unsubscribe_url}) przepuszczamy
 * dosłownie — podstawia je crm_email_personalize() już po renderze.
 */
function _cem_url(mixed $raw): string {
    $u = trim((string)($raw ?? ''));
    if ($u === '' || $u === 'https://' || $u === 'http://') return '';
    if (preg_match('/^\{[a-z_]+\}$/i', $u)) return $u;
    if (preg_match('~^(https?://|mailto:|tel:)~i', $u)) return _cem_esc($u);
    // Ścieżka względna od APP_URL (np. wgrany obrazek) — dopuszczalna.
    if (str_starts_with($u, '/')) return _cem_esc(rtrim(APP_URL, '/') . $u);
    return '#';
}

function _cem_px(mixed $n): string { return ((int)round((float)$n)) . 'px'; }

function _cem_styles(array $decls): string {
    $out = [];
    foreach ($decls as $k => $v) {
        if ($v === '' || $v === null) continue;
        $out[] = $k . ':' . $v;
    }
    return _cem_esc(implode(';', $out));
}

/** Kolor hex/rgb albo '' — nie wpuszczamy dowolnego stringu do atrybutu style. */
function _cem_color(mixed $v, string $fallback = ''): string {
    $v = trim((string)($v ?? ''));
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $v)) return $v;
    if (preg_match('/^rgba?\([\d\s.,%]+\)$/i', $v)) return $v;
    return $fallback;
}

/**
 * Bogaty tekst z edytora — allowlista tagów i atrybutów.
 *
 * Świadomie NIE używamy tu żadnej biblioteki: moduł nie ma zależności przez
 * Composera, a zakres dozwolonego HTML-a w newsletterze jest wąski (formatowanie
 * akapitu). Wszystko poza allowlistą jest usuwane wraz z treścią.
 */
function _cem_rich(mixed $html): string {
    $s = (string)($html ?? '');

    // 1) Kompletnie usuwamy elementy, które nie mają czego szukać w mailu.
    $s = preg_replace('~<\s*(script|style|iframe|object|embed|form|input|link|meta|base)\b[^>]*>.*?<\s*/\s*\1\s*>~is', '', $s);
    $s = preg_replace('~<\s*/?\s*(script|style|iframe|object|embed|form|input|link|meta|base)\b[^>]*>~i', '', $s);
    // 2) Komentarze (w tym warunkowe MSO — nie pozwalamy ich wstrzykiwać z treści).
    $s = preg_replace('/<!--.*?-->/s', '', $s);
    // 3) Handlery zdarzeń i style inline z treści.
    $s = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $s);
    // 4) Tagi poza allowlistą — zdejmujemy sam znacznik, treść zostaje.
    $allowed = 'p|br|strong|b|em|i|u|s|a|ul|ol|li|span|h1|h2|h3|h4|blockquote';
    $s = preg_replace('~<(?!/?(?:' . $allowed . ')\b)[^>]*>~i', '', $s);
    // 5) Atrybuty: na <a> zostaje wyłącznie bezpieczny href + target/rel.
    $s = preg_replace_callback('~<a\b[^>]*>~i', function (array $m): string {
        preg_match('~href\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $m[0], $h);
        $href = _cem_url($h[2] ?? $h[3] ?? $h[4] ?? '');
        return '<a href="' . ($href ?: '#') . '" target="_blank" rel="noopener">';
    }, $s);
    // 6) Pozostałe dozwolone tagi tracą wszystkie atrybuty (w tym class/style).
    $s = preg_replace('~<(' . str_replace('a|', '', $allowed) . ')\s+[^>]*>~i', '<$1>', $s);

    return $s;
}

/** Wiersz tabeli z paddingiem kontenera — wspólne opakowanie każdego bloku. */
function _cem_cell(array $o, string $inner): string {
    $s = $o['settings'];
    $padX = $o['paddingX'] ?? 28;
    $align = (string)($o['align'] ?? 'left');
    if (!in_array($align, ['left', 'center', 'right'], true)) $align = 'left';
    // data-cem-block istnieje TYLKO w podglądzie edytora (tryb editable) —
    // wysyłka nigdy nie widzi tego atrybutu.
    $mark = isset($o['block_id']) && $o['block_id'] !== ''
        ? ' data-cem-block="' . _cem_esc($o['block_id']) . '"' : '';
    return '<tr><td align="' . $align . '"' . $mark . ' class="px" style="' . _cem_styles([
        'padding'          => _cem_px($o['paddingY'] ?? 0) . ' ' . _cem_px($padX),
        'background-color' => _cem_color($s['contentBackground'], '#FFFFFF'),
    ]) . '">' . $inner . '</td></tr>';
}

// ─────────────────────────────────────────────────────────────────────────────
// Renderery bloków
// ─────────────────────────────────────────────────────────────────────────────

/** Renderuje pojedynczy blok do wiersza tabeli (<tr>). */
function crm_email_render_block(array $block, array $settings, array $opts = []): string {
    $type = (string)($block['type'] ?? '');
    $defs = crm_email_block_defs();
    if (!isset($defs[$type])) return '';
    $p = array_merge($defs[$type]['defaults'], (array)($block['props'] ?? []));
    $s = $settings;
    // Identyfikator wstrzykiwany do znacznika tylko w trybie kanwy edytora.
    $bid = !empty($opts['editable']) ? (string)($block['id'] ?? '') : '';

    switch ($type) {
        case 'heading': {
            $level = in_array((int)$p['level'], [1, 2, 3], true) ? (int)$p['level'] : 2;
            $size  = [1 => $s['baseFontSize'] * 2, 2 => (int)round($s['baseFontSize'] * 1.5), 3 => (int)round($s['baseFontSize'] * 1.2)][$level];
            $inner = '<h' . $level . ' style="' . _cem_styles([
                'margin'              => '0',
                'font-family'         => $s['fontFamily'],
                'font-size'           => _cem_px($size),
                'line-height'         => _cem_px($size * 1.28),
                'mso-line-height-rule' => 'exactly',
                'font-weight'         => '700',
                'color'               => _cem_color($p['color'], _cem_color($s['headingColor'] ?? '', '#111827')),
                'text-align'          => $p['align'],
            ]) . '">' . _cem_esc($p['text']) . '</h' . $level . '>';
            return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY'], 'align' => $p['align']], $inner);
        }

        case 'text': {
            $inner = '<div class="rich" style="' . _cem_styles([
                'font-family'          => $s['fontFamily'],
                'font-size'            => _cem_px($s['baseFontSize']),
                'line-height'          => _cem_px($s['baseFontSize'] * 1.6),
                'mso-line-height-rule' => 'exactly',
                'color'                => _cem_color($s['textColor'], '#1F2937'),
                'text-align'           => $p['align'],
            ]) . '">' . _cem_rich($p['html']) . '</div>';
            return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY']], $inner);
        }

        case 'button': {
            $href = _cem_url($p['href']) ?: '#';
            $bg   = _cem_color($p['bg'], '#0176D3');
            $fg   = _cem_color($p['color'], '#FFFFFF');
            $r    = (int)$s['borderRadius'];
            $arc  = max(0, min(50, (int)round($r / 44 * 100)));
            $cls  = !empty($p['fullWidthMobile']) ? 'btn-fluid' : '';
            // Outlook: VML roundrect (nie zna border-radius). Reszta klientów:
            // zwykły <a> z paddingiem, ukryty przed Outlookiem komentarzem downlevel.
            $inner = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . _cem_esc($p['align']) . '">'
                . '<tr><td align="center" style="' . _cem_styles(['border-radius' => _cem_px($r), 'background-color' => $bg]) . '">'
                . '<!--[if mso]>'
                . '<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"'
                . ' href="' . $href . '" style="height:44px;v-text-anchor:middle;width:260px" arcsize="' . $arc . '%"'
                . ' stroke="f" fillcolor="' . $bg . '"><w:anchorlock/>'
                . '<center style="color:' . $fg . ';font-family:Arial,sans-serif;font-size:16px;font-weight:bold">'
                . _cem_esc($p['label']) . '</center></v:roundrect>'
                . '<![endif]-->'
                . '<!--[if !mso]><!-- -->'
                . '<a class="' . $cls . '" href="' . $href . '" style="' . _cem_styles([
                    'display'          => 'inline-block',
                    'padding'          => '13px 30px',
                    'border-radius'    => _cem_px($r),
                    'background-color' => $bg,
                    'color'            => $fg,
                    'font-family'      => $s['fontFamily'],
                    'font-size'        => '16px',
                    'font-weight'      => '700',
                    'line-height'      => '18px',
                    'text-decoration'  => 'none',
                    'mso-padding-alt'  => '0',
                ]) . '">' . _cem_esc($p['label']) . '</a>'
                . '<!--<![endif]-->'
                . '</td></tr></table>';
            return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY'], 'align' => $p['align']], $inner);
        }

        case 'image': {
            $src = _cem_url($p['src']);
            $max = (int)$p['width'] > 0 ? (int)$p['width'] : ((int)$s['containerWidth'] - 56);
            if ($src === '') {
                // Placeholder tylko w podglądzie edytora; przy wysyłce blok bez
                // źródła jest pomijany (zob. crm_email_render(): ostrzeżenia).
                return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY'], 'align' => 'center'],
                    '<div style="border:1px dashed #CBD5E1;border-radius:6px;padding:28px;color:#94A3B8;'
                    . 'font-family:Arial,sans-serif;font-size:13px;text-align:center">Brak obrazka</div>');
            }
            $img = '<img src="' . $src . '" alt="' . _cem_esc($p['alt']) . '" width="' . $max . '" class="img-fluid" style="'
                . _cem_styles([
                    'display'                 => 'block',
                    'width'                   => '100%',
                    'max-width'               => _cem_px($max),
                    'height'                  => 'auto',
                    'border'                  => '0',
                    'outline'                 => 'none',
                    'text-decoration'         => 'none',
                    '-ms-interpolation-mode'  => 'bicubic',
                ]) . '">';
            $body = _cem_url($p['href']) ? '<a href="' . _cem_url($p['href']) . '" style="text-decoration:none">' . $img . '</a>' : $img;
            // Obrazek ma własne wyrównanie realizowane tabelą — inaczej align na
            // komórce nie działa na blokowym <img> w części klientów.
            $inner = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . _cem_esc($p['align'])
                . '" style="' . ($p['align'] === 'center' ? 'margin:0 auto' : '') . '"><tr><td>' . $body . '</td></tr></table>';
            return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY'], 'align' => $p['align']], $inner);
        }

        case 'product_grid': {
            $cols  = in_array((int)$p['columns'], [2, 3], true) ? (int)$p['columns'] : 2;
            $gap   = max(0, min(40, (int)$p['gap']));
            $items = array_values(array_filter((array)$p['items'], fn($i) => trim((string)($i['title'] ?? '')) !== '' || trim((string)($i['image'] ?? '')) !== ''));
            if (!$items) return '';
            $colW  = (int)floor(100 / $cols);
            $imgW  = (int)floor(((int)$s['containerWidth'] - 56 - $gap * ($cols - 1)) / $cols);

            $rows = '';
            foreach (array_chunk($items, $cols) as $chunk) {
                $cells = '';
                foreach ($chunk as $it) {
                    $href = _cem_url($it['href'] ?? '');
                    $open = $href ? '<a href="' . $href . '" style="text-decoration:none;color:' . _cem_color($s['textColor'], '#1F2937') . '">' : '<div>';
                    $close = $href ? '</a>' : '</div>';
                    $pic = _cem_url($it['image'] ?? '')
                        ? '<img src="' . _cem_url($it['image']) . '" alt="' . _cem_esc($it['title'] ?? '') . '" width="' . $imgW
                          . '" style="display:block;width:100%;max-width:' . $imgW . 'px;height:auto;border:0;border-radius:'
                          . _cem_px($s['borderRadius']) . '">'
                        : '';
                    $cells .= '<th class="col" width="' . $colW . '%" valign="top" style="'
                        . _cem_styles(['font-weight' => '400', 'text-align' => 'left', 'padding' => '0 ' . _cem_px($gap / 2)]) . '">'
                        . $open . $pic
                        . '<div style="' . _cem_styles([
                            'font-family' => $s['fontFamily'], 'font-size' => '15px', 'font-weight' => '600',
                            'line-height' => '20px', 'padding' => '10px 0 4px', 'color' => _cem_color($s['textColor'], '#1F2937'),
                          ]) . '">' . _cem_esc($it['title'] ?? '') . '</div>'
                        . (trim((string)($it['desc'] ?? '')) !== ''
                            ? '<div style="' . _cem_styles([
                                'font-family' => $s['fontFamily'], 'font-size' => '13px', 'line-height' => '19px',
                                'color' => '#6B7280', 'padding' => '0 0 4px',
                              ]) . '">' . _cem_esc($it['desc']) . '</div>' : '')
                        . (trim((string)($it['price'] ?? '')) !== ''
                            ? '<div style="' . _cem_styles([
                                'font-family' => $s['fontFamily'], 'font-size' => '14px', 'font-weight' => '700',
                                'color' => _cem_color($s['linkColor'], '#0176D3'),
                              ]) . '">' . _cem_esc($it['price']) . '</div>' : '')
                        . $close . '</th>';
                }
                // Dopełnienie rzędu pustymi komórkami — bez tego ostatni niepełny
                // rząd rozciąga karty na całą szerokość.
                for ($i = count($chunk); $i < $cols; $i++) {
                    $cells .= '<th class="col" width="' . $colW . '%">&nbsp;</th>';
                }
                $rows .= '<tr>' . $cells . '</tr>';
            }
            $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>';
            return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY']], $inner);
        }

        case 'social': {
            $known = crm_email_social_networks();
            $style = in_array((string)$p['style'], ['rdcol', 'sqrdcol', 'black', 'bw'], true) ? (string)$p['style'] : 'rdcol';
            $size  = max(16, min(48, (int)$p['size']));
            $base  = rtrim(APP_URL, '/') . '/assets/mosaico/templates/versafix-1/img/icons';
            $cells = '';
            foreach ((array)$p['networks'] as $n) {
                $code = (string)($n['name'] ?? '');
                $url  = _cem_url($n['url'] ?? '');
                if (!isset($known[$code]) || $url === '') continue;
                $cells .= '<td style="padding:0 5px"><a href="' . $url . '">'
                    . '<img src="' . _cem_esc("{$base}/{$code}-{$style}-96.png") . '" alt="' . _cem_esc($known[$code])
                    . '" width="' . $size . '" height="' . $size . '" style="display:block;border:0;width:' . $size . 'px;height:' . $size . 'px">'
                    . '</a></td>';
            }
            if ($cells === '') return '';
            $inner = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . _cem_esc($p['align'])
                . '" style="' . ($p['align'] === 'center' ? 'margin:0 auto' : '') . '"><tr>' . $cells . '</tr></table>';
            return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY'], 'align' => $p['align']], $inner);
        }

        case 'divider': {
            $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td style="' . _cem_styles([
                    'border-top' => _cem_px($p['thickness']) . ' solid ' . _cem_color($p['color'], '#E5E7EB'),
                    'font-size'  => '0', 'line-height' => '0',
                  ]) . '">&nbsp;</td></tr></table>';
            return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY']], $inner);
        }

        case 'spacer': {
            $hh = max(4, min(120, (int)$p['height']));
            $mark = $bid !== '' ? ' data-cem-block="' . _cem_esc($bid) . '"' : '';
            return '<tr><td height="' . $hh . '"' . $mark . ' style="height:' . $hh . 'px;font-size:0;line-height:0;background-color:'
                . _cem_color($s['contentBackground'], '#FFFFFF') . '">&nbsp;</td></tr>';
        }

        case 'footer': {
            $col = _cem_color($p['color'], '#6B7280');
            $fs  = max(10, min(16, (int)$p['fontSize']));
            $lnk = fn(string $u) => '<a href="' . $u . '" style="color:' . $col . ';text-decoration:underline">';
            $inner = '<div style="' . _cem_styles([
                'font-family'          => $s['fontFamily'],
                'font-size'            => _cem_px($fs),
                'line-height'          => _cem_px($fs * 1.6),
                'mso-line-height-rule' => 'exactly',
                'color'                => $col,
                'text-align'           => 'center',
            ]) . '">'
                . ($p['org'] !== '' ? '<strong>' . _cem_esc($p['org']) . '</strong>' : '')
                . ($p['address'] !== '' ? '<br>' . nl2br(_cem_esc($p['address'])) : '')
                . '<br><br>' . _cem_esc($p['reason'])
                . '<br>' . $lnk('{unsubscribe_url}') . _cem_esc($p['unsubscribeLabel']) . '</a>'
                . '</div>';
            return _cem_cell(['block_id' => $bid, 'settings' => $s, 'paddingY' => $p['paddingY']], $inner);
        }
    }
    return '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Render całego dokumentu
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Składa dokument bloków w kompletny e-mail.
 *
 * @param array $design ['version'=>int,'settings'=>array,'blocks'=>array]
 * @param array $opts   subject, preheader, tracking (bool — czy wstawić placeholder pikselu)
 * @return array{html:string,text:string,links:array<int,string>,warnings:array<int,string>}
 */
function crm_email_render(array $design, array $opts = []): array {
    $s = array_merge(crm_email_default_settings(), (array)($design['settings'] ?? []));
    $s['containerWidth'] = max(480, min(720, (int)$s['containerWidth']));
    $blocks = array_values((array)($design['blocks'] ?? []));

    $warnings = [];
    $links    = [];
    $has_footer = false;

    foreach ($blocks as $b) {
        $type = (string)($b['type'] ?? '');
        $p    = (array)($b['props'] ?? []);
        if ($type === 'footer') $has_footer = true;
        if ($type === 'image' && trim((string)($p['src'] ?? '')) === '') {
            $warnings[] = 'Blok „Obrazek" nie ma wskazanego pliku — w wysyłce będzie pusty.';
        }
        if ($type === 'image' && trim((string)($p['alt'] ?? '')) === '' && trim((string)($p['src'] ?? '')) !== '') {
            $warnings[] = 'Obrazek bez tekstu alternatywnego — odbiorcy z zablokowanymi obrazkami nie zobaczą nic.';
        }
        if ($type === 'button' && _cem_url($p['href'] ?? '') === '') {
            $warnings[] = 'Przycisk CTA bez adresu URL.';
        }
        // Zbiórka linków do trackingu (rejestrowane w crm_campaign_links).
        foreach (['href', 'url'] as $k) {
            $v = trim((string)($p[$k] ?? ''));
            if (preg_match('~^https?://~i', $v)) $links[$v] = true;
        }
        foreach ((array)($p['items'] ?? []) as $it) {
            $v = trim((string)($it['href'] ?? ''));
            if (preg_match('~^https?://~i', $v)) $links[$v] = true;
        }
        // Ikony społecznościowe: tylko znane serwisy — nieznane i tak nie trafiają
        // do HTML-a, więc rejestrowanie ich linków tworzyłoby martwe wiersze.
        $known_nets = crm_email_social_networks();
        foreach ((array)($p['networks'] ?? []) as $n) {
            $v = trim((string)($n['url'] ?? ''));
            if (isset($known_nets[(string)($n['name'] ?? '')]) && preg_match('~^https?://~i', $v)) $links[$v] = true;
        }
        // Linki wewnątrz bogatego tekstu.
        if ($type === 'text' && preg_match_all('~href\s*=\s*"(https?://[^"]+)"~i', (string)($p['html'] ?? ''), $m)) {
            foreach ($m[1] as $v) $links[html_entity_decode($v, ENT_QUOTES, 'UTF-8')] = true;
        }
    }

    if (!$has_footer) {
        $warnings[] = 'Brak bloku „Stopka" z linkiem wypisania — wysyłka masowa bez niego jest niedopuszczalna.';
    }

    $editable = !empty($opts['editable']);
    $body = '';
    foreach ($blocks as $b) $body .= crm_email_render_block($b, $s, ['editable' => $editable]) . "\n";

    $subject   = (string)($opts['subject'] ?? '');
    $preheader = (string)($opts['preheader'] ?? '');
    $bg        = _cem_color($s['backgroundColor'], '#F1F5F9');
    $cbg       = _cem_color($s['contentBackground'], '#FFFFFF');
    $width     = (int)$s['containerWidth'];
    $pixel     = !empty($opts['tracking']) ? '{tracking_pixel}' : '';

    // Zmienne wstawiane do szablonu dokumentu poniżej.
    $this_title = _cem_esc($subject);
    $link_color = _cem_color($s['linkColor'], '#0176D3');
    $radius     = (int)$s['borderRadius'];
    $pre_esc    = _cem_esc($preheader);
    // Wypełniacz po preheaderze: bez niego skrzynka dokleja do podglądu początek
    // treści. Niewidoczne znaki (soft hyphen + zero-width non-joiner) domykają
    // podgląd, nie dodając nic widocznego.
    $pre_pad    = $preheader !== '' ? str_repeat('&#847;&zwnj;&nbsp;', 40) : '';
    // Podświetlenia kanwy — obecne wyłącznie w trybie edytora.
    // touch-action:none i user-select:none są tu konieczne: przeciąganie bloku
    // zaczyna się od pointerdown na jego treści, a bez tego przeglądarka zaczyna
    // zaznaczać tekst (desktop) albo przewijać stronę (dotyk).
    $editor_css = $editable ? "  [data-cem-block]{outline:1px dashed transparent;transition:outline-color .1s;"
        . "cursor:grab;user-select:none;-webkit-user-select:none;touch-action:none}\n"
        . "  [data-cem-block] *{user-select:none;-webkit-user-select:none;-webkit-user-drag:none}\n"
        . "  [data-cem-block]:hover{outline-color:#93C5FD}\n"
        . "  [data-cem-block].cem-sel{outline:2px solid #0176D3 !important}\n"
        . "  [data-cem-block].cem-dragging{opacity:.45}\n" : '';

    $html = <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="pl">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="format-detection" content="telephone=no,address=no,email=no,date=no" />
<meta name="color-scheme" content="light dark" />
<meta name="supported-color-schemes" content="light dark" />
<title>{$this_title}</title>
<!--[if mso]>
<xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
<style>table,td,div,p,a,h1,h2,h3{font-family:Arial,Helvetica,sans-serif !important}</style>
<![endif]-->
<style type="text/css">
  body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}
  table,td{mso-table-lspace:0pt;mso-table-rspace:0pt}
  img{-ms-interpolation-mode:bicubic;border:0;height:auto;line-height:100%;outline:none;text-decoration:none}
  body{margin:0 !important;padding:0 !important;width:100% !important}
  .rich p{margin:0 0 12px}
  .rich p:last-child{margin-bottom:0}
  .rich ul,.rich ol{margin:0 0 12px;padding-left:22px}
  .rich a{color:{$link_color}}
  a[x-apple-data-detectors]{color:inherit !important;text-decoration:none !important}
  @media only screen and (max-width:620px){
    .wrapper{width:100% !important}
    .px{padding-left:18px !important;padding-right:18px !important}
    .col{display:block !important;width:100% !important;padding:0 0 22px !important}
    .btn-fluid{display:block !important;width:100% !important;text-align:center !important}
    .img-fluid{max-width:100% !important}
  }
{$editor_css}</style>
</head>
<body style="margin:0;padding:0;word-spacing:normal;background-color:{$bg}">
<div role="article" aria-roledescription="email" aria-label="{$this_title}" lang="pl" style="background-color:{$bg}">
  <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">{$pre_esc}{$pre_pad}</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{$bg}">
    <tr><td align="center" style="padding:26px 10px">
      <!--[if mso]><table role="presentation" width="{$width}" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
      <table role="presentation" class="wrapper" cellpadding="0" cellspacing="0" border="0" width="{$width}"
             style="width:{$width}px;max-width:100%;background-color:{$cbg};border-radius:{$radius}px">
{$body}
      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</div>
{$pixel}
</body>
</html>
HTML;

    return [
        'html'     => $html,
        'text'     => crm_email_plaintext($design, $s),
        'links'    => array_keys($links),
        'warnings' => array_values(array_unique($warnings)),
    ];
}

/**
 * Wersja text/plain — nie ozdoba, a warunek dostarczalności: filtry
 * antyspamowe punktują wiadomości wyłącznie HTML-owe.
 */
function crm_email_plaintext(array $design, array $settings = []): string {
    $out = [];
    foreach ((array)($design['blocks'] ?? []) as $b) {
        $type = (string)($b['type'] ?? '');
        $defs = crm_email_block_defs();
        if (!isset($defs[$type])) continue;
        $p = array_merge($defs[$type]['defaults'], (array)($b['props'] ?? []));

        switch ($type) {
            case 'heading':
                $t = trim((string)$p['text']);
                if ($t !== '') $out[] = $t . "\n" . str_repeat('=', min(60, mb_strlen($t)));
                break;
            case 'text':
                // Sanityzacja PRZED strip_tags: inaczej treść usuniętego <script>
                // wypada z tagów i ląduje w wersji tekstowej jako zwykły tekst.
                $t = _cem_rich($p['html']);
                $t = preg_replace('~<br\s*/?>~i', "\n", $t);
                $t = preg_replace('~</(p|div|li|h[1-4])>~i', "\n", $t);
                $t = preg_replace('~<li[^>]*>~i', '- ', $t);
                $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
                $t = trim(preg_replace("/\n{3,}/", "\n\n", $t));
                if ($t !== '') $out[] = $t;
                break;
            case 'button':
                if (trim((string)$p['label']) !== '') $out[] = $p['label'] . ': ' . $p['href'];
                break;
            case 'image':
                if (trim((string)$p['alt']) !== '') $out[] = '[' . $p['alt'] . ']';
                break;
            case 'product_grid':
                foreach ((array)$p['items'] as $it) {
                    if (trim((string)($it['title'] ?? '')) === '') continue;
                    $line = '- ' . $it['title'];
                    if (trim((string)($it['price'] ?? '')) !== '') $line .= ' — ' . $it['price'];
                    if (trim((string)($it['href'] ?? '')) !== '')  $line .= "\n  " . $it['href'];
                    $out[] = $line;
                }
                break;
            case 'social':
                $known = crm_email_social_networks();
                foreach ((array)$p['networks'] as $n) {
                    $code = (string)($n['name'] ?? '');
                    if (!isset($known[$code]) || trim((string)($n['url'] ?? '')) === '') continue;
                    $out[] = $known[$code] . ': ' . $n['url'];
                }
                break;
            case 'divider':
                $out[] = str_repeat('-', 40);
                break;
            case 'footer':
                $out[] = "---\n" . trim($p['org'] . "\n" . $p['address']) . "\n\n" . $p['reason']
                       . "\n" . $p['unsubscribeLabel'] . ': {unsubscribe_url}';
                break;
        }
    }
    return trim(implode("\n\n", $out));
}

// ─────────────────────────────────────────────────────────────────────────────
// Personalizacja i tracking (po renderze, per odbiorca)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Zmienne personalizacji dla kontaktu — ten sam zestaw co
 * CrmManager::renderTemplate(), żeby szablony tekstowe i newslettery blokowe
 * mówiły tym samym językiem.
 */
function crm_email_merge_data(array $contact): array {
    return [
        '{imie}'          => explode(' ', trim((string)($contact['imie_nazwisko'] ?? '')))[0] ?? '',
        '{imie_nazwisko}' => (string)($contact['imie_nazwisko'] ?? ''),
        '{email}'         => (string)($contact['email'] ?? ''),
        '{telefon}'       => (string)($contact['telefon'] ?? ''),
        '{organizacja}'   => (string)($contact['organizacja'] ?? ''),
        '{stanowisko}'    => (string)($contact['stanowisko'] ?? ''),
        '{wojewodztwo}'   => (string)($contact['wojewodztwo'] ?? ''),
        '{powiat}'        => (string)($contact['powiat'] ?? ''),
        '{gmina}'         => (string)($contact['gmina'] ?? ''),
        '{data}'          => date('d.m.Y'),
    ];
}

/**
 * Podstawia personalizację i tracking w gotowym HTML-u.
 *
 * Renderujemy RAZ na kampanię, a tutaj wykonujemy tanie podstawienie per odbiorca.
 * Wartości pól kontaktu są escapowane — dane kontaktu też są treścią od
 * użytkownika i nie mogą wstrzyknąć znaczników do wiadomości.
 *
 * @param array<string,int> $link_ids Mapa URL → crm_campaign_links.id
 */
function crm_email_personalize(string $html, array $contact, string $token, array $link_ids = [], bool $escape = true): string {
    $base = rtrim(APP_URL, '/');
    $t    = urlencode($token);

    // 1) Linki → przekierowanie po ID. URL docelowy nie wędruje w query stringu,
    //    więc /crm/track/click.php przestaje być otwartym przekierowaniem.
    if ($link_ids) {
        $html = preg_replace_callback('~href="(https?://[^"]+)"~i', function (array $m) use ($link_ids, $base, $t): string {
            $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $id  = $link_ids[$url] ?? null;
            return $id ? 'href="' . htmlspecialchars($base . '/crm/track/click.php?t=' . $t . '&l=' . (int)$id, ENT_QUOTES) . '"' : $m[0];
        }, $html);
    }

    // 2) Placeholdery systemowe.
    $sys = [
        '{unsubscribe_url}' => htmlspecialchars($base . '/crm/track/unsub.php?t=' . $t, ENT_QUOTES),
        '{tracking_pixel}'  => '<img src="' . htmlspecialchars($base . '/crm/track/open.php?t=' . $t, ENT_QUOTES)
                             . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;max-height:1px;max-width:1px">',
    ];
    $html = str_replace(array_keys($sys), array_values($sys), $html);

    // 3) Pola kontaktu.
    $vars = crm_email_merge_data($contact);
    if ($escape) {
        $vars = array_map(fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $vars);
    }
    return str_replace(array_keys($vars), array_values($vars), $html);
}

/** Ta sama personalizacja dla wersji tekstowej (bez escapowania, bez pikselu). */
function crm_email_personalize_text(string $text, array $contact, string $token): string {
    $base = rtrim(APP_URL, '/');
    $text = str_replace('{unsubscribe_url}', $base . '/crm/track/unsub.php?t=' . urlencode($token), $text);
    $vars = crm_email_merge_data($contact);
    return str_replace(array_keys($vars), array_values($vars), $text);
}

// ─────────────────────────────────────────────────────────────────────────────
// Dostęp do dokumentu
// ─────────────────────────────────────────────────────────────────────────────

/** Dekoduje design_json; null gdy brak/uszkodzony. */
function crm_email_design_decode(?string $json): ?array {
    if ($json === null || trim($json) === '') return null;
    $d = json_decode($json, true);
    if (!is_array($d) || !isset($d['blocks']) || !is_array($d['blocks'])) return null;
    return [
        'version'  => (int)($d['version'] ?? 2),
        'settings' => array_merge(crm_email_default_settings(), (array)($d['settings'] ?? [])),
        'blocks'   => array_values($d['blocks']),
    ];
}

/**
 * Normalizuje dokument przyjęty od edytora: wywala nieznane typy bloków,
 * przycina wartości do zakresów z definicji i porządkuje ID.
 * Nigdy nie zapisujemy do bazy tego, co przyszło z requestu, bez tego kroku.
 */
function crm_email_design_sanitize(array $in): array {
    $defs     = crm_email_block_defs();
    $settings = array_merge(crm_email_default_settings(), (array)($in['settings'] ?? []));

    // Style globalne — tylko znane klucze i bezpieczne wartości.
    $fonts = array_column(crm_email_font_stacks(), 'value');
    $settings['containerWidth'] = max(480, min(720, (int)$settings['containerWidth']));
    $settings['baseFontSize']   = max(12, min(20, (int)$settings['baseFontSize']));
    $settings['borderRadius']   = max(0, min(24, (int)$settings['borderRadius']));
    foreach (['backgroundColor', 'contentBackground', 'textColor', 'headingColor', 'linkColor'] as $k) {
        $settings[$k] = _cem_color($settings[$k], crm_email_default_settings()[$k] ?? '#000000');
    }
    if (!in_array($settings['fontFamily'], $fonts, true)) $settings['fontFamily'] = $fonts[0];
    $settings = array_intersect_key($settings, crm_email_default_settings());

    $blocks = [];
    foreach ((array)($in['blocks'] ?? []) as $b) {
        $type = (string)($b['type'] ?? '');
        if (!isset($defs[$type])) continue;
        $props = array_merge($defs[$type]['defaults'], array_intersect_key(
            (array)($b['props'] ?? []), $defs[$type]['defaults']
        ));
        // Liczby i wybory przycinamy do zadeklarowanych zakresów.
        foreach ($defs[$type]['fields'] as $f) {
            $k = $f['key'];
            if (!array_key_exists($k, $props)) continue;
            if ($f['kind'] === 'number') {
                $props[$k] = max((int)($f['min'] ?? 0), min((int)($f['max'] ?? 9999), (int)$props[$k]));
            } elseif ($f['kind'] === 'select') {
                $ok = array_column($f['options'], 'value');
                if (!in_array((string)$props[$k], $ok, true)) $props[$k] = $ok[0];
            } elseif ($f['kind'] === 'toggle') {
                $props[$k] = !empty($props[$k]);
            } elseif ($f['kind'] === 'color') {
                $props[$k] = _cem_color($props[$k], (string)($defs[$type]['defaults'][$k] ?? ''));
            }
        }
        if ($type === 'product_grid') {
            $props['items'] = array_slice(array_map(fn($i) => [
                'image' => (string)($i['image'] ?? ''), 'title' => (string)($i['title'] ?? ''),
                'desc'  => (string)($i['desc'] ?? ''),  'price' => (string)($i['price'] ?? ''),
                'href'  => (string)($i['href'] ?? ''),
            ], (array)($props['items'] ?? [])), 0, 12);
        }
        if ($type === 'social') {
            $known = crm_email_social_networks();
            $props['networks'] = array_values(array_filter(array_map(fn($n) => [
                'name' => (string)($n['name'] ?? ''), 'url' => (string)($n['url'] ?? ''),
            ], (array)($props['networks'] ?? [])), fn($n) => isset($known[$n['name']])));
        }
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($b['id'] ?? ''));
        $blocks[] = ['id' => $id !== '' ? $id : ('b' . bin2hex(random_bytes(4))), 'type' => $type, 'props' => $props];
    }

    return ['version' => 2, 'settings' => $settings, 'blocks' => $blocks];
}
