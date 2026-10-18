<?php
declare(strict_types=1);
/**
 * Import i eksport Słownika Centralnego.
 *
 * JSON (pełny, przenośny między instalacjami — rodzice po KODACH, nie po id):
 *   {"format":"szo-dictionary","version":1,"dictionaries":[
 *      {"code":"…","name":"…","kind":"business","parent_code":null,"is_protected":false,"sort_order":0,"description":null,
 *       "entries":[{"code":"…","label":"…","parent_code":null,"sort_order":0,"is_active":true,
 *                   "valid_from":null,"valid_to":null,"description":null,"attributes":{},"labels":{"en":"…"}}]}]}
 *   Import przyjmuje też pojedynczy słownik (obiekt z "code" i "entries").
 *
 * CSV (jeden słownik, Excel-friendly: UTF-8 z BOM, separator „;”):
 *   code;label;parent_code;sort_order;is_active;valid_from;valid_to;description;attributes;label_en;label_uk…
 *   attributes = obiekt JSON w jednej komórce. Przy imporcie separator „;”, „,” albo tabulator wykrywany sam.
 *   Komórki zaczynające się od = + - @ dostają w eksporcie apostrof (ochrona przed formułami w Excelu).
 */
final class DictionaryTransfer
{
    private const CSV_BASE = ['code', 'label', 'parent_code', 'sort_order', 'is_active', 'valid_from', 'valid_to', 'description', 'attributes'];
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly DictionaryService $svc) {}

    // ── Eksport ──────────────────────────────────────────────────────────

    /** @param string[]|null $codes null = wszystkie nieusunięte słowniki */
    public function exportJson(?array $codes = null): string
    {
        $tree = $this->svc->dictionaryTree();
        $byId = [];
        foreach ($tree as [$d]) $byId[$d->id] = $d;
        $out = [];
        foreach ($tree as [$d]) {
            if ($codes !== null && !in_array($d->code, $codes, true)) continue;
            $out[] = $d->toArray() + ['parent_code' => $d->parentId !== null ? ($byId[$d->parentId]->code ?? null) : null,
                                      'entries' => $this->entryRows($d)];
        }
        return json_encode(['format' => 'szo-dictionary', 'version' => 1, 'exported_at' => date('c'), 'dictionaries' => $out],
                           JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function exportCsv(string $code): string
    {
        $d = $this->svc->dictionary($code);
        $rows = $this->entryRows($d);
        $locales = DictionaryService::extraLocales();
        foreach ($rows as $r) foreach (array_keys((array)$r['labels']) as $l) if (!in_array($l, $locales, true)) $locales[] = $l;
        $head = [...self::CSV_BASE, ...array_map(fn($l) => 'label_' . $l, $locales)];
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $head, ';', '"', '');
        foreach ($rows as $r) {
            $labels = (array)$r['labels'];
            $line = [$r['code'], $r['label'], $r['parent_code'] ?? '', $r['sort_order'], $r['is_active'] ? '1' : '0',
                     $r['valid_from'] ?? '', $r['valid_to'] ?? '', $r['description'] ?? '',
                     (array)$r['attributes'] ? json_encode($r['attributes'], JSON_UNESCAPED_UNICODE) : ''];
            foreach ($locales as $l) $line[] = $labels[$l] ?? '';
            fputcsv($fh, array_map([self::class, 'csvSafe'], $line), ';', '"', '');
        }
        rewind($fh);
        return (string)stream_get_contents($fh);
    }

    // ── Import ───────────────────────────────────────────────────────────

    /**
     * @param string|null $targetCode dla CSV: kod słownika docelowego (musi istnieć); dla JSON ignorowany
     * @return array<int, array> statystyki per słownik (DictionaryService::importDictionary)
     */
    public function import(string $content, string $format, ?string $targetCode = null, bool $dryRun = false): array
    {
        if (strlen($content) > self::MAX_BYTES) throw new DictionaryValidationException(['file' => 'Plik jest za duży (limit 5 MB).']);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $run = fn() => $format === 'csv' ? [$this->importCsv($content, (string)$targetCode)] : $this->importJson($content);
        return $dryRun ? $this->svcRepoDryRun($run) : $run();
    }

    private function importJson(string $content): array
    {
        $data = json_decode($content, true);
        if (!is_array($data)) throw new DictionaryValidationException(['file' => 'Plik nie jest poprawnym JSON-em: ' . json_last_error_msg() . '.']);
        $list = isset($data['dictionaries']) && is_array($data['dictionaries']) ? $data['dictionaries'] : (isset($data['code']) ? [$data] : null);
        if ($list === null) throw new DictionaryValidationException(['file' => 'Brak klucza „dictionaries” (albo pojedynczego słownika z „code”).']);
        // Kategorie nadrzędne przed podrzędnymi — kolejność z pliku, a brakujących rodziców zakładamy w następnym przebiegu.
        $out = [];
        $pending = $list;
        for ($pass = 0; $pending && $pass < 20; $pass++) {
            $next = [];
            foreach ($pending as $dict) {
                if (!is_array($dict)) continue;
                $parent = (string)($dict['parent_code'] ?? '');
                $inFile = $parent !== '' && array_filter($pending, fn($x) => is_array($x) && ($x['code'] ?? null) === $parent);
                if ($inFile && !$this->svc->exists($parent)) { $next[] = $dict; continue; }
                $entries = [];
                foreach (array_values((array)($dict['entries'] ?? [])) as $i => $e) if (is_array($e)) $entries[] = $e + ['_line' => $i + 1];
                unset($dict['entries']);
                try { $out[] = $this->svc->importDictionary($dict, $entries); }
                catch (DictionaryException $ex) { $out[] = ['dictionary' => (string)($dict['code'] ?? '?'), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => [0 => $ex->getMessage()]]; }
            }
            if (count($next) === count($pending)) break;
            $pending = $next;
        }
        return $out;
    }

    private function importCsv(string $content, string $targetCode): array
    {
        if ($targetCode === '') throw new DictionaryValidationException(['dictionary' => 'Wybierz słownik docelowy dla pliku CSV.']);
        $first = strtok($content, "\n") ?: '';
        $delim = ';';
        foreach ([';', "\t", ','] as $c) if (substr_count($first, $c) > substr_count($first, $delim)) $delim = $c;
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $content);
        rewind($fh);
        $head = fgetcsv($fh, 0, $delim, '"', '');
        if (!$head) throw new DictionaryValidationException(['file' => 'Pusty plik CSV.']);
        $head = array_map(fn($h) => strtolower(trim((string)$h)), $head);
        if (!in_array('code', $head, true) || !in_array('label', $head, true))
            throw new DictionaryValidationException(['file' => 'Pierwszy wiersz CSV musi zawierać kolumny „code” i „label”.']);
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', $cells) === '') continue;
            $row = ['_line' => $line];
            foreach ($head as $i => $col) $row[$col] = self::csvUnsafe((string)($cells[$i] ?? ''));
            if (($row['sort_order'] ?? '') === '') unset($row['sort_order']);
            if (($row['is_active'] ?? '') === '') unset($row['is_active']);
            $rows[] = $row;
        }
        return $this->svc->importDictionary(null, $rows, $targetCode);
    }

    // ── Pomocnicze ───────────────────────────────────────────────────────

    private function entryRows(Dictionary $d): array
    {
        $entries = $this->svc->entries($d);
        $codeById = [];
        foreach ($entries as $e) $codeById[$e->id] = $e->code;
        // Rodzice przed dziećmi — plik da się zaimportować w jednym przebiegu.
        $children = [];
        foreach ($entries as $e) $children[$e->parentId !== null && isset($codeById[$e->parentId]) ? $e->parentId : 0][] = $e;
        $out = [];
        $walk = function (int $pid) use (&$walk, &$out, $children, $codeById): void {
            foreach ($children[$pid] ?? [] as $e) { $out[] = $e->toArray($e->parentId !== null ? ($codeById[$e->parentId] ?? null) : null); $walk($e->id); }
        };
        $walk(0);
        return $out;
    }

    private function svcRepoDryRun(callable $run): array
    {
        try { return dictRepository()->dryRun($run); }
        finally { (new DictionaryCache())->invalidate(); }      // cache mógł zapamiętać wycofane dane
    }

    private static function csvSafe(mixed $v): string
    {
        $v = (string)$v;
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($v) ? "'" . $v : $v;
    }

    private static function csvUnsafe(string $v): string
    {
        $v = trim($v);
        return strlen($v) > 1 && $v[0] === "'" && in_array($v[1], ['=', '+', '-', '@'], true) ? substr($v, 1) : $v;
    }
}
