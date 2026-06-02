<?php
/**
 * contracts/wolontariat/aneks.php
 * Generator aneksu do porozumienia wolontariackiego (przedłużenie).
 *
 * GET ?id=X           — ID NOWEJ umowy (po przedłużeniu /Pn)
 * GET &format=pdf     — podgląd HTML do druku (domyślnie)
 * GET &format=docx    — pobierz plik .docx (Open XML przez ZipArchive)
 * GET &preview=1      — HTML bez auto-print
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

// Znajdź umowę bazową (bez /Pn)
$base_numer = preg_replace('|/P\d+$|', '', $row['numer_umowy']);
$base_row   = null;
if ($base_numer !== $row['numer_umowy']) {
    $base_row = db_one("SELECT * FROM umowy_wolontariat WHERE numer_umowy = ?", [$base_numer]);
}

// Dane organizacji
$org_name    = org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : '');
$org_adres   = org_setting('org_adres')       ?: '';
$org_miasto  = org_setting('org_miejscowosc') ?: '';
$org_nip     = org_setting('org_nip')         ?: '';
$org_krs     = org_setting('org_krs')         ?: '';
$org_repr    = org_setting('org_reprezentant') ?: '';
$org_stanow  = org_setting('org_stanowisko_repr') ?: 'Prezes Zarządu';

// Dane umowy
$numer_aneksu = $row['numer_umowy'];                         // np. W-2025-001/P2
$numer_orig   = $base_numer;                                  // np. W-2025-001
$vol_name     = $row['imie_nazwisko']   ?? '';
$vol_pesel    = $row['pesel']           ?? '';
$vol_adres    = $row['adres']           ?? '';
$data_aneksu  = date('d.m.Y');
$data_od      = $row['data_rozpoczecia'] ? date('d.m.Y', strtotime($row['data_rozpoczecia'])) : '—';
$data_do      = $row['bezterminowa']
    ? 'czas nieokreślony'
    : ($row['data_zakonczenia'] ? date('d.m.Y', strtotime($row['data_zakonczenia'])) : '—');
$miejsce      = $row['miejsce_wolontariatu'] ?? '—';
$przedmiot    = $row['przedmiot_porozumienia'] ?? '';
$godziny      = $row['godzin_tygodniowo'] ? h($row['godzin_tygodniowo']) . ' h/tyg.' : '—';

// Numer aneksu (P1, P2 …)
preg_match('|/P(\d+)$|', $numer_aneksu, $pm);
$p_nr     = isset($pm[1]) ? $pm[1] : '1';
$p_nr_ord = $p_nr . ($p_nr == '1' ? '.' : '.');

// ── DOCX ─────────────────────────────────────────────────────────────────────
if ($format === 'docx') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('Brak rozszerzenia ZipArchive. Użyj formatu PDF.');
    }

    $tmp = sys_get_temp_dir() . '/aneks_' . $id . '_' . time() . '.docx';

    // ── Treść dokumentu (OOXML) ────────────────────────────────────────────
    function _w(string $text): string {
        return htmlspecialchars($text, ENT_XML1, 'UTF-8');
    }
    function _para(string $text, bool $bold = false, string $align = 'left', int $size = 24): string {
        $b   = $bold ? '<w:b/>' : '';
        $jc  = $align !== 'left' ? "<w:jc w:val=\"{$align}\"/>" : '';
        return "<w:p><w:pPr>{$jc}<w:spacing w:after=\"100\"/></w:pPr>"
             . "<w:r><w:rPr>{$b}<w:sz w:val=\"{$size}\"/><w:szCs w:val=\"{$size}\"/></w:rPr>"
             . "<w:t xml:space=\"preserve\">" . _w($text) . "</w:t></w:r></w:p>";
    }
    function _row2(string $lbl, string $val): string {
        return "<w:tr>"
            . "<w:tc><w:tcPr><w:tcW w:w=\"3000\" w:type=\"dxa\"/><w:shd w:fill=\"F8FAFC\" w:val=\"clear\"/></w:tcPr>"
            . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>" . _w($lbl) . "</w:t></w:r></w:p></w:tc>"
            . "<w:tc><w:tcPr><w:tcW w:w=\"5000\" w:type=\"dxa\"/></w:tcPr>"
            . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>" . _w($val) . "</w:t></w:r></w:p></w:tc>"
            . "</w:tr>";
    }

    $paragraphs = implode("\n", [
        // Nagłówek
        _para("ANEKS NR {$p_nr_ord} DO POROZUMIENIA WOLONTARIACKIEGO", true, 'center', 28),
        _para("nr {$numer_orig}", false, 'center', 22),
        _para('', false),
        // Strony
        _para("Zawarty dnia {$data_aneksu} r. w {$org_miasto} pomiędzy:", false),
        _para('', false),
        _para("1. {$org_name}", true),
        $org_adres ? _para("    z siedzibą: {$org_adres}, {$org_miasto}") : '',
        $org_nip   ? _para("    NIP: {$org_nip}" . ($org_krs ? "  KRS: {$org_krs}" : '')) : '',
        $org_repr  ? _para("    reprezentowaną przez: {$org_repr} — {$org_stanow}") : '',
        _para('    zwaną dalej "Organizacją",'),
        _para('', false),
        _para("a"),
        _para('', false),
        _para("2. Panią/Panem: {$vol_name}", true),
        $vol_pesel ? _para("    PESEL: {$vol_pesel}") : '',
        $vol_adres ? _para("    zamieszkałą/ym: {$vol_adres}") : '',
        _para('    zwanym/a dalej "Wolontariuszem".'),
        _para('', false),
        // Treść
        _para("§ 1", true, 'center'),
        _para("Na podstawie art. 44 ust. 2 ustawy z dnia 24 kwietnia 2003 r. o działalności pożytku publicznego"
            . " i o wolontariacie Strony postanawiają przedłużyć porozumienie wolontariackie nr {$numer_orig}"
            . " na warunkach określonych poniżej."),
        _para('', false),
        _para("§ 2", true, 'center'),
        _para("Zmienia się postanowienia porozumienia w zakresie czasu obowiązywania:"),
        _para('', false),
        // Tabela zmian
        "<w:tbl>"
        . "<w:tblPr><w:tblStyle w:val=\"TableGrid\"/><w:tblW w:w=\"8000\" w:type=\"dxa\"/>"
        . "<w:tblBorders><w:top w:val=\"single\" w:sz=\"4\"/><w:left w:val=\"single\" w:sz=\"4\"/>"
        . "<w:bottom w:val=\"single\" w:sz=\"4\"/><w:right w:val=\"single\" w:sz=\"4\"/>"
        . "<w:insideH w:val=\"single\" w:sz=\"4\"/><w:insideV w:val=\"single\" w:sz=\"4\"/></w:tblBorders></w:tblPr>"
        . _row2('Nowy okres obowiązywania:', "od {$data_od} do {$data_do}")
        . _row2('Miejsce wolontariatu:', $miejsce)
        . _row2('Wymiar godzinowy:', $godziny)
        . _row2('Numer aneksu:', $numer_aneksu)
        . "</w:tbl>",
        _para('', false),
        // Paragraf 3
        _para("§ 3", true, 'center'),
        _para("Pozostałe postanowienia porozumienia nr {$numer_orig} pozostają bez zmian."),
        _para('', false),
        _para("§ 4", true, 'center'),
        _para("Aneks sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron."),
        _para('', false),
        _para('', false),
        _para('', false),
        // Podpisy
        "<w:tbl><w:tblPr><w:tblW w:w=\"9000\" w:type=\"dxa\"/>"
        . "<w:tblBorders><w:insideV w:val=\"none\"/></w:tblBorders></w:tblPr>"
        . "<w:tr>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4200\" w:type=\"dxa\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>……………………………………………………</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>Organizacja</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _w($org_repr ?: 'Podpis i pieczęć') . "</w:t></w:r></w:p>"
        . "</w:tc>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"600\" w:type=\"dxa\"/></w:tcPr><w:p/></w:tc>"
        . "<w:tc><w:tcPr><w:tcW w:w=\"4200\" w:type=\"dxa\"/></w:tcPr>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"20\"/></w:rPr><w:t>……………………………………………………</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:b/><w:sz w:val=\"20\"/></w:rPr><w:t>Wolontariusz</w:t></w:r></w:p>"
        . "<w:p><w:r><w:rPr><w:sz w:val=\"18\"/></w:rPr><w:t>" . _w($vol_name) . "</w:t></w:r></w:p>"
        . "</w:tc>"
        . "</w:tr></w:tbl>",
    ]);

    $document_xml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"
            xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<w:body>
{$paragraphs}
<w:sectPr>
  <w:pgSz w:w="11906" w:h="16838"/>
  <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1800" w:header="708" w:footer="708"/>
</w:sectPr>
</w:body>
</w:document>
XML;

    $rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $word_rels_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr>'
        . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>'
        . '<w:sz w:val="24"/><w:szCs w:val="24"/>'
        . '<w:lang w:val="pl-PL"/>'
        . '</w:rPr></w:rPrDefault></w:docDefaults>'
        . '</w:styles>';

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '</Types>';

    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',         $content_types);
    $zip->addFromString('_rels/.rels',                 $rels_xml);
    $zip->addFromString('word/document.xml',           $document_xml);
    $zip->addFromString('word/_rels/document.xml.rels',$word_rels_xml);
    $zip->addFromString('word/styles.xml',             $styles_xml);
    $zip->close();

    $filename = 'aneks_' . preg_replace('/[^a-z0-9]/i', '_', $numer_aneksu) . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ── HTML / PDF ────────────────────────────────────────────────────────────────
$is_preview = isset($_GET['preview']);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aneks nr <?= h($p_nr_ord) ?> — <?= h($numer_orig) ?></title>
<style>
* { box-sizing: border-box; }
body {
  font-family: 'Times New Roman', Times, serif;
  font-size: 12pt;
  color: #000;
  margin: 0;
  padding: 0;
  background: #f5f5f5;
}
.no-print {
  background: #fff;
  border-bottom: 1px solid #ddd;
  padding: .75rem 1.25rem;
  display: flex;
  gap: .75rem;
  align-items: center;
  flex-wrap: wrap;
}
.no-print button, .no-print a {
  padding: .45rem 1rem;
  border-radius: 6px;
  font-size: .88rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  border: none;
}
.btn-pdf  { background: #dc2626; color: #fff; }
.btn-docx { background: #1e6dff; color: #fff; }
.btn-back { background: #f1f5f9; color: #374151; border: 1px solid #e2e8f0; }
.page {
  max-width: 210mm;
  min-height: 297mm;
  margin: 1.5rem auto;
  background: #fff;
  padding: 2.5cm 2.5cm 2.5cm 3cm;
  box-shadow: 0 2px 16px rgba(0,0,0,.12);
}
h1 { font-size: 14pt; text-align: center; margin: 0 0 4pt; text-transform: uppercase; letter-spacing: .03em; }
.subtitle { text-align: center; font-size: 11pt; margin-bottom: 24pt; color: #444; }
p { margin: 0 0 8pt; line-height: 1.55; text-align: justify; }
.section-title { font-size: 12pt; font-weight: bold; text-align: center; margin: 16pt 0 8pt; }
table.data-table {
  width: 100%;
  border-collapse: collapse;
  margin: 12pt 0 16pt;
  font-size: 11pt;
}
table.data-table td {
  border: 1px solid #ccc;
  padding: 5pt 8pt;
  vertical-align: top;
}
table.data-table td:first-child {
  font-weight: bold;
  width: 42%;
  background: #f8fafc;
}
.signatures {
  display: flex;
  justify-content: space-between;
  margin-top: 48pt;
  gap: 40pt;
}
.sig-block {
  flex: 1;
  text-align: center;
}
.sig-line {
  border-top: 1px solid #000;
  margin-bottom: 4pt;
}
.sig-name { font-size: 10pt; }
.sig-label { font-weight: bold; font-size: 11pt; margin-bottom: 4pt; }
.parties { margin: 8pt 0 16pt; }
.party { margin-bottom: 8pt; }
.party strong { display: block; }
@media print {
  .no-print { display: none !important; }
  body { background: #fff; }
  .page { box-shadow: none; margin: 0; padding: 2.5cm 2.5cm 2.5cm 3cm; }
}
</style>
</head>
<body>
<?php if (!$is_preview): ?>
<script>setTimeout(function(){ window.print(); }, 600);</script>
<?php endif; ?>

<div class="no-print">
  <a href="aneks.php?id=<?= $id ?>&format=pdf&preview=1" class="btn-pdf" target="_blank">
    🖨 Drukuj / Zapisz jako PDF
  </a>
  <?php if (class_exists('ZipArchive')): ?>
  <a href="aneks.php?id=<?= $id ?>&format=docx" class="btn-docx">
    📄 Pobierz DOCX (Word)
  </a>
  <?php endif; ?>
  <a href="view.php?id=<?= $id ?>" class="btn-back">← Wróć do umowy</a>
  <span style="font-size:.82rem;color:#64748b;margin-left:.5rem">
    Aneks nr <?= h($p_nr_ord) ?> do umowy <?= h($numer_orig) ?>
  </span>
</div>

<div class="page">

  <h1>Aneks nr <?= h($p_nr_ord) ?></h1>
  <div class="subtitle">
    do porozumienia wolontariackiego nr <strong><?= h($numer_orig) ?></strong>
  </div>

  <p>Zawarty dnia <strong><?= h($data_aneksu) ?> r.</strong> w <?= h($org_miasto ?: '…………………') ?> pomiędzy:</p>

  <div class="parties">
    <div class="party">
      <strong>1. <?= h($org_name) ?></strong>
      <?php if ($org_adres): ?><span>z siedzibą: <?= h($org_adres) ?><?= $org_miasto ? ', ' . h($org_miasto) : '' ?></span><br><?php endif; ?>
      <?php if ($org_nip): ?><span>NIP: <?= h($org_nip) ?><?= $org_krs ? ' &nbsp;&nbsp; KRS: ' . h($org_krs) : '' ?></span><br><?php endif; ?>
      <?php if ($org_repr): ?><span>reprezentowaną przez: <?= h($org_repr) ?> — <?= h($org_stanow) ?></span><br><?php endif; ?>
      zwaną dalej <strong>„Organizacją"</strong>,
    </div>
    <p style="margin:8pt 0 8pt;font-weight:bold">a</p>
    <div class="party">
      <strong>2. Panią/Panem: <?= h($vol_name) ?></strong>
      <?php if ($vol_pesel): ?><span>PESEL: <?= h($vol_pesel) ?></span><br><?php endif; ?>
      <?php if ($vol_adres): ?><span>zamieszkałą/ym: <?= h($vol_adres) ?></span><br><?php endif; ?>
      zwanym/ą dalej <strong>„Wolontariuszem"</strong>.
    </div>
  </div>

  <div class="section-title">§ 1</div>
  <p>
    Na podstawie art. 44 ust. 2 ustawy z dnia 24 kwietnia 2003 r. o działalności pożytku publicznego
    i o wolontariacie (Dz.U. 2003 nr 96 poz. 873 z późn. zm.) Strony postanawiają przedłużyć
    porozumienie wolontariackie nr <strong><?= h($numer_orig) ?></strong> na warunkach określonych
    w niniejszym aneksie.
  </p>

  <div class="section-title">§ 2</div>
  <p>Zmienia się postanowienia porozumienia w zakresie czasu obowiązywania i warunków współpracy:</p>

  <table class="data-table">
    <tr>
      <td>Numer aneksu:</td>
      <td><strong><?= h($numer_aneksu) ?></strong></td>
    </tr>
    <tr>
      <td>Nowy okres obowiązywania:</td>
      <td>od <strong><?= h($data_od) ?></strong> do <strong><?= h($data_do) ?></strong></td>
    </tr>
    <tr>
      <td>Miejsce wolontariatu:</td>
      <td><?= h($miejsce) ?></td>
    </tr>
    <tr>
      <td>Wymiar godzinowy:</td>
      <td><?= h($godziny) ?></td>
    </tr>
    <?php if ($przedmiot): ?>
    <tr>
      <td>Zakres czynności:</td>
      <td><?= nl2br(h($przedmiot)) ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <div class="section-title">§ 3</div>
  <p>
    Pozostałe postanowienia porozumienia nr <strong><?= h($numer_orig) ?></strong>
    pozostają bez zmian.
  </p>

  <div class="section-title">§ 4</div>
  <p>
    Aneks sporządzono w dwóch jednobrzmiących egzemplarzach, po jednym dla każdej ze Stron.
    Aneks wchodzi w życie z dniem jego podpisania przez obie Strony.
  </p>

  <div class="signatures">
    <div class="sig-block">
      <div class="sig-label">Organizacja</div>
      <div class="sig-line">&nbsp;</div>
      <div class="sig-name"><?= h($org_repr ?: $org_name) ?></div>
      <div class="sig-name" style="color:#777;font-size:10pt"><?= h($org_stanow) ?></div>
    </div>
    <div class="sig-block">
      <div class="sig-label">Wolontariusz</div>
      <div class="sig-line">&nbsp;</div>
      <div class="sig-name"><?= h($vol_name) ?></div>
    </div>
  </div>

</div>
</body>
</html>
