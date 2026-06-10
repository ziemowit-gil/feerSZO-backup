<?php
/**
 * contracts/wolontariat/potwierdzenie.php
 * Potwierdzenie rejestracji wolontariusza — dokument urzędowy.
 *
 * GET ?id=X          — ID porozumienia
 * GET &format=pdf    — podgląd HTML do druku (domyślnie)
 * GET &format=docx   — pobierz plik .docx
 * GET &preview=1     — HTML bez auto-print
 */

if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

require_login();

$id     = (int)($_GET['id']     ?? 0);
$format = in_array($_GET['format'] ?? '', ['pdf', 'docx']) ? $_GET['format'] : 'pdf';

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
$vol_name    = $row['imie_nazwisko']   ?? '';
$vol_pesel   = $row['pesel']           ?? '';
$vol_dob     = $row['data_urodzenia']  ?? '';
$vol_adres   = trim(implode(', ', array_filter([
    trim(($row['addr_street'] ?? '') . ' ' . ($row['addr_house'] ?? '') . ($row['addr_flat'] ? '/' . $row['addr_flat'] : '')),
    ($row['addr_postal'] ?? '') . ' ' . ($row['addr_city'] ?? ''),
]))) ?: ($row['adres'] ?? '');
$vol_telefon = $row['telefon']         ?? '';
$vol_email   = $row['email']           ?? '';

// ── Dane porozumienia ─────────────────────────────────────────────────────────
$numer       = $row['numer_umowy']           ?? '';
$data_dzis   = date('d.m.Y');
$data_zawar  = $row['data_zawarcia']    ? date('d.m.Y', strtotime($row['data_zawarcia']))    : '—';
$data_od     = $row['data_rozpoczecia'] ? date('d.m.Y', strtotime($row['data_rozpoczecia'])) : '—';
$data_do     = !empty($row['bezterminowa'])
    ? 'czas nieokreślony'
    : ($row['data_zakonczenia'] ? date('d.m.Y', strtotime($row['data_zakonczenia'])) : '—');
$miejsce     = $row['miejsce_wolontariatu']   ?? '—';
$przedmiot   = $row['przedmiot_porozumienia'] ?? '';
$projekt     = $row['projekt_program']        ?? '';
$opiekun     = $row['opiekun']                ?? '';
$godziny     = $row['godzin_tygodniowo']
    ? $row['godzin_tygodniowo'] . ' godz./tydzień' : '—';
$bhp         = !empty($row['szkolenie_bhp'])
    ? ('Tak' . ($row['data_szkolenia_bhp'] ? ', data: ' . date('d.m.Y', strtotime($row['data_szkolenia_bhp'])) : ''))
    : 'Nie';
$nnw         = !empty($row['ubezpieczenie_nnw'])
    ? ('Tak' . ($row['numer_polisy_nnw'] ? ', polisa: ' . $row['numer_polisy_nnw'] : ''))
    : 'Nie';
$oc          = !empty($row['ubezpieczenie_oc']) ? 'Tak' : 'Nie';
$zwrot       = !empty($row['zwrot_kosztow'])
    ? ('Tak' . ($row['limit_zwrotu_kosztow'] ? ', limit: ' . number_format((float)$row['limit_zwrotu_kosztow'], 2, ',', ' ') . ' zł' : ''))
    : 'Nie';

// Status porozumienia (słownik jak w reszcie systemu)
$status_labels = [
    'aktywna'    => 'Aktywne',
    'aktywne'    => 'Aktywne',
    'zakonczona' => 'Zakończone',
    'zawarta'    => 'Zawarte',
    'robocza'    => 'Wersja robocza',
];
$status_raw = $row['status'] ?? '';
$status_txt = $status_labels[$status_raw] ?? ucfirst($status_raw) ?: '—';

$fn_safe = 'potw_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $numer);

// ── DOCX helpers (identyczne jak w aneks.php) ─────────────────────────────────
function _pw(string $text): string {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function _pp(string $text, bool $bold = false, string $align = 'both', int $sz = 24): string {
    $b  = $bold ? '<w:b/><w:bCs/>' : '';
    $jc = "<w:jc w:val=\"{$align}\"/>";
    return "<w:p><w:pPr>{$jc}<w:spacing w:after=\"80\"/></w:pPr>"
         . "<w:r><w:rPr>{$b}<w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/></w:rPr>"
         . "<w:t xml:space=\"preserve\">" . _pw($text) . "</w:t></w:r></w:p>";
}
function _ptrow(string $lbl, string $val, bool $hl = false): string {
    $shd = $hl ? '<w:shd w:fill="EFF6FF" w:val="clear"/>' : '<w:shd w:fill="F8FAFC" w:val="clear"/>';
    return "<w:tr>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"3200\" w:type=\"dxa\"/>{$shd}</w:tcPr>"
        . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>" . _pw($lbl) . "</w:t></w:r></w:p></w:tc>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4800\" w:type=\"dxa\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t xml:space=\"preserve\">" . _pw($val) . "</w:t></w:r></w:p></w:tc>"
        . "</w:tr>";
}
function _psec(string $title, int $sz = 24): string {
    return "<w:p><w:pPr><w:jc w:val=\"center\"/><w:spacing w:before=\"200\" w:after=\"80\"/></w:pPr>"
         . "<w:r><w:rPr><w:b/><w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/></w:rPr>"
         . "<w:t>" . _pw($title) . "</w:t></w:r></w:p>";
}

// ── DOCX ──────────────────────────────────────────────────────────────────────
if ($format === 'docx') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('Brak rozszerzenia ZipArchive. Użyj formatu PDF.');
    }

    $tmp = sys_get_temp_dir() . '/' . $fn_safe . '_' . time() . '.docx';

    $tbl_open  = "<w:tbl><w:tblPr>"
        . "<w:tblW w:w=\"8000\" w:type=\"dxa\"/>"
        . "<w:tblBorders>"
        . "<w:top w:val=\"single\" w:sz=\"4\"/><w:left w:val=\"single\" w:sz=\"4\"/>"
        . "<w:bottom w:val=\"single\" w:sz=\"4\"/><w:right w:val=\"single\" w:sz=\"4\"/>"
        . "<w:insideH w:val=\"single\" w:sz=\"4\"/><w:insideV w:val=\"single\" w:sz=\"4\"/>"
        . "</w:tblBorders></w:tblPr>";

    $parts = [
        // Miejsce i data
        _pp($org_miasto . ', dnia ' . $data_dzis . ' r.', false, 'right', 20),
        _pp(''),

        // Nagłówek
        _pp('POTWIERDZENIE REJESTRACJI WOLONTARIUSZA', true, 'center', 28),
        _pp('nr ' . $numer, false, 'center', 22),
        _pp(''),

        // Akapit potwierdzający
        _pp($org_name
            . ($org_adres ? ', z siedzibą: ' . $org_adres . ($org_miasto ? ', ' . $org_miasto : '') : '')
            . ($org_nip   ? ' (NIP: ' . $org_nip . ')' : '')
            . ' — zwaną/y dalej „Organizacją" — niniejszym potwierdza, że:'),
        _pp(''),
        _pp($vol_name
            . ($vol_pesel ? ', PESEL: ' . $vol_pesel : '')
            . ($vol_dob   ? ', ur. ' . date('d.m.Y', strtotime($vol_dob)) : ''),
            true, 'center', 26),
        _pp(''),
        _pp('jest zarejestrowany/a jako wolontariusz/ka i świadczy pracę wolontariacką na rzecz Organizacji '
            . 'na podstawie Porozumienia Wolontariackiego nr ' . $numer
            . ', zawartego dnia ' . $data_zawar . ' r., na warunkach określonych poniżej.'),
        _pp(''),

        _psec('Dane Porozumienia'),

        // Tabela danych
        $tbl_open
        . _ptrow('Numer porozumienia:', $numer, true)
        . _ptrow('Data zawarcia:', $data_zawar)
        . _ptrow('Okres wolontariatu:', $data_od . ' – ' . $data_do, true)
        . _ptrow('Miejsce wolontariatu:', $miejsce)
        . ($przedmiot ? _ptrow('Zakres czynności:', $przedmiot) : '')
        . ($projekt   ? _ptrow('Projekt / program:', $projekt)   : '')
        . ($opiekun   ? _ptrow('Opiekun:', $opiekun)             : '')
        . _ptrow('Wymiar godzinowy:', $godziny)
        . _ptrow('Szkolenie BHP:', $bhp)
        . _ptrow('Ubezpieczenie NNW:', $nnw)
        . _ptrow('Ubezpieczenie OC:', $oc)
        . _ptrow('Zwrot kosztów:', $zwrot)
        . _ptrow('Status:', $status_txt, true)
        . "</w:tbl>",

        _pp(''),
        _psec('Klauzula'),
        _pp('Niniejsze potwierdzenie jest dokumentem informacyjnym wydanym na podstawie danych zawartych '
            . 'w systemie ewidencji wolontariuszy ' . $org_name . '. Dokument wystawiono dnia ' . $data_dzis . ' r.'),
        _pp(''), _pp(''), _pp(''),

        // Podpis jednostronny (tylko organizacja)
        "<w:tbl><w:tblPr><w:tblW w:w=\"9000\" w:type=\"dxa\"/></w:tblPr>"
        . "<w:tr>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4800\" w:type=\"dxa\"/></w:tcPr><w:p/></w:tc>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4200\" w:type=\"dxa\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>…………………………………………………</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>Organizacja</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _pw($org_repr ?: $org_name) . "</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _pw($org_stanow) . "</w:t></w:r></w:p>"
        . "</w:tc>"
        . "</w:tr></w:tbl>",
    ];

    $body_content = implode("\n", array_filter($parts));

    $doc_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body_content
        . '<w:sectPr>'
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

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $fn_safe . '.docx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ── HTML / PDF ─────────────────────────────────────────────────────────────────
$is_preview = isset($_GET['preview']);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Potwierdzenie rejestracji <?= h($numer) ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; margin:0; padding:0; background:#f5f5f5; }
.no-print { background:#fff; border-bottom:1px solid #ddd; padding:.7rem 1.25rem; display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; font-family:system-ui,sans-serif; }
.no-print button, .no-print a { padding:.42rem 1rem; border-radius:6px; font-size:.86rem; font-weight:600; cursor:pointer; text-decoration:none; border:none; }
.btn-pdf  { background:#dc2626; color:#fff; }
.btn-docx { background:#1e6dff; color:#fff; }
.btn-back { background:#f1f5f9; color:#374151; border:1px solid #e2e8f0 !important; }
.page { max-width:210mm; min-height:297mm; margin:1.5rem auto; background:#fff; padding:2.5cm 2.5cm 2.5cm 3cm; box-shadow:0 2px 16px rgba(0,0,0,.12); }
.miejsce-data { text-align:right; font-size:11pt; color:#333; margin-bottom:28pt; }
h1 { font-size:15pt; text-align:center; margin:0 0 4pt; text-transform:uppercase; letter-spacing:.04em; }
.doc-nr { text-align:center; font-size:11pt; color:#555; margin-bottom:22pt; }
p { margin:0 0 8pt; line-height:1.65; text-align:justify; }
.vol-name { text-align:center; font-size:14pt; font-weight:bold; margin:10pt 0; }
.sec { font-size:12pt; font-weight:bold; text-align:center; margin:14pt 0 7pt; }
table.dt { width:100%; border-collapse:collapse; margin:8pt 0 14pt; font-size:11pt; }
table.dt td { border:1px solid #bbb; padding:4pt 7pt; vertical-align:top; }
table.dt td:first-child { font-weight:bold; width:40%; background:#f8fafc; }
table.dt tr.hl td { background:#eff6ff; }
table.dt tr.hl td:first-child { background:#dbeafe; }
.klauzula { font-size:9.5pt; color:#555; border-top:1px solid #ccc; padding-top:8pt; margin-top:8pt; line-height:1.5; }
.sig-wrap { display:flex; justify-content:flex-end; margin-top:48pt; }
.sig-block { width:46%; text-align:center; }
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
  <a href="potwierdzenie.php?id=<?= $id ?>&format=pdf&preview=1" class="btn-pdf" target="_blank">&#128424; Drukuj / PDF</a>
  <?php if (class_exists('ZipArchive')): ?>
  <a href="potwierdzenie.php?id=<?= $id ?>&format=docx" class="btn-docx">&#128196; DOCX (Word)</a>
  <?php endif; ?>
  <a href="view.php?id=<?= $id ?>" class="btn-back">&#8592; Wróć do umowy</a>
  <span style="font-size:.8rem;color:#64748b">Potwierdzenie rejestracji <?= h($numer) ?></span>
</div>

<div class="page">

  <div class="miejsce-data"><?= h($org_miasto ?: '…………………') ?>, dnia <?= h($data_dzis) ?> r.</div>

  <h1>Potwierdzenie rejestracji wolontariusza</h1>
  <div class="doc-nr">nr <strong><?= h($numer) ?></strong></div>

  <p>
    <?= h($org_name) ?><?= $org_adres ? ', z siedzibą: ' . h($org_adres) . ($org_miasto ? ', ' . h($org_miasto) : '') : '' ?><?= $org_nip ? ' (NIP: ' . h($org_nip) . ')' : '' ?>
    — zwaną/y dalej <strong>„Organizacją"</strong> — niniejszym potwierdza, że:
  </p>

  <div class="vol-name">
    <?= h($vol_name) ?>
    <?php if ($vol_pesel): ?><br><span style="font-size:11pt;font-weight:normal">PESEL: <?= h($vol_pesel) ?></span><?php endif; ?>
    <?php if ($vol_dob):   ?><br><span style="font-size:11pt;font-weight:normal">ur. <?= h(date('d.m.Y', strtotime($vol_dob))) ?></span><?php endif; ?>
  </div>

  <p>
    jest zarejestrowany/a jako wolontariusz/ka i świadczy pracę wolontariacką na rzecz Organizacji
    na podstawie Porozumienia Wolontariackiego nr <strong><?= h($numer) ?></strong>,
    zawartego dnia <?= h($data_zawar) ?> r., na warunkach określonych poniżej.
  </p>

  <div class="sec">Dane Porozumienia</div>

  <table class="dt">
    <tr class="hl"><td>Numer porozumienia:</td><td><strong><?= h($numer) ?></strong></td></tr>
    <tr><td>Data zawarcia:</td><td><?= h($data_zawar) ?></td></tr>
    <tr class="hl"><td>Okres wolontariatu:</td><td><strong><?= h($data_od) ?> – <?= h($data_do) ?></strong></td></tr>
    <tr><td>Miejsce wolontariatu:</td><td><?= h($miejsce) ?></td></tr>
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

  <p class="klauzula">
    Niniejsze potwierdzenie jest dokumentem informacyjnym wydanym na podstawie danych zawartych
    w systemie ewidencji wolontariuszy <?= h($org_name) ?>.
    Dokument wystawiono dnia <?= h($data_dzis) ?> r.
    <?php if ($org_repr): ?>Upoważniona osoba: <?= h($org_repr) ?>, <?= h($org_stanow) ?>.<?php endif; ?>
  </p>

  <div class="sig-wrap">
    <div class="sig-block">
      <div class="sig-label">Organizacja</div>
      <div class="sig-line"></div>
      <div class="sig-sub"><?= h($org_repr ?: $org_name) ?><br><?= h($org_stanow) ?></div>
    </div>
  </div>

</div>
</body>
</html>
