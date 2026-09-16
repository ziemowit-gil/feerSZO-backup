<?php
/**
 * modules/equi_exams/logic/json_helpers.php — tolerancyjne czytniki wartości z
 * tablic-jak-JSON, współdzielone przez grading/authoring/generator/stats.
 *
 * Port semantyki Json.java (str/num/integer/id/bool/strings/has) z exam-engine
 * (Java) — świadomie tolerancyjne (liczba jako string, "tak"/"nie" jako bool
 * itd.), bo dokładnie takie dane przychodzą dziś z formularzy w karty30/ti/.
 * Nie myl z PHP-owym json_encode/decode — te funkcje działają NA już
 * zdekodowanej tablicy asocjacyjnej.
 */

function ex_cfg_bool(array $m, string $key, bool $default): bool {
    if (!array_key_exists($key, $m) || $m[$key] === null) return $default;
    $v = $m[$key];
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return $v != 0;
    if (is_string($v)) {
        $s = strtolower(trim($v));
        if (in_array($s, ['1', 'true', 'tak', 'yes', 'on'], true)) return true;
        if (in_array($s, ['0', 'false', 'nie', 'no', 'off'], true)) return false;
        return $default;
    }
    return $default;
}

function ex_cfg_num(array $m, string $key, float $default): float {
    if (!array_key_exists($key, $m) || $m[$key] === null) return $default;
    $v = $m[$key];
    if (is_bool($v)) return $v ? 1.0 : 0.0;
    if (is_int($v) || is_float($v)) return (float)$v;
    if (is_string($v)) {
        $t = trim($v);
        return is_numeric($t) ? (float)$t : $default;
    }
    return $default;
}

/** Odpowiednik Json.integer/Json.id — Math.round(num(...)). */
function ex_cfg_int(array $m, string $key, int $default): int {
    if (!array_key_exists($key, $m) || $m[$key] === null) return $default;
    return (int)round(ex_cfg_num($m, $key, (float)$default));
}

function ex_cfg_str(array $m, string $key, string $default = ''): string {
    if (!array_key_exists($key, $m) || $m[$key] === null) return $default;
    $v = $m[$key];
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_int($v)) return (string)$v;
    if (is_float($v)) return (floor($v) == $v && abs($v) < 1e15) ? (string)(int)$v : (string)$v;
    if (is_string($v)) return $v;
    return $default;
}

/** Odpowiednik Json.strings — pojedynczy string traktowany jak lista jednoelementowa. */
function ex_cfg_strings(array $m, string $key): array {
    if (!array_key_exists($key, $m) || $m[$key] === null) return [];
    $v = $m[$key];
    if (is_string($v)) return [$v];
    if (is_array($v)) {
        $out = [];
        foreach ($v as $x) { if ($x !== null) $out[] = is_string($x) ? $x : (string)$x; }
        return $out;
    }
    return [];
}

function ex_cfg_has(array $m, string $key): bool {
    return array_key_exists($key, $m) && $m[$key] !== null;
}

/** Zwraca podtablicę pod kluczem albo []; nigdy null. */
function ex_cfg_map(array $m, string $key): array {
    $v = $m[$key] ?? null;
    return is_array($v) ? $v : [];
}

/** Zwraca listę pod kluczem albo []; toleruje asocjacyjne "listy" z dziurami (array_values). */
function ex_cfg_list(array $m, string $key): array {
    $v = $m[$key] ?? null;
    return is_array($v) ? array_values($v) : [];
}
