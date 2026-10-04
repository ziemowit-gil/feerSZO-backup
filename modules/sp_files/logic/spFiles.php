<?php
/**
 * modules/sp_files/logic/spFiles.php — dopasowanie plików umów do SharePointa, gdy brak ich lokalnie.
 *
 * Pliki umów (plik_umowy, plik_potwierdzenia, załączniki, zgody, aneksy, dokumenty dodatkowe) trafiają na
 * SharePoint przy uploadzie (sp_sync_upload: {sp_base_folder}/{ścieżka względna}), ale lokalnie bywają
 * niedostępne — po przeniesieniu serwera, czyszczeniu katalogu uploads/, wdrożeniu bez plików. Ten moduł
 * znajduje taki plik na SharePoincie i przywraca go do uploads/ pod tą samą ścieżką, więc linki w umowach
 * znów działają.
 *
 * Dopasowanie (locate), w kolejności:
 *   1. dokładna ścieżka {sp_base_folder}/{ścieżka umowy} — tak pliki wysyła sp_sync_upload; bez opóźnienia indeksu;
 *   2. ta sama ścieżka bez folderu bazowego (gdy folder bazowy zmieniono albo wcześniej był pusty);
 *   3. wyszukiwanie po nazwie pliku w całej bibliotece (nazwa pliku z uploadu jest unikalna: data_czas_losowe):
 *      jedno trafienie → dopasowane; kilka → wybiera to z folderu o nazwie jak katalog umowy; inaczej NIEJEDNOZNACZNE
 *      (administrator wskazuje właściwy w narzędziu admin/sp_contract_files.php).
 * Nic nie jest dopasowywane „na oko” po treści i nigdy nie nadpisujemy istniejącego pliku lokalnego.
 *
 * Bezpieczeństwo: przywracamy wyłącznie ścieżki WSKAZANE przez rekordy umów (isReferenced) — nie da się tą drogą
 * pobrać dowolnego pliku z biblioteki; ścieżka musi przejść normalize() (bez „..”, bez ukośnika na początku,
 * znane rozszerzenia); zawartość trafia do pliku tymczasowego i dopiero potem na miejsce; każde przywrócenie
 * zapisuje się w audycie (sp_files.restored). Brak zmian w bazie — tylko pliki w uploads/.
 */
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class SpFiles
{
    /** Rozszerzenia, które wolno przywrócić (zestaw z uploadu umów + typowe dokumenty biurowe). */
    private const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc', 'odt', 'xlsx', 'xls'];

    /** Górny limit pobieranego pliku (cała zawartość trafia do pamięci). */
    private const MAX_BYTES = 40 * 1024 * 1024;

    /**
     * Kolumny ze ścieżkami plików w tabelach umów: typ (slug z CONTRACT_TYPES) => kolumny.
     * 'zalaczniki' bywa listą ścieżek — rozbijana w extractPaths().
     */
    private const CONTRACT_COLUMNS = [
        'wolontariat' => ['plik_umowy', 'plik_potwierdzenia', 'rpts_plik_potwierdzenia', 'zgoda_opiekuna'],
        'zlecenie'    => ['plik_umowy', 'plik_potwierdzenia'],
        'dzielo'      => ['plik_umowy', 'plik_potwierdzenia'],
        'praca'       => ['plik_umowy', 'plik_potwierdzenia'],
        'uslugi'      => ['plik_umowy', 'plik_potwierdzenia', 'zalaczniki'],
        'inne'        => ['plik_umowy', 'plik_potwierdzenia', 'zalaczniki'],
        'powierzenie' => ['plik_umowy', 'plik_potwierdzenia', 'zalaczniki'],
    ];

    /** Opisy kolumn do raportu. */
    private const COLUMN_LABELS = [
        'plik_umowy' => 'Plik umowy', 'plik_potwierdzenia' => 'Potwierdzenie', 'rpts_plik_potwierdzenia' => 'Potwierdzenie RPTS',
        'zgoda_opiekuna' => 'Zgoda opiekuna', 'zalaczniki' => 'Załącznik', 'plik_aneksu' => 'Aneks', 'stored_path' => 'Dokument dodatkowy',
        'plik' => 'Pismo',
    ];

    // ── Ścieżki ──────────────────────────────────────────────────────────────

    /**
     * Ścieżka względna pliku w uploads/ albo null, gdy nie wygląda na bezpieczną: bez „..”, bez ukośnika na początku
     * i pustych segmentów, tylko litery/cyfry i . _ ( ) - spacja, tylko znane rozszerzenia.
     */
    public static function normalize(string $rel): ?string
    {
        $rel = str_replace('\\', '/', trim($rel));
        if ($rel === '' || strlen($rel) > 300 || str_contains($rel, "\0") || $rel[0] === '/' || str_contains($rel, '//')) return null;
        if (preg_match('~(^|/)\.\.?(/|$)~', $rel)) return null;
        if (!preg_match('~^[\p{L}\p{N}._() /-]+$~u', $rel)) return null;
        if (!in_array(strtolower(pathinfo($rel, PATHINFO_EXTENSION)), self::ALLOWED_EXT, true)) return null;
        return $rel;
    }

    public static function localPath(string $rel): string { return UPLOAD_DIR . $rel; }

    /** Ścieżki plików z wartości kolumny (pojedyncza ścieżka, JSON-owa lista albo lista rozdzielona , ; | / białymi znakami). @return list<string> */
    public static function extractPaths(?string $value): array
    {
        $value = trim((string)$value);
        if ($value === '') return [];
        $parts = [$value];
        if ($value[0] === '[' && is_array($j = json_decode($value, true))) $parts = array_map('strval', $j);
        elseif (preg_match('~[,;|\n]~', $value)) $parts = preg_split('~[,;|\n]+~', $value) ?: [];
        $out = [];
        foreach ($parts as $p) { $n = self::normalize((string)$p); if ($n !== null) $out[$n] = true; }
        return array_keys($out);
    }

    // ── Odwołania z umów ─────────────────────────────────────────────────────

    private static function tableColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $cols = DB_TYPE === 'sqlite'
                ? array_column(db_all("PRAGMA table_info(" . $table . ")"), 'name')
                : array_column(db_all("SHOW COLUMNS FROM " . $table), 'Field');
        } catch (\Throwable $e) { $cols = []; }
        return $cache[$table] = $cols;
    }

    /**
     * Wszystkie odwołania do plików z rekordów umów: ['kind', 'type', 'id', 'number', 'column', 'column_label', 'rel', 'view', 'local' => bool].
     * Źródła: tabele umowy_*, aneksy (contract_amendments), dokumenty dodatkowe (contract_extra_docs), pisma do umów (contract_letters).
     *
     * @return list<array>
     */
    public static function references(): array
    {
        $out = [];
        $labels = defined('CONTRACT_TYPES') ? CONTRACT_TYPES : [];
        $add = function (string $kind, string $type, int $id, string $number, string $col, string $rel, string $view) use (&$out): void {
            $out[] = ['kind' => $kind, 'type' => $type, 'id' => $id, 'number' => $number, 'column' => $col,
                      'column_label' => self::COLUMN_LABELS[$col] ?? $col, 'rel' => $rel, 'view' => $view,
                      'local' => is_file(self::localPath($rel))];
        };

        foreach (self::CONTRACT_COLUMNS as $type => $cols) {
            $table = 'umowy_' . $type;
            $have  = self::tableColumns($table);
            $cols  = array_values(array_intersect($cols, $have));
            if (!$cols) continue;
            $num = in_array('numer_umowy', $have, true) ? 'numer_umowy' : "''";
            foreach (db_all("SELECT id, {$num} AS number, " . implode(', ', $cols) . " FROM {$table}") as $r) {
                foreach ($cols as $c) {
                    foreach (self::extractPaths($r[$c] ?? '') as $rel) {
                        $add('umowa', $type, (int)$r['id'], (string)$r['number'], $c, $rel, "contracts/{$type}/view.php?id=" . (int)$r['id']);
                    }
                }
            }
        }
        // aneksy i dokumenty dodatkowe wskazują umowę przez (contract_type, contract_id); numer umowy dociągamy hurtem
        $linked = [];
        foreach ([['contract_amendments', 'plik_aneksu', 'aneks'], ['contract_extra_docs', 'stored_path', 'dokument']] as [$table, $col, $kind]) {
            if (!in_array($col, self::tableColumns($table), true)) continue;
            foreach (db_all("SELECT id, contract_type, contract_id, {$col} AS f FROM {$table} WHERE COALESCE({$col}, '') <> ''") as $r) {
                foreach (self::extractPaths($r['f']) as $rel) $linked[] = [$kind, (string)$r['contract_type'], (int)$r['contract_id'], $col, $rel];
            }
        }
        $numbers = [];
        foreach (array_unique(array_column($linked, 1)) as $type) {
            if (!isset(self::CONTRACT_COLUMNS[$type]) || !in_array('numer_umowy', self::tableColumns('umowy_' . $type), true)) continue;
            $ids = array_values(array_unique(array_column(array_filter($linked, fn($l) => $l[1] === $type), 2)));
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (db_all("SELECT id, numer_umowy FROM umowy_{$type} WHERE id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk) as $r) {
                    $numbers[$type][(int)$r['id']] = (string)$r['numer_umowy'];
                }
            }
        }
        foreach ($linked as [$kind, $type, $cid, $col, $rel]) {
            $add($kind, $type, $cid, $numbers[$type][$cid] ?? '', $col, $rel, isset($labels[$type]) ? "contracts/{$type}/view.php?id={$cid}" : '');
        }
        if (in_array('plik', self::tableColumns('contract_letters'), true)) {
            foreach (db_all("SELECT id, plik FROM contract_letters WHERE COALESCE(plik, '') <> ''") as $r) {
                foreach (self::extractPaths($r['plik']) as $rel) $add('pismo', '', (int)$r['id'], '', 'plik', $rel, 'contracts/letters/view.php?id=' . (int)$r['id']);
            }
        }
        return $out;
    }

    /** Odwołania, których pliku brak lokalnie. @return list<array> */
    public static function missing(): array
    {
        return array_values(array_filter(self::references(), fn($r) => !$r['local']));
    }

    /** Czy ścieżkę wskazuje któryś rekord umowy (zabezpieczenie: nie przywracamy dowolnych plików z biblioteki). */
    public static function isReferenced(string $rel): bool
    {
        $rel = self::normalize($rel);
        if ($rel === null) return false;
        $like = '%' . addcslashes($rel, '%_\\') . '%';
        foreach (self::CONTRACT_COLUMNS as $type => $cols) {
            $table = 'umowy_' . $type; $have = self::tableColumns($table);
            foreach ($cols as $c) {
                if (!in_array($c, $have, true)) continue;
                // wartość pojedyncza = równość; lista (załączniki) = trafienie w treści, potem dokładny test na rozbitej liście
                $row = $c === 'zalaczniki'
                    ? db_all("SELECT {$c} AS v FROM {$table} WHERE {$c} LIKE ? ESCAPE '\\'", [$like])
                    : db_all("SELECT {$c} AS v FROM {$table} WHERE {$c} = ?", [$rel]);
                foreach ($row as $r) if (in_array($rel, self::extractPaths($r['v']), true)) return true;
            }
        }
        foreach ([['contract_amendments', 'plik_aneksu'], ['contract_extra_docs', 'stored_path'], ['contract_letters', 'plik']] as [$table, $col]) {
            if (!in_array($col, self::tableColumns($table), true)) continue;
            foreach (db_all("SELECT {$col} AS v FROM {$table} WHERE {$col} LIKE ? ESCAPE '\\'", [$like]) as $r) {
                if (in_array($rel, self::extractPaths($r['v']), true)) return true;
            }
        }
        return false;
    }

    // ── SharePoint ───────────────────────────────────────────────────────────

    /** Czy integracja SharePoint jest włączona i skonfigurowana (bez żadnych zapytań do Graph). */
    public static function canRestore(): bool
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        if (!function_exists('m365_setting')) require_once dirname(__DIR__, 3) . '/includes/m365.php';
        return $cache = m365_setting('sp_enabled') === '1' && m365_setting('sp_site_url') !== '' && (new M365Graph())->is_configured();
    }

    /** @return array{g:M365Graph,drive:string,base:string} */
    private static function context(?M365Graph $g = null): array
    {
        if (!function_exists('m365_setting')) require_once dirname(__DIR__, 3) . '/includes/m365.php';
        if (m365_setting('sp_enabled') !== '1') throw new \RuntimeException('Integracja SharePoint jest wyłączona (Administracja → SharePoint).');
        $site_url = m365_setting('sp_site_url');
        if ($site_url === '') throw new \RuntimeException('Brak adresu witryny SharePoint w ustawieniach.');
        $g ??= new M365Graph();
        if (!$g->is_configured()) throw new \RuntimeException('Brak konfiguracji Microsoft 365 (Graph).');
        $drive = $g->sp_drive_id($g->sp_site_id($site_url), m365_setting('sp_library') ?: '');
        return ['g' => $g, 'drive' => $drive, 'base' => trim(m365_setting('sp_base_folder'), '/')];
    }

    /** Pozycja z Graph → ['id', 'name', 'size', 'web_url', 'folder' (ścieżka folderu w bibliotece), 'path']. */
    private static function shape(array $it): array
    {
        $parent = (string)($it['parentReference']['path'] ?? '');
        $folder = preg_match('~root:(.*)$~', $parent, $m) ? trim($m[1], '/') : '';
        return ['id' => (string)$it['id'], 'name' => (string)($it['name'] ?? ''), 'size' => (int)($it['size'] ?? 0),
                'web_url' => (string)($it['webUrl'] ?? ''), 'folder' => $folder, 'path' => ltrim($folder . '/' . ($it['name'] ?? ''), '/')];
    }

    /**
     * Szuka pliku na SharePoincie (patrz nagłówek: ścieżka dokładna → ścieżka bez folderu bazowego → po nazwie).
     * Niczego nie pobiera ani nie zapisuje.
     *
     * @return array{status:string,method:string,item:?array,candidates:list<array>,message:string}
     *         status: found | missing | ambiguous | error
     */
    public static function locate(string $rel, ?M365Graph $graph = null): array
    {
        $res = ['status' => 'missing', 'method' => '', 'item' => null, 'candidates' => [], 'message' => ''];
        $rel = self::normalize($rel);
        if ($rel === null) return ['status' => 'error', 'message' => 'Niedozwolona ścieżka pliku.'] + $res;
        try {
            $c = self::context($graph);
            // 1–2) dokładna ścieżka: z folderem bazowym i bez niego
            $paths = array_values(array_unique(array_filter([$c['base'] !== '' ? $c['base'] . '/' . $rel : $rel, $rel])));
            foreach ($paths as $p) {
                $it = $c['g']->sp_item_by_path($c['drive'], $p);
                if ($it && isset($it['file'])) return ['status' => 'found', 'method' => 'path', 'item' => self::shape($it), 'message' => ''] + $res;
            }
            // 3) po nazwie pliku w całej bibliotece
            $hits = array_map([self::class, 'shape'], $c['g']->sp_search_files_by_name($c['drive'], basename($rel)));
            if (count($hits) === 1) return ['status' => 'found', 'method' => 'search', 'item' => $hits[0], 'message' => ''] + $res;
            if (count($hits) > 1) {
                $dir  = trim(dirname($rel), './');
                $same = $dir === '' ? [] : array_values(array_filter($hits, fn($h) => strcasecmp(basename($h['folder']), basename($dir)) === 0));
                if (count($same) === 1) return ['status' => 'found', 'method' => 'search', 'item' => $same[0], 'message' => ''] + $res;
                return ['status' => 'ambiguous', 'candidates' => $hits, 'message' => count($hits) . ' plików o tej nazwie na SharePoincie.'] + $res;
            }
            return $res;
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 300)] + $res;
        }
    }

    /**
     * Przywraca plik umowy z SharePointa do uploads/ (pod tą samą ścieżką względną). $itemId — świadomy wybór
     * kandydata przy dopasowaniu niejednoznacznym (musi być jednym ze znalezionych). Nie nadpisuje istniejącego pliku.
     *
     * @return array{status:string,method:string,item:?array,candidates:list<array>,message:string,bytes?:int}
     *         status: restored | exists | missing | ambiguous | error
     */
    public static function restore(string $rel, ?string $itemId = null, ?M365Graph $graph = null): array
    {
        $rel = self::normalize($rel);
        if ($rel === null) return ['status' => 'error', 'method' => '', 'item' => null, 'candidates' => [], 'message' => 'Niedozwolona ścieżka pliku.'];
        if (is_file(self::localPath($rel))) return ['status' => 'exists', 'method' => '', 'item' => null, 'candidates' => [], 'message' => 'Plik jest już na serwerze.'];

        $loc  = self::locate($rel, $graph);
        $item = $loc['item'];
        if ($itemId !== null && $itemId !== '') {
            $pool = $loc['candidates'] ?: ($item ? [$item] : []);
            $pick = array_values(array_filter($pool, fn($c) => $c['id'] === $itemId));
            if (!$pick) return ['status' => 'error', 'message' => 'Wskazany plik nie jest jednym ze znalezionych kandydatów.'] + $loc;
            $item = $pick[0]; $loc['status'] = 'found'; $loc['method'] = $loc['method'] ?: 'search';
        }
        if ($loc['status'] !== 'found' || !$item) return $loc;

        try {
            if ($item['size'] > self::MAX_BYTES) throw new \RuntimeException('Plik na SharePoincie jest za duży (' . round($item['size'] / 1048576) . ' MB).');
            $c = self::context($graph);
            $bytes = $c['g']->sp_download_file($c['drive'], $item['id']);
            if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) throw new \RuntimeException('Pobrano pusty lub zbyt duży plik.');
            if ($item['size'] > 0 && strlen($bytes) !== $item['size']) throw new \RuntimeException('Pobrano niepełny plik (' . strlen($bytes) . ' z ' . $item['size'] . ' B).');
            self::writeAtomic(self::localPath($rel), $bytes);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 300)] + $loc;
        }
        self::audit($rel, $item, $loc['method'], strlen($bytes));
        return ['status' => 'restored', 'message' => '', 'bytes' => strlen($bytes), 'item' => $item] + $loc;
    }

    /**
     * Ścieżka bezwzględna pliku umowy: istniejąca lokalnie albo świeżo przywrócona z SharePointa (tylko dla ścieżek
     * wskazanych przez umowy). null, gdy pliku nie ma i nie udało się go znaleźć. Dla akcji po stronie serwera
     * (wysyłka do podpisu, walidacja podpisu), które wymagają pliku na dysku.
     */
    public static function ensureLocal(string $rel): ?string
    {
        $norm = self::normalize($rel);
        if ($norm === null) return null;
        $abs = self::localPath($norm);
        if (is_file($abs)) return $abs;
        if (!self::canRestore() || !self::isReferenced($norm)) return null;
        $r = self::restore($norm);
        return in_array($r['status'], ['restored', 'exists'], true) && is_file($abs) ? $abs : null;
    }

    // ── Zapis ────────────────────────────────────────────────────────────────

    /** Zapis przez plik tymczasowy w tym samym katalogu; nie nadpisuje pliku, który pojawił się w międzyczasie. */
    private static function writeAtomic(string $abs, string $bytes): void
    {
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new \RuntimeException('Nie można utworzyć katalogu uploads.');
        $tmp = $abs . '.part.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) throw new \RuntimeException('Nie można zapisać pliku w uploads.');
        @chmod($tmp, 0644);
        if (is_file($abs)) { @unlink($tmp); return; }          // ktoś już przywrócił ten plik — zostaje jego wersja
        if (!@rename($tmp, $abs)) { @unlink($tmp); throw new \RuntimeException('Nie można umieścić pliku w uploads.'); }
    }

    private static function audit(string $rel, array $item, string $method, int $bytes): void
    {
        try {
            // w CLI nie ma sesji (current_user() próbowałoby ją otworzyć po wypisaniu wyniku) — wpis bez użytkownika
            $uid = (PHP_SAPI === 'cli' || !function_exists('current_user')) ? 0 : (int)((current_user()['id'] ?? 0));
            audit_log('sp_files.restored', ['rel' => $rel, 'sp_path' => $item['path'], 'method' => $method, 'bytes' => $bytes, 'item_id' => $item['id']], $uid);
        } catch (\Throwable $e) {
            error_log('[sp_files] audyt przywrócenia nie zapisany: ' . $e->getMessage());
        }
    }
}
