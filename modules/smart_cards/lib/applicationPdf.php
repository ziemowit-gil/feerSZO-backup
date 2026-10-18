<?php
/**
 * modules/smart_cards/lib/applicationPdf.php — wydruk wniosku o kartę (mPDF) w układzie
 * urzędowego formularza (wzór: deklaracja CIT-8): symbol formularza w nagłówku, sekcje A–H
 * z szarymi belkami, numerowane pola w ramkach z etykietą w lewym górnym rogu, kratki ☒/☐,
 * pola jasne (wnioskodawca) i ciemne (administrator kart), miejsca na podpisy, stopka
 * z symbolem, numerem wniosku i stroną.
 */

require_once dirname(__DIR__) . '/logic/smart_cards.php';

const SCARD_FORM_SYMBOL  = 'WK-1';
const SCARD_FORM_VERSION = '1';

/** HTML wydruku. $zones — [id => wiersz access_zones], $issued — strefy wydanej karty. */
function scardApplicationPdfHtml(array $a, array $zones, array $issued): string {
    $e   = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $box = fn(bool $on) => '<span class="bx">' . ($on ? '&#9746;' : '&#9744;') . '</span>';
    $d   = fn(?string $v, string $f = 'd.m.Y') => $v ? date($f, strtotime($v)) : '';
    $n = 0;
    // pole formularza: numer, etykieta, wartość; $dark — pole wypełniane przez administratora
    $fld = function (string $label, string $valueHtml, string $extra = '', bool $dark = false) use (&$n): string {
        $n++;
        // pole ciemne — tło inline (mPDF nie łączy tła z dwóch klas); doklejone do istniejącego style=""
        if ($dark) $extra = str_contains($extra, 'style="') ? str_replace('style="', 'style="background:#e6e6e6;', $extra) : $extra . ' style="background:#e6e6e6"';
        return '<td class="f"' . $extra . '><div class="lb"><b>' . $n . '.</b> ' . $label . '</div><div class="v">' . ($valueHtml !== '' ? $valueHtml : '&nbsp;') . '</div></td>';
    };
    $sec = fn(string $letter, string $title, string $hint = '') => '<table class="t"><tr><td class="sec"><b>' . $letter . '. ' . $title . '</b>' . ($hint !== '' ? ' <span class="hint">' . $hint . '</span>' : '') . '</td></tr></table>';
    // belka sekcji jako pierwszy wiersz tabeli — z page-break-inside:avoid sekcja nie rozdzieli się między strony
    $secRow = fn(string $letter, string $title, int $cols, string $hint = '') => '<tr><td class="sec" colspan="' . $cols . '"><b>' . $letter . '. ' . $title . '</b>' . ($hint !== '' ? ' <span class="hint">' . $hint . '</span>' : '') . '</td></tr>';

    $orgName = function_exists('org_setting') ? (string)org_setting('org_name') : '';
    $orgAddr = function_exists('org_setting') ? (string)org_setting('org_adres') : '';
    $status  = (string)$a['status'];
    $decided = $status !== 'pending';
    $photo   = SmartCardService::photoFile($a);

    // ── Nagłówek ──
    $h = '<table class="t"><tr>'
       . $fld('Numer wniosku', '<span class="big">' . $e($a['number']) . '</span>', ' width="34%"')
       . $fld('Data złożenia', $e($d($a['created_at'], 'd.m.Y H:i')), ' width="26%"')
       . $fld('Status wniosku', $e(SCARD_APP_STATUSES[$status]['label'] ?? $status), ' width="20%"')
       . '<td class="sym" width="20%"><div class="symb">' . SCARD_FORM_SYMBOL . '</div><div class="symv">wersja ' . SCARD_FORM_VERSION . '</div></td>'
       . '</tr></table>'
       . '<table class="t"><tr><td class="ttl"><div class="ttl1">WNIOSEK O WYDANIE KARTY DOSTĘPU (NFC)</div>'
       . '<div class="ttl2">karta identyfikacyjna i dostępowa do stref ' . ($orgName !== '' ? $e($orgName) : 'organizacji') . '</div></td></tr>'
       . '<tr><td class="lgd"><b>Podstawa:</b> wewnętrzne zasady kontroli dostępu do pomieszczeń.&nbsp; <b>Wypełnia:</b> pola jasne — wnioskodawca (sekcje A–F), '
       . 'pola ciemne — administrator kart (sekcje G–H).&nbsp; <b>Miejsce składania:</b> ' . ($orgAddr !== '' ? $e($orgAddr) : 'administrator kart dostępu') . '.</td></tr></table>';

    // ── A. Cel ──
    $cells = [];
    $i = 0;
    foreach (SCARD_APP_TYPES as $k => $t) {
        $i++;
        $cells[] = '<td class="opt">' . $box($a['app_type'] === $k) . ' ' . $i . '. ' . $e($t['label']) . '</td>';
    }
    $types = '<table class="o">' . implode('', array_map(fn($r) => '<tr>' . implode('', $r) . str_repeat('<td class="opt"></td>', 3 - count($r)) . '</tr>', array_chunk($cells, 3))) . '</table>';
    $h .= $sec('A', 'CEL ZŁOŻENIA WNIOSKU')
        . '<table class="t"><tr>' . $fld('Rodzaj wniosku (zaznaczyć właściwy kwadrat)', $types, ' colspan="2"') . '</tr><tr>'
        . $fld('Dotychczasowa karta — UID (wypełnić przy rodzajach 2–5)', $a['replaced_card_id'] ? '<span class="mono">' . $e(scard_uid_display((string)$a['replaced_card_uid'])) . '</span>' : '', ' width="60%"')
        . $fld('Stan dotychczasowej karty w ewidencji', $a['replaced_card_status'] ? $e(SCARD_STATUSES[$a['replaced_card_status']]['label'] ?? $a['replaced_card_status']) : '', ' width="40%"')
        . '</tr></table>';

    // ── B. Dane osoby (+ zdjęcie) ──
    $photoCell = '<td class="f ph" rowspan="3" width="27%"><div class="lb"><b>%N%.</b> Zdjęcie posiadacza</div>'
        . ($photo ? '<div class="phi"><img src="' . $e($photo) . '" style="width:25mm;height:32mm"></div>' : '<div class="phe">miejsce na zdjęcie<br>25 × 32 mm</div>') . '</td>';
    $h .= $sec('B', 'DANE OSOBY, DLA KTÓREJ MA BYĆ WYDANA KARTA')
        . '<table class="t"><tr>' . $fld('Nazwisko i imię', $e($a['user_name']), ' colspan="2"');
    $photoN = ++$n;
    $h .= str_replace('%N%', (string)$photoN, $photoCell) . '</tr><tr>'
        . $fld('Jednostka organizacyjna', $e($a['org_unit']), ' width="37%"')
        . $fld('Stanowisko / funkcja', $e($a['job_title']), ' width="36%"')
        . '</tr><tr>'
        . $fld('Telefon', $e($a['phone']))
        . $fld('Adres e-mail', $e($a['email'] !== '' ? $a['email'] : $a['user_email']))
        . '</tr></table>';

    // ── C. Zakres ──
    $zoneRows = '';
    $req = array_flip($a['zone_list']);
    $lp = 0;
    foreach ($zones as $zid => $z) {
        if (!isset($req[$zid]) && !(int)$z['is_active']) continue;
        $lp++;
        $zoneRows .= '<tr><td class="c">' . $lp . '</td><td>' . $e($z['zone_name']) . '</td><td class="c">' . (int)$z['security_level'] . ' — ' . $e(SCARD_LEVELS[(int)$z['security_level']] ?? '') . '</td>'
            . '<td class="c">' . $box(isset($req[$zid])) . '</td><td class="c" style="background:#e6e6e6">' . ($decided && $status === 'approved' ? $box(in_array((int)$zid, $issued, true)) : '&#9744;') . '</td></tr>';
    }
    $h .= $sec('C', 'WNIOSKOWANY ZAKRES KARTY')
        . '<table class="t"><tr>'
        . $fld('Wzór karty', $e($a['template_name'] ?: 'bez preferencji (wzór domyślny)'), ' width="40%"')
        . $fld('Ważna od', $e($d($a['valid_from'])), ' width="30%"')
        . $fld('Ważna do', $a['valid_to'] ? $e($d($a['valid_to'])) : 'standardowo ' . SCARD_DEFAULT_VALIDITY_YEARS . ' lata', ' width="30%"')
        . '</tr></table>';
    $zonesN = ++$n;
    $h .= '<table class="t"><tr><td class="f" style="padding-bottom:1.5mm"><div class="lb"><b>' . $zonesN . '.</b> Strefy dostępu — kolumna „wniosek”: wnioskodawca, kolumna „przyznano”: administrator kart</div>'
        . ($zoneRows !== '' ? '<table class="z"><tr><th width="8%">Lp.</th><th>Strefa</th><th width="28%">Poziom bezpieczeństwa</th><th width="12%">wniosek</th><th width="12%" style="background:#d9d9d9">przyznano</th></tr>' . $zoneRows . '</table>'
                            : '<div class="v">brak zdefiniowanych stref — zakres zgodnie ze wzorem karty</div>')
        . '</td></tr></table>';

    // ── D. Uzasadnienie ──
    $h .= $sec('D', 'UZASADNIENIE WNIOSKU')
        . '<table class="t"><tr>' . $fld('Uzasadnienie', nl2br($e($a['reason'])), ' style="height:12mm"') . '</tr></table>';

    // ── E. Oświadczenia ──
    $h .= $sec('E', 'OŚWIADCZENIA WNIOSKODAWCY')
        . '<table class="t"><tr>' . $fld('Odpowiedzialność za kartę', $box((bool)$a['consent_responsibility']) . ' <span class="st">' . $e(SCARD_CONSENTS['responsibility']) . '</span>', ' colspan="2"') . '</tr><tr>'
        . $fld('Ochrona danych osobowych', $box((bool)$a['consent_rodo']) . ' <span class="st">' . $e(SCARD_CONSENTS['rodo']) . '</span>', ' colspan="2"') . '</tr><tr>'
        . $fld('Data', $e($d($a['consent_at'] ?: $a['created_at'])), ' width="30%" style="height:12mm"')
        . $fld('Podpis wnioskodawcy (osoby, dla której wydawana jest karta)', '', ' width="70%"')
        . '</tr></table>'
        . ((int)$a['created_by'] && (int)$a['created_by'] !== (int)$a['user_id']
            ? '<div class="note">Wniosek wprowadzony do systemu w imieniu osoby przez: ' . $e($a['created_by_name']) . '.</div>' : '');

    // ── F. Przełożony ──
    $h .= $sec('F', 'AKCEPTACJA PRZEŁOŻONEGO')
        . '<table class="t"><tr>'
        . $fld('Imię i nazwisko przełożonego', $e($a['supervisor_name']), ' width="40%" style="height:12mm"')
        . $fld('Data', '', ' width="20%"')
        . $fld('Podpis przełożonego', '', ' width="40%"')
        . '</tr></table>';

    // ── G. Decyzja (pola ciemne) ──
    $h .= '<table class="t" style="page-break-inside:avoid">' . $secRow('G', 'DECYZJA', 3, '(wypełnia administrator kart)') . '<tr>'
        . $fld('Rozstrzygnięcie', $box($status === 'approved') . ' 1. zatwierdzono — wydano kartę &nbsp;&nbsp; ' . $box($status === 'rejected') . ' 2. odmówiono wydania karty', ' colspan="3"', true)
        . '</tr><tr>'
        . $fld('Rozpatrzył(a)', $decided ? $e($a['decided_by_name']) : '', ' width="40%"', true)
        . $fld('Data decyzji', $decided ? $e($d($a['decided_at'], 'd.m.Y H:i')) : '', ' width="25%"', true)
        . $fld('Numer karty (UID)', $a['card_id'] ? '<span class="mono">' . $e(scard_uid_display((string)$a['card_uid'])) . '</span>' : '', ' width="35%"', true)
        . '</tr><tr>'
        . $fld('Karta ważna do', $a['card_id'] ? $e($d($a['card_expires_at'])) : '', '', true)
        . $fld($status === 'rejected' ? 'Uzasadnienie odmowy' : 'Uwagi', $e($a['decision_note']), ' colspan="2"', true)
        . '</tr><tr>'
        . $fld('Pieczęć', '', ' style="height:15mm"', true)
        . $fld('Podpis administratora kart', '', ' colspan="2"', true)
        . '</tr></table>';

    // ── H. Odbiór ──
    $h .= '<table class="t" style="page-break-inside:avoid">' . $secRow('H', 'POTWIERDZENIE ODBIORU KARTY', 2) . '<tr>'
        . $fld('Data odbioru', '', ' width="30%" style="height:12mm"')
        . $fld('Podpis odbierającego — potwierdzam odbiór karty i zapoznanie się z zasadami jej używania', '', ' width="70%"')
        . '</tr></table>';

    return '<style>
      body{font-family:dejavusans;font-size:8.6pt;color:#000}
      table.t{width:100%;border-collapse:collapse;margin:0}
      table.t td{border:0.25mm solid #000;vertical-align:top}
      td.f{padding:0.6mm 1.4mm 0.9mm;background:#fff}
      .lb{font-size:6.4pt;color:#222;margin-bottom:0.4mm}
      .v{font-size:9.2pt;font-weight:bold}
      .big{font-size:12pt;letter-spacing:0.3mm}
      td.sec{background:#bfbfbf;padding:1mm 1.6mm;font-size:8.6pt;letter-spacing:0.15mm}
      .hint{font-weight:normal;font-size:7pt}
      td.sym{background:#bfbfbf;text-align:center;vertical-align:middle}
      .symb{font-size:20pt;font-weight:bold;letter-spacing:0.5mm}
      .symv{font-size:6.5pt}
      td.ttl{text-align:center;padding:1.6mm 2mm;background:#fff}
      .ttl1{font-size:13pt;font-weight:bold;letter-spacing:0.2mm}
      .ttl2{font-size:8pt;margin-top:0.6mm}
      td.lgd{font-size:6.8pt;padding:1mm 1.6mm;background:#f2f2f2}
      .bx{font-size:10pt;font-family:dejavusans}
      table.o{width:100%;border-collapse:collapse} table.o td.opt{border:0;width:33%;font-size:8pt;font-weight:normal;padding:0.3mm 0}
      .st{font-size:6.9pt;font-weight:normal}
      .mono{font-family:dejavusansmono}
      td.ph{text-align:center}
      .phi{margin-top:1mm} .phe{margin:8mm auto;color:#777;font-size:7pt;font-weight:normal}
      table.z{width:100%;border-collapse:collapse;margin-top:0.6mm}
      table.z th{border:0.2mm solid #000;font-size:6.6pt;font-weight:bold;padding:0.6mm 1mm;background:#f2f2f2}
      table.z td{border:0.2mm solid #000;font-size:8pt;padding:0.6mm 1mm;vertical-align:middle}
      table.z td.c{text-align:center}
      .note{font-size:6.8pt;margin:0.8mm 0 0}
    </style>' . $h;
}

/** Treść PDF wniosku (string). */
function scardApplicationPdf(SmartCardService $svc, array $a): string {
    require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
    $zones = [];
    foreach ($svc->zones() as $z) $zones[(int)$z['id']] = $z;
    $tmp = UPLOAD_DIR . 'mpdf_tmp';
    if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12,
                            'margin_top' => 8, 'margin_bottom' => 13, 'margin_footer' => 5, 'default_font' => 'dejavusans', 'tempDir' => $tmp,
                            'shrink_tables_to_fit' => 1]);   // sekcje z page-break-inside:avoid przechodzą na następną stronę zamiast się kurczyć
    $mpdf->SetTitle('Wniosek o wydanie karty dostępu ' . $a['number'] . ' — ' . $a['user_name']);
    $mpdf->SetAuthor(function_exists('org_setting') ? (string)org_setting('org_name') : '');
    $mpdf->SetHTMLFooter('<table width="100%" style="font-family:dejavusans;font-size:7pt;border-top:0.25mm solid #000"><tr>'
        . '<td width="33%"><b>' . SCARD_FORM_SYMBOL . '</b> (' . SCARD_FORM_VERSION . ')</td>'
        . '<td width="34%" style="text-align:center">Wniosek nr ' . htmlspecialchars($a['number']) . '</td>'
        . '<td width="33%" style="text-align:right">Strona {PAGENO}/{nbpg}</td></tr></table>');
    $mpdf->WriteHTML(scardApplicationPdfHtml($a, $zones, $svc->issuedZoneIds($a)));
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}
