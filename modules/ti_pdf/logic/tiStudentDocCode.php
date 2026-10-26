<?php
declare(strict_types=1);

/**
 * modules/ti_pdf/logic/tiStudentDocCode.php — kod Code128 w stopce KAŻDEGO dokumentu TI konkretnego kursanta (uczestnika).
 *
 * Format: KD{nr kursanta, 6 cyfr}/{TYP}/{nr dokumentu}, np. KD000123/DOP1/45, KD000123/RM/7, KD000123/KONS/12. Prefiks KD{nr} jest wspólny
 * dla wszystkich dokumentów kursanta; „Skanuj kod” w panelu dydaktyka (Licencje) rozpoznaje KD… i otwiera kartę uczestnika.
 * Alfabet Code128-B: wielkie litery, cyfry, „-” i „/”. Bez numeru kursanta (0) — brak kodu (druk bez zmian).
 * Użycie w generatorze mPDF:
 *   $kd = ti_student_doc_code($clientId, 'DOP1', $docId);
 *   new \Mpdf\Mpdf([... 'margin_bottom' => ti_student_doc_margin($kd) ...]);
 *   $mpdf->SetHTMLFooter(ti_student_doc_footer($istniejącaStopkaHtml, $kd));
 */

/** Symbol dokumentu / numer do kodu: wielkie litery i cyfry, reszta → „-”. */
function ti_student_doc_token(string $v): string
{
    return trim((string)preg_replace('/[^A-Z0-9]+/', '-', strtoupper(strtr($v, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                                                                                  'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']))), '-');
}

/** Kod dokumentu kursanta; '' gdy nie znamy numeru kursanta. $docId — id rekordu dokumentu (puste = tylko typ). */
function ti_student_doc_code(int $clientId, string $type, $docId = ''): string
{
    if ($clientId <= 0) return '';
    $code = 'KD' . str_pad((string)$clientId, 6, '0', STR_PAD_LEFT);
    $t = ti_student_doc_token($type);
    if ($t !== '') $code .= '/' . $t;
    $d = ti_student_doc_token((string)$docId);
    if ($d !== '') $code .= '/' . $d;
    return $code;
}

/** Rozpoznanie kodu: KD000123[/TYP[/ID]] → ['client_id' => 123, 'type' => 'TYP', 'doc' => 'ID'] albo null. */
function ti_student_doc_parse(string $code): ?array
{
    if (!preg_match('~^KD(\d{1,9})(?:/([A-Z0-9-]{1,30}))?(?:/([A-Z0-9-]{1,30}))?$~', strtoupper(trim($code)), $m)) return null;
    return ['client_id' => (int)$m[1], 'type' => $m[2] ?? '', 'doc' => $m[3] ?? ''];
}

/** Margines dolny strony z miejscem na wiersz z kodem (gdy kod jest). */
function ti_student_doc_margin(string $code, int $base = 13): int
{
    return $code !== '' ? $base + 8 : $base;
}

/** Stopka mPDF: istniejąca stopka + wiersz z Code128 i kodem tekstem (wyrównany do prawej). Bez kodu zwraca stopkę bez zmian. */
function ti_student_doc_footer(string $footerHtml, string $code): string
{
    if ($code === '') return $footerHtml;
    $c = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    return $footerHtml . '<div style="text-align:right;font-family:dejavusans;font-size:6pt"><barcode code="' . $c . '" type="C128B" size="0.75" height="0.55" /><br>' . $c . '</div>';
}
