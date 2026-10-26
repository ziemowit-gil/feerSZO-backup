<?php
/**
 * includes/crmAiBuilder.php — „Statusy i listy z AI” w CRM.
 *
 * Trzy rzeczy w jednym miejscu:
 *   1. Katalog GOTOWYCH statusów (bez AI) dla firm i dla adresów technicznych/ogólnych, każdy z opcjonalną regułą
 *      automatyczną (includes/crmStatusRules.php). Zakładanie = zawsze świadomy wybór człowieka; reguły powstają WYŁĄCZONE.
 *   2. Zapytanie do modelu (Anthropic, ten sam klucz co Asystent AI) o propozycje statusów, reguł i LIST (segmentów dynamicznych
 *      crm_segments). Model dostaje wyłącznie ZBIORCZE statystyki bazy (liczby, rozkłady, najczęstsze domeny firm) — żadnych
 *      danych osobowych, adresów e-mail ani nazw osób. Odpowiedź jest walidowana po stronie serwera: statusy przez slug,
 *      reguły przez białą listę warunków, listy przez crm_segment_sanitize() (pola/operatory z białej listy).
 *   3. Zastosowanie zaznaczonych propozycji.
 * Propozycje czekają w sesji pod losowym tokenem — formularz zatwierdzający przesyła tylko indeksy, nigdy treść.
 */
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/crmStatusRules.php';
require_once __DIR__ . '/crm_segment.php';
require_once __DIR__ . '/crm_segments_ui.php';
require_once __DIR__ . '/asystent_ai.php';
require_once __DIR__ . '/crmAutomat.php';

/** Gotowe propozycje (bez AI). rule = [cond, param, from(csv)] albo null. */
function crm_ai_builder_catalog(): array {
    return [
        // ── Firmy i organizacje ────────────────────────────────────────────
        ['key' => 'firma-do-weryfikacji', 'group' => 'Firmy', 'slug' => 'firma-do-weryfikacji', 'label' => 'Firma do weryfikacji', 'color' => '#B45309',
         'desc' => 'Firma lub organizacja bez numeru NIP — do uzupełnienia danych (np. importem z GUS).',
         'rule' => ['Firma bez NIP → do weryfikacji', 'org_no_nip', '', 'prospect']],
        ['key' => 'dostawca',            'group' => 'Firmy', 'slug' => 'dostawca',            'label' => 'Dostawca',            'color' => '#706E6B',
         'desc' => 'Firma, od której fundacja kupuje usługi lub towary (nie klient).', 'rule' => null],
        ['key' => 'sponsor',             'group' => 'Firmy', 'slug' => 'sponsor',             'label' => 'Sponsor / mecenas',   'color' => '#FE9339',
         'desc' => 'Firma wspierająca finansowo lub rzeczowo działania fundacji.', 'rule' => null],
        ['key' => 'instytucja-publiczna','group' => 'Firmy', 'slug' => 'instytucja-publiczna','label' => 'Instytucja publiczna','color' => '#032D60',
         'desc' => 'Urząd, szkoła, jednostka samorządu — osobny tryb korespondencji i rozliczeń.', 'rule' => null],
        ['key' => 'wspolpraca-zawieszona','group' => 'Firmy','slug' => 'wspolpraca-zawieszona','label' => 'Współpraca zawieszona','color' => '#939393',
         'desc' => 'Firma, z którą współpraca jest przerwana, ale historia ma zostać.', 'rule' => null],
        // ── Adresy techniczne i ogólne ─────────────────────────────────────
        ['key' => 'techniczny',          'group' => 'Adresy techniczne', 'slug' => 'techniczny', 'label' => 'Techniczny (automat)', 'color' => '#6B7280',
         'desc' => 'Kontakt typu „Kontakt techniczny” (noreply, powiadomienia systemów) — poza wysyłkami i kampaniami.',
         'rule' => ['Kontakt techniczny → techniczny', 'type', 'kontakt_techniczny', '']],
        ['key' => 'skrzynka-ogolna',     'group' => 'Adresy techniczne', 'slug' => 'skrzynka-ogolna', 'label' => 'Skrzynka ogólna (biuro@, info@)', 'color' => '#D97706',
         'desc' => 'Adres funkcyjny, nie imienny — korespondencja bezosobowa („Szanowni Państwo”).',
         'rule' => ['Adres ogólny → skrzynka ogólna', 'email_kind', 'generic', 'prospect']],
        ['key' => 'powiadomienia',       'group' => 'Adresy techniczne', 'slug' => 'powiadomienia', 'label' => 'Powiadomienia systemowe', 'color' => '#9CA3AF',
         'desc' => 'Nadawcy faktur, potwierdzeń, alertów z usług zewnętrznych — nadawane ręcznie lub po przeglądzie Bota sprzątającego.', 'rule' => null],
    ];
}

/** Zakłada zaznaczone gotowe statusy (+ ich reguły, wyłączone). @return array{statuses:int,rules:int} */
function crm_ai_builder_apply_catalog(array $keys): array {
    crm_status_rules_schema();
    $by = [];
    foreach (crm_ai_builder_catalog() as $c) $by[$c['key']] = $c;
    $st = 0; $ru = 0;
    foreach ($keys as $k) {
        $c = $by[(string)$k] ?? null;
        if (!$c) continue;
        $max = (int)(db_one("SELECT COALESCE(MAX(sort_order),0) AS n FROM crm_statuses")['n'] ?? 0);
        if (!db_one("SELECT id FROM crm_statuses WHERE slug=?", [$c['slug']])) {
            db_insert('crm_statuses', ['slug' => $c['slug'], 'label' => $c['label'], 'color' => $c['color'], 'sort_order' => $max + 1, 'is_active' => 1]);
            $st++;
        }
        if ($c['rule'] && !db_one("SELECT id FROM crm_status_rules WHERE name=?", [$c['rule'][0]])) {
            $rmax = (int)(db_one("SELECT COALESCE(MAX(sort_order),0) AS n FROM crm_status_rules")['n'] ?? 0);
            db_insert('crm_status_rules', ['name' => $c['rule'][0], 'is_active' => 0, 'sort_order' => $rmax + 1, 'cond' => $c['rule'][1],
                                           'param' => $c['rule'][2], 'from_statuses' => $c['rule'][3], 'to_status' => $c['slug']]);
            $ru++;
        }
    }
    return ['statuses' => $st, 'rules' => $ru];
}

/** Zbiorcze statystyki bazy dla modelu — bez danych osobowych. */
function crm_ai_builder_context(): array {
    $one = fn(string $sql, array $p = []) => (int)(db_one($sql, $p)['n'] ?? 0);
    $dist = function (string $col, int $lim = 15): array {
        $out = [];
        foreach (db_all("SELECT COALESCE(NULLIF(TRIM($col),''),'(brak)') AS k, COUNT(*) AS n FROM crm_contacts WHERE crm_active=1 GROUP BY 1 ORDER BY n DESC LIMIT $lim") as $r) $out[(string)$r['k']] = (int)$r['n'];
        return $out;
    };
    $ctx = [
        'kontaktow_aktywnych' => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE crm_active=1"),
        'wg_typu'             => $dist('type'),
        'wg_statusu'          => $dist('status'),
        'wg_rodzaju_adresu_email' => $dist('email_kind'),
        'wg_zrodla'           => $dist('source'),
        'wg_branzy'           => $dist('branza'),
        'wg_formy_prawnej'    => $dist('forma_prawna'),
        'wg_wojewodztwa'      => $dist('wojewodztwo', 20),
        'firm_bez_nip'        => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE crm_active=1 AND type IN ('organizacja','kontrahent','partner') AND TRIM(COALESCE(nip,''))=''"),
        'bez_adresu_email'    => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE crm_active=1 AND TRIM(COALESCE(email,''))=''"),
        'wypisani_z_mailingu' => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE crm_active=1 AND email_opt_out=1"),
        'istniejace_statusy'  => array_map(fn($r) => $r['slug'] . ' = ' . $r['label'], db_all("SELECT slug,label FROM crm_statuses WHERE is_active=1 ORDER BY sort_order")),
        'istniejace_reguly'   => array_map(fn($r) => $r['name'], db_all("SELECT name FROM crm_status_rules")),
        'istniejace_listy'    => array_map(fn($r) => $r['name'], db_all("SELECT name FROM crm_segments")),
        'tagi'                => array_map(fn($r) => $r['tag'], db_all("SELECT tag FROM crm_tags GROUP BY tag ORDER BY COUNT(*) DESC LIMIT 30")),
    ];
    // Najczęstsze domeny FIRM (nie osób) — pomagają wykryć np. administrację publiczną; domena firmy nie jest daną osobową.
    $dom = [];
    foreach (db_all("SELECT LOWER(SUBSTR(email, INSTR(email,'@')+1)) AS d, COUNT(*) AS n FROM crm_contacts
                      WHERE crm_active=1 AND email LIKE '%@%' AND type IN ('organizacja','kontrahent','partner') GROUP BY d ORDER BY n DESC LIMIT 15") as $r) $dom[(string)$r['d']] = (int)$r['n'];
    $ctx['najczestsze_domeny_firm'] = $dom;
    $ctx['adresow_technicznych_wg_wzorca'] = [
        'noreply_lub_powiadomienia' => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE crm_active=1 AND (LOWER(email) LIKE 'noreply@%' OR LOWER(email) LIKE 'no-reply@%' OR LOWER(email) LIKE 'notification%@%' OR LOWER(email) LIKE 'mailer-daemon@%')"),
        'biuro_info_kontakt'        => $one("SELECT COUNT(*) AS n FROM crm_contacts WHERE crm_active=1 AND (LOWER(email) LIKE 'biuro@%' OR LOWER(email) LIKE 'info@%' OR LOWER(email) LIKE 'kontakt@%' OR LOWER(email) LIKE 'sekretariat@%')"),
    ];
    return $ctx;
}

/** Pola i operatory segmentów w zwięzłej postaci dla promptu. */
function crm_ai_builder_segment_spec(): string {
    $ops = crm_segment_operators();
    $lines = [];
    foreach (crm_segment_fields() as $k => $f) {
        if (str_starts_with($k, 'custom:') || in_array($f['kind'], ['event', 'consent'], true)) continue;
        $opt = '';
        if (!empty($f['options']) && count($f['options']) <= 25) $opt = ' wartości: ' . implode(' | ', array_map(fn($o) => $o['value'], $f['options']));
        $lines[] = "- {$k} ({$f['kind']}): operatory " . implode(', ', array_keys($ops[$f['kind']] ?? [])) . $opt;
    }
    return implode("\n", $lines);
}

/**
 * Pyta model o propozycje. Zwraca ['error'=>..] albo ['statuses'=>[], 'rules'=>[], 'segments'=>[], 'summary'=>'']
 * — wszystkie elementy już zwalidowane i opatrzone liczbą kontaktów.
 * $caller pozwala testom podstawić atrapę zamiast prawdziwego API.
 */
function crm_ai_builder_ask(string $goal, ?callable $caller = null): array {
    $caller ??= 'asai_call';
    if ($caller === 'asai_call' && !asai_enabled()) return ['error' => 'Brak klucza Anthropic API — skonfiguruj w: Admin → Ustawienia AI. Gotowe propozycje (bez AI) działają bez klucza.'];
    $goal = trim($goal) !== '' ? trim($goal) : 'Zaproponuj statusy dla firm i adresów technicznych oraz kilka praktycznych list roboczych.';

    $conds = [
        'active_contract' => 'kontakt ma aktywną umowę (bez parametru)',
        'category'        => 'kontakt ma kategorię (param = slug kategorii)',
        'inactive_days'   => 'brak aktywności od N dni (param = liczba >= 30)',
        'type'            => 'typ kontaktu (param = ' . implode('|', array_keys(CRM_CONTACT_TYPES)) . ')',
        'email_kind'      => 'rodzaj adresu (param = personal|generic|unknown)',
        'org_no_nip'      => 'firma bez NIP (bez parametru)',
    ];
    $system = "Jesteś analitykiem CRM polskiej fundacji. Na podstawie ZBIORCZYCH statystyk bazy kontaktów proponujesz: "
        . "(a) nowe STATUSY relacji, (b) REGUŁY automatycznego nadawania statusu, (c) LISTY (segmenty dynamiczne) do pracy i wysyłek. "
        . "Zasady: nie powtarzaj istniejących statusów, reguł i list; proponuj tylko to, co ma pokrycie w danych (wartości liczbowe wskazują, czego jest dużo); "
        . "maksymalnie 6 statusów, 6 reguł i 8 list; slug statusu: małe litery, cyfry i myślniki; kolor w formacie #RRGGBB; teksty po polsku, krótkie. "
        . "Reguła: cond z listy, to_status = slug (istniejący lub proponowany), from_statuses = lista slugów lub pusta.\n"
        . "Dozwolone warunki reguł:\n" . implode("\n", array_map(fn($k, $v) => "- $k: $v", array_keys($conds), $conds)) . "\n"
        . "Lista (segment): drzewo {op:and|or, rules:[{field,op,value}]}; value dla operatorów in/not_in to tablica tekstów, dla pozostałych tekst, dla 'days' liczba dni. "
        . "Dostępne pola:\n" . crm_ai_builder_segment_spec() . "\n"
        . "Pamiętaj: adresy techniczne (noreply, powiadomienia) i ogólne (biuro@) nie powinny dostawać wysyłek imiennych.";

    $schema = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string', 'description' => '1–2 zdania: co widać w bazie i skąd propozycje'],
            'statuses' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'slug' => ['type' => 'string'], 'label' => ['type' => 'string'], 'color' => ['type' => 'string'], 'reason' => ['type' => 'string']],
                'required' => ['slug', 'label', 'reason']]],
            'rules' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'cond' => ['type' => 'string', 'enum' => array_keys($conds)], 'param' => ['type' => 'string'],
                'from_statuses' => ['type' => 'array', 'items' => ['type' => 'string']], 'to_status' => ['type' => 'string'], 'reason' => ['type' => 'string']],
                'required' => ['name', 'cond', 'to_status', 'reason']]],
            'segments' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string'], 'description' => ['type' => 'string'],
                'filter' => ['type' => 'object', 'properties' => ['op' => ['type' => 'string'], 'rules' => ['type' => 'array', 'items' => ['type' => 'object']]], 'required' => ['rules']]],
                'required' => ['name', 'filter']]],
        ],
        'required' => ['summary', 'statuses', 'rules', 'segments'],
    ];

    $payload = [
        'model' => asai_model(), 'max_tokens' => 4096, 'system' => $system,
        'tools' => [['name' => 'zaproponuj', 'description' => 'Zwraca gotowe propozycje statusów, reguł i list.', 'input_schema' => $schema]],
        'tool_choice' => ['type' => 'tool', 'name' => 'zaproponuj'],
        'messages' => [['role' => 'user', 'content' => "Cel użytkownika: {$goal}\n\nStatystyki bazy (JSON):\n" . json_encode(crm_ai_builder_context(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
    ];
    $resp = $caller($payload);
    if (!empty($resp['error'])) return ['error' => (string)$resp['error']];
    $in = null;
    foreach ((array)($resp['content'] ?? []) as $b) if (($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === 'zaproponuj') { $in = $b['input'] ?? null; break; }
    if (!is_array($in)) return ['error' => 'Model nie zwrócił propozycji — spróbuj doprecyzować cel.'];
    return crm_ai_builder_validate($in);
}

/** Walidacja i wzbogacenie propozycji modelu. Wszystko, co niepoprawne, jest po cichu odrzucane. */
function crm_ai_builder_validate(array $in): array {
    $existing = array_column(db_all("SELECT slug FROM crm_statuses"), 'slug');
    $out = ['summary' => mb_substr(trim((string)($in['summary'] ?? '')), 0, 400), 'statuses' => [], 'rules' => [], 'segments' => []];
    $slugs = array_flip($existing);

    foreach ((array)($in['statuses'] ?? []) as $s) {
        if (!is_array($s)) continue;
        $slug = trim(preg_replace('/[^a-z0-9\-]+/', '-', mb_strtolower(strtr((string)($s['slug'] ?? ''), ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']))), '-');
        $label = trim((string)($s['label'] ?? ''));
        if ($slug === '' || $label === '' || isset($slugs[$slug])) continue;
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($s['color'] ?? '')) ? (string)$s['color'] : '#6B7280';
        $slugs[$slug] = true;
        $out['statuses'][] = ['slug' => mb_substr($slug, 0, 40), 'label' => mb_substr($label, 0, 60), 'color' => $color, 'reason' => mb_substr(trim((string)($s['reason'] ?? '')), 0, 300)];
        if (count($out['statuses']) >= 6) break;
    }

    $existing_rules = array_column(db_all("SELECT name FROM crm_status_rules"), 'name');
    foreach ((array)($in['rules'] ?? []) as $r) {
        if (!is_array($r)) continue;
        $cond = (string)($r['cond'] ?? ''); $param = trim((string)($r['param'] ?? '')); $to = (string)($r['to_status'] ?? '');
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '' || in_array($name, $existing_rules, true) || !isset(CRM_STATUS_RULE_CONDITIONS[$cond]) || !isset($slugs[$to])) continue;
        if ($cond === 'inactive_days' && (int)$param < 30) continue;
        if ($cond === 'type' && !isset(CRM_CONTACT_TYPES[$param])) continue;
        if ($cond === 'email_kind' && !in_array($param, ['personal', 'generic', 'unknown'], true)) continue;
        if ($cond === 'category' && $param === '') continue;
        if (in_array($cond, ['active_contract', 'org_no_nip'], true)) $param = '';
        $from = array_values(array_filter(array_map('strval', (array)($r['from_statuses'] ?? [])), fn($x) => isset($slugs[$x])));
        $cand = count(crm_status_rule_candidates(['cond' => $cond, 'param' => $param]));
        $out['rules'][] = ['name' => mb_substr($name, 0, 80), 'cond' => $cond, 'param' => $param, 'from' => $from, 'to' => $to,
                           'reason' => mb_substr(trim((string)($r['reason'] ?? '')), 0, 300), 'candidates' => $cand];
        if (count($out['rules']) >= 6) break;
    }

    $existing_seg = array_map('mb_strtolower', array_column(db_all("SELECT name FROM crm_segments"), 'name'));
    foreach ((array)($in['segments'] ?? []) as $g) {
        if (!is_array($g)) continue;
        $name = trim((string)($g['name'] ?? ''));
        if ($name === '' || in_array(mb_strtolower($name), $existing_seg, true)) continue;
        $tree = crm_segment_sanitize((array)($g['filter'] ?? []));
        if (empty($tree['rules'])) continue;
        $out['segments'][] = ['name' => mb_substr($name, 0, 80), 'description' => mb_substr(trim((string)($g['description'] ?? '')), 0, 300),
                              'filter' => $tree, 'count' => count(crm_segment_resolve_ids($tree)), 'describe' => crm_segment_describe($tree)];
        if (count($out['segments']) >= 8) break;
    }
    return $out;
}

/** Zakłada zaznaczone elementy propozycji AI. $sel = ['statuses'=>[idx], 'rules'=>[idx], 'segments'=>[idx]]. */
function crm_ai_builder_apply(array $proposal, array $sel): array {
    crm_status_rules_schema();
    $res = ['statuses' => 0, 'rules' => 0, 'segments' => 0, 'errors' => []];
    $pick = fn(string $k) => array_values(array_filter(array_map('intval', (array)($sel[$k] ?? [])), fn($i) => isset($proposal[$k][$i])));
    foreach ($pick('statuses') as $i) {
        $s = $proposal['statuses'][$i];
        if (db_one("SELECT id FROM crm_statuses WHERE slug=?", [$s['slug']])) continue;
        $max = (int)(db_one("SELECT COALESCE(MAX(sort_order),0) AS n FROM crm_statuses")['n'] ?? 0);
        db_insert('crm_statuses', ['slug' => $s['slug'], 'label' => $s['label'], 'color' => $s['color'], 'sort_order' => $max + 1, 'is_active' => 1]);
        $res['statuses']++;
    }
    foreach ($pick('rules') as $i) {
        $r = $proposal['rules'][$i];
        if (!db_one("SELECT id FROM crm_statuses WHERE slug=?", [$r['to']])) { $res['errors'][] = "Reguła „{$r['name']}” — brak statusu docelowego „{$r['to']}” (zaznacz też ten status)."; continue; }
        if (db_one("SELECT id FROM crm_status_rules WHERE name=?", [$r['name']])) continue;
        $rmax = (int)(db_one("SELECT COALESCE(MAX(sort_order),0) AS n FROM crm_status_rules")['n'] ?? 0);
        db_insert('crm_status_rules', ['name' => $r['name'], 'is_active' => 0, 'sort_order' => $rmax + 1, 'cond' => $r['cond'], 'param' => $r['param'],
                                       'from_statuses' => implode(',', $r['from']), 'to_status' => $r['to']]);
        $res['rules']++;
    }
    foreach ($pick('segments') as $i) {
        $g = $proposal['segments'][$i];
        $r = crm_segment_save($g['name'], $g['filter'], $g['description']);
        if ($r['ok']) $res['segments']++; else $res['errors'][] = "Lista „{$g['name']}” — " . $r['error'];
    }
    return $res;
}

// ── Asystent AI w CRM: narzędzia agenta ──────────────────────────────────────────────────────────────────────────────

/** Czy rozmówca może używać narzędzi CRM asystenta (zalogowany pracownik z zapisem w CRM, moduł włączony). */
function crm_ai_assistant_allowed(array $ctx): bool {
    if (empty($ctx['personal']) || !in_array($ctx['role'] ?? '', ['editor', 'admin'], true)) return false;
    try {
        if (function_exists('org_setting') && org_setting('crm_enabled') === '0') return false;
        return function_exists('crm_can') && crm_can('contacts', 'write');
    } catch (\Throwable $e) { return false; }
}

function crm_ai_assistant_tools(): array {
    $conds = array_keys(CRM_STATUS_RULE_CONDITIONS);
    return [
        ['name' => 'crm_przeglad_bazy',
         'description' => 'CRM: zbiorcze statystyki bazy kontaktów (liczby wg typu, statusu, rodzaju adresu e-mail, branży, województwa, istniejące statusy/listy/tagi, najczęstsze domeny firm). Bez danych osobowych. Użyj przed proponowaniem statusów lub list.',
         'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
        ['name' => 'crm_utworz_liste',
         'description' => 'CRM: tworzy LISTĘ (segment dynamiczny) kontaktów wg filtra. Odwracalne (listę można usunąć), ale użyj dopiero, gdy użytkownik wyraźnie o to prosi lub zaakceptował propozycję. Pola filtra: ' . implode(', ', array_keys(array_filter(crm_segment_fields(), fn($f, $k) => !str_starts_with($k, 'custom:') && !in_array($f['kind'], ['event', 'consent'], true), ARRAY_FILTER_USE_BOTH))) . '.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'nazwa' => ['type' => 'string'], 'opis' => ['type' => 'string'],
             'filtr' => ['type' => 'object', 'description' => 'Drzewo {op:"and"|"or", rules:[{field, op, value}]}; operatory: in/not_in (value = tablica), contains/eq/starts/empty/not_empty (tekst), has/not_has (tag, grupa), within_days/older_than_days (liczba dni).']],
             'required' => ['nazwa', 'filtr']]],
        ['name' => 'crm_utworz_status',
         'description' => 'CRM: dodaje nowy STATUS relacji do słownika (tylko administrator lub osoba z uprawnieniem do ustawień CRM). Reguły automatyczne zakłada się wyłącznie ręcznie na ekranie „Statusy i listy z AI”: ' . APP_URL . '/crm/settings/ai_builder.php (warunki: ' . implode(', ', $conds) . ').',
         'input_schema' => ['type' => 'object', 'properties' => ['etykieta' => ['type' => 'string'], 'kolor' => ['type' => 'string', 'description' => '#RRGGBB']], 'required' => ['etykieta']]],
    ];
}

/** Wykonuje narzędzie CRM asystenta. @return array{out:string,summary:string} */
function crm_ai_assistant_exec(string $name, array $in, array $ctx): array {
    if (!crm_ai_assistant_allowed($ctx)) return ['out' => 'Brak uprawnień do narzędzi CRM.', 'summary' => 'odmowa'];
    if ($name === 'crm_przeglad_bazy') {
        return ['out' => json_encode(crm_ai_builder_context(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'summary' => 'statystyki CRM'];
    }
    if ($name === 'crm_utworz_liste') {
        $tree = crm_segment_sanitize((array)($in['filtr'] ?? []));
        if (empty($tree['rules'])) return ['out' => 'Filtr nie zawiera żadnego poprawnego warunku (sprawdź nazwy pól i operatory).', 'summary' => 'filtr odrzucony'];
        $r = crm_segment_save((string)($in['nazwa'] ?? ''), $tree, (string)($in['opis'] ?? ''));
        if (!$r['ok']) return ['out' => 'Nie utworzono listy: ' . $r['error'], 'summary' => 'błąd'];
        $n = count(crm_segment_resolve_ids($tree));
        return ['out' => "Utworzono listę „" . trim((string)$in['nazwa']) . "” ({$n} kontaktów teraz): " . crm_segment_describe($tree) . '. Listy znajdziesz w CRM: ' . APP_URL . '/crm/index.php', 'summary' => "lista: {$n} kontaktów"];
    }
    if ($name === 'crm_utworz_status') {
        if (!(function_exists('is_admin') && is_admin()) && !(function_exists('can_write') && can_write('crm_ustawienia'))) return ['out' => 'Statusy może dodawać administrator lub osoba z uprawnieniem do ustawień CRM.', 'summary' => 'odmowa'];
        $label = trim((string)($in['etykieta'] ?? ''));
        $res = crm_ai_builder_validate(['statuses' => [['slug' => $label, 'label' => $label, 'color' => (string)($in['kolor'] ?? ''), 'reason' => '']]]);
        if (!$res['statuses']) return ['out' => 'Taki status już istnieje albo etykieta jest niepoprawna.', 'summary' => 'pominięto'];
        $r = crm_ai_builder_apply($res, ['statuses' => [0]]);
        return ['out' => $r['statuses'] ? "Dodano status „{$label}”." : 'Status już istnieje.', 'summary' => 'status: ' . $label];
    }
    return ['out' => 'Nieznane narzędzie CRM.', 'summary' => '—'];
}

// ── Gotowe listy (segmenty) bez AI ───────────────────────────────────────────────────────────────────────────────────

/** Katalog gotowych list: key, name, desc, filter (drzewo segmentu). */
function crm_ai_builder_segment_catalog(): array {
    $orgs = ['organizacja', 'kontrahent', 'partner'];
    return [
        ['key' => 'firmy', 'name' => 'Firmy i organizacje', 'desc' => 'Wszystkie kartoteki firm, organizacji, kontrahentów i partnerów.',
         'filter' => ['op' => 'and', 'rules' => [['field' => 'type', 'op' => 'in', 'value' => $orgs]]]],
        ['key' => 'techniczne', 'name' => 'Adresy techniczne', 'desc' => 'Typ „Kontakt techniczny” albo status Techniczny / Powiadomienia — do przeglądu i czyszczenia.',
         'filter' => ['op' => 'or', 'rules' => [['field' => 'type', 'op' => 'in', 'value' => ['kontakt_techniczny']], ['field' => 'status', 'op' => 'in', 'value' => CRM_TECHNICAL_STATUSES]]]],
        ['key' => 'do-weryfikacji', 'name' => 'Firmy do weryfikacji', 'desc' => 'Firmy ze statusem „Firma do weryfikacji” (np. bez NIP) — do uzupełnienia.',
         'filter' => ['op' => 'and', 'rules' => [['field' => 'status', 'op' => 'in', 'value' => ['firma-do-weryfikacji']]]]],
        ['key' => 'partnerzy-darczyncy', 'name' => 'Partnerzy i darczyńcy', 'desc' => 'Status Partner, Darczyńca lub Sponsor — do podziękowań i sprawozdań.',
         'filter' => ['op' => 'and', 'rules' => [['field' => 'status', 'op' => 'in', 'value' => ['partner', 'darczyńca', 'sponsor']]]]],
        ['key' => 'nowe-30', 'name' => 'Nowe kontakty (30 dni)', 'desc' => 'Dodane w ostatnich 30 dniach — do powitania i przypisania opiekuna.',
         'filter' => ['op' => 'and', 'rules' => [['field' => 'created', 'op' => 'within_days', 'value' => '30']]]],
        ['key' => 'uspione', 'name' => 'Obiecujące bez ruchu (rok)', 'desc' => 'Status „Obiecujący”, dodani ponad rok temu — do podjęcia kontaktu albo zamknięcia.',
         'filter' => ['op' => 'and', 'rules' => [['field' => 'status', 'op' => 'in', 'value' => ['prospect']], ['field' => 'created', 'op' => 'older_than_days', 'value' => '365']]]],
    ];
}

/** Zakłada zaznaczone gotowe listy. @return array{created:int,errors:string[]} */
function crm_ai_builder_apply_segments(array $keys): array {
    $by = []; foreach (crm_ai_builder_segment_catalog() as $c) $by[$c['key']] = $c;
    $created = 0; $errors = [];
    foreach ($keys as $k) {
        $c = $by[(string)$k] ?? null;
        if (!$c) continue;
        $r = crm_segment_save($c['name'], crm_segment_sanitize($c['filter']), $c['desc']);
        if ($r['ok']) $created++; else $errors[] = $c['name'] . ': ' . $r['error'];
    }
    return ['created' => $created, 'errors' => $errors];
}
