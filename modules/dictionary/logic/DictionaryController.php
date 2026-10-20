<?php
declare(strict_types=1);
/**
 * modules/dictionary/logic/DictionaryController.php — panel Słownika Centralnego i endpointy JSON
 * (trasy w ../router.php). Kontroler tylko tłumaczy HTTP ↔ DTO/serwis; reguły są w DictionaryService.
 *
 * Uprawnienia (moduł 'slowniki' w includes/permissions.php, admin ma wszystko):
 *   can_read   — przegląd, historia, eksport, API ze szczegółami;
 *   can_write  — zakładanie i edycja słowników i wpisów, import;
 *   can_delete — przenoszenie do kosza i przywracanie;
 *   is_admin() — włączanie/zdejmowanie zabezpieczenia słownika.
 */
final class DictionaryController
{
    private const VIEW = __DIR__ . '/../view/';

    private function svc(): DictionaryService { return dictService(); }

    private function render(string $file, array $data = []): string
    {
        return Router::view(self::VIEW . $file, $data + [
            'canWrite' => can_write('slowniki'), 'canDelete' => can_delete('slowniki'), 'isAdmin' => is_admin(),
        ]);
    }

    private function back(string $url, string $type, string $msg): ?string
    {
        flash_set($type, $msg);
        return Router::redirect($url);
    }

    // ══ Słowniki ═════════════════════════════════════════════════════════

    public function index(): string
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $trash = !empty($_GET['trash']);
        $tree = $this->svc()->dictionaryTree($trash);
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $tree = array_values(array_filter($tree, fn($row) => str_contains(mb_strtolower($row[0]->name . ' ' . $row[0]->code . ' ' . $row[0]->description), $needle)));
        }
        return $this->render('index.php', ['tree' => $tree, 'counts' => $this->svc()->entryCounts(), 'q' => $q, 'trash' => $trash]);
    }

    public function create(): string
    {
        $parent = (string)($_GET['parent'] ?? '');
        return $this->render('dictForm.php', ['dict' => null, 'old' => ['parent_code' => $parent, 'kind' => 'business'], 'errors' => [],
                                              'parents' => $this->svc()->dictionaryTree()]);
    }

    public function store(): ?string
    {
        try {
            $d = $this->svc()->createDictionary(DictionaryInput::fromArray($this->dictPost(null)));
            return $this->back(route('dict.show', ['code' => $d->code]), 'success', "Utworzono słownik „{$d->name}”.");
        } catch (DictionaryValidationException $e) {
            return $this->render('dictForm.php', ['dict' => null, 'old' => $_POST, 'errors' => $e->errors, 'parents' => $this->svc()->dictionaryTree()]);
        } catch (DictionaryException $e) {
            return $this->back(route('dict.index'), 'error', $e->getMessage());
        }
    }

    public function show(string $code): ?string
    {
        try { $d = $this->svc()->dictionary($code, true); }
        catch (DictionaryNotFoundException $e) { http_response_code(404); return $this->back(route('dict.index'), 'error', $e->getMessage()); }
        $trash = !empty($_GET['trash']);
        $entries = $this->svc()->entries($d, true);
        $today = date('Y-m-d');
        $parents = [];
        foreach ($entries as $e) $parents[$e->id] = $e;
        [$rows, $view] = $this->limitTree($this->orderTree($entries, $trash), $trash);
        return $this->render('show.php', [
            'dict' => $d, 'entries' => $rows, 'treeView' => $view, 'byId' => $parents, 'trash' => $trash, 'today' => $today,
            'trashCount' => count(array_filter($entries, fn($e) => $e->isDeleted())),
            'parentDict' => $d->parentId ? $this->findById($d->parentId) : null,
            'children' => array_filter($this->svc()->allDictionaries(), fn($c) => $c->parentId === $d->id),
            'locales' => DictionaryService::extraLocales(),
        ]);
    }

    public function edit(string $code): ?string
    {
        try { $d = $this->svc()->dictionary($code); }
        catch (DictionaryException $e) { return $this->back(route('dict.index'), 'error', $e->getMessage()); }
        $parent = $d->parentId ? $this->findById($d->parentId) : null;
        return $this->render('dictForm.php', ['dict' => $d, 'old' => $d->toArray() + ['parent_code' => $parent?->code], 'errors' => [],
                                              'parents' => $this->svc()->dictionaryTree()]);
    }

    public function update(string $code): ?string
    {
        try {
            $d = $this->svc()->updateDictionary($code, DictionaryInput::fromArray($this->dictPost($this->svc()->dictionary($code))));
            return $this->back(route('dict.show', ['code' => $d->code]), 'success', 'Zapisano zmiany słownika.');
        } catch (DictionaryValidationException $e) {
            return $this->render('dictForm.php', ['dict' => $this->svc()->dictionary($code), 'old' => $_POST, 'errors' => $e->errors,
                                                  'parents' => $this->svc()->dictionaryTree()]);
        } catch (DictionaryException $e) {
            return $this->back(route('dict.show', ['code' => $code]), 'error', $e->getMessage());
        }
    }

    public function destroy(string $code): ?string
    {
        try {
            $this->svc()->deleteDictionary($code);
            return $this->back(route('dict.index'), 'success', "Słownik „{$code}” przeniesiono do kosza.");
        } catch (DictionaryException $e) {
            return $this->back(route('dict.show', ['code' => $code]), 'error', $e->getMessage());
        }
    }

    public function restore(string $code): ?string
    {
        try {
            $this->svc()->restoreDictionary($code);
            return $this->back(route('dict.show', ['code' => $code]), 'success', 'Przywrócono słownik z kosza.');
        } catch (DictionaryException $e) {
            return $this->back(route('dict.index'), 'error', $e->getMessage());
        }
    }

    public function protect(string $code): ?string
    {
        if (!is_admin()) { http_response_code(403); return 'Zabezpieczenie słownika może zmienić tylko administrator.'; }
        $on = ($_POST['on'] ?? '') === '1';
        try {
            $this->svc()->setProtected($code, $on);
            return $this->back(route('dict.show', ['code' => $code]), 'success', $on ? 'Słownik zabezpieczono przed edycją.' : 'Zdjęto zabezpieczenie — słownik można edytować.');
        } catch (DictionaryException $e) {
            return $this->back(route('dict.index'), 'error', $e->getMessage());
        }
    }

    public function history(string $code): ?string
    {
        try { $d = $this->svc()->dictionary($code, true); }
        catch (DictionaryException $e) { return $this->back(route('dict.index'), 'error', $e->getMessage()); }
        $entryId = (int)($_GET['entry'] ?? 0) ?: null;
        return $this->render('history.php', ['dict' => $d, 'rows' => $this->svc()->history($d, $entryId, 500), 'entryId' => $entryId]);
    }

    // ══ Wpisy ════════════════════════════════════════════════════════════

    public function entryCreate(string $code): ?string
    {
        try { $d = $this->svc()->dictionary($code); }
        catch (DictionaryException $e) { return $this->back(route('dict.index'), 'error', $e->getMessage()); }
        return $this->entryForm($d, null, ['parent_code' => (string)($_GET['parent'] ?? ''), 'is_active' => '1'], []);
    }

    public function entryStore(string $code): ?string
    {
        try {
            $e = $this->svc()->createEntry($code, EntryInput::fromArray($this->entryPost()));
            $next = !empty($_POST['add_next']) ? route('dict.entry.create', ['code' => $code, 'parent' => $_POST['parent_code'] ?? '']) : route('dict.show', ['code' => $code]);
            return $this->back($next, 'success', "Dodano wpis „{$e->label}”.");
        } catch (DictionaryValidationException $ex) {
            return $this->entryForm($this->svc()->dictionary($code), null, $_POST, $ex->errors);
        } catch (DictionaryException $ex) {
            return $this->back(route('dict.show', ['code' => $code]), 'error', $ex->getMessage());
        }
    }

    public function entryEdit(string $code, int $id): ?string
    {
        try {
            $d = $this->svc()->dictionary($code);
            $e = $this->svc()->entry($d, $id);
        } catch (DictionaryException $ex) { return $this->back(route('dict.show', ['code' => $code]), 'error', $ex->getMessage()); }
        $old = $e->toArray($e->parentId ? $this->svc()->entry($d, $e->parentId)->code : null);
        $old['attributes'] = $e->attributes ? json_encode($e->attributes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
        foreach ($e->labels as $l => $v) $old['label_' . $l] = $v;
        $old['version'] = $e->version;
        $old['is_active'] = $e->isActive ? '1' : '';
        return $this->entryForm($d, $e, $old, []);
    }

    public function entryUpdate(string $code, int $id): ?string
    {
        try {
            $e = $this->svc()->updateEntry($code, $id, EntryInput::fromArray($this->entryPost()));
            return $this->back(route('dict.show', ['code' => $code]) . '#e' . $e->id, 'success', "Zapisano wpis „{$e->label}” (wersja {$e->version}).");
        } catch (DictionaryValidationException $ex) {
            $d = $this->svc()->dictionary($code);
            return $this->entryForm($d, $this->svc()->entry($d, $id), $_POST, $ex->errors);
        } catch (DictionaryConflictException $ex) {
            return $this->back(route('dict.entry.edit', ['code' => $code, 'id' => $id]), 'error', $ex->getMessage());
        } catch (DictionaryException $ex) {
            return $this->back(route('dict.show', ['code' => $code]), 'error', $ex->getMessage());
        }
    }

    public function entryDestroy(string $code, int $id): ?string
    {
        try {
            $this->svc()->deleteEntry($code, $id);
            return $this->back(route('dict.show', ['code' => $code]), 'success', 'Wpis przeniesiono do kosza.');
        } catch (DictionaryException $ex) {
            return $this->back(route('dict.show', ['code' => $code]), 'error', $ex->getMessage());
        }
    }

    public function entryRestore(string $code, int $id): ?string
    {
        try {
            $this->svc()->restoreEntry($code, $id);
            return $this->back(route('dict.show', ['code' => $code]) . '#e' . $id, 'success', 'Przywrócono wpis z kosza.');
        } catch (DictionaryException $ex) {
            return $this->back(route('dict.show', ['code' => $code, 'trash' => 1]), 'error', $ex->getMessage());
        }
    }

    // ══ Import / eksport ═════════════════════════════════════════════════

    public function export(string $code): ?string
    {
        $fmt = ($_GET['format'] ?? 'json') === 'csv' ? 'csv' : 'json';
        try {
            $body = $fmt === 'csv' ? dictTransfer()->exportCsv($code) : dictTransfer()->exportJson([$this->svc()->dictionary($code)->code]);
        } catch (DictionaryException $e) { return $this->back(route('dict.index'), 'error', $e->getMessage()); }
        $this->download($body, 'slownik_' . $code . '_' . date('Ymd') . '.' . $fmt, $fmt === 'csv' ? 'text/csv' : 'application/json');
        return null;
    }

    public function exportAll(): ?string
    {
        $this->download(dictTransfer()->exportJson(), 'slowniki_' . date('Ymd_His') . '.json', 'application/json');
        return null;
    }

    public function importForm(): string
    {
        return $this->render('import.php', ['dicts' => $this->svc()->dictionaryTree(), 'target' => (string)($_GET['to'] ?? ''), 'result' => null, 'dryRun' => true]);
    }

    public function import(): string
    {
        $f = $_FILES['file'] ?? null;
        $target = (string)($_POST['target'] ?? '');
        $dry = !empty($_POST['dry_run']);
        $view = fn(?array $result, ?string $error = null) => $this->render('import.php',
            ['dicts' => $this->svc()->dictionaryTree(), 'target' => $target, 'result' => $result, 'error' => $error, 'dryRun' => $dry]);
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) return $view(null, 'Wybierz plik CSV albo JSON.');
        if ((int)$f['size'] > DictionaryTransfer::MAX_BYTES) return $view(null, 'Plik jest za duży (limit 5 MB).');
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $fmt = match (true) { $ext === 'csv' || $ext === 'txt' => 'csv', $ext === 'json' => 'json', default => '' };
        if ($fmt === '') return $view(null, 'Obsługiwane pliki: .csv, .json.');
        try {
            $result = dictTransfer()->import((string)file_get_contents((string)$f['tmp_name']), $fmt, $target !== '' ? $target : null, $dry);
            return $view($result);
        } catch (DictionaryException $e) {
            return $view(null, $e->getMessage());
        }
    }

    // ══ JSON API (dla JS innych modułów) ═════════════════════════════════

    /** GET /api — lista słowników (wymaga odczytu modułu). */
    public function apiList(): array
    {
        if (!can_read('slowniki')) { http_response_code(403); return ['error' => 'Brak dostępu do Słownika Centralnego.']; }
        $counts = $this->svc()->entryCounts();
        return ['dictionaries' => array_map(fn($row) => $row[0]->toArray() + ['depth' => $row[1], 'entries' => $counts[$row[0]->id] ?? 0],
                                            $this->svc()->dictionaryTree())];
    }

    /**
     * GET /api/{code}?locale=en&at=2026-01-01&leaves=1 — aktywne wpisy obowiązujące w dniu `at`.
     * Każdy zalogowany (listy rozwijane w formularzach) — dlatego w słownikach NIE trzymamy danych wrażliwych.
     */
    public function apiOptions(string $code): array
    {
        $at = (string)($_GET['at'] ?? '');
        if ($at !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $at)) { http_response_code(422); return ['error' => 'Parametr at: RRRR-MM-DD.']; }
        if (!$this->svc()->exists($code)) { http_response_code(404); return ['error' => 'Nie ma takiego słownika.']; }
        $items = $this->svc()->getDictionaryItems($code, $_GET['locale'] ?? null, $at !== '' ? $at : null);
        if (!empty($_GET['leaves'])) $items = array_filter($items, fn($i) => !$i['has_children']);
        return ['code' => $code, 'items' => array_values($items)];
    }

    // ══ Pomocnicze ═══════════════════════════════════════════════════════

    private function entryForm(Dictionary $d, ?DictionaryEntry $e, array $old, array $errors): string
    {
        $all = $this->svc()->entries($d);
        $parentChoices = array_filter($this->orderTree($all, false), fn($x) => !$e || $x[0]->id !== $e->id);
        return $this->render('entryForm.php', ['dict' => $d, 'entry' => $e, 'old' => $old, 'errors' => $errors,
                                               'parentChoices' => $parentChoices, 'locales' => DictionaryService::extraLocales()]);
    }

    private function dictPost(?Dictionary $current): array
    {
        $in = $_POST;
        // Zabezpieczenie zmienia tylko administrator (osobny przycisk); formularz zachowuje bieżący stan.
        if (!is_admin()) $in['is_protected'] = $current?->isProtected ? '1' : '';
        return $in;
    }

    private function entryPost(): array
    {
        $in = $_POST;
        $in['is_active'] = !empty($_POST['is_active']);
        return $in;
    }

    private function findById(int $id): ?Dictionary
    {
        foreach ($this->svc()->allDictionaries(true) as $d) if ($d->id === $id) return $d;
        return null;
    }

    /**
     * Wpisy w kolejności drzewa: [DictionaryEntry, głębokość]. $trash = tylko usunięte (płasko).
     * @param DictionaryEntry[] $entries
     */
    /** Powyżej tylu wpisów (np. TERYT: ~4400) drzewo pokazujemy po gałęziach — inaczej strona ma kilka MB. */
    private const BIG_TREE = 500;

    /**
     * Duże drzewo: domyślnie tylko najwyższy poziom; ?pod=kod — wpis z bezpośrednimi podrzędnymi; ?q= — szukaj
     * po etykiecie i kodzie (do 300 wyników). Wiersze dostają liczbę ukrytych podrzędnych (link „N podrzędnych”).
     * @return array{0: list<array{0:mixed,1:int,2?:int}>, 1: array{big:bool,q:string,pod:string,podLabel:string,total:int,shown:int}}
     */
    private function limitTree(array $tree, bool $trash): array
    {
        $q = trim((string)($_GET['q'] ?? ''));
        $pod = trim((string)($_GET['pod'] ?? ''));
        $view = ['big' => false, 'q' => $q, 'pod' => $pod, 'podLabel' => '', 'total' => count($tree), 'shown' => count($tree)];
        if ($trash || (count($tree) <= self::BIG_TREE && $q === '' && $pod === '')) return [$tree, $view];
        $view['big'] = true;
        $kids = [];
        foreach ($tree as [$e]) if ($e->parentId !== null) $kids[$e->parentId] = ($kids[$e->parentId] ?? 0) + 1;
        $rows = [];
        if ($q !== '') {
            $n = mb_strtolower($q);
            foreach ($tree as [$e, $depth]) {
                if (mb_strpos(mb_strtolower($e->label), $n) === false && mb_strpos(mb_strtolower($e->code), $n) === false) continue;
                $rows[] = [$e, 0, $kids[$e->id] ?? 0];
                if (count($rows) >= 300) break;
            }
        } elseif ($pod !== '') {
            $root = null;
            foreach ($tree as [$e, $depth]) if ($e->code === $pod) { $root = $e; $rows[] = [$e, 0, 0]; $view['podLabel'] = $e->label; break; }
            if ($root) foreach ($tree as [$e]) if ($e->parentId === $root->id) $rows[] = [$e, 1, $kids[$e->id] ?? 0];
        } else {
            foreach ($tree as [$e, $depth]) if ($depth === 0) $rows[] = [$e, 0, $kids[$e->id] ?? 0];
        }
        $view['shown'] = count($rows);
        return [$rows, $view];
    }

    private function orderTree(array $entries, bool $trash): array
    {
        if ($trash) return array_map(fn($e) => [$e, 0], array_values(array_filter($entries, fn($e) => $e->isDeleted())));
        $live = array_filter($entries, fn($e) => !$e->isDeleted());
        $ids = [];
        foreach ($live as $e) $ids[$e->id] = true;
        $children = [];
        foreach ($live as $e) $children[$e->parentId !== null && isset($ids[$e->parentId]) ? $e->parentId : 0][] = $e;
        $out = [];
        $walk = function (int $pid, int $depth) use (&$walk, &$out, $children): void {
            foreach ($children[$pid] ?? [] as $e) { $out[] = [$e, $depth]; if ($depth < 20) $walk($e->id, $depth + 1); }
        };
        $walk(0, 0);
        return $out;
    }

    private function download(string $body, string $name, string $type): void
    {
        header('Content-Type: ' . $type . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.\-]/', '_', $name) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($body));
        echo $body;
    }
}
