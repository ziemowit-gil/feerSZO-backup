<?php
/**
 * auth/szocert_download.php — pobieranie aplikacji SzoCert (logowanie
 * certyfikatem X.509 bez ręcznego uploadu .p12, patrz client-apps/szocert/).
 *
 * Publiczna (jak reszta auth/) — sam program nie ma żadnej wartości bez
 * zaimportowanego certyfikatu, który i tak wystawia admin osobno.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$DIST_DIR = dirname(__DIR__) . '/client-apps/szocert/dist';
$files = [
    'gui-mac'  => ['SzoCert-macos-gui.zip', 'SzoCert (macOS) — zalecane, dwuklik'],
    'gui-win'  => ['SzoCert-gui-windows.exe', 'SzoCert (Windows) — zalecane, dwuklik'],
    'cli-mac'  => ['szocert-macos', 'szocert (macOS) — wersja terminalowa'],
    'cli-win'  => ['szocert-windows.exe', 'szocert (Windows) — wersja terminalowa'],
];

if (isset($_GET['get'], $files[$_GET['get']])) {
    [$fname] = $files[$_GET['get']];
    $path = $DIST_DIR . '/' . $fname;
    if (!is_file($path)) { http_response_code(404); die('Plik niedostępny — poproś administratora o ponowne zbudowanie (client-apps/szocert/README.md).'); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$PAGE_TITLE = 'SzoCert — logowanie certyfikatem';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($PAGE_TITLE) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>body{background:#f1f5f9}.wrap{max-width:640px;margin:3rem auto;padding:0 1rem}</style>
</head>
<body>
<div class="wrap">
  <div class="text-center mb-4">
    <i class="bi bi-patch-check-fill" style="font-size:2.5rem;color:#0d6efd"></i>
    <h1 class="h4 mt-2">SzoCert</h1>
    <p class="text-muted">Logowanie certyfikatem X.509 bez ręcznego wybierania pliku .p12 przy każdym logowaniu.</p>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <h2 class="h6">1. Pobierz i uruchom</h2>
      <div class="d-grid gap-2">
        <a class="btn btn-primary" href="?get=gui-mac"><i class="bi bi-apple me-1"></i>Pobierz dla macOS</a>
        <a class="btn btn-primary" href="?get=gui-win"><i class="bi bi-windows me-1"></i>Pobierz dla Windows</a>
      </div>
      <p class="small text-muted mt-2 mb-0">
        Niepodpisane pliki — macOS: kliknij prawym → „Otwórz" przy pierwszym uruchomieniu.
        Windows: „Więcej informacji" → „Uruchom mimo to" (SmartScreen).
      </p>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <h2 class="h6">2. Zaimportuj certyfikat (jednorazowo)</h2>
      <ol class="small mb-0">
        <li>Poproś administratora o plik <code>.p12</code> i hasło do niego.</li>
        <li>Uruchom SzoCert (dwuklik) — poprosi o wskazanie pliku i to hasło.</li>
        <li>Ustaw <strong>własne hasło zabezpieczające</strong> — to ono będzie potrzebne przy każdym kolejnym uruchomieniu (hasło od administratora już nie).</li>
      </ol>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <h2 class="h6">3. Loguj się</h2>
      <p class="small mb-0">
        Uruchom SzoCert przed logowaniem (zostanie w tle) i wejdź na stronę logowania —
        w zakładce „Certyfikat X.509" pojawi się przycisk „Zaloguj aplikacją SzoCert".
      </p>
    </div>
  </div>

  <details class="mb-3">
    <summary class="small text-muted">Wersja terminalowa (zaawansowani)</summary>
    <div class="d-grid gap-2 mt-2">
      <a class="btn btn-outline-secondary btn-sm" href="?get=cli-mac">szocert (macOS, CLI)</a>
      <a class="btn btn-outline-secondary btn-sm" href="?get=cli-win">szocert.exe (Windows, CLI)</a>
    </div>
  </details>

  <div class="text-center">
    <a href="<?= APP_URL ?>/auth/login.php" class="small">&larr; Powrót do logowania</a>
  </div>
</div>
</body>
</html>
