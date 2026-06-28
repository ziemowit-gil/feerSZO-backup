<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/onboarding_schema.php';
auth_start();

$token = trim($_GET['token'] ?? '');
$ob_id = intval($_GET['id'] ?? 0);

// ── Helper: render standalone page ───────────────────────────────────────────
function render_page(string $type, string $title, string $body_html): void {
    $asset_base = APP_URL . '/assets';
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> — <?= defined('ORG_NAME') ? h(ORG_NAME) : 'Portal wspolpracownika' ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:520px">
  <?= $body_html ?>
</div>
</body>
</html>
<?php
    exit;
}

// ── Validate parameters ───────────────────────────────────────────────────────
if ($token === '' || $ob_id <= 0) {
    render_page('error', 'Nieprawidłowy link', <<<HTML
<div class="card shadow-sm">
  <div class="card-body text-center py-5">
    <i class="bi bi-x-circle-fill fs-1 text-danger mb-3 d-block"></i>
    <h4 class="mb-2">Nieprawidłowy link</h4>
    <p class="text-muted mb-0">Link weryfikacyjny jest niekompletny lub nieprawidłowy.</p>
  </div>
</div>
HTML);
}

// ── Look up the volunteer record ──────────────────────────────────────────────
$vol = db_one(
    "SELECT * FROM onboarding_volunteers WHERE id = ? AND email_token = ?",
    [$ob_id, $token]
);

if (!$vol) {
    render_page('error', 'Nieprawidłowy link', <<<HTML
<div class="card shadow-sm">
  <div class="card-body text-center py-5">
    <i class="bi bi-x-circle-fill fs-1 text-danger mb-3 d-block"></i>
    <h4 class="mb-2">Link nieprawidłowy lub wygasł.</h4>
    <p class="text-muted mb-0">Ten link weryfikacyjny nie istnieje lub już nie jest aktywny.</p>
  </div>
</div>
HTML);
}

// ── Check token expiry ────────────────────────────────────────────────────────
if (!empty($vol['email_token_expires']) && strtotime($vol['email_token_expires']) < time()) {
    render_page('error', 'Link wygasł', <<<HTML
<div class="card shadow-sm">
  <div class="card-body text-center py-5">
    <i class="bi bi-clock-history fs-1 text-warning mb-3 d-block"></i>
    <h4 class="mb-2">Link wygasł.</h4>
    <p class="text-muted mb-0">Ten link weryfikacyjny utracił ważność. Wróć do formularza i poproś o wysłanie nowego e-maila.</p>
  </div>
</div>
HTML);
}

// ── Mark e-mail as verified ───────────────────────────────────────────────────
$stmt = db()->prepare(
    "UPDATE onboarding_volunteers
     SET email_verified = 1, email_token = NULL, email_token_expires = NULL,
         updated_at = datetime('now')
     WHERE id = ?"
);
$stmt->execute([$ob_id]);

$_SESSION['ob_email_verified'] = true;

// ── Success page ──────────────────────────────────────────────────────────────
$wizard_url = h(APP_URL . '/onboarding/index.php?step=4');

$success_html = <<<HTML
<div class="card shadow-sm">
  <div class="card-body text-center py-5">
    <i class="bi bi-check-circle-fill fs-1 text-success mb-3 d-block"></i>
    <h4 class="mb-2">E-mail zweryfikowany!</h4>
    <p class="text-muted mb-4">
      Twój adres e-mail został potwierdzony. Możesz teraz wrócić do formularza
      i kontynuować wypełnianie formularza zgłoszeniowego.
    </p>
    <a href="{$wizard_url}" class="btn btn-success btn-lg px-4">
      <i class="bi bi-arrow-left-circle me-2"></i>Wróć do formularza
    </a>
  </div>
</div>
HTML;

render_page('success', 'E-mail zweryfikowany', $success_html);
