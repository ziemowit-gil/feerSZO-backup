<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/DeclarationDocument.php — wydruk oświadczenia OKK-1: PDF (mPDF) w układzie urzędowego formularza
 * (wzór: deklaracja CIT-8, jak wniosek WK-1 kart NFC) oraz DOCX (PHPWord) do podpisu odręcznego.
 *
 * Układ PDF: symbol formularza i wersja w nagłówku, sekcje A–G z szarymi belkami, numerowane pola w ramkach z etykietą
 * w lewym górnym rogu, kratki ☒/☐, pola jasne (składający) i ciemne (wypełnia Fundacja — weryfikacja), miejsce na podpis,
 * stopka z symbolem, numerem oświadczenia/wersją i stroną. Pola puste drukują się z podpowiedzią wzoru (do wypełnienia ręcznie).
 */
require_once __DIR__ . '/DeclarationForm.php';
require_once __DIR__ . '/DeclarationStatus.php';

final class DeclarationDocument
{
    public static function number(array $decl): string
    {
        return 'OKK/' . (int)$decl['series_id'] . '/' . substr((string)($decl['created_at'] ?: date('Y')), 0, 4) . '/v' . (int)$decl['version'];
    }

    public static function html(array $decl): string
    {
        $d = $decl['data'];
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $box = fn(bool $on) => '<span class="bx">' . ($on ? '&#9746;' : '&#9744;') . '</span>';
        $val = function (string $k) use ($d, $e): string {
            $v = trim((string)($d[$k] ?? ''));
            if ($v === '') return '<span class="ph">' . $e(DeclarationForm::FIELDS[$k][1]) . '</span><div class="dots">&nbsp;</div>';
            return nl2br($e($v));
        };
        $n = 0;
        $fld = function (string $label, string $valueHtml, string $extra = '', bool $dark = false) use (&$n): string {
            $n++;
            if ($dark) $extra = str_contains($extra, 'style="') ? str_replace('style="', 'style="background:#e6e6e6;', $extra) : $extra . ' style="background:#e6e6e6"';
            return '<td class="f"' . $extra . '><div class="lb"><b>' . $n . '.</b> ' . $label . '</div><div class="v">' . ($valueHtml !== '' ? $valueHtml : '&nbsp;') . '</div></td>';
        };
        $sec = fn(string $l, string $t, string $hint = '') => '<table class="t"><tr><td class="sec"><b>' . $l . '. ' . $t . '</b>' . ($hint !== '' ? ' <span class="hint">' . $hint . '</span>' : '') . '</td></tr></table>';
        $secRow = fn(string $l, string $t, int $cols, string $hint = '') => '<tr><td class="sec" colspan="' . $cols . '"><b>' . $l . '. ' . $t . '</b>' . ($hint !== '' ? ' <span class="hint">' . $hint . '</span>' : '') . '</td></tr>';

        $status = $decl['statusEnum'] ?? DeclarationStatus::tryFrom((string)$decl['status']) ?? DeclarationStatus::Draft;
        $course = !empty($decl['course_id']) ? MsuesSzoData::course((int)$decl['course_id']) : null;
        $org = trim((string)$d['podmiot']) !== '' ? (string)$d['podmiot'] : MsuesSzoData::orgName();

        // ── Nagłówek ──
        $h = '<table class="t"><tr>'
           . $fld('Numer oświadczenia', '<span class="big">' . $e(self::number($decl)) . '</span>', ' width="36%"')
           . $fld('Usługa / grupa', $course ? $e(MsuesSzoData::courseLabel($course)) : 'wszystkie usługi (oświadczenie ogólne)', ' width="30%"')
           . $fld('Projekt', $e($decl['project'] ?? ''), ' width="14%"')
           . '<td class="sym" width="20%"><div class="symb">' . DeclarationForm::SYMBOL . '</div><div class="symv">wersja wzoru ' . DeclarationForm::VERSION . '</div></td>'
           . '</tr></table>'
           . '<table class="t"><tr><td class="ttl"><div class="ttl1">OŚWIADCZENIE O KWALIFIKACJACH I KOMPETENCJACH</div>'
           . '<div class="ttl1">DO PROWADZENIA SZKOLEŃ LUB KONSULTACJI</div>'
           . '<div class="ttl2">dokumentacja kadry — Małopolskie Standardy Usług Edukacyjno-Szkoleniowych (MSUES 2.0)</div></td></tr>'
           . '<tr><td class="lgd"><b>Składający:</b> osoba prowadząca szkolenia lub konsultacje na rzecz ' . $e($org) . '.&nbsp; '
           . '<b>Wypełnia:</b> pola jasne — składający (sekcje A–F), pola ciemne — Fundacja (sekcja G).&nbsp; '
           . '<b>Miejsce składania:</b> ' . $e((string)org_setting('org_adres') ?: $org) . '.</td></tr></table>';

        // ── A. Miejsce i data ──
        $h .= $sec('A', 'MIEJSCE I DATA ZŁOŻENIA OŚWIADCZENIA')
            . '<table class="t"><tr>'
            . $fld('Miejscowość', $val('miejscowosc'), ' width="60%"')
            . $fld('Data (dzień — miesiąc — rok)', trim((string)$d['data']) !== '' ? $e(DeclarationForm::dateLong((string)$d['data'])) : '<span class="ph">data</span><div class="dots">&nbsp;</div>', ' width="40%"')
            . '</tr></table>';

        // ── B. Dane składającego ──
        $h .= $sec('B', 'DANE SKŁADAJĄCEGO OŚWIADCZENIE', '(Ja, niżej podpisana/podpisany)')
            . '<table class="t"><tr>' . $fld('Imię i nazwisko', $val('imie_nazwisko'), ' width="40%"')
            . $fld('Adres lub dane identyfikacyjne, jeśli wymagane', $val('dane_identyfikacyjne'), ' width="60%"') . '</tr></table>';

        // ── C. Podstawa współpracy ──
        $p = (string)$d['podstawa'];
        $h .= $sec('C', 'PODSTAWA WSPÓŁPRACY', '(prowadząca/prowadzący działania jako — zaznaczyć właściwy kwadrat)')
            . '<table class="t"><tr>' . $fld('Prowadzę działania jako',
                $box($p === 'wolontariat') . ' 1. wolontariuszka/wolontariusz &nbsp;&nbsp; ' . $box($p === 'zlecenie') . ' 2. zleceniobiorczyni/zleceniobiorca &nbsp;&nbsp; '
                . $box($p === 'inna') . ' 3. inna podstawa współpracy', ' colspan="2"')
            . '</tr><tr>' . $fld('Inna podstawa współpracy (wypełnić przy kwadracie 3)', $p === 'inna' ? $val('podstawa_inna') : '', ' width="40%"')
            . $fld('Na rzecz (podmiot zlecający)', $e($org), ' width="60%"') . '</tr></table>';

        // ── D. Oświadczenia ──
        $h .= $sec('D', 'OŚWIADCZENIA', '(oświadczam, że:)');
        $rows = '';
        foreach (DeclarationForm::points() as $i => [$text, $fields]) {
            $pn = $i + 1;
            $inner = '<div class="pt"><b>' . $pn . '.</b> ' . $e($text) . '</div>';
            if ($fields) {
                $sub = '';
                foreach ($fields as $k) {
                    $n++;
                    $lab = DeclarationForm::SUBLABELS[$k] ?? '';
                    $sub .= '<tr><td class="f in"><div class="lb"><b>' . $n . '.</b> ' . $e($lab !== '' ? $lab : DeclarationForm::FIELDS[$k][1]) . '</div><div class="v">' . $val($k) . '</div></td></tr>';
                }
                $inner .= '<table class="t sub">' . $sub . '</table>';
            }
            $rows .= '<tr><td class="pc">' . $inner . '</td></tr>';
        }
        $h .= '<table class="t">' . $rows . '</table>';

        // ── E. Oświadczenia dodatkowe (opcjonalne) ──
        if (!empty($d['zgoda_materialy']) || !empty($d['zgoda_rodo'])) {
            $h .= '<table class="t" style="page-break-inside:avoid">' . $secRow('E', 'OŚWIADCZENIA DODATKOWE', 1, '(zaznaczone przez składającego)');
            foreach (['zgoda_materialy', 'zgoda_rodo'] as $k) {
                if (!empty($d[$k])) $h .= '<tr>' . $fld('Oświadczenie', $box(true) . ' <span class="st">' . $e(DeclarationForm::FIELDS[$k][0]) . '.</span>') . '</tr>';
            }
            $h .= '</table>';
        }

        // ── F. Podpis ──
        $h .= '<table class="t" style="page-break-inside:avoid">' . $secRow('F', 'POUCZENIE I PODPIS SKŁADAJĄCEGO', 2)
            . '<tr><td class="f" colspan="2"><div class="st2">' . $e(DeclarationForm::CLOSING) . '</div></td></tr><tr>'
            . $fld('Miejscowość i data', $e(trim((string)$d['miejscowosc']) . (trim((string)$d['data']) !== '' ? ', ' . DeclarationForm::dateLong((string)$d['data']) : '')), ' width="35%" style="height:16mm"')
            . $fld('Czytelny podpis', '', ' width="65%"')
            . '</tr></table>';

        // ── G. Weryfikacja (pola ciemne) ──
        $decided = in_array($status, [DeclarationStatus::Approved, DeclarationStatus::Rejected, DeclarationStatus::Superseded], true);
        $znak = '';
        if (!empty($decl['ezd_sprawa_id'])) { try { $znak = (string)(db_one("SELECT znak_sprawy FROM ezd_sprawy WHERE id = ?", [(int)$decl['ezd_sprawa_id']])['znak_sprawy'] ?? ''); } catch (\Throwable $e2) {} }
        $h .= '<table class="t" style="page-break-inside:avoid">' . $secRow('G', 'WERYFIKACJA', 3, '(wypełnia Fundacja)') . '<tr>'
            . $fld('Rozstrzygnięcie', $box($status === DeclarationStatus::Approved || $status === DeclarationStatus::Superseded) . ' 1. zatwierdzono pod audyt MSUES &nbsp;&nbsp; '
                . $box($status === DeclarationStatus::Rejected) . ' 2. odrzucono — do uzupełnienia', ' colspan="3"', true)
            . '</tr><tr>'
            . $fld('Zweryfikował(a)', $decided ? $e($decl['decided_name']) : '', ' width="40%"', true)
            . $fld('Data weryfikacji', $decided ? $e(DeclarationForm::dateLong(substr((string)$decl['decided_at'], 0, 10))) : '', ' width="25%"', true)
            . $fld('Znak sprawy EZD', $e($znak), ' width="35%"', true)
            . '</tr><tr>'
            . $fld('Uwagi', $e($decl['decision_note'] ?? ''), ' colspan="2" style="height:12mm"', true)
            . $fld('Podpis weryfikującego', '', '', true)
            . '</tr></table>';

        return '<style>
          body{font-family:dejavusans;font-size:8.6pt;color:#000}
          table.t{width:100%;border-collapse:collapse;margin:0}
          table.t td{border:0.25mm solid #000;vertical-align:top}
          td.f{padding:0.6mm 1.4mm 0.9mm;background:#fff}
          td.pc{padding:1mm 1.6mm 1.2mm;background:#fff}
          table.sub{margin-top:1mm} table.sub td.in{border:0.2mm solid #000}
          .pt{font-size:8.4pt;text-align:justify;line-height:1.32}
          .lb{font-size:6.4pt;color:#222;margin-bottom:0.4mm}
          .v{font-size:9pt;font-weight:bold}
          .ph{font-weight:normal;font-size:7pt;color:#666;font-style:italic}
          .dots{border-bottom:0.2mm dotted #000;height:4mm}
          .big{font-size:11pt;letter-spacing:0.2mm}
          td.sec{background:#bfbfbf;padding:1mm 1.6mm;font-size:8.6pt;letter-spacing:0.15mm}
          .hint{font-weight:normal;font-size:7pt}
          td.sym{background:#bfbfbf;text-align:center;vertical-align:middle}
          .symb{font-size:20pt;font-weight:bold;letter-spacing:0.5mm}
          .symv{font-size:6.5pt}
          td.ttl{text-align:center;padding:1.6mm 2mm;background:#fff}
          .ttl1{font-size:12pt;font-weight:bold;letter-spacing:0.2mm}
          .ttl2{font-size:7.6pt;margin-top:0.8mm}
          td.lgd{font-size:6.8pt;padding:1mm 1.6mm;background:#f2f2f2}
          .bx{font-size:10pt;font-family:dejavusans}
          .st{font-size:7.4pt;font-weight:normal}
          .st2{font-size:8pt;text-align:justify}
        </style>' . $h;
    }

    public static function pdf(array $decl): string
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 8, 'margin_bottom' => 13,
                                'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp, 'shrink_tables_to_fit' => 1]);
        $mpdf->SetTitle(DeclarationForm::title() . ' — ' . ($decl['data']['imie_nazwisko'] ?? ''));
        $mpdf->SetAuthor(MsuesSzoData::orgName());
        $mpdf->SetHTMLFooter('<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr>'
            . '<td width="33%"><b>' . DeclarationForm::SYMBOL . '</b> (' . DeclarationForm::VERSION . ')</td>'
            . '<td width="34%" style="text-align:center">' . htmlspecialchars(self::number($decl)) . '</td>'
            . '<td width="33%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>');
        $mpdf->WriteHTML(self::html($decl));
        return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** DOCX z tą samą treścią (do edycji/podpisu odręcznego). Bez addHtml — PHPWord psuje <br> (patrz feedback PHPWord). */
    public static function docx(array $decl): string
    {
        require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
        $er = error_reporting(error_reporting() & ~E_DEPRECATED);
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
        $d = $decl['data'];
        $J = \PhpOffice\PhpWord\SimpleType\Jc::class;
        $w = new \PhpOffice\PhpWord\PhpWord();
        $w->getSettings()->setThemeFontLang(new \PhpOffice\PhpWord\Style\Language('pl-PL'));
        $w->setDefaultFontName('Arial');
        $w->setDefaultFontSize(10);
        $just = ['alignment' => $J::BOTH, 'spaceAfter' => 100, 'lineHeight' => 1.2];
        $num = $just + ['indentation' => ['left' => 400, 'hanging' => 400], 'tabs' => [new \PhpOffice\PhpWord\Style\Tab('left', 400)]];
        $ctr = ['alignment' => $J::CENTER, 'spaceAfter' => 60];
        $fill = function ($s, string $k) use ($d) {
            $v = trim((string)($d[$k] ?? ''));
            $lines = $v !== '' ? explode("\n", $v) : ['…………………………………… (' . DeclarationForm::FIELDS[$k][1] . ')'];
            foreach ($lines as $line) $s->addText($line, $v !== '' ? ['bold' => true] : ['color' => '666666', 'italic' => true], ['indentation' => ['left' => 400], 'spaceAfter' => 40]);
        };
        $s = $w->addSection(['marginLeft' => 1250, 'marginRight' => 1250, 'marginTop' => 1000, 'marginBottom' => 1000]);
        $s->addFooter()->addPreserveText(DeclarationForm::SYMBOL . ' · ' . self::number($decl) . ' · {PAGE} / {NUMPAGES}', ['size' => 8], ['alignment' => $J::CENTER]);
        $s->addText('OŚWIADCZENIE O KWALIFIKACJACH I KOMPETENCJACH', ['bold' => true, 'size' => 12], $ctr);
        $s->addText('DO PROWADZENIA SZKOLEŃ LUB KONSULTACJI', ['bold' => true, 'size' => 12], $ctr);
        $s->addTextBreak(1);
        $place = trim((string)$d['miejscowosc']) ?: '……………………';
        $date = trim((string)$d['data']) !== '' ? DeclarationForm::dateLong((string)$d['data']) : '……………';
        $s->addText('Miejscowość: ' . $place . ', dnia ' . $date, [], ['alignment' => $J::RIGHT, 'spaceAfter' => 200]);
        $s->addText('Ja, niżej podpisana/podpisany:', [], $just);
        $fill($s, 'imie_nazwisko');
        $fill($s, 'dane_identyfikacyjne');
        $s->addText('prowadząca/prowadzący działania jako:', [], $just);
        $p = (string)$d['podstawa'];
        $s->addText(($p === 'wolontariat' ? '☒' : '☐') . ' wolontariuszka/wolontariusz   ' . ($p === 'zlecenie' ? '☒' : '☐') . ' zleceniobiorczyni/zleceniobiorca   '
            . ($p === 'inna' ? '☒' : '☐') . ' inna podstawa współpracy: ' . ($p === 'inna' && trim((string)$d['podstawa_inna']) !== '' ? $d['podstawa_inna'] : '……………………'), [], $just);
        $org = trim((string)$d['podmiot']) !== '' ? (string)$d['podmiot'] : MsuesSzoData::orgName();
        $s->addText('na rzecz: ' . $org . ', oświadczam, że:', [], $just);
        foreach (DeclarationForm::points() as $i => [$text, $fields]) {
            $s->addText(($i + 1) . ".\t" . $text, [], $num);
            foreach ($fields as $k) {
                if (isset(DeclarationForm::SUBLABELS[$k])) $s->addText(DeclarationForm::SUBLABELS[$k] . ':', ['italic' => true], ['indentation' => ['left' => 400], 'spaceAfter' => 20]);
                $fill($s, $k);
            }
        }
        foreach (['zgoda_materialy', 'zgoda_rodo'] as $k) {
            if (!empty($d[$k])) $s->addText("☒\t" . DeclarationForm::FIELDS[$k][0] . '.', [], $num);
        }
        $s->addTextBreak(1);
        $s->addText(DeclarationForm::CLOSING, [], $just);
        $s->addTextBreak(2);
        $s->addText('........................................................', [], ['alignment' => $J::RIGHT, 'spaceAfter' => 0]);
        $s->addText('czytelny podpis', ['size' => 8], ['alignment' => $J::RIGHT]);
        $tmp = tempnam(sys_get_temp_dir(), 'okk');
        \PhpOffice\PhpWord\IOFactory::createWriter($w, 'Word2007')->save($tmp);
        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);
        error_reporting($er);
        return $bytes;
    }
}
