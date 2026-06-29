<?php
/**
 * konsultacje/pdf.php — Oficjalny protokół „Karta konsultacji" do druku/PDF.
 *
 * GET: id (int) — ID karty.
 *
 * Widok zoptymalizowany pod @media print (A4) z auto-wywołaniem okna druku.
 * Dla przeglądarki „Zapisz jako PDF" daje gotowy dokument. Układ jest tak
 * przygotowany, że ten sam HTML można też podać do Dompdf/mPDF (jeśli będą
 * dostępne) — patrz funkcja cc_render_pdf_html() na dole pliku.
 *
 * Dostęp: tylko zalogowani (dokument zawiera pełną treść konsultacji).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/consultations.php';

$id = (int)($_GET['id'] ?? 0);

// Dostęp: zalogowany (nie-viewer) ALBO osoba, która właśnie wypełniła tę kartę
// w publicznym formularzu (ID na liście dozwolonych w sesji). Dzięki temu
// konsultant bez konta może wygenerować PDF tylko dla swojego świeżego wpisu.
auth_start();
$pub_ok = $id > 0 && in_array($id, $_SESSION['cc_pub_pdf'] ?? [], true);
if (!$pub_ok) {
    require_login();
    if (is_viewer()) { http_response_code(403); exit('Brak dostępu.'); }
}

$c = $id ? cc_get($id) : null;
if (!$c) { http_response_code(404); exit('Karta konsultacyjna nie istnieje.'); }

$auto_print = !isset($_GET['noprint']);
$just_saved = isset($_GET['saved']);

echo cc_render_pdf_html($c, $auto_print, $just_saved);

/**
 * Zwraca kompletny dokument HTML protokołu konsultacji.
 * Wydzielone do funkcji, aby ten sam markup mógł zasilić generator PDF
 * (Dompdf/mPDF) bez fragmentu auto-druku.
 */
function cc_render_pdf_html(array $c, bool $auto_print = false, bool $just_saved = false): string
{
    $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $area = cc_label(cc_areas(),  $c['area_type']);
    $form = cc_label(cc_forms(),  $c['form']);
    $date = date_pl($c['consultation_date']);
    $hrs  = cc_hours_label((float)$c['hours']);

    // Data sporządzenia — z created_at, a gdy go brak (np. stary rekord) → dziś.
    $prepared = substr((string)($c['created_at'] ?? ''), 0, 10);
    if ($prepared === '' || !cc_valid_date($prepared)) {
        $prepared = date('Y-m-d');
    }
    $prepared_pl = date_pl($prepared);

    // Logo Miasta Krakowa do stopki — wczytywane z pliku, jeśli istnieje.
    // Wgraj oficjalny znak pod jedną ze ścieżek (PNG/JPG/SVG) i pojawi się sam.
    $krakow_logo = cc_krakow_logo_tag();

    // Sekcje opisowe — zachowaj akapity z formularza.
    $section = function (string $title, ?string $body) {
        $txt = trim((string)$body);
        $html = $txt !== '' ? nl2br(h($txt)) : '<span class="muted">—</span>';
        return '<section class="block"><h2>' . h($title) . '</h2><div class="prose">' . $html . '</div></section>';
    };

    // Link powrotu: zalogowany → lista kart; publiczny konsultant → formularz.
    $back_url   = (function_exists('current_user') && current_user() && !is_viewer())
        ? APP_URL . '/konsultacje/admin.php'
        : APP_URL . '/konsultacje/form.php';
    $back_label = (function_exists('current_user') && current_user() && !is_viewer())
        ? 'Powrót do listy' : 'Powrót do formularza';

    // Forma zdalna (online / telefonicznie / mailowo) — beneficjent nie podpisuje.
    $is_remote = in_array($c['form'], ['online', 'telefonicznie', 'mailowo'], true);

    $consultant = trim((string)$c['consultant']) !== '' ? h($c['consultant']) : '&nbsp;';

    // Prawa kolumna podpisów: linia dla konsultacji stacjonarnej,
    // adnotacja o braku podpisu dla konsultacji zdalnej.
    if ($is_remote) {
        $beneficiary_col =
            '<div class="sig">
               <div class="remote-note">
                 Konsultacja udzielona zdalnie (' . h($form) . ') —
                 podpis beneficjenta organizacji nie jest wymagany.
               </div>
             </div>';
    } else {
        $beneficiary_col =
            '<div class="sig">
               <div class="line">
                 <div class="name">&nbsp;</div>
                 <div class="role">Podpis przedstawiciela organizacji</div>
               </div>
             </div>';
    }

    // Dopisek o finansowaniu — stała stopka dokumentu.
    $project_note =
        'Konsultacja udzielona w ramach projektu „Akademia Dostępności w NGO" '
      . 'finansowanego ze środków Miasta Krakowa.';
    $print_js = $auto_print
        ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},250);});</script>'
        : '';

    $saved_banner = $just_saved
        ? '<div class="saved-banner" role="status">Karta zapisana. Dokument zostanie wydrukowany — wybierz „Zapisz jako PDF", aby pobrać plik.</div>'
        : '';

    $title = 'Karta konsultacji nr ' . (int)$c['id'];

    return '<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . h($title) . '</title>
<style>
  :root { --ink:#1a1a1a; --line:#94a3b8; --muted:#64748b; }
  * { box-sizing:border-box; }
  html,body { margin:0; padding:0; }
  body {
    font-family: "DejaVu Sans", "Segoe UI", Arial, sans-serif;
    color: var(--ink); font-size: 12pt; line-height: 1.5; background:#f1f5f9;
  }
  .sheet {
    background:#fff; width: 210mm; min-height: 297mm; margin: 12px auto;
    padding: 22mm 20mm; box-shadow: 0 2px 18px rgba(0,0,0,.12);
    display:flex; flex-direction:column;
  }
  .toolbar {
    max-width:210mm; margin: 14px auto 0; display:flex; gap:.5rem; justify-content:flex-end;
  }
  .toolbar button, .toolbar a {
    font: inherit; font-size: 11pt; padding:.45rem .9rem; border-radius:6px;
    border:1px solid #cbd5e1; background:#fff; color:#1d4ed8; cursor:pointer; text-decoration:none;
  }
  .toolbar button:focus-visible, .toolbar a:focus-visible { outline:3px solid #1d4ed8; outline-offset:2px; }
  .saved-banner {
    max-width:210mm; margin: 10px auto 0; padding:.6rem .9rem; border-radius:8px;
    background:#ecfdf5; border:1px solid #86efac; color:#166534; font-size:11pt;
  }
  header.doc { border-bottom:2px solid var(--ink); padding-bottom:10px; margin-bottom:18px; }
  .org { font-size:13pt; font-weight:700; letter-spacing:.2px; }
  h1 { font-size:18pt; margin:6px 0 0; text-transform:uppercase; letter-spacing:.5px; }
  .docno { color:var(--muted); font-size:10.5pt; margin-top:2px; }
  /* Tabela metryczki */
  table.meta { width:100%; border-collapse:collapse; margin-bottom:14px; }
  table.meta th, table.meta td {
    text-align:left; padding:7px 10px; border:1px solid #d7dde5; vertical-align:top; font-size:11.5pt;
  }
  table.meta th { width:34%; background:#f4f6fa; font-weight:600; color:#334155; }
  .block { margin: 10px 0 4px; }
  .block h2 {
    font-size:11.5pt; text-transform:uppercase; letter-spacing:.4px;
    color:#334155; border-bottom:1px solid #d7dde5; padding-bottom:4px; margin:0 0 6px;
  }
  .prose { white-space:normal; }
  .muted { color:var(--muted); }
  .spacer { flex:1 1 auto; min-height: 18mm; }
  /* Stopka podpisów — dwie równe kolumny */
  .signatures {
    margin-top: 16mm; display:flex; gap: 18mm; page-break-inside: avoid;
  }
  .sig { flex:1 1 0; text-align:center; }
  .sig .line {
    border-top:1px dotted var(--ink); margin-top: 16mm; padding-top:6px;
    font-size:10.5pt; color:#334155;
  }
  .sig .name { font-weight:600; min-height:1.2em; }
  .sig .role { color:var(--muted); font-size:9.5pt; }
  .sig .remote-note {
    margin-top: 10mm; padding:10px 12px; border:1px dashed var(--line);
    border-radius:6px; background:#f8fafc; color:#475569; font-size:9.5pt;
    line-height:1.4; font-style:italic;
  }
  .project-note {
    margin-top: 10mm; padding:9px 12px; border-left:3px solid var(--ink);
    background:#f4f6fa; color:#334155; font-size:9.5pt; line-height:1.45;
  }
  .funding {
    margin-top: 8mm; display:flex; flex-direction:column; align-items:center;
    gap:5px; text-align:center;
  }
  .funding img { max-height: 22mm; max-width: 70mm; width:auto; height:auto; }
  .funding .logo-missing {
    width:60mm; height:18mm; border:1px dashed var(--line); border-radius:6px;
    display:flex; align-items:center; justify-content:center;
    color:var(--muted); font-size:9pt; padding:4px 8px;
  }
  .funding-cap { color:var(--muted); font-size:9pt; }
  footer.doc { margin-top: 8mm; border-top:1px solid #d7dde5; padding-top:6px;
    color:var(--muted); font-size:9pt; display:flex; justify-content:space-between; }
  @media print {
    body { background:#fff; }
    .toolbar, .saved-banner { display:none !important; }
    .sheet { box-shadow:none; margin:0; width:auto; min-height:auto; padding:0; }
    @page { size: A4; margin: 18mm; }
  }
</style>
</head>
<body>
  <div class="toolbar" role="toolbar" aria-label="Akcje dokumentu">
    <button type="button" onclick="window.print()">Drukuj / zapisz jako PDF</button>
    <a href="' . h($back_url) . '">' . h($back_label) . '</a>
  </div>
  ' . $saved_banner . '

  <article class="sheet">
    <header class="doc">
      <div class="org">' . h($org) . '</div>
      <h1>Karta konsultacji</h1>
      <div class="docno">Dokument nr ' . (int)$c['id'] . ' &middot; data sporządzenia: ' . h($prepared_pl) . '</div>
    </header>

    <table class="meta">
      <tbody>
        <tr><th scope="row">Organizacja</th><td>' . h($c['org_name']) . '</td></tr>
        <tr><th scope="row">Data konsultacji</th><td>' . h($date) . '</td></tr>
        <tr><th scope="row">Obszar wsparcia</th><td>' . h($area) . '</td></tr>
        <tr><th scope="row">Forma konsultacji</th><td>' . h($form) . '</td></tr>
        <tr><th scope="row">Liczba godzin</th><td>' . h($hrs) . '</td></tr>
      </tbody>
    </table>

    ' . $section('Problem / zagadnienie', $c['problem_description']) . '
    ' . $section('Podjęte czynności',     $c['actions_taken']) . '
    ' . $section('Dalsze kroki',          $c['next_steps']) . '

    <div class="spacer"></div>

    <div class="signatures">
      <div class="sig">
        <div class="line">
          <div class="name">' . $consultant . '</div>
          <div class="role">Podpis konsultanta</div>
        </div>
      </div>
      ' . $beneficiary_col . '
    </div>

    <div class="project-note">' . h($project_note) . '</div>

    <div class="funding">
      ' . $krakow_logo . '
      <div class="funding-cap">Projekt finansowany ze środków Miasta Krakowa</div>
    </div>

    <footer class="doc">
      <span>' . h($org) . '</span>
      <span>Karta konsultacyjna #' . (int)$c['id'] . ' &middot; ' . h($prepared_pl) . '</span>
    </footer>
  </article>
' . $print_js . '
</body>
</html>';
}

/**
 * Zwraca znacznik <img> z logo Miasta Krakowa, jeśli plik istnieje, w przeciwnym
 * razie dyskretny placeholder ze wskazówką, gdzie wgrać znak.
 *
 * Oficjalny znak należy wgrać (zachowując zasady KIWizualizacji Miasta) pod jedną
 * ze ścieżek poniżej. Plik osadzamy jako data-URI, aby działał też na wydruku/PDF.
 */
function cc_krakow_logo_tag(): string
{
    $root = dirname(__DIR__);
    $candidates = [
        '/assets/logo/krakow.svg', '/assets/logo/krakow.png', '/assets/logo/krakow.jpg',
        '/assets/img/krakow.svg',  '/assets/img/krakow.png',  '/assets/img/krakow.jpg',
    ];
    foreach ($candidates as $rel) {
        $path = $root . $rel;
        if (!is_file($path) || filesize($path) === 0) continue;

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'svg'        => 'image/svg+xml',
            'jpg','jpeg' => 'image/jpeg',
            default      => 'image/png',
        };
        $data = @file_get_contents($path);
        if ($data === false) continue;

        $uri = 'data:' . $mime . ';base64,' . base64_encode($data);
        return '<img src="' . h($uri) . '" alt="Logo Miasta Krakowa">';
    }

    // Brak pliku — placeholder z instrukcją (widoczny także na wydruku).
    return '<div class="logo-missing">Logo Miasta Krakowa<br>(wgraj plik: assets/logo/krakow.png)</div>';
}
