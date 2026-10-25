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
    // Autora ustalamy tylko, gdy sesja już działa albo da się ją jeszcze bezpiecznie wystartować (nagłówki niewysłane) —
    // odczyt słownika w trakcie renderowania strony (np. publiczny podgląd zgłoszenia) nie może startować sesji po wyjściu.
    if (session_status() === PHP_SESSION_ACTIVE || (PHP_SAPI !== 'cli' && !headers_sent())) {
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

/**
 * <datalist> z podpowiedziami ze słownika dla zwykłego pola tekstowego (wolny tekst nadal dozwolony) — dodaj do pola
 * atrybut list="dl-{$inputId}". $fill = [atrybut wpisu => id pola]: po wybraniu podpowiedzi uzupełnia PUSTE pola
 * wartościami z atrybutów JSON wpisu (np. ['adres' => 'organ_adres']). Grupy (wpisy z dziećmi) są pomijane.
 * Pusty słownik / błąd → pusty string (pole działa jak dotąd).
 */
function dictDatalist(string $code, string $inputId, array $fill = []): string
{
    $items = array_filter(dictItems($code), fn($i) => !$i['has_children']);
    if (!$items) return '';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $map = [];
    $html = '<datalist id="dl-' . $e($inputId) . '">';
    foreach ($items as $i) {
        $html .= '<option value="' . $e($i['label']) . '">' . $e($i['attributes']['miasto'] ?? '') . '</option>';
        if ($fill) $map[$i['label']] = array_intersect_key($i['attributes'], $fill);
    }
    $html .= '</datalist>';
    if ($fill && array_filter($map)) {
        $json = json_encode(['input' => $inputId, 'fill' => $fill, 'map' => $map], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html .= '<script>(function(c){var el=document.getElementById(c.input);if(!el)return;'
               . 'el.addEventListener("change",function(){var a=c.map[el.value];if(!a)return;'
               . 'Object.keys(c.fill).forEach(function(k){var t=document.getElementById(c.fill[k]);'
               . 'if(t&&!t.value&&a[k]){t.value=a[k];t.dispatchEvent(new Event("input",{bubbles:true}));}});});})(' . $json . ');</script>';
    }
    return $html;
}
