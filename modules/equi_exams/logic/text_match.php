<?php
/**
 * modules/equi_exams/logic/text_match.php — normalizacja i dopasowanie tekstu.
 *
 * Port z exam-engine (Java): model/Text.java. Współdzielone przez pytania
 * fill_blank, short_answer i porównywanie wyjścia programu (code_run i pochodne
 * — patrz code_grading.php).
 *
 * Ustawienia normalizacji czytane z configu pytania (semantyka jak w Javie):
 *   caseSensitive  (domyślnie false), trim (domyślnie true),
 *   collapseSpaces (domyślnie true), ignoreAccents (domyślnie false),
 *   ignorePunct    (domyślnie false), regex (domyślnie false).
 */

require_once __DIR__ . '/json_helpers.php';

function ex_text_strip_accents(string $s): string {
    static $map = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ];
    return strtr($s, $map);
}

function ex_text_normalize(?string $raw, array $cfg): string {
    $s = $raw ?? '';
    $s = str_replace(["\r\n", "\r"], ["\n", "\n"], $s);
    if (ex_cfg_bool($cfg, 'trim', true)) $s = trim($s);
    if (ex_cfg_bool($cfg, 'collapseSpaces', true)) $s = preg_replace('/[ \t]+/', ' ', $s);
    if (!ex_cfg_bool($cfg, 'caseSensitive', false)) $s = mb_strtolower($s, 'UTF-8');
    if (ex_cfg_bool($cfg, 'ignoreAccents', false)) $s = ex_text_strip_accents($s);
    // \p{Punct} w Javie to klasa POSIX (ASCII) — [[:punct:]] w PCRE to dokładnie to samo.
    if (ex_cfg_bool($cfg, 'ignorePunct', false)) $s = preg_replace('/[[:punct:]]/', '', $s);
    return $s;
}

/** Czy odpowiedź kursanta pasuje do któregokolwiek z wzorców akceptowanych. */
function ex_text_matches_any(?string $given, array $accept, array $cfg): bool {
    if (empty($accept)) return false;
    $regex = ex_cfg_bool($cfg, 'regex', false);
    $g = ex_text_normalize($given, $cfg);
    foreach ($accept as $pattern) {
        if ($pattern === null) continue;
        $pattern = (string)$pattern;
        if ($regex) {
            $caseSensitive = ex_cfg_bool($cfg, 'caseSensitive', false);
            $re = '~^(?:' . $pattern . ')$~uD' . ($caseSensitive ? '' : 'i');
            $r = @preg_match($re, $given === null ? '' : trim($given));
            if ($r === 1) return true;
            if ($r === false) {
                // Wzorzec prowadzącego nieprawidłowy — nie może wywrócić oceniania, traktujemy jak tekst.
                if (ex_text_normalize($pattern, $cfg) === $g) return true;
            }
        } elseif (ex_text_normalize($pattern, $cfg) === $g) {
            return true;
        }
    }
    return false;
}

/** Czy tekst zawiera którekolwiek ze słów kluczowych (dopasowanie po normalizacji). */
function ex_text_contains_any(?string $haystack, array $needles, array $cfg): bool {
    if (empty($needles)) return false;
    $h = ex_text_normalize($haystack, $cfg);
    $regex = ex_cfg_bool($cfg, 'regex', false);
    $caseSensitive = ex_cfg_bool($cfg, 'caseSensitive', false);
    foreach ($needles as $n) {
        if ($n === null) continue;
        $n = (string)$n;
        if (trim($n) === '') continue;
        if ($regex) {
            $re = '~' . $n . '~u' . ($caseSensitive ? '' : 'i');
            $r = @preg_match($re, $haystack ?? '');
            if ($r === 1) return true;
            if ($r !== false) continue; // poprawny wzorzec, brak dopasowania — pomiń dosłowne porównanie
            // $r === false → wzorzec nieprawidłowy, spadamy do dopasowania dosłownego niżej
        }
        if (str_contains($h, ex_text_normalize($n, $cfg))) return true;
    }
    return false;
}

// ── Porównywanie wyjścia programu ────────────────────────────────────────────

/** Zwraca true, gdy wyjście programu odpowiada oczekiwanemu wg trybu dopasowania. $tc: [expected, matchMode, tolerance]. */
function ex_text_output_matches(?string $actual, array $tc): bool {
    $a = str_replace(["\r\n", "\r"], ["\n", "\n"], $actual ?? '');
    $e = str_replace(["\r\n", "\r"], ["\n", "\n"], (string)($tc['expected'] ?? ''));
    $mode = (string)($tc['matchMode'] ?? 'trim');

    if ($mode === 'exact') return $a === $e;

    if ($mode === 'regex') {
        $r = @preg_match('~^(?:' . $e . ')$~usD', $a);
        return $r === 1;
    }

    if ($mode === 'tokens' || $mode === 'numeric') {
        $at = preg_split('/\s+/', trim($a));
        $et = preg_split('/\s+/', trim($e));
        if ($at === ['']) $at = [];
        if ($et === ['']) $et = [];
        if (count($at) !== count($et)) return false;
        $tolerance = (float)($tc['tolerance'] ?? 0.000001);
        foreach ($at as $i => $tokA) {
            $tokE = $et[$i];
            if ($tokA === $tokE) continue;
            if ($mode !== 'numeric') return false;
            if (!is_numeric($tokA) || !is_numeric($tokE)) return false;
            $da = (float)$tokA; $de = (float)$tokE;
            $diff = abs($da - $de);
            if ($diff > $tolerance && $diff > abs($de) * $tolerance) return false;
        }
        return true;
    }

    // "trim" (domyślny): pomija białe znaki na końcach linii i puste linie końcowe
    return ex_text_trim_lines($a) === ex_text_trim_lines($e);
}

function ex_text_trim_lines(string $s): string {
    $lines = explode("\n", $s);
    $last = count($lines) - 1;
    while ($last >= 0 && trim($lines[$last]) === '') $last--;
    $out = [];
    for ($i = 0; $i <= $last; $i++) $out[] = rtrim($lines[$i]);
    return implode("\n", $out);
}

/** Skraca tekst do limitu znaków — chroni odpowiedź przed zalaniem (Text.cap, inne komunikaty niż Sandbox.cap). */
function ex_text_cap(?string $s, int $max): string {
    $s = $s ?? '';
    if (mb_strlen($s, 'UTF-8') <= $max) return $s;
    return mb_substr($s, 0, $max, 'UTF-8') . "\n… (obcięto, wyjście dłuższe niż {$max} znaków)";
}
