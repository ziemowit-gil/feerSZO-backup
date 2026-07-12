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
        $W = 180; // szerokość robocza (mm)

        // ── Paleta i pomocniki wizualne ───────────────────────────────────────
        $cNavy = [22, 53, 102]; $cInk = [28, 35, 51]; $cMuted = [124, 132, 146];
        $cLine = [224, 229, 237]; $cTint = [240, 244, 251]; $cAccent = [37, 99, 235];
        $tc = fn(array $c) => $pdf->SetTextColor($c[0], $c[1], $c[2]);
        $fc = fn(array $c) => $pdf->SetFillColor($c[0], $c[1], $c[2]);
        $dc = fn(array $c) => $pdf->SetDrawColor($c[0], $c[1], $c[2]);
        // Mikro-etykieta sekcji (wersaliki, wyciszona)
        $micro = function (string $t) use ($pdf, $pl, $tc, $cMuted) {
            $pdf->SetFont('DejaVu', 'B', 7.5); $tc($cMuted);
            $pdf->Cell(0, 4.6, $pl(mb_strtoupper($t, 'UTF-8')), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
        };

        // ── Hero: pasek ze znakiem koszulki ──────────────────────────────────
        $bandH = 23;
        $fc($cNavy); $pdf->Rect(15, 15, $W, $bandH, 'F');
        $pdf->SetFont('DejaVu', '', 7.5); $pdf->SetTextColor(182, 198, 226);
        $pdf->SetXY(19, 18.2); $pdf->Cell(90, 4, $pl('KARTA KOSZULKI'), 0, 0, 'L');
        $pdf->SetXY(105, 18.2);
        $pdf->Cell($W - 96, 4, $pl(($org_name ?: '') . '   ·   ' . date('d.m.Y H:i')), 0, 0, 'R');
        $pdf->SetFont('DejaVu', 'B', 18); $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(19, 23.6); $pdf->Cell($W - 8, 9, $pl($sprawa['znak_sprawy'] ?: '—'), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY(15 + $bandH + 7);

        // ── Tytuł + status ────────────────────────────────────────────────────
        $pdf->SetX(15);
        $pdf->SetFont('DejaVu', 'B', 13.5); $tc($cInk);
        $pdf->MultiCell($W, 6.8, $pl($sprawa['title']), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2.5);
        $stat  = EZD_STATUSES_SPRAWA[$sprawa['status']]['label'] ?? $sprawa['status'];
        $pdf->SetFont('DejaVu', 'B', 8);
        $pillW = $pdf->GetStringWidth($pl($stat)) + 9;
        $pillY = $pdf->GetY();
        $fc($cTint); $pdf->Rect(15, $pillY, $pillW, 6.2, 'F');
        $fc($cAccent); $pdf->Rect(15, $pillY, 1.6, 6.2, 'F');   // akcent
        $tc($cNavy); $pdf->SetXY(15, $pillY + 0.4);
        $pdf->Cell($pillW, 5.4, $pl($stat), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY($pillY + 6.2 + 5);

        // ── Opis — wyróżnione pole ────────────────────────────────────────────
        if (!empty($sprawa['description'])) {
            $desc  = $pl($sprawa['description']);
            $lines = max(2, (int)ceil(mb_strlen($sprawa['description']) / 92) + 1);
            $boxH  = $lines * 5.5 + 11;
            $yBox  = $pdf->GetY();
            $fc($cTint);   $pdf->Rect(15, $yBox, $W, $boxH, 'F');
            $fc($cAccent); $pdf->Rect(15, $yBox, 1.6, $boxH, 'F');   // lewy pasek akcentu
            $pdf->SetXY(20, $yBox + 3);
            $micro('Opis koszulki');
            $pdf->SetFont('DejaVu', '', 9.5); $tc($cInk);
            $pdf->SetX(20);
            $pdf->MultiCell($W - 8, 5.5, $desc, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetY($yBox + $boxH + 5);
        }

        // ── Hasło klasyfikacyjne JRWA (pełne, z zawijaniem) ───────────────────
        if ($jrwa && trim((string)($jrwa['title'] ?? '')) !== '') {
            $micro('Hasło klasyfikacyjne JRWA · kat. arch. ' . ($jrwa['kat_arch'] ?? '—'));
            $pdf->SetFont('DejaVu', 'B', 9.5); $tc($cInk);
            $pdf->MultiCell(0, 5.5, $pl(trim(($jrwa['symbol'] ?? '') . '   ' . ($jrwa['title'] ?? ''))), 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(3);
        }

        // ── Metadane (dwie kolumny) ───────────────────────────────────────────
        $meta = [
            ['Segregator',           $sprawa['teczka_symbol'] ?? '—'],
            ['Klasyfikacja JRWA',    $jrwa['symbol'] ?? '—'],
            ['Kategoria archiwalna', $jrwa['kat_arch'] ?? '—'],
            ['Właściciel',           $sprawa['owner_name'] ?? '—'],
            ['Otwarto',              $sprawa['created_at'] ? date('d.m.Y', strtotime($sprawa['created_at'])) : '—'],
        ];
        if (!empty($sprawa['ciagla']))        $meta[] = ['Termin', 'koszulka ciągła (stale otwarta)'];
        elseif (!empty($sprawa['deadline']))  $meta[] = ['Termin', date('d.m.Y', strtotime($sprawa['deadline']))];
        if (!empty($sprawa['closed_at']))     $meta[] = ['Zamknięto', date('d.m.Y', strtotime($sprawa['closed_at']))];

        $colW = ($W - 8) / 2;
        $rowH = 12.5;
        $y0   = $pdf->GetY();
        $col  = 0;
        foreach ($meta as $i => [$k, $v]) {
            $x = 15 + ($col ? $colW + 8 : 0);
            $yr = $y0 + (int)floor($i / 2) * $rowH;
            $pdf->SetXY($x, $yr);
            $pdf->SetFont('DejaVu', 'B', 7.5); $tc($cMuted);
            $pdf->Cell($colW, 4.6, $pl(mb_strtoupper($k, 'UTF-8')), 0, 1);
            $pdf->SetX($x);
            $pdf->SetFont('DejaVu', 'B', 10); $tc($cInk);
            $pdf->Cell($colW, 5.6, $pl($v), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
            $col = 1 - $col;
        }
        $pdf->SetY($y0 + (int)ceil(count($meta) / 2) * $rowH + 3);

        // ── Separator ────────────────────────────────────────────────────────
        $dc($cLine);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);

        // ── Spis dokumentów ───────────────────────────────────────────────────
        $micro('Dokumenty w koszulce (' . count($files) . ')');
        $pdf->Ln(1);
        if ($files) {
            foreach ($files as $i => $z) {
                $yr = $pdf->GetY();
                if ($i % 2 === 1) { $fc($cTint); $pdf->Rect(15, $yr, $W, 7, 'F'); }
                $pdf->SetXY(18, $yr + 1);
                $pdf->SetFont('DejaVu', 'B', 8.5); $tc($cAccent);
                $pdf->Cell(8, 5, $pl(($i + 1) . '.'), 0, 0);
                $pdf->SetFont('DejaVu', '', 8.5); $tc($cInk);
                $pdf->Cell(112, 5, $pl($z['original_name']), 0, 0);
                $pdf->SetFont('DejaVu', '', 8); $tc($cMuted);
                $pdf->Cell($W - 8 - 120, 5, $pl($zal_src($z) . ' · ' . ezd_filesize($z['file_size'])), 0, 1, 'R');
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetY($yr + 7);
            }
        } else {
            $pdf->SetFont('DejaVu', '', 9); $tc($cMuted);
            $pdf->Cell(0, 6, $pl('Brak plików — karta zawiera wyłącznie metadane.'), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
        }

        // ── Kod QR + adnotacje kancelaryjne ───────────────────────────────────
        // Zapewnij miejsce na blok (QR 30 mm + podpisy) — inaczej przejdź na nową stronę.
        if ($pdf->GetY() > 235) { $pdf->AddPage('P', 'A4'); }
        $pdf->Ln(6);
        $dc($cLine);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);
        $blockY = $pdf->GetY();

        // QR z linkiem do koszulki — generowany LOKALNIE (dane sprawy nie wychodzą na zewnątrz)
        $qrSize = 30; // mm
        $qrFile = qr_png_file(rtrim(APP_URL, '/') . '/ezd/sprawy/view.php?id=' . $id,
                              UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $id . '/', 300);
        if ($qrFile) {
            try { $pdf->Image($qrFile, 15, $blockY, $qrSize, $qrSize); } catch (\Throwable $e) {}
            @unlink($qrFile);
            $pdf->SetXY(15, $blockY + $qrSize + 1.5);
            $pdf->SetFont('DejaVu', '', 6.5); $tc($cMuted);
            $pdf->MultiCell($qrSize, 3, $pl('Zeskanuj, aby otworzyć koszulkę w EZD'), 0, 'C');
            $pdf->SetTextColor(0, 0, 0);
        }

        // Adnotacje / miejsce na podpisy — po prawej od QR
        $ax = $qrFile ? 15 + $qrSize + 8 : 15;
        $pdf->SetXY($ax, $blockY);
        $pdf->SetFont('DejaVu', 'B', 7.5); $tc($cMuted);
        $pdf->Cell(195 - $ax, 4.6, $pl('ADNOTACJE KANCELARYJNE'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY($blockY + 6);
        $annLine = function (string $label) use ($pdf, $ax, $pl, $tc, $cMuted, $cLine, $dc) {
            $y   = $pdf->GetY();
            $lbl = $pl($label);
            $pdf->SetX($ax);
            $pdf->SetFont('DejaVu', '', 8); $tc($cMuted);
            $pdf->Cell($pdf->GetStringWidth($lbl) + 2, 7, $lbl, 0, 0);
            $pdf->SetTextColor(0, 0, 0);
            $dc($cLine);
            $pdf->Line($pdf->GetX(), $y + 5.5, 195, $y + 5.5);
            $pdf->Ln(7);
        };
        $annLine('Sprawę założył / prowadzi:');
        $annLine('Data przekazania / dekretacja:');
        $annLine('Sprawę zakończono dnia:');
        $annLine('Przekazano do archiwum (kat. ' . ($jrwa['kat_arch'] ?? '—') . '):');

        // Zejdź pod wyższy z dwóch bloków (QR vs adnotacje)
        $pdf->SetY(max($pdf->GetY(), $blockY + $qrSize + ($qrFile ? 6 : 0)));
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
$stat_label = EZD_STATUSES_SPRAWA[$sprawa['status']]['label'] ?? $sprawa['status'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active">Drukuj</li>
</ol></nav>

<?= flash_html() ?>

<style>
  .pk-wrap{max-width:900px}
  .pk-hero{border-radius:16px;overflow:hidden;background:#fff;
           box-shadow:0 1px 2px rgba(16,42,82,.06),0 14px 32px -18px rgba(16,42,82,.35)}
  .pk-hero__top{background:linear-gradient(135deg,#12305e 0%,#1e4a8a 100%);color:#fff;padding:22px 26px 20px}
  .pk-eyebrow{font-size:.66rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:rgba(255,255,255,.6)}
  .pk-znak{font-size:1.55rem;font-weight:800;line-height:1.12;letter-spacing:.01em;color:#fff;margin-top:3px}
  .pk-title{font-size:1.02rem;font-weight:600;color:rgba(255,255,255,.92);margin-top:10px;line-height:1.4}
  .pk-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
  .pk-chip{display:inline-flex;align-items:center;gap:6px;font-size:.74rem;font-weight:600;
           padding:4px 11px;border-radius:999px;background:rgba(255,255,255,.13);
           color:#fff;border:1px solid rgba(255,255,255,.22);white-space:nowrap}
  .pk-chip i{opacity:.8}
  .pk-back{background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.26);
           border-radius:8px;white-space:nowrap;transition:background .15s}
  .pk-back:hover{background:rgba(255,255,255,.26);color:#fff}
  .pk-desc{background:#f2f6fd;border-top:1px solid #e6eefb;padding:14px 24px;
           display:flex;gap:12px;align-items:flex-start}
  .pk-desc__lbl{font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#5a719e;margin-bottom:3px}
  .pk-desc__txt{font-size:.92rem;color:#1a2a45;line-height:1.55;white-space:pre-wrap}
  .pk-meta{display:flex;flex-wrap:wrap;gap:18px 30px;padding:16px 24px;border-top:1px solid #eef2f8}
  .pk-meta__k{font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#8a94a6}
  .pk-meta__v{font-size:.9rem;font-weight:700;color:#1c2333;margin-top:2px}
  .pk-card{border:1px solid #edf0f6;border-radius:14px;background:#fff;
           box-shadow:0 1px 2px rgba(16,42,82,.05);transition:box-shadow .15s,border-color .15s;overflow:hidden}
  .pk-cta{display:flex;align-items:center;gap:18px;padding:18px 22px}
  .pk-cta__icon{width:52px;height:52px;border-radius:12px;background:#fdecec;display:flex;
                align-items:center;justify-content:center;flex-shrink:0;color:#dc2626;font-size:1.7rem}
  .pk-cta__btn{background:#dc2626;border:0;color:#fff;font-weight:600;border-radius:9px;
               padding:11px 22px;white-space:nowrap;transition:background .15s,transform .1s}
  .pk-cta__btn:hover{background:#b91c1c;color:#fff;transform:translateY(-1px)}
  .pk-sec{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7a8496}
  .pk-doc{display:flex;align-items:center;gap:14px;padding:11px 20px;border-top:1px solid #f2f4f9;transition:background .12s}
  .pk-doc:first-of-type{border-top:0}
  .pk-doc:hover{background:#f7f9fc}
  .pk-doc__icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .pk-doc__name{font-size:.88rem;font-weight:600;color:#1c2333}
  .pk-doc__meta{font-size:.73rem;color:#8a94a6;margin-top:1px}
  .pk-foot{font-size:.75rem;color:#98a2b3;padding:12px 20px;border-top:1px solid #f2f4f9;background:#fcfdff}
</style>

<div class="pk-wrap">

  <!-- ── Karta podglądu sprawy ─────────────────────────────────────────────── -->
  <div class="pk-hero mb-4">
    <div class="pk-hero__top">
      <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
        <div class="flex-grow-1">
          <div class="pk-eyebrow">Znak koszulki</div>
          <div class="pk-znak"><?= h($sprawa['znak_sprawy']) ?></div>
        </div>
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>" class="btn btn-sm pk-back">
          <i class="bi bi-arrow-left me-1"></i>Wróć do koszulki
        </a>
      </div>
      <div class="pk-title"><?= h($sprawa['title']) ?></div>
      <div class="pk-chips">
        <span class="pk-chip"><i class="bi bi-flag"></i><?= h($stat_label) ?></span>
        <span class="pk-chip"><i class="bi bi-folder2"></i><?= h($sprawa['teczka_symbol'] ?? '—') ?></span>
        <?php if ($jrwa): ?>
        <span class="pk-chip"><i class="bi bi-diagram-3"></i>JRWA <?= h($jrwa['symbol'] ?? '—') ?></span>
        <span class="pk-chip"><i class="bi bi-archive"></i>kat. <?= h($jrwa['kat_arch'] ?? '—') ?></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($sprawa['description'])): ?>
    <div class="pk-desc">
      <i class="bi bi-card-text" style="color:#2f5aa8;font-size:1.15rem;margin-top:1px;flex-shrink:0"></i>
      <div>
        <div class="pk-desc__lbl">Opis koszulki</div>
        <div class="pk-desc__txt"><?= h($sprawa['description']) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <div class="pk-meta">
      <div><div class="pk-meta__k">Właściciel</div><div class="pk-meta__v"><?= h($sprawa['owner_name'] ?? '—') ?></div></div>
      <div><div class="pk-meta__k">Otwarto</div><div class="pk-meta__v"><?= $sprawa['created_at'] ? date('d.m.Y', strtotime($sprawa['created_at'])) : '—' ?></div></div>
      <?php if (!empty($sprawa['ciagla'])): ?>
      <div><div class="pk-meta__k">Termin</div><div class="pk-meta__v">koszulka ciągła</div></div>
      <?php elseif (!empty($sprawa['deadline'])): ?>
      <div><div class="pk-meta__k">Termin</div><div class="pk-meta__v"><?= date('d.m.Y', strtotime($sprawa['deadline'])) ?></div></div>
      <?php endif; ?>
      <div class="ms-auto"><div class="pk-meta__k">Dokumenty</div><div class="pk-meta__v"><?= count($zalaczniki) ?></div></div>
    </div>
  </div>

  <!-- ── Akcja: cała sprawa ────────────────────────────────────────────────── -->
  <div class="pk-card mb-3">
    <div class="pk-cta">
      <div class="pk-cta__icon"><i class="bi bi-file-earmark-pdf"></i></div>
      <div class="flex-grow-1">
        <div class="fw-bold mb-1">Cała koszulka — jeden plik PDF</div>
        <div class="text-secondary" style="font-size:.83rem">
          Okładka (dane, klasyfikacja JRWA, kod QR, adnotacje) + scalone
          <?= count($zalaczniki) ?> <?= count($zalaczniki) === 1 ? 'dokument' : 'dokumenty/dokumentów' ?> w jednym pliku.
        </div>
      </div>
      <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>&out=pdf" target="_blank" rel="noopener" class="btn pk-cta__btn">
        <i class="bi bi-download me-2"></i>Pobierz PDF
      </a>
    </div>
  </div>

  <!-- ── Lista dokumentów ─────────────────────────────────────────────────── -->
  <?php if ($zalaczniki): ?>
  <div class="pk-card">
    <div class="d-flex align-items-center px-4 pt-3 pb-2">
      <span class="pk-sec"><i class="bi bi-files me-2"></i>Pojedynczy dokument</span>
      <span class="text-secondary ms-2" style="font-size:.8rem">— pobierz z listy</span>
    </div>
    <div>
      <?php foreach ($zalaczniki as $i => $z):
        $ext       = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION));
        $printable = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true) || in_array($ext, EZD_PDF_CONVERTIBLE_EXT, true);
        $icon      = ezd_file_icon($z['original_name']);
      ?>
      <div class="pk-doc">
        <div class="pk-doc__icon" style="background:<?= $printable ? '#eef4ff' : '#f5f7fa' ?>">
          <i class="bi <?= $icon ?>" style="font-size:1.1rem;color:<?= $printable ? '#2563eb' : '#94a3b8' ?>"></i>
        </div>
        <div class="flex-grow-1 overflow-hidden">
          <div class="pk-doc__name text-truncate"><?= h($z['original_name']) ?></div>
          <div class="pk-doc__meta"><?= h($zal_src($z)) ?> · <?= ezd_filesize($z['file_size']) ?></div>
        </div>
        <?php if ($printable): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/print.php?id=<?= $id ?>&out=pdf&zal=<?= (int)$z['id'] ?>"
           target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" style="white-space:nowrap">
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
    <div class="pk-foot">
      <i class="bi bi-info-circle me-1"></i>PDF, obrazy (JPG, PNG) i pliki Word/Excel (DOC, DOCX, XLS, XLSX — konwertowane automatycznie) można eksportować do PDF. Inne formaty pobierz jako oryginał — są wykazane na okładce PDF całej koszulki.
    </div>
  </div>
  <?php endif; ?>

</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
