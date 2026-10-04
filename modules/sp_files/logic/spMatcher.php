<?php
/**
 * modules/sp_files/logic/spMatcher.php — dopasowanie skanów z SharePointa do umów bez pliku.
 *
 * Umowy wgrane do systemu bez pliku (albo z plikiem, którego nie ma ani na serwerze, ani na SharePoincie) mają często
 * skan położony na SharePoincie ręcznie, pod nazwą nieznaną aplikacji. SpMatcher porównuje takie umowy z lokalnym indeksem
 * biblioteki (SpIndex) i PROPONUJE pary umowa → plik z oceną pewności i uzasadnieniem. Niczego nie przypisuje sam:
 * przypisanie (attach) robi administrator po obejrzeniu propozycji — wtedy plik jest pobierany, sprawdzany i zapisywany
 * jako plik umowy (plik_umowy), dokładnie jak zwykły upload.
 *
 * Punktacja (0–100) pary umowa–plik; porównujemy tokeny: małe litery, polskie znaki bez ogonków, rozdzielane każdym
 * znakiem niealfanumerycznym i granicą litera/cyfra, zera wiodące liczb pomijane (WOL/0001/2026 = „wol 1 2026”):
 *   numer umowy jako ciągły fragment nazwy/ścieżki pliku    +70   (WOL_0001_2026, WOL 0001 2026, wol-1-2026 …)
 *   numer z rejestru umów (RU/…) / numer roboczy / systemowy +65 / +55
 *   elementy numeru w dowolnej kolejności                    +35   (tylko numery o ≥3 elementach)
 *   strona umowy: imię i nazwisko / nazwa (min. 2 człony; odmiana nazwiska tolerowana) +40, tylko część nazwy +15; przy zgodnym numerze tylko +10 / +4
 *   rok zgodny z rokiem umowy +5 (inny rok w nazwie, gdy brak numeru: −10); data zawarcia w nazwie +10
 *   słowo „umowa” +2, słowo rodzaju umowy +3 (słowo innego rodzaju −8); numer projektu +8
 *   słowa aneks / załącznik / rachunek / protokół / oświadczenie / zgoda / wzór / CV / zaświadczenie … −40 (to nie główny plik umowy)
 *   po numerze w nazwie jeszcze kod („…_P1”, „…_2”) −12 (może to inny dokument), samo nazwisko przy kilku umowach tej osoby −8
 * Poziomy: wysoka ≥70 (praktycznie zawsze numer), średnia ≥40, niska ≥15; poniżej — nie pokazujemy.
 *
 * Konflikty: (1) plik, którego nazwa wskazuje numerem INNĄ umowę (także taką, która ma już plik), nie jest proponowany
 * przez nazwisko; (2) dłuższy numer zasłania krótszy (WOL/0001/2026/P1 vs WOL/0001/2026); (3) plik pasujący do kilku umów
 * z podobną punktacją (np. zdublowany numer, ta sama osoba) dostaje ostrzeżenie i najwyżej „średnią”; (4) dwa niemal
 * równe pliki dla jednej umowy — też najwyżej „średnia”. Odrzucone przez administratora pary nie wracają.
 *
 * Przypisanie (attach): umowa musi nadal nie mieć pliku (albo mieć wskazanie do pliku, którego nie ma lokalnie
 * i którego oryginału nie ma na SharePoincie); plik jest pobierany po ID, sprawdzany (istnieje, nazwa i rozmiar jak w indeksie,
 * ≤ 20 MB, rozszerzenie pdf/jpg/jpeg/png/docx, nagłówek zgodny z rozszerzeniem), zapisywany pod nazwą w stylu aplikacji
 * {rodzaj}/{RRRRMMDD_GGMMSS}_{hex8}.{ext} i wpisywany w plik_umowy w jednej transakcji z audytem (sp_files.attached).
 * Potem kopia trafia na SharePoint pod nazwą aplikacji (jak przy każdym uploadzie), więc odzyskiwanie plików działa dalej.
 */
require_once __DIR__ . '/spIndex.php';

final class SpMatcher
{
    /** Limit rozmiaru przypisywanego skanu — jak limit uploadu umów (handle_upload). */
    public const ATTACH_MAX_BYTES = 20 * 1024 * 1024;

    public const LEVEL_MIN    = ['high' => 70, 'medium' => 40, 'low' => 15];
    public const LEVEL_LABELS = ['high' => 'wysoka', 'medium' => 'średnia', 'low' => 'niska'];

    private const FOLD = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a',
        'í' => 'i', 'ñ' => 'n', 'ç' => 'c', 'š' => 's', 'č' => 'c', 'ž' => 'z', 'ř' => 'r', 'ě' => 'e', 'ů' => 'u', 'ý' => 'y',
    ];

    /** Tokeny, które nic nie mówią o osobie / podmiocie (formy prawne, tytuły). */
    private const NAME_STOP = ['sp', 'zoo', 'ska', 'spzoo', 'sa', 'sc', 'sj', 'spk', 'oo', 'fundacja', 'stowarzyszenie', 'firma', 'dr', 'mgr', 'inz', 'prof', 'pan', 'pani', 'jr', 'sr'];

    /** Początki słów, które wskazują, że plik to nie główny dokument umowy. */
    private const EXCLUDE_STEMS = ['aneks', 'zalacznik', 'rachunek', 'protokol', 'oswiadczeni', 'potwierdzeni', 'zgod', 'rodo', 'faktur', 'pelnomocnictw',
                                   'wniosek', 'wnioski', 'rezygnacj', 'wypowiedzeni', 'rozwiazani', 'ewidencj', 'sprawozdani', 'wzor', 'szablon',
                                   'cv', 'zaswiadczeni', 'dowod', 'paszport', 'legitymacj', 'skierowani', 'badan', 'orzeczeni', 'polis', 'ubezpieczeni',
                                   'certyfikat', 'dyplom', 'swiadectw'];

    /** Początki słów charakterystycznych dla rodzaju umowy. */
    private const TYPE_STEMS = [
        'wolontariat' => ['wolontar', 'porozumieni'], 'zlecenie' => ['zleceni'], 'dzielo' => ['dziel'], 'praca' => ['prac'],
        'uslugi' => ['uslug'], 'powierzenie' => ['powierzeni'], 'inne' => [],
    ];

    // ── Tokeny ───────────────────────────────────────────────────────────────

    /** Małe litery, polskie i inne znaki diakrytyczne zdjęte (także rozłożone, jak w nazwach z macOS). */
    public static function fold(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        return strtr($s, self::FOLD);
    }

    /**
     * Tokeny tekstu: rozdzielane wszystkim, co nie jest literą/cyfrą, oraz granicą litera↔cyfra („WOL0001” → wol, 1);
     * liczby bez zer wiodących („0001” → „1”).
     *
     * @return list<string>
     */
    public static function tokens(string $s): array
    {
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', self::fold($s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
            foreach (preg_split('/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/', $w) ?: [] as $t) {
                if ($t === '') continue;
                if (ctype_digit($t)) { $t = ltrim($t, '0'); if ($t === '') $t = '0'; }
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Wycina z tekstu daty (RRRR-MM-DD, DD.MM.RRRR, RRRRMMDD; separatory - . / _) i zwraca [tekst bez dat, daty Y-m-d].
     *
     * @return array{0:string,1:list<string>}
     */
    public static function extractDates(string $s): array
    {
        $dates = [];
        $rules = [
            ['/(?<!\d)((?:19|20)\d{2})[-._\/](0?[1-9]|1[0-2])[-._\/](0?[1-9]|[12]\d|3[01])(?!\d)/', [1, 2, 3]],
            ['/(?<!\d)(0?[1-9]|[12]\d|3[01])[-._\/](0?[1-9]|1[0-2])[-._\/]((?:19|20)\d{2})(?!\d)/', [3, 2, 1]],
            ['/(?<!\d)((?:19|20)\d{2})(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])(?!\d)/', [1, 2, 3]],
        ];
        foreach ($rules as [$re, $map]) {
            $s = preg_replace_callback($re, function (array $m) use (&$dates, $map): string {
                [$y, $mo, $d] = [(int)$m[$map[0]], (int)$m[$map[1]], (int)$m[$map[2]]];
                if (!checkdate($mo, $d, $y)) return $m[0];
                $dates[] = sprintf('%04d-%02d-%02d', $y, $mo, $d);
                return ' ';
            }, $s) ?? $s;
        }
        return [$s, array_values(array_unique($dates))];
    }

    /** Czy token(y) nadają się do identyfikacji numeru: ≥2 elementy i przynajmniej jedna liczba różna od zera (odsiewa „0000”, „---”). */
    private static function identifying(array $tokens): array
    {
        if (count($tokens) < 2) return [];
        foreach ($tokens as $t) if (ctype_digit($t) && $t !== '0') return $tokens;
        return [];
    }

    /** Człony nazwy osoby / podmiotu istotne dla dopasowania (≥3 znaki, nie liczby, nie formy prawne). @return list<string> */
    private static function nameTokens(string $name): array
    {
        $out = [];
        foreach (self::tokens($name) as $t) {
            if (strlen($t) < 3 || ctype_digit($t) || in_array($t, self::NAME_STOP, true)) continue;
            $out[$t] = $t;
        }
        return array_values($out);
    }

    private static function nameKey(array $tokens): string
    {
        $t = $tokens; sort($t);
        return implode(' ', $t);
    }

    /** Czy dwa tokeny to ta sama (odmieniona) nazwa: ≥6 znaków, różnica długości ≤3 i wspólny początek ≥ max(5, krótszy−2). */
    private static function similar(string $a, string $b): bool
    {
        $la = strlen($a); $lb = strlen($b);
        if ($la < 6 || $lb < 6 || abs($la - $lb) > 3) return false;
        $max = min($la, $lb); $common = 0;
        while ($common < $max && $a[$common] === $b[$common]) $common++;
        return $common >= max(5, $max - 2);
    }

    /** Pozycja ciągłego fragmentu $needle w $hay albo -1. */
    private static function runIndex(array $hay, array $needle): int
    {
        $n = count($needle); $h = count($hay);
        if ($n === 0 || $n > $h) return -1;
        $first = $needle[0];
        for ($i = 0; $i <= $h - $n; $i++) {
            if ($hay[$i] !== $first) continue;
            for ($j = 1; $j < $n; $j++) if ($hay[$i + $j] !== $needle[$j]) continue 2;
            return $i;
        }
        return -1;
    }

    private static function hasAlpha(array $tokens): bool
    {
        foreach ($tokens as $t) if (!ctype_digit((string)$t)) return true;
        return false;
    }

    /** Pierwszy token zaczynający się od któregoś z początków słów albo null. */
    private static function stemHit(array $tokens, array $stems): ?string
    {
        foreach ($tokens as $t) foreach ($stems as $s) if (str_starts_with((string)$t, $s)) return (string)$t;
        return null;
    }

    // ── Przygotowanie danych ─────────────────────────────────────────────────

    /**
     * Rekord umowy z polami pochodnymi (tokeny numerów i nazw, rok). Wejście: type, id, number, register, internal[], names[],
     * signed (Y-m-d), project, status, current, lost, target.
     */
    public static function prepare(array $c): array
    {
        if (!empty($c['_p'])) return $c;
        $c += ['type' => '', 'id' => 0, 'number' => '', 'register' => '', 'internal' => [], 'names' => [], 'signed' => '', 'project' => '',
               'status' => '', 'current' => '', 'lost' => false, 'target' => true];
        $c['key']        = $c['type'] . ':' . $c['id'];
        $c['num_tokens'] = self::identifying(self::tokens((string)$c['number']));
        $c['reg_tokens'] = self::identifying(self::tokens((string)$c['register']));
        $c['int_tokens'] = [];
        foreach ((array)$c['internal'] as $k => $v) {
            $t = self::identifying(self::tokens((string)$v));
            if ($t) $c['int_tokens'][$k] = $t;
        }
        $c['name_tokens'] = [];
        foreach ((array)$c['names'] as $nm) {
            $t = self::nameTokens((string)$nm);
            if ($t) $c['name_tokens'][] = ['label' => trim((string)$nm), 'tokens' => $t];
        }
        $year = preg_match('/^(\d{4})-\d{2}-\d{2}/', (string)$c['signed'], $m) ? (int)$m[1] : null;
        if (!$year) foreach ($c['num_tokens'] as $t) if (preg_match('/^(19|20)\d{2}$/', $t)) { $year = (int)$t; break; }
        $c['year']        = $year;
        $proj             = self::tokens((string)$c['project']);
        $c['proj_tokens'] = count($proj) >= 2 ? $proj : [];
        $c['name_dups']   = $c['name_dups'] ?? 1;
        $c['_p']          = true;
        return $c;
    }

    /** Wpis indeksu z polami pochodnymi: tokens (ścieżka bez rozszerzenia), clean (bez dat), dates, years, set. */
    public static function prepareFile(array $f): array
    {
        if (isset($f['set'])) return $f;
        $stem = preg_replace('~\.[A-Za-z0-9]{1,5}$~', '', (string)($f['path'] ?? $f['name'] ?? '')) ?? '';
        [$noDates, $dates] = self::extractDates($stem);
        $f['tokens'] = self::tokens($stem);
        $f['clean']  = self::tokens($noDates);
        $f['dates']  = $dates;
        $years = [];
        foreach ($f['tokens'] as $t) if (preg_match('/^(19|20)\d{2}$/', $t)) $years[(int)$t] = true;
        foreach ($dates as $d) $years[(int)substr($d, 0, 4)] = true;
        $f['years']  = array_keys($years);
        $f['set']    = array_flip($f['tokens']);
        return $f;
    }

    // ── Punktacja pary ───────────────────────────────────────────────────────

    /** Źródła numeru umowy do wyszukania w nazwach plików: [kind, label, tokens, base]. @return list<array> */
    private static function numberSources(array $c): array
    {
        $out = []; $seen = [];
        $add = function (string $kind, string $label, array $tokens, int $base) use (&$out, &$seen): void {
            $k = implode(' ', $tokens);
            if ($tokens === [] || isset($seen[$k])) return;
            $seen[$k] = true;
            $out[] = ['kind' => $kind, 'label' => $label, 'tokens' => $tokens, 'base' => $base];
        };
        $add('full', (string)$c['number'], $c['num_tokens'], 70);
        $add('register', (string)$c['register'], $c['reg_tokens'], 65);
        foreach ($c['int_tokens'] as $k => $tok) $add('internal', (string)$c['internal'][$k], $tok, 55);
        return $out;
    }

    /**
     * Trafienie numeru w pliku: ['kind','label','score','tokens','trailing','loose'] albo null. Ciągły fragment tokenów = pełny wynik;
     * $loose (tylko dla numeru umowy o ≥3 elementach): wszystkie elementy są w nazwie, ale nie obok siebie = 35.
     * Numer złożony wyłącznie z liczb (np. 12/2026) nie liczy się, gdy to fragment daty albo sąsiaduje z inną liczbą.
     */
    private static function numberHit(array $src, array $f, bool $loose): ?array
    {
        $needle = $src['tokens']; $k = count($needle);
        if (self::runIndex($f['tokens'], $needle) >= 0) {
            $j = self::runIndex($f['clean'], $needle);
            if (!self::hasAlpha($needle)) {
                if ($j < 0) return null;                                    // tylko jako fragment daty
                $prev = $j > 0 ? $f['clean'][$j - 1] : null;
                $next = $f['clean'][$j + $k] ?? null;
                if (($prev !== null && ctype_digit($prev)) || ($next !== null && ctype_digit($next))) return null;
            }
            $trailing = null;
            if ($j >= 0) {
                $next = $f['clean'][$j + $k] ?? null; $next2 = $f['clean'][$j + $k + 1] ?? null;
                if ($next !== null && (strlen($next) === 1 || preg_match('/^\d{1,2}$/', $next))) {
                    $trailing = $next . (strlen($next) === 1 && $next2 !== null && preg_match('/^\d{1,2}$/', $next2) ? ' ' . $next2 : '');
                }
            }
            return ['kind' => $src['kind'], 'label' => $src['label'], 'score' => $src['base'], 'tokens' => $needle, 'trailing' => $trailing, 'loose' => false];
        }
        if ($loose && $k >= 3) {
            foreach ($needle as $t) if (!isset($f['set'][$t])) return null;
            return ['kind' => $src['kind'], 'label' => $src['label'], 'score' => 35, 'tokens' => $needle, 'trailing' => null, 'loose' => true];
        }
        return null;
    }

    /** Ile członów nazwy znaleziono w tokenach pliku: [m, najdłuższy dopasowany człon]. */
    private static function nameMatch(array $nameTokens, array $set): array
    {
        $m = 0; $longest = 0;
        foreach ($nameTokens as $t) {
            $hit = isset($set[$t]);
            if (!$hit && strlen($t) >= 6) {
                foreach ($set as $ft => $_) { if (self::similar($t, (string)$ft)) { $hit = true; break; } }
            }
            if ($hit) { $m++; $longest = max($longest, strlen($t)); }
        }
        return [$m, $longest];
    }

    /**
     * Ocena jednej pary umowa–plik.
     *
     * @return array{score:int,level:string,evidence:list<string>,warnings:list<string>,number_kind:string}
     *         level: high|medium|low|none
     */
    public static function score(array $c, array $f): array
    {
        $c = self::prepare($c); $f = self::prepareFile($f);
        $ev = []; $warn = []; $score = 0;

        $best = null;
        foreach (self::numberSources($c) as $src) {
            $h = self::numberHit($src, $f, $src['kind'] === 'full');
            if ($h && ($best === null || $h['score'] > $best['score'])) $best = $h;
        }
        if ($best) {
            $score += $best['score'];
            $ev[] = match (true) {
                $best['loose']                  => 'elementy numeru „' . $best['label'] . '” są w nazwie pliku, ale nie w tej kolejności',
                $best['kind'] === 'register'    => 'numer z rejestru umów „' . $best['label'] . '” w nazwie pliku',
                $best['kind'] === 'internal'    => 'numer roboczy/systemowy „' . $best['label'] . '” w nazwie pliku',
                default                         => 'numer umowy „' . $best['label'] . '” w nazwie pliku',
            };
            if ($best['trailing'] !== null) {
                $score -= 12;
                $warn[] = 'po numerze w nazwie pliku jest jeszcze „' . mb_strtoupper(str_replace(' ', '', $best['trailing'])) . '” — to może być inny dokument (np. aneks)';
            }
        }

        $name = null;
        foreach ($c['name_tokens'] as $nt) {
            [$m, $longest] = self::nameMatch($nt['tokens'], $f['set']);
            $n = count($nt['tokens']);
            if ($m === 0) continue;
            $cand = ['label' => $nt['label'], 'm' => $m, 'n' => $n, 'longest' => $longest];
            if ($name === null || [$m, $longest] > [$name['m'], $name['longest']]) $name = $cand;
        }
        if ($name) {
            if ($name['n'] >= 2 && $name['m'] >= 2) {
                $score += $best ? 10 : 40;
                $ev[] = 'strona umowy „' . $name['label'] . '” (imię i nazwisko / nazwa) w ścieżce pliku';
            } elseif ($name['longest'] >= 5) {
                $solo = $name['n'] === 1;
                $score += $best ? 4 : ($solo ? 30 : 15);
                $ev[] = ($solo ? 'strona umowy „' : 'tylko część nazwy strony umowy „') . $name['label'] . '” w ścieżce pliku';
            }
            $dupName = !$best && $name['longest'] >= 5 && (int)$c['name_dups'] > 1;
        }

        $yearHit = false;
        if ($c['year']) {
            if (in_array($c['year'], $f['years'], true)) { $score += 5; $yearHit = true; $ev[] = 'rok ' . $c['year'] . ' zgodny z umową'; }
            elseif ($f['years'] && !$best) { $score -= 10; $warn[] = 'w nazwie pliku jest inny rok (' . implode(', ', $f['years']) . ') niż w umowie (' . $c['year'] . ')'; }
        }
        // kilka umów tej osoby: samo nazwisko nie rozstrzyga, chyba że rok w nazwie pliku wskazuje właściwą
        if (!empty($dupName) && !$yearHit) {
            $score -= 8;
            $warn[] = 'ta osoba ma w tym rodzaju umów ' . (int)$c['name_dups'] . ' umowy — samo nazwisko nie rozstrzyga, która to';
        }
        if ($c['signed'] !== '' && in_array((string)$c['signed'], $f['dates'], true)) {
            $score += 10; $ev[] = 'data zawarcia umowy (' . $c['signed'] . ') w nazwie pliku';
        }

        $own = self::TYPE_STEMS[$c['type']] ?? [];
        if ($own && self::stemHit($f['tokens'], $own) !== null) { $score += 3; $ev[] = 'słowo wskazujące rodzaj umowy'; }
        else {
            foreach (self::TYPE_STEMS as $t => $stems) {
                if ($t !== $c['type'] && $stems && self::stemHit($f['tokens'], $stems) !== null) { $score -= 8; $warn[] = 'w nazwie pliku jest słowo innego rodzaju umowy'; break; }
            }
        }
        if (self::stemHit($f['tokens'], ['umow']) !== null) { $score += 2; $ev[] = 'słowo „umowa” w nazwie pliku'; }
        if ($c['proj_tokens'] && self::runIndex($f['tokens'], $c['proj_tokens']) >= 0) { $score += 8; $ev[] = 'numer projektu „' . $c['project'] . '” w nazwie pliku'; }

        if (($x = self::stemHit($f['tokens'], self::EXCLUDE_STEMS)) !== null) {
            $score -= 40;
            $warn[] = 'w ścieżce pliku jest „' . $x . '” — to raczej nie główny dokument umowy';
        }

        $score = max(0, min(100, $score));
        return ['score' => $score, 'level' => self::levelOf($score), 'evidence' => $ev, 'warnings' => $warn, 'number_kind' => $best['kind'] ?? ''];
    }

    public static function levelOf(int $score): string
    {
        return $score >= self::LEVEL_MIN['high'] ? 'high' : ($score >= self::LEVEL_MIN['medium'] ? 'medium' : ($score >= self::LEVEL_MIN['low'] ? 'low' : 'none'));
    }

    private static function rank(string $level): int
    {
        return ['high' => 3, 'medium' => 2, 'low' => 1][$level] ?? 0;
    }

    // ── Propozycje ───────────────────────────────────────────────────────────

    /** Pliki z listy, w których występuje najrzadszy z tokenów (wszystkie $tokens muszą być w pliku, inaczej pusto). @return list<int> */
    private static function candidatesByTokens(array $tokens, array $post): array
    {
        $best = null;
        foreach (array_unique($tokens) as $t) {
            if (!isset($post[$t])) return [];
            if ($best === null || count($post[$t]) < count($post[$best])) $best = $t;
        }
        return $best === null ? [] : $post[$best];
    }

    /** Pliki, w których występuje nazwa osoby / podmiotu (człony ≥5 znaków, z odmianą; bez nich — najrzadszy człon). @return list<int> */
    private static function nameCandidates(array $c, array $post, array $byPrefix): array
    {
        $out = [];
        foreach ($c['name_tokens'] as $nt) {
            $use = array_filter($nt['tokens'], fn($t) => strlen($t) >= 5);
            if (!$use) {
                $rare = null;
                foreach ($nt['tokens'] as $t) if (isset($post[$t]) && ($rare === null || count($post[$t]) < count($post[$rare]))) $rare = $t;
                $use = $rare !== null ? [$rare] : [];
            }
            foreach ($use as $t) {
                foreach ($post[$t] ?? [] as $i) $out[$i] = true;
                if (strlen($t) >= 6) {
                    foreach ($byPrefix[substr($t, 0, 4)] ?? [] as $v) {
                        if ($v !== $t && self::similar($t, $v)) foreach ($post[$v] as $i) $out[$i] = true;
                    }
                }
            }
        }
        return array_keys($out);
    }

    /**
     * Dopasowanie czyste (bez bazy i Graph): umowy × pliki → propozycje.
     *
     * $contracts: wszystkie umowy (target=true — te, dla których szukamy pliku; reszta służy do rozpoznawania plików
     * należących do innych umów). $files: wpisy indeksu (item_id, name, folder, path, size, web_url, …).
     * $opts: min (poziom: high|medium|low, domyślnie medium), per_contract (5), rejected (zbiór „typ:id|item_id”),
     * used (item_id → lista kluczy umów, do których plik już przypisano).
     *
     * @return array{rows:list<array>,unmatched:list<array>,stats:array}
     */
    public static function propose(array $contracts, array $files, array $opts = []): array
    {
        $minLevel = in_array($opts['min'] ?? 'medium', array_keys(self::LEVEL_MIN), true) ? (string)($opts['min'] ?? 'medium') : 'medium';
        $perC     = max(1, (int)($opts['per_contract'] ?? 5));
        $rejected = (array)($opts['rejected'] ?? []);
        $used     = (array)($opts['used'] ?? []);

        $C = [];
        foreach ($contracts as $c) { $p = self::prepare($c); $C[$p['key']] = $p; }
        $freq = [];
        foreach ($C as $c) foreach ($c['name_tokens'] as $nt) $freq[$c['type']][self::nameKey($nt['tokens'])][$c['key']] = true;
        foreach ($C as $k => $c) {
            $d = 1;
            foreach ($c['name_tokens'] as $nt) $d = max($d, count($freq[$c['type']][self::nameKey($nt['tokens'])] ?? []));
            $C[$k]['name_dups'] = $d;
        }

        $F = [];
        foreach ($files as $f) $F[] = self::prepareFile($f);
        $post = [];
        foreach ($F as $i => $f) foreach ($f['tokens'] as $t) $post[$t][$i] = $i;
        foreach ($post as $t => $ids) $post[$t] = array_values($ids);
        $byPrefix = [];
        foreach (array_keys($post) as $t) { $t = (string)$t; if (strlen($t) >= 6) $byPrefix[substr($t, 0, 4)][] = $t; }

        // 1) trafienia numerów (wszystkie umowy): plik → umowa → trafienie
        $hits = []; $mine = [];
        foreach ($C as $key => $c) {
            foreach (self::numberSources($c) as $src) {
                foreach (self::candidatesByTokens($src['tokens'], $post) as $i) {
                    $h = self::numberHit($src, $F[$i], false);
                    if ($h === null) continue;
                    if (!isset($hits[$i][$key]) || $h['score'] > $hits[$i][$key]['score']) { $hits[$i][$key] = $h; $mine[$key][$i] = true; }
                }
            }
        }
        // 2) dłuższy numer zasłania krótszy (WOL/0001/2026/P1 vs WOL/0001/2026)
        foreach ($hits as $i => $byC) {
            if (count($byC) < 2) continue;
            foreach ($byC as $ka => $ha) {
                foreach ($byC as $kb => $hb) {
                    if ($ka !== $kb && count($hb['tokens']) > count($ha['tokens']) && self::runIndex($hb['tokens'], $ha['tokens']) >= 0) {
                        unset($hits[$i][$ka], $mine[$ka][$i]);
                        break;
                    }
                }
            }
        }

        // 3) kandydaci dla umów docelowych
        $cands = []; $claims = [];
        foreach ($C as $key => $c) {
            if (empty($c['target'])) continue;
            $idx = array_fill_keys(array_keys($mine[$key] ?? []), true);
            foreach (self::nameCandidates($c, $post, $byPrefix) as $i) $idx[$i] = true;
            if (count($c['num_tokens']) >= 3) foreach (self::candidatesByTokens($c['num_tokens'], $post) as $i) $idx[$i] = true;   // numer „luźny”
            foreach (array_keys($idx) as $i) {
                $f = $F[$i];
                if (!empty($hits[$i]) && !isset($hits[$i][$key])) continue;                 // nazwa pliku wskazuje numerem inną umowę
                if (isset($rejected[$key . '|' . $f['item_id']])) continue;
                $s = self::score($c, $f);
                if ($s['score'] < self::LEVEL_MIN['low']) continue;
                $cands[$key][] = ['i' => $i, 'score' => $s['score'], 'level' => $s['level'], 'evidence' => $s['evidence'],
                                  'warnings' => $s['warnings'], 'number_kind' => $s['number_kind']];
                $claims[$i][$key] = $s['score'];
            }
        }

        // 4) konflikty, ostrzeżenia, przycięcie
        $rows = []; $unmatched = []; $stats = ['targets' => 0, 'with_proposals' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'files' => count($F)];
        foreach ($C as $key => $c) {
            if (empty($c['target'])) continue;
            $stats['targets']++;
            $list = $cands[$key] ?? [];
            usort($list, fn($a, $b) => [$b['score'], $F[$a['i']]['path']] <=> [$a['score'], $F[$b['i']]['path']]);
            foreach ($list as &$cd) {
                $rivals = [];
                foreach ($claims[$cd['i']] ?? [] as $ok => $os) {
                    if ($ok !== $key && $os >= $cd['score'] - 10) $rivals[] = (string)($C[$ok]['number'] ?: $ok);
                }
                $cd['shared_with'] = $rivals;
                if ($rivals) {
                    $cd['warnings'][] = 'ten plik pasuje też do: ' . implode(', ', array_slice($rivals, 0, 3)) . (count($rivals) > 3 ? ' i ' . (count($rivals) - 3) . ' innych' : '');
                    if ($cd['level'] === 'high') $cd['level'] = 'medium';
                }
            }
            unset($cd);
            if (count($list) >= 2 && $list[0]['score'] - $list[1]['score'] < 10) {
                foreach ([0, 1] as $n) {
                    if ($list[$n]['level'] === 'high') $list[$n]['level'] = 'medium';
                    $list[$n]['warnings'][] = 'drugi plik ma niemal tyle samo punktów — sprawdź, który jest właściwy';
                }
            }
            $visible = []; $lowHidden = 0;
            foreach ($list as $cd) {
                if (self::rank($cd['level']) >= self::rank($minLevel)) $visible[] = $cd; else $lowHidden++;
            }
            $visible = array_slice($visible, 0, $perC);
            $pub = self::publicContract($c);
            if (!$visible) { $unmatched[] = $pub + ['hidden' => $lowHidden]; continue; }
            foreach ($visible as &$cd) {
                $f = $F[$cd['i']];
                $cd['file'] = ['item_id' => (string)$f['item_id'], 'name' => (string)$f['name'], 'folder' => (string)($f['folder'] ?? ''), 'path' => (string)($f['path'] ?? $f['name']),
                               'ext' => (string)($f['ext'] ?? ''), 'size' => (int)($f['size'] ?? 0), 'web_url' => (string)($f['web_url'] ?? ''), 'modified_at' => (string)($f['modified_at'] ?? '')];
                $cd['used_by'] = [];
                foreach ((array)($used[$f['item_id']] ?? []) as $uk) $cd['used_by'][] = (string)(($C[$uk]['number'] ?? '') ?: $uk);
                if ($cd['used_by']) $cd['warnings'][] = 'ten plik został już przypisany do: ' . implode(', ', array_slice($cd['used_by'], 0, 3));
                if ($cd['file']['size'] > self::ATTACH_MAX_BYTES) $cd['warnings'][] = 'plik jest większy niż 20 MB — nie da się go przypisać (limit uploadu umów)';
                unset($cd['i']);
            }
            unset($cd);
            $stats['with_proposals']++;
            $stats[$visible[0]['level']]++;
            $rows[] = ['contract' => $pub, 'candidates' => $visible, 'best_level' => $visible[0]['level'], 'best_score' => $visible[0]['score'], 'hidden' => $lowHidden];
        }
        usort($rows, fn($a, $b) => [self::rank($b['best_level']), $b['best_score'], $a['contract']['number']] <=> [self::rank($a['best_level']), $a['best_score'], $b['contract']['number']]);
        usort($unmatched, fn($a, $b) => [$a['type'], $a['number']] <=> [$b['type'], $b['number']]);
        return ['rows' => $rows, 'unmatched' => $unmatched, 'stats' => $stats];
    }

    /** Pola umowy pokazywane w wynikach (bez tokenów). */
    private static function publicContract(array $c): array
    {
        return ['key' => $c['key'], 'type' => $c['type'], 'id' => (int)$c['id'], 'number' => (string)$c['number'], 'register' => (string)$c['register'],
                'names' => array_values(array_filter(array_map('strval', (array)$c['names']))), 'signed' => (string)$c['signed'], 'status' => (string)$c['status'],
                'current' => (string)$c['current'], 'lost' => (bool)$c['lost']];
    }

    // ── Dane z bazy ──────────────────────────────────────────────────────────

    /**
     * Wszystkie umowy w formie wejściowej propose(). Docelowe (target) wg zakresu: empty — bez pliku, lost — plik wskazany,
     * ale go brak lokalnie i brak pliku o tej nazwie w indeksie SharePointa (przy przypisaniu sprawdzane jeszcze na żywo), all — jedne i drugie. $type ogranicza umowy docelowe do jednego rodzaju
     * (pozostałe rodzaje zostają na liście jako niedocelowe — rozpoznają pliki z numerem innej umowy).
     *
     * @return list<array>
     */
    public static function loadContracts(string $scope = 'empty', string $type = ''): array
    {
        $out = [];
        foreach (SpFiles::contractTypes() as $t) {
            $table = 'umowy_' . $t;
            $have  = SpFiles::tableColumns($table);
            if (!in_array('id', $have, true) || !in_array('plik_umowy', $have, true)) continue;
            $pick = array_values(array_intersect(['id', 'numer_umowy', 'status', 'plik_umowy', 'data_zawarcia', 'nr_rejestru', 'nr_roboczy', 'nr_system',
                                                  'webngo_numer_umowy', 'imie_nazwisko', 'nazwa_wykonawcy', 'strona_umowy', 'numer_projektu'], $have));
            foreach (db_all("SELECT " . implode(', ', $pick) . " FROM {$table}") as $r) {
                $cur  = trim((string)($r['plik_umowy'] ?? ''));
                $lost = false;
                if ($cur !== '' && SpFiles::normalize($cur) !== null && !is_file(UPLOAD_DIR . $cur)) $lost = !SpIndex::hasFileNamed(basename($cur));
                $target = ($type === '' || $type === $t) && match ($scope) {
                    'lost'  => $lost,
                    'all'   => $cur === '' || $lost,
                    default => $cur === '',
                };
                $internal = array_values(array_filter(array_map(fn($k) => trim((string)($r[$k] ?? '')), ['nr_roboczy', 'nr_system', 'webngo_numer_umowy']), fn($v) => $v !== ''));
                $names    = array_values(array_filter(array_map(fn($k) => trim((string)($r[$k] ?? '')), ['imie_nazwisko', 'nazwa_wykonawcy', 'strona_umowy']), fn($v) => $v !== ''));
                $out[] = ['type' => $t, 'id' => (int)$r['id'], 'number' => trim((string)($r['numer_umowy'] ?? '')), 'register' => trim((string)($r['nr_rejestru'] ?? '')),
                          'internal' => $internal, 'names' => array_values(array_unique($names)), 'signed' => (string)($r['data_zawarcia'] ?? ''),
                          'project' => trim((string)($r['numer_projektu'] ?? '')), 'status' => (string)($r['status'] ?? ''), 'current' => $cur, 'lost' => $lost, 'target' => $target];
            }
        }
        return $out;
    }

    /**
     * Propozycje dla umów z bazy (lista umów × indeks SharePointa), bez rozstrzygniętych wcześniej par.
     * $opts: scope (empty|lost|all), type, min (high|medium|low), per_contract.
     *
     * @return array{rows:list<array>,unmatched:list<array>,stats:array}
     */
    public static function proposals(array $opts = []): array
    {
        sp_files_migrate();
        $scope = in_array($opts['scope'] ?? 'empty', ['empty', 'lost', 'all'], true) ? (string)($opts['scope'] ?? 'empty') : 'empty';
        $rejected = [];
        foreach (db_all("SELECT contract_type, contract_id, item_id FROM sp_match_rejections") as $r) $rejected[$r['contract_type'] . ':' . $r['contract_id'] . '|' . $r['item_id']] = true;
        $used = [];
        foreach (db_all("SELECT contract_type, contract_id, item_id FROM sp_match_attached") as $r) $used[$r['item_id']][] = $r['contract_type'] . ':' . $r['contract_id'];
        return self::propose(self::loadContracts($scope, (string)($opts['type'] ?? '')), SpIndex::files(), [
            'min' => $opts['min'] ?? 'medium', 'per_contract' => $opts['per_contract'] ?? 5, 'rejected' => $rejected, 'used' => $used,
        ]);
    }

    /** Liczba odrzuconych propozycji (do przycisku „przywróć”). */
    public static function rejectionCount(): int
    {
        sp_files_migrate();
        return (int)(db_one("SELECT COUNT(*) AS n FROM sp_match_rejections")['n'] ?? 0);
    }

    // ── Decyzje administratora ───────────────────────────────────────────────

    /** Zapamiętuje odrzucenie pary umowa–plik (nie wróci na listę). Zwraca true, gdy pary jeszcze nie było. */
    public static function reject(string $type, int $contractId, string $itemId, int $userId = 0): bool
    {
        sp_files_migrate();
        if (!in_array($type, SpFiles::contractTypes(), true) || $contractId <= 0 || $itemId === '' || strlen($itemId) > 300) return false;
        $st = db()->prepare("INSERT OR IGNORE INTO sp_match_rejections (contract_type, contract_id, item_id, rejected_by, rejected_at) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$type, $contractId, $itemId, $userId, date('Y-m-d H:i:s')]);
        return $st->rowCount() === 1;
    }

    /** Przywraca wszystkie odrzucone propozycje. Zwraca liczbę przywróconych. */
    public static function clearRejections(): int
    {
        sp_files_migrate();
        $n = self::rejectionCount();
        db()->exec("DELETE FROM sp_match_rejections");
        return $n;
    }

    private static function magicOk(string $ext, string $bytes): bool
    {
        return match ($ext) {
            'pdf'         => str_contains(substr($bytes, 0, 1024), '%PDF-'),
            'jpg', 'jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'png'         => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'docx'        => str_starts_with($bytes, "PK\x03\x04"),
            default       => false,
        };
    }

    /**
     * Pobiera wskazany plik z SharePointa i zapisuje go jako plik umowy (plik_umowy). Szczegóły i zabezpieczenia: nagłówek pliku.
     *
     * @return array{status:string,message:string,rel:string,bytes:int}  status: attached | error
     */
    public static function attach(string $type, int $contractId, string $itemId, int $userId = 0, int $score = 0, ?M365Graph $graph = null): array
    {
        $fail = fn(string $m): array => ['status' => 'error', 'message' => $m, 'rel' => '', 'bytes' => 0];
        sp_files_migrate();
        if (!in_array($type, SpFiles::contractTypes(), true)) return $fail('Nieznany rodzaj umowy.');
        $table = 'umowy_' . $type;
        $cols  = SpFiles::tableColumns($table);
        $row   = db_one("SELECT id, plik_umowy" . (in_array('numer_umowy', $cols, true) ? ', numer_umowy' : '') . " FROM {$table} WHERE id = ?", [$contractId]);
        if (!$row) return $fail('Nie ma takiej umowy.');
        $cur = trim((string)($row['plik_umowy'] ?? ''));
        if ($cur !== '') {
            if (SpFiles::normalize($cur) === null) return $fail('Umowa ma niestandardową ścieżkę pliku — popraw ją ręcznie.');
            if (is_file(UPLOAD_DIR . $cur)) return $fail('Ta umowa ma już plik — nie nadpisujemy.');
            // utracone wskazanie wolno zastąpić tylko wtedy, gdy oryginału naprawdę nie ma na SharePoincie (sprawdzamy na żywo, nie z indeksu)
            $loc = SpFiles::locate($cur, $graph);
            if ($loc['status'] === 'found' || $loc['status'] === 'ambiguous') return $fail('Oryginał pliku tej umowy leży na SharePoincie — odzyskaj go w „Pliki umów — dopasowanie”.');
            if ($loc['status'] === 'error') return $fail('Nie udało się sprawdzić, czy oryginał pliku jest na SharePoincie: ' . $loc['message']);
        }
        $ix = SpIndex::find($itemId);
        if (!$ix || (int)$ix['app_named'] === 1) return $fail('Tego pliku nie ma w indeksie SharePointa — odśwież indeks.');
        if (!in_array($ix['ext'], SpIndex::ATTACH_EXT, true)) return $fail('Niedozwolone rozszerzenie pliku.');
        if ($ix['size'] <= 0 || $ix['size'] > self::ATTACH_MAX_BYTES) return $fail('Plik ma niedozwolony rozmiar (limit ' . (int)(self::ATTACH_MAX_BYTES / 1048576) . ' MB, jak przy uploadzie umów).');

        try {
            $c    = SpFiles::context($graph);
            $live = $c['g']->sp_item_by_id($c['drive'], $itemId);
            if (!$live || !isset($live['file'])) return $fail('Tego pliku nie ma już na SharePoincie — odśwież indeks.');
            if ((string)($live['name'] ?? '') !== $ix['name'] || (int)($live['size'] ?? 0) !== $ix['size']) {
                return $fail('Plik na SharePoincie zmienił się od ostatniego indeksowania (nazwa lub rozmiar) — odśwież indeks i sprawdź ponownie.');
            }
            $bytes = $c['g']->sp_download_file($c['drive'], $itemId);
        } catch (\Throwable $e) {
            return $fail(mb_substr($e->getMessage(), 0, 300));
        }
        if (strlen($bytes) !== $ix['size']) return $fail('Pobrano niepełny plik (' . strlen($bytes) . ' z ' . $ix['size'] . ' B).');
        if (!self::magicOk((string)$ix['ext'], $bytes)) return $fail('Zawartość pliku nie odpowiada jego rozszerzeniu (.' . $ix['ext'] . ') — nie przypisuję.');

        $rel = $type . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ix['ext'];
        try {
            SpFiles::writeAtomic(UPLOAD_DIR . $rel, $bytes);
        } catch (\Throwable $e) {
            return $fail(mb_substr($e->getMessage(), 0, 300));
        }

        $pdo = db();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $set = in_array('updated_at', $cols, true) ? ', updated_at = ?' : '';
            $st  = $pdo->prepare("UPDATE {$table} SET plik_umowy = ?{$set} WHERE id = ? AND COALESCE(plik_umowy, '') = ?");
            $st->execute(array_merge([$rel], $set !== '' ? [date('Y-m-d H:i:s')] : [], [$contractId, $cur]));
            if ($st->rowCount() !== 1) throw new \RuntimeException('Umowa zmieniła się w międzyczasie (ktoś dodał plik) — odśwież listę.');
            $pdo->prepare("INSERT INTO sp_match_attached (contract_type, contract_id, item_id, sp_path, rel, bytes, score, attached_by, attached_at) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$type, $contractId, $itemId, (string)$ix['path'], $rel, strlen($bytes), $score, $userId, date('Y-m-d H:i:s')]);
            audit_log('sp_files.attached', ['type' => $type, 'contract_id' => $contractId, 'number' => (string)($row['numer_umowy'] ?? ''), 'rel' => $rel,
                                            'sp_path' => (string)$ix['path'], 'item_id' => $itemId, 'bytes' => strlen($bytes), 'score' => $score, 'replaced' => $cur], $userId);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            @unlink(UPLOAD_DIR . $rel);
            return $fail(mb_substr($e->getMessage(), 0, 300));
        }
        // kopia pod nazwą aplikacji — jak przy każdym uploadzie umowy; błąd tylko do logu
        if (function_exists('upload_sync_sharepoint')) upload_sync_sharepoint($rel);
        return ['status' => 'attached', 'message' => '', 'rel' => $rel, 'bytes' => strlen($bytes)];
    }
}
