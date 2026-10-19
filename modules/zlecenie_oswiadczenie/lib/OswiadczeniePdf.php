<?php
declare(strict_types=1);

/**
 * modules/zlecenie_oswiadczenie/lib/OswiadczeniePdf.php — wydruki PDF oświadczeń zleceniobiorcy (mPDF).
 *
 *  - ZUS: strona 1 — instrukcja („wydrukuj, sprawdź, podpisz, prześlij skan przez system”), strona 2 — oświadczenie
 *    w NIEZMIENIONEJ formie: akapity z OswiadczenieDocx::paragraphs() (ten sam model co DOCX, ze strażnikiem treści),
 *    marginesy, czcionka i interlinia jak we wzorze.
 *  - ZLB: oświadczenie o numerze rachunku bankowego w układzie formularza urzędowego (jak deklaracja CIT-8: symbol ZLB
 *    w kaflu, szare belki sekcji, numerowane pola w ramkach, pola Fundacji ciemne), treść oświadczenia słowo w słowo
 *    ze wzoru (ZLB_TEXT); strona 2 — instrukcja.
 * Brak mPDF → wyjątek (kontroler pokazuje komunikat i proponuje DOCX).
 */
require_once __DIR__ . '/OswiadczenieDocx.php';
require_once __DIR__ . '/OswForm.php';

final class OswiadczeniePdf
{
    public const ZLB_VERSION = '1/2026';
    /** Treść wzoru „Oświadczenie o numerze rachunku bankowego” — bez zmian redakcyjnych. */
    public const ZLB_TITLE = 'Oświadczenie o numerze rachunku bankowego';
    public const ZLB_LEAD = 'Dane rachunku bankowego do wypłaty wynagrodzenia z tytułu zatrudnienia w Fundacji Edukacji Empatii Rozwoju „FEER”';
    public const ZLB_TEXT = [
        'W związku z planowanym zatrudnieniem na podstawie umowy cywilnoprawnej, realizowanej w ramach zadania finansowanego ze środków publicznych, oświadczam, że jestem właścicielem wskazanego do wypłaty wynagrodzenia rachunku bankowego.',
        'W odniesieniu do powyższego rachunku potwierdzam, co następuje:',
        '1) rachunek ten nie jest objęty żadnym zajęciem komorniczym ani innymi postępowaniami egzekucyjnymi,',
        '2) rachunek jest prowadzony dla osoby fizycznej i nie jest wykorzystywany w ramach prowadzonej działalności gospodarczej.',
        'Jednocześnie przyjmuję do wiadomości, że w przypadku uzyskania przez Fundację informacji o wystąpieniu zajęcia komorniczego na wskazanym rachunku, Fundacja zastrzega sobie prawo do natychmiastowego rozwiązania zawartej umowy z wyłącznej winy Zleceniobiorcy.',
        'Niniejsze oświadczenie pozostaje w mocy do końca roku kalendarzowego, w którym zostało złożone. Termin ten ulega skróceniu wyłącznie w sytuacji, gdy w toku trwającej współpracy Zleceniobiorca złoży nowe oświadczenie o Numerze Rachunku Bankowego (NRB).',
    ];

    private static function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

    private static function mpdf(array $margins = [25, 25, 25, 25]): \Mpdf\Mpdf
    {
        $auto = dirname(__DIR__, 3) . '/vendor/autoload.php';
        if (!is_file($auto)) throw new RuntimeException('Brak biblioteki PDF (vendor).');
        require_once $auto;
        $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        [$t, $r, $b, $l] = $margins;
        return new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_top' => $t, 'margin_right' => $r, 'margin_bottom' => $b,
                               'margin_left' => $l, 'margin_footer' => 6, 'default_font' => 'dejavusanscondensed', 'tempDir' => $tmp]);
    }

    // ── ZUS ───────────────────────────────────────────────────────────────────

    /** PDF: instrukcja + oświadczenie ZUS w niezmienionej formie. $meta: uploadUrl, numer, data, kto */
    public static function zus(array $a, array $umowa, array $meta): string
    {
        $paras = OswiadczenieDocx::paragraphs($a, $umowa);   // ten sam model co DOCX + strażnik treści wzoru
        $m = self::mpdf([12, 16, 14, 16]);
        $m->SetTitle('Oświadczenie dla celów podatkowych i ubezpieczenia ZUS — ' . ($a['nazwisko_imiona'] ?? ''));
        $m->SetAuthor((string)org_setting('org_name'));
        $m->WriteHTML(self::instructionHtml('Oświadczenie dla celów podatkowych i ubezpieczenia ZUS', $meta,
            'Wpisz datę i podpisz się w miejscu „podpis wykonawcy” na dole oświadczenia.'));
        // Strona wzoru: marginesy 2,5 cm, Arial 11 pt, interlinia 16 pt (jak w pliku DOCX)
        $m->AddPageByArray(['margin-left' => 25, 'margin-right' => 25, 'margin-top' => 25, 'margin-bottom' => 25]);
        // FreeSans ma metrykę zgodną z Arialem (czcionka wzoru) — linie łamią się jak w Wordzie; kratki z DejaVu (pełny zestaw znaków)
        $h = '<style>p{margin:0;font-family:freesans;font-size:11pt;line-height:16pt}s{text-decoration:line-through}.bx{font-family:dejavusans}</style>';
        $prevRow = null;
        foreach ($paras as $p) {
            if ($p['row'] !== null && $p['row'] === $prevRow) continue;   // komórki wiersza tabeli = jedna linia
            $prevRow = $p['row'];
            $txt = '';
            $lead = 0;
            foreach ($p['runs'] as $i => $r) {
                $t = $r['text'];
                if ($txt === '' && $i === array_key_first($p['runs'])) { $lead = strspn($t, "\t"); $t = substr($t, $lead); }
                $t = str_replace("\t", str_repeat('&#160;', 12), self::e($t));
                $t = preg_replace('/ {2,}/', ' &#160;', $t);
                $t = str_replace(['□', '☒'], ['<span class="bx">□</span>', '<span class="bx">☒</span>'], $t);
                if ($r['bold'])   $t = '<b>' . $t . '</b>';
                if ($r['strike']) $t = '<s>' . $t . '</s>';
                $txt .= $t;
            }
            if (trim(strip_tags($txt)) === '' && $lead === 0) $txt = '&#160;';
            $h .= '<p style="text-align:' . ($p['align'] === 'center' ? 'center' : 'left') . ($lead ? ';margin-left:' . ($lead * 12.5) . 'mm' : '') . '">' . $txt . '</p>';
        }
        $m->WriteHTML($h);
        return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    // ── ZLB ───────────────────────────────────────────────────────────────────

    /** HTML formularza ZLB w układzie urzędowym. $meta: numer, umowa, data (Y-m-d), filledAt, ip */
    public static function zlbHtml(array $a, array $meta): string
    {
        $n = 0;
        $fld = function (string $label, string $val, string $attr = '', bool $dark = false) use (&$n): string {
            $n++;
            return '<td class="f"' . $attr . ($dark ? ' style="background:#e6e6e6"' : '') . '><div class="lb"><b>' . $n . '.</b> ' . $label . '</div><div class="v">' . ($val !== '' ? $val : '&#160;') . '</div></td>';
        };
        $sec = fn(string $l, string $t, int $cols = 1, string $hint = '') => '<tr><td class="sec" colspan="' . $cols . '"><b>' . $l . '. ' . $t . '</b>' . ($hint ? ' <span class="hint">' . $hint . '</span>' : '') . '</td></tr>';
        $nrb = preg_replace('/\D/', '', preg_replace('/^PL/i', '', (string)($a['nrb'] ?? '')));
        $isPl = strlen($nrb) === 26 && (ctype_digit((string)($a['nrb'] ?? '')) || str_starts_with(strtoupper((string)$a['nrb']), 'PL'));
        if ($isPl) {
            $cells = '';
            foreach (str_split($nrb) as $i => $d) $cells .= '<td class="dg' . (in_array($i, [1, 5, 9, 13, 17, 21], true) ? ' gap' : '') . '">' . $d . '</td>';
            $nrbHtml = '<table class="dgt"><tr>' . $cells . '</tr></table>';
        } else {
            $nrbHtml = '<span class="big">' . self::e(OswForm::nrbFormat((string)($a['nrb'] ?? ''))) . '</span>';
        }
        $h = self::css()
            . '<table class="t"><tr>'
            . $fld('Numer dokumentu', '<span class="big">' . self::e($meta['numer'] ?? '') . '</span>', ' width="40%"')
            . $fld('Do umowy zlecenia nr', self::e($meta['umowa'] ?? ''), ' width="40%"')
            . '<td class="sym" width="20%"><div class="symb">ZLB</div><div class="symp">/1</div><div class="symv">wersja wzoru ' . self::ZLB_VERSION . '</div></td>'
            . '</tr></table>'
            . '<table class="t"><tr><td class="ttl"><div class="ttl1">OŚWIADCZENIE O NUMERZE RACHUNKU BANKOWEGO</div><div class="ttl2">' . self::e(self::ZLB_LEAD) . '</div></td></tr>'
            . '<tr><td class="lgd">Pola jasne wypełnia Zleceniobiorca (wypełniono w kreatorze), pola ciemne wypełnia Fundacja. Po wydrukowaniu sprawdź dane, podpisz czytelnie i prześlij skan przez system.</td></tr></table>'
            . '<table class="t">' . $sec('A', 'DANE SKŁADAJĄCEGO OŚWIADCZENIE', 3)
            . '<tr>' . $fld('Imię i nazwisko', self::e(OswForm::nameFirst((string)($a['nazwisko_imiona'] ?? ''))), ' width="50%"')
            . $fld('Miejscowość', self::e($a['zlb_miejscowosc'] ?? ''), ' width="30%"')
            . $fld('Data', self::e(OswForm::pl((string)($meta['data'] ?? ''))), ' width="20%"') . '</tr></table>'
            . '<table class="t">' . $sec('B', 'DANE RACHUNKU BANKOWEGO', 2, '(do wypłaty wynagrodzenia)')
            . '<tr>' . $fld('Numer', $nrbHtml, ' colspan="2"') . '</tr>'
            . '<tr>' . $fld('Bank prowadzący rachunek', self::e($a['bank_nazwa'] ?? ''), ' width="50%"')
            . $fld('Imię i nazwisko posiadacza rachunku', self::e($a['posiadacz'] ?? ''), ' width="50%"') . '</tr></table>'
            . '<table class="t">' . $sec('C', 'OŚWIADCZENIE', 1) . '</table><table class="tc">';
        $last = count(self::ZLB_TEXT) - 1;
        foreach (self::ZLB_TEXT as $i => $t) {
            $pad = (in_array($i, [2, 3], true) ? 'padding:0.3mm 2mm 0.3mm 8mm' : 'padding:' . ($i === 0 ? '1.6' : '1.2') . 'mm 2mm 0.3mm 2mm')
                 . ($i === $last ? ';padding-bottom:1.8mm;border-bottom:0.25mm solid #000' : '');
            $h .= '<tr><td class="pt" style="' . $pad . '">' . self::e($t) . '</td></tr>';
        }
        $h .= '</table>'
            . '<table class="t">' . $sec('D', 'PODPIS', 2)
            . '<tr>' . $fld('Miejscowość, data', self::e(trim(($a['zlb_miejscowosc'] ?? '') . ', ' . OswForm::pl((string)($meta['data'] ?? '')), ', ')), ' width="40%"')
            . '<td class="f" width="60%" style="height:24mm"><div class="lb"><b>' . (++$n) . '.</b> Czytelny podpis Zleceniobiorcy</div></td></tr></table>'
            . '<table class="t">' . $sec('E', 'ADNOTACJE FUNDACJI', 3)
            . '<tr>' . $fld('Data wpływu skanu', '', ' width="30%"', true)
            . $fld('Zweryfikował(a) — imię i nazwisko', '', ' width="40%"', true)
            . $fld('Podpis', '', ' width="30%"', true) . '</tr></table>';
        if (!empty($meta['filledAt'])) {
            $h .= '<div class="esig">Dane wpisane elektronicznie w systemie SZO: ' . self::e(date('d.m.Y H:i', (int)strtotime((string)$meta['filledAt'])))
                . (!empty($meta['ip']) ? ', IP ' . self::e($meta['ip']) : '') . '. Oświadczenie wymaga własnoręcznego podpisu.</div>';
        }
        return $h;
    }

    public static function zlb(array $a, array $meta): string
    {
        $m = self::mpdf([8, 12, 13, 12]);
        $m->SetTitle('ZLB — ' . self::ZLB_TITLE . ' — ' . ($a['nazwisko_imiona'] ?? ''));
        $m->SetAuthor((string)org_setting('org_name'));
        $m->SetHTMLFooter('<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr>'
            . '<td width="33%"><b>ZLB</b> (' . self::ZLB_VERSION . ')</td><td width="34%" style="text-align:center">' . self::e($meta['numer'] ?? '') . '</td>'
            . '<td width="33%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>');
        $m->WriteHTML(self::zlbHtml($a, $meta));
        $m->AddPage();
        $m->WriteHTML(self::instructionHtml(self::ZLB_TITLE . ' (ZLB)', $meta, 'Podpisz się czytelnie w polu „Czytelny podpis Zleceniobiorcy” (sekcja D).', 1));
        return $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    // ── Instrukcja ────────────────────────────────────────────────────────────

    /** Strona instrukcji. $formPage — która strona PDF to oświadczenie (do wydruku). */
    public static function instructionHtml(string $docName, array $meta, string $signHint, int $formPage = 2): string
    {
        $url = (string)($meta['uploadUrl'] ?? '');
        $qr = '';
        if ($url !== '') {
            require_once dirname(__DIR__, 3) . '/includes/qr.php';
            $uri = qr_data_uri($url, 220);
            if ($uri) $qr = '<img src="' . $uri . '" width="34mm" height="34mm" alt="">';
        }
        $steps = [
            ['Wydrukuj oświadczenie', 'Wydrukuj stronę ' . $formPage . ' tego pliku (samo oświadczenie). Tej instrukcji nie musisz drukować ani odsyłać.'],
            ['Sprawdź dane', 'Przeczytaj wydruk i sprawdź wszystkie dane. Jeśli coś się nie zgadza, nie poprawiaj go długopisem — wróć do kreatora, popraw dane i wydrukuj oświadczenie ponownie.'],
            ['Podpisz', $signHint . ' Podpis musi być własnoręczny i czytelny.'],
            ['Zeskanuj', 'Zeskanuj podpisane oświadczenie albo zrób wyraźne zdjęcie całej strony (widoczne wszystkie rogi, bez cieni). Plik PDF, JPG lub PNG, najwyżej 20 MB.'],
            ['Wyślij skan przez system', 'Otwórz stronę oświadczeń (adres i kod QR poniżej), wybierz „Prześlij skan” przy tym dokumencie i dodaj plik. Nie wysyłaj skanu e-mailem — system przypisze go do Twojej umowy, a Ty zobaczysz, kiedy Fundacja go sprawdzi.'],
            ['Zachowaj oryginał', 'Papierowy oryginał zachowaj — Fundacja może poprosić o jego dostarczenie.'],
        ];
        $h = '<style>.ins{font-family:dejavusans;font-size:10pt;color:#000}.ins h1{font-size:15pt;margin:0 0 1mm}.ins .sub{font-size:9pt;color:#333;margin-bottom:5mm}'
           . '.ins table.st{width:100%;border-collapse:collapse}.ins td{vertical-align:top;padding:2mm 0}.ins .no{width:10mm;font-size:14pt;font-weight:bold;color:#1ba1e2}'
           . '.ins .hd{font-weight:bold;font-size:10.5pt}.ins .box{border:0.3mm solid #000;padding:3mm;margin-top:5mm}.ins .url{font-size:9pt;word-wrap:break-word}</style>'
           . '<div class="ins"><h1>Instrukcja — jak złożyć oświadczenie</h1><div class="sub">' . self::e($docName)
           . (!empty($meta['umowa']) ? ' · umowa ' . self::e($meta['umowa']) : '') . (!empty($meta['kto']) ? ' · ' . self::e($meta['kto']) : '') . '</div><table class="st">';
        foreach ($steps as $i => [$t, $d]) {
            $h .= '<tr><td class="no">' . ($i + 1) . '</td><td><div class="hd">' . self::e($t) . '</div>' . self::e($d) . '</td></tr>';
        }
        $h .= '</table>';
        if ($url !== '') {
            $h .= '<table class="box" width="100%"><tr><td>' . ($qr ? $qr : '') . '</td><td style="padding-left:4mm"><div class="hd">Tu prześlesz skan:</div><div class="url">' . self::e($url) . '</div>'
                . '<div style="font-size:8.5pt;margin-top:2mm">Zeskanuj kod QR telefonem, aby od razu otworzyć stronę i dodać zdjęcie podpisanego oświadczenia.</div></td></tr></table>';
        }
        return $h . '</div>';
    }

    private static function css(): string
    {
        return '<style>
          body{font-family:dejavusans;font-size:8.6pt;color:#000}
          table.t{width:100%;border-collapse:collapse;margin:0}
          table.t td{border:0.25mm solid #000;vertical-align:top}
          td.f{padding:0.6mm 1.4mm 0.9mm;background:#fff}
          td.pc{padding:1.4mm 2mm 1.6mm;background:#fff}
          table.tc{width:100%;border-collapse:collapse;margin:0}
          table.tc td{border-left:0.25mm solid #000;border-right:0.25mm solid #000;vertical-align:top}
          .pt{font-size:9pt;text-align:justify;line-height:1.4}
          .lb{font-size:6.4pt;color:#222;margin-bottom:0.4mm}
          .v{font-size:9.5pt;font-weight:bold}
          .big{font-size:10.5pt;letter-spacing:0.2mm}
          td.sec{background:#bfbfbf;padding:1mm 1.6mm;font-size:8.6pt;letter-spacing:0.15mm}
          .hint{font-weight:normal;font-size:7pt}
          td.sym{background:#1ba1e2;color:#fff;text-align:center;vertical-align:middle}
          .symb{font-size:22pt;font-weight:bold;letter-spacing:0.5mm}
          .symp{font-size:11pt;font-weight:bold}
          .symv{font-size:6.5pt}
          td.ttl{text-align:center;padding:2mm;background:#fff}
          .ttl1{font-size:12pt;font-weight:bold;letter-spacing:0.2mm}
          .ttl2{font-size:8pt;margin-top:1mm}
          td.lgd{font-size:6.8pt;padding:1mm 1.6mm;background:#f2f2f2}
          table.dgt{border-collapse:collapse;margin-top:0.6mm}
          table.dgt td.dg{border:0.25mm solid #000;width:5.6mm;height:6.4mm;text-align:center;vertical-align:middle;font-size:11pt;font-weight:bold}
          table.dgt td.gap{border-right:0.7mm solid #000}
          .esig{font-size:6.6pt;color:#333;margin-top:1.2mm}
        </style>';
    }
}
