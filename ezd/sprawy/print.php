<?php
/**
 * Drukuj sprawę → PDF. Łączy w jeden plik PDF okładkę sprawy + dokumenty
 * (załączniki sprawy: repozytorium, pisma, umowy, dokumenty wewnętrzne).
 *
 *   ?id=N                 → ekran wyboru (cała sprawa / jeden dokument)
 *   ?id=N&out=pdf         → PDF całej sprawy (okładka + wszystkie dokumenty)
 *   ?id=N&out=pdf&zal=Z   → PDF jednego dokumentu (bez okładki)
 *
 * Pliki PDF są scalane stronami (FPDI), obrazy osadzane jako strona, pliki
 * Word/Excel (EZD_PDF_CONVERTIBLE_EXT) konwertowane "w locie" na PDF przez
 * SharePoint/Graph (bez zapisu trwałego załącznika) i też scalane stronami.
 * Pozostałe formaty (zip, txt…) dostają stronę-notatkę (oryginał w repozytorium).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');

$id     = (int)($_GET['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error', 'Koszulka nie istnieje.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }
if (!ezd_sprawa_access($sprawa, (int)current_user()['id'])) { flash_set('error', 'Brak dostępu do tej koszulki.'); header('Location: ' . APP_URL . '/ezd/index.php'); exit; }

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
        $W = 180; // szerokość robocza (mm)

        // ── Pasek nagłówkowy ─────────────────────────────────────────────────
        $pdf->SetFillColor(22, 53, 102);
        $pdf->Rect(15, 15, $W, 14, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('DejaVu', 'B', 11);
        $pdf->SetXY(18, 15);
        $pdf->Cell($W - 6, 7, $pl('KARTA KOSZULKI'), 0, 1, 'L');
        $pdf->SetFont('DejaVu', '', 8);
        $pdf->SetXY(18, 22);
        $pdf->Cell($W - 6, 7, $pl(($org_name ?: '') . '   ·   Wygenerowano: ' . date('d.m.Y H:i')), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);

        // ── Znak sprawy (duży, wyróżniony) ──────────────────────────────────
        $pdf->SetY(35);
        $pdf->SetFont('DejaVu', 'B', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 5, $pl('ZNAK KOSZULKI'), 0, 1);
        $pdf->SetFont('DejaVu', 'B', 17);
        $pdf->SetTextColor(22, 53, 102);
        $pdf->Cell(0, 9, $pl($sprawa['znak_sprawy'] ?: '—'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(1);

        // ── Tytuł sprawy ─────────────────────────────────────────────────────
        $pdf->SetFont('DejaVu', 'B', 13);
        $pdf->MultiCell(0, 7, $pl($sprawa['title']), 0, 'L');
        $pdf->Ln(3);

        // ── Opis — wyróżnione pole ────────────────────────────────────────────
        if (!empty($sprawa['description'])) {
            $desc = $pl($sprawa['description']);
            $pdf->SetFillColor(240, 245, 255);
            // Szacuj wysokość multi-cell: ~5mm na linię, min 14mm
            $lines = max(3, (int)ceil(mb_strlen($sprawa['description']) / 90) + 1);
            $boxH  = $lines * 5.5 + 6;
            $pdf->Rect(15, $pdf->GetY(), $W, $boxH, 'F');
            $pdf->SetDrawColor(180, 200, 230);
            $pdf->Rect(15, $pdf->GetY(), 2, $boxH, 'F');     // lewy pasek akcentu
            $pdf->SetDrawColor(200, 200, 200);
            $xStart = $pdf->GetY();
            $pdf->SetFont('DejaVu', 'B', 8);
            $pdf->SetTextColor(80, 100, 140);
            $pdf->SetXY(19, $xStart + 2);
            $pdf->Cell(0, 5, $pl('OPIS KOSZULKI'), 0, 1);
            $pdf->SetFont('DejaVu', '', 9.5);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetX(19);
            $pdf->MultiCell($W - 4, 5.5, $desc, 0, 'L');
            $pdf->SetY($xStart + $boxH + 4);
        }
        $pdf->Ln(2);

        // ── Metadane (dwie kolumny) ───────────────────────────────────────────
        $stat   = EZD_STATUSES_SPRAWA[$sprawa['status']]['label'] ?? $sprawa['status'];
        $meta   = [
            ['Segregator',    $sprawa['teczka_symbol'] ?? '—'],
            ['Status',        $stat],
            ['Właściciel',    $sprawa['owner_name'] ?? '—'],
            ['Otwarto',       $sprawa['created_at'] ? date('d.m.Y', strtotime($sprawa['created_at'])) : '—'],
        ];
        if (!empty($sprawa['ciagla']))        $meta[] = ['Termin', 'koszulka ciągła (stale otwarta)'];
        elseif (!empty($sprawa['deadline']))  $meta[] = ['Termin', date('d.m.Y', strtotime($sprawa['deadline']))];
        if (!empty($sprawa['closed_at']))     $meta[] = ['Zamknięto', date('d.m.Y', strtotime($sprawa['closed_at']))];

        $colW = ($W - 6) / 2;
        $y0   = $pdf->GetY();
        $col  = 0;
        foreach ($meta as $i => [$k, $v]) {
            $x = 15 + ($col ? $colW + 6 : 0);
            $pdf->SetXY($x, $y0 + (int)floor($i / 2) * 11);
            $pdf->SetFont('DejaVu', '', 8); $pdf->SetTextColor(110, 110, 110);
            $pdf->Cell($colW, 5, $pl($k), 0, 1);
            $pdf->SetX($x);
            $pdf->SetFont('DejaVu', 'B', 9); $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell($colW, 5.5, $pl($v), 0, 1);
            $col = 1 - $col;
        }
        $pdf->SetY($y0 + (int)ceil(count($meta) / 2) * 11 + 4);

        // ── Separator ────────────────────────────────────────────────────────
        $pdf->SetDrawColor(210, 215, 225);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(4);

        // ── Spis dokumentów ───────────────────────────────────────────────────
        $pdf->SetFont('DejaVu', 'B', 9);
        $pdf->SetTextColor(22, 53, 102);
        $pdf->Cell(0, 6, $pl('DOKUMENTY W KOSZULCE  (' . count($files) . ')'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        if ($files) {
            foreach ($files as $i => $z) {
                $pdf->SetFillColor($i % 2 === 0 ? 250 : 244, $i % 2 === 0 ? 251 : 247, 255);
                $pdf->Rect(15, $pdf->GetY(), $W, 6.5, 'F');
                $pdf->SetFont('DejaVu', '', 8.5);
                $nr   = $pl(($i + 1) . '.');
                $name = $pl($z['original_name']);
                $src  = $pl($zal_src($z) . ' · ' . ezd_filesize($z['file_size']));
                $pdf->SetXY(16, $pdf->GetY() + 0.5);
                $pdf->Cell(8, 5.5, $nr, 0, 0);
                $pdf->Cell(110, 5.5, $name, 0, 0);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->Cell(0, 5.5, $src, 0, 1);
                $pdf->SetTextColor(0, 0, 0);
            }
        } else {
            $pdf->SetFont('DejaVu', '', 9);
            $pdf->SetTextColor(130, 130, 130);
            $pdf->Cell(0, 6, $pl('Brak plików — karta zawiera wyłącznie metadane.'), 0, 1);
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
                $notePage($z, 'Nie udało się dołączyć pliku PDF (możliwe szyfrowanie lub uszkodzenie). Oryginał dostępny w repozytorium koszulki.');
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
        } elseif (in_array($ext, EZD_PDF_CONVERTIBLE_EXT, true)) {
            // Word/Excel — konwertuj "w locie" na PDF przez SharePoint/Graph (bez trwałego załącznika) i dołącz strony
            $tmpPdf = null;
            try {
                if (empty($z['sp_drive_id']) || empty($z['sp_item_id'])) {
                    $sync = ezd_sp_sync_attachment((int)$z['id']);
                    if (!$sync['ok']) throw new \RuntimeException($sync['error'] ?: 'Nie udało się wysłać pliku na SharePoint.');
                    $z = ezd_zal_get((int)$z['id']);
                }
                require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
                $graph      = new M365Graph();
                $pdfContent = $graph->sp_download_file_as_pdf($z['sp_drive_id'], $z['sp_item_id']);
                $tmpPdf     = tempnam(sys_get_temp_dir(), 'ezdcv_');
                file_put_contents($tmpPdf, $pdfContent);
                $count = $pdf->setSourceFile($tmpPdf);
                for ($i = 1; $i <= $count; $i++) {
                    $tpl  = $pdf->importPage($i);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                }
            } catch (\Throwable $e) {
                $notePage($z, 'Nie udało się przekonwertować pliku na PDF (' . $e->getMessage() . '). Oryginał dostępny w repozytorium koszulki.');
            } finally {
                if ($tmpPdf) @unlink($tmpPdf);
            }
        } else {
            $notePage($z, 'Załącznik nie jest plikiem PDF ani obrazem (' . strtoupper($ext ?: 'plik') . ') — oryginał dostępny w repozytorium koszulki.');
        }
    }

    $base = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $sprawa['znak_sprawy'] ?: ('koszulka_' . $id));
    $base = trim($base, '_') ?: ('koszulka_' . $id);
    if ($zalId) $base .= '_dokument';

    // Czyść ewentualne bufory przed strumieniem PDF
    while (ob_get_level() > 0) { ob_end_clean(); }
    $pdf->Output('I', $base . '.pdf');
    exit;
}

// ── Ekran wyboru ─────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Drukuj koszulkę — ' . $sprawa['znak_sprawy'];
$stat_label = EZD_STATUSES_SPRAWA[$sprawa['status']]['label'] ?? $sprawa['status'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Drukuj</li>
</ol></nav>

<?= flash_html() ?>

<div style="max-width:860px">

  <!-- ── Karta podglądu sprawy ─────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4 overflow-hidden">
    <div style="background:linear-gradient(135deg,#163566 0%,#1e4a8a 100%);padding:20px 24px 14px">
      <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
        <div>
          <div style="font-size:.7rem;font-weight:700;letter-spacing:.1em;color:rgba(255,255,255,.55);text-transform:uppercase;margin-bottom:4px">Znak koszulki</div>
          <div style="font-size:1.45rem;font-weight:800;color:#fff;letter-spacing:.01em;line-height:1.15"><?= h($sprawa['znak_sprawy']) ?></div>
        </div>
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>"
           class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);white-space:nowrap">
          <i class="bi bi-arrow-left me-1"></i>Wróć do koszulki
        </a>
      </div>
      <div style="font-size:1.05rem;font-weight:600;color:rgba(255,255,255,.9);margin-top:10px;line-height:1.35">
        <?= h($sprawa['title']) ?>
      </div>
    </div>

    <?php if (!empty($sprawa['description'])): ?>
    <div style="background:#f0f5ff;border-left:4px solid #163566;padding:14px 20px 14px 20px;display:flex;gap:12px;align-items:flex-start">
      <i class="bi bi-card-text" style="color:#163566;font-size:1.15rem;margin-top:2px;flex-shrink:0"></i>
      <div>
        <div style="font-size:.68rem;font-weight:700;letter-spacing:.09em;color:#4a6096;text-transform:uppercase;margin-bottom:4px">Opis koszulki</div>
        <div style="font-size:.93rem;color:#1a2a45;line-height:1.55;white-space:pre-wrap"><?= h($sprawa['description']) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="card-body py-3 px-4 d-flex gap-4 flex-wrap" style="font-size:.82rem;background:#fff;border-top:1px solid #e8ecf4">
      <span><span class="text-secondary">Segregator:</span> <strong><?= h($sprawa['teczka_symbol'] ?? '—') ?></strong></span>
      <span><span class="text-secondary">Status:</span> <strong><?= h($stat_label) ?></strong></span>
      <?php if (!empty($sprawa['owner_name'])): ?>
      <span><span class="text-secondary">Właściciel:</span> <strong><?= h($sprawa['owner_name']) ?></strong></span>
      <?php endif; ?>
      <?php if (!empty($sprawa['deadline'])): ?>
      <span><span class="text-secondary">Termin:</span> <strong><?= date('d.m.Y', strtotime($sprawa['deadline'])) ?></strong></span>
      <?php endif; ?>
      <span class="ms-auto text-secondary"><?= count($zalaczniki) ?> <?= count($zalaczniki) === 1 ? 'dokument' : (count($zalaczniki) < 5 ? 'dokumenty' : 'dokumentów') ?></span>
    </div>
  </div>

  <!-- ── Akcja: cała sprawa ────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex align-items-center gap-4 flex-wrap px-4 py-3">
      <div style="width:48px;height:48px;background:#fff0f0;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-file-earmark-pdf" style="font-size:1.6rem;color:#dc2626"></i>
      </div>
      <div class="flex-grow-1">
        <div class="fw-bold mb-1">Cała koszulka — jeden plik PDF</div>
        <div class="text-secondary" style="font-size:.82rem">
          Okładka z danymi i opisem koszulki + scalone <?= count($zalaczniki) ?> <?= count($zalaczniki) === 1 ? 'dokument' : 'dokumenty/dokumentów' ?> w jednym pliku.
        </div>
      </div>
      <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>&out=pdf" target="_blank" rel="noopener"
         class="btn btn-danger px-4">
        <i class="bi bi-download me-2"></i>Pobierz PDF
      </a>
    </div>
  </div>

  <!-- ── Lista dokumentów ─────────────────────────────────────────────────── -->
  <?php if ($zalaczniki): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header border-0 bg-white px-4 pt-3 pb-2">
      <span class="fw-semibold"><i class="bi bi-files me-2 text-secondary"></i>Pojedynczy dokument</span>
      <span class="text-secondary ms-2" style="font-size:.8rem">— wybierz z listy</span>
    </div>
    <div class="card-body p-0">
      <?php foreach ($zalaczniki as $i => $z):
        $ext       = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
        $printable = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) || in_array($ext, EZD_PDF_CONVERTIBLE_EXT, true);
        $icon      = ezd_file_icon($z['original_name']);
        $bg        = $i % 2 === 0 ? '' : 'style="background:#fafbfc"';
      ?>
      <div class="d-flex align-items-center gap-3 px-4 py-2 border-bottom" <?= $bg ?>>
        <div style="width:34px;height:34px;background:<?= $printable ? '#eff6ff' : '#f8fafc' ?>;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="bi <?= $icon ?>" style="font-size:1.1rem;color:<?= $printable ? '#2563eb' : '#94a3b8' ?>"></i>
        </div>
        <div class="flex-grow-1 overflow-hidden">
          <div class="fw-semibold text-truncate" style="font-size:.88rem"><?= h($z['original_name']) ?></div>
          <div class="text-secondary" style="font-size:.73rem"><?= h($zal_src($z)) ?> · <?= ezd_filesize($z['file_size']) ?></div>
        </div>
        <?php if ($printable): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>&out=pdf&zal=<?= (int)$z['id'] ?>"
           target="_blank" rel="noopener"
           class="btn btn-sm btn-outline-primary" style="white-space:nowrap">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
        <?php else: ?>
        <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= (int)$z['id'] ?>&dl=1"
           class="btn btn-sm btn-outline-secondary" style="white-space:nowrap" title="Format nie-PDF — pobierz oryginał">
          <i class="bi bi-download me-1"></i>Oryginał
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer border-0 bg-white px-4 py-2" style="font-size:.76rem;color:#94a3b8">
      <i class="bi bi-info-circle me-1"></i>PDF, obrazy (JPG, PNG) i pliki Word/Excel (DOC, DOCX, XLS, XLSX — konwertowane automatycznie) można eksportować do PDF. Inne formaty pobierz jako oryginał — są wykazane na okładce PDF całej koszulki.
    </div>
  </div>
  <?php endif; ?>

</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
