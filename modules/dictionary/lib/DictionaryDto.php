<?php
declare(strict_types=1);
/**
 * DTO wejściowe Słownika Centralnego: walidacja i normalizacja danych z formularza, importu albo innego modułu.
 * fromArray() rzuca DictionaryValidationException z listą błędów per pole — do tabel trafiają tylko te obiekty.
 */

/** Kod słownika/wpisu: litery, cyfry, _ . - (bez spacji), do 64 znaków. */
function dictNormalizeCode(string $code): string
{
    return trim($code);
}

function dictValidCode(string $code): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,63}$/', $code);
}

final class DictionaryInput
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly DictionaryKind $kind,
        public readonly bool $isProtected,
        public readonly int $sortOrder,
        public readonly ?string $parentCode,
    ) {}

    public static function fromArray(array $in): self
    {
        $err  = [];
        $code = dictNormalizeCode((string)($in['code'] ?? ''));
        $name = trim((string)($in['name'] ?? ''));
        if (!dictValidCode($code)) $err['code'] = 'Kod słownika: 1–64 znaki, litery bez polskich znaków, cyfry, _ . - (bez spacji).';
        if ($name === '' || mb_strlen($name) > 190) $err['name'] = 'Podaj nazwę słownika (do 190 znaków).';
        $kind = DictionaryKind::tryFrom((string)($in['kind'] ?? 'business'));
        if ($kind === null) $err['kind'] = 'Nieznany rodzaj słownika.';
        $parent = trim((string)($in['parent_code'] ?? ''));
        if ($parent !== '' && !dictValidCode($parent)) $err['parent_code'] = 'Błędny kod kategorii nadrzędnej.';
        if ($parent !== '' && $parent === $code) $err['parent_code'] = 'Słownik nie może być swoją kategorią nadrzędną.';
        if ($err) throw new DictionaryValidationException($err);
        $desc = trim((string)($in['description'] ?? ''));
        return new self($code, $name, $desc !== '' ? $desc : null, $kind, dictBool($in['is_protected'] ?? false),
                        (int)($in['sort_order'] ?? 0), $parent !== '' ? $parent : null);
    }
}

final class EntryInput
{
    /** @param array<string,string> $labels tłumaczenia [locale => etykieta] */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly ?string $description,
        public readonly array $attributes,
        public readonly int $sortOrder,
        public readonly bool $isActive,
        public readonly ?string $validFrom,
        public readonly ?string $validTo,
        public readonly ?string $parentCode,
        public readonly array $labels,
        public readonly ?int $expectedVersion = null,
    ) {}

    /**
     * Klucze: code, label, description, attributes (tablica albo tekst JSON), sort_order, is_active,
     * valid_from, valid_to (Y-m-d), parent_code, labels ([locale => etykieta]) albo label_xx, version.
     */
    public static function fromArray(array $in): self
    {
        $err   = [];
        $code  = dictNormalizeCode((string)($in['code'] ?? ''));
        $label = trim((string)($in['label'] ?? ''));
        if (!dictValidCode($code)) $err['code'] = 'Kod wpisu: 1–64 znaki, litery bez polskich znaków, cyfry, _ . - (bez spacji).';
        if ($label === '' || mb_strlen($label) > 255) $err['label'] = 'Podaj etykietę wpisu (do 255 znaków).';

        $attrs = $in['attributes'] ?? [];
        if (is_string($attrs)) {
            $attrs = trim($attrs);
            if ($attrs === '') $attrs = [];
            else {
                $dec = json_decode($attrs, true);
                if (!is_array($dec) || ($dec !== [] && array_is_list($dec))) { $err['attributes'] = 'Atrybuty muszą być obiektem JSON, np. {"adres": "…"}.'; $dec = []; }
                $attrs = $dec;
            }
        } elseif ($attrs instanceof \stdClass) {
            $attrs = (array)$attrs;
        }
        if (!is_array($attrs)) { $err['attributes'] = 'Atrybuty muszą być obiektem JSON.'; $attrs = []; }

        $from = dictDateOrNull($in['valid_from'] ?? null, 'valid_from', $err);
        $to   = dictDateOrNull($in['valid_to'] ?? null, 'valid_to', $err);
        if ($from !== null && $to !== null && $to < $from) $err['valid_to'] = 'Data „obowiązuje do” jest wcześniejsza niż „obowiązuje od”.';

        $parent = trim((string)($in['parent_code'] ?? ''));
        if ($parent !== '' && $parent === $code) $err['parent_code'] = 'Wpis nie może być swoim rodzicem.';

        $labels = [];
        $src = $in['labels'] ?? [];
        if ($src instanceof \stdClass) $src = (array)$src;
        foreach ($in as $k => $v) if (is_string($k) && preg_match('/^label_([a-z]{2,8})$/', $k, $m)) $src[$m[1]] = $v;
        foreach ((array)$src as $loc => $v) {
            $loc = strtolower(trim((string)$loc)); $v = trim((string)$v);
            if ($v === '' || !preg_match('/^[a-z]{2,8}$/', $loc)) continue;
            if (mb_strlen($v) > 255) { $err['label_' . $loc] = "Etykieta ($loc) jest za długa."; continue; }
            $labels[$loc] = $v;
        }
        if ($err) throw new DictionaryValidationException($err);

        $desc = trim((string)($in['description'] ?? ''));
        $ver  = isset($in['version']) && (string)$in['version'] !== '' ? (int)$in['version'] : null;
        return new self($code, $label, $desc !== '' ? $desc : null, $attrs, (int)($in['sort_order'] ?? 0),
                        dictBool($in['is_active'] ?? true), $from, $to, $parent !== '' ? $parent : null, $labels, $ver);
    }
}

function dictBool(mixed $v): bool
{
    if (is_bool($v)) return $v;
    return in_array(strtolower(trim((string)$v)), ['1', 'true', 'tak', 'yes', 'on', 't'], true);
}

function dictDateOrNull(mixed $v, string $field, array &$err): ?string
{
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) { $err[$field] = 'Data w formacie RRRR-MM-DD.'; return null; }
    return $v;
}
