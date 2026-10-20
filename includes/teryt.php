<?php
/**
 * includes/teryt.php — województwa, powiaty i gminy (TERYT GUS) ze Słownika Centralnego.
 *
 * Słownik systemowy TERYT_DICT = drzewo: województwo (kod 2 cyfry, np. 12) → powiat (4 cyfry, 1206) → gmina
 * i jej części (7 znaków z rodzajem, 1206011). Etykieta = nazwa urzędowa (województwa małymi literami);
 * atrybuty: poziom (woj|pow|gmi), typ (np. „powiat”, „gmina wiejska”, „miasto na prawach powiatu”).
 * Edycja w Admin › Słownik Centralny; pełne dane z GUS: admin/teryt_import.php (importuje TERC i synchronizuje słownik).
 * Tabela teryt_units zostaje jako zapas — gdy słownika jeszcze nie ma, funkcje czytają z niej.
 *
 *   terytWojewodztwa()            ['12' => 'małopolskie', …]
 *   terytWojNames()               ['dolnośląskie', …] — dla pól zapisujących nazwę województwa
 *   terytChildren('12')           powiaty województwa; terytChildren('1206') — gminy powiatu
 *   terytSearch('krak', 3)        wyszukiwarka po nazwie na poziomie 1/2/3
 *   terytGmina('kod'|'nazwa', $v) gmina z powiatem i województwem
 */

require_once __DIR__ . '/db.php';

const TERYT_DICT = 'teryt';
const TERYT_LEVELS = [1 => 'woj', 2 => 'pow', 3 => 'gmi'];
const TERYT_WOJ_DEFAULT = [
    '02' => 'dolnośląskie', '04' => 'kujawsko-pomorskie', '06' => 'lubelskie', '08' => 'lubuskie',
    '10' => 'łódzkie', '12' => 'małopolskie', '14' => 'mazowieckie', '16' => 'opolskie',
    '18' => 'podkarpackie', '20' => 'podlaskie', '22' => 'pomorskie', '24' => 'śląskie',
    '26' => 'świętokrzyskie', '28' => 'warmińsko-mazurskie', '30' => 'wielkopolskie', '32' => 'zachodniopomorskie',
];

/**
 * Wszystkie jednostki: kod => [kod, nazwa, typ, poziom 1–3, rodzic].
 * Ze słownika (aktywne wpisy, z cache słownika); gdy pusty — z teryt_units; gdy i tej brak — 16 województw.
 * @return array<string, array{kod:string,nazwa:string,typ:string,poziom:int,rodzic:?string}>
 */
function terytUnits(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $out = [];
    require_once dirname(__DIR__) . '/modules/dictionary/lib/dictionaryApi.php';
    foreach (dictItems(TERYT_DICT) as $code => $it) {
        $a = (array)($it['attributes'] ?? []);
        $lvl = (int)(array_search($a['poziom'] ?? '', TERYT_LEVELS, true) ?: (strlen((string)$code) <= 2 ? 1 : (strlen((string)$code) <= 4 ? 2 : 3)));
        $out[(string)$code] = ['kod' => (string)$code, 'nazwa' => (string)$it['label'], 'typ' => (string)($a['typ'] ?? ''), 'poziom' => $lvl, 'rodzic' => $it['parent'] ?? null];
    }
    if (!$out) $out = _terytFromTable();
    if (!$out) foreach (TERYT_WOJ_DEFAULT as $k => $n) $out[$k] = ['kod' => $k, 'nazwa' => $n, 'typ' => 'województwo', 'poziom' => 1, 'rodzic' => null];
    return $cache = $out;
}

/** Jednostki z tabeli teryt_units (import GUS) — zapas i źródło zasiewu słownika. */
function _terytFromTable(): array {
    try {
        $rows = db_all("SELECT level, kod_woj, kod_pow, kod_gmi, nazwa, nazwa_typ FROM teryt_units ORDER BY level, kod_woj, kod_pow, kod_gmi, nazwa");
    } catch (\Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $lvl = (int)$r['level'];
        $kod = (string)($lvl === 1 ? $r['kod_woj'] : ($lvl === 2 ? $r['kod_pow'] : $r['kod_gmi']));
        if ($kod === '' || ($lvl > 1 && strlen($kod) !== ($lvl === 2 ? 4 : 7))) continue;
        // województwa są w tabeli dwa razy (seed „małopolskie” + import GUS „MAŁOPOLSKIE”) — zostaje pisownia małymi literami
        $nazwa = $lvl === 1 ? mb_strtolower((string)$r['nazwa']) : (string)$r['nazwa'];
        if (isset($out[$kod])) continue;
        $out[$kod] = ['kod' => $kod, 'nazwa' => $nazwa, 'typ' => (string)($r['nazwa_typ'] ?? ($lvl === 1 ? 'województwo' : '')),
                      'poziom' => $lvl, 'rodzic' => $lvl === 1 ? null : ($lvl === 2 ? (string)$r['kod_woj'] : (string)$r['kod_pow'])];
    }
    return $out;
}

/** @return array<string,string> kod => nazwa województwa (alfabetycznie) */
function terytWojewodztwa(): array {
    $w = [];
    foreach (terytUnits() as $k => $u) if ($u['poziom'] === 1) $w[$k] = $u['nazwa'];
    uasort($w, fn($a, $b) => _terytCollate($a) <=> _terytCollate($b));
    return $w ?: TERYT_WOJ_DEFAULT;
}

/** Nazwy województw — dla formularzy, które zapisują nazwę (np. „małopolskie”). @return list<string> */
function terytWojNames(): array {
    return array_values(terytWojewodztwa());
}

/** Jednostki podrzędne (powiaty województwa / gminy powiatu), alfabetycznie. @return list<array> */
function terytChildren(string $parent): array {
    $out = array_values(array_filter(terytUnits(), fn($u) => $u['rodzic'] === $parent));
    usort($out, fn($a, $b) => [_terytCollate($a['nazwa']), $a['kod']] <=> [_terytCollate($b['nazwa']), $b['kod']]);
    return $out;
}

/** Szukaj po nazwie na poziomie 1–3: najpierw „zaczyna się od”, potem „zawiera”. @return list<array> */
function terytSearch(string $q, int $level = 3, int $limit = 30): array {
    $q = mb_strtolower(trim($q));
    if (mb_strlen($q) < 2) return [];
    $starts = $contains = [];
    foreach (terytUnits() as $u) {
        if ($u['poziom'] !== $level) continue;
        $p = mb_strpos(mb_strtolower($u['nazwa']), $q);
        if ($p === 0) $starts[] = $u; elseif ($p !== false) $contains[] = $u;
    }
    $sort = fn($a, $b) => _terytCollate($a['nazwa']) <=> _terytCollate($b['nazwa']);
    usort($starts, $sort); usort($contains, $sort);
    return array_slice(array_merge($starts, $contains), 0, $limit);
}

/** Gmina po kodzie albo nazwie + jej powiat i województwo. */
function terytGmina(string $by, string $val): ?array {
    $g = null;
    if ($by === 'kod') $g = terytUnits()[$val] ?? null;
    else foreach (terytUnits() as $u) if ($u['poziom'] === 3 && $u['nazwa'] === $val) { $g = $u; break; }
    if (!$g || $g['poziom'] !== 3) return null;
    $all = terytUnits();
    $pow = $all[(string)$g['rodzic']] ?? null;
    $woj = $pow ? ($all[(string)$pow['rodzic']] ?? null) : null;
    return ['gmina' => $g['nazwa'], 'kod_gmi' => $g['kod'], 'kod_pow' => $pow['kod'] ?? substr($g['kod'], 0, 4),
            'kod_woj' => $woj['kod'] ?? substr($g['kod'], 0, 2), 'powiat' => $pow['nazwa'] ?? null, 'woj' => $woj['nazwa'] ?? null];
}

/** Klucz sortowania po polsku bez zależności od locale (ą po a, ł po l…). */
function _terytCollate(string $s): string {
    static $map = ['ą' => 'a~', 'ć' => 'c~', 'ę' => 'e~', 'ł' => 'l~', 'ń' => 'n~', 'ó' => 'o~', 'ś' => 's~', 'ź' => 'z~', 'ż' => 'z~~'];
    return strtr(mb_strtolower($s), $map);
}

/**
 * Synchronizuje słownik TERYT_DICT z tabelą teryt_units (zasiew i po imporcie TERC z GUS). Dodaje i aktualizuje
 * wpisy po kodach — wpisów nie usuwa (słownik systemowy; zbędne wyłącz w Słowniku Centralnym).
 * @return array{created:int,updated:int,unchanged:int,errors:array}
 */
function terytSyncDictionary(): array {
    require_once dirname(__DIR__) . '/modules/dictionary/lib/dictionaryApi.php';
    $units = _terytFromTable();
    if (!$units) foreach (TERYT_WOJ_DEFAULT as $k => $n) $units[$k] = ['kod' => $k, 'nazwa' => $n, 'typ' => 'województwo', 'poziom' => 1, 'rodzic' => null];
    uasort($units, fn($a, $b) => [$a['poziom'], $a['kod']] <=> [$b['poziom'], $b['kod']]);
    $entries = [];
    $i = 0;
    foreach ($units as $u)
        $entries[] = ['code' => $u['kod'], 'label' => $u['nazwa'], 'parent_code' => $u['rodzic'], 'sort_order' => (++$i) * 10, 'is_active' => true,
                      'description' => null, 'attributes' => ['poziom' => TERYT_LEVELS[$u['poziom']], 'typ' => $u['typ']]];
    $json = json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => [
        ['code' => TERYT_DICT, 'name' => 'TERYT — województwa, powiaty, gminy', 'kind' => 'system', 'parent_code' => null,
         'is_protected' => false, 'sort_order' => 80,
         'description' => 'Podział terytorialny wg TERYT (GUS): województwo → powiat → gmina. Kody = kody TERYT (2/4/7 znaków), używane w kodzie i w danych. Atrybuty: poziom (woj|pow|gmi), typ. Pełny, aktualny rejestr: Admin › Import TERYT (plik TERC z eTERYT) — import aktualizuje ten słownik.',
         'entries' => $entries],
    ]], JSON_UNESCAPED_UNICODE);
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $res = dictTransfer()->import($json, 'json');
        if ($own) $pdo->commit();
    } catch (\Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $r = $res[0] ?? [];
    return ['created' => (int)($r['created'] ?? 0), 'updated' => (int)($r['updated'] ?? 0), 'unchanged' => (int)($r['unchanged'] ?? 0), 'errors' => (array)($r['errors'] ?? [])];
}

/**
 * Podpowiedzi ze słownika TERYT dla pól adresowych formularza: województwo (select z nazwami) → powiat → gmina
 * (zwykłe pola tekstowe — wolny tekst nadal dozwolony) i opcjonalnie kod TERYT gminy (uzupełniany, gdy pusty).
 * Selektory CSS szukane w formularzu pola województwa (albo w dokumencie). Bez województwa — szukanie po wpisanym tekście.
 * Dane: crm/api/teryt.php (powiaty, gminy, search). Zwraca HTML do wstawienia za polami.
 */
function terytFieldsAttach(string $wojSel, string $powSel, string $gmiSel, string $kodSel = ''): string {
    static $n = 0;
    $n++;
    $cfg = ['woj' => $wojSel, 'pow' => $powSel, 'gmi' => $gmiSel, 'kod' => $kodSel, 'api' => APP_URL . '/crm/api/teryt.php',
            'wojKody' => array_flip(terytWojewodztwa()), 'id' => 'tdl' . $n];
    $js = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    return '<datalist id="tdl' . $n . 'p"></datalist><datalist id="tdl' . $n . 'g"></datalist><script>(function(c){'
        . 'var w=c.woj?document.querySelector(c.woj):null,root=(w&&w.form)||document,p=root.querySelector(c.pow),g=root.querySelector(c.gmi),k=c.kod?root.querySelector(c.kod):null;'
        . 'if(!p&&!g)return;var pd=document.getElementById(c.id+"p"),gd=document.getElementById(c.id+"g"),pm={},gm={},t=0;'
        . 'if(p)p.setAttribute("list",c.id+"p");if(g)g.setAttribute("list",c.id+"g");'
        . 'function key(s){return String(s||"").trim().toLowerCase().replace(/^(powiat|m\.|miasto|gmina)\s+/,"");}'
        . 'function fill(dl,map,rows){dl.innerHTML="";rows.forEach(function(r){if(!(key(r.name) in map))map[key(r.name)]=r.id;var o=document.createElement("option");o.value=r.name;o.label=r.label;dl.appendChild(o);});}'
        . 'function get(q,cb){fetch(c.api+"?"+q,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(d){cb(Array.isArray(d)?d:[]);}).catch(function(){});}'
        . 'function wk(){return w?c.wojKody[w.value]:null;}'
        . 'function loadP(){if(!p)return;var x=wk();pm={};if(x)get("action=powiaty&woj="+x,function(d){fill(pd,pm,d);loadG();});else pd.innerHTML="";}'
        . 'function loadG(){if(!g)return;var x=p?pm[key(p.value)]:null;gm={};if(x)get("action=gminy&pow="+x,function(d){fill(gd,gm,d);});}'
        . 'function typed(inp,dl,map,lvl){clearTimeout(t);var v=inp.value.trim();if(v.length<2||Object.keys(map).length&&(lvl===2?wk():(p&&pm[key(p.value)])))return;'
        . 't=setTimeout(function(){get("action=search&level="+lvl+"&q="+encodeURIComponent(v),function(d){var m={};fill(dl,m,d);Object.assign(map,m);});},250);}'
        . 'if(w)w.addEventListener("change",loadP);'
        . 'if(p){p.addEventListener("change",loadG);p.addEventListener("input",function(){typed(p,pd,pm,2);});}'
        . 'if(g){g.addEventListener("input",function(){typed(g,gd,gm,3);});g.addEventListener("change",function(){var x=gm[key(g.value)];if(k&&x&&!k.value)k.value=x;});}'
        . 'if(wk())loadP();'
        . '})(' . $js . ');</script>';
}

/**
 * Podpowiedzi miejscowości z TERYT (Słownik Centralny) dla pojedynczego pola „Miasto” — formularze wysyłki pism
 * (Postivo, książka nadawcza). Wpisanie ≥2 liter → gminy z TERYT (crm/api/addr.php?action=city); po kodzie pocztowym
 * XX-XXX — miejscowości tego kodu (gdy jest zaimportowana tabela kodów). Wolny tekst nadal dozwolony.
 */
function terytCityAttach(string $citySel, string $postalSel = ''): string {
    static $n = 0;
    $n++;
    $cfg = json_encode(['city' => $citySel, 'postal' => $postalSel, 'api' => APP_URL . '/crm/api/addr.php', 'id' => 'tcl' . $n],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    return '<datalist id="tcl' . $n . '"></datalist><script>(function(c){var f=document.querySelector(c.city);if(!f)return;'
        . 'var dl=document.getElementById(c.id),p=c.postal?document.querySelector(c.postal):null,t=0;f.setAttribute("list",c.id);'
        . 'function fill(a){dl.innerHTML="";a.forEach(function(v){var o=document.createElement("option");o.value=v;dl.appendChild(o);});}'
        . 'function get(q,cb){fetch(c.api+"?"+q,{credentials:"same-origin"}).then(function(r){return r.json();}).then(cb).catch(function(){});}'
        . 'f.addEventListener("input",function(){clearTimeout(t);var v=f.value.trim();if(v.length<2)return;t=setTimeout(function(){get("action=city&q="+encodeURIComponent(v),function(d){if(Array.isArray(d))fill(d);});},250);});'
        . 'if(p)p.addEventListener("change",function(){var v=p.value.trim();if(!/^\d{2}-\d{3}$/.test(v))return;get("action=postal_city&code="+v,function(d){var a=(d&&d.cities)||[];if(a.length){fill(a);if(!f.value&&a.length===1)f.value=a[0];}});});'
        . '})(' . $cfg . ');</script>';
}
