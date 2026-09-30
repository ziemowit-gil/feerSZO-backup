<?php
/**
 * EODoK — zestawienie dla księgowego: zaakceptowane wydatki i przychody do zaksięgowania
 * w wybranym okresie, jako PDF (mPDF) i Excel (.xlsx, własny minimalny zapis — w projekcie
 * nie ma biblioteki arkuszy). Okres wg daty wpływu dokumentu (jak tabela analityczna).
 */
require_once __DIR__ . '/edok_bank.php';

/** Zaakceptowane dokumenty okresu (bez proform i dokumentów testowych), z transakcją bankową, jeśli przypisana. */
function edok_ksiegowy_rows(string $od, string $do, string $kierunek): array {
    $rows = db_all(
        "SELECT * FROM edok_documents
         WHERE status = 'zaakceptowany' AND COALESCE(kierunek,'wydatek') = ? AND typ_dokumentu <> 'proforma'
           AND number NOT LIKE 'EODoK-TEST%'
           AND substr(COALESCE(data_wplywu, data_wystawienia, created_at), 1, 10) BETWEEN ? AND ?
         ORDER BY COALESCE(data_wplywu, data_wystawienia, created_at), id",
        [$kierunek, $od, $do]
    );
    $out = [];
    foreach ($rows as $d) {
        $bank = edok_bank_for_doc((int)$d['id']);
        $out[] = [
            'id'       => (int)$d['id'],
            'przelew'  => $kierunek === 'wydatek' && edok_przelew_exportable($d), // czeka na przelew (nieopłacony, ma rachunek)
            'number'   => $d['number'],
            'data'     => substr((string)($d['data_wplywu'] ?: $d['data_wystawienia'] ?: $d['created_at']), 0, 10),
            'wystaw'   => (string)$d['data_wystawienia'],
            'typ'      => EDOK_TYPES[$d['typ_dokumentu']] ?? $d['typ_dokumentu'],
            'nr'       => $d['nr_faktury'],
            'kontr'    => $d['kontrahent_nazwa'] . ($kierunek === 'przychod' && $d['zrodlo_przychodu'] ? ' — ' . $d['zrodlo_przychodu'] : ''),
            'nip'      => $d['kontrahent_nip'],
            'netto'    => _edok_kwota_float((string)$d['kwota_netto']),
            'vat'      => _edok_kwota_float((string)$d['kwota_vat']),
            'brutto'   => _edok_kwota_float((string)$d['kwota_brutto']),
            'waluta'   => $d['waluta'] ?: 'PLN',
            'klasyf'   => edok_transfer_label_klasyfikacja($d['rodzaj_dzialalnosci'], $d['projekt']),
            'mpk'      => $d['mpk'],
            'opis'     => $d['description'],
            'platnosc' => $kierunek === 'wydatek' ? edok_ksiegowy_platnosc($d, $bank) : '',
            'rejestr'  => edok_ksiegowy_rejestr($d, $kierunek),
            'sprzedaz' => (string)$d['data_sprzedazy'],
            'termin'   => (string)$d['termin_platnosci'],
            'rachunek' => $kierunek === 'wydatek' ? (edok_zaplata_do_zwrotu($d) ? (string)$d['zwrot_rachunek'] : (string)$d['rachunek_bankowy']) : '',
            'wskazowka' => edok_ksiegowy_wskazowka($d, $kierunek, $bank),
            'bank'     => $bank ? implode('; ', array_map(fn($b) => date_pl($b['data_waluty']) . ' ' . number_format((float)$b['kwota'], 2, ',', ' '), $bank)) : '',
        ];
    }
    return $out;
}

function edok_ksiegowy_platnosc(array $d, array $bank): string {
    if (edok_zaplata_do_zwrotu($d)) return 'do zwrotu kosztów: ' . ($d['zwrot_osoba'] ?: 'osoba prywatna');
    if (!empty($d['zaplacono_przed'])) return 'zapłacona przed akceptacją (' . (edok_zaplata_opis($d) ?: 'tak') . ')';
    return match ($d['status_platnosci'] ?: 'nowy') { 'oplacony' => 'opłacona', 'zlecony' => 'przekazana do banku', 'anulowany' => 'anulowana', default => 'do zapłaty' };
}

/** Do którego rejestru wpisać dokument (wg typu i obecności VAT). */
function edok_ksiegowy_rejestr(array $d, string $kierunek): string {
    $vat = abs(_edok_kwota_float((string)$d['kwota_vat'])) >= 0.005 || in_array((string)$d['stawka_vat'], ['23', '8', '5', '0', 'zw', 'np'], true);
    if ($kierunek === 'przychod') {
        return in_array($d['typ_dokumentu'], ['faktura_sprzedazy', 'korekta_sprzedazy'], true) ? 'Rejestr sprzedaży VAT' : 'Przychód bez rejestru VAT';
    }
    return match ($d['typ_dokumentu']) {
        'faktura_vat', 'faktura_korygujaca' => $vat ? 'Rejestr zakupów VAT' : 'Zakup bez VAT',
        'rachunek'      => 'Rachunek (bez rejestru VAT)',
        'lista_plac'    => 'Lista płac / wynagrodzenia',
        'nota_ksiegowa' => 'Nota księgowa',
        default         => 'Ewidencja pozostałych kosztów',
    };
}

/** Jednozdaniowa wskazówka ujęcia: co, na jaką działalność/MPK, jak rozliczone (zapłata/rozrachunek). */
function edok_ksiegowy_wskazowka(array $d, string $kierunek, array $bank): string {
    $klas = edok_transfer_label_klasyfikacja($d['rodzaj_dzialalnosci'], $d['projekt']);
    $parts = [($kierunek === 'przychod' ? 'Przychód: ' : 'Koszt: ') . ($klas !== '' && $klas !== '—' ? $klas : 'brak klasyfikacji') . ($d['mpk'] !== '' ? ', MPK ' . $d['mpk'] : '')];
    if ($kierunek === 'wydatek') {
        if (edok_zaplata_do_zwrotu($d)) $parts[] = 'rozrachunek z osobą (zwrot kosztów: ' . ($d['zwrot_osoba'] ?: '—') . ')';
        elseif (!empty($d['zaplacono_przed'])) $parts[] = 'zapłacona przed księgowaniem — ' . (edok_zaplata_opis($d) ?: 'zapłata');
        elseif (($d['status_platnosci'] ?: 'nowy') === 'oplacony') $parts[] = 'opłacona' . ($bank ? ' (potwierdzone wyciągiem)' : '');
        else $parts[] = 'zobowiązanie wobec kontrahenta, termin ' . ($d['termin_platnosci'] ? date_pl($d['termin_platnosci']) : 'brak');
    } elseif ($bank) {
        $parts[] = 'wpływ potwierdzony wyciągiem';
    }
    return implode('; ', $parts);
}

const EDOK_KSIEGOWY_COLS = [
    'number' => 'Nr EODoK', 'data' => 'Data', 'typ' => 'Typ', 'nr' => 'Nr dokumentu', 'kontr' => 'Kontrahent', 'nip' => 'NIP',
    'netto' => 'Netto', 'vat' => 'VAT', 'brutto' => 'Brutto', 'waluta' => 'Waluta', 'klasyf' => 'Klasyfikacja', 'mpk' => 'MPK',
    'opis' => 'Opis', 'platnosc' => 'Płatność', 'bank' => 'Transakcja bankowa',
    'rejestr' => 'Rejestr', 'sprzedaz' => 'Data sprzedaży', 'termin' => 'Termin płatności', 'rachunek' => 'Rachunek do zapłaty/zwrotu',
    'wskazowka' => 'Jak zaksięgować',
];

// ── Excel ──────────────────────────────────────────────────────────────────────

function _edok_xlsx_col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function _edok_xml(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/**
 * Minimalny .xlsx. $sheets: [nazwa => ['cols'=>[nagłówki], 'rows'=>[[wartości]], 'num'=>[indeksy kolumn kwotowych], 'sum'=>bool]].
 * Zwraca zawartość pliku (string).
 */
function edok_xlsx(array $sheets): string {
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $n = 0;
    foreach ($sheets as $name => $sh) {
        $n++;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . _edok_xml(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $name), 0, 31)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $num = array_flip($sh['num'] ?? []);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach ($sh['cols'] as $i => $_) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (isset($num[$i]) ? 13 : ($sh['widths'][$i] ?? 18)) . '" customWidth="1"/>';
        $xml .= '</cols><sheetData><row r="1">';
        foreach ($sh['cols'] as $i => $c) $xml .= '<c r="' . _edok_xlsx_col($i) . '1" s="1" t="inlineStr"><is><t>' . _edok_xml($c) . '</t></is></c>';
        $xml .= '</row>';
        $r = 1; $sum = [];
        foreach ($sh['rows'] as $row) {
            $r++; $xml .= '<row r="' . $r . '">';
            foreach ($row as $i => $v) {
                $ref = _edok_xlsx_col($i) . $r;
                if (isset($num[$i])) { $xml .= '<c r="' . $ref . '" s="2"><v>' . (float)$v . '</v></c>'; $sum[$i] = ($sum[$i] ?? 0) + (float)$v; }
                else $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . _edok_xml((string)$v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        if (!empty($sh['sum']) && $sum) {
            $r++; $xml .= '<row r="' . $r . '"><c r="A' . $r . '" s="1" t="inlineStr"><is><t>Razem</t></is></c>';
            foreach ($sum as $i => $v) $xml .= '<c r="' . _edok_xlsx_col($i) . $r . '" s="3"><v>' . $v . '</v></c>';
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        $z->addFromString('xl/worksheets/sheet' . $n . '.xml', $xml);
    }
    $z->addFromString('[Content_Types].xml', $ct . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', $rels . '<Relationship Id="rId99" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    // style: 0 domyślny, 1 nagłówek (pogrubiony, szare tło), 2 kwota 0,00, 3 suma (pogrubiona, 0,00)
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="4"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" applyNumberFormat="1" applyFont="1"/></cellXfs></styleSheet>');
    $z->close();
    $bin = (string)file_get_contents($tmp); @unlink($tmp);
    return $bin;
}

/** Arkusz z wierszy edok_ksiegowy_rows(). */
function edok_ksiegowy_sheet(array $rows, bool $wydatek): array {
    $keys = array_keys(EDOK_KSIEGOWY_COLS);
    if (!$wydatek) $keys = array_values(array_diff($keys, ['platnosc']));
    $cols = array_map(fn($k) => EDOK_KSIEGOWY_COLS[$k], $keys);
    $num = [array_search('netto', $keys), array_search('vat', $keys), array_search('brutto', $keys)];
    return ['cols' => $cols, 'num' => $num, 'sum' => true, 'widths' => array_map(fn($k) => in_array($k, ['kontr', 'opis', 'klasyf', 'bank', 'platnosc', 'wskazowka', 'rachunek']) ? 34 : 16, $keys),
        'rows' => array_map(fn($r) => array_map(fn($k) => $r[$k], $keys), $rows)];
}

// ── PDF ────────────────────────────────────────────────────────────────────────

function edok_ksiegowy_pdf(string $od, string $do, array $wydatki, array $przychody): string {
    $fmt = fn($v) => number_format((float)$v, 2, ',', ' ');
    $table = function (string $title, array $rows, bool $wydatek) use ($fmt) {
        $h = '<h2>' . h($title) . ' (' . count($rows) . ')</h2>';
        if (!$rows) return $h . '<p>Brak dokumentów w okresie.</p>';
        $h .= '<table><thead><tr><th>Nr EODoK</th><th>Data</th><th>Dokument</th><th>Kontrahent</th><th class="r">Netto</th><th class="r">VAT</th><th class="r">Brutto</th><th>Rejestr / klasyfikacja</th>'
            . '<th>Jak zaksięgować</th>' . ($wydatek ? '<th>Płatność</th>' : '') . '</tr></thead><tbody>';
        $sn = $sv = $sb = 0;
        foreach ($rows as $r) {
            $sn += $r['netto']; $sv += $r['vat']; $sb += $r['brutto'];
            $h .= '<tr><td>' . h($r['number']) . '</td><td>' . h(date_pl($r['data'])) . '</td><td>' . h($r['typ']) . '<br>' . h($r['nr']) . '</td><td>' . h($r['kontr']) . ($r['nip'] ? '<br>NIP ' . h($r['nip']) : '') . '</td>'
                . '<td class="r">' . $fmt($r['netto']) . '</td><td class="r">' . $fmt($r['vat']) . '</td><td class="r">' . $fmt($r['brutto']) . ' ' . h($r['waluta']) . '</td><td><strong>' . h($r['rejestr']) . '</strong><br>' . h($r['klasyf']) . ($r['mpk'] ? '<br>MPK: ' . h($r['mpk']) : '') . ($r['sprzedaz'] ? '<br>sprzedaż: ' . h(date_pl($r['sprzedaz'])) : '') . '</td>'
                . '<td>' . h($r['wskazowka']) . ($r['rachunek'] ? '<br>rachunek: ' . h($r['rachunek']) : '') . '</td>'
                . ($wydatek ? '<td>' . h($r['platnosc']) . ($r['bank'] ? '<br>bank: ' . h($r['bank']) : '') . '</td>' : '') . '</tr>';
        }
        return $h . '<tr class="sum"><td colspan="4">Razem</td><td class="r">' . $fmt($sn) . '</td><td class="r">' . $fmt($sv) . '</td><td class="r">' . $fmt($sb) . '</td><td colspan="' . ($wydatek ? 3 : 2) . '"></td></tr></tbody></table>';
    };
    $html = '<style>body{font-family:dejavusans;font-size:8pt} h1{font-size:13pt;margin:0} h2{font-size:11pt;margin:14px 0 4px} table{border-collapse:collapse;width:100%} th,td{border:.5px solid #999;padding:2px 4px;vertical-align:top} th{background:#e5e7eb} .r{text-align:right;white-space:nowrap} .sum td{font-weight:bold;background:#f3f4f6}</style>'
        . '<h1>EODoK — dokumenty do zaksięgowania</h1><p>Okres: ' . h(date_pl($od)) . ' – ' . h(date_pl($do)) . ' · wygenerowano ' . date('d.m.Y H:i') . '</p>'
        . $table('Wydatki', $wydatki, true) . $table('Przychody', $przychody, false);
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $tmp_dir = rtrim(UPLOAD_DIR, '/') . '/mpdf_tmp';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 8, 'margin_bottom' => 8, 'default_font' => 'dejavusans', 'tempDir' => $tmp_dir]);
    $mpdf->SetTitle('EODoK — do zaksięgowania ' . $od . ' – ' . $do);
    $mpdf->WriteHTML($html);
    return (string)$mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}
