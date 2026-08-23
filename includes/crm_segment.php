<?php
/**
 * includes/crm_segment.php — segmentacja kontaktów: filtr jako drzewo warunków
 * → (SQL, parametry) i lista ID kontaktów.
 *
 * PO CO OSOBNY PLIK: dotąd segment kampanii mógł być tylko listą tagów, grup
 * albo „wszyscy" (crm_campaign_collect_ids). To wystarcza do prostych wysyłek,
 * ale nie do pytań w rodzaju „organizacje z branży edukacja, z woj. mazowieckiego,
 * które otworzyły cokolwiek w ostatnim kwartale". Ten plik dokłada segment
 * typu 'filter' obok dotychczasowych — bez zmiany zachowania starych kampanii.
 *
 * BEZPIECZEŃSTWO: filtr przychodzi z formularza, więc ŻADNE pole ani operator nie
 * jest interpolowany do SQL-a wprost. Nazwy kolumn pochodzą wyłącznie z białej
 * listy crm_segment_fields(), wartości zawsze przez parametry. Nieznane pole albo
 * operator = warunek pomijany, nigdy „przepuszczony dalej".
 *
 * Wymaga: includes/db.php, includes/crm.php
 */

require_once __DIR__ . '/crm_newsletter_schema.php';

/**
 * Katalog pól dostępnych w segmentacji — używany też przez UI kreatora,
 * żeby lista pól w formularzu i lista pól akceptowanych przez kompilator
 * nie mogły się rozjechać.
 *
 * kind: 'enum' (wartości ze słownika), 'text' (dowolny tekst), 'flag',
 *       'event' (historia wysyłek), 'days' (okno czasowe), 'consent'
 */
function crm_segment_fields(): array {
    $statuses = [];
    try {
        foreach (db_all("SELECT slug, label FROM crm_statuses WHERE is_active=1 ORDER BY sort_order, label") as $r) {
            $statuses[] = ['value' => $r['slug'], 'label' => $r['label']];
        }
    } catch (\Throwable $e) {}

    $types = [];
    foreach (CRM_CONTACT_TYPES as $slug => $meta) $types[] = ['value' => $slug, 'label' => $meta['label']];

    $distinct = function (string $col): array {
        try {
            $rows = db_all("SELECT DISTINCT {$col} AS v FROM crm_contacts WHERE {$col} IS NOT NULL AND TRIM({$col}) <> '' ORDER BY v LIMIT 200");
            return array_map(fn($r) => ['value' => $r['v'], 'label' => $r['v']], $rows);
        } catch (\Throwable $e) { return []; }
    };

    $fields = [
        'status'      => ['label' => 'Status kontaktu', 'kind' => 'enum', 'column' => 'status',      'options' => $statuses],
        'type'        => ['label' => 'Typ kontaktu',    'kind' => 'enum', 'column' => 'type',        'options' => $types],
        'branza'      => ['label' => 'Branża',          'kind' => 'enum', 'column' => 'branza',      'options' => $distinct('branza')],
        'wojewodztwo' => ['label' => 'Województwo',     'kind' => 'enum', 'column' => 'wojewodztwo', 'options' => $distinct('wojewodztwo')],
        'powiat'      => ['label' => 'Powiat',          'kind' => 'text', 'column' => 'powiat'],
        'gmina'       => ['label' => 'Gmina',           'kind' => 'text', 'column' => 'gmina'],
        'organizacja' => ['label' => 'Organizacja',     'kind' => 'text', 'column' => 'organizacja'],
        'stanowisko'  => ['label' => 'Stanowisko',      'kind' => 'text', 'column' => 'stanowisko'],
        'forma_prawna' => ['label' => 'Forma prawna',   'kind' => 'enum', 'column' => 'forma_prawna', 'options' => $distinct('forma_prawna')],
        'source'      => ['label' => 'Źródło rekordu',  'kind' => 'enum', 'column' => 'source',      'options' => $distinct('source')],
        'tag'         => ['label' => 'Tag',             'kind' => 'tag',   'options' => []],
        'group'       => ['label' => 'Grupa',           'kind' => 'group', 'options' => []],
        'created'     => ['label' => 'Data dodania',    'kind' => 'days'],
        'campaign'    => ['label' => 'Historia wysyłek', 'kind' => 'event'],
        'consent'     => ['label' => 'Zgoda na cel',    'kind' => 'consent', 'options' => []],
    ];

    try {
        foreach (db_all("SELECT DISTINCT tag FROM crm_tags ORDER BY tag LIMIT 500") as $t) {
            $fields['tag']['options'][] = ['value' => $t['tag'], 'label' => $t['tag']];
        }
    } catch (\Throwable $e) {}

    try {
        foreach (db_all("SELECT id, name FROM crm_groups ORDER BY name") as $g) {
            $fields['group']['options'][] = ['value' => (string)(int)$g['id'], 'label' => $g['name']];
        }
    } catch (\Throwable $e) {}

    try {
        foreach (db_all("SELECT id, nazwa FROM crm_consent_purposes WHERE is_active=1 ORDER BY nazwa") as $p) {
            $fields['consent']['options'][] = ['value' => (string)(int)$p['id'], 'label' => $p['nazwa']];
        }
    } catch (\Throwable $e) {}

    // Pola niestandardowe (crm_contact_field_defs) — wartości w crm_contact_field_values.
    try {
        foreach (db_all("SELECT id, label, field_type, options FROM crm_contact_field_defs WHERE is_active=1 ORDER BY sort_order, label") as $d) {
            $opts = [];
            if (trim((string)$d['options']) !== '') {
                foreach (preg_split('/[\r\n,]+/', (string)$d['options']) as $o) {
                    $o = trim($o);
                    if ($o !== '') $opts[] = ['value' => $o, 'label' => $o];
                }
            }
            $fields['custom:' . (int)$d['id']] = [
                'label'   => $d['label'] . ' (pole własne)',
                'kind'    => $opts ? 'custom_enum' : 'custom_text',
                'def_id'  => (int)$d['id'],
                'options' => $opts,
            ];
        }
    } catch (\Throwable $e) {}

    return $fields;
}

/** Operatory dopuszczalne dla danego rodzaju pola (dla UI i walidacji). */
function crm_segment_operators(): array {
    return [
        'enum'        => ['in' => 'jest jednym z', 'not_in' => 'nie jest żadnym z'],
        'custom_enum' => ['in' => 'jest jednym z', 'not_in' => 'nie jest żadnym z'],
        'text'        => ['contains' => 'zawiera', 'not_contains' => 'nie zawiera', 'eq' => 'równa się',
                          'starts' => 'zaczyna się od', 'empty' => 'jest puste', 'not_empty' => 'jest wypełnione'],
        'custom_text' => ['contains' => 'zawiera', 'eq' => 'równa się', 'empty' => 'jest puste', 'not_empty' => 'jest wypełnione'],
        'tag'         => ['has' => 'ma tag', 'not_has' => 'nie ma tagu'],
        'group'       => ['in' => 'należy do', 'not_in' => 'nie należy do'],
        'days'        => ['within_days' => 'w ciągu ostatnich (dni)', 'older_than_days' => 'starsze niż (dni)'],
        'event'       => ['opened' => 'otworzył wiadomość w ciągu (dni)',
                          'clicked' => 'kliknął w ciągu (dni)',
                          'not_opened' => 'NIE otworzył nic w ciągu (dni)',
                          'bounced' => 'wiadomość odbiła się (kiedykolwiek)'],
        'consent'     => ['has' => 'ma aktualną zgodę', 'not_has' => 'nie ma aktualnej zgody'],
    ];
}

/**
 * Kompiluje drzewo filtra do pary (SQL, parametry). Warunki odnoszą się do
 * aliasu `c` tabeli crm_contacts.
 *
 * Kształt węzła grupującego: ['op' => 'and'|'or', 'rules' => [...]]
 * Kształt reguły:            ['field' => ..., 'op' => ..., 'value' => mixed]
 *
 * @return array{0:string,1:array}
 */
function crm_segment_compile(array $node, int $depth = 0): array {
    if ($depth > 5) return ['', []];   // ochrona przed zapętlonym/wrogim wejściem

    // ── Węzeł grupujący ──────────────────────────────────────────────────────
    if (isset($node['rules']) && is_array($node['rules'])) {
        $glue = strtolower((string)($node['op'] ?? 'and')) === 'or' ? ' OR ' : ' AND ';
        $sql = []; $params = [];
        foreach ($node['rules'] as $r) {
            if (!is_array($r)) continue;
            [$s, $p] = crm_segment_compile($r, $depth + 1);
            if ($s === '') continue;
            $sql[] = '(' . $s . ')';
            $params = array_merge($params, $p);
        }
        return $sql ? [implode($glue, $sql), $params] : ['', []];
    }

    // ── Reguła ───────────────────────────────────────────────────────────────
    $fields = crm_segment_fields();
    $key    = (string)($node['field'] ?? '');
    $op     = (string)($node['op'] ?? '');
    $val    = $node['value'] ?? null;

    if (!isset($fields[$key])) return ['', []];
    $def  = $fields[$key];
    $kind = $def['kind'];
    if (!isset(crm_segment_operators()[$kind][$op])) return ['', []];

    $list = function ($v): array {
        $out = [];
        foreach ((array)$v as $x) { $x = trim((string)$x); if ($x !== '') $out[] = $x; }
        return array_values(array_unique($out));
    };

    switch ($kind) {
        case 'enum': {
            $vals = $list($val);
            if (!$vals) return ['', []];
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $col = $def['column'];
            return $op === 'in'
                ? ["c.{$col} IN ({$ph})", $vals]
                : ["(c.{$col} IS NULL OR c.{$col} NOT IN ({$ph}))", $vals];
        }

        case 'text': {
            $col = $def['column'];
            $v   = trim((string)$val);
            return match ($op) {
                'empty'        => ["(c.{$col} IS NULL OR TRIM(c.{$col}) = '')", []],
                'not_empty'    => ["(c.{$col} IS NOT NULL AND TRIM(c.{$col}) <> '')", []],
                'eq'           => $v === '' ? ['', []] : ["c.{$col} = ?", [$v]],
                'starts'       => $v === '' ? ['', []] : ["c.{$col} LIKE ?", [$v . '%']],
                'not_contains' => $v === '' ? ['', []] : ["(c.{$col} IS NULL OR c.{$col} NOT LIKE ?)", ['%' . $v . '%']],
                default        => $v === '' ? ['', []] : ["c.{$col} LIKE ?", ['%' . $v . '%']],
            };
        }

        case 'tag': {
            $vals = $list($val);
            if (!$vals) return ['', []];
            $ph = implode(',', array_fill(0, count($vals), '?'));
            $sub = "SELECT contact_id FROM crm_tags WHERE tag IN ({$ph})";
            return $op === 'has' ? ["c.id IN ({$sub})", $vals] : ["c.id NOT IN ({$sub})", $vals];
        }

        case 'group': {
            $ids = array_values(array_filter(array_map('intval', (array)$val)));
            if (!$ids) return ['', []];
            $ph = implode(',', array_fill(0, count($ids), '?'));
            // Podgrupy i grupy połączone traktujemy jak w crm_campaign_collect_ids:
            // przynależność do dziecka liczy się jako przynależność do rodzica.
            $sub = "SELECT gm.contact_id FROM crm_group_members gm WHERE gm.group_id IN ({$ph})
                    UNION SELECT gm.contact_id FROM crm_group_members gm
                          JOIN crm_group_links gl ON gl.child_group_id = gm.group_id
                          WHERE gl.parent_group_id IN ({$ph})
                    UNION SELECT gm.contact_id FROM crm_group_members gm
                          JOIN crm_groups g ON g.id = gm.group_id
                          WHERE g.parent_id IN ({$ph})";
            $params = array_merge($ids, $ids, $ids);
            return $op === 'in' ? ["c.id IN ({$sub})", $params] : ["c.id NOT IN ({$sub})", $params];
        }

        case 'days': {
            $d = max(1, min(3650, (int)$val));
            return $op === 'within_days'
                ? ["c.created_at >= datetime('now', ?)", ['-' . $d . ' days']]
                : ["c.created_at <  datetime('now', ?)", ['-' . $d . ' days']];
        }

        case 'event': {
            if ($op === 'bounced') {
                return ["c.id IN (SELECT contact_id FROM crm_campaign_events WHERE type IN ('hard_bounce','soft_bounce','failed'))", []];
            }
            $d    = max(1, min(3650, (int)$val));
            $type = $op === 'clicked' ? 'click' : 'open';
            $sub  = "SELECT contact_id FROM crm_campaign_events
                     WHERE type = ? AND contact_id IS NOT NULL AND occurred_at >= datetime('now', ?)";
            $params = [$type, '-' . $d . ' days'];
            // „NIE otworzył" celowo zawęża do osób, które COŚ dostały: kontakt,
            // do którego nigdy nie pisaliśmy, nie jest „nieaktywny" — jest nowy.
            if ($op === 'not_opened') {
                return [
                    "(c.id IN (SELECT contact_id FROM crm_campaign_recipients WHERE status='sent')
                      AND c.id NOT IN ({$sub}))",
                    $params,
                ];
            }
            return ["c.id IN ({$sub})", $params];
        }

        case 'consent': {
            $pid = (int)$val;
            if ($pid <= 0) return ['', []];
            // Ostatni wpis w crm_consents decyduje o aktualnym stanie zgody.
            $sub = "SELECT contact_id FROM crm_consents cs
                    WHERE cs.purpose_id = ? AND cs.granted = 1
                      AND cs.id = (SELECT MAX(id) FROM crm_consents WHERE contact_id = cs.contact_id AND purpose_id = cs.purpose_id)";
            return $op === 'has' ? ["c.id IN ({$sub})", [$pid]] : ["c.id NOT IN ({$sub})", [$pid]];
        }

        case 'custom_enum':
        case 'custom_text': {
            $did = (int)($def['def_id'] ?? 0);
            if ($did <= 0) return ['', []];
            $base = "SELECT contact_id FROM crm_contact_field_values WHERE field_def_id = ?";
            if ($op === 'empty') {
                return ["c.id NOT IN ({$base} AND TRIM(value) <> '')", [$did]];
            }
            if ($op === 'not_empty') {
                return ["c.id IN ({$base} AND TRIM(value) <> '')", [$did]];
            }
            if ($op === 'in' || $op === 'not_in') {
                $vals = $list($val);
                if (!$vals) return ['', []];
                $ph = implode(',', array_fill(0, count($vals), '?'));
                $sql = "{$base} AND value IN ({$ph})";
                return $op === 'in' ? ["c.id IN ({$sql})", array_merge([$did], $vals)] : ["c.id NOT IN ({$sql})", array_merge([$did], $vals)];
            }
            $v = trim((string)$val);
            if ($v === '') return ['', []];
            $sql = $op === 'eq' ? "{$base} AND value = ?" : "{$base} AND value LIKE ?";
            return ["c.id IN ({$sql})", [$did, $op === 'eq' ? $v : '%' . $v . '%']];
        }
    }

    return ['', []];
}

/** Normalizuje filtr przyjęty z formularza (odsiewa nieznane pola/operatory). */
function crm_segment_sanitize(array $in, int $depth = 0): array {
    if ($depth > 5) return ['op' => 'and', 'rules' => []];
    $fields = crm_segment_fields();
    $ops    = crm_segment_operators();

    $out = ['op' => strtolower((string)($in['op'] ?? 'and')) === 'or' ? 'or' : 'and', 'rules' => []];
    foreach ((array)($in['rules'] ?? []) as $r) {
        if (!is_array($r)) continue;
        if (isset($r['rules'])) { $out['rules'][] = crm_segment_sanitize($r, $depth + 1); continue; }
        $f = (string)($r['field'] ?? '');
        $o = (string)($r['op'] ?? '');
        if (!isset($fields[$f])) continue;
        if (!isset($ops[$fields[$f]['kind']][$o])) continue;
        $v = $r['value'] ?? '';
        $out['rules'][] = ['field' => $f, 'op' => $o, 'value' => is_array($v) ? array_values(array_map('strval', $v)) : (string)$v];
    }
    return $out;
}

/**
 * Zwraca ID kontaktów spełniających filtr. Sam filtr NIE odsiewa opt-outów ani
 * wykluczeń — tym zajmuje się crm_campaign_resolve_recipients(), żeby jedna
 * reguła („nie piszemy do wypisanych") żyła w jednym miejscu.
 */
function crm_segment_resolve_ids(array $filter): array {
    [$where, $params] = crm_segment_compile($filter);
    $sql = "SELECT c.id FROM crm_contacts c WHERE c.crm_active = 1";
    if ($where !== '') $sql .= " AND ({$where})";
    try {
        return array_map(fn($r) => (int)$r['id'], db_all($sql, $params));
    } catch (\Throwable $e) {
        return [];
    }
}

/** Ilu odbiorców realnie dostanie wysyłkę wg tego filtra (po odsianiu wykluczeń). */
function crm_segment_count(array $filter, int $purpose_id = 0): array {
    $ids = crm_segment_resolve_ids($filter);
    $total = count($ids);
    if (!$ids) return ['matched' => 0, 'sendable' => 0, 'skipped' => 0];

    require_once __DIR__ . '/crm_campaign.php';
    $rows = crm_campaign_resolve_recipients('ids', ['contact_ids' => $ids], $purpose_id);
    return ['matched' => $total, 'sendable' => count($rows), 'skipped' => $total - count($rows)];
}

/** Podpis filtra czytelny dla człowieka — do listy kampanii i podglądu segmentu. */
function crm_segment_describe(array $filter): string {
    $fields = crm_segment_fields();
    $ops    = crm_segment_operators();
    $parts  = [];
    foreach ((array)($filter['rules'] ?? []) as $r) {
        if (isset($r['rules'])) { $inner = crm_segment_describe($r); if ($inner !== '') $parts[] = '(' . $inner . ')'; continue; }
        $f = (string)($r['field'] ?? '');
        if (!isset($fields[$f])) continue;
        $kind = $fields[$f]['kind'];
        $opl  = $ops[$kind][(string)($r['op'] ?? '')] ?? '';
        $v    = $r['value'] ?? '';
        $vs   = is_array($v) ? implode(', ', $v) : (string)$v;
        // Grupy i cele zgód pokazujemy nazwą, nie ID.
        if (in_array($kind, ['group', 'consent'], true)) {
            $map = [];
            foreach ($fields[$f]['options'] as $o) $map[$o['value']] = $o['label'];
            $vs = implode(', ', array_map(fn($x) => $map[(string)$x] ?? $x, (array)$v));
        }
        $parts[] = trim($fields[$f]['label'] . ' ' . $opl . ' ' . $vs);
    }
    $glue = strtolower((string)($filter['op'] ?? 'and')) === 'or' ? ' LUB ' : ' oraz ';
    return implode($glue, $parts);
}
