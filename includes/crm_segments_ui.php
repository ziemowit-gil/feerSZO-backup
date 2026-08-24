<?php
/**
 * includes/crm_segments_ui.php — zapisywanie filtrów listy kontaktów jako segment.
 *
 * Tabela `crm_segments` i silnik reguł (includes/crm_segment.php) istniały, ale
 * segment dało się zbudować wyłącznie w kreatorze kampanii. Filtry na liście
 * kontaktów — rozbudowane, z wyszukiwaniem zaawansowanym — trzeba było ustawiać
 * od nowa przy każdym wejściu, a raz znalezionej grupy („darczyńcy z Pomorza bez
 * wysyłki od pół roku") nie dało się zapamiętać.
 *
 * Tu jest tłumaczenie w obie strony:
 *   filtry listy  → drzewo reguł segmentu  (zapis)
 *   segment       → filtry listy           (otwarcie zapisanego widoku)
 *
 * DLACZEGO PRZEZ DRZEWO, A NIE „ZAPISZMY QUERY STRING": zapisany w formacie
 * segmentu filtr działa od razu w kampaniach i wysyłce masowej. Zapamiętany adres
 * URL byłby wygodą tylko na tej jednej stronie.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crm_segment.php';

/**
 * Filtry listy kontaktów → drzewo reguł segmentu.
 *
 * Pomijamy filtry, których silnik segmentów nie zna (np. `q` — pełnotekstowe
 * szukanie po wszystkim, `uslugi`, `action_id`). Zamiast udawać, że zostały
 * uwzględnione, zwracamy je osobno, żeby interfejs mógł o nich uczciwie
 * powiedzieć „tego nie da się zapisać w segmencie".
 *
 * @return array{tree:array, skipped:array<string,string>}
 */
function crm_segment_from_filters(array $f): array
{
    $rules   = [];
    $skipped = [];

    $add = static function (string $field, string $op, $value) use (&$rules): void {
        $rules[] = ['field' => $field, 'op' => $op, 'value' => $value];
    };

    // Proste odwzorowanie 1:1 na pola segmentu
    foreach (['status' => 'status', 'type' => 'type', 'branza' => 'branza',
              'wojewodztwo' => 'wojewodztwo', 'source' => 'source'] as $fk => $field) {
        if (!empty($f[$fk])) $add($field, 'in', [(string)$f[$fk]]);
    }
    foreach (['powiat' => 'powiat', 'gmina' => 'gmina'] as $fk => $field) {
        if (!empty($f[$fk])) $add($field, 'eq', (string)$f[$fk]);
    }
    if (!empty($f['tag']))   $add('tag',   'has', [(string)$f['tag']]);
    if (!empty($f['group'])) $add('group', 'in',  [(string)(int)$f['group']]);

    // Okna czasowe: lista operuje datami, segment liczbą dni. Przeliczamy datę
    // „od" na liczbę dni wstecz — segment ma być stały w czasie, a nie przywiązany
    // do dnia, w którym ktoś go zapisał.
    if (!empty($f['created_from'])) {
        $days = (int)floor((time() - strtotime((string)$f['created_from'])) / 86400);
        if ($days > 0) $add('created', 'within_days', $days);
    }
    if (!empty($f['stale_days'])) $add('campaign', 'not_opened', (int)$f['stale_days']);

    // Filtry bez odpowiednika w silniku segmentów
    $labels = [
        'q'         => 'fraza w wyszukiwarce',
        'q_all'     => 'wyszukiwanie zaawansowane',
        'owner'     => 'opiekun',
        'action_id' => 'działanie',
        'uslugi'    => 'usługi na rzecz FEER',
        'critical'  => 'kontakt krytyczny operacyjnie',
        'has_email' => 'ma e-mail',
        'has_phone' => 'ma telefon',
        'created_to'=> 'data dodania „do"',
        'last_from' => 'ostatni kontakt „od"',
        'last_to'   => 'ostatni kontakt „do"',
    ];
    foreach ($labels as $fk => $label) {
        if (!empty($f[$fk])) $skipped[$fk] = $label;
    }

    return ['tree' => ['op' => 'and', 'rules' => $rules], 'skipped' => $skipped];
}

/**
 * Drzewo reguł segmentu → filtry listy kontaktów.
 *
 * Odwrotność powyższego, na tyle, na ile się da: segment zbudowany w kreatorze
 * kampanii może mieć reguły, których lista nie umie pokazać (OR, historia
 * wysyłek). Wtedy zwracamy to, co się przekłada, a resztę pomijamy — lepiej
 * pokazać zawężenie przybliżone niż pustą listę.
 */
function crm_filters_from_segment(array $tree): array
{
    $out = [];
    foreach ((array)($tree['rules'] ?? []) as $r) {
        if (!is_array($r) || isset($r['rules'])) continue;      // zagnieżdżone grupy pomijamy
        $field = (string)($r['field'] ?? '');
        $op    = (string)($r['op'] ?? '');
        $val   = $r['value'] ?? null;
        $first = is_array($val) ? (string)($val[0] ?? '') : (string)$val;

        switch ($field) {
            case 'status': case 'type': case 'branza': case 'wojewodztwo': case 'source':
                if ($op === 'in' && $first !== '') $out[$field] = $first;
                break;
            case 'powiat': case 'gmina':
                if ($op === 'eq' && $first !== '') $out[$field] = $first;
                break;
            case 'tag':
                if ($op === 'has' && $first !== '') $out['tag'] = $first;
                break;
            case 'group':
                if ($op === 'in' && $first !== '') $out['group'] = (int)$first;
                break;
            case 'created':
                if ($op === 'within_days' && (int)$first > 0) {
                    $out['created_from'] = date('Y-m-d', time() - (int)$first * 86400);
                }
                break;
        }
    }
    return $out;
}

/** Zapisuje segment. Nazwa jest unikalna — powtórzona nadpisuje definicję. */
function crm_segment_save(string $name, array $tree, string $description = '', ?int $id = null): array
{
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'error' => 'Segment musi mieć nazwę.', 'id' => 0];
    if (empty($tree['rules'])) {
        return ['ok' => false, 'error' => 'Segment bez żadnego warunku obejmowałby całą bazę.', 'id' => 0];
    }

    $json  = json_encode($tree, JSON_UNESCAPED_UNICODE);
    $count = count(crm_segment_resolve_ids($tree));

    try {
        if ($id) {
            db()->prepare("UPDATE crm_segments SET name=?, description=?, filter_json=?,
                                  cached_count=?, cached_at=?, updated_at=? WHERE id=?")
                ->execute([$name, trim($description) ?: null, $json, $count,
                           date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
            return ['ok' => true, 'error' => '', 'id' => $id];
        }
        $new = db_insert('crm_segments', [
            'name'         => $name,
            'description'  => trim($description) ?: null,
            'filter_json'  => $json,
            'cached_count' => $count,
            'cached_at'    => date('Y-m-d H:i:s'),
            'created_by'   => (int)(current_user()['id'] ?? 0) ?: null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'error' => '', 'id' => (int)$new];
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) {
            return ['ok' => false, 'error' => 'Segment o tej nazwie już istnieje.', 'id' => 0];
        }
        error_log('[crm_segment_save] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Nie udało się zapisać segmentu.', 'id' => 0];
    }
}

/** @return array<int,array> Zapisane segmenty, najświeższe pierwsze. */
function crm_segments_list(): array
{
    try {
        return db_all("SELECT s.*, u.name AS author
                         FROM crm_segments s LEFT JOIN users u ON u.id = s.created_by
                     ORDER BY s.updated_at DESC, s.id DESC LIMIT 100");
    } catch (\Throwable $e) { return []; }
}

function crm_segment_get(int $id): ?array
{
    try { return db_one("SELECT * FROM crm_segments WHERE id=?", [$id]) ?: null; }
    catch (\Throwable $e) { return null; }
}

function crm_segment_delete(int $id): bool
{
    try { db()->prepare("DELETE FROM crm_segments WHERE id=?")->execute([$id]); return true; }
    catch (\Throwable $e) { return false; }
}

/**
 * Odświeża zapisaną liczebność.
 *
 * Segment jest dynamiczny — liczba z chwili zapisu po miesiącu jest nieprawdziwa.
 * Liczymy na żądanie, a nie przy każdym wyświetleniu listy: to zapytanie po całej
 * bazie kontaktów.
 */
function crm_segment_refresh_count(int $id): int
{
    $s = crm_segment_get($id);
    if (!$s) return 0;
    $tree = json_decode((string)$s['filter_json'], true) ?: [];
    $n = count(crm_segment_resolve_ids($tree));
    try {
        db()->prepare("UPDATE crm_segments SET cached_count=?, cached_at=? WHERE id=?")
            ->execute([$n, date('Y-m-d H:i:s'), $id]);
    } catch (\Throwable $e) {}
    return $n;
}

/* Opis filtra dla człowieka mieszka w includes/crm_segment.php
   (crm_segment_describe) — obsługuje też zagnieżdżone grupy i LUB. Druga wersja
   tutaj rozjeżdżałaby się z pierwszą przy każdej zmianie katalogu pól. */
