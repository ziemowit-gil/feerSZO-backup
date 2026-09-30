<?php
/**
 * includes/edok_ksef_view.php — wizualizacja faktury ustrukturyzowanej KSeF (FA(3)) z pliku XML
 * w układzie oficjalnej wizualizacji KSeF („Krajowy System e-Faktur"): nagłówek z numerem faktury,
 * Sprzedawca / Nabywca, Szczegóły, Pozycje, Podsumowanie stawek, Płatność, rachunek, QR weryfikacyjny.
 * HTML dla mPDF (dokument końcowy / PDF miesięczny) i edok/print.php. To podgląd danych z XML —
 * oryginałem faktury jest plik XML w KSeF.
 */

/** Czy treść wygląda na fakturę FA (korzeń „Faktura", także z prefiksem przestrzeni nazw). */
function edok_ksef_is_invoice_xml(string $xml): bool {
    return $xml !== '' && strlen($xml) < 5 * 1024 * 1024 && preg_match('/<(?:\w+:)?Faktura[\s>]/', substr($xml, 0, 4096)) === 1;
}

function edok_ksef_css(): string {
    return '
  .ksef { font-family: dejavusans, Arial, sans-serif; font-size: 8.5px; color: #222; }
  .ksef .logo { font-size: 17px; } .ksef .logo b { color: #d0021b; font-weight: normal; }
  .ksef .r { text-align: right; } .ksef .nr { font-size: 19px; font-weight: bold; }
  .ksef .muted { color: #555; } .ksef .b { font-weight: bold; }
  .ksef hr { border: 0; border-top: 1px solid #bbb; margin: 10px 0 8px; }
  .ksef h2 { font-size: 11px; font-weight: normal; margin: 0 0 5px; }
  .ksef table { width: 100%; border-collapse: collapse; } .ksef td, .ksef th { vertical-align: top; padding: 0; font-size: 8.5px; }
  .ksef .sec td { padding-right: 14px; font-size: 8.5px; }
  .ksef .url { font-size: 7px; color: #0645ad; word-break: break-all; } .ksef .lbl { font-weight: bold; }
  .ksef .grid th { background: #f2f2f2; border: 1px solid #aaa; padding: 3px 4px; font-size: 7.5px; text-align: left; font-weight: bold; }
  .ksef .grid td { border: 1px solid #aaa; padding: 3px 4px; font-size: 8px; }
  .ksef .grid .num { text-align: right; }
  .ksef .total { text-align: right; font-size: 11px; margin: 6px 0 8px; }
  .ksef .addr { margin-top: 5px; } .ksef .gap { height: 4px; }
  .ksef .foot { font-size: 7.5px; margin-top: 12px; color: #444; }
';
}

/** Link i numer do kodu QR weryfikacji faktury w KSeF: NIP sprzedawcy / data wystawienia (dd-mm-rrrr) / SHA-256 pliku XML (base64url). */
function edok_ksef_verify_url(string $xml, string $nip, string $issue_date): string {
    if ($nip === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $issue_date, $m)) return '';
    $hash = rtrim(strtr(base64_encode(hash('sha256', $xml, true)), '+/', '-_'), '=');
    return "https://qr.ksef.mf.gov.pl/invoice/{$nip}/{$m[3]}-{$m[2]}-{$m[1]}/{$hash}";
}

/**
 * @param string $xml       treść FA(3) (dokładnie w postaci z KSeF — od niej liczony jest skrót do linku QR)
 * @param string $ksef_ref  numer KSeF, jeśli znany
 * @param bool   $ksef      false = faktura sprzedaży wczytana z pliku XML: bez jakichkolwiek dopisków o KSeF (nagłówek, numer, QR, stopka)
 * @return string HTML (fragment) albo '' gdy XML nie jest fakturą
 */
function edok_ksef_visualization_html(string $xml, string $ksef_ref = '', bool $ksef = true): string {
    if (!edok_ksef_is_invoice_xml($xml)) return '';
    if (!$ksef) $ksef_ref = '';
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    if ($sx === false) return '';
    $ln = fn(string $n) => '*[local-name()="' . $n . '"]';
    $q = fn(string $path, ?SimpleXMLElement $ctx = null): array => ($ctx ?? $sx)->xpath($path) ?: [];
    $one = function (string $path, ?SimpleXMLElement $ctx = null) use ($q): string { $n = $q($path, $ctx); return $n ? trim((string)$n[0]) : ''; };
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $money = fn(string $v) => $v === '' ? '' : number_format((float)$v, 2, ',', '');
    $fa = '//' . $ln('Fa');
    $dt = fn(string $d) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "{$m[3]}.{$m[2]}.{$m[1]}" : $d;

    $typ = $one($fa . '/' . $ln('RodzajFaktury'));
    $typ_l = ['VAT' => 'Faktura podstawowa', 'KOR' => 'Faktura korygująca', 'ZAL' => 'Faktura zaliczkowa', 'ROZ' => 'Faktura rozliczeniowa',
              'UPR' => 'Faktura uproszczona', 'KOR_ZAL' => 'Faktura korygująca fakturę zaliczkową', 'KOR_ROZ' => 'Faktura korygująca fakturę rozliczeniową'][$typ] ?? 'Faktura';
    $waluta = $one($fa . '/' . $ln('KodWaluty')) ?: 'PLN';
    $nr = $one($fa . '/' . $ln('P_2'));
    $issue = $one($fa . '/' . $ln('P_1'));
    $seller_nip = preg_replace('/\D/', '', $one('//' . $ln('Podmiot1') . '//' . $ln('NIP')));

    // ── Nagłówek ──
    $h = '<div class="ksef"><table><tr><td class="logo">' . ($ksef ? 'Krajowy System <b>e-</b>Faktur' : $e($typ_l)) . '</td>'
       . '<td class="r"><span class="muted">Numer Faktury:</span><br><span class="nr">' . $e($nr) . '</span><br><span class="muted">' . ($ksef ? $e($typ_l) : '') . '</span>'
       . ($ksef_ref !== '' ? '<br><span class="b">Numer KSeF:</span> ' . $e($ksef_ref) : '') . '</td></tr></table><hr>';

    // ── Strona (Sprzedawca / Nabywca) ──
    $party = function (string $tag, bool $buyer) use ($q, $one, $ln, $e): string {
        $p = $q('//' . $ln($tag));
        if (!$p) return '';
        $p = $p[0];
        $lines = [];
        $pref = $one('.//' . $ln('PrefiksPodatnika'), $p); if ($pref !== '') $lines[] = '<span class="lbl">Prefiks VAT:</span> ' . $e($pref);
        $nip = $one('.//' . $ln('NIP'), $p);
        if ($nip !== '') $lines[] = '<span class="lbl">NIP:</span> ' . $e($nip);
        elseif ($one('.//' . $ln('NrVatUE'), $p) !== '') $lines[] = '<span class="lbl">Numer VAT UE:</span> ' . $e(trim($one('.//' . $ln('KodUE'), $p) . ' ' . $one('.//' . $ln('NrVatUE'), $p)));
        elseif ($one('.//' . $ln('NrID'), $p) !== '') $lines[] = '<span class="lbl">Identyfikator:</span> ' . $e($one('.//' . $ln('NrID'), $p));
        $nm = $one('.//' . $ln('Nazwa'), $p) ?: $one('.//' . $ln('PelnaNazwa'), $p);
        if ($nm !== '') $lines[] = '<span class="lbl">Nazwa:</span> ' . $e($nm);
        $h = implode('<br>', $lines);
        $l1 = $one('.//' . $ln('AdresL1'), $p); $l2 = $one('.//' . $ln('AdresL2'), $p); $kraj = $one('.//' . $ln('KodKraju'), $p);
        if ($l1 !== '' || $l2 !== '') {
            $kraj_l = ['PL' => 'Polska', 'DE' => 'Niemcy', 'CZ' => 'Czechy', 'SK' => 'Słowacja', 'GB' => 'Wielka Brytania', 'US' => 'Stany Zjednoczone'][$kraj] ?? $kraj;
            $h .= '<div class="addr"><span class="lbl">Adres</span><br>' . $e($l2) . ($l2 !== '' && $l1 !== '' ? '<br>' : '') . $e($l1) . ($kraj_l !== '' ? '<br>' . $e($kraj_l) : '') . '</div>';
        }
        $mail = $one('.//' . $ln('Email'), $p); $tel = $one('.//' . $ln('Telefon'), $p);
        if ($mail !== '' || $tel !== '') $h .= '<div class="addr"><span class="lbl">Dane kontaktowe</span><br>' . ($mail !== '' ? '<span class="lbl">E-mail:</span> ' . $e($mail) : '') . ($mail !== '' && $tel !== '' ? '<br>' : '') . ($tel !== '' ? '<span class="lbl">Tel.:</span> ' . $e($tel) : '') . '</div>';
        if ($buyer) {
            $jst = $one('.//' . $ln('JST'), $p) === '1' ? 'TAK' : 'NIE'; $gv = $one('.//' . $ln('GV'), $p) === '1' ? 'TAK' : 'NIE';
            $h .= '<div class="addr">Faktura dotyczy jednostki podrzędnej JST: ' . $jst . '<br>Faktura dotyczy członka grupy GV: ' . $gv . '</div>';
        }
        return $h;
    };
    $h .= '<table class="sec"><tr><td style="width:50%"><h2>Sprzedawca</h2>' . $party('Podmiot1', false) . '</td><td><h2>Nabywca</h2>' . $party('Podmiot2', true) . '</td></tr></table><hr>';

    // ── Szczegóły ──
    $sprz = $one($fa . '/' . $ln('P_6')) ?: trim($one($fa . '/' . $ln('OkresFa') . '/' . $ln('P_6_Od')) . ' – ' . $one($fa . '/' . $ln('OkresFa') . '/' . $ln('P_6_Do')), ' –');
    $h .= '<h2>Szczegóły</h2><table class="sec"><tr><td style="width:50%">Data wystawienia, z zastrzeżeniem art. 106na ust. 1 ustawy: ' . $e($dt($issue))
        . ($sprz !== '' ? '<br>Data dokonania lub zakończenia dostawy towarów lub wykonania usługi: ' . $e($dt($sprz)) : '') . '</td><td>'
        . ($one($fa . '/' . $ln('P_1M')) !== '' ? 'Miejsce wystawienia: ' . $e($one($fa . '/' . $ln('P_1M'))) . '<br>' : '') . 'Kod waluty: ' . $e($waluta) . '</td></tr></table>';

    $nrk = $one($fa . '/' . $ln('DaneFaKorygowanej') . '/' . $ln('NrFaKorygowanej'));
    if ($nrk !== '') {
        $h .= '<div class="gap"></div><span class="lbl">Korygowana faktura:</span> ' . $e($nrk) . ' z dnia ' . $e($dt($one($fa . '/' . $ln('DaneFaKorygowanej') . '/' . $ln('DataWystFaKorygowanej'))))
            . ($one($fa . '/' . $ln('PrzyczynaKorekty')) !== '' ? '<br><span class="lbl">Przyczyna korekty:</span> ' . $e($one($fa . '/' . $ln('PrzyczynaKorekty'))) : '');
    }
    $h .= '<hr>';

    // ── Pozycje ──
    $rows = $q($fa . '/' . $ln('FaWiersz'));
    $gross_prices = false; $gross_vals = false; $has_idx = false; $has_disc = false; $has_vat = false;
    foreach ($rows as $w) {
        if ($one($ln('P_9B'), $w) !== '') $gross_prices = true;
        if ($one($ln('P_11A'), $w) !== '' && $one($ln('P_11'), $w) === '') $gross_vals = true;
        if ($one($ln('Indeks'), $w) !== '') $has_idx = true;
        if ($one($ln('P_10'), $w) !== '') $has_disc = true;
        if ($one($ln('P_11Vat'), $w) !== '') $has_vat = true;
    }
    $h .= '<h2>Pozycje</h2><div>Faktura wystawiona w walucie ' . $e($waluta) . '</div><div class="gap"></div><table class="grid"><tr><th>Lp.</th><th>Nazwa towaru lub usługi</th><th>Cena jedn. '
        . ($gross_prices ? 'brutto' : 'netto') . '</th><th>Ilość</th><th>Miara</th>' . ($has_disc ? '<th>Rabat</th>' : '') . '<th>Stawka podatku</th><th>Wartość sprzedaży ' . ($gross_vals ? 'brutto' : 'netto') . '</th>'
        . ($has_vat ? '<th>Kwota VAT</th>' : '') . ($has_idx ? '<th>Indeks</th>' : '') . '</tr>';
    $gtins = [];
    foreach ($rows as $w) {
        $stawka = $one($ln('P_12'), $w);
        $h .= '<tr><td>' . $e($one($ln('NrWierszaFa'), $w)) . '</td><td>' . $e($one($ln('P_7'), $w)) . '</td><td class="num">' . $money($one($ln('P_9B'), $w) ?: $one($ln('P_9A'), $w))
            . '</td><td class="num">' . $e(rtrim(rtrim($one($ln('P_8B'), $w), '0'), '.,') !== '' ? str_replace('.', ',', $one($ln('P_8B'), $w)) : $one($ln('P_8B'), $w)) . '</td><td>' . $e($one($ln('P_8A'), $w)) . '</td>'
            . ($has_disc ? '<td class="num">' . $money($one($ln('P_10'), $w)) . '</td>' : '')
            . '<td>' . $e($stawka !== '' && is_numeric($stawka) ? $stawka . '%' : $stawka) . '</td><td class="num">' . $money($gross_vals ? $one($ln('P_11A'), $w) : ($one($ln('P_11'), $w) ?: $one($ln('P_11A'), $w))) . '</td>'
            . ($has_vat ? '<td class="num">' . $money($one($ln('P_11Vat'), $w)) . '</td>' : '') . ($has_idx ? '<td>' . $e($one($ln('Indeks'), $w)) . '</td>' : '') . '</tr>';
        if ($one($ln('GTIN'), $w) !== '') $gtins[$one($ln('NrWierszaFa'), $w)] = $one($ln('GTIN'), $w);
    }
    $h .= '</table>';
    if ($gtins) { $h .= '<div class="gap"></div><table class="grid"><tr><th style="width:8%">Lp.</th><th>GTIN</th></tr>'; foreach ($gtins as $lp => $g) $h .= '<tr><td>' . $e((string)$lp) . '</td><td>' . $e($g) . '</td></tr>'; $h .= '</table>'; }
    $h .= '<div class="total">Kwota należności ogółem: <span class="b">' . $money($one($fa . '/' . $ln('P_15'))) . ' ' . $e($waluta) . '</span></div>';

    // ── Podsumowanie stawek podatku ──
    $defs = [['23% lub 22%', 'P_13_1', 'P_14_1'], ['8% lub 7%', 'P_13_2', 'P_14_2'], ['5%', 'P_13_3', 'P_14_3'], ['taryfa taxi', 'P_13_4', 'P_14_4'],
             ['0% (kraj, bez WDT i eksportu)', 'P_13_6_1', ''], ['0% (WDT)', 'P_13_6_2', ''], ['0% (eksport)', 'P_13_6_3', ''], ['zwolnione od podatku', 'P_13_7', ''],
             ['nie podlega (poza terytorium kraju)', 'P_13_8', ''], ['nie podlega (art. 100 ust. 1 pkt 4)', 'P_13_9', ''], ['odwrotne obciążenie', 'P_13_10', ''], ['procedura marży', 'P_13_11', '']];
    $h .= '<h2>Podsumowanie stawek podatku</h2><table class="grid"><tr><th style="width:6%">Lp.</th><th>Stawka podatku</th><th>Kwota netto</th><th>Kwota podatku</th><th>Kwota brutto</th></tr>';
    $lp = 0;
    foreach ($defs as [$lbl, $n, $v]) {
        $nv = $one($fa . '/' . $ln($n)); if ($nv === '' || (float)$nv == 0.0) continue;
        $vv = $v !== '' ? (float)$one($fa . '/' . $ln($v)) : 0.0;
        $h .= '<tr><td>' . (++$lp) . '</td><td>' . $e($lbl) . '</td><td class="num">' . $money($nv) . '</td><td class="num">' . number_format($vv, 2, ',', '') . '</td><td class="num">' . number_format((float)$nv + $vv, 2, ',', '') . '</td></tr>';
    }
    $h .= '</table><hr>';

    // ── Adnotacje ──
    $an = $fa . '/' . $ln('Adnotacje');
    $ann = [];
    if ($one($an . '/' . $ln('P_16')) === '1') $ann[] = 'Metoda kasowa';
    if ($one($an . '/' . $ln('P_17')) === '1') $ann[] = 'Samofakturowanie';
    if ($one($an . '/' . $ln('P_18')) === '1') $ann[] = 'Odwrotne obciążenie';
    if ($one($an . '/' . $ln('P_18A')) === '1') $ann[] = 'Mechanizm podzielonej płatności';
    if ($one($an . '/' . $ln('Zwolnienie') . '/' . $ln('P_19')) === '1') {
        $ann[] = 'Dostawa towarów lub świadczenie usług zwolnionych od podatku. Podstawa: ' . ($one($an . '/' . $ln('Zwolnienie') . '/' . $ln('P_19A')) ?: $one($an . '/' . $ln('Zwolnienie') . '/' . $ln('P_19B')) ?: $one($an . '/' . $ln('Zwolnienie') . '/' . $ln('P_19C')));
    }
    if ($one($an . '/' . $ln('PMarzy') . '/' . $ln('P_PMarzy')) === '1') $ann[] = 'Procedura marży';
    if ($ann) { $h .= '<h2>Adnotacje</h2><div>' . implode('<br>', array_map($e, $ann)) . '</div><hr>'; }

    // ── Dodatkowe informacje ──
    $do = $q($fa . '/' . $ln('DodatkowyOpis'));
    if ($do) {
        $h .= '<h2>Dodatkowe informacje</h2><table class="grid"><tr><th style="width:6%">Lp.</th><th style="width:30%">Rodzaj informacji</th><th>Treść informacji</th></tr>';
        foreach ($do as $i => $d) $h .= '<tr><td>' . ($i + 1) . '</td><td>' . $e($one($ln('Klucz'), $d)) . '</td><td>' . $e($one($ln('Wartosc'), $d)) . '</td></tr>';
        $h .= '</table><hr>';
    }

    // ── Rozliczenie ──
    $dz = $one($fa . '/' . $ln('Rozliczenie') . '/' . $ln('DoZaplaty'));
    if ($dz !== '') $h .= '<h2>Rozliczenie</h2><div class="r"><span class="b">Do zapłaty:</span> ' . $money($dz) . ' ' . $e($waluta) . '</div><hr>';

    // ── Płatność ──
    $pl = $fa . '/' . $ln('Platnosc');
    if ($q($pl)) {
        $forma = ['1' => 'Gotówka', '2' => 'Karta', '3' => 'Bon', '4' => 'Czek', '5' => 'Kredyt', '6' => 'Przelew', '7' => 'Płatność mobilna'][$one($pl . '/' . $ln('FormaPlatnosci'))] ?? '';
        if ($forma === '' && $one($pl . '/' . $ln('PlatnoscInna')) === '1') $forma = 'Płatność inna';
        $zap = $one($pl . '/' . $ln('Zaplacono')) === '1';
        $h .= '<h2>Płatność</h2>';
        $h .= 'Informacja o płatności: ' . ($zap ? 'Zapłacono' : ($one($pl . '/' . $ln('ZnacznikZaplatyCzesciowej')) !== '' ? 'Zapłacono częściowo' : 'Brak zapłaty'));
        if ($one($pl . '/' . $ln('DataZaplaty')) !== '') $h .= '<br>Data zapłaty: ' . $e($dt($one($pl . '/' . $ln('DataZaplaty'))));
        if ($forma !== '') $h .= '<br>Forma płatności: ' . $e($forma);
        if ($one($pl . '/' . $ln('OpisPlatnosci')) !== '') $h .= '<br>Opis płatności innej: ' . $e($one($pl . '/' . $ln('OpisPlatnosci')));
        $terms = $q($pl . '/' . $ln('TerminPlatnosci'));
        if ($terms) {
            $h .= '<div class="gap"></div><table class="grid" style="width:55%"><tr><th>Termin płatności</th><th>Opis płatności</th></tr>';
            foreach ($terms as $t) {
                $to = $q($ln('TerminOpis'), $t);
                $op = $to ? trim($one($ln('Ilosc'), $to[0]) . ' ' . $one($ln('Jednostka'), $to[0]) . ' od ' . mb_strtolower($one($ln('ZdarzeniePoczatkowe'), $to[0]))) : '';
                $h .= '<tr><td>' . $e($dt($one($ln('Termin'), $t))) . '</td><td>' . $e($op) . '</td></tr>';
            }
            $h .= '</table>';
        }
        $acc = $q($pl . '/' . $ln('RachunekBankowy'));
        if ($acc) {
            $h .= '<div class="gap"></div><h2 style="margin-top:8px">Numer rachunku bankowego</h2><table class="grid" style="width:55%">';
            foreach ($acc as $a) {
                $nrb = preg_replace('/\s+/', '', $one($ln('NrRB'), $a));
                $nrb_f = strlen($nrb) === 26 ? substr($nrb, 0, 2) . ' ' . substr($nrb, 2, 8) . ' ' . trim(chunk_split(substr($nrb, 10), 4, ' ')) : $nrb;
                foreach (['Pełny numer rachunku' => $nrb_f, 'Kod SWIFT' => $one($ln('SWIFT'), $a), 'Rachunek własny banku' => $one($ln('RachunekWlasnyBanku'), $a),
                          'Nazwa banku' => $one($ln('NazwaBanku'), $a), 'Opis rachunku' => $one($ln('OpisRachunku'), $a)] as $k => $v)
                    $h .= '<tr><td style="width:40%;font-weight:bold">' . $e($k) . '</td><td>' . $e((string)$v) . '</td></tr>';
            }
            $h .= '</table>';
        }
        $h .= '<hr>';
    }

    // ── Pozostałe informacje (stopka / rejestry) ──
    $stopka = $one('//' . $ln('Stopka') . '//' . $ln('StopkaFaktury'));
    $reg = [];
    foreach (['KRS' => 'KRS', 'REGON' => 'REGON', 'BDO' => 'BDO'] as $t => $l) { $v = $one('//' . $ln('Stopka') . '//' . $ln($t)); if ($v !== '') $reg[] = $l . ': ' . $v; }
    if ($stopka !== '' || $reg) {
        $h .= '<h2>Pozostałe informacje</h2><table class="grid"><tr><th>Stopka faktury</th></tr><tr><td>' . $e(trim($stopka . ' ' . implode(' ', $reg))) . '</td></tr></table><hr>';
    }

    // ── Weryfikacja w KSeF + QR ──
    $url = $ksef ? edok_ksef_verify_url($xml, $seller_nip, $issue) : '';
    if ($url !== '') {
        $qr = '';
        try {
            require_once dirname(__DIR__) . '/vendor/autoload.php';
            $png = (new \Mpdf\QrCode\Output\Png())->output(new \Mpdf\QrCode\QrCode($url, 'M'), 110);
            $qr = '<img src="data:image/png;base64,' . base64_encode($png) . '" style="width:110px;height:110px" alt="QR">';
        } catch (\Throwable $ex) {}
        $h .= '<h2>Sprawdź, czy Twoja faktura znajduje się w KSeF!</h2><table><tr><td style="width:130px">' . $qr
            . ($ksef_ref !== '' ? '<div style="font-size:7px;margin-top:3px">' . $e($ksef_ref) . '</div>' : '') . '</td><td>'
            . 'Nie możesz zeskanować kodu z obrazka? Kliknij w link weryfikacyjny i przejdź do weryfikacji faktury!<br><br>'
            . '<span style="color:#0645ad">' . $e($url) . '</span></td></tr></table>';
    }
    $h .= '<div class="foot">' . ($ksef ? 'Wytworzona w: SZO (EODoK) — wizualizacja wygenerowana z pliku XML faktury ustrukturyzowanej.' : 'Wizualizacja wygenerowana z pliku XML faktury.') . '</div></div>';
    return $h;
}

/** Wizualizacja dla dokumentu EODoK, którego plik źródłowy to XML (z KSeF); '' gdy dokument nie ma takiego pliku. */
function edok_ksef_visualization_for_doc(array $doc): string {
    $rel = (string)($doc['file_path'] ?? '');
    if ($rel === '' || strtolower(pathinfo($rel, PATHINFO_EXTENSION)) !== 'xml') return '';
    $abs = UPLOAD_DIR . ltrim($rel, '/');
    if (!is_file($abs)) return '';
    return edok_ksef_visualization_html((string)file_get_contents($abs), (string)($doc['ksef_reference'] ?? ''), edok_ksef_show_ksef($doc));
}
