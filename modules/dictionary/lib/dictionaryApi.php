<?php
declare(strict_types=1);
/**
 * modules/dictionary/lib/dictionaryApi.php — punkt wejścia Słownika Centralnego dla CAŁEGO systemu.
 *
 *   require_once ROOT . '/modules/dictionary/lib/dictionaryApi.php';
 *   $opts  = dictOptions('instytucje_dotujace');                    // ['kod' => 'Etykieta', …]
 *   $items = dictItems('instytucje_dotujace');                      // z atrybutami JSON, rodzicem i głębokością
 *   $label = dictLabel('instytucje_dotujace', $kod, $kod);       // etykieta zapisanej wartości
 *   if (!dictAllowed('instytucje_dotujace', $_POST['x'])) …        // walidacja wartości z formularza
 *
 * Funkcje-skróty NIGDY nie rzucają wyjątku: brak słownika, pusty słownik albo błąd bazy → $fallback
 * (zwykle dotychczasowa stała), więc moduł działa także przed migracją i na świeżej instalacji.
 * Pełne API: dictService() → DictionaryService (zapis, historia, import/eksport — patrz README.md modułu).
 */

require_once __DIR__ . '/DictionaryKind.php';
require_once __DIR__ . '/DictionaryExceptions.php';
require_once __DIR__ . '/Dictionary.php';
require_once __DIR__ . '/DictionaryEntry.php';
require_once __DIR__ . '/DictionaryDto.php';
require_once __DIR__ . '/DictionaryRepository.php';
require_once __DIR__ . '/DictionaryCache.php';
require_once __DIR__ . '/DictionaryService.php';
require_once __DIR__ . '/DictionaryTransfer.php';
require_once __DIR__ . '/dictionarySchema.php';

function dictRepository(): DictionaryRepository
{
    static $repo = null;
    return $repo ??= new DictionaryRepository(db());
}

/** Serwis jako bieżący użytkownik (historia zmian zapisuje autora); w CLI / bez sesji autor = null (system). */
function dictService(): DictionaryService
{
    static $svc = [];
    dictionaryMigrate();
    $uid = null;
    if (PHP_SAPI !== 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        $u = function_exists('current_user') ? current_user() : null;
        $uid = $u ? (int)($u['id'] ?? 0) ?: null : null;
    }
    return $svc[$uid ?? 0] ??= new DictionaryService(dictRepository(), new DictionaryCache(), $uid);
}

function dictTransfer(): DictionaryTransfer
{
    return new DictionaryTransfer(dictService());
}

/** @return array<string,string> */
function dictOptions(string $code, array $fallback = [], ?string $locale = null, ?string $at = null): array
{
    try {
        $o = dictService()->getDictionaryOptions($code, $locale, $at);
        return $o ?: $fallback;
    } catch (\Throwable $e) {
        return $fallback;
    }
}

/** @return array<string, array{code:string,label:string,parent:?string,depth:int,has_children:bool,attributes:array,description:?string}> */
function dictItems(string $code, array $fallback = [], ?string $locale = null, ?string $at = null): array
{
    try {
        $o = dictService()->getDictionaryItems($code, $locale, $at);
        return $o ?: $fallback;
    } catch (\Throwable $e) {
        return $fallback;
    }
}

function dictLabel(string $code, ?string $entryCode, ?string $fallback = null, ?string $locale = null): ?string
{
    if ($entryCode === null || $entryCode === '') return $fallback;
    try {
        return dictService()->getLabel($code, $entryCode, $locale) ?? $fallback;
    } catch (\Throwable $e) {
        return $fallback;
    }
}

/** Czy wartość jest aktywnym, obowiązującym wpisem słownika; $fallback = dozwolone klucze, gdy słownika brak. */
function dictAllowed(string $code, ?string $entryCode, array $fallback = []): bool
{
    if ($entryCode === null || $entryCode === '') return false;
    try {
        if (dictService()->exists($code)) return dictService()->isAllowed($code, $entryCode);
    } catch (\Throwable $e) {}
    return in_array($entryCode, $fallback, true);
}
