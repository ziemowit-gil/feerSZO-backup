<?php
/**
 * Drukuj sprawę → PDF. Łączy w jeden plik PDF okładkę sprawy + dokumenty
 * (załączniki sprawy: repozytorium, pisma, umowy, dokumenty wewnętrzne).
 *
 *   ?id=N                 → ekran wyboru (cała sprawa / jeden dokument)
 *   ?id=N&out=pdf         → PDF całej sprawy (okładka + wszystkie dokumenty)
 *   ?id=N&out=pdf&zal=Z   → PDF jednego dokumentu (bez okładki)
 *
 * Pliki PDF są scalane stronami (FPDI), obrazy osadzane jako strona, pozostałe
 * formaty (docx/zip…) dostają stronę-notatkę (oryginał w repozytorium).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$id     = (int)($_GET['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error', 'Sprawa nie istnieje.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

$zalaczniki = ezd_zalaczniki_by($id); // wszystkie dokumenty sprawy (jednolita ścieżka)
$out   = $_GET['out'] ?? '';
$zalId = (int)($_GET['zal'] ?? 0);

$zal_path = fn(array $z): string => UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/' . $z['filename'];
$zal_src  = fn(array $z): string => $z['pismo_id'] ? 'Pismo' : ($z['umowa_id'] ? 'Umowa' : ($z['dokument_id'] ? 'Dokument wewn.' : 'Repozytorium'));

// ── Generowanie PDF ──────────────────────────────────────────────────────────
if ($out === 'pdf') {
    require_once dirname(dirname(__DIR__)) . '/includes/fpdf/fpdf.php';
    require_once dirname(dirname(__DIR__)) . '/includes/fpdi/autoload_fpdi.php';

    // Zestaw dokumentów do druku
    if ($zalId) {
        $files = array_values(array_filter($zalaczniki, fn($z) => (int)$z['id'] === $zalId));
        if (!$files) { flash_set('error', 'Nie znaleziono dokumentu.'); header('Location: ' . APP_URL . '/ezd/sprawy/print.php?id=' . $id); exit; }
        $with_cover = false;
    } else {
        $files = $zalaczniki;
        $with_cover = true;
    }

    $org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $font_dir = dirname(dirname(__DIR__)) . '/includes/fpdf/font/';

    // Konwersja UTF-8 → ISO-8859-2 (wymagana przez font DejaVu enc:iso-8859-2)
    $pl = fn(string $s): string => iconv('UTF-8', 'ISO-8859-2//TRANSLIT//IGNORE', $s) ?: $s;

    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddFont('DejaVu', '',  'dejavusans.json',  $font_dir);
    $pdf->AddFont('DejaVu', 'B', 'dejavusansb.json', $font_dir);

    // Strona-notatka dla plików, których nie da się wyrenderować
    $notePage = function (array $z, string $msg) use ($pdf, $zal_src, $pl) {
        $pdf->AddPage('P', 'A4');
        $pdf->SetFont('DejaVu', 'B', 12);
        $pdf->MultiCell(0, 7, $pl($z['original_name']), 0, 'L');
        $pdf->Ln(2);
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(0, 6, $pl($zal_src($z) . ' · ' . ezd_filesize($z['file_size'])), 0, 1);
        $pdf->Ln(2);
        $pdf->MultiCell(0, 6, $pl($msg), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
    };

    // ── Okładka (tylko dla całej sprawy) ─────────────────────────────────────
    if ($with_cover) {
        $pdf->AddPage('P', 'A4');
        $W = 180;
        $pdf->SetFillColor(30, 64, 120);
        $pdf->Rect(15, 15, $W, 11, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('DejaVu', 'B', 13);
        $pdf->SetXY(15, 15);
        $pdf->Cell($W, 11, $pl('KARTA SPRAWY'), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(6);

        if ($org_name) { $pdf->SetFont('DejaVu', 'B', 11); $pdf->Cell(0, 6, $pl($org_name), 0, 1); }
        $pdf->SetFont('DejaVu', '', 9);
        $pdf->SetTextColor(110, 110, 110);
        $pdf->Cell(0, 5, $pl('Wygenerowano: ' . date('d.m.Y H:i')), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);

        $pdf->SetFont('DejaVu', 'B', 14);
        $pdf->MultiCell(0, 7, $pl($sprawa['title']), 0, 'L');
        $pdf->Ln(1);

        $row = function (string $k, string $v) use ($pdf, $pl) {
            $pdf->SetFont('DejaVu', '', 9);  $pdf->SetTextColor(110, 110, 110); $pdf->Cell(40, 6, $pl($k), 0, 0);
            $pdf->SetFont('DejaVu', 'B', 9); $pdf->SetTextColor(0, 0, 0);
            $pdf->MultiCell(0, 6, $pl($v !== '' ? $v : '—'), 0, 'L');
        };
        $stat = EZD_STATUSES_SPRAWA[$sprawa['status']]['label'] ?? $sprawa['status'];
        $row('Znak sprawy:',  (string)$sprawa['znak_sprawy']);
        $row('Teczka (JRWA):', (string)$sprawa['teczka_symbol']);
        $row('Status:',        (string)$stat);
        $row('Właściciel:',    (string)($sprawa['owner_name'] ?? ''));
        $row('Otwarto:',       $sprawa['created_at'] ? date('d.m.Y', strtotime($sprawa['created_at'])) : '');
        if (!empty($sprawa['ciagla']))      $row('Termin:', 'sprawa ciągła (stale otwarta)');
        elseif (!empty($sprawa['deadline'])) $row('Termin:', date('d.m.Y', strtotime($sprawa['deadline'])));
        if (!empty($sprawa['closed_at']))   $row('Zamknięto:', date('d.m.Y', strtotime($sprawa['closed_at'])));
        if (!empty($sprawa['description'])) { $pdf->Ln(1); $row('Opis:', (string)$sprawa['description']); }

        $pdf->Ln(4);
        $pdf->SetFont('DejaVu', 'B', 10);
        $pdf->Cell(0, 7, $pl('Załączone dokumenty (' . count($files) . ')'), 0, 1);
        $pdf->SetFont('DejaVu', '', 9);
        if ($files) {
            $i = 0;
            foreach ($files as $z) {
                $i++;
                $line = $i . '. ' . $z['original_name'] . '  [' . $zal_src($z) . ', ' . ezd_filesize($z['file_size']) . ']';
                $pdf->MultiCell(0, 5.5, $pl($line), 0, 'L');
            }
        } else {
            $pdf->SetTextColor(110, 110, 110);
            $pdf->Cell(0, 6, $pl('Brak plików w sprawie — karta zawiera wyłącznie metadane.'), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    // ── Scalanie dokumentów ──────────────────────────────────────────────────
    foreach ($files as $z) {
        $path = $zal_path($z);
        $ext  = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));

        if (!is_file($path)) { $notePage($z, 'Plik niedostępny na dysku.'); continue; }

        if ($ext === 'pdf') {
            try {
                $count = $pdf->setSourceFile($path);
                for ($i = 1; $i <= $count; $i++) {
                    $tpl  = $pdf->importPage($i);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                }
            } catch (\Throwable $e) {
                $notePage($z, 'Nie udało się dołączyć pliku PDF (możliwe szyfrowanie lub uszkodzenie). Oryginał dostępny w repozytorium sprawy.');
            }
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $pdf->AddPage('P', 'A4');
            $pdf->SetFont('DejaVu', '', 8);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->Cell(0, 5, $pl($z['original_name'] . ' · ' . $zal_src($z)), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
            $sz = @getimagesize($path) ?: [0, 0];
            $maxW = 180; $maxH = 250;
            $w = $maxW;
            $h = ($sz[0] ?? 0) > 0 ? ($sz[1] / $sz[0]) * $w : $maxH;
            if ($h > $maxH) { $h = $maxH; $w = ($sz[1] ?? 0) > 0 ? ($sz[0] / $sz[1]) * $h : $maxW; }
            try { $pdf->Image($path, 15, 24, $w, $h); }
            catch (\Throwable $e) { $notePage($z, 'Nie udało się osadzić obrazu.'); }
        } else {
            $notePage($z, 'Załącznik nie jest plikiem PDF ani obrazem (' . strtoupper($ext ?: 'plik') . ') — oryginał dostępny w repozytorium sprawy.');
        }
    }

    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $sprawa['znak_sprawy'] ?: ('sprawa_' . $id));
    $base = trim($base, '_') ?: ('sprawa_' . $id);
    if ($zalId) $base .= '_dokument';

    // Czyść ewentualne bufory przed strumieniem PDF
    while (ob_get_level() > 0) { ob_end_clean(); }
    $pdf->Output('I', $base . '.pdf');
    exit;
}

// ── Ekran wyboru ─────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Drukuj sprawę — ' . $sprawa['znak_sprawy'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Drukuj</li>
</ol></nav>

<?= flash_html() ?>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-printer text-dark me-2"></i>Drukuj sprawę do PDF</h4>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-arrow-left me-1"></i>Wróć do sprawy</a>
</div>

<div class="row g-3" style="max-width:820px">
  <!-- Cała sprawa -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-body d-flex align-items-center gap-3 flex-wrap">
        <i class="bi bi-file-earmark-pdf text-danger" style="font-size:2rem"></i>
        <div class="flex-grow-1">
          <div class="fw-bold">Cała sprawa (wszystkie dokumenty)</div>
          <div class="text-muted" style="font-size:.83rem">Okładka z danymi sprawy + scalone wszystkie pliki (<?= count($zalaczniki) ?>) w jednym PDF.</div>
        </div>
        <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>&out=pdf" target="_blank" rel="noopener" class="btn btn-dark">
          <i class="bi bi-download me-1"></i>Pobierz PDF
        </a>
      </div>
    </div>
  </div>

  <!-- Pojedynczy dokument -->
  <div class="col-12">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-files me-2"></i>…albo jeden dokument</div>
      <div class="card-body p-0">
        <?php if (!$zalaczniki): ?>
        <div class="text-center text-muted py-3" style="font-size:.85rem">Brak dokumentów w tej sprawie.</div>
        <?php else: foreach ($zalaczniki as $z):
          $ext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
          $printable = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
        ?>
        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style="font-size:.84rem">
          <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5 flex-shrink-0"></i>
          <div class="flex-grow-1 overflow-hidden">
            <div class="fw-semibold text-truncate"><?= h($z['original_name']) ?></div>
            <div class="text-muted" style="font-size:.72rem"><?= h($zal_src($z)) ?> · <?= ezd_filesize($z['file_size']) ?></div>
          </div>
          <?php if ($printable): ?>
          <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>&out=pdf&zal=<?= (int)$z['id'] ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-dark">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
          </a>
          <?php else: ?>
          <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= (int)$z['id'] ?>&dl=1" class="btn btn-sm btn-outline-secondary" title="Format nie-PDF — pobierz oryginał">
            <i class="bi bi-download me-1"></i>Oryginał
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i>Pliki PDF i obrazy trafiają do PDF. Inne formaty (DOCX, ZIP…) pobierz jako oryginał — w PDF całej sprawy są wykazane na okładce.</div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
