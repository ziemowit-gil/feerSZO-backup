<?php
/**
 * modules/ti_consultations/logic/consultations.php — Karty konsultacji w panelu dydaktyka (przeniesione z karty30/consultations).
 *
 * Tabela: k30_consultations (bez zmian w znaczeniu pól) + session_id, course_id, card_no.
 * Numer karty: KON.XXX.MM.RRRR — XXX kolejny w miesiącu konsultacji (od 001), MM miesiąc, RRRR rok; nadawany przy utworzeniu.
 *   • karta samodzielna („konsultacja normalna”) — bez terminu grupy;
 *   • karta terminu szkolenia krótkiego SzK — jedna na uczestnika i termin; zapis kart terminu uzupełnia dokumentację lekcji
 *     (temat, obecność, czas trwania, status „odbyła się”).
 * Zatwierdzenie jak dotąd: kod IKA (cpc_verify) + ważny certyfikat x509 doradcy (k30_verify_consultant_cert), SHA1 z danych
 * karty, zużyte godziny beneficjenta (k30_clients.used), wpis w CRM. Zatwierdzona karta samodzielna trafia do EZD od razu; karty grupy
 * SzK — przy zamknięciu grupy (ręcznie albo automatycznie po zatwierdzeniu ostatniej karty, gdy wszystkie terminy minęły).
 * EZD: JRWA 381 „Doradztwo i konsultacje tyfloinformatyczne”, segregator roczny, koszulka na kartę samodzielną / na grupę SzK.
 * Wyłącznik: org_setting('ti_consultations_ezd_auto') = '0'.
 */
require_once dirname(__DIR__, 3) . '/includes/karty30.php';

final class TiConsultations
{
    public const NO_RE   = '/^KON\.\d{3,}\.\d{2}\.\d{4}$/';
    public const EZD_JRWA = '381';
    private const HELD = ['held', 'individual_change', 'remote_material'];

    public static function statuses(): array { return K30_CONSULTATION_STATUSES; }

    /** Etapy karty: Robocza → Podpisana (konsultant, IKA + x509) → Zatwierdzona przez kierownika; albo Anulowana. */
    public const STAGES = [
        'draft'     => ['Robocza', 'secondary'],
        'signed'    => ['Podpisana', 'primary'],
        'approved'  => ['Zatwierdzona przez kierownika', 'success'],
        'cancelled' => ['Anulowana', 'danger'],
    ];

    public static function stage(array $k): string
    {
        if (($k['status'] ?? '') === 'cancelled') return 'cancelled';
        if (($k['status'] ?? '') !== 'completed') return 'draft';
        return !empty($k['mgr_approved_at']) ? 'approved' : 'signed';
    }

    public static function stageBadge(array $k): string
    {
        [$l, $c] = self::STAGES[self::stage($k)];
        return '<span class="badge text-bg-' . $c . '">' . htmlspecialchars($l, ENT_QUOTES, 'UTF-8') . '</span>';
    }

    /**
     * Etap 3: zatwierdzenie podpisanych kart przez kierownika. Karta samodzielna trafia wtedy do EZD; szkolenie krótkie SzK
     * zamyka się samo, gdy wszystkie jego karty są zatwierdzone, a terminy rozliczone.
     * @return array{approved:int[], closed:?string}
     */
    public static function managerApprove(array $ids, int $uid, string $name): array
    {
        $done = []; $courses = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $k = self::get($id);
            if (!$k || self::stage($k) !== 'signed') continue;
            db()->prepare("UPDATE k30_consultations SET mgr_approved_by=?, mgr_approved_name=?, mgr_approved_at=datetime('now'), updated_at=datetime('now') WHERE id=? AND mgr_approved_at IS NULL")
               ->execute([$uid ?: null, $name, $id]);
            $done[] = $id;
            if ((int)$k['course_id'] > 0) $courses[(int)$k['course_id']] = true;
            elseif (self::ezdActive()) self::ezdCard($id, $uid);
        }
        $closed = null;
        foreach (array_keys($courses) as $cid) {
            if (self::groupClosable($cid)['ok']) { self::closeGroup($cid, $uid, $name, true); $closed = (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid])['name'] ?? ''); }
        }
        return ['approved' => $done, 'closed' => $closed];
    }

    /** Słowniki danych zgłoszenia — wartości domyślne (klucz => etykieta); edytowalne w Ustawieniach konsultacji (dicts()). */
    public const INTAKE = [
        'contact_pref' => ['email' => 'E-mail', 'phone' => 'Telefon / SMS', 'app' => 'Aplikacja / komunikator'],
        'who'          => ['niewidoma' => 'Osoba niewidoma / słabowidząca', 'rodzic' => 'Rodzic / opiekun prawny',
                           'nauczyciel' => 'Nauczyciel / edukator / terapeuta', 'instytucja' => 'Przedstawiciel instytucji / firmy / NGO', 'inne' => 'Inne'],
        'vision'       => ['niewidoma' => 'Osoba całkowicie niewidoma', 'slabowidzaca' => 'Osoba słabowidząca (powiększenie / kontrast)',
                           'gluchoslepota' => 'Osoba z głuchoślepotą', 'brak' => 'Brak dysfunkcji wzroku (dostępność dla instytucji/firmy)'],
        'topics'       => ['sprzet' => 'Dobór sprzętu tyfloinformatycznego (linijka brajlowska, powiększalnik, dyktafon)',
                           'screenreader' => 'Oprogramowanie odczytu ekranu (NVDA, JAWS, VoiceOver, TalkBack)',
                           'konfiguracja' => 'Dobór i konfiguracja smartfona / komputera / systemu',
                           'biurowe' => 'Nauka obsługi oprogramowania biurowego / narzędzi programistycznych',
                           'wcag' => 'Dostępność cyfrowa stron WWW / dokumentów (WCAG)',
                           'adaptacja' => 'Adaptacja materiałów dydaktycznych lub stanowiska pracy/nauki', 'inne' => 'Inne'],
        'systems'      => ['windows' => 'Windows', 'macos' => 'macOS', 'ios' => 'iOS', 'android' => 'Android', 'linux' => 'Linux'],
        'form'         => ['online' => 'On-line (Teams, Zoom, telefon)', 'stacjonarnie' => 'Stacjonarnie (siedziba / dojazd)'],
    ];

    public const DICT_NAMES = ['contact_pref' => 'Preferowana forma kontaktu', 'who' => 'Kogo dotyczy zgłoszenie', 'vision' => 'Stopień / specyfika wzroku',
                               'topics' => 'Tematy konsultacji', 'systems' => 'Systemy', 'form' => 'Forma konsultacji'];
    /** Domyślne teksty zgód — {polityka} i {regulamin} zamieniane na linki z ustawień. */
    public const RODO_DEFAULT  = 'Beneficjent wyraził zgodę na przetwarzanie danych osobowych (RODO) po zapoznaniu się z obowiązkiem informacyjnym i {polityka}.';
    public const TERMS_DEFAULT = 'Akceptacja {regulamin} (jeśli dotyczy).';

    /** Słowniki zgłoszenia w Słowniku Centralnym (kategoria ti_konsultacje, słowniki systemowe — klucze zapisane na kartach). */
    public const DICT_CENTRAL = ['contact_pref' => 'ti_konsultacje_kontakt', 'who' => 'ti_konsultacje_kogo', 'vision' => 'ti_konsultacje_wzrok',
                                 'topics' => 'ti_konsultacje_tematy', 'systems' => 'ti_konsultacje_systemy', 'form' => 'ti_konsultacje_forma'];

    private static function dictApi(): void
    {
        require_once dirname(__DIR__, 3) . '/modules/dictionary/lib/dictionaryApi.php';
    }

    /**
     * Słowniki zgłoszenia ze Słownika Centralnego (DICT_CENTRAL). $activeOnly — tylko aktywne i obowiązujące (formularz);
     * inaczej wszystkie (opisy na kartach i wydrukach starszych kart, także wyłączone). Zapas, gdy słownika jeszcze nie ma:
     * dawne ustawienie org_setting ti_consult_dicts, a bez niego INTAKE.
     * @return array<string, array<string,string>>
     */
    public static function dicts(bool $activeOnly = false): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = self::legacyDicts();
            try {
                self::dictApi();
                $svc = dictService();
                foreach (self::DICT_CENTRAL as $d => $code) {
                    if (!$svc->exists($code)) continue;
                    $all = [];
                    foreach ($svc->entries($svc->dictionary($code)) as $e) if (!$e->isDeleted()) $all[$e->code] = $e->label;
                    if (!$all) continue;
                    foreach ($cache['all'][$d] as $k => $l) if (!isset($all[$k])) $all[$k] = $l;   // stare klucze zawsze opisane
                    $cache['all'][$d] = $all;
                    $cache['active'][$d] = dictOptions($code);
                }
            } catch (\Throwable $e) { error_log('[ti_consultations] dicts: ' . $e->getMessage()); }
        }
        return $cache[$activeOnly ? 'active' : 'all'];
    }

    /** Dawne słowniki: INTAKE + zmiany z org_setting ti_consult_dicts (JSON {słownik: [[klucz, etykieta, aktywna], …]}). */
    private static function legacyDicts(): array
    {
        $out = ['all' => self::INTAKE, 'active' => self::INTAKE];
        $j = function_exists('org_setting') ? json_decode((string)org_setting('ti_consult_dicts'), true) : null;
        if (is_array($j)) foreach (self::DICT_NAMES as $d => $_) {
            if (empty($j[$d]) || !is_array($j[$d])) continue;   // brak / pusty słownik = domyślny
            $all = []; $act = [];
            foreach ($j[$d] as $row) {
                [$k, $l, $on] = array_pad((array)$row, 3, 1);
                if (!is_string($k) || $k === '' || trim((string)$l) === '') continue;
                $all[$k] = (string)$l; if ($on) $act[$k] = (string)$l;
            }
            foreach (self::INTAKE[$d] as $k => $l) if (!isset($all[$k])) { $all[$k] = $l; }
            $out['all'][$d] = $all; $out['active'][$d] = $act;
        }
        return $out;
    }

    /** Zasiew Słownika Centralnego: kategoria + 6 słowników z dotychczasowymi pozycjami (z ustawień, z aktywnością). */
    public static function dictionarySeed(): string
    {
        $L = self::legacyDicts();
        $dicts = [['code' => 'ti_konsultacje', 'name' => 'Konsultacje TI', 'kind' => 'static', 'parent_code' => null, 'is_protected' => false, 'sort_order' => 75,
                   'description' => 'Słowniki karty konsultacji w panelu dydaktyka (formularz zgłoszenia).', 'entries' => []]];
        $i = 0;
        foreach (self::DICT_CENTRAL as $d => $code) {
            $entries = [];
            $n = 0;
            foreach ($L['all'][$d] as $k => $l)
                $entries[] = ['code' => $k, 'label' => $l, 'parent_code' => null, 'sort_order' => (++$n) * 10, 'is_active' => isset($L['active'][$d][$k]), 'description' => null];
            $dicts[] = ['code' => $code, 'name' => 'Konsultacje — ' . mb_strtolower(self::DICT_NAMES[$d]), 'kind' => 'system', 'parent_code' => 'ti_konsultacje',
                        'is_protected' => false, 'sort_order' => ++$i,
                        'description' => 'Lista na karcie konsultacji (dydaktyk/konsultacja.php), sekcja Zgłoszenie. Klucze zapisują się na kartach — zmieniaj nazwy, kolejność i aktywność (wyłączone zostają opisane na starszych kartach), nowe pozycje dodawaj tutaj.'
                                         . (in_array($d, ['who', 'topics'], true) ? ' Pozycja „inne” odblokowuje pole „Inne — jakie?”.' : '') . ($d === 'contact_pref' ? ' Pozycja „phone” wymaga numeru telefonu.' : ''),
                        'entries' => $entries];
        }
        return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'dictionaries' => $dicts], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Zapis słowników z ekranu ustawień (dydaktyk/konsultacjeUstawienia.php) do Słownika Centralnego:
     * $in[słownik] = [['key'=>, 'label'=>, 'on'=>bool], …]; puste klucze = nowe pozycje. Pozycji się nie usuwa (wyłącza).
     */
    public static function saveDicts(array $in): void
    {
        self::dictApi();
        $cur = self::dicts();
        foreach (self::DICT_CENTRAL as $d => $code) {
            $rows = []; $used = [];
            $taken = [];   // klucze istniejących pozycji — nowa pozycja ich nie zajmie
            foreach ((array)($in[$d] ?? []) as $r) if (preg_match('/^[a-z0-9_]{1,40}$/', (string)($r['key'] ?? ''))) $taken[$r['key']] = true;
            foreach ((array)($in[$d] ?? []) as $r) {
                $l = trim(mb_substr((string)($r['label'] ?? ''), 0, 200));
                if ($l === '') continue;
                $k = (string)($r['key'] ?? '');
                if (!preg_match('/^[a-z0-9_]{1,40}$/', $k)) {   // nowa pozycja — klucz z etykiety, unikalny w słowniku
                    $pl = strtr(mb_strtolower($l), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
                    $base = substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $pl) ?: '')), '_'), 0, 30) ?: 'poz';
                    $k = $base; $i = 2;
                    while (isset($used[$k]) || isset($taken[$k]) || isset($cur[$d][$k])) $k = $base . '_' . $i++;
                }
                if (isset($used[$k])) continue;
                $used[$k] = true;
                $rows[] = ['code' => $k, 'label' => $l, 'parent_code' => null, 'sort_order' => count($rows) * 10 + 10, 'is_active' => !empty($r['on']), 'description' => null, '_line' => count($rows) + 1];
            }
            if ($rows) self::importDict($code, $rows);
        }
    }

    /** Przywraca domyślne nazwy i aktywność (INTAKE); pozycje spoza domyślnych zostają wyłączone. */
    public static function resetDicts(): void
    {
        self::dictApi();
        $cur = self::dicts();
        foreach (self::DICT_CENTRAL as $d => $code) {
            $rows = []; $n = 0;
            foreach (self::INTAKE[$d] as $k => $l) $rows[] = ['code' => $k, 'label' => $l, 'parent_code' => null, 'sort_order' => (++$n) * 10, 'is_active' => true, 'description' => null, '_line' => $n];
            foreach ($cur[$d] as $k => $l) if (!isset(self::INTAKE[$d][$k])) $rows[] = ['code' => $k, 'label' => $l, 'parent_code' => null, 'sort_order' => (++$n) * 10, 'is_active' => false, 'description' => null, '_line' => $n];
            self::importDict($code, $rows);
        }
        if (function_exists('org_setting_set')) org_setting_set('ti_consult_dicts', '');
    }

    private static function importDict(string $code, array $rows): void
    {
        $svc = dictService();
        if (!$svc->exists($code)) { dictTransfer()->import(self::dictionarySeed(), 'json'); }
        $r = $svc->importDictionary(null, $rows, $code);
        if (!empty($r['errors'])) throw new \RuntimeException('Słownik ' . $code . ': ' . implode('; ', $r['errors']));
    }

    /** Tekst zgody z linkami (HTML, już escapowany). $kind = 'rodo' | 'terms'. */
    public static function consentHtml(string $kind): string
    {
        $txt = trim((string)org_setting($kind === 'rodo' ? 'ti_consult_rodo_text' : 'ti_consult_terms_text')) ?: ($kind === 'rodo' ? self::RODO_DEFAULT : self::TERMS_DEFAULT);
        $link = function (string $key, string $label) {
            $u = trim((string)org_setting($key));
            return preg_match('#^https?://#i', $u) ? '<a href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $label . '</a>' : $label;
        };
        return strtr(htmlspecialchars($txt, ENT_QUOTES, 'UTF-8'), [
            '{polityka}' => $link('ti_consult_privacy_url', 'Polityką Prywatności'),
            '{regulamin}' => $link('ti_consult_terms_url', 'regulaminu świadczenia konsultacji'),
        ]);
    }

    /** Tekst zgody bez HTML (do PDF / zapisu przy karcie). */
    public static function consentText(string $kind): string
    {
        return html_entity_decode(strip_tags(self::consentHtml($kind)), ENT_QUOTES, 'UTF-8');
    }

    /** Dane zgłoszenia z formularza (pola intake_*) — tylko znane wartości. */
    public static function intakeFromPost(array $p): array
    {
        $D = self::dicts();
        $one = fn(string $k) => isset($D[$k][$p['intake_' . $k] ?? '']) ? (string)$p['intake_' . $k] : '';
        $many = fn(string $k) => array_values(array_intersect(array_keys($D[$k]), (array)($p['intake_' . $k] ?? [])));
        $t = fn(string $k, int $max = 500) => mb_substr(trim((string)($p['intake_' . $k] ?? '')), 0, $max);
        return [
            'email' => $t('email', 190), 'phone' => $t('phone', 40), 'contact_pref' => $one('contact_pref'), 'contact_app' => $t('contact_app', 100),
            'who' => $one('who'), 'who_other' => $t('who_other', 200), 'vision' => $one('vision'),
            'topics' => $many('topics'), 'topics_other' => $t('topics_other', 300), 'systems' => $many('systems'), 'at_tools' => $t('at_tools', 300),
            'problem' => $t('problem', 4000), 'form' => $one('form'), 'availability' => $t('availability', 300),
            'rodo' => !empty($p['intake_rodo']), 'terms' => !empty($p['intake_terms']), 'consent_at' => !empty($p['intake_rodo']) ? date('Y-m-d H:i:s') : '',
            'rodo_text' => !empty($p['intake_rodo']) ? self::consentText('rodo') : '', 'terms_text' => !empty($p['intake_terms']) ? self::consentText('terms') : '',
        ];
    }

    /** Walidacja danych zgłoszenia karty samodzielnej. */
    public static function intakeErrors(array $i): array
    {
        $e = [];
        if ($i['email'] === '' || !filter_var($i['email'], FILTER_VALIDATE_EMAIL)) $e[] = 'Podaj poprawny adres e-mail beneficjenta.';
        if ($i['contact_pref'] === 'phone' && $i['phone'] === '') $e[] = 'Preferowany kontakt telefoniczny — podaj numer telefonu.';
        if (!$i['rodo']) $e[] = 'Wymagana zgoda na przetwarzanie danych osobowych (RODO).';
        return $e;
    }

    public static function intake(array $card): array
    {
        $j = json_decode((string)($card['intake'] ?? ''), true);
        return is_array($j) ? $j : [];
    }

    /** Wiersze [etykieta, wartość] danych zgłoszenia — do podglądu i PDF. */
    public static function intakeRows(array $card): array
    {
        $i = self::intake($card);
        if (!$i) return [];
        $L = self::dicts(); $lab = fn($k, $v) => $L[$k][$v] ?? '';
        $topics = array_map(fn($v) => $lab('topics', $v), (array)($i['topics'] ?? []));
        if (($i['topics_other'] ?? '') !== '') $topics[] = 'inne: ' . $i['topics_other'];
        return array_values(array_filter([
            ['E-mail', $i['email'] ?? ''], ['Telefon', $i['phone'] ?? ''],
            ['Preferowany kontakt', trim($lab('contact_pref', $i['contact_pref'] ?? '') . (($i['contact_app'] ?? '') !== '' ? ' — ' . $i['contact_app'] : ''))],
            ['Kogo dotyczy', trim($lab('who', $i['who'] ?? '') . (($i['who_other'] ?? '') !== '' ? ' — ' . $i['who_other'] : ''))],
            ['Wzrok', $lab('vision', $i['vision'] ?? '')],
            ['Temat konsultacji', implode('; ', $topics)],
            ['Systemy', implode(', ', array_map(fn($v) => $lab('systems', $v), (array)($i['systems'] ?? [])))],
            ['Czytnik ekranu / powiększalnik', $i['at_tools'] ?? ''],
            ['Opis problemu / cel', $i['problem'] ?? ''],
            ['Preferowana forma', $lab('form', $i['form'] ?? '')], ['Dogodne terminy', $i['availability'] ?? ''],
            ['Zgoda RODO', !empty($i['rodo']) ? 'tak' . (($i['consent_at'] ?? '') !== '' ? ' (' . date('d.m.Y H:i', strtotime((string)$i['consent_at'])) . ')' : '') . (($i['rodo_text'] ?? '') !== '' ? ' — „' . $i['rodo_text'] . '”' : '') : 'nie'],
            ['Regulamin konsultacji', !empty($i['terms']) ? 'zaakceptowany' . (($i['terms_text'] ?? '') !== '' ? ' — „' . $i['terms_text'] . '”' : '') : 'nie dotyczy / brak'],
        ], fn($r) => trim((string)$r[1]) !== ''));
    }

    // ── Odczyt ───────────────────────────────────────────────────────────────

    public static function get(int $id): ?array
    {
        return db_one(
            "SELECT co.*, c.name AS client_name, c.phone AS client_phone, u.name AS consultant_name, cr.name AS created_by_name,
                    k.name AS course_name, k.simple_mode AS course_simple, s.lesson_date, s.time_from, s.time_to
               FROM k30_consultations co
               LEFT JOIN k30_clients c ON c.id = co.client_id
               LEFT JOIN users u ON u.id = co.consultant_id
               LEFT JOIN users cr ON cr.id = co.created_by
               LEFT JOIN k30_ti_courses k ON k.id = co.course_id
               LEFT JOIN k30_ti_sessions s ON s.id = co.session_id
              WHERE co.id = ?", [$id]) ?: null;
    }

    /**
     * Lista kart. $f: q (beneficjent / numer), status (etap: draft|signed|approved|cancelled), ym (RRRR-MM), kind ('sam' — samodzielne | 'szk' — terminy szkoleń krótkich | ''), client_id, course_id.
     * Kadra widzi wszystko; pozostali — karty, w których są konsultantem, i karty terminów swoich grup.
     */
    public static function list(array $f, int $uid, bool $staff, int $limit = 300): array
    {
        $w = ['1=1']; $p = [];
        if (($q = trim((string)($f['q'] ?? ''))) !== '') { $w[] = "(c.name LIKE ? OR co.card_no LIKE ?)"; $p[] = "%$q%"; $p[] = "%$q%"; }
        $stageSql = ['draft' => "co.status = 'draft'", 'signed' => "co.status = 'completed' AND co.mgr_approved_at IS NULL",
                     'approved' => "co.status = 'completed' AND co.mgr_approved_at IS NOT NULL", 'cancelled' => "co.status = 'cancelled'"];
        if (isset($stageSql[$f['status'] ?? ''])) $w[] = $stageSql[$f['status']];   // filtr etapu (Robocza / Podpisana / Zatwierdzona / Anulowana)
        if (preg_match('/^\d{4}-\d{2}$/', (string)($f['ym'] ?? ''))) { $w[] = "strftime('%Y-%m', co.consultation_datetime) = ?"; $p[] = $f['ym']; }
        if (($f['kind'] ?? '') === 'sam') $w[] = 'COALESCE(co.session_id,0) = 0';
        if (($f['kind'] ?? '') === 'szk') $w[] = 'COALESCE(co.session_id,0) > 0';
        if ((int)($f['client_id'] ?? 0) > 0) { $w[] = 'co.client_id = ?'; $p[] = (int)$f['client_id']; }
        if ((int)($f['course_id'] ?? 0) > 0) { $w[] = 'co.course_id = ?'; $p[] = (int)$f['course_id']; }
        if (!$staff) {
            $w[] = "(co.consultant_id = ? OR co.course_id IN (SELECT id FROM k30_ti_courses WHERE instructor_id = ?)
                     OR co.session_id IN (SELECT id FROM k30_ti_sessions WHERE instructor_id = ?))";
            array_push($p, $uid, $uid, $uid);
        }
        return db_all(
            "SELECT co.*, c.name AS client_name, u.name AS consultant_name, k.name AS course_name
               FROM k30_consultations co
               LEFT JOIN k30_clients c ON c.id = co.client_id
               LEFT JOIN users u ON u.id = co.consultant_id
               LEFT JOIN k30_ti_courses k ON k.id = co.course_id
              WHERE " . implode(' AND ', $w) . " ORDER BY co.consultation_datetime DESC, co.id DESC LIMIT " . (int)$limit, $p);
    }

    /** Czy użytkownik może oglądać / edytować kartę (kadra, konsultant karty, prowadzący terminu lub grupy). */
    public static function canAccess(array $card, int $uid, bool $staff): bool
    {
        if ($staff || (int)$card['consultant_id'] === $uid) return true;
        if ((int)($card['session_id'] ?? 0) > 0) return k30_ti_instructor_owns_session($uid, (int)$card['session_id']);
        return false;
    }

    /**
     * Kto może edytować kartę na danym etapie: Robocza — konsultant karty, prowadzący terminu SzK i kadra;
     * Podpisana — tylko kadra (zmiana treści cofa podpis, bo SHA1 podpisu obejmuje treść); Zatwierdzona i Anulowana — nikt.
     */
    public static function canEdit(array $card, int $uid, bool $staff): bool
    {
        $stage = self::stage($card);
        if ($stage === 'approved' || $stage === 'cancelled') return false;
        if ($stage === 'signed') return $staff;
        return self::canAccess($card, $uid, $staff);
    }

    /** Powód braku edycji — do komunikatu. */
    public static function editBlockReason(array $card): string
    {
        return match (self::stage($card)) {
            'approved'  => 'Karta zatwierdzona przez kierownika — zmiany wymagają najpierw cofnięcia zatwierdzenia.',
            'cancelled' => 'Karta anulowana.',
            'signed'    => 'Karta podpisana — zmienić ją może kierownik (podpis zostanie cofnięty).',
            default     => '',
        };
    }

    /** Cofa podpis konsultanta (po zmianie treści przez kadrę): karta wraca do Roboczej, wpis w dzienniku konta. */
    public static function unsign(int $id, int $uid, string $byName, string $why): void
    {
        db()->prepare("UPDATE k30_consultations SET status='draft', confirmed=0, approved_by_name='', sha1sum='', cert_fingerprint=NULL, cert_subject=NULL, ika_verified_at=NULL, updated_at=datetime('now') WHERE id=?")->execute([$id]);
        try {
            $k = db_one("SELECT client_id, card_no FROM k30_consultations WHERE id=?", [$id]);
            $acc = $k ? db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=?", [(int)$k['client_id']]) : null;
            if ($acc && function_exists('ti_account_log')) ti_account_log((int)$acc['id'], 'consultation', 'Cofnięto podpis karty ' . ($k['card_no'] ?? '') . ' — ' . $why . '.', $uid ?: null, $byName);
        } catch (\Throwable $e) {}
    }

    /** Kierownik cofa swoje zatwierdzenie (karta wraca do Podpisanej); EZD nie jest ruszane. */
    public static function managerUnapprove(int $id, int $uid, string $byName): bool
    {
        $k = self::get($id);
        if (!$k || self::stage($k) !== 'approved') return false;
        db()->prepare("UPDATE k30_consultations SET mgr_approved_by=NULL, mgr_approved_name='', mgr_approved_at=NULL, updated_at=datetime('now') WHERE id=?")->execute([$id]);
        try {
            $acc = db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=?", [(int)$k['client_id']]);
            if ($acc && function_exists('ti_account_log')) ti_account_log((int)$acc['id'], 'consultation', 'Kierownik cofnął zatwierdzenie karty ' . $k['card_no'] . '.', $uid ?: null, $byName);
        } catch (\Throwable $e) {}
        return true;
    }

    // ── Numeracja KON.XXX.MM.RRRR ───────────────────────────────────────────

    /** Kolejny numer karty w miesiącu daty konsultacji (RRRR-MM-DD…). */
    public static function cardNumber(string $datetime): string
    {
        $y = (int)substr($datetime, 0, 4) ?: (int)date('Y');
        $m = (int)substr($datetime, 5, 2) ?: (int)date('n');
        $sfx = sprintf('.%02d.%04d', $m, $y);
        $max = 0;
        foreach (db_all("SELECT card_no FROM k30_consultations WHERE card_no LIKE ?", ['KON.%' . $sfx]) as $r)
            if (preg_match('/^KON\.(\d+)\./', (string)$r['card_no'], $mm)) $max = max($max, (int)$mm[1]);
        return sprintf('KON.%03d', $max + 1) . $sfx;
    }

    // ── Konto beneficjenta konsultacji ──────────────────────────────────────

    /**
     * Konto panelu kursanta dla beneficjenta konsultacji bez konta: login „k” + 4 cyfry (k1234), hasło jednorazowe
     * (zmiana przy pierwszym logowaniu) wysyłane SMS-em, tryb panelu 4 (tylko Moje konsultacje i Rozliczenia).
     * Beneficjent, który ma już konto (np. kursant szkoleń), zostaje bez zmian — zwraca null.
     * @return array{login:string, password:string, sms:string}|null
     */
    public static function ensureBeneficiaryAccount(int $clientId, int $uid, bool $sendSms = true): ?array
    {
        if ($clientId <= 0 || db_one("SELECT 1 FROM k30_ti_student_accounts WHERE client_id=?", [$clientId])) return null;
        $c = db_one("SELECT id, name, phone FROM k30_clients WHERE id=?", [$clientId]);
        if (!$c) return null;
        $digits = 4; $login = '';
        for ($try = 0; $try < 60; $try++) {
            if ($try === 40) $digits = 5;   // pula 4 cyfr prawie pełna — dłuższy numer
            $cand = 'k' . str_pad((string)random_int(0, 10 ** $digits - 1), $digits, '0', STR_PAD_LEFT);
            if (!db_one("SELECT 1 FROM k30_ti_student_accounts WHERE login=? OR login_alias=?", [$cand, $cand])) { $login = $cand; break; }
        }
        if ($login === '') return null;
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'; $pass = '';
        for ($i = 0; $i < 8; $i++) $pass .= $chars[random_int(0, strlen($chars) - 1)];
        require_once dirname(__DIR__, 2) . '/ti_simple_groups/logic/simpleGroups.php';
        $accId = (int)db_insert('k30_ti_student_accounts', [
            'client_id' => $clientId, 'login' => $login, 'password_hash' => password_hash($pass, PASSWORD_BCRYPT),
            'student_no' => TiSimpleGroups::studentNo($clientId), 'is_active' => 1, 'must_change_password' => 1, 'simple_mode' => 4,
            'created_by' => $uid ?: null, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        try { require_once dirname(__DIR__, 2) . '/payment_portal/logic/paymentPortal.php'; pp_ti_sync_nrb($clientId, 'konsultacje', null); } catch (\Throwable $e) {}
        $sms = '';
        if ($sendSms) {
            require_once dirname(__DIR__, 3) . '/includes/sms.php';
            $phone = trim((string)($c['phone'] ?? ''));
            if ($phone === '') $sms = 'brak telefonu — przekaż login i hasło ręcznie';
            elseif (!sms_channel_ready()) $sms = 'SMS wyłączony — przekaż login i hasło ręcznie';
            else {
                $org = defined('ORG_NAME') ? ORG_NAME : 'Panel';
                $url = defined('APP_URL') ? ' ' . rtrim(APP_URL, '/') . '/karty30/ti/kursant/login.php' : '';
                try { sms_send($phone, "{$org} - konsultacje. Login: {$login}, haslo: {$pass}.{$url}", 'access_data'); $sms = 'hasło wysłane SMS-em'; }
                catch (\Throwable $e) { $sms = 'błąd SMS — przekaż login i hasło ręcznie'; }
            }
        }
        try { if (function_exists('ti_account_log')) ti_account_log($accId, 'created', 'Konto beneficjenta konsultacji (' . $login . ').', $uid ?: null); } catch (\Throwable $e) {}
        return ['login' => $login, 'password' => $pass, 'sms' => $sms];
    }

    /** Komunikat o założonym koncie do flash (hasło pokazywane, gdy SMS nie wyszedł). */
    public static function accountMsg(?array $a): string
    {
        if (!$a) return '';
        return ' Założono konto beneficjenta w panelu: login ' . $a['login'] . ($a['sms'] === 'hasło wysłane SMS-em' ? ' — hasło wysłane SMS-em.' : ', hasło jednorazowe ' . $a['password'] . ' (' . $a['sms'] . ').');
    }

    // ── Karta samodzielna ───────────────────────────────────────────────────

    /**
     * Nowa albo edycja roboczej karty samodzielnej. $d: client_id, consultant_id, schedule_id, datetime (RRRR-MM-DDTHH:MM),
     * duration, description, next_action.
     * @return array{ok:bool, id:int, errors:string[]}
     */
    public static function saveStandalone(array $d, ?int $id, int $uid, bool $staff = false, string $byName = ''): array
    {
        $err = [];
        $client = (int)($d['client_id'] ?? 0);
        $dt = trim((string)($d['datetime'] ?? ''));
        $cl = $client ? db_one("SELECT id, name FROM k30_clients WHERE id=?", [$client]) : null;
        if (!$cl) $err[] = 'Wybierz beneficjenta.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $dt)) $err[] = 'Podaj datę i godzinę konsultacji.';
        $old = $id ? self::get($id) : null;
        if ($id && (!$old || !self::canEdit($old, $uid, $staff))) $err[] = $old ? (self::editBlockReason($old) ?: 'Nie masz uprawnień do edycji tej karty.') : 'Nie znaleziono karty.';
        if ($err) return ['ok' => false, 'id' => (int)$id, 'errors' => $err];
        $dtDb = str_replace('T', ' ', substr($dt, 0, 16)) . ':00';
        if (isset($d['intake'])) {
            $err = array_merge($err, self::intakeErrors($d['intake']));
            if ($err) return ['ok' => false, 'id' => (int)$id, 'errors' => $err];
        }
        $row = [
            'client_id' => $client, 'consultant_id' => ((int)($d['consultant_id'] ?? 0)) ?: null,
            'schedule_id' => ((int)($d['schedule_id'] ?? 0)) ?: null, 'consultation_datetime' => $dtDb,
            'duration_minutes' => max(0, (int)($d['duration'] ?? 0)) ?: null,
            'description' => trim((string)($d['description'] ?? '')) ?: null, 'next_action' => trim((string)($d['next_action'] ?? '')) ?: null,
        ];
        if (isset($d['intake'])) {
            $row['intake'] = json_encode($d['intake'], JSON_UNESCAPED_UNICODE);
            // Brakujący e-mail / telefon beneficjenta uzupełniamy z karty (nie nadpisujemy istniejących)
            db()->prepare("UPDATE k30_clients SET email=COALESCE(NULLIF(email,''), ?), phone=COALESCE(NULLIF(phone,''), ?) WHERE id=?")
               ->execute([$d['intake']['email'] ?: null, $d['intake']['phone'] ?: null, $client]);
        }
        if ($id) {
            $set = []; $par = [];
            foreach ($row as $k => $v) { $set[] = "$k=?"; $par[] = $v; }
            $par[] = $id;
            db()->prepare("UPDATE k30_consultations SET " . implode(',', $set) . ", updated_at=datetime('now') WHERE id=?")->execute($par);
            $unsigned = false;
            if ($old && self::stage($old) === 'signed') { self::unsign($id, $uid, $byName, 'zmiana treści przez ' . ($byName ?: 'kadrę')); $unsigned = true; }
            return ['ok' => true, 'id' => $id, 'errors' => [], 'unsigned' => $unsigned];
        }
        $row += ['status' => 'draft', 'card_no' => self::cardNumber($dtDb), 'created_by' => $uid ?: null];
        $newId = (int)db_insert('k30_consultations', $row);
        $acc = self::ensureBeneficiaryAccount($client, $uid);   // beneficjent bez konta → konto „k1234” + SMS
        return ['ok' => true, 'id' => $newId, 'errors' => [], 'account' => $acc];
    }

    // ── Karty terminu szkolenia krótkiego SzK ───────────────────────────────

    /** Numer terminu w grupie (1, 2, …) wg daty — bez odwołanych, szkiców i rezerwacji. */
    public static function lessonOrdinal(int $sessionId): int
    {
        $s = db_one("SELECT course_id, lesson_date, time_from, id FROM k30_ti_sessions WHERE id=?", [$sessionId]);
        if (!$s) return 0;
        return 1 + (int)(db_one(
            "SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE course_id=? AND draft_id IS NULL AND status NOT IN ('draft','reserved','cancelled')
                AND (lesson_date < ? OR (lesson_date = ? AND (time_from < ? OR (time_from = ? AND id < ?))))",
            [(int)$s['course_id'], $s['lesson_date'], $s['lesson_date'], $s['time_from'], $s['time_from'], $sessionId])['n'] ?? 0);
    }

    /** Termin z grupą, uczestnikami (obecność) i ich kartami. */
    public static function sessionView(int $sessionId): ?array
    {
        $s = db_one(
            "SELECT s.*, c.name AS course_name, c.simple_mode, c.internal_desc, c.closed_at, COALESCE(s.instructor_id, c.instructor_id) AS eff_instructor
               FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id WHERE s.id = ?", [$sessionId]);
        if (!$s) return null;
        $people = db_all(
            "SELECT e.client_id, cl.name AS client_name, a.attended, a.cancelled
               FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id = e.client_id
               LEFT JOIN k30_ti_attendance a ON a.session_id = ? AND a.client_id = e.client_id
              WHERE e.course_id = ? AND e.status = 'active' ORDER BY cl.name", [$sessionId, (int)$s['course_id']]);
        $cards = [];
        foreach (db_all("SELECT * FROM k30_consultations WHERE session_id = ? AND status <> 'cancelled'", [$sessionId]) as $c) $cards[(int)$c['client_id']] = $c;
        $s['ordinal'] = self::lessonOrdinal($sessionId);
        return ['session' => $s, 'people' => $people, 'cards' => $cards];
    }

    /**
     * Zapis kart terminu: wspólny opis / następne działania / czas / temat + obecność uczestników.
     * Obecni dostają (lub mają zaktualizowaną) roboczą kartę; nieobecni — robocza karta jest anulowana.
     * Lekcja: temat, czas trwania, obecność, a gdy termin minął i ktoś był — status „odbyła się”.
     * $d: topic, description, next_action, duration, present: int[] (client_id), notes: [client_id => tekst].
     * @return array{ok:bool, msg:string, cards:int}
     */
    public static function saveSession(int $sessionId, array $d, int $uid, bool $staff = false, string $byName = ''): array
    {
        $v = self::sessionView($sessionId);
        if (!$v) return ['ok' => false, 'msg' => 'Nie znaleziono terminu.', 'cards' => 0];
        $s = $v['session'];
        if (!empty($s['closed_at'])) return ['ok' => false, 'msg' => 'Grupa jest zamknięta — karty i dokumentacja są zablokowane.', 'cards' => 0];
        $present = array_map('intval', (array)($d['present'] ?? []));
        $dur = max(0, (int)($d['duration'] ?? 0)) ?: (int)$s['duration_min'];
        $desc = trim((string)($d['description'] ?? ''));
        $next = trim((string)($d['next_action'] ?? ''));
        $topic = trim((string)($d['topic'] ?? '')) ?: mb_substr(trim(strtok($desc, "\n") ?: ''), 0, 200);
        $dt = $s['lesson_date'] . ' ' . (substr((string)$s['time_from'], 0, 5) ?: '00:00') . ':00';
        $consultant = (int)$s['eff_instructor'] ?: ($uid ?: null);
        $pdo = db(); $pdo->beginTransaction(); $unsigned = [];
        try {
            $n = 0;
            foreach ($v['people'] as $p) {
                $cid = (int)$p['client_id'];
                $isIn = in_array($cid, $present, true);
                // Obecność lekcji
                $pdo->prepare("INSERT INTO k30_ti_attendance (session_id, client_id, attended) VALUES (?,?,?)
                               ON CONFLICT(session_id, client_id) DO UPDATE SET attended = excluded.attended")->execute([$sessionId, $cid, $isIn ? 1 : 0]);
                $card = $v['cards'][$cid] ?? null;
                if ($card && self::stage($card) === 'approved') continue;   // zatwierdzona przez kierownika — nienaruszalna
                if ($card && self::stage($card) === 'signed') { if (!$staff) continue; self::unsign((int)$card['id'], $uid, $byName, 'zmiana kart terminu przez ' . ($byName ?: 'kadrę')); $unsigned[] = $card['card_no']; }
                $note = trim((string)($d['notes'][$cid] ?? ''));
                $text = $desc . ($note !== '' ? "\n\n" . $note : '');
                if ($isIn) {
                    $row = ['consultation_datetime' => $dt, 'duration_minutes' => $dur ?: null, 'description' => $text !== '' ? $text : null,
                            'next_action' => $next !== '' ? $next : null, 'consultant_id' => $consultant];
                    if ($card) {
                        $set = []; $par = [];
                        foreach ($row as $k => $val) { $set[] = "$k=?"; $par[] = $val; }
                        $par[] = (int)$card['id'];
                        $pdo->prepare("UPDATE k30_consultations SET " . implode(',', $set) . ", updated_at=datetime('now') WHERE id=?")->execute($par);
                    } else {
                        db_insert('k30_consultations', $row + ['client_id' => $cid, 'session_id' => $sessionId, 'course_id' => (int)$s['course_id'],
                                                               'card_no' => self::cardNumber($dt), 'status' => 'draft', 'created_by' => $uid ?: null]);
                    }
                    $n++;
                } elseif ($card) {
                    $pdo->prepare("UPDATE k30_consultations SET status='cancelled', updated_at=datetime('now') WHERE id=?")->execute([(int)$card['id']]);
                }
            }
            // Dokumentacja lekcji
            $status = (string)$s['status'];
            if ($present && $status === 'planned' && (string)$s['lesson_date'] <= date('Y-m-d')) $status = 'held';
            $pdo->prepare("UPDATE k30_ti_sessions SET topic=?, duration_min=?, status=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$topic !== '' ? $topic : (string)$s['topic'], $dur ?: (int)$s['duration_min'], $status, $sessionId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('ti_consultations saveSession: ' . $e->getMessage());
            return ['ok' => false, 'msg' => 'Nie udało się zapisać kart — spróbuj ponownie.', 'cards' => 0];
        }
        $msg = 'Zapisano karty konsultacji: ' . $n . '.';
        if ($unsigned) $msg .= ' Cofnięto podpis kart: ' . implode(', ', $unsigned) . ' — wymagają ponownego podpisu.';
        if (!$present) $msg .= ' Nikt nie był obecny — lekcja nie została oznaczona jako odbyta.';
        elseif ((string)$s['lesson_date'] > date('Y-m-d')) $msg .= ' Termin jeszcze nie minął — status lekcji bez zmian.';
        return ['ok' => true, 'msg' => $msg, 'cards' => $n];
    }

    // ── Zatwierdzenie (IKA + x509) ──────────────────────────────────────────

    /**
     * Zatwierdza robocze karty (jedną albo wszystkie karty terminu) — jedno wpisanie kodu IKA.
     * @return array{ok:bool, errors:string[], approved:int[], closed:?string}
     */
    public static function approve(array $ids, int $uid, string $approverName, string $ika): array
    {
        require_once dirname(__DIR__, 3) . '/includes/cpc.php';
        cpc_migrate();
        $err = [];
        $ika = trim($ika);
        if ($ika === '') $err[] = 'Kod IKA jest wymagany do zatwierdzenia.';
        else {
            $r = cpc_verify($uid, $ika);
            if (!empty($r['blocked'])) $err[] = 'Kod IKA zablokowany' . (!empty($r['blocked_until']) ? ' do ' . date('H:i d.m.Y', strtotime((string)$r['blocked_until'])) : '') . '. Za dużo błędnych prób.';
            elseif (empty($r['ok'])) $err[] = 'Nieprawidłowy kod IKA. Pozostało prób: ' . max(0, 3 - (int)($r['fails'] ?? 0)) . '.';
        }
        $cert = k30_verify_consultant_cert($uid);
        if (!$cert['ok']) $err[] = (string)$cert['error'];
        if ($err) return ['ok' => false, 'errors' => $err, 'approved' => [], 'closed' => null];
        $c = $cert['cert']; $done = []; $courses = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $k = self::get($id);
            if (!$k || $k['status'] !== 'draft') continue;
            $now = date('Y-m-d H:i:s');
            $sha1 = sha1(json_encode([
                'id' => $k['id'], 'client_id' => $k['client_id'], 'dt' => $k['consultation_datetime'], 'duration' => $k['duration_minutes'],
                'description' => $k['description'], 'approved_by' => $approverName, 'approved_at' => $now,
                'cert_fingerprint' => $c['cert_fingerprint'], 'cert_subject' => $c['cert_subject'], 'ika_verified_at' => $now,
            ], JSON_UNESCAPED_UNICODE));
            db()->prepare("UPDATE k30_consultations SET status='completed', confirmed=1, approved_by_name=?, sha1sum=?, cert_fingerprint=?, cert_subject=?,
                                  ika_verified_at=?, updated_at=datetime('now') WHERE id=? AND status='draft'")
                ->execute([$approverName, $sha1, $c['cert_fingerprint'], $c['cert_subject'], $now, $id]);
            if ($k['duration_minutes']) db()->prepare("UPDATE k30_clients SET used=used+?, updated_at=datetime('now') WHERE id=?")->execute([round($k['duration_minutes'] / 60, 2), $k['client_id']]);
            try {
                $cl = db_one("SELECT * FROM k30_clients WHERE id=?", [(int)$k['client_id']]);
                if ($cl && ($crm = k30_get_crm_contact($cl) ?: k30_sync_to_crm($cl, $uid)))
                    k30_log_crm_activity($crm, 'meeting', 'Zatwierdzona konsultacja ' . ($k['card_no'] ?: '#' . $k['id']), (string)($k['description'] ?? ''),
                        (string)$k['consultation_datetime'], 'done', 'Zatwierdził: ' . $approverName . ' · SHA1: ' . substr($sha1, 0, 12) . '…', $uid);
            } catch (\Throwable $e) { error_log('ti_consultations CRM: ' . $e->getMessage()); }
            $done[] = $id;
        }
        // EZD i zamknięcie szkolenia SzK — dopiero po zatwierdzeniu przez kierownika (managerApprove)
        return ['ok' => true, 'errors' => [], 'approved' => $done, 'closed' => null];
    }

    public static function cancel(int $id): bool
    {
        $r = db()->prepare("UPDATE k30_consultations SET status='cancelled', updated_at=datetime('now') WHERE id=? AND status='draft'");
        $r->execute([$id]);
        return $r->rowCount() > 0;
    }

    // ── Zamknięcie grupy SzK ────────────────────────────────────────────────

    /**
     * Czy grupę uproszczoną można zamknąć: wszystkie terminy minęły (albo odwołane), każdy odbyty termin ma karty,
     * żadna karta nie jest robocza. Zwraca też powód, gdy nie.
     * @return array{ok:bool, reason:string, cards:int}
     */
    public static function groupClosable(int $courseId): array
    {
        $c = db_one("SELECT simple_mode, closed_at FROM k30_ti_courses WHERE id=?", [$courseId]);
        if (!$c || (int)$c['simple_mode'] <= 0) return ['ok' => false, 'reason' => 'To nie jest grupa uproszczona.', 'cards' => 0];
        if (!empty($c['closed_at'])) return ['ok' => false, 'reason' => 'Grupa jest już zamknięta.', 'cards' => 0];
        $left = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE course_id=? AND draft_id IS NULL AND status IN ('planned','reserved') ", [$courseId])['n'] ?? 0);
        if ($left) return ['ok' => false, 'reason' => 'Zostały terminy bez rozliczenia (zaplanowane): ' . $left . '.', 'cards' => 0];
        $noCard = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions s WHERE s.course_id=? AND s.status IN ('held','individual_change','remote_material')
                                  AND NOT EXISTS (SELECT 1 FROM k30_consultations k WHERE k.session_id=s.id AND k.status<>'cancelled')", [$courseId])['n'] ?? 0);
        if ($noCard) return ['ok' => false, 'reason' => 'Odbyte terminy bez karty konsultacji: ' . $noCard . '.', 'cards' => 0];
        $draft = (int)(db_one("SELECT COUNT(*) AS n FROM k30_consultations WHERE course_id=? AND status='draft'", [$courseId])['n'] ?? 0);
        if ($draft) return ['ok' => false, 'reason' => 'Niepodpisane (robocze) karty: ' . $draft . '.', 'cards' => 0];
        $nomgr = (int)(db_one("SELECT COUNT(*) AS n FROM k30_consultations WHERE course_id=? AND status='completed' AND mgr_approved_at IS NULL", [$courseId])['n'] ?? 0);
        if ($nomgr) return ['ok' => false, 'reason' => 'Karty czekające na zatwierdzenie przez kierownika: ' . $nomgr . '.', 'cards' => 0];
        $cards = (int)(db_one("SELECT COUNT(*) AS n FROM k30_consultations WHERE course_id=? AND status='completed'", [$courseId])['n'] ?? 0);
        return ['ok' => true, 'reason' => '', 'cards' => $cards];
    }

    /** Zamyka i archiwizuje grupę (ti_course_close) i rejestruje jej karty w EZD. $force — kadra może zamknąć mimo braków. */
    public static function closeGroup(int $courseId, int $uid, string $byName, bool $auto = false): ?int
    {
        ti_course_close($courseId, $uid, $byName);
        ti_course_log($courseId, 'consultations_close', $auto ? 'Automatycznie po zatwierdzeniu ostatniej karty konsultacji.' : 'Zamknięcie konsultacji (karty → EZD).', $uid, $byName);
        return self::ezdActive() ? self::ezdGroup($courseId, $uid) : null;
    }

    // ── PDF ──────────────────────────────────────────────────────────────────

    /** HTML jednej karty (do PDF; kilka kart = kilka stron). */
    private static function cardHtml(array $k): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $rows = [
            ['Numer karty', $k['card_no'] ?: '#' . $k['id']],
            ['Beneficjent', $k['client_name'] ?? ''],
            ['Data i godzina', date('d.m.Y H:i', strtotime((string)$k['consultation_datetime']))],
            ['Czas trwania', $k['duration_minutes'] ? (int)$k['duration_minutes'] . ' min' : '—'],
            ['Konsultant', $k['consultant_name'] ?? '—'],
            ['Etap', self::STAGES[self::stage($k)][0]],
        ];
        if (!empty($k['course_name'])) $rows[] = ['Szkolenie krótkie', $k['course_name']];
        $h = '<h1>Karta konsultacji</h1><table class="t">';
        foreach ($rows as [$a, $b]) $h .= '<tr><th>' . $e($a) . '</th><td>' . $e($b) . '</td></tr>';
        $h .= '</table>';
        if ($ir = self::intakeRows($k)) {
            $h .= '<h2>Zgłoszenie</h2><table class="t">';
            foreach ($ir as [$a, $b]) $h .= '<tr><th>' . $e($a) . '</th><td>' . nl2br($e($b)) . '</td></tr>';
            $h .= '</table>';
        }
        $h .= '<h2>Opis konsultacji</h2><div class="box">' . nl2br($e($k['description'] ?: '—')) . '</div>'
            . '<h2>Następne działania</h2><div class="box">' . nl2br($e($k['next_action'] ?: '—')) . '</div>';
        if ($k['status'] === 'completed') {
            $h .= '<h2>Zatwierdzenie</h2><table class="t">'
                . '<tr><th>Zatwierdził(a)</th><td>' . $e($k['approved_by_name']) . '</td></tr>'
                . '<tr><th>Weryfikacja IKA</th><td>' . $e($k['ika_verified_at'] ? date('d.m.Y H:i:s', strtotime((string)$k['ika_verified_at'])) : '—') . '</td></tr>'
                . '<tr><th>Certyfikat x509</th><td>' . $e($k['cert_subject']) . '<br><span class="mono">' . $e($k['cert_fingerprint']) . '</span></td></tr>'
                . '<tr><th>SHA1 danych karty</th><td class="mono">' . $e($k['sha1sum']) . '</td></tr>'
                . '<tr><th>Zatwierdzenie kierownika</th><td>' . (!empty($k['mgr_approved_at']) ? $e($k['mgr_approved_name']) . ', ' . $e(date('d.m.Y H:i', strtotime((string)$k['mgr_approved_at']))) : 'oczekuje') . '</td></tr></table>';
        } else {
            $h .= '<p class="muted">Karta robocza — bez podpisu elektronicznego; dokument do podpisu odręcznego.</p>';
        }
        // Podpisy odręczne: beneficjent zawsze; konsultant — gdy karta nie jest podpisana elektronicznie (IKA + x509)
        $h .= '<table class="sig"><tr><td>' . "\n\n" . '</td><td></td></tr><tr><th>data i podpis beneficjenta / opiekuna</th>'
            . ($k['status'] === 'completed' ? '<th>podpisano elektronicznie — ' . $e($k['approved_by_name']) . '</th>' : '<th>data i podpis konsultanta</th>') . '</tr></table>';
        return $h;
    }

    /** PDF jednej lub wielu kart (ids w kolejności). */
    public static function pdf(array $ids): string
    {
        $css = '<style>body{font-family:dejavusans;font-size:10pt;color:#111}h1{font-size:15pt;margin:0 0 3mm}h2{font-size:11pt;margin:5mm 0 1.5mm}
                table.t{border-collapse:collapse;width:100%}table.t th,table.t td{border:0.3mm solid #666;padding:1.8mm;text-align:left;vertical-align:top}
                table.t th{background:#eef2f7;width:42mm}.box{border:0.3mm solid #666;padding:2.5mm;min-height:18mm}
                .mono{font-family:dejavusansmono;font-size:8pt}.muted{color:#555}
                table.sig{width:100%;margin-top:14mm;border-collapse:separate;border-spacing:8mm 0}table.sig td{height:14mm}
                table.sig th{border-top:0.3mm solid #333;font-weight:normal;font-size:8.5pt;text-align:center;padding-top:1.5mm;width:50%}</style>';
        $parts = []; $marks = [];
        foreach ($ids as $id) if ($k = self::get((int)$id)) { $parts[] = self::cardHtml($k); $marks[] = self::watermark($k); }
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = (defined('UPLOAD_DIR') ? UPLOAD_DIR : sys_get_temp_dir() . '/') . 'mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $pdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 18, 'margin_right' => 18, 'margin_top' => 16, 'margin_bottom' => 16,
                                'default_font' => 'dejavusans', 'tempDir' => $tmp]);
        $pdf->SetTitle('Karta konsultacji');
        $pdf->SetFooter((defined('ORG_NAME') ? ORG_NAME : '') . '||{PAGENO}/{nbpg}');
        // Znak wodny per karta: ROBOCZA / NIEPODPISANA (podpisana, bez zatwierdzenia kierownika); zatwierdzona — bez znaku
        $pdf->WriteHTML($css);
        foreach ($parts as $i => $html) {
            if ($i > 0) $pdf->AddPage();
            $pdf->SetWatermarkText($marks[$i], $marks[$i] !== '' ? 0.13 : 0);
            $pdf->showWatermarkText = $marks[$i] !== '';
            $pdf->WriteHTML($html);
        }
        if (!$parts) $pdf->WriteHTML('<p>Brak kart.</p>');
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** Tekst znaku wodnego dla etapu karty ('' = bez znaku). */
    public static function watermark(array $k): string
    {
        return match (self::stage($k)) { 'draft' => 'ROBOCZA', 'signed' => 'NIEPODPISANA', 'cancelled' => 'ANULOWANA', default => '' };
    }

    public static function pdfName(array $k): string
    {
        return 'karta-konsultacji-' . preg_replace('/[^A-Za-z0-9.]+/', '-', ($k['card_no'] ?? '') ?: (string)$k['id']) . '.pdf';
    }

    // ── EZD (JRWA 381) ───────────────────────────────────────────────────────

    public static function ezdActive(): bool
    {
        return function_exists('module_enabled') && module_enabled('ezd_enabled') && org_setting('ti_consultations_ezd_auto') !== '0';
    }

    /** Segregator roczny klasy 381 (zakłada, gdy brak); null, gdy w JRWA nie ma klasy 381. */
    private static function ezdTeczka(int $uid): ?int
    {
        require_once dirname(__DIR__, 3) . '/includes/ezd.php';
        $rok = (int)date('Y');
        $t = db_one("SELECT id FROM ezd_teczki WHERE symbol=? AND rok=? AND status='open' ORDER BY id DESC LIMIT 1", [self::EZD_JRWA, $rok]);
        if ($t) return (int)$t['id'];
        $j = db_one("SELECT id, title FROM ezd_jrwa WHERE symbol=?", [self::EZD_JRWA]);
        if (!$j) return null;
        return (int)ezd_teczka_create(['jrwa_id' => (int)$j['id'], 'symbol' => self::EZD_JRWA, 'title' => $j['title'] . ' ' . $rok, 'rok' => $rok, 'owner_id' => null], $uid);
    }

    /** Koszulka po ref (nowa, gdy brak) + pismo wewnętrzne z PDF. */
    private static function ezdFile(string $refType, int $refId, string $title, string $desc, string $pdf, string $file, int $uid): ?int
    {
        try {
            $teczka = self::ezdTeczka($uid);
            if (!$teczka) return null;
            $rows = function_exists('ezd_sprawy_by_ref') ? ezd_sprawy_by_ref($refType, $refId) : [];
            $sid = $rows ? (int)$rows[0]['id'] : (int)ezd_sprawa_create([
                'teczka_id' => $teczka, 'title' => $title, 'description' => $desc, 'status' => 'open', 'priority' => 'normal',
                'owner_id' => $uid ?: null, 'ref_type' => $refType, 'ref_id' => $refId,
            ], $uid);
            $pid = (int)ezd_pismo_create([
                'sprawa_id' => $sid, 'kierunek' => 'wewnetrzne', 'title' => $title, 'tresc' => 'PDF dołączony automatycznie z panelu dydaktyka TI.',
                'data_pisma' => date('Y-m-d'), 'status' => 'zakonczone', 'owner_id' => $uid ?: null, 'rodzaj_medium' => 'inne',
            ], $uid);
            $tmp = tempnam(sys_get_temp_dir(), 'ticons_');
            if ($tmp !== false) { file_put_contents($tmp, $pdf); try { ezd_attach_path($tmp, $file, $sid, $pid, $uid); } finally { @unlink($tmp); } }
            return $sid;
        } catch (\Throwable $e) {
            error_log('ti_consultations EZD ' . $refType . ' #' . $refId . ': ' . $e->getMessage());
            return null;
        }
    }

    public static function ezdCard(int $id, int $uid): ?int
    {
        $k = self::get($id);
        if (!$k || self::stage($k) !== 'approved') return null;
        return self::ezdFile('ti_konsultacja', $id, 'Karta konsultacji ' . ($k['card_no'] ?: '#' . $id) . ' — ' . $k['client_name'],
            'Zatwierdzona karta konsultacji (IKA + x509), SHA1 ' . $k['sha1sum'], self::pdf([$id]), self::pdfName($k), $uid);
    }

    public static function ezdGroup(int $courseId, int $uid): ?int
    {
        $c = db_one("SELECT name, internal_desc FROM k30_ti_courses WHERE id=?", [$courseId]);
        $ids = array_map(fn($r) => (int)$r['id'], db_all("SELECT id FROM k30_consultations WHERE course_id=? AND status='completed' AND mgr_approved_at IS NOT NULL ORDER BY consultation_datetime, id", [$courseId]));
        if (!$c || !$ids) return null;
        return self::ezdFile('ti_konsultacje_grupa', $courseId, 'Karty konsultacji — ' . $c['name'],
            'Grupa uproszczona ' . $c['name'] . (trim((string)$c['internal_desc']) !== '' ? ' (' . $c['internal_desc'] . ')' : '') . '; kart: ' . count($ids) . '.',
            self::pdf($ids), 'karty-konsultacji-' . preg_replace('/[^A-Za-z0-9.]+/', '-', (string)$c['name']) . '.pdf', $uid);
    }
}
