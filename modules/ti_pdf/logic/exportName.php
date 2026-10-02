<?php
/**
 * modules/ti_pdf/logic/exportName.php — jednolita nazwa pliku wydruków kursu TI:
 *   {Typ kursu}_{data wygenerowania}_{nazwa kursu}_{rodzaj dokumentu}.{rozszerzenie}
 * np. Hybryda_2026-10-02_26RDI-INF-NI2_Julia_Krynicka_robocze.pdf
 * Polskie znaki są zamieniane na ASCII (bezpieczne nazwy w nagłówkach HTTP i na każdym systemie).
 */

const TI_EXPORT_TYPE_LABELS = ['individual' => 'Indywidualny', 'group' => 'Grupowy', 'hybrid' => 'Hybryda'];

/** Tekst → bezpieczny fragment nazwy pliku (ASCII, litery/cyfry/myślnik, reszta → „_"). */
function ti_export_ascii(string $s, int $max = 70): string {
    $s = strtr($s, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                    'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']);
    $s = trim((string)preg_replace('/[^A-Za-z0-9-]+/', '_', $s), '_-');
    return mb_substr($s, 0, $max) ?: 'kurs';
}

/**
 * Nazwa pliku wydruku kursu. $kind — rodzaj dokumentu (np. 'plan_zajec', 'robocze'); $ext — rozszerzenie
 * bez kropki ('' = sama nazwa bez rozszerzenia). $date — domyślnie dziś (data wygenerowania, Y-m-d).
 */
function ti_export_filename(int $course_id, string $kind, string $ext = '', ?string $date = null): string {
    $c = $course_id > 0 ? db_one("SELECT name, class_type FROM k30_ti_courses WHERE id=?", [$course_id]) : null;
    $type = TI_EXPORT_TYPE_LABELS[(string)($c['class_type'] ?? '')] ?? 'Kurs';
    $base = $type . '_' . ($date ?: date('Y-m-d')) . '_' . ti_export_ascii((string)($c['name'] ?? 'kurs'))
          . ($kind !== '' ? '_' . ti_export_ascii($kind, 40) : '');
    return $ext === '' ? $base : $base . '.' . ltrim($ext, '.');
}
