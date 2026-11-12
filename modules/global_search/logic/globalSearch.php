<?php
/**
 * modules/global_search/logic/globalSearch.php — globalna wyszukiwarka osób i umów (funkcjonalność współdzielona).
 *
 * Używają jej: paleta Ctrl+K w nagłówku (api/search_quick.php) i strona pełnych wyników (search.php).
 *
 * Co rozpoznaje w zapytaniu (GlobalSearch::parse):
 *   • PESEL       — dokładnie 11 cyfr. PESEL bywa zaszyfrowany (modules/field_crypto), więc szukamy TYLKO pełnego
 *                   numeru przez indeks ślepy (fcMatchPesel), nigdy fragmentu;
 *   • telefon     — same cyfry/spacje/+/-/(), od 6 cyfr; porównanie po cyfrach, prefiks 48/0048 odcinany
 *                   (w bazie są zapisy „+48 535 23 1923” i „48535231923”);
 *   • e-mail      — zapytanie z „@” (albo jedno słowo bez spacji — fragment adresu);
 *   • numer umowy — zapytanie z cyfrą: numer_umowy, nr_rejestru (RU/…), nr_roboczy, nr_system, numer WebNGO;
 *   • nazwisko    — tekst; każde słowo musi pasować (kolejność dowolna: „Kowalski Jan” = „Jan Kowalski”).
 *
 * Źródła i uprawnienia (każde źródło sprawdza je samo — wyszukiwarka nie może być obejściem uprawnień modułów):
 *   • umowy (8 typów)  — can_read/can_write('umowy'), typ włączony (contract_{typ}), ukryte pominięte
 *                        (contractHiddenSql), zawężony dostęp do umowy (contract_can_access);
 *   • strony umów      — rola admin/editor (jak persons/view.php);
 *   • kontakty CRM     — moduł włączony + crm_can('contacts','read') + dostęp do grupy kontaktu;
 *   • uczestnicy TI    — jak k30_require_access(): admin, can_read('karty30') albo doradca (k30_consultant);
 *   • konta w systemie — tylko administrator.
 * Pełnego PESEL-u nie pokazujemy nigdy (fcMask); każde szukanie po PESEL-u trafia do dziennika audytu (zamaskowane).
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/auth.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';

final class GlobalSearch
{
    /** Typy umów: krótka etykieta plakietki i ikona. Kolejność = kolejność źródeł. */
    private const CONTRACT_META = [
        'zlecenie'    => ['badge' => 'Zlecenie',    'icon' => 'bi-file-earmark-person'],
        'wolontariat' => ['badge' => 'Wolontariat', 'icon' => 'bi-heart'],
        'dzielo'      => ['badge' => 'Dzieło',      'icon' => 'bi-file-earmark-text'],
        'praca'       => ['badge' => 'Praca',       'icon' => 'bi-briefcase'],
        'uslugi'      => ['badge' => 'Usługi',      'icon' => 'bi-tools'],
        'szk'         => ['badge' => 'SZK',         'icon' => 'bi-mortarboard'],
        'inne'        => ['badge' => 'Inna',        'icon' => 'bi-file-earmark'],
        'powierzenie' => ['badge' => 'Powierzenie', 'icon' => 'bi-building'],
    ];

    /** Kolumny kandydujące w tabelach umowy_* (bierzemy te, które w danej tabeli istnieją) → opis dopasowania. */
    private const CONTRACT_NAME   = ['imie_nazwisko' => 'nazwisko', 'nazwa_wykonawcy' => 'wykonawca', 'strona_umowy' => 'strona umowy',
                                     'platnik_nazwa' => 'płatnik', 'rodzic_imie_nazwisko' => 'nazwisko rodzica'];
    private const CONTRACT_PESEL  = ['pesel' => 'PESEL', 'nip_pesel' => 'PESEL', 'pesel_nip_krs' => 'PESEL'];
    private const CONTRACT_EMAIL  = ['email' => 'e-mail', 'email_login' => 'e-mail służbowy', 'rodzic_email' => 'e-mail rodzica'];
    private const CONTRACT_PHONE  = ['telefon' => 'telefon', 'rodzic_telefon' => 'telefon rodzica'];
    private const CONTRACT_NUMBER = ['nr_rejestru' => 'nr rejestru', 'numer_umowy' => 'nr umowy', 'nr_roboczy' => 'nr roboczy',
                                     'nr_system' => 'nr systemowy', 'webngo_numer_umowy' => 'nr WebNGO'];

    /** @var array<string, string[]> */
    private static array $columnCache = [];

    // ─────────────────────────────── Rozpoznanie zapytania ───────────────────────────────

    /**
     * @return array{q:string, kind:string, pesel:?string, phone:?string, email:?string, number:?string, name:?string, tokens:string[], hint:string}
     *   kind: pesel | phone | email | number | name | '' (za krótkie).
     */
    public static function parse(string $q): array
    {
        $q = trim((string)preg_replace('/\s+/u', ' ', $q));
        $out = ['q' => $q, 'kind' => '', 'pesel' => null, 'phone' => null, 'email' => null, 'number' => null,
                'name' => null, 'tokens' => [], 'hint' => ''];
        if (mb_strlen($q) < 2) return $out;

        $digits    = (string)preg_replace('/\D/', '', $q);
        $phoneLike = (bool)preg_match('/^[\d\s+\-().\/]+$/', $q);
        $hasLetter = (bool)preg_match('/\p{L}/u', $q);

        if ($phoneLike && strlen($digits) === 11 && !str_starts_with($q, '+')) {
            $out['pesel'] = $digits;
            $out['kind'] = 'pesel';
        }
        if ($phoneLike && strlen($digits) >= 6 && strlen($digits) <= 13 && !str_contains($q, '/')) {
            $national = $digits;
            if (strlen($national) === 13 && str_starts_with($national, '0048')) $national = substr($national, 4);
            elseif (strlen($national) === 11 && str_starts_with($national, '48')) $national = substr($national, 2);
            if (strlen($national) >= 6 && strlen($national) <= 11) {
                $out['phone'] = $national;
                if ($out['kind'] === '') $out['kind'] = 'phone';
            }
        }
        if (str_contains($q, '@')) {
            $out['email'] = mb_strtolower(str_replace(' ', '', $q));
            $out['kind'] = 'email';
            return $out;
        }
        if (preg_match('/\d/', $q)) {
            $out['number'] = $q;
            if ($out['kind'] === '') $out['kind'] = 'number';
        }
        if ($hasLetter && $digits === '') {
            $tokens = array_values(array_filter(explode(' ', $q), fn($t) => mb_strlen($t) >= 2));
            $out['tokens'] = array_slice($tokens ?: [$q], 0, 4);
            $out['name'] = $q;
            $out['kind'] = 'name';
            // Jedno słowo bez spacji może być fragmentem adresu e-mail („kowalski”, „jan.kowalski”).
            if (!str_contains($q, ' ')) $out['email'] = mb_strtolower($q);
        }

        // Fragment PESEL-u nie zadziała (zaszyfrowany — tylko pełny numer). Podpowiedź przy 10 cyfrach od razu,
        // przy 6–9 dopiero gdy nic się nie znajdzie (9 cyfr to zwykle telefon) — patrz run().
        if ($phoneLike && !str_starts_with($q, '+') && strlen($digits) >= 6 && strlen($digits) <= 10 && !str_contains($q, '/')) {
            $out['hint'] = strlen($digits) === 10 ? 'pesel' : 'pesel-if-empty';
        }
        return $out;
    }

    // ─────────────────────────────── Wyszukiwanie ───────────────────────────────

    /**
     * Szuka we wszystkich źródłach, do których zalogowany ma dostęp.
     * $perSource — limit trafień z jednego źródła; $perGroup — limit pozycji w grupie (Osoby / Umowy).
     * @return array{query:string, kind:string, hint:string, total:int, groups:array<int, array{key:string, label:string, icon:string, items:array}>}
     */
    public static function run(string $q, int $perSource = 5, int $perGroup = 8, string $via = 'paleta'): array
    {
        $p = self::parse($q);
        $res = ['query' => $p['q'], 'kind' => $p['kind'], 'hint' => '', 'total' => 0, 'groups' => []];
        if ($p['kind'] === '' || !current_user()) return $res;

        $people = [];
        $contracts = [];
        foreach (self::contractSlugs() as $slug) {
            foreach (self::searchContracts($slug, $p, $perSource) as $hit) $contracts[] = $hit;
        }
        foreach (['searchPersons', 'searchCrm', 'searchTi', 'searchUsers'] as $fn) {
            foreach (self::$fn($p, $perSource) as $hit) $people[] = $hit;
        }

        $groups = [
            'osoby' => ['key' => 'osoby', 'label' => 'Osoby', 'icon' => 'bi-people', 'items' => self::rank($people, $perGroup)],
            'umowy' => ['key' => 'umowy', 'label' => 'Umowy', 'icon' => 'bi-file-earmark-text', 'items' => self::rank($contracts, $perGroup)],
        ];
        // Numer umowy → najpierw umowy; PESEL / telefon / e-mail / nazwisko → najpierw osoby.
        $order = $p['kind'] === 'number' ? ['umowy', 'osoby'] : ['osoby', 'umowy'];
        foreach ($order as $k) {
            if ($groups[$k]['items']) {
                $res['groups'][] = $groups[$k];
                $res['total'] += count($groups[$k]['items']);
            }
        }

        if ($p['hint'] === 'pesel' || ($p['hint'] === 'pesel-if-empty' && $res['total'] === 0)) {
            $res['hint'] = 'PESEL wyszukujemy tylko w całości (11 cyfr) — wpisz pełny numer.';
        } else {
            $res['hint'] = '';
        }

        if ($p['pesel'] !== null) self::auditPesel($p['pesel'], $res['total'], $via);
        return $res;
    }

    /** Spłaszczone wyniki (paleta Ctrl+K): każda pozycja z kluczem group/groupLabel. */
    public static function flat(array $res): array
    {
        $out = [];
        foreach ($res['groups'] as $g) {
            foreach ($g['items'] as $it) $out[] = $it + ['group' => $g['key'], 'groupLabel' => $g['label']];
        }
        return $out;
    }

    // ─────────────────────────────── Źródła: umowy ───────────────────────────────

    /** @return string[] typy umów włączone i dostępne dla zalogowanego (pusta — brak prawa do umów). */
    private static function contractSlugs(): array
    {
        // Viewer nie otworzy cudzej umowy (viewer_owns_contract w view.php) — nawet z osobistym uprawnieniem.
        if (is_viewer() || !self::allowed('umowy')) return [];
        $out = [];
        foreach (array_keys(self::CONTRACT_META) as $slug) {
            if (!isset(CONTRACT_TYPES[$slug]) || !module_enabled('contract_' . $slug)) continue;
            $out[] = $slug;
        }
        return $out;
    }

    private static function searchContracts(string $slug, array $p, int $limit): array
    {
        $table = 'umowy_' . $slug;
        $cols  = self::columns(db(), $table);
        if (!$cols || !in_array('id', $cols, true)) return [];

        $pick = fn(array $cands) => array_intersect_key($cands, array_flip($cols));
        $nameCols   = $pick(self::CONTRACT_NAME);
        $peselCols  = $pick(self::CONTRACT_PESEL);
        $emailCols  = $pick(self::CONTRACT_EMAIL);
        $phoneCols  = $pick(self::CONTRACT_PHONE);
        $numberCols = $pick(self::CONTRACT_NUMBER);
        $partyCol   = CONTRACT_REGISTRY_FIELDS[$slug]['name_col'] ?? 'imie_nazwisko';
        if (!in_array($partyCol, $cols, true)) $partyCol = array_key_first($nameCols) ?? 'numer_umowy';

        [$where, $params] = self::conditions($table, $p, $nameCols, $peselCols, $emailCols, $phoneCols, $numberCols);
        if ($where === '') return [];

        $select = array_unique(array_merge(['id', $partyCol], array_keys($nameCols), array_keys($peselCols),
            array_keys($emailCols), array_keys($phoneCols), array_keys($numberCols),
            array_intersect(['status', 'data_od', 'data_do', 'data_zawarcia'], $cols)));
        $sql = 'SELECT ' . implode(', ', array_map(fn($c) => "`{$c}`", $select)) . " FROM `{$table}` WHERE ({$where})"
             . contractHiddenSql($table) . ' ORDER BY id DESC LIMIT ' . ($limit * 3);
        try { $rows = db_all($sql, $params); } catch (\Throwable $e) { return []; }

        require_once dirname(__DIR__, 3) . '/includes/contract_access.php';
        require_once dirname(__DIR__, 3) . '/modules/contract_status/lib/contractStatuses.php';

        $meta = self::CONTRACT_META[$slug];
        $hits = [];
        foreach ($rows as $r) {
            if (!contract_can_access($slug, $r)) continue;
            [$score, $match, $matchCol] = self::scoreRow($r, $p, $nameCols, $peselCols, $emailCols, $phoneCols, $numberCols);
            $party   = trim((string)($r[$partyCol] ?? ''));
            $numbers = array_values(array_filter([trim((string)($r['nr_rejestru'] ?? '')), trim((string)($r['numer_umowy'] ?? ''))]));
            $isNumberHit = isset($numberCols[$matchCol]);
            $label = $isNumberHit ? (string)$r[$matchCol] : ($party !== '' ? $party : ($numbers[0] ?? '#' . $r['id']));
            $sub = [];
            if ($isNumberHit) { if ($party !== '') $sub[] = $party; }
            elseif ($numbers) $sub[] = implode(' · ', $numbers);
            $sub[] = CONTRACT_TYPES[$slug];
            if (!empty($r['status'])) {
                try { $sub[] = contractStatusLabel((string)$r['status']); } catch (\Throwable $e) { $sub[] = (string)$r['status']; }
            }
            $hits[] = [
                'label' => $label,
                'sub'   => implode(' · ', $sub),
                'match' => self::matchText($match, $matchCol, $r),
                'icon'  => $meta['icon'],
                'badge' => $meta['badge'],
                'url'   => APP_URL . '/contracts/' . $slug . '/view.php?id=' . (int)$r['id'],
                'score' => $score,
                'type'  => 'data',
            ];
            if (count($hits) >= $limit) break;
        }
        return $hits;
    }

    // ─────────────────────────────── Źródła: osoby ───────────────────────────────

    /** Strony umów (tabela persons) — dostęp jak persons/view.php: admin / editor. */
    private static function searchPersons(array $p, int $limit): array
    {
        $role = current_user()['role'] ?? '';
        if (!in_array($role, ['admin', 'editor'], true)) return [];
        return self::searchPeople(db(), 'persons', $p, $limit, [
            'name' => ['imie_nazwisko' => 'nazwisko'], 'pesel' => ['pesel' => 'PESEL'],
            'email' => ['email' => 'e-mail'], 'phone' => ['telefon' => 'telefon'],
            'labelCol' => 'imie_nazwisko', 'extraWhere' => '',
            'badge' => 'Strona umowy', 'icon' => 'bi-person-vcard', 'source' => 'Strony umów',
            'url' => fn($r) => APP_URL . '/persons/view.php?id=' . (int)$r['id'],
        ]);
    }

    /** Kontakty CRM — moduł włączony, rola z prawem odczytu kontaktów, dostęp do grupy kontaktu. */
    private static function searchCrm(array $p, int $limit): array
    {
        if (!module_enabled('crm_enabled') || !self::allowed('crm')) return [];
        require_once dirname(__DIR__, 3) . '/includes/crm.php';
        if (!function_exists('crm_can') || !crm_can('contacts', 'read')) return [];
        $pdo = function_exists('crm_db') ? crm_db() : db();
        $cols = self::columns($pdo, 'crm_contacts');
        $extra = (in_array('crm_active', $cols, true) ? ' AND COALESCE(crm_active,1)=1' : '')
               . (in_array('anonymized_at', $cols, true) ? ' AND anonymized_at IS NULL' : '');
        $hits = self::searchPeople($pdo, 'crm_contacts', $p, $limit * 3, [
            'name' => ['imie_nazwisko' => 'nazwisko', 'nazwisko' => 'nazwisko', 'imie' => 'imię'],
            'pesel' => ['pesel' => 'PESEL'], 'email' => ['email' => 'e-mail'], 'phone' => ['telefon' => 'telefon'],
            'labelCol' => 'imie_nazwisko', 'extraWhere' => $extra,
            'badge' => 'CRM', 'icon' => 'bi-person-lines-fill', 'source' => 'Kontakt CRM',
            'url' => fn($r) => APP_URL . '/crm/contact/view.php?id=' . (int)$r['id'],
        ]);
        if (function_exists('crm_can_access_contact')) {
            $hits = array_values(array_filter($hits, fn($h) => crm_can_access_contact((int)$h['_id'])));
        }
        return array_slice($hits, 0, $limit);
    }

    /** Uczestnicy TI (k30_clients) — ta sama zasada co k30_require_access(). */
    private static function searchTi(array $p, int $limit): array
    {
        if (!self::canSeeTi()) return [];
        return self::searchPeople(db(), 'k30_clients', $p, $limit, [
            'name' => ['name' => 'nazwisko'], 'pesel' => ['pesel' => 'PESEL'],
            'email' => ['email' => 'e-mail'], 'phone' => ['phone' => 'telefon'],
            'labelCol' => 'name', 'extraWhere' => '',
            'badge' => 'Uczestnik TI', 'icon' => 'bi-mortarboard', 'source' => 'Uczestnik TI',
            'url' => fn($r) => APP_URL . '/karty30/clients/view.php?id=' . (int)$r['id'],
        ]);
    }

    /** Konta w systemie (users) — tylko administrator; prowadzi do listy użytkowników w Tożsamości. */
    private static function searchUsers(array $p, int $limit): array
    {
        if (!is_admin()) return [];
        $cols = self::columns(db(), 'users');
        return self::searchPeople(db(), 'users', $p, $limit, [
            'name' => ['name' => 'nazwisko', 'last_name' => 'nazwisko', 'first_name' => 'imię'],
            'pesel' => ['rpts_pesel' => 'PESEL'],
            'email' => ['email' => 'e-mail', 'alt_email' => 'e-mail dodatkowy'],
            'phone' => ['phone_number' => 'telefon', 'alt_phone_number' => 'telefon dodatkowy'],
            'labelCol' => 'name', 'extraWhere' => '',
            'extraSelect' => array_values(array_intersect(['is_active', 'role'], $cols)),
            'badge' => 'Konto', 'icon' => 'bi-person-badge', 'source' => 'Konto w systemie',
            'url' => fn($r) => APP_URL . '/tozsamosc/uzytkownicy.php?q=' . rawurlencode((string)($r['email'] ?? $r['name'] ?? '')),
            'subExtra' => fn($r) => array_key_exists('is_active', $r) && !(int)$r['is_active'] ? 'konto nieaktywne' : '',
        ]);
    }

    /** Wspólne szukanie w tabeli z osobami. $cfg: name/pesel/email/phone (kolumna → opis), labelCol, badge, icon, url(), … */
    private static function searchPeople(\PDO $pdo, string $table, array $p, int $limit, array $cfg): array
    {
        $cols = self::columns($pdo, $table);
        if (!$cols || !in_array('id', $cols, true)) return [];
        $pick = fn(array $cands) => array_intersect_key($cands, array_flip($cols));
        $nameCols = $pick($cfg['name']); $peselCols = $pick($cfg['pesel']);
        $emailCols = $pick($cfg['email']); $phoneCols = $pick($cfg['phone']);

        [$where, $params] = self::conditions($table, $p, $nameCols, $peselCols, $emailCols, $phoneCols, []);
        if ($where === '') return [];

        $labelCol = in_array($cfg['labelCol'], $cols, true) ? $cfg['labelCol'] : (array_key_first($nameCols) ?? 'id');
        $select = array_unique(array_merge(['id', $labelCol], array_keys($nameCols), array_keys($peselCols),
            array_keys($emailCols), array_keys($phoneCols), $cfg['extraSelect'] ?? []));
        $sql = 'SELECT ' . implode(', ', array_map(fn($c) => "`{$c}`", $select)) . " FROM `{$table}` WHERE ({$where}){$cfg['extraWhere']}"
             . ' ORDER BY id DESC LIMIT ' . ($limit * 2);
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { return []; }

        $hits = [];
        foreach ($rows as $r) {
            [$score, $match, $matchCol] = self::scoreRow($r, $p, $nameCols, $peselCols, $emailCols, $phoneCols, []);
            $label = trim((string)($r[$labelCol] ?? ''));
            if ($label === '' && isset($r['first_name'])) $label = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            if ($label === '') $label = trim((string)($r['email'] ?? '')) ?: '#' . $r['id'];
            $sub = [$cfg['source']];
            foreach (array_keys($emailCols) as $c) { if (!empty($r[$c])) { $sub[] = (string)$r[$c]; break; } }
            foreach (array_keys($phoneCols) as $c) { if (!empty($r[$c])) { $sub[] = (string)$r[$c]; break; } }
            if (isset($cfg['subExtra']) && ($x = ($cfg['subExtra'])($r)) !== '') $sub[] = $x;
            $hits[] = [
                'label' => $label,
                'sub'   => implode(' · ', $sub),
                'match' => self::matchText($match, $matchCol, $r),
                'icon'  => $cfg['icon'],
                'badge' => $cfg['badge'],
                'url'   => ($cfg['url'])($r),
                'score' => $score,
                'type'  => 'data',
                '_id'   => (int)$r['id'],
            ];
            if (count($hits) >= $limit) break;
        }
        return $hits;
    }

    // ─────────────────────────────── Warunki SQL i ocena trafień ───────────────────────────────

    /** Buduje WHERE (alternatywa po rodzajach dopasowania). @return array{0:string, 1:array} */
    private static function conditions(string $table, array $p, array $nameCols, array $peselCols, array $emailCols, array $phoneCols, array $numberCols): array
    {
        $or = [];
        $params = [];
        if ($p['pesel'] !== null) {
            foreach (array_keys($peselCols) as $c) {
                [$sql, $pp] = fcMatchPesel($table, $c, $p['pesel']);
                $or[] = $sql;
                array_push($params, ...$pp);
            }
        }
        if ($p['phone'] !== null) {
            foreach (array_keys($phoneCols) as $c) {
                $or[] = self::digitsExpr($c) . " LIKE ?";
                $params[] = '%' . $p['phone'] . '%';
            }
        }
        if ($p['email'] !== null) {
            foreach (array_keys($emailCols) as $c) {
                $or[] = "LOWER(`{$c}`) LIKE ? ESCAPE '\\'";
                $params[] = '%' . self::likeEscape($p['email']) . '%';
            }
        }
        if ($p['number'] !== null) {
            foreach (array_keys($numberCols) as $c) {
                $or[] = "`{$c}` LIKE ? ESCAPE '\\'";
                $params[] = '%' . self::likeEscape($p['number']) . '%';
            }
        }
        if ($p['tokens'] && $nameCols) {
            $and = [];
            foreach ($p['tokens'] as $t) {
                $tokOr = [];
                foreach (array_keys($nameCols) as $c) {
                    foreach (self::caseVariants($t) as $v) {
                        $tokOr[] = "`{$c}` LIKE ? ESCAPE '\\'";
                        $params[] = '%' . self::likeEscape($v) . '%';
                    }
                }
                $and[] = '(' . implode(' OR ', $tokOr) . ')';
            }
            // Wiele słów: każde musi pasować — ale wystarczy, że w JAKIEJKOLWIEK kolumnie nazwiska (imię w `imie`, nazwisko w `nazwisko`).
            $or[] = '(' . implode(' AND ', $and) . ')';
        }
        return [$or ? implode(' OR ', $or) : '', $params];
    }

    /** Ocena trafienia: [score, rodzaj dopasowania, kolumna]. */
    private static function scoreRow(array $r, array $p, array $nameCols, array $peselCols, array $emailCols, array $phoneCols, array $numberCols): array
    {
        $best = [0, '', ''];
        $take = function (int $s, string $kind, string $col) use (&$best) { if ($s > $best[0]) $best = [$s, $kind, $col]; };

        if ($p['pesel'] !== null) {
            foreach (array_keys($peselCols) as $c) {
                if (preg_replace('/\D/', '', (string)($r[$c] ?? '')) === $p['pesel']) $take(100, 'pesel', $c);
            }
        }
        if ($p['number'] !== null) {
            $n = mb_strtolower($p['number']);
            foreach (array_keys($numberCols) as $c) {
                $v = mb_strtolower(trim((string)($r[$c] ?? '')));
                if ($v === '') continue;
                if ($v === $n) $take(96, 'number', $c);
                elseif (str_starts_with($v, $n)) $take(82, 'number', $c);
                elseif (str_contains($v, $n)) $take(62, 'number', $c);
            }
        }
        if ($p['email'] !== null) {
            foreach (array_keys($emailCols) as $c) {
                $v = mb_strtolower(trim((string)($r[$c] ?? '')));
                if ($v === '') continue;
                if ($v === $p['email']) $take(95, 'email', $c);
                elseif (str_contains($v, $p['email'])) $take(str_contains($p['email'], '@') ? 72 : 50, 'email', $c);
            }
        }
        if ($p['phone'] !== null) {
            foreach (array_keys($phoneCols) as $c) {
                $d = preg_replace('/\D/', '', (string)($r[$c] ?? ''));
                if ($d === '') continue;
                if (str_ends_with($d, $p['phone']) && strlen($p['phone']) >= 9) $take(90, 'phone', $c);
                elseif (str_contains($d, $p['phone'])) $take(66, 'phone', $c);
            }
        }
        if ($p['tokens']) {
            $q = mb_strtolower($p['q']);
            foreach (array_keys($nameCols) as $c) {
                $v = mb_strtolower(trim((string)($r[$c] ?? '')));
                if ($v === '') continue;
                $words = preg_split('/[\s\-]+/u', $v) ?: [];
                if ($v === $q) { $take(92, 'name', $c); continue; }
                $found = 0; $prefix = true;
                foreach ($p['tokens'] as $t) {
                    $t = mb_strtolower($t);
                    if (!str_contains($v, $t)) { $prefix = false; continue; }
                    $found++;
                    $hasPrefix = false;
                    foreach ($words as $w) if (str_starts_with($w, $t)) { $hasPrefix = true; break; }
                    $prefix = $prefix && $hasPrefix;
                }
                if ($found === count($p['tokens'])) $take($prefix ? 78 : 56, 'name', $c);
                elseif ($found > 0) $take(40, 'name', $c); // słowa rozłożone na kolumny imię / nazwisko
            }
        }
        return $best;
    }

    private static function matchText(string $kind, string $col, array $r): string
    {
        $labels = self::CONTRACT_NAME + self::CONTRACT_PESEL + self::CONTRACT_EMAIL + self::CONTRACT_PHONE + self::CONTRACT_NUMBER
                + ['nazwisko' => 'nazwisko', 'imie' => 'imię', 'name' => 'nazwisko', 'first_name' => 'imię', 'last_name' => 'nazwisko',
                   'rpts_pesel' => 'PESEL', 'alt_email' => 'e-mail dodatkowy', 'phone' => 'telefon',
                   'phone_number' => 'telefon', 'alt_phone_number' => 'telefon dodatkowy'];
        return match ($kind) {
            'pesel'  => 'PESEL ' . (function_exists('fcMask') ? fcMask(preg_replace('/\D/', '', (string)($r[$col] ?? '')), 'digits') : '•••'),
            ''       => '',
            default  => $labels[$col] ?? $col,
        };
    }

    /** Sortuje po ocenie (malejąco), potem alfabetycznie; usuwa pola techniczne. */
    private static function rank(array $hits, int $limit): array
    {
        usort($hits, fn($a, $b) => [$b['score'], $a['label']] <=> [$a['score'], $b['label']]);
        $hits = array_slice($hits, 0, $limit);
        foreach ($hits as &$h) unset($h['_id']);
        return $hits;
    }

    // ─────────────────────────────── Pomocnicze ───────────────────────────────

    /**
     * Prawo odczytu modułu. Rola „viewer” (wolontariusze, uczestnicy — „Mój panel”) ma w DOMYŚLNYCH uprawnieniach roli
     * odczyt m.in. umów, CRM i Dydaktyki, ale strony i tak blokują jej cudze dane (viewer_owns_contract, is_viewer() na
     * listach). Wyszukiwarka nie może być furtką do PESEL-i, więc u viewera liczą się tylko uprawnienia nadane osobiście.
     */
    private static function allowed(string $module): bool
    {
        if (is_admin()) return true;
        if (!is_viewer()) return can_read($module) || can_write($module);
        $up = user_permissions((int)(current_user()['id'] ?? 0));
        return !empty($up[$module]['can_read']) || !empty($up[$module]['can_write']);
    }

    /** Uprawnienie do danych uczestników TI — jak k30_require_access() (bez przekierowań), z zasadą allowed() dla viewera. */
    private static function canSeeTi(): bool
    {
        if (self::allowed('karty30')) return true;
        try {
            $row = db_one("SELECT k30_consultant FROM users WHERE id=?", [(int)(current_user()['id'] ?? 0)]);
            return !empty($row['k30_consultant']);
        } catch (\Throwable $e) { return false; }
    }

    /** Wyrażenie SQL: same cyfry kolumny (telefony zapisane ze spacjami, myślnikami, +48, nawiasami). */
    private static function digitsExpr(string $col): string
    {
        $e = "COALESCE(`{$col}`,'')";
        foreach ([' ', '-', '+', '(', ')', '.', '/'] as $ch) $e = "REPLACE({$e},'{$ch}','')";
        return $e;
    }

    /**
     * Warianty wielkości liter. LIKE w SQLite ignoruje wielkość liter tylko dla ASCII, więc „łukasz” nie znalazłoby
     * „Łukasz” — dokładamy wersję małą, wielką i z wielkiej litery (MySQL z utf8mb4_unicode_ci i tak porówna bez nich).
     */
    private static function caseVariants(string $t): array
    {
        return array_values(array_unique([$t, mb_strtolower($t), mb_convert_case($t, MB_CASE_TITLE), mb_strtoupper($t)]));
    }

    private static function likeEscape(string $v): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);
    }

    /** Kolumny tabeli w danym połączeniu (pusta tablica, gdy tabeli nie ma). */
    private static function columns(\PDO $pdo, string $table): array
    {
        $key = spl_object_id($pdo) . ':' . $table;
        if (isset(self::$columnCache[$key])) return self::$columnCache[$key];
        if (!preg_match('/^[a-z0-9_]+$/', $table)) return [];
        try {
            $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $cols = $driver === 'sqlite'
                ? array_column($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(\PDO::FETCH_ASSOC), 'name')
                : $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) { $cols = []; }
        return self::$columnCache[$key] = $cols;
    }

    /** Dziennik audytu: kto i kiedy szukał po PESEL-u (numer zamaskowany, liczba wyników). */
    private static function auditPesel(string $pesel, int $total, string $via): void
    {
        try {
            require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';
            audit_log('search.pesel', [
                'pesel'  => function_exists('fcMask') ? fcMask($pesel, 'digits') : '•••',
                'wyniki' => $total,
                'gdzie'  => $via,
            ]);
        } catch (\Throwable $e) {}
    }
}
