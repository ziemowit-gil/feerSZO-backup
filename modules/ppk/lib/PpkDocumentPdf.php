<?php
/**
 * modules/ppk/lib/PpkDocumentPdf.php — druki PPKR (deklaracja rezygnacji z wpłat do PPK) i PPKP (wniosek o wpłaty / przystąpienie).
 *
 * Treść jest PROJEKTEM Fundacji — nie wzorem urzędowym (dla deklaracji pracownika wobec pracodawcy ustawa nie narzuca jednego wzoru, ale
 * brzmienie powinno zatwierdzić kadry/prawnik). Teksty leżą w stałych niżej: zmiana brzmienia = zmiana tu, bez ruszania logiki.
 * Dane: snapshot druku (employee_ppk_declarations.snapshot) + PESEL czytany z umowy w chwili wydruku (kolumna szyfrowana — nie kopiujemy go do snapshotu).
 * Dla druku złożonego elektronicznie stopka zamiast linii podpisu zawiera datę i potwierdzenie złożenia.
 */
require_once __DIR__ . '/PpkCalculator.php';

final class PpkDocumentPdf
{
    private const TITLES = [
        'PPKR' => ['Deklaracja rezygnacji z dokonywania wpłat do PPK', 'PPKR'],
        'PPKP' => ['Wniosek o dokonywanie wpłat do PPK (przystąpienie)', 'PPKP'],
    ];

    private const RESIGN_INTRO = 'składam deklarację o rezygnacji z dokonywania wpłat do pracowniczego planu kapitałowego (PPK) prowadzonego u pracodawcy. '
        . 'Rezygnacja obejmuje wpłaty finansowane przeze mnie oraz wpłaty finansowane przez pracodawcę.';
    private const RESIGN_NOTES = [
        'Rezygnacja nie wyłącza możliwości złożenia w każdym czasie wniosku o dokonywanie wpłat do PPK.',
        'Jeżeli nie złożę kolejnej deklaracji rezygnacji, pracodawca co 4 lata ponownie dokona zapisu do PPK i będzie dokonywał wpłat.',
    ];
    private const JOIN_INTRO = 'składam wniosek o dokonywanie wpłat do pracowniczego planu kapitałowego (PPK) prowadzonego u pracodawcy '
        . 'i deklaruję następujące wysokości wpłat, liczone od wynagrodzenia brutto stanowiącego podstawę wymiaru składek na ubezpieczenia emerytalne i rentowe:';
    private const JOIN_NOTES = [
        'Wpłata podstawowa finansowana przez pracodawcę wynosi 1,5% wynagrodzenia i nie podlega zmianie.',
        'Wpłaty finansowane przez pracodawcę stanowią przychód ze stosunku pracy podlegający opodatkowaniu podatkiem dochodowym od osób fizycznych; nie są podstawą wymiaru składek na ubezpieczenia społeczne.',
        'Mogę w każdej chwili złożyć deklarację rezygnacji z dokonywania wpłat do PPK.',
    ];

    private static function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    private static function dots(string $v, int $len = 40): string { return $v !== '' ? '<b>' . self::e($v) . '</b>' : str_repeat('.', $len); }
    private static function dateFmt(?string $d): string { return $d && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "$m[3].$m[2].$m[1]" : ''; }

    private static function mpdf(): \Mpdf\Mpdf
    {
        $auto = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (!is_file($auto)) throw new RuntimeException('Brak biblioteki PDF (vendor).');
        require_once $auto;
        $tmp = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : sys_get_temp_dir(), '/') . '/mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        return new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_top' => 22, 'margin_right' => 22, 'margin_bottom' => 22, 'margin_left' => 22,
                               'default_font' => 'dejavusanscondensed', 'tempDir' => $tmp]);
    }

    /**
     * @param array $decl wiersz employee_ppk_declarations
     * @param array $contract wiersz umowy_praca (PESEL czytany stąd)
     * @return string bajty PDF
     */
    public static function render(array $decl, array $contract): string
    {
        $kind = (string)$decl['kind'];
        if (!isset(self::TITLES[$kind])) throw new InvalidArgumentException('Nieznany rodzaj druku.');
        [$title, $symbol] = self::TITLES[$kind];
        $s = json_decode((string)$decl['snapshot'], true) ?: [];
        $join = $kind === 'PPKP';
        $rates = PpkRates::fromArray($s['rates'] ?? []);

        $ratesHtml = '';
        if ($join) {
            $row = static fn(string $l, int $bp) => '<tr><td style="padding:1.2mm 4mm 1.2mm 0">' . self::e($l) . '</td><td style="padding:1.2mm 0"><b>' . self::e(PpkRates::bpToPercent($bp)) . '%</b></td></tr>';
            $ratesHtml = '<table style="margin:3mm 0 3mm 6mm;font-size:10pt">'
                . $row('Wpłata podstawowa finansowana przez pracownika', $rates->employeeBaseBp)
                . $row('Wpłata dodatkowa finansowana przez pracownika', $rates->employeeAddBp)
                . $row('Wpłata podstawowa finansowana przez pracodawcę', $rates->employerBaseBp)
                . $row('Wpłata dodatkowa finansowana przez pracodawcę', $rates->employerAddBp)
                . '</table>';
        }
        $notes = '';
        foreach ($join ? self::JOIN_NOTES : self::RESIGN_NOTES as $i => $n) $notes .= '<p style="margin:0 0 2mm 6mm">' . ($i + 1) . ') ' . self::e($n) . '</p>';

        $electronic = ($decl['form'] ?? '') === 'elektroniczna';
        if ($electronic) {
            $signHtml = '<div style="border:0.3mm solid #444;padding:3mm;font-size:9.5pt;margin-top:10mm"><b>Oświadczenie złożone w formie elektronicznej</b><br>'
                . 'Data złożenia: <b>' . self::e(self::dateFmt($decl['declared_at'] ?? '')) . '</b><br>'
                . 'Potwierdzenie: ' . self::e($decl['proof'] ?? '') . '</div>';
        } else {
            $signHtml = '<table style="width:100%;margin-top:18mm;font-size:9pt;text-align:center"><tr>'
                . '<td style="width:45%;border-top:0.3mm solid #000;padding-top:1mm">(data i podpis pracownika)</td><td style="width:10%"></td>'
                . '<td style="width:45%;border-top:0.3mm solid #000;padding-top:1mm">(data przyjęcia i podpis pracodawcy)</td></tr></table>';
        }

        $html = '<div style="font-size:10.5pt;line-height:1.45">'
            . '<table style="width:100%"><tr><td style="font-size:20pt;font-weight:bold;color:#0f6fa8">' . self::e($symbol) . '</td>'
            . '<td style="text-align:right;font-size:9pt;color:#444">Nr ' . self::e($decl['number']) . '</td></tr></table>'
            . '<div style="border-top:0.5mm solid #0f6fa8;margin:1mm 0 6mm"></div>'
            . '<p style="margin:0 0 1mm">Pracodawca: ' . self::dots((string)($s['employer_name'] ?? ''), 50) . '</p>'
            . '<h1 style="font-size:14pt;text-align:center;margin:8mm 0 7mm">' . self::e(mb_strtoupper($title)) . '</h1>'
            . '<p>Imię i nazwisko: ' . self::dots((string)($s['employee_name'] ?? '')) . '<br>'
            . 'PESEL: ' . self::dots((string)($contract['pesel'] ?? ''), 20) . '<br>'
            . 'Adres: ' . self::dots((string)($s['address'] ?? '')) . '<br>'
            . 'Stanowisko: ' . self::dots((string)($s['position'] ?? '')) . '<br>'
            . 'Umowa o pracę nr: ' . self::dots((string)($s['contract_number'] ?? ''), 20) . '</p>'
            . '<p style="margin-top:6mm">Ja, niżej podpisany(a), ' . self::e($join ? self::JOIN_INTRO : self::RESIGN_INTRO) . '</p>'
            . $ratesHtml
            . '<p style="margin:' . ($join ? '2mm' : '4mm') . ' 0 2mm"><b>Przyjmuję do wiadomości, że:</b></p>' . $notes
            . ($join ? '' : '<p style="margin-top:4mm">Rezygnacja obowiązuje od dnia: <b>' . (($decl['effective_from'] ?? '') !== '' ? self::e(self::dateFmt($decl['effective_from'])) : str_repeat('.', 20)) . '</b></p>')
            . ($join && ($decl['effective_from'] ?? '') !== '' ? '<p style="margin-top:4mm">Wpłaty obowiązują od dnia: <b>' . self::e(self::dateFmt($decl['effective_from'])) . '</b></p>' : '')
            . $signHtml
            . '</div>';

        $m = self::mpdf();
        $m->SetTitle($title . ' ' . $decl['number']);
        $m->WriteHTML($html);
        return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }
}
