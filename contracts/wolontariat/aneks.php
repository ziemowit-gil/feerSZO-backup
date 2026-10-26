<?php
/**
 * contracts/wolontariat/aneks.php
 * Generator aneksu do porozumienia wolontariackiego.
 *
 * GET ?id=X           — ID NOWEJ umowy (po przedłużeniu np. W-2025-001/P/1)
 * GET &format=pdf     — podgląd HTML do druku (domyślnie)
 * GET &format=docx    — pobierz plik .docx (Open XML przez ZipArchive)
 * GET &preview=1      — HTML bez auto-print
 *
 * Numer aneksu: NUMER_BAZOWY/P/1, NUMER_BAZOWY/P/2 itd.
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$id     = (int)($_GET['id']     ?? 0);
$format = in_array($_GET['format'] ?? '', ['pdf','docx']) ? $_GET['format'] : 'pdf';

if (!$id) { http_response_code(400); exit('Brak ID.'); }

$row = db_one("SELECT * FROM umowy_wolontariat WHERE id = ?", [$id]);
if (!$row) { http_response_code(404); exit('Nie znaleziono umowy.'); }
if (!viewer_owns_contract('wolontariat', $row) && !can_edit()) {
    http_response_code(403); exit('Brak dostępu.');
}

// ── Parsuj numer: BAZA/Pn (np. W-2025-001/P2) ────────────────────────────────
$numer_pelny = $row['numer_umowy'];                   // np. W-2025-001/P2
preg_match('|^(.*)/P(\d+)$|', $numer_pelny, $pm);
$numer_orig  = $pm[1] ?? $numer_pelny;               // W-2025-001
$p_nr        = $pm[2] ?? '1';                        // 2
$numer_aneksu = $numer_orig . '/P' . $p_nr;          // W-2025-001/P2

// Umowa bazowa (oryginalna) do porównania
$base_row = db_one("SELECT * FROM umowy_wolontariat WHERE numer_umowy = ?", [$numer_orig]);

// ── Dane organizacji ──────────────────────────────────────────────────────────
$org_name   = org_setting('org_name')              ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres  = org_setting('org_adres')             ?: '';
$org_miasto = org_setting('org_miejscowosc')       ?: '';
$org_nip    = org_setting('org_nip')               ?: '';
$org_krs    = org_setting('org_krs')               ?: '';
$org_repr   = org_setting('org_reprezentant')      ?: '';
$org_stanow = org_setting('org_stanowisko_repr')   ?: 'Prezes Zarządu';

// ── Pełne dane wolontariusza ──────────────────────────────────────────────────
$vol_name    = $row['imie_nazwisko']          ?? '';
$vol_pesel   = $row['pesel']                  ?? '';
$vol_dob     = $row['data_urodzenia']         ?? '';
$vol_adres   = trim(implode(', ', array_filter([
    trim(($row['addr_street'] ?? '') . ' ' . ($row['addr_house'] ?? '') . ($row['addr_flat'] ? '/' . $row['addr_flat'] : '')),
    ($row['addr_postal'] ?? '') . ' ' . ($row['addr_city'] ?? ''),
]))) ?: ($row['adres'] ?? '');
$vol_telefon = $row['telefon']                ?? '';
$vol_email   = $row['email']                  ?? '';

// ── Dane umowy (nowej / aneksu) ───────────────────────────────────────────────
$data_aneksu   = date('d.m.Y');
$data_zawarcia = $row['data_zawarcia']    ? date('d.m.Y', strtotime($row['data_zawarcia']))    : '—';
$data_od       = $row['data_rozpoczecia'] ? date('d.m.Y', strtotime($row['data_rozpoczecia'])) : '—';
$data_do       = !empty($row['bezterminowa'])
    ? 'czas nieokreślony'
    : ($row['data_zakonczenia'] ? date('d.m.Y', strtotime($row['data_zakonczenia'])) : '—');

// Poprzedni okres (z umowy bazowej)
$data_od_prev  = ($base_row && $base_row['data_rozpoczecia'])
    ? date('d.m.Y', strtotime($base_row['data_rozpoczecia'])) : '';
$data_do_prev  = ($base_row && !empty($base_row['bezterminowa']))
    ? 'czas nieokreślony'
    : (($base_row && $base_row['data_zakonczenia']) ? date('d.m.Y', strtotime($base_row['data_zakonczenia'])) : '');

$miejsce      = $row['miejsce_wolontariatu']         ?? '—';
$przedmiot    = $row['przedmiot_porozumienia']       ?? '';
$projekt      = $row['projekt_program']              ?? '';
$opiekun      = $row['opiekun']                      ?? '';
$godziny      = $row['godzin_tygodniowo']
    ? $row['godzin_tygodniowo'] . ' godz./tydzień' : '—';
$bhp          = !empty($row['szkolenie_bhp'])
    ? ('Tak' . ($row['data_szkolenia_bhp'] ? ', data: ' . date('d.m.Y', strtotime($row['data_szkolenia_bhp'])) : ''))
    : 'Nie';
$nnw          = !empty($row['ubezpieczenie_nnw'])
    ? ('Tak' . ($row['numer_polisy_nnw'] ? ', polisa: ' . $row['numer_polisy_nnw'] : ''))
    : 'Nie';
$oc           = !empty($row['ubezpieczenie_oc'])  ? 'Tak' : 'Nie';
$zwrot        = !empty($row['zwrot_kosztow'])
    ? ('Tak' . ($row['limit_zwrotu_kosztow'] ? ', limit: ' . number_format((float)$row['limit_zwrotu_kosztow'], 2, ',', ' ') . ' zł' : ''))
    : 'Nie';
$nr_rejestru  = $row['nr_rejestru'] ?? '';

// ── Helpers ───────────────────────────────────────────────────────────────────
function _w(string $text): string {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function _p(string $text, bool $bold = false, string $align = 'both', int $sz = 24): string {
    $b  = $bold ? '<w:b/><w:bCs/>' : '';
    $jc = "<w:jc w:val=\"{$align}\"/>";
    return "<w:p><w:pPr>{$jc}<w:spacing w:after=\"80\"/></w:pPr>"
         . "<w:r><w:rPr>{$b}<w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/></w:rPr>"
         . "<w:t xml:space=\"preserve\">" . _w($text) . "</w:t></w:r></w:p>";
}
function _trow(string $lbl, string $val, bool $highlight = false): string {
    $shd = $highlight ? '<w:shd w:fill="FFF9C4" w:val="clear"/>' : '<w:shd w:fill="F8FAFC" w:val="clear"/>';
    return "<w:tr>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"3200\" w:type=\"dxa\"/>{$shd}</w:tcPr>"
        . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>" . _w($lbl) . "</w:t></w:r></w:p></w:tc>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4800\" w:type=\"dxa\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t xml:space=\"preserve\">" . _w($val) . "</w:t></w:r></w:p></w:tc>"
        . "</w:tr>";
}
function _section(string $title, int $sz = 24): string {
    return "<w:p><w:pPr><w:jc w:val=\"center\"/><w:spacing w:before=\"200\" w:after=\"80\"/></w:pPr>"
         . "<w:r><w:rPr><w:b/><w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/></w:rPr>"
         . "<w:t>" . _w($title) . "</w:t></w:r></w:p>";
}

/** Obraz PNG jako <w:drawing> inline (wymiary w punktach) — do stopki DOCX składanej ręcznie. */
function _img_inline(string $rid, int $n, int $wpt, int $hpt, string $name): string {
    $cx = $wpt * 12700; $cy = $hpt * 12700;
    return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
         . '<wp:docPr id="' . $n . '" name="' . _w($name) . '"/>'
         . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
         . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $n . '" name="' . _w($name) . '"/><pic:cNvPicPr/></pic:nvPicPr>'
         . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
         . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
         . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

// ── Kody do stopki (Code128 + QR + numer) — jeden stały kod aneksu w rejestrze dokumentów ─────────────────────────
require_once dirname(dirname(__DIR__)) . '/modules/doc_registry/lib/DocRegistry.php';
$_an_title = 'Aneks do porozumienia wolontariackiego #' . $id;
$_an_path  = '/contracts/wolontariat/view.php?id=' . $id;

// ── DOCX ──────────────────────────────────────────────────────────────────────
if ($format === 'docx') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('Brak rozszerzenia ZipArchive. Użyj formatu PDF.');
    }

    $tmp = sys_get_temp_dir() . '/aneks_' . $id . '_' . time() . '.docx';

    $parts = [
        // Nagłówek
        _p('ANEKS NR ' . $p_nr, true, 'center', 28),
        _p('do Porozumienia Wolontariackiego nr ' . $numer_orig, false, 'center', 22),
        _p('numer aneksu: ' . $numer_aneksu, false, 'center', 20),
        _p(''),

        // Strony
        _p('Zawarty dnia ' . $data_aneksu . ' r. w ' . ($org_miasto ?: '…………………') . ' pomiędzy:'),
        _p(''),
        _p('1. ' . $org_name, true),
        $org_adres  ? _p('   z siedzibą: ' . $org_adres . ($org_miasto ? ', ' . $org_miasto : '')) : '',
        $org_nip    ? _p('   NIP: ' . $org_nip . ($org_krs ? '   KRS: ' . $org_krs : '')) : '',
        $org_repr   ? _p('   reprezentowaną przez: ' . $org_repr . ' — ' . $org_stanow) : '',
        _p('   zwaną dalej "Organizacją",'),
        _p(''),
        _p('a'),
        _p(''),
        _p('2. ' . $vol_name, true),
        $vol_pesel  ? _p('   PESEL: ' . $vol_pesel) : '',
        $vol_dob    ? _p('   Data urodzenia: ' . date('d.m.Y', strtotime($vol_dob))) : '',
        $vol_adres  ? _p('   Adres: ' . $vol_adres) : '',
        $vol_telefon? _p('   Tel.: ' . $vol_telefon) : '',
        $vol_email  ? _p('   E-mail: ' . $vol_email) : '',
        _p('   zwanym/a dalej "Wolontariuszem".'),
        _p(''),

        _section('§ 1  Podstawa'),
        _p('Na podstawie art. 44 ust. 2 ustawy z dnia 24 kwietnia 2003 r. o działalności pożytku publicznego '
           . 'i o wolontariacie Strony postanawiają zmienić warunki porozumienia wolontariackiego nr '
           . $numer_orig . ' zgodnie z poniższymi ustaleniami.'),
        _p(''),

        _section('§ 2  Pełne dane porozumienia po zmianie'),

        // Tabela — pełne dane
        "<w:tbl><w:tblPr>"
        . "<w:tblW w:w=\"8000\" w:type=\"dxa\"/>"
        . "<w:tblBorders>"
        . "<w:top w:val=\"single\" w:sz=\"4\"/><w:left w:val=\"single\" w:sz=\"4\"/>"
        . "<w:bottom w:val=\"single\" w:sz=\"4\"/><w:right w:val=\"single\" w:sz=\"4\"/>"
        . "<w:insideH w:val=\"single\" w:sz=\"4\"/><w:insideV w:val=\"single\" w:sz=\"4\"/>"
        . "</w:tblBorders></w:tblPr>"
        . _trow('Numer umowy (oryginał):', $numer_orig)
        . _trow('Numer aneksu:', $numer_aneksu, true)
        . _trow('Data zawarcia aneksu:', $data_aneksu, true)
        . _trow('Poprzedni okres:', $data_od_prev && $data_do_prev ? $data_od_prev . ' – ' . $data_do_prev : '—')
        . _trow('Nowy okres obowiązywania:', $data_od . ' – ' . $data_do, true)
        . _trow('Miejsce wolontariatu:', $miejsce)
        . _trow('Wymiar godzinowy:', $godziny)
        . ($przedmiot ? _trow('Zakres czynności:', $przedmiot) : '')
        . ($projekt   ? _trow('Projekt / program:', $projekt)   : '')
        . ($opiekun   ? _trow('Opiekun:', $opiekun)             : '')
        . _trow('Szkolenie BHP:', $bhp)
        . _trow('Ubezpieczenie NNW:', $nnw)
        . _trow('Ubezpieczenie OC:', $oc)
        . _trow('Zwrot kosztów:', $zwrot)
        . ($nr_rejestru ? _trow('Nr rejestru:', $nr_rejestru) : '')
        . "</w:tbl>",

        _p(''),
        _section('§ 3  Pozostałe postanowienia'),
        _p('Pozostałe postanowienia porozumienia nr ' . $numer_orig . ' pozostają bez zmian.'),
        _p(''),
        _section('§ 4  Egzemplarze'),
        _p('Aneks sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron. '
           . 'Aneks wchodzi w życie z dniem podpisania przez obie Strony.'),
        _p(''), _p(''), _p(''),

        // Podpisy
        "<w:tbl><w:tblPr><w:tblW w:w=\"9000\" w:type=\"dxa\"/></w:tblPr>"
        . "<w:tr>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4200\" w:type=\"dxa\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>…………………………………………………</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>Organizacja</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _w($org_repr ?: $org_name) . "</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _w($org_stanow) . "</w:t></w:r></w:p>"
        . "</w:tc>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"600\" w:type=\"dxa\"/></w:tcPr><w:p/></w:tc>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4200\" w:type=\"dxa\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>…………………………………………………</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>Wolontariusz</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _w($vol_name) . "</w:t></w:r></w:p>"
        . ($vol_pesel ? "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>PESEL: " . _w($vol_pesel) . "</w:t></w:r></w:p>" : '')
        . "</w:tc>"
        . "</w:tr></w:tbl>",
    ];

    $body_content = implode("\n", array_filter($parts));

    $codes = DocRegistry::printCodes('wol_aneks', (string)$id, $_an_title, $_an_path);
    if ($codes && (empty($codes['bar']) || empty($codes['qr']))) $codes = null;

    $doc_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>' . $body_content
        . '<w:sectPr>' . ($codes ? '<w:footerReference w:type="default" r:id="rId2"/>' : '')
        . '<w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1800" w:header="708" w:footer="708"/>'
        . '</w:sectPr></w:body></w:document>';

    $rels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $word_rels = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . ($codes ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>' : '')
        . '</Relationships>';

    // Stopka z kodami: Code128 + QR w jednym wierszu, pod spodem kod · numer · adres weryfikacji
    $footer_xml = $footer_rels = '';
    if ($codes) {
        $footer_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>'
            . _img_inline('rId1', 1, 130, 17, 'Kod kreskowy') . _img_inline('rId2', 2, 26, 26, 'Kod QR') . '</w:p>'
            . '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="13"/></w:rPr>'
            . '<w:t xml:space="preserve">' . _w($codes['code'] . ' · ' . $codes['doc_number'] . ' · weryfikacja: ' . $codes['url']) . '</w:t></w:r></w:p></w:ftr>';
        $footer_rels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/bar.png"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/qr.png"/>'
            . '</Relationships>';
    }

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
        . '<Default Extension="png" ContentType="image/png"/>'
        . ($codes ? '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>' : '')
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
    if ($codes) {
        $zip->addFromString('word/footer1.xml',              $footer_xml);
        $zip->addFromString('word/_rels/footer1.xml.rels',   $footer_rels);
        $zip->addFromString('word/media/bar.png',            $codes['bar']);
        $zip->addFromString('word/media/qr.png',             $codes['qr']);
    }
    $zip->close();

    $fn = 'aneks_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $numer_aneksu) . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ── HTML / PDF ─────────────────────────────────────────────────────────────────
$is_preview = isset($_GET['preview']);
$codes_html = DocRegistry::printFooterHtml('wol_aneks', (string)$id, $_an_title, $_an_path);   // stopka z kodami (Code128 + QR)
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aneks <?= h($numer_aneksu) ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; margin:0; padding:0; background:#f5f5f5; }
.no-print { background:#fff; border-bottom:1px solid #ddd; padding:.7rem 1.25rem; display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; font-family:system-ui,sans-serif; }
.no-print button, .no-print a { padding:.42rem 1rem; border-radius:6px; font-size:.86rem; font-weight:600; cursor:pointer; text-decoration:none; border:none; }
.btn-pdf  { background:#dc2626; color:#fff; }
.btn-docx { background:#1e6dff; color:#fff; }
.btn-back { background:#f1f5f9; color:#374151; border:1px solid #e2e8f0 !important; }
.page { max-width:210mm; min-height:297mm; margin:1.5rem auto; background:#fff; padding:2.5cm 2.5cm 2.5cm 3cm; box-shadow:0 2px 16px rgba(0,0,0,.12); }
h1 { font-size:15pt; text-align:center; margin:0 0 3pt; text-transform:uppercase; letter-spacing:.04em; }
.subtitle { text-align:center; font-size:11pt; margin-bottom:4pt; color:#444; }
.aneks-nr { text-align:center; font-size:10pt; color:#555; margin-bottom:22pt; }
p { margin:0 0 7pt; line-height:1.6; text-align:justify; }
.sec { font-size:12pt; font-weight:bold; text-align:center; margin:14pt 0 7pt; }
.parties { margin:6pt 0 14pt; }
.party { margin-bottom:8pt; padding-left:1.5em; }
.party strong { display:block; margin-left:-1.5em; }
table.dt { width:100%; border-collapse:collapse; margin:8pt 0 14pt; font-size:11pt; }
table.dt td { border:1px solid #bbb; padding:4pt 7pt; vertical-align:top; }
table.dt td:first-child { font-weight:bold; width:40%; background:#f8fafc; }
table.dt tr.changed td { background:#fffde7; }
table.dt tr.changed td:first-child { background:#fff9c4; }
.doc-foot { margin-top:26pt; padding-top:6pt; border-top:1px solid #bbb; }
.signatures { display:flex; justify-content:space-between; margin-top:44pt; gap:32pt; }
.sig-block { flex:1; text-align:center; }
.sig-line { border-top:1px solid #000; margin-bottom:3pt; padding-top:2pt; }
.sig-label { font-weight:bold; font-size:11pt; margin-bottom:3pt; }
.sig-sub { font-size:9.5pt; color:#444; line-height:1.4; }
@media print {
  .no-print { display:none !important; }
  body { background:#fff; }
  .page { box-shadow:none; margin:0; padding:2.5cm 2.5cm 2.5cm 3cm; }
}
</style>
</head>
<body>
<?php if (!$is_preview): ?><script>setTimeout(()=>window.print(),600);</script><?php endif; ?>

<div class="no-print">
  <a href="aneks.php?id=<?= $id ?>&format=pdf&preview=1" class="btn-pdf" target="_blank">🖨 Drukuj / PDF</a>
  <?php if (class_exists('ZipArchive')): ?>
  <a href="aneks.php?id=<?= $id ?>&format=docx" class="btn-docx">📄 DOCX (Word)</a>
  <?php endif; ?>
  <a href="view.php?id=<?= $id ?>" class="btn-back">← Wróć do umowy</a>
  <span style="font-size:.8rem;color:#64748b">Aneks <?= h($numer_aneksu) ?></span>
</div>

<div class="page">

  <h1>Aneks nr <?= h($p_nr) ?></h1>
  <div class="subtitle">do Porozumienia Wolontariackiego nr <strong><?= h($numer_orig) ?></strong></div>
  <div class="aneks-nr">numer aneksu: <strong><?= h($numer_aneksu) ?></strong></div>

  <p>Zawarty dnia <strong><?= h($data_aneksu) ?> r.</strong> w <?= h($org_miasto ?: '…………………') ?> pomiędzy:</p>

  <div class="parties">
    <div class="party">
      <strong>1. <?= h($org_name) ?></strong>
      <?php if ($org_adres): ?>z siedzibą: <?= h($org_adres) ?><?= $org_miasto ? ', ' . h($org_miasto) : '' ?><br><?php endif; ?>
      <?php if ($org_nip): ?>NIP: <?= h($org_nip) ?><?= $org_krs ? ' &emsp; KRS: ' . h($org_krs) : '' ?><br><?php endif; ?>
      <?php if ($org_repr): ?>reprezentowaną przez: <?= h($org_repr) ?> — <?= h($org_stanow) ?><br><?php endif; ?>
      zwaną dalej <strong>„Organizacją"</strong>,
    </div>
    <p><strong>a</strong></p>
    <div class="party">
      <strong>2. <?= h($vol_name) ?></strong>
      <?php if ($vol_pesel):   ?>PESEL: <?= h($vol_pesel) ?><br><?php endif; ?>
      <?php if ($vol_dob):     ?>ur. <?= h(date('d.m.Y', strtotime($vol_dob))) ?><br><?php endif; ?>
      <?php if ($vol_adres):   ?>zam.: <?= h($vol_adres) ?><br><?php endif; ?>
      <?php if ($vol_telefon): ?>tel.: <?= h($vol_telefon) ?><br><?php endif; ?>
      <?php if ($vol_email):   ?>e-mail: <?= h($vol_email) ?><br><?php endif; ?>
      zwanym/ą dalej <strong>„Wolontariuszem"</strong>.
    </div>
  </div>

  <div class="sec">§ 1 &nbsp; Podstawa prawna</div>
  <p>Na podstawie art. 44 ust. 2 ustawy z dnia 24 kwietnia 2003 r. o działalności pożytku publicznego i o wolontariacie
     Strony postanawiają zmienić warunki Porozumienia Wolontariackiego nr <strong><?= h($numer_orig) ?></strong>
     zgodnie z poniższymi ustaleniami.</p>

  <div class="sec">§ 2 &nbsp; Pełne dane Porozumienia po zmianie</div>

  <table class="dt">
    <tr><td>Numer umowy (oryginał):</td><td><?= h($numer_orig) ?></td></tr>
    <tr class="changed"><td>Numer aneksu:</td><td><strong><?= h($numer_aneksu) ?></strong></td></tr>
    <tr class="changed"><td>Data zawarcia aneksu:</td><td><strong><?= h($data_aneksu) ?></strong></td></tr>
    <?php if ($data_od_prev || $data_do_prev): ?>
    <tr><td>Poprzedni okres:</td><td><?= h($data_od_prev) ?> – <?= h($data_do_prev) ?></td></tr>
    <?php endif; ?>
    <tr class="changed"><td>Nowy okres obowiązywania:</td><td><strong><?= h($data_od) ?> – <?= h($data_do) ?></strong></td></tr>
    <tr><td>Miejsce wolontariatu:</td><td><?= h($miejsce) ?></td></tr>
    <tr><td>Wymiar godzinowy:</td><td><?= h($godziny) ?></td></tr>
    <?php if ($przedmiot): ?><tr><td>Zakres czynności:</td><td><?= nl2br(h($przedmiot)) ?></td></tr><?php endif; ?>
    <?php if ($projekt):   ?><tr><td>Projekt / program:</td><td><?= h($projekt) ?></td></tr><?php endif; ?>
    <?php if ($opiekun):   ?><tr><td>Opiekun:</td><td><?= h($opiekun) ?></td></tr><?php endif; ?>
    <tr><td>Szkolenie BHP:</td><td><?= h($bhp) ?></td></tr>
    <tr><td>Ubezpieczenie NNW:</td><td><?= h($nnw) ?></td></tr>
    <tr><td>Ubezpieczenie OC:</td><td><?= h($oc) ?></td></tr>
    <tr><td>Zwrot kosztów:</td><td><?= h($zwrot) ?></td></tr>
    <?php if ($nr_rejestru): ?><tr><td>Nr rejestru:</td><td><?= h($nr_rejestru) ?></td></tr><?php endif; ?>
  </table>

  <div class="sec">§ 3 &nbsp; Pozostałe postanowienia</div>
  <p>Pozostałe postanowienia Porozumienia nr <strong><?= h($numer_orig) ?></strong> pozostają bez zmian.</p>

  <div class="sec">§ 4 &nbsp; Egzemplarze</div>
  <p>Aneks sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron.
     Aneks wchodzi w życie z dniem jego podpisania przez obie Strony.</p>

  <div class="signatures">
    <div class="sig-block">
      <div class="sig-label">Organizacja</div>
      <div class="sig-line"></div>
      <div class="sig-sub"><?= h($org_repr ?: $org_name) ?><br><?= h($org_stanow) ?></div>
    </div>
    <div class="sig-block">
      <div class="sig-label">Wolontariusz/ka</div>
      <div class="sig-line"></div>
      <div class="sig-sub"><?= h($vol_name) ?><?= $vol_pesel ? '<br>PESEL: ' . h($vol_pesel) : '' ?></div>
    </div>
  </div>

  <?php if ($codes_html !== ''): ?><div class="doc-foot"><?= $codes_html ?></div><?php endif; ?>

</div>
</body>
</html>
