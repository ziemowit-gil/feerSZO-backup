<?php
declare(strict_types=1);

/**
 * modules/ti_dop/lib/DopDocument.php — wydruk wniosków DOP1 / DOP2 na arkuszu A4 wg wzoru: nagłówek (organizacja i logo
 * w lewym górnym rogu, miejscowość i data w prawym), tytuł, numer wniosku, sekcje I–V/VI w ramkach z numerowanymi polami,
 * kratki ☒/☐, miejsce na podpis odręczny (albo adnotacja podpisu elektronicznego), pola wewnętrzne (weryfikacja, akceptacja,
 * realizacja) na szarym tle ze stemplami (kto, kiedy, IP). Pusty wzór: render z pustym wnioskiem (number '').
 * Fallback: brak mPDF → HTML (jak w module licencji).
 */
require_once dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/lib/ldSchema.php';
require_once __DIR__ . '/DopForm.php';

final class DopDocument
{
    private int $n = 0;

    private static function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    private static function box(bool $on): string { return '<span class="bx">' . ($on ? '&#9746;' : '&#9744;') . '</span>'; }
    private static function orBlank(string $v, string $hint = ''): string
    {
        return trim($v) === '' ? '<span class="ph">' . self::e($hint) . '</span><div class="dots">&nbsp;</div>' : nl2br(self::e($v));
    }
    private static function money($v): string { return $v === '' || $v === null ? '' : number_format((float)$v, 2, ',', ' ') . ' PLN'; }
    private static function iban(string $v): string
    {
        $d = preg_replace('/^PL/', '', strtoupper(preg_replace('/\s+/', '', $v)));
        return $d === '' ? 'PL __ ____ ____ ____ ____ ____ ____' : 'PL ' . substr($d, 0, 2) . ' ' . implode(' ', str_split(substr($d, 2), 4));
    }

    private function fld(string $label, string $html, string $extra = '', bool $dark = false): string
    {
        $this->n++;
        if ($dark) $extra .= ' style="background:#e6e6e6"';
        return '<td class="f"' . $extra . '><div class="lb"><b>' . $this->n . '.</b> ' . self::e($label) . '</div><div class="v">' . ($html !== '' ? $html : '&nbsp;') . '</div></td>';
    }

    private static function sec(string $roman, string $title, int $cols = 2): string
    {
        return '<tr><td class="sec" colspan="' . $cols . '"><b>SEKCJA ' . $roman . ': ' . self::e(mb_strtoupper($title)) . '</b></td></tr>';
    }

    private static function stamp(?string $who, ?string $at, ?string $ip, string $label): string
    {
        return $at ? '<div class="esig">' . self::e($label) . ': ' . self::e((string)$who) . ', ' . self::e(ldDate(substr((string)$at, 0, 10)) . ' ' . substr((string)$at, 11, 5)) . ($ip ? ', IP ' . self::e($ip) : '') . '</div>' : '';
    }

    public function html(array $r): string
    {
        $this->n = 0;
        $f = $r['formEnum'];
        $d = $r['d'];
        $st = $r['stepsData'];
        $blankForm = (string)$r['number'] === '';
        $org = ldOrgName();
        $logo = '';
        try {
            if (function_exists('branding_load') || is_file(dirname(__DIR__, 3) . '/includes/branding.php')) {
                require_once dirname(__DIR__, 3) . '/includes/branding.php';
                $b = branding_load();
                $lp = (string)($b['logo_path'] ?? '');
                if ($lp !== '' && is_file($lp)) $logo = '<img src="' . self::e($lp) . '" style="height:12mm"><br>';
            }
        } catch (\Throwable $e) {}
        $fields = $f->fields();
        $val = fn(string $k) => (string)($d[$k] ?? '');
        $chk = fn(string $k) => !empty($d[$k]);
        $date = $blankForm ? '' : ldDate(substr((string)$r['created_at'], 0, 10));
        // ── Nagłówek ──
        $h = '<table class="hdr"><tr><td style="width:60%">' . $logo . '<b style="font-size:11pt">' . self::e($org) . '</b><br><span class="small">' . self::e((string)org_setting('org_adres')) . '</span></td>'
           . '<td style="width:40%;text-align:right">' . ($blankForm ? '...................................., dnia ....................' : self::e(($r['place'] ?: '') . ', dnia ' . $date)) . '<div class="small">miejscowość i data wypełnienia</div></td></tr></table>'
           . ($f === DopForm::Zpro ? '' : '<div class="title">' . $f->docLabel() . ' ' . self::e($f->value) . ' – ' . self::e(mb_strtoupper($f->title())) . '</div>')
           . '<div class="num">Numer ' . ($f->isNonFinancial() ? 'dokumentu' : 'wniosku') . ' (wypełnia biuro): <b>' . ($blankForm ? self::e($f->value) . ' / ______ / ______' : self::e(str_replace('/', ' / ', (string)$r['number']))) . '</b>'
           . (!$blankForm ? ' &nbsp;·&nbsp; termin realizacji: do ' . LD_REALIZATION_DAYS . ' dni (' . self::e(ldDate($r['due_on'])) . ')' : '') . '</div>';
        $section = function (string $roman) use ($f, $fields, $val, $chk): string {
            $rows = '';
            foreach ($fields as $k => [$label, $type, $req, $opts, $sec]) {
                if ($sec !== $roman || $type === 'check') continue;
                if ($type === 'money') {
                    $cell = $val($k) !== '' ? '<b>' . self::e(self::money($val($k))) . '</b><div class="small">słownie: ' . self::e(dopKwotaSlownie((float)$val($k))) . '</div>'
                                            : '___________________ PLN <div class="small">(słownie: ___________________________________)</div>';
                } else {
                    $v = match ($type) { 'date' => ldDate($val($k)), 'iban' => self::iban($val($k)), 'select' => (string)($opts[$val($k)] ?? ''), 'course' => $val('grupa_nazwa'), default => $val($k) };
                    $cell = $type === 'iban' ? '<b>' . self::e($v) . '</b>' : self::orBlank($v, mb_strtolower($label));
                }
                $rows .= '<tr>' . $this->fld($label, $cell, ' colspan="2"') . '</tr>';
            }
            foreach ($fields as $k => [$label, $type, $req, $opts, $sec]) {
                if ($sec !== $roman || $type !== 'check') continue;
                $rows .= '<tr><td class="f" colspan="2">' . self::box($chk($k)) . ' ' . self::e($label) . '</td></tr>';
            }
            return $rows;
        };
        $sections = $f->sections();
        $blankLine = fn(string $v, int $n = 40) => $v !== '' ? '<b>' . self::e($v) . '</b>' : str_repeat('.', $n);
        if ($f === DopForm::Zpro) {
            // Pismo wg wzoru „Podanie o zmianę prowadzącego zajęcia”
            $h .= '<table class="t"><tr>' . $this->fld('Do', $blankLine($val('do'), 60), ' colspan="2"') . '</tr><tr>'
                . $this->fld('Wnioskodawca', $blankLine($val('nazwa'), 60) . ($val('opiekun') !== '' ? '<div class="small">przedstawiciel ustawowy: ' . self::e($val('opiekun')) . '</div>' : '') . ($val('kontakt') !== '' ? '<div class="small">' . self::e($val('kontakt')) . '</div>' : ''), ' colspan="2"') . '</tr></table>'
                . '<div class="title" style="margin-top:6mm">PODANIE O ZMIANĘ PROWADZĄCEGO ZAJĘCIA</div>'
                . '<div class="letter" style="font-size:10.5pt;line-height:1.6;margin:4mm 2mm">'
                . '<p>Zwracam się z uprzejmą prośbą o zmianę osoby prowadzącej zajęcia w grupie ' . $blankLine($val('grupa_nazwa'), 40) . ', obecnie prowadzone przez ' . $blankLine($val('obecny'), 40) . '.</p>'
                . ($val('proponowany') !== '' || $val('od_dnia') !== '' ? '<p>' . ($val('proponowany') !== '' ? 'Proponuję, aby zajęcia prowadził(a): <b>' . self::e($val('proponowany')) . '</b>. ' : '') . ($val('od_dnia') !== '' ? 'Proszę o zmianę od dnia <b>' . self::e(ldDate($val('od_dnia'))) . '</b>.' : '') . '</p>' : '')
                . '<p>Prośbę uzasadniam następująco: ' . $blankLine($val('uzasadnienie'), 120) . '.</p>'
                . '<p>Proszę o rozpatrzenie podania i poinformowanie mnie o podjętej decyzji.</p>'
                . '<p style="margin-top:6mm">Z poważaniem,</p></div>';
        } elseif ($f->isResignation()) {
            $h .= '<table class="t">' . self::sec('I', $sections['I']) . $section('I') . '</table>';
            $h .= '<table class="t">' . self::sec('II', $sections['II']) . '<tr><td class="pc" colspan="2"><b>' . self::e($f->statement()) . '</b></td></tr>' . $section('II') . '</table>';
        } elseif ($f === DopForm::Dop1) {
            $h .= '<table class="t">' . self::sec('I', $sections['I']) . $section('I') . '</table>';
            $reason = $val('przyczyna');
            $h .= '<table class="t">' . self::sec('II', $sections['II']) . '<tr><td class="pc" colspan="2">' . self::e($f->statement()) . '</td></tr>'
                . '<tr>' . $this->fld('Kwota do zwrotu', $val('kwota') !== '' ? '<b>' . self::e(self::money($val('kwota'))) . '</b><div class="small">słownie: ' . self::e(dopKwotaSlownie((float)$val('kwota'))) . '</div>'
                    : '___________________ PLN <div class="small">(słownie: ___________________________________)</div>', ' colspan="2"') . '</tr>'
                . '<tr>' . $this->fld('Przyczyna powstania nadpłaty', self::box($reason === 'podwojna') . ' Podwójna / błędna wpłata<br>' . self::box($reason === 'korekta') . ' Korekta faktury / rozliczenie umowy<br>'
                    . self::box($reason === 'inne') . ' Inne (jakie?): ' . ($reason === 'inne' ? '<b>' . self::e($val('przyczyna_inna')) . '</b>' : '________________________________'), ' colspan="2"') . '</tr>'
                . '<tr>' . $this->fld('Dane do zwrotu — numer rachunku bankowego (IBAN)', '<b>' . self::e(self::iban($val('iban'))) . '</b>', ' colspan="2"') . '</tr>'
                . '<tr>' . $this->fld('Właściciel rachunku', self::orBlank($val('wlasciciel'), 'właściciel rachunku'), ' colspan="2"') . '</tr>'
                . '<tr><td class="f" colspan="2">' . self::box($chk('oswiadczenie')) . ' <b>Oświadczenie:</b> ' . self::e($fields['oswiadczenie'][0]) . '.</td></tr></table>';
            $h .= '<table class="t">' . self::sec('III', $sections['III'] . ' (zaznaczyć właściwe)') . $section('III') . '</table>';
        } else {
            $h .= '<table class="t">' . self::sec('I', $sections['I']) . $section('I') . '</table>';
            $h .= '<table class="t">' . self::sec('II', $sections['II']) . $section('II') . '</table>';
            $h .= '<table class="t">' . self::sec('III', $sections['III']) . '<tr><td class="pc" colspan="2">' . self::e($f->statement()) . '</td></tr>' . $section('III') . '</table>';
            $h .= '<table class="t">' . self::sec('IV', $sections['IV']) . $section('IV') . '</table>';
        }
        // Podpis wnioskodawcy
        $sigRoman = $f->isNonFinancial() ? 'III' : ($f === DopForm::Dop1 ? 'IV' : 'V');
        $signed = !empty($r['signed_at']);
        $sigHtml = $signed && $r['sign_mode'] === 'link'
            ? '<b>' . self::e($r['signed_name']) . '</b>' . self::stamp($r['signed_name'], $r['signed_at'], $r['signed_ip'], 'Podpisano elektronicznie przez link')
            : ($signed ? '<b>podpis odręczny — skan w aktach wniosku</b>' . self::stamp($r['signed_name'], $r['signed_at'], '', 'Skan wgrano') : '<div style="height:14mm"></div><div class="center">...............................................<br><span class="small">(' . ($f->isNonFinancial() ? 'Czytelny podpis uczestnika / przedstawiciela ustawowego' : ($f === DopForm::Dop1 ? 'Odręczny podpis Wnioskodawcy / Czytelny' : 'Odręczny podpis / Pieczęć imienna')) . ')</span></div>');
        $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec($sigRoman, $sections[$sigRoman]) . '<tr><td class="f" colspan="2">' . $sigHtml . '</td></tr></table>';
        // Część wewnętrzna
        if ($f->isNonFinancial()) {
            $v = $st['verify'] ?? []; $ex = $st['execute'] ?? [];
            $dec = isset($v['grant']);
            $okL = $f->isResignation() ? 'Rezygnację przyjęto' : 'Wyrażono zgodę na zmianę prowadzącego';
            $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('IV', $sections['IV'])
                . '<tr>' . $this->fld('Decyzja', self::box($dec && !empty($v['grant'])) . ' ' . $okL . ' &nbsp;&nbsp; ' . self::box($dec && empty($v['grant'])) . ' Odmowa (powód: ' . ($dec && empty($v['grant']) ? '<b>' . self::e($v['powod'] ?? '') . '</b>' : '_________________________') . ')'
                    . ($dec && !empty($v['grant']) && ($v['powod'] ?? '') !== '' ? '<div class="small">' . self::e($v['powod']) . '</div>' : ''), ' colspan="2"', true) . '</tr>'
                . '<tr>' . $this->fld('Data i podpis kierownika', $dec ? self::e($v['name'] ?? '') . self::stamp($v['name'] ?? '', $v['at'] ?? '', $v['ip'] ?? '', 'Zatwierdzono elektronicznie') : '', ' style="height:12mm"', true)
                . $this->fld($f->isResignation() ? 'Zapis zakończono z dniem' : 'Zmiana wprowadzona od dnia / nowa osoba prowadząca',
                    $ex ? ($f->isResignation() ? '<b>' . self::e(ldDate($ex['data_zakonczenia'] ?? '')) . '</b>' . (!empty($ex['zapisy']) ? '<div class="small">' . self::e(implode(', ', $ex['zapisy'])) . '</div>' : '')
                                               : '<b>' . self::e(ldDate($ex['data_zmiany'] ?? '')) . '</b>' . (($ex['nowy'] ?? '') !== '' ? ' — ' . self::e($ex['nowy']) : ''))
                        . self::stamp($ex['name'] ?? '', $ex['at'] ?? '', $ex['ip'] ?? '', 'Zarejestrował(a)') : '', '', true) . '</tr></table>';
        } elseif ($f === DopForm::Dop1) {
            $v = $st['verify'] ?? [];
            $ex = $st['execute'] ?? [];
            $dec = isset($v['grant']);
            $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('V', $sections['V'] . ' (wypełnia osoba upoważniona)')
                . '<tr>' . $this->fld('Potwierdzone saldo nadpłaty na dzień', $dec ? self::e(ldDate($v['saldo_na_dzien'] ?? '')) . ' wynosi: <b>' . self::e(self::money($v['saldo'] ?? '')) . '</b>' : '____-____-' . date('Y') . ' r. wynosi: ______________ PLN', ' colspan="2"', true) . '</tr>'
                . '<tr>' . $this->fld('Zgodność numeru rachunku', self::box(($v['zgodnosc'] ?? '') === 'zgodny') . ' Zgodny z historią wpłat &nbsp;&nbsp; ' . self::box(($v['zgodnosc'] ?? '') === 'niezgodny') . ' Niezgodny (załączono upoważnienie)', ' colspan="2"', true) . '</tr>'
                . '<tr>' . $this->fld('Decyzja', self::box($dec && !empty($v['grant'])) . ' Zatwierdzono do zwrotu &nbsp;&nbsp; ' . self::box($dec && empty($v['grant'])) . ' Odrzucono (Powód: ' . ($dec && empty($v['grant']) ? '<b>' . self::e($v['powod'] ?? '') . '</b>' : '_________________________') . ')', ' colspan="2"', true) . '</tr>'
                . '<tr>' . $this->fld('Data i podpis księgowego', $dec ? self::e($v['name'] ?? '') . self::stamp($v['name'] ?? '', $v['at'] ?? '', $v['ip'] ?? '', 'Zatwierdzono elektronicznie') : '', ' style="height:12mm"', true)
                . $this->fld('Data realizacji przelewu / Nr transakcji', $ex ? self::e(ldDate($ex['data_przelewu'] ?? '')) . ' / <b>' . self::e($ex['transakcja'] ?? '') . '</b>' . self::stamp($ex['name'] ?? '', $ex['at'] ?? '', $ex['ip'] ?? '', 'Zarejestrował(a)') : '', '', true) . '</tr></table>';
        } else {
            $v = $st['verify'] ?? []; $ap = $st['approve'] ?? []; $ex = $st['execute'] ?? [];
            $h .= '<table class="t" style="page-break-inside:avoid">' . self::sec('VI', $sections['VI'] . ' (wypełniają działy wewnętrzne)')
                . '<tr><td class="sub" colspan="2"><b>1. Weryfikacja dostępności środków na subkoncie</b></td></tr><tr>'
                . $this->fld('Dostępne saldo subkonta', $v ? '<b>' . self::e(self::money($v['saldo'] ?? '')) . '</b>' : '______________ PLN', '', true)
                . $this->fld('Data i podpis kontrolera / księgowego', $v ? self::e($v['name'] ?? '') . self::stamp($v['name'] ?? '', $v['at'] ?? '', $v['ip'] ?? '', 'Zweryfikowano elektronicznie') : '', ' style="height:11mm"', true) . '</tr>'
                . '<tr><td class="sub" colspan="2"><b>2. Zatwierdzenie Dyrektora / Kierownika</b></td></tr><tr>'
                . $this->fld('Decyzja', self::box(!empty($ap) && !empty($ap['zgoda'])) . ' Wyrażam zgodę na realizację &nbsp;&nbsp; ' . self::box(!empty($ap) && empty($ap['zgoda'])) . ' Brak zgody' . (!empty($ap['uwagi']) ? '<div class="small">' . self::e($ap['uwagi']) . '</div>' : ''), '', true)
                . $this->fld('Data, podpis i pieczęć', $ap ? self::e($ap['name'] ?? '') . self::stamp($ap['name'] ?? '', $ap['at'] ?? '', $ap['ip'] ?? '', 'Zatwierdzono elektronicznie') : '', ' style="height:11mm"', true) . '</tr>'
                . '<tr><td class="sub" colspan="2"><b>3. Realizacja Płatności (Dział Finansowy)</b></td></tr><tr>'
                . $this->fld('Data wykonania przelewu / Identyfikator wyciągu lub transakcji', $ex ? self::e(ldDate($ex['data_przelewu'] ?? '')) . ' / <b>' . self::e($ex['transakcja'] ?? '') . '</b>' : '', '', true)
                . $this->fld('Podpis osoby wykonującej przelew', $ex ? self::e($ex['name'] ?? '') . self::stamp($ex['name'] ?? '', $ex['at'] ?? '', $ex['ip'] ?? '', 'Zarejestrował(a)') : '', ' style="height:11mm"', true) . '</tr></table>';
        }
        return self::css() . $h;
    }

    private static function css(): string
    {
        return '<style>
          body{font-family:dejavusans;font-size:8.8pt;color:#000}
          table.hdr{width:100%;border-collapse:collapse;margin-bottom:3mm} table.hdr td{vertical-align:top}
          .title{font-size:13pt;font-weight:bold;text-align:center;margin:2mm 0 1mm;letter-spacing:0.2mm}
          .num{text-align:center;font-size:8.6pt;margin-bottom:3mm}
          table.t{width:100%;border-collapse:collapse;margin:0 0 2mm}
          table.t td{border:0.25mm solid #000;vertical-align:top}
          td.f{padding:0.8mm 1.6mm 1mm} td.pc{padding:1.2mm 1.6mm;font-size:9pt}
          td.sec{background:#bfbfbf;padding:1mm 1.6mm;font-size:8.8pt;letter-spacing:0.15mm}
          td.sub{background:#d9d9d9;padding:0.8mm 1.6mm;font-size:8.4pt}
          .lb{font-size:6.6pt;color:#222;margin-bottom:0.4mm} .v{font-size:9.2pt}
          .ph{font-size:7pt;color:#666;font-style:italic} .dots{border-bottom:0.2mm dotted #000;height:4mm}
          .bx{font-size:10.5pt} .small{font-size:7pt} .center{text-align:center}
          .esig{font-size:6.6pt;color:#333;margin-top:0.8mm}
        </style>';
    }

    /** ['bytes','mime','ext'] — PDF (mPDF) albo HTML, gdy mPDF niedostępny. */
    public static function render(array $r): array
    {
        $html = (new self())->html($r);
        $title = 'Wniosek ' . $r['formEnum']->value . ' — ' . $r['formEnum']->title();
        try {
            $auto = dirname(__DIR__, 3) . '/vendor/autoload.php';
            if (!is_file($auto)) throw new RuntimeException('brak vendor/autoload.php');
            require_once $auto;
            $tmp = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
            if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 10, 'margin_bottom' => 13,
                                    'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp, 'shrink_tables_to_fit' => 1]);
            $mpdf->SetTitle($title);
            $mpdf->SetAuthor(ldOrgName());
            $mpdf->SetHTMLFooter('<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr><td width="40%"><b>' . htmlspecialchars($r['formEnum']->value) . '</b> — '
                . htmlspecialchars($r['formEnum']->title()) . '</td><td width="35%" style="text-align:center">' . htmlspecialchars((string)$r['number']) . '</td><td width="25%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>');
            $mpdf->WriteHTML($html);
            return ['bytes' => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN), 'mime' => 'application/pdf', 'ext' => 'pdf'];
        } catch (\Throwable $e) {
            error_log('dop pdf fallback html: ' . $e->getMessage());
            return ['bytes' => '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head><body>' . $html . '</body></html>', 'mime' => 'text/html', 'ext' => 'html'];
        }
    }

    /** Pusty wzór do druku (wersja papierowa). */
    public static function blank(DopForm $f): array
    {
        return self::render(['formEnum' => $f, 'statusEnum' => DopStatus::Signing, 'number' => '', 'd' => [], 'stepsData' => [], 'place' => '', 'created_at' => '',
                             'due_on' => '', 'signed_at' => '', 'signed_name' => '', 'signed_ip' => '', 'sign_mode' => 'paper']);
    }
}
