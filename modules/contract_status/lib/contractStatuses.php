<?php
/**
 * modules/contract_status/lib/contractStatuses.php — statusy umów (wszystkie typy) z jednego źródła:
 * słownika systemowego CONTRACT_STATUS_DICT w Słowniku Centralnym (Admin › Słownik Centralny › umowy_statusy).
 *
 * Wpis = status. Kod wpisu jest ASCII (wymóg słownika: `w_realizacji`), a wartość kolumny `status` w tabelach umowy_*
 * (z polskimi znakami i spacjami: `w realizacji`) leży w atrybucie `wartosc` — bez niego wartość = kod wpisu.
 * Wartości się nie zmieniają (są w danych i w regułach); całe API niżej przyjmuje i zwraca WARTOŚCI. Atrybuty wpisu:
 *   wartosc   — wartość zapisywana w kolumnie `status`,
 *   class     — klasa plakietki (Bootstrap: primary/secondary/success/info/warning/danger albo teal/orange/indigo/purple),
 *   color, bg — kolor (tekst, kafel; kontrast ≥ 4,5:1 z białym) i jasne tło (#RRGGBB),
 *   icon      — Bootstrap Icons (bi-…),
 *   grupa     — draft | active | blocked | closed | cancelled (macierz przejść ContractStatusTransitionValidator,
 *               „umowa aktywna” w licznikach, raportach i cronach),
 *   typy      — typy umów, w których status jest do wyboru (zlecenie, dzielo, uslugi, inne, wolontariat,
 *               szkolenie_ti, powierzenie, praca),
 *   next      — dozwolone przejścia dla edytora (admin: dowolny status typu),
 *   systemowy — nadaje go system, nie ma go na listach wyboru (aneks, zablokowana).
 * Stała CONTRACT_STATUS_DEFAULTS (etykiety/klasy z dawnej STATUS_LABELS) = zasiew słownika i zapas, gdy go brak.
 *
 * Zamiast list wpisanych na sztywno: contractStatusOptions($typ), contractStatusLabel(), contractStatusBadge(),
 * contractStatusesInGroup('active'), contractStatusSqlIn(['active']).
 */

const CONTRACT_STATUS_DICT = 'umowy_statusy';

/** Typy umów cywilnoprawnych — wspólny zestaw statusów. */
const CONTRACT_STATUS_CIVIL = ['zlecenie', 'dzielo', 'uslugi', 'inne', 'wolontariat', 'szkolenie_ti', 'szk'];

const CONTRACT_STATUS_GROUPS = [
    'draft'     => 'Robocza',
    'active'    => 'Aktywna',
    'blocked'   => 'Zablokowana',
    'closed'    => 'Zamknięta',
    'cancelled' => 'Anulowana / rozwiązana',
];

const CONTRACT_STATUS_DEFAULTS = [
    'projekt'                 => ['label' => 'Projekt',                 'class' => 'secondary', 'color' => '#4B5563', 'bg' => '#F3F4F6', 'icon' => 'bi-pencil-square',    'grupa' => 'draft',     'typy' => [...CONTRACT_STATUS_CIVIL, 'powierzenie'],
                                  'next' => ['do podpisu', 'jednostronnie', 'w podpisie w instytucji', 'podpisana', 'anulowana']],
    'do podpisu'              => ['label' => 'Do podpisu',              'class' => 'teal',      'color' => '#0F766E', 'bg' => '#F0FDFA', 'icon' => 'bi-pen',              'grupa' => 'draft',     'typy' => CONTRACT_STATUS_CIVIL,
                                  'next' => ['podpisana', 'anulowana']],
    'jednostronnie'           => ['label' => 'Podpisana jednostronnie', 'class' => 'teal',      'color' => '#0F766E', 'bg' => '#F0FDFA', 'icon' => 'bi-pen',              'grupa' => 'draft',     'typy' => ['powierzenie'],
                                  'next' => ['w podpisie w instytucji', 'podpisana', 'anulowana']],
    'w podpisie w instytucji' => ['label' => 'W podpisie w instytucji', 'class' => 'orange',    'color' => '#C2410C', 'bg' => '#FFF7ED', 'icon' => 'bi-building',         'grupa' => 'draft',     'typy' => ['powierzenie'],
                                  'next' => ['podpisana', 'anulowana']],
    'podpisana'               => ['label' => 'Podpisana',               'class' => 'primary',   'color' => '#1D4ED8', 'bg' => '#EFF6FF', 'icon' => 'bi-check2-square',    'grupa' => 'active',    'typy' => [...CONTRACT_STATUS_CIVIL, 'powierzenie'],
                                  'next' => ['w realizacji', 'zakończona', 'rozwiązana', 'anulowana']],
    'obowiązująca'            => ['label' => 'Obowiązująca',            'class' => 'success',   'color' => '#15803D', 'bg' => '#F0FDF4', 'icon' => 'bi-shield-check',     'grupa' => 'active',    'typy' => ['praca'],
                                  'next' => ['zakończona', 'rozwiązana', 'wygasła']],
    'w realizacji'            => ['label' => 'W realizacji',            'class' => 'info',      'color' => '#0369A1', 'bg' => '#F0F9FF', 'icon' => 'bi-play-circle',      'grupa' => 'active',    'typy' => [...CONTRACT_STATUS_CIVIL, 'powierzenie'],
                                  'next' => ['zawieszona', 'do rozliczenia', 'zakończona', 'rozwiązana']],
    // Zlecenie wielomiesięczne z niezamkniętym minionym miesiącem w saldzie — nadaje i zdejmuje system (zl_okres_sync()).
    'okres do rozliczenia'    => ['label' => 'Okres do rozliczenia',    'class' => 'orange',    'color' => '#A16207', 'bg' => '#FEFCE8', 'icon' => 'bi-calendar2-check', 'grupa' => 'active',    'typy' => ['zlecenie'],
                                  'next' => ['w realizacji', 'zawieszona', 'do rozliczenia', 'zakończona', 'rozwiązana'], 'systemowy' => true],
    'zawieszona'              => ['label' => 'Zawieszona',              'class' => 'orange',    'color' => '#C2410C', 'bg' => '#FFF7ED', 'icon' => 'bi-pause-circle',     'grupa' => 'active',    'typy' => CONTRACT_STATUS_CIVIL,
                                  'next' => ['w realizacji', 'do rozliczenia', 'zakończona', 'rozwiązana']],
    'do rozliczenia'          => ['label' => 'Do rozliczenia',          'class' => 'indigo',    'color' => '#4F46E5', 'bg' => '#EEF2FF', 'icon' => 'bi-calculator',       'grupa' => 'active',    'typy' => [...CONTRACT_STATUS_CIVIL, 'powierzenie'],
                                  'next' => ['zakończona', 'rozwiązana']],
    'zakończona'              => ['label' => 'Zakończona',              'class' => 'success',   'color' => '#15803D', 'bg' => '#F0FDF4', 'icon' => 'bi-flag',             'grupa' => 'closed',    'typy' => [...CONTRACT_STATUS_CIVIL, 'powierzenie'],
                                  'next' => []],
    'wygasła'                 => ['label' => 'Wygasła',                 'class' => 'secondary', 'color' => '#4B5563', 'bg' => '#F3F4F6', 'icon' => 'bi-hourglass-bottom', 'grupa' => 'closed',    'typy' => ['praca'],
                                  'next' => []],
    'rozwiązana'              => ['label' => 'Rozwiązana',              'class' => 'warning',   'color' => '#B45309', 'bg' => '#FFFBEB', 'icon' => 'bi-x-octagon',        'grupa' => 'cancelled', 'typy' => [...CONTRACT_STATUS_CIVIL, 'powierzenie', 'praca'],
                                  'next' => []],
    'anulowana'               => ['label' => 'Anulowana',               'class' => 'danger',    'color' => '#B91C1C', 'bg' => '#FEF2F2', 'icon' => 'bi-x-circle',         'grupa' => 'cancelled', 'typy' => [...CONTRACT_STATUS_CIVIL, 'powierzenie'],
                                  'next' => []],
    // Blokada jest systemowa (ContractMinorGuard::syncAfterSave) albo ręczna tylko dla admina.
    'zablokowana'             => ['label' => 'Zablokowana',             'class' => 'danger',    'color' => '#B91C1C', 'bg' => '#FEF2F2', 'icon' => 'bi-lock',             'grupa' => 'blocked',   'typy' => ['wolontariat'],
                                  'next' => [], 'systemowy' => true],
    'aneks'                   => ['label' => 'Aneks',                   'class' => 'purple',    'color' => '#7C3AED', 'bg' => '#F5F3FF', 'icon' => 'bi-files',            'grupa' => '',          'typy' => [],
                                  'next' => [], 'systemowy' => true],
];

/** Kod wpisu słownika dla wartości statusu: bez polskich znaków, spacje → _ (np. „w realizacji” → w_realizacji). */
function contractStatusSlug(string $value): string
{
    $v = strtr(mb_strtolower(trim($value)), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    return trim((string)preg_replace('/[^a-z0-9.-]+/', '_', $v), '_');
}

/** Typ „szk” (umowa szkoleniowa) dziedziczy statusy dawnego „szkolenie_ti” zapisane w słowniku. */
function _contractStatusTypesAlias(array $types): array
{
    if (in_array('szkolenie_ti', $types, true) && !in_array('szk', $types, true)) $types[] = 'szk';
    return $types;
}

function _contractStatusNorm(string $code, array $src, array $def): array
{
    $hex  = fn($v, $d) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{3,8}$/', $v) ? $v : $d;
    $list = fn($v, $d) => is_array($v) ? array_values(array_filter($v, 'is_string')) : (is_string($v) && $v !== '' ? array_map('trim', explode(',', $v)) : $d);
    $class = (string)($src['class'] ?? $def['class'] ?? 'secondary');
    $group = (string)($src['grupa'] ?? $def['grupa'] ?? 'active');
    return [
        'label'  => (string)($src['label'] ?? $def['label'] ?? $code),
        'class'  => preg_match('/^[a-z-]+$/', $class) ? $class : 'secondary',
        'color'  => $hex($src['color'] ?? null, $def['color'] ?? '#4B5563'),
        'bg'     => $hex($src['bg'] ?? null, $def['bg'] ?? '#F3F4F6'),
        'icon'   => is_string($src['icon'] ?? null) && preg_match('/^bi-[a-z0-9-]+$/', $src['icon']) ? $src['icon'] : ($def['icon'] ?? 'bi-circle'),
        'group'  => isset(CONTRACT_STATUS_GROUPS[$group]) ? $group : '',
        'types'  => _contractStatusTypesAlias($list($src['typy'] ?? null, $def['typy'] ?? [])),
        'next'   => $list($src['next'] ?? null, $def['next'] ?? []),
        'system' => (bool)($src['systemowy'] ?? $def['systemowy'] ?? false),
    ];
}

/** Wartości domyślne statusu — etykieta i klasa z dawnej stałej STATUS_LABELS, gdy jest załadowana. */
function _contractStatusDefault(string $code): array
{
    $d = CONTRACT_STATUS_DEFAULTS[$code] ?? [];
    if (defined('STATUS_LABELS') && isset(STATUS_LABELS[$code])) $d = ['label' => STATUS_LABELS[$code]['label'], 'class' => STATUS_LABELS[$code]['class']] + $d;
    return $d;
}

/**
 * Aktywne statusy ze słownika w jego kolejności (bez słownika — CONTRACT_STATUS_DEFAULTS).
 * @return array<string, array{label:string,class:string,color:string,bg:string,icon:string,group:string,types:list<string>,next:list<string>,system:bool}>
 */
function contractStatuses(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';
    $out = [];
    foreach (dictItems(CONTRACT_STATUS_DICT) as $entry => $it) {
        if (!empty($it['has_children'])) continue;
        $attr = (array)($it['attributes'] ?? []);
        $code = is_string($attr['wartosc'] ?? null) && trim($attr['wartosc']) !== '' ? trim($attr['wartosc']) : (string)$entry;
        $out[$code] = _contractStatusNorm($code, ['label' => $it['label']] + $attr, _contractStatusDefault($code));
    }
    if (!$out) foreach (array_keys(CONTRACT_STATUS_DEFAULTS) as $code) $out[$code] = _contractStatusNorm($code, [], _contractStatusDefault($code));
    return $cache = $out;
}

/** Konfiguracja dowolnej zapisanej wartości (także statusu wyłączonego w słowniku albo nieznanego). */
function contractStatusCfg(?string $code): array
{
    $code = (string)$code;
    $all = contractStatuses();
    if (isset($all[$code])) return $all[$code];
    // wyłączony w słowniku albo nieznany — etykieta wpisu (także z kosza), resztę bierzemy z wartości domyślnych
    $def = _contractStatusDefault($code);
    require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';
    $label = $code === '' ? '—' : dictLabel(CONTRACT_STATUS_DICT, contractStatusSlug($code), $def['label'] ?? $code);
    return _contractStatusNorm($code, ['label' => $label], $def + ['grupa' => '']);
}

function contractStatusLabel(?string $code): string
{
    return contractStatusCfg($code)['label'];
}

/** Grupa statusu: draft | active | blocked | closed | cancelled ('' — aneks / nieznany). */
function contractStatusGroup(?string $code): string
{
    return contractStatusCfg($code)['group'];
}

/** Hex koloru statusu (kafle, kanban, wykresy). */
function contractStatusColor(?string $code): string
{
    return contractStatusCfg($code)['color'];
}

/** Czy status istnieje (aktywny wpis słownika). $type — dodatkowo: czy jest dostępny w tym typie umowy. */
function contractStatusExists(?string $code, string $type = ''): bool
{
    $s = contractStatuses()[(string)$code] ?? null;
    if (!$s) return false;
    return $type === '' || in_array($type, $s['types'], true) || $s['system'];
}

/**
 * Statusy do wyboru w typie umowy: ['kod' => 'Etykieta'] (bez systemowych). Bieżąca wartość zawsze zostaje
 * na liście (także spoza zestawu typu), żeby edycja jej nie gubiła. $type '' = wszystkie typy.
 * $withSystem — także statusy nadawane przez system (do FILTRÓW list, nie do formularzy).
 * @return array<string,string>
 */
function contractStatusOptions(string $type = '', ?string $current = null, bool $withSystem = false): array
{
    $out = [];
    foreach (contractStatuses() as $code => $s) {
        if ($code === 'aneks' && !$withSystem) continue;
        $inType = $type === '' || in_array($type, $s['types'], true) || ($code === 'aneks' && $withSystem);
        if ($code === $current || ((!$s['system'] || $withSystem) && $inType)) $out[$code] = $s['label'];
    }
    if ($current !== null && $current !== '' && !isset($out[$current])) $out[$current] = contractStatusLabel($current);
    return $out;
}

/**
 * Statusy, na które można przejść z $current. Edytor: przejścia `next` dostępne w typie; admin: każdy status typu
 * (także systemowy „zablokowana”, bez aneksu). Twarde reguły grup sprawdza ContractStatusTransitionValidator.
 * @return list<string>
 */
function contractStatusNext(string $current, string $type = '', bool $isAdmin = false): array
{
    $all = contractStatuses();
    $inType = fn(string $c) => isset($all[$c]) && ($type === '' || in_array($type, $all[$c]['types'], true));
    if ($isAdmin) return array_values(array_filter(array_keys($all), fn($c) => $c !== 'aneks' && $inType($c)));
    return array_values(array_filter($all[$current]['next'] ?? (CONTRACT_STATUS_DEFAULTS[$current]['next'] ?? []), $inType));
}

/**
 * Kody statusów z podanych grup — także wyłączonych w słowniku (dane wciąż mogą je mieć).
 * @return list<string>
 */
function contractStatusesInGroup(string ...$groups): array
{
    $codes = [];
    foreach (contractStatuses() as $c => $s) if (in_array($s['group'], $groups, true)) $codes[] = $c;
    foreach (CONTRACT_STATUS_DEFAULTS as $c => $d) if (!in_array($c, $codes, true) && in_array(contractStatusGroup($c), $groups, true)) $codes[] = $c;
    return $codes;
}

/** Lista SQL kodów z grup: "'podpisana','w realizacji',…" (do wstawienia w IN (…)). */
function contractStatusSqlList(string ...$groups): string
{
    $codes = contractStatusesInGroup(...$groups) ?: ['-'];
    return implode(',', array_map(fn($c) => "'" . str_replace("'", "''", $c) . "'", $codes));
}

/** Warunek SQL: "{$col} IN ('podpisana','w realizacji',…)" dla grup statusów. */
function contractStatusSqlIn(array $groups, string $col = 'status'): string
{
    return $col . ' IN (' . contractStatusSqlList(...$groups) . ')';
}

/** Plakietka statusu — tło = kolor ze słownika (kontrast z białym tekstem ≥ 4,5:1). */
function contractStatusBadge(?string $code): string
{
    $s = contractStatusCfg($code);
    return '<span class="badge" style="background:' . $s['color'] . ';color:#fff">' . htmlspecialchars($s['label'], ENT_QUOTES) . '</span>';
}

/** Zasiew słownika (format importu Słownika Centralnego). */
function contractStatusesSeed(): string
{
    $entries = [];
    $i = 0;
    foreach (CONTRACT_STATUS_DEFAULTS as $code => $d) {
        $attr = ($code !== contractStatusSlug($code) ? ['wartosc' => $code] : []) + ['class' => $d['class'], 'color' => $d['color'], 'bg' => $d['bg'], 'icon' => $d['icon'], 'grupa' => $d['grupa'],
                 'typy' => array_values($d['typy']), 'next' => $d['next']];
        if (!empty($d['systemowy'])) $attr['systemowy'] = true;
        $entries[] = ['code' => contractStatusSlug($code), 'label' => $d['label'], 'parent_code' => null, 'sort_order' => (++$i) * 10, 'is_active' => true,
                      'description' => null, 'attributes' => $attr];
    }
    return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => [
        ['code' => CONTRACT_STATUS_DICT, 'name' => 'Umowy — statusy', 'kind' => 'system', 'parent_code' => null,
         'is_protected' => false, 'sort_order' => 60,
         'description' => 'Statusy umów wszystkich typów (listy wyboru, filtry, plakietki, kafle, kanban, liczniki aktywnych umów). '
            . 'Atrybut wartosc = wartość zapisana w umowach (z polskimi znakami i spacjami) — nie zmieniaj go; nowy status bez wartosc zapisuje się kodem wpisu. '
            . 'Zmieniaj etykiety, kolejność i atrybuty: class (plakietka: primary, secondary, success, info, warning, danger, teal, orange, indigo, purple), '
            . 'color i bg (#RRGGBB; color z białym tekstem musi mieć kontrast min. 4,5:1), icon (Bootstrap Icons, np. bi-flag), '
            . 'grupa (draft = robocza, active = aktywna, blocked = zablokowana, closed = zamknięta, cancelled = anulowana/rozwiązana), '
            . 'typy (lista typów umów, w których status jest do wyboru: zlecenie, dzielo, uslugi, inne, wolontariat, szkolenie_ti, powierzenie, praca), '
            . 'next (przejścia dla edytora), systemowy (true = nadaje go system, nie ma go na listach). Kody są zapisane w danych i używane w kodzie.',
         'entries' => $entries],
    ]], JSON_UNESCAPED_UNICODE);
}
