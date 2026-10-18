<?php
declare(strict_types=1);
/**
 * DictionaryService — logika Słownika Centralnego i jego API dla reszty systemu.
 *
 * ODCZYT (dla modułów, z cache — patrz DictionaryCache):
 *   getDictionaryOptions('kod')            → ['kod_wpisu' => 'Etykieta', …] aktywne i obowiązujące dziś, w kolejności drzewa
 *   getDictionaryItems('kod')              → ['kod_wpisu' => ['label','parent','depth','has_children','attributes'], …]
 *   getLabel('kod', 'kod_wpisu')           → etykieta także wpisu nieaktywnego/wygasłego (dane historyczne) albo null
 *   isAllowed('kod', 'kod_wpisu')          → walidacja wartości z formularza (aktywny i obowiązujący)
 * Wszystkie przyjmują $locale (null = polski, etykieta główna) i $at (Y-m-d, null = dziś).
 *
 * ZAPIS (panel, import): reguły blokad —
 *   is_protected = słownik zamrożony w całości (zdjąć może tylko administrator: setProtected());
 *   kind=system  = kody wpisów są w kodzie: nie wolno zmienić kodu wpisu/słownika ani usunąć wpisu/słownika.
 * Każdy zapis → wiersz w dictionary_history (przed/po) i unieważnienie cache.
 */
final class DictionaryService
{
    public const DEFAULT_LOCALE = 'pl';

    public function __construct(
        private readonly DictionaryRepository $repo,
        private readonly DictionaryCache $cache,
        private readonly ?int $actorId = null,
    ) {}

    // ══ Odczyt (API dla modułów) ═══════════════════════════════════════════

    /** @return array<string,string> kod wpisu => etykieta */
    public function getDictionaryOptions(string $code, ?string $locale = null, ?string $at = null, bool $leavesOnly = false): array
    {
        $out = [];
        foreach ($this->getDictionaryItems($code, $locale, $at) as $k => $it) {
            if ($leavesOnly && $it['has_children']) continue;
            $out[$k] = $it['label'];
        }
        return $out;
    }

    /**
     * Aktywne wpisy obowiązujące w dniu $at, w kolejności drzewa (rodzic, potem dzieci).
     * @return array<string, array{code:string,label:string,parent:?string,depth:int,has_children:bool,attributes:array,description:?string}>
     */
    public function getDictionaryItems(string $code, ?string $locale = null, ?string $at = null): array
    {
        $at = $at ?? date('Y-m-d');
        $loc = $this->normLocale($locale);
        return $this->cache->remember("items:$code:$loc:$at", function () use ($code, $loc, $at) {
            $dict = $this->repo->findDictionaryByCode($code);
            if (!$dict) return [];
            $live = array_filter($this->repo->entries($dict->id), fn(DictionaryEntry $e) => $e->isActive && $e->isValidOn($at));
            return $this->flattenTree($live, $loc);
        });
    }

    /** Etykieta wpisu (także nieaktywnego, wygasłego albo w koszu — do wyświetlania zapisanych danych). */
    public function getLabel(string $code, string $entryCode, ?string $locale = null): ?string
    {
        $loc = $this->normLocale($locale);
        $map = $this->cache->remember("labels:$code:$loc", function () use ($code, $loc) {
            $dict = $this->repo->findDictionaryByCode($code, true);
            if (!$dict) return [];
            $out = [];
            foreach ($this->repo->entries($dict->id, true) as $e) $out[$e->code] = $e->labelIn($loc === self::DEFAULT_LOCALE ? null : $loc);
            return $out;
        });
        return $map[$entryCode] ?? null;
    }

    public function isAllowed(string $code, string $entryCode, ?string $at = null): bool
    {
        return isset($this->getDictionaryItems($code, null, $at)[$entryCode]);
    }

    public function exists(string $code): bool
    {
        return $this->cache->remember("exists:$code", fn() => $this->repo->findDictionaryByCode($code) !== null);
    }

    /** Języki etykiet dodatkowych (ustawienie organizacji dictionary_locales, domyślnie en,uk). */
    public static function extraLocales(): array
    {
        $raw = function_exists('org_setting') ? trim((string)org_setting('dictionary_locales')) : '';
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw !== '' ? $raw : 'en,uk') as $l) {
            $l = strtolower($l);
            if (preg_match('/^[a-z]{2,8}$/', $l) && $l !== self::DEFAULT_LOCALE) $out[] = $l;
        }
        return array_values(array_unique($out));
    }

    // ══ Odczyt (panel) ═════════════════════════════════════════════════════

    public function dictionary(string $code, bool $withDeleted = false): Dictionary
    {
        return $this->repo->findDictionaryByCode($code, $withDeleted) ?? throw new DictionaryNotFoundException("Nie ma słownika „{$code}”.");
    }

    /** @return Dictionary[] */
    public function allDictionaries(bool $withDeleted = false): array { return $this->repo->allDictionaries($withDeleted); }

    public function entryCounts(): array { return $this->repo->entryCounts(); }

    /** @return DictionaryEntry[] */
    public function entries(Dictionary $dict, bool $withDeleted = false): array { return $this->repo->entries($dict->id, $withDeleted); }

    public function entry(Dictionary $dict, int $id): DictionaryEntry
    {
        $e = $this->repo->findEntry($id);
        if (!$e || $e->dictionaryId !== $dict->id) throw new DictionaryNotFoundException('Nie ma takiego wpisu w tym słowniku.');
        return $e;
    }

    /**
     * Drzewo słowników do panelu: lista [Dictionary, głębokość] w kolejności drzewa.
     * @return array<int, array{0:Dictionary,1:int}>
     */
    public function dictionaryTree(bool $withDeleted = false): array
    {
        $all = $this->repo->allDictionaries($withDeleted);
        $ids = [];
        foreach ($all as $d) $ids[$d->id] = true;
        $children = [];
        foreach ($all as $d) $children[$d->parentId !== null && isset($ids[$d->parentId]) ? $d->parentId : 0][] = $d;
        $out = [];
        $walk = function (int $pid, int $depth) use (&$walk, &$out, $children): void {
            foreach ($children[$pid] ?? [] as $d) { $out[] = [$d, $depth]; if ($depth < 20) $walk($d->id, $depth + 1); }
        };
        $walk(0, 0);
        return $out;
    }

    public function history(Dictionary $dict, ?int $entryId = null, int $limit = 200): array
    {
        $rows = $this->repo->history($dict->id, $entryId, $limit);
        $names = $this->repo->userNames(array_column($rows, 'user_id'));
        foreach ($rows as &$r) $r['user_name'] = $names[(int)$r['user_id']] ?? ($r['user_id'] ? '#' . $r['user_id'] : 'system');
        return $rows;
    }

    // ══ Zapis: słowniki ═══════════════════════════════════════════════════

    public function createDictionary(DictionaryInput $in): Dictionary
    {
        if ($old = $this->repo->findDictionaryByCode($in->code, true)) {
            throw new DictionaryValidationException(['code' => $old->isDeleted()
                ? "Słownik o kodzie „{$in->code}” jest w koszu — przywróć go zamiast zakładać nowy."
                : "Słownik o kodzie „{$in->code}” już istnieje."]);
        }
        $parentId = $this->resolveParentDictionary($in->parentCode, null);
        $now = $this->now();
        $id = $this->repo->transaction(function () use ($in, $parentId, $now) {
            $id = $this->repo->insertDictionary([
                'parent_id' => $parentId, 'code' => $in->code, 'name' => $in->name, 'description' => $in->description,
                'kind' => $in->kind->value, 'is_protected' => $in->isProtected ? 1 : 0, 'sort_order' => $in->sortOrder,
                'created_at' => $now, 'created_by' => $this->actorId, 'updated_at' => $now, 'updated_by' => $this->actorId,
            ]);
            $this->repo->addHistory($id, null, 'dict.create', null, $this->dictSnapshot($id), $this->actorId);
            return $id;
        });
        $this->cache->invalidate();
        return $this->repo->findDictionaryById($id);
    }

    public function updateDictionary(string $code, DictionaryInput $in): Dictionary
    {
        $d = $this->dictionary($code);
        $this->assertEditable($d);
        if ($in->code !== $d->code) {
            if ($d->kind->codesFrozen()) throw new DictionaryLockedException('Kodu słownika systemowego nie można zmienić — używa go kod aplikacji.');
            if ($this->repo->findDictionaryByCode($in->code, true)) throw new DictionaryValidationException(['code' => "Kod „{$in->code}” jest zajęty."]);
        }
        $parentId = $this->resolveParentDictionary($in->parentCode, $d->id);
        $before = $this->dictSnapshot($d->id);
        $this->repo->transaction(function () use ($d, $in, $parentId, $before) {
            $this->repo->updateDictionary($d->id, [
                'parent_id' => $parentId, 'code' => $in->code, 'name' => $in->name, 'description' => $in->description,
                'kind' => $in->kind->value, 'is_protected' => $in->isProtected ? 1 : 0, 'sort_order' => $in->sortOrder,
                'updated_at' => $this->now(), 'updated_by' => $this->actorId,
            ]);
            $this->repo->addHistory($d->id, null, 'dict.update', $before, $this->dictSnapshot($d->id), $this->actorId);
        });
        $this->cache->invalidate();
        return $this->repo->findDictionaryById($d->id);
    }

    /** Włącza/zdejmuje zabezpieczenie (kontroler wpuszcza tu tylko administratora). */
    public function setProtected(string $code, bool $protected): void
    {
        $d = $this->dictionary($code);
        if ($d->isProtected === $protected) return;
        $this->repo->transaction(function () use ($d, $protected) {
            $this->repo->updateDictionary($d->id, ['is_protected' => $protected ? 1 : 0, 'updated_at' => $this->now(), 'updated_by' => $this->actorId]);
            $this->repo->addHistory($d->id, null, $protected ? 'dict.protect' : 'dict.unprotect', null, null, $this->actorId);
        });
        $this->cache->invalidate();
    }

    public function deleteDictionary(string $code): void
    {
        $d = $this->dictionary($code);
        $this->assertEditable($d);
        if ($d->kind->codesFrozen()) throw new DictionaryLockedException('Słownika systemowego nie można usunąć — używa go kod aplikacji.');
        foreach ($this->repo->allDictionaries() as $c) {
            if ($c->parentId === $d->id) throw new DictionaryLockedException("Najpierw przenieś albo usuń słowniki podrzędne (np. „{$c->name}”).");
        }
        $this->repo->transaction(function () use ($d) {
            $this->repo->updateDictionary($d->id, ['deleted_at' => $this->now(), 'updated_at' => $this->now(), 'updated_by' => $this->actorId]);
            $this->repo->addHistory($d->id, null, 'dict.delete', null, null, $this->actorId);
        });
        $this->cache->invalidate();
    }

    public function restoreDictionary(string $code): void
    {
        $d = $this->dictionary($code, true);
        if (!$d->isDeleted()) return;
        $this->repo->transaction(function () use ($d) {
            $this->repo->updateDictionary($d->id, ['deleted_at' => null, 'updated_at' => $this->now(), 'updated_by' => $this->actorId]);
            $this->repo->addHistory($d->id, null, 'dict.restore', null, null, $this->actorId);
        });
        $this->cache->invalidate();
    }

    // ══ Zapis: wpisy ══════════════════════════════════════════════════════

    public function createEntry(string $dictCode, EntryInput $in): DictionaryEntry
    {
        $d = $this->dictionary($dictCode);
        $this->assertEditable($d);
        if ($old = $this->repo->findEntryByCode($d->id, $in->code)) {
            throw new DictionaryValidationException(['code' => $old->isDeleted()
                ? "Wpis „{$in->code}” jest w koszu — przywróć go zamiast dodawać nowy."
                : "Wpis o kodzie „{$in->code}” już istnieje."]);
        }
        $parentId = $this->resolveParentEntry($d, $in->parentCode, null);
        $id = $this->repo->transaction(function () use ($d, $in, $parentId) {
            $now = $this->now();
            $id = $this->repo->insertEntry($this->entryRow($in, $parentId) + [
                'dictionary_id' => $d->id, 'version' => 1,
                'created_at' => $now, 'created_by' => $this->actorId, 'updated_at' => $now, 'updated_by' => $this->actorId,
            ]);
            $this->repo->replaceLabels($id, $in->labels);
            $this->repo->addHistory($d->id, $id, 'entry.create', null, $this->entrySnapshot($id), $this->actorId);
            return $id;
        });
        $this->cache->invalidate();
        return $this->repo->findEntry($id);
    }

    public function updateEntry(string $dictCode, int $id, EntryInput $in): DictionaryEntry
    {
        $d = $this->dictionary($dictCode);
        $this->assertEditable($d);
        $e = $this->entry($d, $id);
        if ($in->code !== $e->code) {
            if ($d->kind->codesFrozen()) throw new DictionaryLockedException("Kodu wpisu „{$e->code}” w słowniku systemowym nie można zmienić — używa go kod aplikacji.");
            if ($this->repo->findEntryByCode($d->id, $in->code)) throw new DictionaryValidationException(['code' => "Kod „{$in->code}” jest zajęty w tym słowniku."]);
        }
        $parentId = $this->resolveParentEntry($d, $in->parentCode, $e->id);
        $before = $this->entrySnapshot($e->id);
        $this->repo->transaction(function () use ($d, $e, $in, $parentId, $before) {
            $ok = $this->repo->updateEntry($e->id, $this->entryRow($in, $parentId) + [
                'version' => $e->version + 1, 'updated_at' => $this->now(), 'updated_by' => $this->actorId,
            ], $in->expectedVersion ?? $e->version);
            if (!$ok) throw new DictionaryConflictException('Ktoś zmienił ten wpis w międzyczasie — odśwież stronę i nanieś zmiany ponownie.');
            $this->repo->replaceLabels($e->id, $in->labels);
            $after = $this->entrySnapshot($e->id);
            $this->repo->addHistory($d->id, $e->id, 'entry.update', $before, $after, $this->actorId);
        });
        $this->cache->invalidate();
        return $this->repo->findEntry($e->id);
    }

    public function deleteEntry(string $dictCode, int $id): void
    {
        $d = $this->dictionary($dictCode);
        $this->assertEditable($d);
        $e = $this->entry($d, $id);
        if ($e->isDeleted()) return;
        if ($d->kind->codesFrozen()) throw new DictionaryLockedException("Wpisu słownika systemowego nie można usunąć — wyłącz go (odznacz „aktywny”).");
        if ($this->repo->hasLiveChildren($e->id)) throw new DictionaryLockedException('Wpis ma wpisy podrzędne — najpierw je przenieś albo usuń.');
        $this->repo->transaction(function () use ($d, $e) {
            $this->repo->updateEntry($e->id, ['deleted_at' => $this->now(), 'version' => $e->version + 1, 'updated_at' => $this->now(), 'updated_by' => $this->actorId]);
            $this->repo->addHistory($d->id, $e->id, 'entry.delete', null, null, $this->actorId);
        });
        $this->cache->invalidate();
    }

    public function restoreEntry(string $dictCode, int $id): void
    {
        $d = $this->dictionary($dictCode);
        $this->assertEditable($d);
        $e = $this->entry($d, $id);
        if (!$e->isDeleted()) return;
        $this->repo->transaction(function () use ($d, $e) {
            $this->repo->updateEntry($e->id, ['deleted_at' => null, 'version' => $e->version + 1, 'updated_at' => $this->now(), 'updated_by' => $this->actorId]);
            $this->repo->addHistory($d->id, $e->id, 'entry.restore', null, null, $this->actorId);
        });
        $this->cache->invalidate();
    }

    // ══ Import (używa DictionaryTransfer) ═════════════════════════════════

    /**
     * Wstawia albo aktualizuje słownik i jego wpisy (po kodach). Wpisy spoza pliku zostają bez zmian.
     * Reguły blokad jak w panelu; błąd pojedynczego wpisu nie przerywa importu (trafia do 'errors').
     * @param array $dictData dane słownika (jak DictionaryInput::fromArray); null = słownik musi już istnieć ($dictCode)
     * @param array<int,array> $entries wiersze jak EntryInput::fromArray (parent_code = kod rodzica)
     * @return array{dictionary:string, created:int, updated:int, unchanged:int, errors:array<int,string>}
     */
    public function importDictionary(?array $dictData, array $entries, ?string $dictCode = null): array
    {
        $stats = ['dictionary' => '', 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []];
        if ($dictData !== null) {
            $din = DictionaryInput::fromArray($dictData);
            $existing = $this->repo->findDictionaryByCode($din->code, true);
            if ($existing?->isDeleted()) $this->restoreDictionary($din->code);
            $d = $existing ? $this->dictionary($din->code) : $this->createDictionary($din);
            if ($existing && !$d->isProtected && $this->dictChanged($d, $din)) $this->updateDictionary($d->code, $din);
        } else {
            $d = $this->dictionary((string)$dictCode);
        }
        $stats['dictionary'] = $d->code;
        $this->assertEditable($this->dictionary($d->code));

        // Rodzice przed dziećmi: najpierw wiersze bez parent_code, potem kolejne poziomy (do 20 przebiegów).
        $pending = array_values($entries);
        for ($pass = 0; $pending && $pass < 20; $pass++) {
            $next = [];
            foreach ($pending as $i => $row) {
                $line = (int)($row['_line'] ?? ($i + 1));
                try {
                    $in = EntryInput::fromArray($row);
                    if ($in->parentCode !== null && !$this->repo->findEntryByCode($d->id, $in->parentCode)) {
                        if (array_filter($pending, fn($r) => (string)($r['code'] ?? '') === $in->parentCode)) { $next[] = $row; continue; }
                    }
                    $cur = $this->repo->findEntryByCode($d->id, $in->code);
                    if (!$cur) { $this->createEntry($d->code, $in); $stats['created']++; continue; }
                    if ($cur->isDeleted()) $this->restoreEntry($d->code, $cur->id);
                    $cur = $this->repo->findEntry($cur->id);
                    if ($this->entryChanged($d, $cur, $in)) {
                        $this->updateEntry($d->code, $cur->id, new EntryInput($in->code, $in->label, $in->description, $in->attributes,
                            $in->sortOrder, $in->isActive, $in->validFrom, $in->validTo, $in->parentCode, $in->labels, $cur->version));
                        $stats['updated']++;
                    } else {
                        $stats['unchanged']++;
                    }
                } catch (DictionaryException $e) {
                    $stats['errors'][$line] = ($row['code'] ?? '?') . ': ' . $e->getMessage();
                }
            }
            if (count($next) === count($pending)) {      // brak postępu — rodzica nie ma w pliku ani w bazie
                foreach ($next as $row) $stats['errors'][(int)($row['_line'] ?? 0)] = ($row['code'] ?? '?') . ': nie ma wpisu nadrzędnego „' . ($row['parent_code'] ?? '') . '”.';
                break;
            }
            $pending = $next;
        }
        return $stats;
    }

    // ══ Pomocnicze ════════════════════════════════════════════════════════

    private function assertEditable(Dictionary $d): void
    {
        if ($d->isProtected) throw new DictionaryLockedException("Słownik „{$d->name}” jest zabezpieczony przed edycją — zabezpieczenie może zdjąć administrator.");
    }

    private function resolveParentDictionary(?string $parentCode, ?int $selfId): ?int
    {
        if ($parentCode === null) return null;
        $p = $this->repo->findDictionaryByCode($parentCode);
        if (!$p) throw new DictionaryValidationException(['parent_code' => "Nie ma kategorii nadrzędnej „{$parentCode}”."]);
        for ($cur = $p, $guard = 0; $cur && $guard < 50; $guard++) {          // bez cykli w drzewie
            if ($selfId !== null && $cur->id === $selfId) throw new DictionaryValidationException(['parent_code' => 'Słownik nie może leżeć w swoim własnym poddrzewie.']);
            $cur = $cur->parentId !== null ? $this->repo->findDictionaryById($cur->parentId) : null;
        }
        return $p->id;
    }

    private function resolveParentEntry(Dictionary $d, ?string $parentCode, ?int $selfId): ?int
    {
        if ($parentCode === null) return null;
        $p = $this->repo->findEntryByCode($d->id, $parentCode);
        if (!$p || $p->isDeleted()) throw new DictionaryValidationException(['parent_code' => "Nie ma wpisu nadrzędnego „{$parentCode}” w tym słowniku."]);
        for ($cur = $p, $guard = 0; $cur && $guard < 50; $guard++) {
            if ($selfId !== null && $cur->id === $selfId) throw new DictionaryValidationException(['parent_code' => 'Wpis nie może leżeć pod samym sobą.']);
            $cur = $cur->parentId !== null ? $this->repo->findEntry($cur->parentId) : null;
        }
        return $p->id;
    }

    private function entryRow(EntryInput $in, ?int $parentId): array
    {
        return [
            'parent_id' => $parentId, 'code' => $in->code, 'label' => $in->label, 'description' => $in->description,
            'attributes' => $in->attributes ? json_encode($in->attributes, JSON_UNESCAPED_UNICODE) : null,
            'sort_order' => $in->sortOrder, 'is_active' => $in->isActive ? 1 : 0,
            'valid_from' => $in->validFrom, 'valid_to' => $in->validTo,
        ];
    }

    private function dictSnapshot(int $id): ?array
    {
        $d = $this->repo->findDictionaryById($id);
        if (!$d) return null;
        $parent = $d->parentId !== null ? $this->repo->findDictionaryById($d->parentId) : null;
        return $d->toArray() + ['parent_code' => $parent?->code];
    }

    private function entrySnapshot(int $id): ?array
    {
        $e = $this->repo->findEntry($id);
        if (!$e) return null;
        $parent = $e->parentId !== null ? $this->repo->findEntry($e->parentId) : null;
        return $e->toArray($parent?->code) + ['version' => $e->version];
    }

    private function dictChanged(Dictionary $d, DictionaryInput $in): bool
    {
        $parent = $d->parentId !== null ? $this->repo->findDictionaryById($d->parentId)?->code : null;
        return [$d->name, $d->description, $d->kind, $d->isProtected, $d->sortOrder, $parent]
           !== [$in->name, $in->description, $in->kind, $in->isProtected, $in->sortOrder, $in->parentCode];
    }

    private function entryChanged(Dictionary $d, DictionaryEntry $e, EntryInput $in): bool
    {
        $parent = $e->parentId !== null ? $this->repo->findEntry($e->parentId)?->code : null;
        $norm = fn(array $a) => json_encode(self::ksortDeep($a), JSON_UNESCAPED_UNICODE);
        return [$e->label, $e->description, $norm($e->attributes), $e->sortOrder, $e->isActive, $e->validFrom, $e->validTo, $parent, $norm($e->labels)]
           !== [$in->label, $in->description, $norm($in->attributes), $in->sortOrder, $in->isActive, $in->validFrom, $in->validTo, $in->parentCode, $norm($in->labels)];
    }

    private static function ksortDeep(array $a): array
    {
        foreach ($a as &$v) if (is_array($v)) $v = self::ksortDeep($v);
        if (!array_is_list($a)) ksort($a);
        return $a;
    }

    /** @param DictionaryEntry[] $entries */
    private function flattenTree(array $entries, string $loc): array
    {
        $byId = [];
        foreach ($entries as $e) $byId[$e->id] = $e;
        $children = [];
        foreach ($entries as $e) $children[$e->parentId !== null && isset($byId[$e->parentId]) ? $e->parentId : 0][] = $e;
        $out = [];
        $walk = function (int $pid, int $depth, ?string $parentCode) use (&$walk, &$out, $children, $loc): void {
            foreach ($children[$pid] ?? [] as $e) {
                $out[$e->code] = [
                    'code' => $e->code, 'label' => $e->labelIn($loc === self::DEFAULT_LOCALE ? null : $loc),
                    'parent' => $parentCode, 'depth' => $depth, 'has_children' => !empty($children[$e->id]),
                    'attributes' => $e->attributes, 'description' => $e->description,
                ];
                if ($depth < 20) $walk($e->id, $depth + 1, $e->code);
            }
        };
        $walk(0, 0, null);
        return $out;
    }

    private function normLocale(?string $locale): string
    {
        $l = strtolower(trim((string)$locale));
        return preg_match('/^[a-z]{2,8}$/', $l) ? $l : self::DEFAULT_LOCALE;
    }

    private function now(): string { return date('Y-m-d H:i:s'); }
}
