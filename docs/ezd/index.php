<?php
/**
 * docs/ezd/index.php — Swagger UI dla specyfikacji HTTP modułu EZD.
 * Dokumentacja opisuje wewnętrzne API — dostęp tylko dla zalogowanych przez
 * Microsoft 365 (konto @feer.org.pl). Niezalogowani trafiają wprost do
 * ekranu logowania MS365 (bez ekranu wyboru metody, jak auth/ms365.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

if (!current_user()) {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $base = parse_url(APP_URL, PHP_URL_PATH) ?? '';
    if ($base !== '' && $base !== '/' && str_starts_with($uri, $base . '/')) {
        $uri = substr($uri, strlen($base));
    }
    header('Location: ' . APP_URL . '/auth/ms365.php?redirect=' . urlencode(APP_URL . $uri));
    exit;
}
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>EZD „Wirtualne Biurko" — dokumentacja HTTP (Swagger UI)</title>
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Ctext y='14' font-size='14'%3E%F0%9F%97%83%EF%B8%8F%3C/text%3E%3C/svg%3E">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
  <style>
    :root { --topbar: #1f2937; }
    body { margin: 0; background: #fafafa; }
    .feer-topbar {
      background: var(--topbar);
      color: #fff;
      padding: 12px 20px;
      font: 600 15px/1.3 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      display: flex; align-items: center; gap: 10px;
    }
    .feer-topbar small { font-weight: 400; opacity: .7; }
    .feer-topbar a.guide { margin-left: auto; color: #fff; text-decoration: none;
      border: 1px solid rgba(255,255,255,.35); border-radius: 8px; padding: 5px 12px;
      font-size: 13px; white-space: nowrap; }
    .feer-topbar a.guide:hover { border-color: #fff; background: rgba(255,255,255,.1); }
    #swagger-ui .topbar { display: none; } /* ukryj domyślny pasek z polem URL */
  </style>
</head>
<body>
  <div class="feer-topbar">
    🗃️ EZD „Wirtualne Biurko" <small>— specyfikacja interfejsu HTTP (System Obsługi Organizacji FEER)</small>
    <a class="guide" href="./przewodnik.php">📖 Przewodnik programisty</a>
  </div>
  <div id="swagger-ui"></div>

  <footer style="padding:16px 20px;color:#94a3b8;font:400 12px/1.5 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;text-align:center">
    Autorzy: Ziemowit Gil, Jarosław Połczyński, Sebastian Dzienisowicz — na potrzeby FEER'a
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js" crossorigin></script>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-standalone-preset.js" crossorigin></script>
  <script>
    window.addEventListener('load', function () {
      window.ui = SwaggerUIBundle({
        url: './openapi.php',
        dom_id: '#swagger-ui',
        deepLinking: true,
        docExpansion: 'none',
        defaultModelsExpandDepth: 0,
        tryItOutEnabled: false,
        presets: [
          SwaggerUIBundle.presets.apis,
          SwaggerUIStandalonePreset
        ],
        plugins: [
          SwaggerUIBundle.plugins.DownloadUrl
        ],
        layout: 'StandaloneLayout'
      });
    });
  </script>
</body>
</html>
