<?php
/**
 * modules/skierowania_badania/lib/skierowaniePdf.php — PDF skierowania na badania lekarskie (mPDF).
 * Układ wg wzoru z załącznika nr 3a do rozporządzenia o badaniach lekarskich pracowników; dwa egzemplarze
 * (dla osoby kierowanej i dla Fundacji) — każdy na osobnej stronie.
 */

require_once dirname(__DIR__) . '/logic/skierowania.php';

function skbPdfHtml(array $s, string $egz): string {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $line = fn($v, $ph = '') => trim((string)$v) !== '' ? '<b>' . $e($v) . '</b>' : '<span class="ph">' . $ph . '</span>';
    $box = fn(bool $on) => $on ? '&#9746;' : '&#9744;';
    $type = (string)$s['contract_type'];
    $c = skbIsPre($s) ? [] : (skbContract($type, (int)$s['contract_id']) ?: []);
    $nrUmowy = skbContractNumber($c);
    $podstawa = skbSetting($type === 'wolontariat' ? 'podstawa_wolontariat' : 'podstawa_zlecenie');
    $data = date('d.m.Y', strtotime((string)$s['data_wystawienia']));
    $rodzaje = [];
    foreach (SKB_RODZAJE as $k => $l) $rodzaje[] = $k === $s['rodzaj'] ? '<b>' . $l . '</b>' : '<s>' . $l . '</s>';
    $orgName = (string)org_setting('org_name');
    $orgAddr = (string)org_setting('org_adres');
    $nip = (string)org_setting('org_nip');
    $byGroup = skbFactorsByGroup($s);

    $factors = '';
    foreach (SKB_CZYNNIKI as $g => [$label]) {
        $items = $byGroup[$g] ?? [];
        $txt = $items ? implode('; ', array_map(fn($f) => $e($f['name']) . (trim((string)($f['wielkosc'] ?? '')) !== '' ? ' — ' . $e($f['wielkosc']) : ''), $items)) : 'brak';
        $factors .= '<tr><td class="g">' . $g . '.</td><td class="gl">' . $e($label) . ':</td><td>' . $txt . '</td></tr>';
    }

    $who = $type === 'wolontariat' ? 'wolontariusza / wolontariuszkę' : 'zleceniobiorcę';
    $rel = skbIsPre($s)
        ? ($type === 'wolontariat' ? 'porozumienia wolontariackiego' : 'umowy zlecenia') . ' (przed zawarciem umowy)'
        : SKB_TYPY[$type] . ($nrUmowy !== '' ? ' nr ' . $e($nrUmowy) : '');
    $tozs = trim((string)$s['pesel']) !== ''
        ? 'nr PESEL ' . $line($s['pesel'])
        : 'nr PESEL —, dokument tożsamości: ' . $line($s['dokument']);

    return '<style>
      body{font-family:dejavusans;font-size:9.6pt;line-height:1.38;color:#000}
      .hdr td{vertical-align:top} .stamp{border:1px dashed #888;width:62mm;height:24mm;padding:2mm;font-size:8pt;color:#333}
      h1{font-size:12.5pt;text-align:center;margin:5mm 0 1mm} .sub{text-align:center;font-size:10pt;margin-bottom:4mm}
      .ph{color:#777} .egz{font-size:7.5pt;color:#555;text-align:right}
      table.f{border-collapse:collapse;width:100%;margin:2mm 0} table.f td{border:0.3mm solid #999;padding:1.2mm 1.6mm;vertical-align:top;font-size:9pt}
      td.g{width:7mm;text-align:center;font-weight:bold} td.gl{width:42mm;font-weight:bold}
      .sign td{vertical-align:bottom;font-size:8pt;text-align:center} .dots{border-top:0.3mm dotted #000;padding-top:1mm}
      .note{font-size:7.4pt;color:#333;margin-top:4mm;line-height:1.3}
    </style>
    <div class="egz">' . $e($s['numer']) . ' · ' . $e($egz) . '</div>
    <table class="hdr" width="100%"><tr>
      <td><div class="stamp">' . $e($orgName) . '<br>' . $e($orgAddr) . ($nip !== '' ? '<br>NIP ' . $e($nip) : '') . '</div><div style="font-size:7pt;color:#555">(oznaczenie podmiotu kierującego)</div></td>
      <td style="text-align:right">' . $e($s['miejscowosc']) . ', dnia ' . $data . '<br><span style="font-size:7pt;color:#555">(miejscowość, data)</span></td>
    </tr></table>
    <h1>SKIEROWANIE NA BADANIA LEKARSKIE</h1>
    <div class="sub">(' . implode(' / ', $rodzaje) . ')*</div>
    ' . (trim((string)$s['placowka']) !== '' ? '<p>Do: <b>' . $e($s['placowka']) . '</b></p>' : '') . '
    <p>Działając na podstawie ' . $e($podstawa) . ', kieruję na badania lekarskie ' . $who . ':</p>
    <p>Pana/Panią* ' . $line($s['imie_nazwisko']) . ', ' . $tozs . '**,<br>
       zamieszkałego/zamieszkałą* w ' . $line($s['adres'], '……………………………………………………………') . '</p>
    <p>' . $box((int)$s['podejmujacy'] === 0) . ' wykonującego/wykonującą &nbsp; lub &nbsp; ' . $box((int)$s['podejmujacy'] === 1) . ' podejmującego/podejmującą
       czynności na podstawie: <b>' . $rel . '</b>, na stanowisku lub stanowiskach:<br>' . $line($s['stanowisko']) . '</p>
    <p>Opis warunków wykonywania czynności***: ' . (trim((string)$s['opis_warunkow']) !== '' ? $e($s['opis_warunkow']) : '<span class="ph">—</span>') . '</p>
    <p>Informacja o występowaniu na stanowisku lub stanowiskach czynników niebezpiecznych, szkodliwych dla zdrowia lub czynników uciążliwych
       i innych wynikających ze sposobu wykonywania pracy, z podaniem wielkości narażenia oraz aktualnych wyników badań i pomiarów****:</p>
    <table class="f">' . $factors . '</table>
    <p>Łączna liczba czynników niebezpiecznych, szkodliwych dla zdrowia lub czynników uciążliwych i innych wynikających ze sposobu wykonywania pracy
       wskazanych w skierowaniu: <b>' . (int)$s['liczba_czynnikow'] . '</b></p>
    ' . (trim((string)$s['uwagi']) !== '' ? '<p>Uwagi: ' . nl2br($e($s['uwagi'])) . '</p>' : '') . '
    <p>Skierowanie na badania lekarskie jest wydawane w dwóch egzemplarzach, z których jeden otrzymuje osoba kierowana na badania.</p>
    <table class="sign" width="100%" style="margin-top:14mm"><tr>
      <td width="45%"></td><td width="10%"></td>
      <td width="45%">' . (trim((string)$s['podpisujacy']) !== '' ? $e($s['podpisujacy']) . '<br>' : '') . '<div class="dots">(podpis osoby reprezentującej ' . $e($orgName) . ')</div></td>
    </tr></table>
    <div class="note">
      * niepotrzebne skreślić.<br>
      ** w przypadku osoby, której nie nadano numeru PESEL — seria, numer i nazwa dokumentu potwierdzającego tożsamość.<br>
      *** opisując warunki pracy, należy uwzględnić w szczególności przepisy wydane na podstawie art. 222 § 3, art. 222<sup>1</sup> § 3, art. 225 § 2, art. 228 § 3, art. 237 § 2 Kodeksu pracy.<br>
      **** w przypadku wykonywania pracy w warunkach narażenia na promieniowanie jonizujące należy wpisać kategorię narażenia; przy pracy na wysokości należy wpisać wysokość.
    </div>';
}

/** PDF (treść) skierowania — dwa egzemplarze. */
function skbPdf(array $s): string {
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    $tmp = UPLOAD_DIR . 'mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 18, 'margin_right' => 16,
                            'margin_top' => 12, 'margin_bottom' => 22, 'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp]);
    $mpdf->SetTitle('Skierowanie na badania lekarskie ' . $s['numer'] . ' — ' . $s['imie_nazwisko']);
    $mpdf->SetAuthor((string)org_setting('org_name'));
    // Stopka z kodami Code128 + QR (rejestr dokumentów) — poza treścią wzoru; oba egzemplarze mają ten sam kod; błąd kodów nie blokuje PDF
    try {
        require_once dirname(__DIR__, 2) . '/doc_registry/lib/DocRegistry.php';
        $fc = DocRegistry::printCodes('skierowanie_badania', (string)($s['id'] ?? $s['numer']), 'Skierowanie na badania lekarskie ' . $s['numer'], 'modules/skierowania_badania/router.php?_route=/' . (int)($s['id'] ?? 0));
        $fimg = fn(?string $png, string $st) => $png ? '<img src="data:image/png;base64,' . base64_encode($png) . '" style="' . $st . '">' : '';
        if ($fc) $mpdf->SetHTMLFooter('<table style="border-collapse:collapse;font-family:dejavusans;font-size:6.5pt;color:#555"><tr>'
            . '<td style="border:none;padding:0 2mm 0 0;width:11mm">' . $fimg($fc['qr'], 'height:9mm;width:9mm') . '</td>'
            . '<td style="border:none;padding:0">' . $fimg($fc['bar'], 'height:5mm') . '<br>' . htmlspecialchars($fc['code'] . ' · ' . $fc['doc_number'], ENT_QUOTES) . '</td>'
            . '</tr></table>');
    } catch (\Throwable $e) { error_log('[skierowanie pdf] kody w stopce: ' . $e->getMessage()); }
    $mpdf->WriteHTML(skbPdfHtml($s, 'egzemplarz dla osoby kierowanej'));
    $mpdf->AddPage();
    $mpdf->WriteHTML(skbPdfHtml($s, 'egzemplarz dla ' . (string)org_setting('org_name')));
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}
