<?php
/**
 * modules/helpdeskCategories/lib/helpdeskCategories.php — kategorie Helpdesku jako drzewo ze Słownika Centralnego
 * i numeracja zgłoszeń wg wzorca „prefiks + rok + licznik”.
 *
 * Kategorie: słownik systemowy HD_CATEGORY_DICT (kategoria → podkategoria, dowolna głębokość). Wpis dziedziczy od rodzica
 * atrybuty `prefix` (2–5 wielkich liter) i `pattern` (wzorzec numeru). Brak słownika/wpisu → stała HD_CATEGORIES (płasko).
 * Numer: wzorzec ze znacznikami {PREFIX} {YYYY} {YY} {MM} {NNNNN} (liczba N = liczba cyfr licznika; licznik MUSI być na końcu),
 * domyślnie {PREFIX}-{YYYY}-{NNNNN} → np. INC-2026-00042. Licznik jest osobny dla każdej kombinacji prefiksu i okresu z wzorca
 * (zmiana roku zeruje licznik). Domyślny wzorzec zmienia ustawienie organizacji hd_number_pattern.
 */
const HD_NUMBER_DEFAULT_PATTERN = '{PREFIX}-{YYYY}-{NNNNN}';

/** Wpisy słownika kategorii: kod => [label, parent, prefix, pattern, path]. Pamięć procesu; fallback na stałe. */
function hdCatItems(): array
{
    static $items = null;
    if ($items !== null) return $items;
    require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';
    $items = [];
    $dict = dictItems(HD_CATEGORY_DICT);
    if ($dict) {
        foreach ($dict as $code => $it) {
            $a = (array)($it['attributes'] ?? []);
            $items[$code] = ['label' => (string)$it['label'], 'parent' => (string)($it['parent'] ?? ''),
                             'prefix' => strtoupper(trim((string)($a['prefix'] ?? ''))), 'pattern' => trim((string)($a['pattern'] ?? ''))];
        }
    }
    // kategorie ze stałej, których słownik jeszcze nie ma (np. nowa kategoria przed migracją słownika) — dopisujemy płasko
    foreach (HD_CATEGORIES as $code => $label) {
        if (!isset($items[$code])) $items[$code] = ['label' => $label, 'parent' => '', 'prefix' => HD_CATEGORY_PREFIXES[$code] ?? '', 'pattern' => ''];
    }
    foreach ($items as $code => &$it) {
        $path = [$it['label']]; $p = $it['parent']; $guard = 0;
        while ($p !== '' && isset($items[$p]) && $guard++ < 10) { array_unshift($path, $items[$p]['label']); $p = $items[$p]['parent']; }
        $it['path'] = implode(' › ', $path);
    }
    unset($it);
    return $items;
}

/** kod => etykieta ze ścieżką („Sprzęt IT › Laptop”), w kolejności drzewa. */
function hdCategories(): array
{
    return array_map(fn($i) => $i['path'], hdCatItems());
}

/** Atrybut kategorii z dziedziczeniem po rodzicach ('' gdy brak). */
function hdCategoryAttr(string $category, string $attr): string
{
    $items = hdCatItems();
    $code = $category; $guard = 0;
    while ($code !== '' && isset($items[$code]) && $guard++ < 10) {
        if ($items[$code][$attr] !== '') return $items[$code][$attr];
        $code = $items[$code]['parent'];
    }
    return '';
}

function hdNumberPatternValid(string $p): bool
{
    return (bool)preg_match('/^[A-Za-z0-9{}\/_.-]+$/', $p) && preg_match('/\{N{3,8}\}$/', $p) && substr_count($p, '{N') === 1
        && !preg_match_all('/\{(?!PREFIX\}|YYYY\}|YY\}|MM\}|N{3,8}\})/', $p);
}

function hdNumberPattern(?string $category = null): string
{
    $p = $category !== null ? hdCategoryAttr($category, 'pattern') : '';
    if ($p === '' || !hdNumberPatternValid($p)) $p = trim(org_setting('hd_number_pattern'));
    return hdNumberPatternValid($p) ? $p : HD_NUMBER_DEFAULT_PATTERN;
}

function hdNumberPrefix(string $category): string
{
    $p = hdCategoryAttr($category, 'prefix');
    if (preg_match('/^[A-Z]{2,5}$/', $p)) return $p;
    return HD_CATEGORY_PREFIXES[$category] ?? 'HD';
}

/** Głowa numeru (część przed licznikiem) i liczba cyfr licznika dla prefiksu, wzorca i daty. @return array{0:string,1:int} */
function hdNumberHead(string $prefix, ?string $pattern = null, ?int $ts = null): array
{
    $pattern = $pattern !== null && hdNumberPatternValid($pattern) ? $pattern : hdNumberPattern();
    $ts = $ts ?? time();
    preg_match('/\{(N+)\}$/', $pattern, $m);
    $head = strtr(substr($pattern, 0, -strlen($m[0])), ['{PREFIX}' => $prefix, '{YYYY}' => date('Y', $ts), '{YY}' => date('y', $ts), '{MM}' => date('m', $ts)]);
    return [$head, strlen($m[1])];
}

/** Największy użyty licznik wśród numerów zaczynających się od $head (same cyfry po głowie). */
function hdNumberMax(string $head): int
{
    $max = 0;
    foreach (db_all("SELECT number FROM helpdesk_tickets WHERE number LIKE ? ESCAPE '\\'", [str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $head) . '%']) as $r) {
        $rest = substr((string)$r['number'], strlen($head));
        if (strncmp((string)$r['number'], $head, strlen($head)) === 0 && ctype_digit($rest)) $max = max($max, (int)$rest);
    }
    return $max;
}

/** Następny numer: wzorzec z wypełnionymi znacznikami + kolejny licznik dla tego samego „nagłówka” (część przed licznikiem). */
function hdNextNumber(string $prefix, ?string $pattern = null, ?int $ts = null): string
{
    [$head, $digits] = hdNumberHead($prefix, $pattern, $ts);
    return $head . str_pad((string)(hdNumberMax($head) + 1), $digits, '0', STR_PAD_LEFT);
}

/** Wzorzec numeru zgłoszenia w tekście (temat maila): [INC-2026-00042] albo stare [HD00042]. */
function hdNumberRegex(): string
{
    return '/\[([A-Z]{2,5}(?:[-\/]?\d{2,4})?[-\/]?\d{3,8})\]/';
}
