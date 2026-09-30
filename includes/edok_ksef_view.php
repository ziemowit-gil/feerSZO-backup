<?php
/**
 * includes/edok_ksef_view.php — wizualizacja faktury ustrukturyzowanej KSeF (FA(3)) z pliku XML
 * jako HTML do wydruków EODoK (mPDF w dokumencie końcowym / PDF miesięcznym oraz edok/print.php).
 * To tylko czytelny podgląd danych z XML — oryginałem faktury pozostaje plik XML w KSeF.
 */

/** Czy treść wygląda na fakturę FA (korzeń „Faktura", także z prefiksem przestrzeni nazw). */
function edok_ksef_is_invoice_xml(string $xml): bool {
    return $xml !== '' && strlen($xml) < 5 * 1024 * 1024 && preg_match('/<(?:\w+:)?Faktura[\s>]/', substr($xml, 0, 4096)) === 1;
}

function edok_ksef_css(): string {
    return '
  .ksef { font-family: dejavusans, Arial, sans-serif; font-size: 9.5px; color: #000; }
  .ksef h1 { font-size: 15px; margin: 0 0 2px; } .ksef .meta { font-size: 9px; color: #333; margin-bottom: 8px; }
  .ksef h2 { font-size: 9.5px; text-transform: uppercase; border-bottom: 1px solid #000; margin: 10px 0 3px; padding-bottom: 1px; }
  .ksef table { width: 100%; border-collapse: collapse; } .ksef td, .ksef th { padding: 2px 4px; vertical-align: top; }
  .ksef .grid td { border: 1px solid #999; } .ksef .grid th { border: 1px solid #999; background: #eee; font-size: 8.5px; text-align: left; }
  .ksef .r { text-align: right; } .ksef .sum td { font-weight: bold; }
  .ksef .foot { margin-top: 12px; border-top: 1px solid #999; padding-top: 4px; font-size: 8px; color: #444; }
';
}

/**
 * @param string $xml       treść FA(3)
 * @param string $ksef_ref  numer/odnośnik KSeF, jeśli znany (do stopki)
 * @return string HTML (fragment) albo '' gdy XML nie jest fakturą
 */
function edok_ksef_visualization_html(string $xml, string $ksef_ref = ''): string {
    if (!edok_ksef_is_invoice_xml($xml)) return '';
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    if ($sx === false) return '';
    $q = function (string $path, ?SimpleXMLElement $ctx = null) use ($sx): array { return ($ctx ?? $sx)->xpath($path) ?: []; };
    $one = function (string $path, ?SimpleXMLElement $ctx = null) use ($q): string { $n = $q($path, $ctx); return $n ? trim((string)$n[0]) : ''; };
    $ln = fn(string $n) => '*[local-name()="' . $n . '"]';
    $fa = '//' . $ln('Fa');
    $money = fn(string $v) => $v === '' ? '' : number_format((float)$v, 2, ',', ' ');
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $party = function (string $tag) use ($q, $one, $ln, $e): string {
        $p = $q('//' . $ln($tag));
        if (!$p) return '<i>brak danych</i>';
        $p = $p[0];
        $nip = $one('.//' . $ln('NIP'), $p); $nazwa = $one('.//' . $ln('Nazwa'), $p) ?: $one('.//' . $ln('PelnaNazwa'), $p);
        $l1 = $one('.//' . $ln('AdresL1'), $p); $l2 = $one('.//' . $ln('AdresL2'), $p); $kraj = $one('.//' . $ln('KodKraju'), $p);
        $h = '<b>' . $e($nazwa) . '</b>';
        if ($nip !== '') $h .= '<br>NIP: ' . $e($nip);
        if ($l1 !== '') $h .= '<br>' . $e($l1);
        if ($l2 !== '') $h .= '<br>' . $e($l2);
        if ($kraj !== '' && $kraj !== 'PL') $h .= '<br>' . $e($kraj);
        return $h;
    };

    $typ = $one($fa . '/' . $ln('RodzajFaktury'));
    $typ_l = ['VAT' => 'Faktura VAT', 'KOR' => 'Faktura korygująca', 'ZAL' => 'Faktura zaliczkowa', 'ROZ' => 'Faktura rozliczeniowa',
              'UPR' => 'Faktura uproszczona', 'KOR_ZAL' => 'Faktura korygująca zaliczkową', 'KOR_ROZ' => 'Faktura korygująca rozliczeniową'][$typ] ?? 'Faktura';
    $waluta = $one($fa . '/' . $ln('KodWaluty')) ?: 'PLN';

    $h = '<div class="ksef"><h1>' . $e($typ_l) . ' nr ' . $e($one($fa . '/' . $ln('P_2'))) . '</h1>';
    $h .= '<div class="meta">Wizualizacja faktury ustrukturyzowanej KSeF'
        . ($ksef_ref !== '' ? ' · nr referencyjny KSeF: ' . $e($ksef_ref) : '') . '</div>';
    $h .= '<table><tr><td style="width:33%"><b>Data wystawienia:</b> ' . $e($one($fa . '/' . $ln('P_1'))) . '</td>'
        . '<td style="width:33%"><b>Miejsce:</b> ' . $e($one($fa . '/' . $ln('P_1M'))) . '</td>'
        . '<td><b>Data sprzedaży/dostawy:</b> ' . $e($one($fa . '/' . $ln('P_6')) ?: $one($fa . '/' . $ln('OkresFa') . '/' . $ln('P_6_Od'))) . '</td></tr></table>';

    $h .= '<table style="margin-top:6px"><tr><td style="width:50%;border:1px solid #999"><b>Sprzedawca</b><br>' . $party('Podmiot1')
        . '</td><td style="border:1px solid #999"><b>Nabywca</b><br>' . $party('Podmiot2') . '</td></tr></table>';

    // Faktura korygująca
    $nrk = $one($fa . '/' . $ln('DaneFaKorygowanej') . '/' . $ln('NrFaKorygowanej'));
    if ($nrk !== '') {
        $h .= '<p><b>Korygowana faktura:</b> ' . $e($nrk) . ' z dnia ' . $e($one($fa . '/' . $ln('DaneFaKorygowanej') . '/' . $ln('DataWystFaKorygowanej')))
            . ($one($fa . '/' . $ln('PrzyczynaKorekty')) !== '' ? '<br><b>Przyczyna korekty:</b> ' . $e($one($fa . '/' . $ln('PrzyczynaKorekty'))) : '') . '</p>';
    }

    // Pozycje
    $h .= '<h2>Pozycje</h2><table class="grid"><tr><th>Lp.</th><th>Nazwa towaru lub usługi</th><th>Jm.</th><th class="r">Ilość</th><th class="r">Cena netto</th><th class="r">Wartość netto</th><th class="r">Stawka</th></tr>';
    foreach ($q($fa . '/' . $ln('FaWiersz')) as $w) {
        $stawka = $one($ln('P_12'), $w);
        $h .= '<tr><td>' . $e($one($ln('NrWierszaFa'), $w)) . '</td><td>' . $e($one($ln('P_7'), $w)) . '</td><td>' . $e($one($ln('P_8A'), $w))
            . '</td><td class="r">' . $e($one($ln('P_8B'), $w)) . '</td><td class="r">' . $money($one($ln('P_9A'), $w) ?: $one($ln('P_9B'), $w))
            . '</td><td class="r">' . $money($one($ln('P_11'), $w) ?: $one($ln('P_11A'), $w)) . '</td><td class="r">' . $e($stawka !== '' && is_numeric($stawka) ? $stawka . '%' : $stawka) . '</td></tr>';
    }
    $h .= '</table>';

    // Podsumowanie VAT
    $rows = [['23%', 'P_13_1', 'P_14_1'], ['8%', 'P_13_2', 'P_14_2'], ['5%', 'P_13_3', 'P_14_3'], ['taksówki', 'P_13_4', 'P_14_4'],
             ['0% (kraj)', 'P_13_6_1', ''], ['0% (WDT)', 'P_13_6_2', ''], ['0% (eksport)', 'P_13_6_3', ''], ['zw.', 'P_13_7', ''],
             ['np. (poza kraj)', 'P_13_8', ''], ['np. (art. 100)', 'P_13_9', ''], ['odwrotne obciążenie', 'P_13_10', ''], ['marża', 'P_13_11', '']];
    $h .= '<h2>Podsumowanie wg stawek</h2><table class="grid"><tr><th>Stawka</th><th class="r">Netto</th><th class="r">VAT</th></tr>';
    foreach ($rows as [$lbl, $n, $v]) {
        $nv = $one($fa . '/' . $ln($n)); if ($nv === '' || (float)$nv == 0.0) continue;
        $h .= '<tr><td>' . $e($lbl) . '</td><td class="r">' . $money($nv) . '</td><td class="r">' . ($v !== '' ? $money($one($fa . '/' . $ln($v))) : '—') . '</td></tr>';
    }
    $h .= '<tr class="sum"><td>Razem do zapłaty (brutto)</td><td class="r" colspan="2">' . $money($one($fa . '/' . $ln('P_15'))) . ' ' . $e($waluta) . '</td></tr></table>';

    // Płatność
    $pl = $fa . '/' . $ln('Platnosc');
    $forma = ['1' => 'gotówka', '2' => 'karta', '3' => 'bon', '4' => 'czek', '5' => 'kredyt', '6' => 'przelew', '7' => 'płatność mobilna'][$one($pl . '/' . $ln('FormaPlatnosci'))] ?? '';
    $pay = [];
    if ($forma !== '') $pay['Forma płatności'] = $forma;
    $termin = $one($pl . '/' . $ln('TerminPlatnosci') . '/' . $ln('Termin')); if ($termin !== '') $pay['Termin płatności'] = $termin;
    $nrb = preg_replace('/\s+/', '', $one($pl . '/' . $ln('RachunekBankowy') . '/' . $ln('NrRB'))); if ($nrb !== '') $pay['Rachunek bankowy'] = trim(chunk_split($nrb, 4, ' '));
    if ($one($pl . '/' . $ln('Zaplacono')) === '1') $pay['Zapłacono'] = 'tak' . ($one($pl . '/' . $ln('DataZaplaty')) !== '' ? ', ' . $one($pl . '/' . $ln('DataZaplaty')) : '');
    if ($pay) {
        $h .= '<h2>Płatność</h2><table>';
        foreach ($pay as $k => $v) $h .= '<tr><td style="width:28%"><b>' . $e($k) . '</b></td><td>' . $e((string)$v) . '</td></tr>';
        $h .= '</table>';
    }

    // Dodatkowe opisy / uwagi
    $extra = [];
    foreach ($q($fa . '/' . $ln('DodatkowyOpis')) as $d) $extra[] = trim($one($ln('Klucz'), $d) . ': ' . $one($ln('Wartosc'), $d), ': ');
    if ($extra) $h .= '<h2>Dodatkowe informacje</h2><p>' . implode('<br>', array_map($e, $extra)) . '</p>';

    $h .= '<div class="foot">Wizualizacja wygenerowana z pliku XML faktury ustrukturyzowanej (FA) — nie jest dokumentem prawnie wiążącym; '
        . 'oryginałem jest faktura w Krajowym Systemie e-Faktur.</div></div>';
    return $h;
}

/** Wizualizacja dla dokumentu EODoK, którego plik źródłowy to XML (z KSeF); '' gdy dokument nie ma takiego pliku. */
function edok_ksef_visualization_for_doc(array $doc): string {
    $rel = (string)($doc['file_path'] ?? '');
    if ($rel === '' || strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'xml') return '';
    $abs = UPLOAD_DIR . ltrim($rel, '/');
    if (!is_file($abs)) return '';
    return edok_ksef_visualization_html((string)file_get_contents($abs), (string)($doc['ksef_reference'] ?? ''));
}
