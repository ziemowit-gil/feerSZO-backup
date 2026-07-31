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
require_once dirname(dirname(__DIR__)) . '/includes/qr.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$id     = (int)($_GET['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error', 'Koszulka nie istnieje.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }
if (!ezd_sprawa_access($sprawa, (int)current_user()['id'])) { flash_set('error', 'Brak dostępu do tej koszulki.'); header('Location: ' . APP_URL . '/ezd/index.php'); exit; }

// Klasyfikacja JRWA (symbol, hasło, kategoria archiwalna) — dziedziczona z segregatora
$jrwa = !empty($sprawa['jrwa_id']) ? ezd_jrwa_get((int)$sprawa['jrwa_id']) : null;

$out   = $_GET['out'] ?? '';
$zalId = (int)($_GET['zal'] ?? 0);

// Wydruk sprawy = operacja na danych osobowych → wymaga re-autoryzacji IKA
// (Indywidualny Kod Autoryzacyjny). Bramka egzekwuje politykę wg roli i wraca
// na dokładnie ten sam URL wydruku (zachowując out/zal). Musi być PRZED outputem.
ika_require(APP_URL . '/ezd/sprawy/print.php?' . http_build_query(array_filter([
    'id'  => $id,
    'out' => $out !== '' ? $out : null,
    'zal' => $zalId ?: null,
])));

$zalaczniki = ezd_zalaczniki_by($id); // wszystkie dokumenty sprawy (jednolita ścieżka)

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

        $cNavy = [22, 53, 102]; $cInk = [28, 35, 51]; $cMuted = [124, 132, 146];
        $cLine = [224, 229, 237];
        $tc = fn(array $c) => $pdf->SetTextColor($c[0], $c[1], $c[2]);
        $fc = fn(array $c) => $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $dc = fn(array $c) => $pdf->SetDrawColor($c[0], $c[1], $c[2]);

        // ── Pasek: numer + data wszczęcia ────────────────────────────────────
        $bandH = 16;
        $fc($cNavy); $pdf->Rect(15, 15, $W, $bandH, 'F');
        $pdf->SetFont('DejaVu', 'B', 14); $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(19, 19.5);
        $pdf->Cell($W * 0.6, 8, $pl($sprawa['znak_sprawy'] ?: '—'), 0, 0, 'L');
        $dateStr = $sprawa['created_at'] ? date('d.m.Y', strtotime($sprawa['created_at'])) : '—';
        $pdf->SetFont('DejaVu', '', 9); $pdf->SetTextColor(182, 198, 226);
        $pdf->Cell($W * 0.4 - 4, 8, $pl('Data wszczęcia: ' . $dateStr), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY(15 + $bandH + 10);

        // ── Tytuł ─────────────────────────────────────────────────────────────
        $pdf->SetX(15);
        $pdf->SetFont('DejaVu', 'B', 15); $tc($cInk);
        $pdf->MultiCell($W, 7.5, $pl($sprawa['title']), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(8);

        // ── Separator ─────────────────────────────────────────────────────────
        $dc($cLine);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(8);

        // ── QR kod (prawy górny narożnik bloku) + lista dokumentów po lewej ──
        $qrSize = 35;
        $qrFile = qr_png_file(rtrim(APP_URL, '/') . '/ezd/sprawy/view.php?id=' . $id,
                              UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $id . '/', 300);
        $blockY = $pdf->GetY();
        if ($qrFile) {
            try { $pdf->Image($qrFile, 195 - $qrSize, $blockY, $qrSize, $qrSize); } catch (\Throwable $e) {}
            @unlink($qrFile);
            $pdf->SetXY(195 - $qrSize, $blockY + $qrSize + 1.5);
            $pdf->SetFont('DejaVu', '', 6.5); $tc($cMuted);
            $pdf->Cell($qrSize, 3, $pl('Otwórz w EZD'), 0, 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetY($blockY);
        }

        $listW = $qrFile ? $W - $qrSize - 8 : $W;
        $pdf->SetFont('DejaVu', 'B', 7.5); $tc($cMuted);
        $pdf->SetX(15); $pdf->Cell($listW, 5, $pl('DOKUMENTY (' . count($files) . ')'), 0, 1);
        $pdf->Ln(1);
        if ($files) {
            foreach ($files as $i => $z) {
                $pdf->SetX(15);
                $pdf->SetFont('DejaVu', '', 8.5); $tc($cInk);
                $pdf->Cell(6, 5.5, $pl(($i + 1) . '.'), 0, 0);
                $pdf->Cell($listW - 6, 5.5, $pl($z['original_name']), 0, 1);
                $pdf->SetTextColor(0, 0, 0);
            }
        } else {
            $pdf->SetFont('DejaVu', '', 9); $tc($cMuted);
            $pdf->Cell($listW, 6, $pl('Brak dokumentów w koszulce.'), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->SetY(max($pdf->GetY() + 4, $blockY + $qrSize + ($qrFile ? 10 : 0)));
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
            // Word/Excel — konwertuj "w locie" na PDF przez SharePoint/Graph (bez trwałego załącznika) i dołącz strony.
            // Plik tymczasowy ląduje w tym samym (znanym jako zapisywalny) katalogu co reszta załączników
            // sprawy — NIE w sys_get_temp_dir(), które bywa niedostępne pod open_basedir na hostingu.
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
                if ($pdfContent === '') throw new \RuntimeException('SharePoint zwrócił pusty plik PDF.');
                $tmpDir = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . (int)$z['sprawa_id'] . '/';
                if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
                    throw new \RuntimeException('Katalog załączników sprawy jest niedostępny.');
                }
                $tmpPdf = $tmpDir . '_convert_' . uniqid('', true) . '.pdf';
                if (file_put_contents($tmpPdf, $pdfContent) === false) {
                    throw new \RuntimeException('Nie udało się zapisać tymczasowego pliku PDF.');
                }
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
                if ($tmpPdf && is_file($tmpPdf)) @unlink($tmpPdf);
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
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Drukuj</li>
</ol></nav>

<?= flash_html() ?>

<style>
.ezd-hero{display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap;background:linear-gradient(135deg,#fef2f2 0%,#ffffff 60%);border:1px solid #fee2e2;border-radius:16px;padding:1.15rem 1.4rem;margin-bottom:1.25rem;}
.ezd-hero-icon{width:52px;height:52px;border-radius:14px;background:#dc2626;color:#fff;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.55rem;box-shadow:0 4px 10px rgba(220,38,38,.28);}
.ezd-hero-title{font-size:1.2rem;font-weight:800;color:#0f172a;line-height:1.2;margin:.15rem 0 .1rem;}
.ezd-hero-desc{font-size:.83rem;color:#5b6472;line-height:1.5;margin-top:.35rem;}
.bc{background:#fff;border:1.5px solid #e8edf3;border-radius:12px;overflow:hidden;margin-bottom:.85rem;}
.bc-h{padding:.5rem 1rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#94a3b8;border-bottom:1px solid #f1f5f9;background:#fafbfc;display:flex;align-items:center;gap:.4rem;}
.bc-b{padding:.9rem 1rem;}
.pr-doc{display:flex;align-items:center;gap:12px;padding:9px 16px;border-top:1px solid #f2f4f9;transition:background .12s;}
.pr-doc:first-of-type{border-top:0;}
.pr-doc:hover{background:#f7f9fc;}
.pr-doc__icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.pr-doc__name{font-size:.87rem;font-weight:600;color:#1c2333;}
.pr-doc__meta{font-size:.72rem;color:#8a94a6;margin-top:1px;}
.pr-foot{font-size:.74rem;color:#94a3b8;padding:9px 16px;border-top:1px solid #f2f4f9;background:#fafbfc;}
</style>

<div style="max-width:820px">

  <!-- Hero -->
  <div class="ezd-hero">
    <div class="ezd-hero-icon"><i class="bi bi-printer"></i></div>
    <div class="flex-grow-1">
      <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
        <code class="bg-white px-2 py-0 rounded fw-bold border" style="font-size:.82rem;color:#1d4ed8"><?= h($sprawa['znak_sprawy']) ?></code>
        <?= ezd_status_badge_sprawa($sprawa['status']) ?>
        <?php if ($jrwa): ?>
        <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.68rem">
          <i class="bi bi-archive me-1"></i>kat. <?= h($jrwa['kat_arch'] ?? '—') ?>
        </span>
        <?php endif; ?>
        <span class="text-muted ms-auto" style="font-size:.75rem"><?= count($zalaczniki) ?> dok.</span>
      </div>
      <div class="ezd-hero-title"><?= h($sprawa['title']) ?></div>
      <?php if (!empty($sprawa['description'])): ?>
      <div class="ezd-hero-desc"><?= h($sprawa['description']) ?></div>
      <?php endif; ?>
    </div>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary align-self-start flex-shrink-0">
      <i class="bi bi-arrow-left me-1"></i>Wróć
    </a>
  </div>

  <!-- Cała koszulka -->
  <div class="bc">
    <div class="bc-h"><i class="bi bi-file-earmark-pdf text-danger"></i>Cały dokument</div>
    <div class="bc-b d-flex align-items-center gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="fw-semibold mb-1" style="font-size:.92rem">Koszulka — jeden plik PDF</div>
        <div class="text-secondary" style="font-size:.81rem">
          Okładka (dane, JRWA, kod QR, adnotacje kancelaryjne) +
          <?= count($zalaczniki) ?> <?= count($zalaczniki) === 1 ? 'dokument' : 'dokumenty/dokumentów' ?> scalonych w jeden plik.
        </div>
      </div>
      <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>&out=pdf" target="_blank" rel="noopener"
         class="btn btn-danger btn-sm flex-shrink-0" style="white-space:nowrap">
        <i class="bi bi-download me-1"></i>Pobierz PDF
      </a>
    </div>
  </div>

  <!-- Lista dokumentów -->
  <?php if ($zalaczniki): ?>
  <div class="bc">
    <div class="bc-h"><i class="bi bi-files"></i>Pojedynczy dokument</div>
    <div>
      <?php foreach ($zalaczniki as $z):
        $ext       = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
        $printable = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) || in_array($ext, EZD_PDF_CONVERTIBLE_EXT, true);
        $icon      = ezd_file_icon($z['original_name']);
      ?>
      <div class="pr-doc">
        <div class="pr-doc__icon" style="background:<?= $printable ? '#eef4ff' : '#f5f7fa' ?>">
          <i class="bi <?= $icon ?>" style="font-size:1rem;color:<?= $printable ? '#2563eb' : '#94a3b8' ?>"></i>
        </div>
        <div class="flex-grow-1 overflow-hidden">
          <div class="pr-doc__name text-truncate"><?= h($z['original_name']) ?></div>
          <div class="pr-doc__meta"><?= h($zal_src($z)) ?> · <?= ezd_filesize($z['file_size']) ?></div>
        </div>
        <?php if ($printable): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>&out=pdf&zal=<?= (int)$z['id'] ?>"
           target="_blank" rel="noopener" class="btn btn-sm btn-outline-danger flex-shrink-0" style="white-space:nowrap">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
        <?php else: ?>
        <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= (int)$z['id'] ?>&dl=1"
           class="btn btn-sm btn-outline-secondary flex-shrink-0" style="white-space:nowrap" title="Format nie-PDF — pobierz oryginał">
          <i class="bi bi-download me-1"></i>Oryginał
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="pr-foot">
      <i class="bi bi-info-circle me-1"></i>PDF, JPG, PNG i pliki Word/Excel są konwertowane automatycznie. Pozostałe formaty pobierz jako oryginał — są wykazane na okładce.
    </div>
  </div>
  <?php endif; ?>

</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
