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
$koresp_kraj     = trim($row['adres_kraj']      ?? '');
// Adres na kopertę — preferuj korespondencyjny, fallback na główny
if ($koresp_linia1) {
    $env_lines = array_filter([$koresp_odbiorca, $koresp_linia1, $koresp_linia2, $koresp_kraj ?: '']);
} else {
    $env_lines = array_filter([$vol_name, $vol_street ?: null, $vol_postal ?: null]);
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
.koperta .stamp-area { width:50mm; height:35mm; border:1.5px dashed #aaa; border-radius:4pt; display:flex; align-items:center; justify-content:center; font-size:8.5pt; color:#bbb; text-align:center; line-height:1.5; flex-shrink:0; }
.koperta .env-mid { flex:1; display:flex; align-items:center; justify-content:center; padding:20mm 0 10mm; }
.koperta .adresat { text-align:left; font-size:12.5pt; line-height:1.8; }
.koperta .adresat .adr-label { font-size:8pt; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:5pt; }
.koperta .adresat .adr-name  { font-size:14pt; font-weight:bold; margin-bottom:3pt; }
.koperta .env-ref { font-size:8pt; color:#aaa; text-align:right; padding-top:6mm; border-top:1px dashed #ddd; }

@media print {
    .no-print { display:none !important; }
    body { background:#fff; }
    .page { box-shadow:none; margin:0; }
    .page:not(.koperta) { padding:2.5cm 2.5cm 2.5cm 3cm; }
    .koperta.page { padding:16mm 18mm; }
}
</style>
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
      <?php if ($org_adres): ?>
      <div><?= h($org_adres) ?></div>
      <?php endif; ?>
      <?php if ($org_miasto): ?><div><?= h($org_miasto) ?></div><?php endif; ?>
    </div>
    <div class="stamp-area">znaczek<br>pocztowy</div>
  </div>

  <div class="env-mid">
    <div class="adresat">
      <div class="adr-label">Adresat</div>
      <?php foreach ($env_lines as $line): ?>
      <?php if ($line === $vol_name): ?>
      <div class="adr-name"><?= h($line) ?></div>
      <?php else: ?>
      <div><?= h($line) ?></div>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php if (empty($env_lines)): ?>
      <div class="adr-name" style="color:#aaa">Brak adresu w systemie</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="env-ref">Porozumienie nr <?= h($numer) ?> &nbsp;·&nbsp; <?= h($org_name) ?></div>

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
      <?php if ($org_adres): ?>
      <div><?= h($org_adres) ?></div>
      <?php endif; ?>
      <?php if ($org_miasto): ?><div><?= h($org_miasto) ?></div><?php endif; ?>
    </div>
    <div class="stamp-area">znaczek<br>pocztowy</div>
  </div>

  <div class="env-mid">
    <div class="adresat">
      <div class="adr-label">Adresat</div>
      <?php foreach ($env_lines as $line): ?>
      <?php if ($line === $vol_name): ?>
      <div class="adr-name"><?= h($line) ?></div>
      <?php else: ?>
      <div><?= h($line) ?></div>
      <?php endif; ?>
      <?php endforeach; ?>
      <?php if (empty($env_lines)): ?>
      <div class="adr-name" style="color:#aaa">Brak adresu w systemie</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="env-ref">Porozumienie nr <?= h($numer) ?> &nbsp;·&nbsp; <?= h($org_name) ?></div>

</div>

<?php endif; ?>
</body>
</html>
