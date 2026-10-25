<?php
/**
 * modules/helpdeskStatuses/lib/helpdeskStatuses.php — własne statusy (podstatusy) zależne od kategorii zgłoszenia.
 *
 * Status bazowy zgłoszenia (nowe / otwarte / oczekuje / rozwiązane / zamknięte …) zostaje — napędza SLA, liczniki, filtry,
 * powiadomienia. Podstatus to doprecyzowanie w ramach kategorii (np. dla wniosków AM: „Zatwierdzone — do realizacji”,
 * „Licencja przypisana”). Każdy podstatus jest mapowany na status bazowy: wybór podstatusu ustawia też ten status
 * (z powiadomieniami jak przy zwykłej zmianie). Zwykła zmiana statusu bazowego czyści podstatus.
 *
 * Definicje: słownik systemowy HD_SUBSTATUS_DICT (Słownik Centralny): kod wpisu, etykieta, kolejność oraz atrybuty
 *   base (kod statusu bazowego), categories (kody kategorii po przecinku; podkategorie dziedziczą), color (opcjonalnie #RRGGBB).
 * Zapas, gdy słownika brak: HD_SUBSTATUS_DEFAULTS.
 */
require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';

const HD_SUBSTATUS_DICT = 'helpdesk_podstatusy';

/** kod => [label, base, categories, color] — domyślne podstatusy (wnioski AM: AM5/AM7 i operacje AM11–24). */
const HD_SUBSTATUS_DEFAULTS = [
    'am_do_realizacji'  => ['label' => 'Zatwierdzone — do realizacji',        'base' => 'otwarte',    'categories' => 'it_licencje_am', 'color' => '#0f6cbd'],
    'am_konto'          => ['label' => 'Utworzono konto / zasób',              'base' => 'otwarte',    'categories' => 'it_licencje_am', 'color' => '#0f6cbd'],
    'am_licencja'       => ['label' => 'Licencja przypisana',                  'base' => 'otwarte',    'categories' => 'it_licencje_am', 'color' => '#0f6cbd'],
    'am_dane_przekazane'=> ['label' => 'Dane przekazane osobie zainteresowanej','base' => 'rozwiązane', 'categories' => 'it_licencje_am', 'color' => '#107c10'],
    'am_zwrot'          => ['label' => 'Zwrot licencji — zaplanowany',         'base' => 'oczekuje',   'categories' => 'it_licencje_am', 'color' => '#8a8886'],
    'am_zwrot_wykonany' => ['label' => 'Licencja odebrana / zablokowana',      'base' => 'zamknięte',  'categories' => 'it_licencje_am', 'color' => '#107c10'],
    'am_odrzucone'      => ['label' => 'Odrzucone',                            'base' => 'zamknięte',  'categories' => 'it_licencje_am', 'color' => '#a4262c'],
];

function hdSubstatusSeed(): string
{
    $entries = []; $i = 0;
    foreach (HD_SUBSTATUS_DEFAULTS as $code => $d)
        $entries[] = ['code' => $code, 'label' => $d['label'], 'parent_code' => null, 'sort_order' => (++$i) * 10, 'is_active' => true, 'description' => null,
                      'attributes' => ['base' => $d['base'], 'categories' => $d['categories'], 'color' => $d['color']]];
    return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => [
        ['code' => HD_SUBSTATUS_DICT, 'name' => 'Helpdesk — statusy własne kategorii', 'kind' => 'system', 'parent_code' => null, 'is_protected' => false, 'sort_order' => 71,
         'description' => 'Podstatusy zgłoszeń zależne od kategorii. Atrybuty: base = status bazowy, na który mapuje się podstatus (nowe, otwarte, oczekuje, krytyczne, przekazane_zewn, wymaga_prac, rozwiązane, zamknięte); categories = kody kategorii Helpdesku po przecinku (podkategorie dziedziczą); color = #RRGGBB (opcjonalnie). Kolejność wpisów = kolejność etapów na karcie zgłoszenia. Kody wpisów są zapisane w zgłoszeniach — nie zmieniaj ich po użyciu.',
         'entries' => $entries],
    ]], JSON_UNESCAPED_UNICODE);
}

/** Wszystkie aktywne podstatusy (kolejność słownika): kod => [label, base, categories[], color]. */
function hdSubstatuses(): array
{
    static $all = null;
    if ($all !== null) return $all;
    require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';
    $all = [];
    $src = [];
    foreach (dictItems(HD_SUBSTATUS_DICT) as $code => $it) {
        $a = (array)($it['attributes'] ?? []);
        $src[$code] = ['label' => (string)$it['label'], 'base' => (string)($a['base'] ?? ''), 'categories' => (string)($a['categories'] ?? ''), 'color' => (string)($a['color'] ?? '')];
    }
    if (!$src) $src = HD_SUBSTATUS_DEFAULTS;
    foreach ($src as $code => $d) {
        if (!isset(HD_STATUSES[$d['base']])) continue;                       // mapowanie na nieznany status bazowy pomijamy
        $all[$code] = ['label' => $d['label'], 'base' => $d['base'], 'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $d['color']) ? $d['color'] : '',
                       'categories' => array_values(array_filter(array_map('trim', explode(',', $d['categories']))))];
    }
    return $all;
}

/** Kody kategorii: sama kategoria i jej przodkowie (podkategorie dziedziczą podstatusy rodzica). */
function hdCategoryLineage(string $category): array
{
    require_once dirname(__DIR__, 3) . '/modules/helpdeskCategories/lib/helpdeskCategories.php';
    $items = hdCatItems(); $out = []; $c = $category; $g = 0;
    while ($c !== '' && $g++ < 10) { $out[] = $c; $c = $items[$c]['parent'] ?? ''; }
    return $out;
}

/** Podstatusy dostępne dla kategorii (w kolejności słownika). */
function hdSubstatusesFor(string $category): array
{
    $lin = hdCategoryLineage($category);
    return array_filter(hdSubstatuses(), fn($s) => (bool)array_intersect($s['categories'], $lin));
}

function hdSubstatusLabel(?string $code): string
{
    return ($code !== null && $code !== '') ? (hdSubstatuses()[$code]['label'] ?? $code) : '';
}

/**
 * Ustawia podstatus zgłoszenia (i status bazowy z mapowania). Zwraca komunikat błędu albo null.
 * $uid/$uname — kto zmienia (notatka wewnętrzna); powiadomienia o zmianie statusu bazowego jak zwykle.
 */
function hdSubstatusSet(int $ticketId, string $code, int $uid = 0, string $uname = ''): ?string
{
    $t = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
    if (!$t) return 'Nie znaleziono zgłoszenia.';
    $sub = hdSubstatusesFor((string)$t['category'])[$code] ?? null;
    if (!$sub) return 'Ten status nie jest dostępny dla kategorii zgłoszenia.';
    $now = date('Y-m-d H:i:s');
    $old = $t['status']; $new = $sub['base'];
    $upd = ['substatus' => $code, 'updated_at' => $now];
    if ($new !== $old) {
        $upd['status'] = $new;
        if ($new === 'rozwiązane') $upd['resolved_at'] = $now;
        if ($new === 'zamknięte') $upd['closed_at'] = $now;
    }
    db_update('helpdesk_tickets', $upd, $ticketId);
    db_insert('helpdesk_messages', ['ticket_id' => $ticketId, 'user_id' => $uid ?: null, 'user_name' => $uname !== '' ? $uname : 'System', 'is_internal' => 1,
        'body' => 'Status: ' . $sub['label'] . ($new !== $old ? ' (' . (HD_STATUSES[$new]['label'] ?? $new) . ')' : '')]);
    $GLOBALS['_hdAmLogged'] = true;   // zdarzenie dla wniosku AM zapisujemy raz — z nazwą podstatusu (nie ponownie w hd_notify_status_change)
    if ($new !== $old) {
        $fresh = db_one("SELECT * FROM helpdesk_tickets WHERE id=?", [$ticketId]);
        if ($fresh) hd_notify_status_change($fresh, $old, $new, '');   // czyści podstatus — ustawiamy go ponownie poniżej
        db_update('helpdesk_tickets', ['substatus' => $code], $ticketId);
    }
    $GLOBALS['_hdAmLogged'] = false;
    if (in_array('it_licencje_am', hdCategoryLineage((string)$t['category']), true)) {
        require_once dirname(__DIR__, 2) . '/helpdeskExtras/lib/helpdeskExtras.php';
        hdAmEvent($ticketId, $sub['label'] . ($new !== $old ? ' (' . (HD_STATUSES[$new]['label'] ?? $new) . ')' : ''), $uname);
    }
    return null;
}


// ── Zarządzanie podstatusami (Helpdesk › Administracja › Statusy kategorii) ───

/** Upewnia się, że słownik podstatusów istnieje (po wdrożeniu przed migracją jednorazową). */
function hdSubstatusEnsureDictionary(): void
{
    require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';
    try { dictService()->dictionary(HD_SUBSTATUS_DICT); return; } catch (\Throwable $e) {}
    dictTransfer()->import(hdSubstatusSeed(), 'json');
}

/** Wpisy do panelu: id, code, label, base, categories[], color, sort, active, version, used (liczba zgłoszeń). */
function hdSubstatusAdminList(): array
{
    hdSubstatusEnsureDictionary();
    $svc = dictService();
    $dict = $svc->dictionary(HD_SUBSTATUS_DICT);
    $out = [];
    foreach ($svc->entries($dict) as $e) {
        $a = $e->attributes;
        $out[] = ['id' => $e->id, 'code' => $e->code, 'label' => $e->label, 'base' => (string)($a['base'] ?? ''),
            'categories' => array_values(array_filter(array_map('trim', explode(',', (string)($a['categories'] ?? ''))))),
            'color' => (string)($a['color'] ?? ''), 'sort' => $e->sortOrder, 'active' => $e->isActive, 'version' => $e->version,
            'used' => (int)(db_one("SELECT COUNT(*) AS n FROM helpdesk_tickets WHERE substatus=?", [$e->code])['n'] ?? 0)];
    }
    usort($out, fn($x, $y) => [$x['sort'], $x['id']] <=> [$y['sort'], $y['id']]);
    return $out;
}

/** Zapis podstatusu (nowy: $id=0). Kod nowego wpisu powstaje z etykiety i nie zmienia się później. Zwraca listę błędów. */
function hdSubstatusAdminSave(int $id, array $in): array
{
    $e = [];
    $label = trim((string)($in['label'] ?? ''));
    if ($label === '') $e[] = 'Podaj nazwę statusu.';
    $base = (string)($in['base'] ?? '');
    if (!isset(HD_STATUSES[$base])) $e[] = 'Wybierz status bazowy.';
    $cats = array_values(array_filter((array)($in['categories'] ?? []), fn($c) => isset(hd_categories()[$c])));
    if (!$cats) $e[] = 'Wybierz co najmniej jedną kategorię.';
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($in['color'] ?? '')) ? (string)$in['color'] : '';
    if ($e) return $e;
    try {
        $svc = dictService();
        $existing = hdSubstatusAdminList();
        $attrs = ['base' => $base, 'categories' => implode(',', $cats), 'color' => $color];
        if ($id > 0) {
            $row = current(array_filter($existing, fn($x) => $x['id'] === $id));
            if (!$row) return ['Nie znaleziono statusu.'];
            $svc->updateEntry(HD_SUBSTATUS_DICT, $id, EntryInput::fromArray(['code' => $row['code'], 'label' => $label, 'attributes' => $attrs,
                'sort_order' => (int)($in['sort_order'] ?? $row['sort']), 'is_active' => $row['active'], 'version' => $row['version']]));
        } else {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(strtr(mb_strtolower($label), ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']))), '_');
            $code = 'st_' . substr($slug !== '' ? $slug : 'status', 0, 40);
            $codes = array_column($existing, 'code'); $n = 2; $base0 = $code;
            while (in_array($code, $codes, true)) $code = $base0 . '_' . $n++;
            $max = $existing ? max(array_column($existing, 'sort')) : 0;
            $svc->createEntry(HD_SUBSTATUS_DICT, EntryInput::fromArray(['code' => $code, 'label' => $label, 'attributes' => $attrs,
                'sort_order' => isset($in['sort_order']) && $in['sort_order'] !== '' ? (int)$in['sort_order'] : $max + 10, 'is_active' => true]));
        }
    } catch (DictionaryValidationException $ex) {
        return array_values($ex->errors);
    } catch (\Throwable $ex) {
        return ['Nie zapisano: ' . $ex->getMessage()];
    }
    return [];
}

/** Włącza / wyłącza podstatus (wyłączony znika z wyboru, ale zgłoszenia z nim nadal go wyświetlają). */
function hdSubstatusAdminToggle(int $id): ?string
{
    try {
        $svc = dictService();
        $row = current(array_filter(hdSubstatusAdminList(), fn($x) => $x['id'] === $id));
        if (!$row) return 'Nie znaleziono statusu.';
        $svc->updateEntry(HD_SUBSTATUS_DICT, $id, EntryInput::fromArray(['code' => $row['code'], 'label' => $row['label'],
            'attributes' => ['base' => $row['base'], 'categories' => implode(',', $row['categories']), 'color' => $row['color']],
            'sort_order' => $row['sort'], 'is_active' => !$row['active'], 'version' => $row['version']]));
    } catch (\Throwable $ex) {
        return 'Nie zapisano: ' . $ex->getMessage();
    }
    return null;
}
