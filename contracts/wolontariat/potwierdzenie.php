<?php
/**
 * contracts/wolontariat/potwierdzenie.php
 * Dokumenty dla porozumienia wolontariackiego.
 *
 * GET ?id=X                — ID porozumienia
 * GET &typ=wkladka         — Karta do segregatora A4, jednostronna + adnotacje (domyślnie)
 * GET &typ=wolontariusz    — Uproszczone potwierdzenie dla wolontariusza
 * GET &typ=koperta_a4      — Koperta A4 (210×297 mm)
 * GET &typ=koperta_c4      — Koperta C4 (229×324 mm)
 * GET &format=pdf          — HTML do druku (domyślnie)
 * GET &format=docx         — pobierz .docx (nie dla kopert)
 * GET &preview=1           — HTML bez auto-print
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/correspondence.php';
require_once dirname(dirname(__DIR__)) . '/includes/postal.php';

require_login();

$id  = (int)($_GET['id'] ?? 0);
$typy_ok = ['wkladka', 'wolontariusz', 'koperta_a4', 'koperta_c4'];
$typ     = in_array($_GET['typ'] ?? '', $typy_ok, true) ? $_GET['typ'] : 'wkladka';
$format  = (!str_starts_with($typ, 'koperta') && in_array($_GET['format'] ?? '', ['pdf','docx'], true))
           ? $_GET['format'] : 'pdf';

if (!$id) { http_response_code(400); exit('Brak ID.'); }
$row = db_one("SELECT * FROM umowy_wolontariat WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); exit('Nie znaleziono umowy.'); }
if (!viewer_owns_contract('wolontariat', $row) && !can_edit()) {
    http_response_code(403); exit('Brak dostępu.');
}

// ── Dane organizacji ──────────────────────────────────────────────────────────
$org_name   = org_setting('org_name')            ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres  = org_setting('org_adres')           ?: '';
$org_miasto = org_setting('org_miejscowosc')     ?: '';
$org_nip    = org_setting('org_nip')             ?: '';
$org_krs    = org_setting('org_krs')             ?: '';
$org_repr   = org_setting('org_reprezentant')    ?: '';
$org_stanow = org_setting('org_stanowisko_repr') ?: 'Prezes Zarządu';

// ── Dane wolontariusza ────────────────────────────────────────────────────────
$vol_name    = $row['imie_nazwisko']  ?? '';
$vol_pesel   = $row['pesel']          ?? '';
$vol_dob     = $row['data_urodzenia'] ?? '';
$vol_email   = $row['email']          ?? '';
$vol_telefon = $row['telefon']        ?? '';

// Adres główny (ustrukturyzowany)
$vol_street  = trim(($row['addr_street'] ?? '') . ' ' . ($row['addr_house'] ?? '') . ($row['addr_flat'] ? '/' . $row['addr_flat'] : ''));
$vol_postal  = trim(($row['addr_postal'] ?? '') . ' ' . ($row['addr_city'] ?? ''));
$vol_adres   = trim(implode(', ', array_filter([$vol_street, $vol_postal]))) ?: ($row['adres'] ?? '');

// Adres korespondencyjny (jeśli inny — koperta)
$koresp_odbiorca = trim($row['adres_odbiorca'] ?? '') ?: $vol_name;
$koresp_linia1   = trim($row['adres_linia1']   ?? '');
$koresp_linia2   = trim($row['adres_linia2']   ?? '');

// Adres na kopertę — 3 linie: Imię Nazwisko / Ulica nr/m / Kod Miasto
// Preferuj adres korespondencyjny, fallback na ustrukturyzowany adres główny
if ($koresp_linia1) {
    $env_line1 = $koresp_odbiorca;   // imię i nazwisko (lub firma)
    $env_line2 = $koresp_linia1;     // ulica nr/m
    $env_line3 = $koresp_linia2;     // kod pocztowy + miejscowość
} else {
    $env_line1 = $vol_name;
    $env_line2 = trim(
        ($row['addr_street'] ?? '') . ' ' .
        ($row['addr_house']  ?? '') .
        ($row['addr_flat'] ? '/' . $row['addr_flat'] : '')
    );
    $env_line3 = trim(($row['addr_postal'] ?? '') . ' ' . ($row['addr_city'] ?? ''));
}

// ── Dane porozumienia ─────────────────────────────────────────────────────────
$numer      = $row['numer_umowy']           ?? '';
$data_dzis  = date('d.m.Y');
$data_zawar = $row['data_zawarcia']    ? date('d.m.Y', strtotime($row['data_zawarcia']))    : '—';
$data_od    = $row['data_rozpoczecia'] ? date('d.m.Y', strtotime($row['data_rozpoczecia'])) : '—';
$data_do    = !empty($row['bezterminowa'])
    ? 'czas nieokreślony'
    : ($row['data_zakonczenia'] ? date('d.m.Y', strtotime($row['data_zakonczenia'])) : '—');
$miejsce    = $row['miejsce_wolontariatu']   ?? '—';
$przedmiot  = $row['przedmiot_porozumienia'] ?? '';
$projekt    = $row['projekt_program']        ?? '';
$opiekun    = $row['opiekun']                ?? '';
$godziny    = $row['godzin_tygodniowo']
    ? $row['godzin_tygodniowo'] . ' godz./tydzień' : '—';
$bhp        = !empty($row['szkolenie_bhp'])
    ? ('Tak' . ($row['data_szkolenia_bhp'] ? ', ' . date('d.m.Y', strtotime($row['data_szkolenia_bhp'])) : ''))
    : 'Nie';
$nnw        = !empty($row['ubezpieczenie_nnw'])
    ? ('Tak' . ($row['numer_polisy_nnw'] ? ', polisa: ' . $row['numer_polisy_nnw'] : ''))
    : 'Nie';
$oc         = !empty($row['ubezpieczenie_oc']) ? 'Tak' : 'Nie';
$zwrot      = !empty($row['zwrot_kosztow'])
    ? ('Tak' . ($row['limit_zwrotu_kosztow'] ? ', limit: ' . number_format((float)$row['limit_zwrotu_kosztow'], 2, ',', ' ') . ' zł' : ''))
    : 'Nie';
$status_labels = ['aktywna'=>'Aktywne','aktywne'=>'Aktywne','zakonczona'=>'Zakończone','zawarta'=>'Zawarte','robocza'=>'Wersja robocza'];
$status_txt    = $status_labels[$row['status'] ?? ''] ?? ucfirst($row['status'] ?? '') ?: '—';

$fn_safe  = preg_replace('/[^a-zA-Z0-9_-]/', '_', $numer);
$base_url = APP_URL . '/contracts/wolontariat/potwierdzenie.php?id=' . $id;

// ── Korespondencja wychodząca — barcode ──────────────────────────────────────
$corr_id  = (int)($_GET['corr_id'] ?? 0);
$corr_row = null;
$s10_code = '';
if ($corr_id && str_starts_with($typ, 'koperta')) {
    $corr_row = db_one(
        "SELECT * FROM correspondence WHERE id=? AND direction='outgoing'",
        [$corr_id]
    );
    if ($corr_row) {
        $s10_code = $corr_row['s10_number'] ?? '';
    }
}

// ── DOCX helpers ──────────────────────────────────────────────────────────────
function _pw(string $t): string { return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function _pp(string $text, bool $bold = false, string $align = 'both', int $sz = 24): string {
    $b  = $bold ? '<w:b/><w:bCs/>' : '';
    $jc = "<w:jc w:val=\"{$align}\"/>";
    return "<w:p><w:pPr>{$jc}<w:spacing w:after=\"80\"/></w:pPr>"
         . "<w:r><w:rPr>{$b}<w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/></w:rPr>"
         . "<w:t xml:space=\"preserve\">" . _pw($text) . "</w:t></w:r></w:p>";
}
function _ptrow(string $lbl, string $val, bool $hl = false): string {
    $bg = $hl ? 'EFF6FF' : 'F8FAFC'; $bgl = $hl ? 'DBEAFE' : 'F1F5F9';
    return "<w:tr>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"3200\" w:type=\"dxa\"/><w:shd w:fill=\"{$bgl}\" w:val=\"clear\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>" . _pw($lbl) . "</w:t></w:r></w:p></w:tc>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4800\" w:type=\"dxa\"/><w:shd w:fill=\"{$bg}\" w:val=\"clear\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t xml:space=\"preserve\">" . _pw($val) . "</w:t></w:r></w:p></w:tc>"
        . "</w:tr>";
}
function _tbl_open(): string {
    return "<w:tbl><w:tblPr><w:tblW w:w=\"8000\" w:type=\"dxa\"/><w:tblBorders>"
         . "<w:top w:val=\"single\" w:sz=\"4\"/><w:left w:val=\"single\" w:sz=\"4\"/>"
         . "<w:bottom w:val=\"single\" w:sz=\"4\"/><w:right w:val=\"single\" w:sz=\"4\"/>"
         . "<w:insideH w:val=\"single\" w:sz=\"4\"/><w:insideV w:val=\"single\" w:sz=\"4\"/>"
         . "</w:tblBorders></w:tblPr>";
}
function _psec(string $title, int $sz = 22): string {
    return "<w:p><w:pPr><w:jc w:val=\"center\"/><w:spacing w:before=\"160\" w:after=\"60\"/></w:pPr>"
         . "<w:r><w:rPr><w:b/><w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/></w:rPr>"
         . "<w:t>" . _pw($title) . "</w:t></w:r></w:p>";
}
function _docx_pack(string $body_content, string $pg_w = '11906', string $pg_h = '16838'): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body_content
        . '<w:sectPr><w:pgSz w:w="' . $pg_w . '" w:h="' . $pg_h . '"/>'
        . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1800" w:header="708" w:footer="708"/>'
        . '</w:sectPr></w:body></w:document>';
}
function _docx_zip(string $tmp, string $doc_xml): void {
    $rels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';
    $word_rels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr>'
        . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
        . '<w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="pl-PL"/>'
        . '</w:rPr></w:rPrDefault></w:docDefaults></w:styles>';
    $ct = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '</Types>';
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',          $ct);
    $zip->addFromString('_rels/.rels',                  $rels);
    $zip->addFromString('word/document.xml',            $doc_xml);
    $zip->addFromString('word/_rels/document.xml.rels', $word_rels);
    $zip->addFromString('word/styles.xml',              $styles);
    $zip->close();
}

// ── DOCX ──────────────────────────────────────────────────────────────────────
if ($format === 'docx') {
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('Brak ZipArchive.'); }

    $tmp = sys_get_temp_dir() . '/' . $typ . '_' . $fn_safe . '_' . time() . '.docx';

    if ($typ === 'wkladka') {
        $parts = [
            _pp($org_name, true, 'center', 26),
            $org_adres  ? _pp($org_adres . ($org_miasto ? ', ' . $org_miasto : ''), false, 'center', 20) : '',
            ($org_nip || $org_krs) ? _pp(implode('   ', array_filter(['NIP: '.$org_nip, $org_krs ? 'KRS: '.$org_krs : ''])), false, 'center', 18) : '',
            _pp(''),
            _pp('KARTA WOLONTARIUSZA', true, 'center', 28),
            _pp('nr ' . $numer, false, 'center', 22),
            _pp($org_miasto . ', dnia ' . $data_dzis . ' r.', false, 'right', 20),
            _pp(''),
            _psec('Dane wolontariusza'),
            _tbl_open()
            . _ptrow('Imię i nazwisko:', $vol_name, true)
            . ($vol_pesel   ? _ptrow('PESEL:', $vol_pesel) : '')
            . ($vol_dob     ? _ptrow('Data urodzenia:', date('d.m.Y', strtotime($vol_dob))) : '')
            . ($vol_adres   ? _ptrow('Adres:', $vol_adres) : '')
            . ($vol_email   ? _ptrow('E-mail:', $vol_email) : '')
            . ($vol_telefon ? _ptrow('Telefon:', $vol_telefon) : '')
            . "</w:tbl>",
            _pp(''),
            _psec('Dane porozumienia'),
            _tbl_open()
            . _ptrow('Numer porozumienia:', $numer, true)
            . _ptrow('Data zawarcia:', $data_zawar)
            . _ptrow('Okres:', $data_od . ' – ' . $data_do, true)
            . _ptrow('Miejsce:', $miejsce)
            . ($przedmiot ? _ptrow('Zakres czynności:', $przedmiot) : '')
            . ($projekt   ? _ptrow('Projekt / program:', $projekt) : '')
            . ($opiekun   ? _ptrow('Opiekun:', $opiekun) : '')
            . _ptrow('Wymiar godzinowy:', $godziny)
            . _ptrow('BHP:', $bhp)
            . _ptrow('NNW:', $nnw)
            . _ptrow('OC:', $oc)
            . _ptrow('Zwrot kosztów:', $zwrot)
            . _ptrow('Status:', $status_txt, true)
            . "</w:tbl>",
            _pp(''),
            _psec('Adnotacje'),
            _pp(''),_pp(''),_pp(''),_pp(''),_pp(''),
            "<w:p><w:pPr><w:jc w:val=\"left\"/></w:pPr>"
            . "<w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>…………………………………………………………………………………………………………………………………………………</w:t></w:r></w:p>",
            "<w:p><w:pPr><w:jc w:val=\"left\"/></w:pPr>"
            . "<w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>…………………………………………………………………………………………………………………………………………………</w:t></w:r></w:p>",
            "<w:p><w:pPr><w:jc w:val=\"left\"/></w:pPr>"
            . "<w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>…………………………………………………………………………………………………………………………………………………</w:t></w:r></w:p>",
            _pp(''), _pp(''),
            // Podpis jednostronny
            "<w:tbl><w:tblPr><w:tblW w:w=\"9000\" w:type=\"dxa\"/><w:tblBorders><w:top w:val=\"none\"/><w:left w:val=\"none\"/><w:bottom w:val=\"none\"/><w:right w:val=\"none\"/><w:insideH w:val=\"none\"/><w:insideV w:val=\"none\"/></w:tblBorders></w:tblPr>"
            . "<w:tr>"
            . "<w:tc><w:tcPr><w:tcW w:w=\"4500\" w:type=\"dxa\"/></w:tcPr><w:p/></w:tc>"
            . "<w:tc><w:tcPr><w:tcW w:w=\"4500\" w:type=\"dxa\"/></w:tcPr>"
            . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>…………………………………………………</w:t></w:r></w:p>"
            . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>Organizacja</w:t></w:r></w:p>"
            . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _pw($org_repr ?: $org_name) . "</w:t></w:r></w:p>"
            . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _pw($org_stanow) . "</w:t></w:r></w:p>"
            . "</w:tc>"
            . "</w:tr></w:tbl>",
        ];
    } else {
        // Potwierdzenie dla wolontariusza
        $parts = [
            _pp($org_miasto . ', dnia ' . $data_dzis . ' r.', false, 'right', 20),
            _pp(''),
            _pp('POTWIERDZENIE WOLONTARIATU', true, 'center', 28),
            _pp('nr ' . $numer, false, 'center', 20),
            _pp(''),
            _pp($org_name . ' potwierdza, że', false, 'center', 22),
            _pp(''),
            _pp($vol_name, true, 'center', 30),
            ($vol_pesel ? _pp('PESEL: ' . $vol_pesel, false, 'center', 20) : ''),
            _pp(''),
            _pp('świadczy pracę wolontariacką na podstawie Porozumienia nr ' . $numer
                . ', w okresie: ' . $data_od . ' – ' . $data_do
                . ($miejsce !== '—' ? ', w miejscu: ' . $miejsce : '') . '.',
                false, 'center', 22),
            _pp(''),
            ($przedmiot ? _pp('Zakres: ' . $przedmiot, false, 'center', 20) : ''),
            _pp(''),_pp(''),_pp(''),
            "<w:tbl><w:tblPr><w:tblW w:w=\"9000\" w:type=\"dxa\"/><w:tblBorders><w:top w:val=\"none\"/><w:left w:val=\"none\"/><w:bottom w:val=\"none\"/><w:right w:val=\"none\"/><w:insideH w:val=\"none\"/><w:insideV w:val=\"none\"/></w:tblBorders></w:tblPr>"
            . "<w:tr>"
            . "<w:tc><w:tcPr><w:tcW w:w=\"4500\" w:type=\"dxa\"/></w:tcPr>"
            . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/><w:color w:val=\"AAAAAA\"/></w:rPr><w:t>Miejsce na pieczęć</w:t></w:r></w:p>"
            . "</w:tc>"
            . "<w:tc><w:tcPr><w:tcW w:w=\"4500\" w:type=\"dxa\"/></w:tcPr>"
            . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>…………………………………………………</w:t></w:r></w:p>"
            . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>Wydaje: " . _pw($org_repr ?: $org_name) . "</w:t></w:r></w:p>"
            . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _pw($org_stanow) . " — " . _pw($org_name) . "</w:t></w:r></w:p>"
            . "</w:tc>"
            . "</w:tr></w:tbl>",
        ];
    }

    $doc_xml = _docx_pack(implode("\n", array_filter($parts)));
    _docx_zip($tmp, $doc_xml);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $typ . '_' . $fn_safe . '.docx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); unlink($tmp); exit;
}

// ── HTML / PDF ─────────────────────────────────────────────────────────────────
$is_preview = isset($_GET['preview']);

// Tytuły dla paska narzędzi
$typ_labels = [
    'wkladka'     => 'Karta do segregatora',
    'wolontariusz'=> 'Potwierdzenie dla wolontariusza',
    'koperta_a4'  => 'Koperta A4',
    'koperta_c4'  => 'Koperta C4',
];
$page_title = ($typ_labels[$typ] ?? 'Dokument') . ' — ' . $numer;

// Wymiary strony @page
$page_size_css = match($typ) {
    'koperta_c4' => '@page { size: 229mm 324mm; margin: 18mm 20mm; }',
    'koperta_a4' => '@page { size: 210mm 297mm; margin: 16mm 18mm; }',
    default      => '@page { size: A4; margin: 2.5cm 2.5cm 2.5cm 3cm; }',
};
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($page_title) ?></title>
<style>
* { box-sizing: border-box; }
<?= $page_size_css ?>
body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; margin:0; padding:0; background:#f5f5f5; }

/* ── Pasek narzędzi ── */
.no-print { background:#fff; border-bottom:1px solid #ddd; padding:.6rem 1.1rem; display:flex; gap:.55rem; align-items:center; flex-wrap:wrap; font-family:system-ui,sans-serif; font-size:.85rem; }
.no-print a, .no-print button { padding:.36rem .85rem; border-radius:6px; font-size:.81rem; font-weight:600; cursor:pointer; text-decoration:none; border:1px solid transparent; }
.btn-pdf  { background:#dc2626; color:#fff; }
.btn-docx { background:#1e6dff; color:#fff; }
.btn-sw   { background:#f1f5f9; color:#374151; border-color:#e2e8f0; }
.btn-back { background:#f8fafc; color:#374151; border-color:#cbd5e1; }
.sep      { color:#cbd5e1; line-height:1; user-select:none; }

/* ── Strona druku ── */
.page { max-width:210mm; min-height:297mm; margin:1.2rem auto; background:#fff; padding:2.5cm 2.5cm 2.5cm 3cm; box-shadow:0 2px 16px rgba(0,0,0,.12); }

/* ── WKŁADKA ── */
.karta .org-header { text-align:center; border-bottom:2px solid #000; padding-bottom:9pt; margin-bottom:14pt; }
.karta .org-header .org-name { font-size:13.5pt; font-weight:bold; }
.karta .org-header .org-sub  { font-size:9.5pt; color:#444; margin-top:2pt; }
.karta h1 { font-size:15pt; text-align:center; text-transform:uppercase; letter-spacing:.04em; margin:0 0 3pt; }
.karta .doc-nr { text-align:center; font-size:11pt; color:#555; margin-bottom:5pt; }
.karta .miejsce-data { text-align:right; font-size:10pt; color:#555; margin-bottom:14pt; }
.karta .sec { font-size:10.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:.05em; color:#1e3a5f; margin:12pt 0 4pt; padding-bottom:2pt; border-bottom:1px solid #ccc; }
.karta table.dt { width:100%; border-collapse:collapse; margin-bottom:8pt; font-size:10pt; }
.karta table.dt td { border:1px solid #ccc; padding:2.5pt 5.5pt; vertical-align:top; }
.karta table.dt td:first-child { font-weight:bold; width:36%; background:#f1f5f9; }
.karta table.dt tr.hl td { background:#eff6ff; }
.karta table.dt tr.hl td:first-child { background:#dbeafe; }
.karta .adnotacje { margin-top:10pt; }
.karta .adnotacje .sec { margin-bottom:6pt; }
.karta .adnotacje-lines { display:flex; flex-direction:column; gap:10pt; }
.karta .adnotacje-line  { border-bottom:1px solid #ccc; height:14pt; }
.karta .sig-wrap { display:flex; justify-content:flex-end; margin-top:20pt; }
.karta .sig-block { width:45%; text-align:center; }
.karta .sig-line  { border-top:1px solid #000; margin-bottom:2pt; padding-top:2pt; }
.karta .sig-label { font-weight:bold; font-size:10.5pt; }
.karta .sig-sub   { font-size:9pt; color:#555; line-height:1.4; }

/* ── POTWIERDZENIE WOLONTARIUSZA ── */
.potw-wolo .miejsce-data { text-align:right; font-size:11pt; color:#444; margin-bottom:28pt; }
.potw-wolo h1 { font-size:16pt; text-align:center; text-transform:uppercase; letter-spacing:.05em; margin:0 0 4pt; }
.potw-wolo .doc-nr { text-align:center; font-size:11pt; color:#666; margin-bottom:26pt; }
.potw-wolo .wystawia { text-align:center; font-size:12pt; margin-bottom:8pt; }
.potw-wolo .vol-name { text-align:center; font-size:18pt; font-weight:bold; margin:10pt 0 4pt; }
.potw-wolo .vol-sub  { text-align:center; font-size:11pt; color:#444; margin-bottom:26pt; }
.potw-wolo .tresc { font-size:13pt; text-align:center; line-height:1.7; margin:0 0 10pt; }
.potw-wolo .zakres { font-size:11pt; text-align:center; color:#444; font-style:italic; margin-bottom:34pt; }
.potw-wolo .bottom { display:flex; justify-content:space-between; align-items:flex-end; margin-top:44pt; gap:20pt; }
.potw-wolo .stamp-box { width:130pt; height:78pt; border:1.5px dashed #aaa; border-radius:5pt; display:flex; align-items:center; justify-content:center; font-size:9pt; color:#aaa; text-align:center; line-height:1.4; }
.potw-wolo .sig-right { text-align:center; flex:1; }
.potw-wolo .sig-line  { border-top:1px solid #000; margin-bottom:2pt; padding-top:2pt; }
.potw-wolo .sig-label { font-weight:bold; font-size:11pt; margin-bottom:2pt; }
.potw-wolo .sig-sub   { font-size:9.5pt; color:#444; line-height:1.5; }

/* ── KOPERTA (wspólne) ── */
.koperta.page {
    padding: 16mm 18mm;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
}
.koperta.page.c4 { max-width: 229mm; min-height: 324mm; }
.koperta.page.a4 { max-width: 210mm; min-height: 297mm; }
.koperta .env-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0; }
.koperta .nadawca { font-size:9.5pt; line-height:1.5; max-width:52%; }
.koperta .nadawca .nad-label { font-size:8pt; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:3pt; }
.koperta .nadawca .nad-name  { font-weight:bold; font-size:10pt; }
.koperta .stamp-area {
  width:50mm; height:36mm;
  border:2px solid #000;
  border-radius:2pt;
  flex-shrink:0;
  display:flex; flex-direction:column; align-items:center; justify-content:flex-end;
  padding-bottom:4pt; gap:0;
  position:relative;
}
.koperta .stamp-area::before {
  content:'';
  display:block;
  width:38mm; height:24mm;
  border:1.5px dashed #aaa;
  position:absolute; top:4pt; left:50%; transform:translateX(-50%);
}
.koperta .stamp-area .stamp-label {
  font-size:7pt; color:#555; text-transform:uppercase; letter-spacing:.08em; text-align:center; line-height:1.3;
  position:relative; z-index:1;
}
.koperta .env-mid { flex:1; display:flex; align-items:center; justify-content:center; padding:20mm 0 10mm; }
.koperta .adresat { text-align:left; font-size:12.5pt; line-height:1.8; }
.koperta .adresat .adr-label { font-size:8pt; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:5pt; }
.koperta .adresat .adr-name  { font-size:14pt; font-weight:bold; margin-bottom:3pt; }
.koperta .env-ref { font-size:8pt; color:#aaa; text-align:right; padding-top:6mm; border-top:1px dashed #ddd; }

/* ── Barcode ── */
.env-barcode { text-align:center; padding-top:5mm; }
.env-barcode svg { max-width:100%; display:block; margin:0 auto; }
.env-barcode-meta { font-size:7pt; color:#555; letter-spacing:.12em; text-align:center; margin-top:2pt; line-height:1.5; }
.env-barcode-meta strong { color:#000; }
.env-barcode-pending { font-size:8pt; color:#aaa; text-align:center; border:1px dashed #ccc; padding:4mm 6mm; margin-top:5mm; border-radius:3pt; }

/* ── Modal rejestracji (no-print) ── */
.dispatch-modal-bg {
  display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9000;
  align-items:center; justify-content:center;
}
.dispatch-modal-bg.open { display:flex; }
.dispatch-modal {
  background:#fff; border-radius:10px; padding:1.5rem;
  max-width:540px; width:95%; max-height:90vh; overflow-y:auto;
  box-shadow:0 8px 40px rgba(0,0,0,.25);
  font-family:system-ui,sans-serif; font-size:.9rem;
}
.dispatch-modal h5 { margin:0 0 1rem; font-size:1.05rem; font-weight:700; }
.dispatch-modal label { display:block; font-size:.8rem; font-weight:600; color:#374151; margin-bottom:.25rem; margin-top:.75rem; }
.dispatch-modal select, .dispatch-modal input[type=text],
.dispatch-modal input[type=date], .dispatch-modal input[type=number] {
  width:100%; padding:.38rem .6rem; border:1px solid #d1d5db; border-radius:6px;
  font-size:.9rem; font-family:inherit;
}
.dispatch-modal .dm-row { display:flex; gap:.5rem; }
.dispatch-modal .dm-row > * { flex:1; }
.dispatch-modal .dm-section { display:none; margin-top:.5rem; padding:.75rem; background:#f8fafc; border-radius:6px; border:1px solid #e2e8f0; }
.dispatch-modal .dm-section.visible { display:block; }
.dispatch-modal .dm-actions { display:flex; gap:.5rem; justify-content:flex-end; margin-top:1.2rem; }
.dispatch-modal .btn-save { background:#1d4ed8; color:#fff; border:none; padding:.45rem 1.1rem; border-radius:6px; font-weight:600; cursor:pointer; font-size:.9rem; }
.dispatch-modal .btn-save:hover { background:#1e40af; }
.dispatch-modal .btn-cancel { background:#f1f5f9; color:#374151; border:1px solid #e2e8f0; padding:.45rem 1rem; border-radius:6px; cursor:pointer; font-size:.9rem; }
.dispatch-modal .dm-msg { font-size:.8rem; color:#374151; margin-top:.5rem; padding:.4rem .6rem; background:#f0fdf4; border-radius:4px; display:none; }
.dispatch-modal .dm-msg.err { background:#fef2f2; color:#b91c1c; }
.no-print-dispatch { /* visible only on-screen, not in print */ }
.btn-register-dispatch {
  display:inline-flex; align-items:center; gap:.4rem;
  background:#1d4ed8; color:#fff; border:none; padding:.4rem .9rem;
  border-radius:6px; font-size:.82rem; font-weight:600; cursor:pointer;
  font-family:system-ui,sans-serif;
}
.btn-register-dispatch:hover { background:#1e40af; }
.btn-registered {
  display:inline-flex; align-items:center; gap:.4rem;
  background:#f0fdf4; color:#166534; border:1px solid #bbf7d0;
  padding:.35rem .8rem; border-radius:6px; font-size:.82rem; font-weight:600;
  font-family:system-ui,sans-serif;
}

@media print {
    .no-print { display:none !important; }
    .dispatch-modal-bg { display:none !important; }
    .no-print-dispatch { display:none !important; }
    body { background:#fff; }
    .page { box-shadow:none; margin:0; }
    .page:not(.koperta) { padding:2.5cm 2.5cm 2.5cm 3cm; }
    .koperta.page { padding:16mm 18mm; }
}
</style>
<?php if (str_starts_with($typ, 'koperta')): ?>
<script src="<?= APP_URL ?>/assets/js/JsBarcode.code128.min.js"></script>
<?php endif; ?>
</head>
<body>
<?php if (!$is_preview): ?><script>setTimeout(()=>window.print(),600);</script><?php endif; ?>

<!-- Pasek narzędzi -->
<div class="no-print">
  <strong style="color:#1e3a5f;white-space:nowrap"><?= h($typ_labels[$typ] ?? '') ?></strong>
  <span class="sep">|</span>
  <?php if (!str_starts_with($typ, 'koperta')): ?>
    <a href="<?= $base_url ?>&typ=<?= $typ ?>&preview=1" class="btn-pdf" target="_blank">&#128424; Drukuj / PDF</a>
    <?php if (class_exists('ZipArchive')): ?>
    <a href="<?= $base_url ?>&typ=<?= $typ ?>&format=docx" class="btn-docx">&#128196; DOCX</a>
    <?php endif; ?>
    <span class="sep">|</span>
    <?php foreach ($typ_labels as $t => $lbl): ?>
    <?php if ($t !== $typ): ?>
    <a href="<?= $base_url ?>&typ=<?= $t ?>&preview=1" class="btn-sw" target="_blank">&#8596; <?= h($lbl) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
  <?php else: ?>
    <a href="<?= $base_url ?>&typ=<?= $typ ?>&preview=1" class="btn-pdf" target="_blank">&#128424; Drukuj / PDF</a>
    <span class="sep">|</span>
    <?php foreach ($typ_labels as $t => $lbl): ?>
    <?php if ($t !== $typ): ?>
    <a href="<?= $base_url ?>&typ=<?= $t ?>&preview=1" class="btn-sw" target="_blank">&#8596; <?= h($lbl) ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
  <?php if (str_starts_with($typ, 'koperta') && can_edit()): ?>
  <span class="sep">|</span>
  <?php if ($corr_row): ?>
    <span class="btn-registered">&#10003; <?= h($corr_row['number']) ?></span>
    <button type="button" class="btn-register-dispatch" style="background:#0369a1"
            onclick="tuOpen()">&#9998; Nr śledzenia</button>
  <?php else: ?>
    <button type="button" class="btn-register-dispatch" onclick="dmOpen()">&#128233; Rejestruj wysyłkę</button>
  <?php endif; ?>
  <?php endif; ?>
  <a href="view.php?id=<?= $id ?>" class="btn-back">&#8592; Wróć</a>
  <span style="font-size:.76rem;color:#94a3b8"><?= h($numer) ?></span>
</div>

<?php if ($typ === 'wkladka'): ?>
<!-- ═══════════════════════════════════════════════════
     WKŁADKA DO SEGREGATORA — jednostronna + adnotacje
     ═══════════════════════════════════════════════════ -->
<div class="page karta">

  <div class="org-header">
    <div class="org-name"><?= h($org_name) ?></div>
    <?php if ($org_adres): ?>
    <div class="org-sub"><?= h($org_adres) ?><?= $org_miasto ? ', ' . h($org_miasto) : '' ?></div>
    <?php endif; ?>
    <?php if ($org_nip || $org_krs): ?>
    <div class="org-sub"><?= $org_nip ? 'NIP: '.h($org_nip) : '' ?><?= ($org_nip&&$org_krs)?' &emsp; ':'' ?><?= $org_krs ? 'KRS: '.h($org_krs) : '' ?></div>
    <?php endif; ?>
  </div>

  <div class="miejsce-data"><?= h($org_miasto ?: '…………………') ?>, dnia <?= h($data_dzis) ?> r.</div>
  <h1>Karta Wolontariusza</h1>
  <div class="doc-nr">Porozumienie nr <strong><?= h($numer) ?></strong></div>

  <div class="sec">Dane wolontariusza</div>
  <table class="dt">
    <tr class="hl"><td>Imię i nazwisko:</td><td><strong><?= h($vol_name) ?></strong></td></tr>
    <?php if ($vol_pesel):   ?><tr><td>PESEL:</td><td class="font-monospace"><?= h($vol_pesel) ?></td></tr><?php endif; ?>
    <?php if ($vol_dob):     ?><tr><td>Data urodzenia:</td><td><?= h(date('d.m.Y', strtotime($vol_dob))) ?></td></tr><?php endif; ?>
    <?php if ($vol_adres):   ?><tr><td>Adres:</td><td><?= h($vol_adres) ?></td></tr><?php endif; ?>
    <?php if ($vol_email):   ?><tr><td>E-mail:</td><td><?= h($vol_email) ?></td></tr><?php endif; ?>
    <?php if ($vol_telefon): ?><tr><td>Telefon:</td><td><?= h($vol_telefon) ?></td></tr><?php endif; ?>
  </table>

  <div class="sec">Dane porozumienia</div>
  <table class="dt">
    <tr class="hl"><td>Numer:</td><td><strong><?= h($numer) ?></strong></td></tr>
    <tr><td>Data zawarcia:</td><td><?= h($data_zawar) ?></td></tr>
    <tr class="hl"><td>Okres:</td><td><strong><?= h($data_od) ?> – <?= h($data_do) ?></strong></td></tr>
    <tr><td>Miejsce:</td><td><?= h($miejsce) ?></td></tr>
    <?php if ($przedmiot): ?><tr><td>Zakres czynności:</td><td><?= nl2br(h($przedmiot)) ?></td></tr><?php endif; ?>
    <?php if ($projekt):   ?><tr><td>Projekt / program:</td><td><?= h($projekt) ?></td></tr><?php endif; ?>
    <?php if ($opiekun):   ?><tr><td>Opiekun:</td><td><?= h($opiekun) ?></td></tr><?php endif; ?>
    <tr><td>Wymiar godzinowy:</td><td><?= h($godziny) ?></td></tr>
    <tr><td>Szkolenie BHP:</td><td><?= h($bhp) ?></td></tr>
    <tr><td>Ubezpieczenie NNW:</td><td><?= h($nnw) ?></td></tr>
    <tr><td>Ubezpieczenie OC:</td><td><?= h($oc) ?></td></tr>
    <tr><td>Zwrot kosztów:</td><td><?= h($zwrot) ?></td></tr>
    <tr class="hl"><td>Status:</td><td><strong><?= h($status_txt) ?></strong></td></tr>
  </table>

  <!-- Adnotacje -->
  <div class="adnotacje">
    <div class="sec">Adnotacje</div>
    <div class="adnotacje-lines">
      <div class="adnotacje-line"></div>
      <div class="adnotacje-line"></div>
      <div class="adnotacje-line"></div>
      <div class="adnotacje-line"></div>
    </div>
  </div>

  <!-- Podpis jednostronny (tylko organizacja) -->
  <div class="sig-wrap">
    <div class="sig-block">
      <div class="sig-label">Organizacja</div>
      <div class="sig-line"></div>
      <div class="sig-sub"><?= h($org_repr ?: $org_name) ?><br><?= h($org_stanow) ?></div>
    </div>
  </div>

</div>

<?php elseif ($typ === 'wolontariusz'): ?>
<!-- ═══════════════════════════════════════════════════
     POTWIERDZENIE DLA WOLONTARIUSZA
     ═══════════════════════════════════════════════════ -->
<div class="page potw-wolo">

  <div class="miejsce-data"><?= h($org_miasto ?: '…………………') ?>, dnia <?= h($data_dzis) ?> r.</div>
  <h1>Potwierdzenie wolontariatu</h1>
  <div class="doc-nr">nr <strong><?= h($numer) ?></strong></div>

  <p class="wystawia"><?= h($org_name) ?> potwierdza, że</p>
  <div class="vol-name"><?= h($vol_name) ?></div>
  <div class="vol-sub">
    <?= $vol_pesel ? 'PESEL: '.h($vol_pesel) : '' ?>
    <?= ($vol_pesel && $vol_dob) ? ' &nbsp;·&nbsp; ' : '' ?>
    <?= $vol_dob ? 'ur. '.h(date('d.m.Y', strtotime($vol_dob))) : '' ?>
  </div>

  <p class="tresc">
    świadczy pracę wolontariacką na rzecz Organizacji<br>
    na podstawie Porozumienia Wolontariackiego nr <strong><?= h($numer) ?></strong><br>
    w okresie: <strong><?= h($data_od) ?> – <?= h($data_do) ?></strong>
    <?= $miejsce !== '—' ? '<br>w miejscu: <strong>'.h($miejsce).'</strong>' : '' ?>
  </p>
  <?php if ($przedmiot): ?>
  <p class="zakres">Zakres czynności: <?= h($przedmiot) ?></p>
  <?php endif; ?>

  <div class="bottom">
    <div class="stamp-box">Miejsce na pieczęć<br>organizacji</div>
    <div class="sig-right">
      <div class="sig-label">Wydaje:</div>
      <div class="sig-line"></div>
      <div class="sig-sub"><?= h($org_repr ?: $org_name) ?><br><?= h($org_stanow) ?><br><em><?= h($org_name) ?></em></div>
    </div>
  </div>

</div>

<?php elseif ($typ === 'koperta_a4'): ?>
<!-- ═══════════════════════════════════════════════════
     KOPERTA A4  (210 × 297 mm)
     ═══════════════════════════════════════════════════ -->
<div class="page koperta a4">

  <div class="env-top">
    <div class="nadawca">
      <div class="nad-label">Nadawca</div>
      <div class="nad-name"><?= h($org_name) ?></div>
      <?php if ($org_adres):  ?><div><?= h($org_adres) ?></div><?php endif; ?>
      <?php if ($org_miasto): ?><div><?= h($org_miasto) ?></div><?php endif; ?>
    </div>
    <div class="stamp-area"><span class="stamp-label">Miejsce<br>na znaczek</span></div>
  </div>

  <div class="env-mid">
    <div class="adresat">
      <div class="adr-label">Adresat</div>
      <?php if ($env_line1): ?><div class="adr-name"><?= h($env_line1) ?></div><?php endif; ?>
      <?php if ($env_line2): ?><div><?= h($env_line2) ?></div><?php endif; ?>
      <?php if ($env_line3): ?><div><?= h($env_line3) ?></div><?php endif; ?>
      <?php if (!$env_line1 && !$env_line2 && !$env_line3): ?>
      <div class="adr-name" style="color:#aaa">Brak adresu w systemie</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="env-barcode">
    <?php if ($s10_code): ?>
    <svg id="env-bc-a4"></svg>
    <div class="env-barcode-meta">
      <strong><?= h($s10_code) ?></strong><br>
      <?= h($corr_row['number'] ?? '') ?>
      <?php if ($corr_row['tracking_number'] ?? ''): ?>
       &nbsp;·&nbsp; nr przesyłki: <?= h($corr_row['tracking_number']) ?>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="env-barcode-pending">
      Porozumienie nr <?= h($numer) ?> &nbsp;·&nbsp; <?= h($org_name) ?><br>
      <small>Zarejestruj wysyłkę, aby wydrukować kod kreskowy S10</small>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php else: /* koperta_c4 */ ?>
<!-- ═══════════════════════════════════════════════════
     KOPERTA C4  (229 × 324 mm) — drukuj na C4
     ═══════════════════════════════════════════════════ -->
<div class="page koperta c4">

  <div class="env-top">
    <div class="nadawca">
      <div class="nad-label">Nadawca</div>
      <div class="nad-name"><?= h($org_name) ?></div>
      <?php if ($org_adres):  ?><div><?= h($org_adres) ?></div><?php endif; ?>
      <?php if ($org_miasto): ?><div><?= h($org_miasto) ?></div><?php endif; ?>
    </div>
    <div class="stamp-area"><span class="stamp-label">Miejsce<br>na znaczek</span></div>
  </div>

  <div class="env-mid">
    <div class="adresat">
      <div class="adr-label">Adresat</div>
      <?php if ($env_line1): ?><div class="adr-name"><?= h($env_line1) ?></div><?php endif; ?>
      <?php if ($env_line2): ?><div><?= h($env_line2) ?></div><?php endif; ?>
      <?php if ($env_line3): ?><div><?= h($env_line3) ?></div><?php endif; ?>
      <?php if (!$env_line1 && !$env_line2 && !$env_line3): ?>
      <div class="adr-name" style="color:#aaa">Brak adresu w systemie</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="env-barcode">
    <?php if ($s10_code): ?>
    <svg id="env-bc-c4"></svg>
    <div class="env-barcode-meta">
      <strong><?= h($s10_code) ?></strong><br>
      <?= h($corr_row['number'] ?? '') ?>
      <?php if ($corr_row['tracking_number'] ?? ''): ?>
       &nbsp;·&nbsp; nr przesyłki: <?= h($corr_row['tracking_number']) ?>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="env-barcode-pending">
      Porozumienie nr <?= h($numer) ?> &nbsp;·&nbsp; <?= h($org_name) ?><br>
      <small>Zarejestruj wysyłkę, aby wydrukować kod kreskowy S10</small>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php endif; ?>

<?php if (str_starts_with($typ, 'koperta') && can_edit()): ?>
<!-- ══════════════════════════════════════
     MODAL — Rejestracja wysyłki wychodzącej
     ══════════════════════════════════════ -->
<div class="dispatch-modal-bg no-print" id="dmBg">
  <div class="dispatch-modal">
    <h5>&#128233; Rejestracja wysyłki wychodzącej</h5>

    <label>Przewoźnik *</label>
    <select id="dmCarrier" onchange="dmCarrierChange()">
      <option value="">— wybierz —</option>
      <?php foreach (carrier_labels() as $ckey => $clabel): ?>
      <option value="<?= h($ckey) ?>"><?= h($clabel) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Data nadania *</label>
    <input type="date" id="dmDate" value="<?= date('Y-m-d') ?>">

    <label>Opis / przedmiot przesyłki</label>
    <input type="text" id="dmSubject"
           value="<?= h('Korespondencja do: ' . $vol_name . ' — ' . $numer) ?>">

    <label>Nr śledzenia (uzupełnij po nadaniu — opcjonalnie)</label>
    <input type="text" id="dmTracking" placeholder="np. RR123456785PL lub numer kuriera">

    <!-- Pola Apaczka (ukryte dopóki nie wybrano kuriera AP) -->
    <div class="dm-section" id="dmApaczka">
      <strong style="font-size:.82rem">Parametry przesyłki (Apaczka.pl)</strong>
      <label>ID usługi Apaczka *</label>
      <input type="number" id="dmServiceId" placeholder="np. 8 (DPD Classic)" min="1">
      <label>Waga (kg)</label>
      <input type="number" id="dmWeight" value="0.5" step="0.1" min="0.1">
      <label>Wymiary (cm): dł. × szer. × wys.</label>
      <div class="dm-row">
        <input type="number" id="dmD1" placeholder="dł." value="30" min="1">
        <input type="number" id="dmD2" placeholder="szer." value="20" min="1">
        <input type="number" id="dmD3" placeholder="wys." value="5" min="1">
      </div>
      <label>Odbiór</label>
      <select id="dmPickup">
        <option value="SELF">Nadaję samodzielnie (drop-off)</option>
        <option value="COURIER">Odbiór przez kuriera</option>
      </select>
      <?php if ($carrier === 'inpost_paczkomat' || true): ?>
      <div id="dmPaczkomat" style="display:none">
        <label>Numer paczkomatu</label>
        <input type="text" id="dmPointId" placeholder="np. WAW001">
      </div>
      <?php endif; ?>
      <label>
        <input type="checkbox" id="dmOrderNow" checked>
        Złóż zamówienie przez Apaczka.pl API teraz
      </label>
    </div>

    <div class="dm-msg" id="dmMsg"></div>

    <div class="dm-actions">
      <button type="button" class="btn-cancel" onclick="dmClose()">Anuluj</button>
      <button type="button" class="btn-save" id="dmSaveBtn" onclick="dmSave()">
        &#128190; Zarejestruj
      </button>
    </div>
  </div>
</div>

<script>
(function(){
  var SAVE_URL = <?= json_encode(APP_URL . '/correspondence/dispatch_save.php') ?>;
  var BASE_URL = <?= json_encode($base_url . '&typ=' . $typ . '&preview=1') ?>;
  var CSRF     = <?= json_encode(csrf_token()) ?>;
  var CARRIER_APACZKA = <?= json_encode(array_keys(array_filter(carrier_labels(), fn($k) => carrier_uses_apaczka($k), ARRAY_FILTER_USE_KEY))) ?>;

  window.dmOpen  = function(){ document.getElementById('dmBg').classList.add('open'); }
  window.dmClose = function(){ document.getElementById('dmBg').classList.remove('open'); }

  window.dmCarrierChange = function(){
    var c = document.getElementById('dmCarrier').value;
    var ap = document.getElementById('dmApaczka');
    var pm = document.getElementById('dmPaczkomat');
    if (CARRIER_APACZKA.indexOf(c) >= 0) {
      ap.classList.add('visible');
    } else {
      ap.classList.remove('visible');
    }
    pm.style.display = (c === 'inpost_paczkomat') ? 'block' : 'none';
  }

  window.dmSave = function(){
    var carrier = document.getElementById('dmCarrier').value;
    if (!carrier) { dmMsg('Wybierz przewoźnika.', true); return; }

    var useApaczka = CARRIER_APACZKA.indexOf(carrier) >= 0;
    var btn = document.getElementById('dmSaveBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Rejestruję…';

    var payload = {
      csrf_token:    CSRF,
      carrier:       carrier,
      contract_type: 'wolontariat',
      contract_id:   <?= $id ?>,
      correspondent: <?= json_encode($env_line1 ?: $vol_name) ?>,
      subject:       document.getElementById('dmSubject').value,
      dispatch_date: document.getElementById('dmDate').value,
      tracking_number: document.getElementById('dmTracking').value,
    };

    if (useApaczka && document.getElementById('dmOrderNow').checked) {
      payload.order_apaczka = 1;
      payload.service_id = document.getElementById('dmServiceId').value;
      payload.weight     = document.getElementById('dmWeight').value;
      payload.dim1       = document.getElementById('dmD1').value;
      payload.dim2       = document.getElementById('dmD2').value;
      payload.dim3       = document.getElementById('dmD3').value;
      payload.pickup_type = document.getElementById('dmPickup').value;
      if (carrier === 'inpost_paczkomat') {
        payload.paczkomat_id = document.getElementById('dmPointId').value;
      }
    }

    fetch(SAVE_URL, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload),
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
      btn.disabled = false;
      btn.textContent = '💾 Zarejestruj';
      if (data.ok) {
        dmMsg(data.msg || 'Zarejestrowano.', false);
        setTimeout(function(){
          window.location.href = BASE_URL + '&corr_id=' + data.corr_id;
        }, 900);
      } else {
        dmMsg(data.msg || 'Błąd zapisu.', true);
      }
    })
    .catch(function(e){
      btn.disabled = false;
      btn.textContent = '💾 Zarejestruj';
      dmMsg('Błąd połączenia: ' + e.message, true);
    });
  }

  function dmMsg(t, isErr){
    var el = document.getElementById('dmMsg');
    el.textContent = t;
    el.style.display = 'block';
    el.classList.toggle('err', isErr);
  }

  // Zainicjuj barcode jeśli S10 dostępny
  var s10 = <?= json_encode($s10_code) ?>;
  if (s10 && typeof JsBarcode !== 'undefined') {
    var svgIds = ['env-bc-a4', 'env-bc-c4'];
    svgIds.forEach(function(sid){
      var el = document.getElementById(sid);
      if (el) {
        JsBarcode(el, s10, {
          format: 'CODE128',
          width: 1.6,
          height: 38,
          displayValue: false,
          margin: 0,
          background: 'transparent',
        });
      }
    });
  }

  // Kliknięcie poza modal zamyka
  document.getElementById('dmBg').addEventListener('click', function(e){
    if (e.target === this) dmClose();
  });

  // ── Ręczna edycja numeru śledzenia (gdy corr_id już istnieje) ─────────────
  <?php if ($corr_row): ?>
  var TU_URL   = <?= json_encode(APP_URL . '/correspondence/tracking_update.php') ?>;
  var TU_CORR  = <?= $corr_id ?>;
  var TU_CURRENT = <?= json_encode($corr_row['tracking_number'] ?? '') ?>;

  window.tuOpen = function(){
    document.getElementById('tuInput').value = TU_CURRENT;
    document.getElementById('tuMsg').style.display = 'none';
    document.getElementById('tuBg').classList.add('open');
  }
  window.tuClose = function(){
    document.getElementById('tuBg').classList.remove('open');
  }
  window.tuSave = function(){
    var val = document.getElementById('tuInput').value.trim();
    var btn = document.getElementById('tuSaveBtn');
    btn.disabled = true; btn.textContent = '⏳';
    fetch(TU_URL, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({csrf_token: CSRF, corr_id: TU_CORR, tracking_number: val}),
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false; btn.textContent = '✔ Zapisz';
      var msg = document.getElementById('tuMsg');
      msg.textContent = d.msg || (d.ok ? 'Zapisano.' : 'Błąd.');
      msg.style.display = 'block';
      msg.className = 'dm-msg' + (d.ok ? '' : ' err');
      if (d.ok) {
        TU_CURRENT = val;
        // Odśwież meta pod barkodem bez przeładowania strony
        var metas = document.querySelectorAll('.env-barcode-meta');
        metas.forEach(function(m){
          var lines = m.innerHTML.split('<br>');
          if (lines.length >= 2) {
            // aktualizuj drugą linię
            var base = lines[0];
            var s10Part = <?= json_encode($corr_row['number'] ?? '') ?>;
            lines[1] = s10Part + (val ? ' &nbsp;·&nbsp; nr przesyłki: ' + val : '');
            m.innerHTML = lines.join('<br>');
          }
        });
        setTimeout(tuClose, 1200);
      }
    })
    .catch(function(e){
      btn.disabled = false; btn.textContent = '✔ Zapisz';
      var msg = document.getElementById('tuMsg');
      msg.textContent = 'Błąd: ' + e.message; msg.style.display = 'block'; msg.className = 'dm-msg err';
    });
  }
  document.getElementById('tuBg').addEventListener('click', function(e){
    if (e.target === this) tuClose();
  });
  <?php endif; ?>
})();
</script>

<?php if ($corr_row): ?>
<!-- Modal: ręczna aktualizacja numeru śledzenia -->
<div class="dispatch-modal-bg no-print" id="tuBg">
  <div class="dispatch-modal" style="max-width:380px">
    <h5>&#9998; Numer śledzenia przesyłki</h5>
    <p style="font-size:.82rem;color:#555;margin:.25rem 0 .75rem">
      Wpisz numer nadany przez przewoźnika lub uzyskany z Poczty Polskiej.<br>
      Pojawi się pod kodem kreskowym na kopercie.
    </p>
    <label>Numer śledzenia</label>
    <input type="text" id="tuInput"
           placeholder="np. RR123456785PL lub numer kuriera"
           style="font-family:monospace">
    <div class="dm-msg" id="tuMsg"></div>
    <div class="dm-actions">
      <button type="button" class="btn-cancel" onclick="tuClose()">Anuluj</button>
      <button type="button" class="btn-save" id="tuSaveBtn" onclick="tuSave()">&#10004; Zapisz</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
</body>
</html>
