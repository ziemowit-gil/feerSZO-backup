<?php
/**
 * modules/ai_anonymizer/lib/aiAnonymizer.php — anonimizacja (pseudonimizacja odwracalna) tekstu przed wysyłką do dostawcy AI.
 *
 * Zasada: do zewnętrznego modelu (Anthropic) nie wysyłamy danych osobowych. Przed wysyłką wartości identyfikujące osoby
 * zamieniamy na znaczniki ([E-MAIL-1], [PESEL-1], [OSOBA-2]…), a w odpowiedzi widocznej dla użytkownika podstawiamy z powrotem
 * (mapa istnieje tylko w pamięci serwera przez czas jednego żądania — nigdzie nie jest zapisywana ani wysyłana).
 *
 * Wykrywane: e-mail, PESEL (z sumą kontrolną), NIP (z sumą kontrolną albo z etykietą/kreskami), numer rachunku (NRB/IBAN PL),
 * telefon (z +48, z separatorami albo z etykietą), numer dowodu/paszportu, oraz imiona i nazwiska ze słownika (konta, osoby, kontakty CRM).
 * OGRANICZENIA (uczciwie): imię i nazwisko spoza słownika oraz w innej odmianie (np. „Kowalskiego”) nie zostaną rozpoznane;
 * adres zamieszkania i data urodzenia podane swobodnym tekstem nie są wykrywane. Dlatego narzędzie „moje_dane” ma własne,
 * ograniczone zestawy pól, a przez zasadę „co do zasady bez danych osobowych” asystent nie powinien dostawać dokumentów z danymi osób.
 */
final class AiAnonymizer
{
    /** token => oryginał */
    private array $map = [];
    /** "KLASA|znormalizowany oryginał" => token */
    private array $rev = [];
    private array $n = [];
    private ?string $nameRegex = null;

    /** @param string[] $names pełne imiona i nazwiska do pseudonimizacji (słownik) */
    public function __construct(array $names = [])
    {
        $uniq = [];
        foreach ($names as $nm) {
            $nm = trim(preg_replace('/\s+/u', ' ', (string)$nm));
            if (mb_strlen($nm) >= 5 && str_contains($nm, ' ')) $uniq[mb_strtolower($nm)] = $nm;
        }
        if ($uniq) {
            $list = array_values($uniq);
            usort($list, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));            // dłuższe najpierw
            $this->nameRegex = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(fn($x) => preg_quote($x, '/'), $list)) . ')(?![\p{L}\p{N}])/iu';
        }
    }

    private function nextToken(string $class, string $original, string $norm): string
    {
        $key = $class . '|' . $norm;
        if (isset($this->rev[$key])) return $this->rev[$key];
        $this->n[$class] = ($this->n[$class] ?? 0) + 1;
        $t = '[' . $class . '-' . $this->n[$class] . ']';
        $this->map[$t] = $original;
        return $this->rev[$key] = $t;
    }

    /** Zamienia dane osobowe na znaczniki (odwracalnie: restore()). */
    public function anonymize(string $text): string
    {
        if ($text === '') return $text;
        $t = $text;

        // 1. e-mail
        $t = preg_replace_callback('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)*/u',
            fn($m) => $this->nextToken('E-MAIL', $m[0], mb_strtolower($m[0])), $t);
        // 2. numer rachunku (NRB 26 cyfr, opcjonalnie PL)
        $t = preg_replace_callback('/(?<![\p{L}\d])(?:PL\s?)?\d{2}(?:\s?\d{4}){6}(?!\d)/u',
            fn($m) => $this->nextToken('NR-KONTA', $m[0], preg_replace('/\s/', '', $m[0])), $t);
        // 3. dokument tożsamości (dowód: 3 litery + 6 cyfr; paszport: 2 litery + 7 cyfr)
        $t = preg_replace_callback('/(?<![\p{L}\d])(?:[A-Z]{3}\s?\d{6}|[A-Z]{2}\s?\d{7})(?![\p{L}\d])/u',
            fn($m) => $this->nextToken('DOKUMENT', $m[0], preg_replace('/\s/', '', $m[0])), $t);
        // 4. PESEL: 11 cyfr z poprawną sumą kontrolną albo po etykiecie „PESEL”
        $t = preg_replace_callback('/(?<![\d-])(?:(PESEL\s*[:.\-]?\s*)([\d][\d \-]{9,14}\d)|(\d{11}))(?![\d-])/iu', function ($m) {
            $label = $m[1] ?? ''; $num = ($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '');
            $d = preg_replace('/\D/', '', $num);
            if ($label === '' && !self::peselOk($d)) return $m[0];
            if (strlen($d) !== 11) return $m[0];
            return $label . $this->nextToken('PESEL', $num, $d);
        }, $t);
        // 5. NIP: z etykietą, z kreskami albo 10 cyfr z poprawną sumą kontrolną
        $t = preg_replace_callback('/(?<![\d-])(?:(NIP\s*[:.\-]?\s*(?:PL)?\s*)(\d[\d \-]{8,12}\d)|(\d{3}-\d{3}-\d{2}-\d{2}|\d{3}-\d{2}-\d{2}-\d{3})|(\d{10}))(?![\d-])/iu', function ($m) {
            $label = $m[1] ?? ''; $num = ($m[2] ?? '') !== '' ? $m[2] : (($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''));
            $d = preg_replace('/\D/', '', $num);
            if (strlen($d) !== 10) return $m[0];
            if ($label === '' && ($m[3] ?? '') === '' && !self::nipOk($d)) return $m[0];
            return $label . $this->nextToken('NIP', $num, $d);
        }, $t);
        // 6. telefon: +48 / 0048, grupy 3-3-3 / 3-2-2-2 z separatorami albo po etykiecie „tel.”
        $t = preg_replace_callback('/(?<![\d\w])(?:(?:\+|00)48[ \-]?\d{3}[ \-]?\d{3}[ \-]?\d{3}|\d{3}[ \-]\d{3}[ \-]\d{3}|\d{3}[ \-]\d{2}[ \-]\d{2}[ \-]\d{2}|(?<=tel\.\s|tel:\s|telefon:\s|tel\.:\s)\d{9})(?![\d\w])/iu',
            fn($m) => $this->nextToken('TELEFON', $m[0], preg_replace('/\D/', '', $m[0]) ), $t);
        // 7. imiona i nazwiska ze słownika
        if ($this->nameRegex) {
            $t = preg_replace_callback($this->nameRegex, fn($m) => $this->nextToken('OSOBA', $m[1], mb_strtolower($m[1])), $t);
        }
        return $t;
    }

    /** Podstawia dane z powrotem w odpowiedzi dla użytkownika. */
    public function restore(string $text): string
    {
        return $this->map ? strtr($text, $this->map) : $text;
    }

    /** restore() rekurencyjnie po stringach (np. wejście narzędzia zwrócone przez model). */
    public function restoreDeep(mixed $v): mixed
    {
        if (is_string($v)) return $this->restore($v);
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = $this->restoreDeep($x); }
        return $v;
    }

    public function tokenCount(): int { return count($this->map); }

    /** Liczniki znaczników wg klasy (do statystyk/audytu — bez wartości). */
    public function stats(): array { return $this->n; }

    /** Nieodwracalne „zamiecenie” (ostatnia linia obrony przed wysyłką): wzorce bez słownika, bez mapy. */
    public static function scrub(string $text): string
    {
        return (new self())->anonymize($text);
    }

    public static function peselOk(string $p): bool
    {
        if (!preg_match('/^\d{11}$/', $p)) return false;
        $w = [1, 3, 7, 9, 1, 3, 7, 9, 1, 3];
        $s = 0;
        for ($i = 0; $i < 10; $i++) $s += $w[$i] * (int)$p[$i];
        return (10 - $s % 10) % 10 === (int)$p[10];
    }

    public static function nipOk(string $n): bool
    {
        if (!preg_match('/^\d{10}$/', $n)) return false;
        $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $s = 0;
        for ($i = 0; $i < 9; $i++) $s += $w[$i] * (int)$n[$i];
        return $s % 11 === (int)$n[9];
    }
}
