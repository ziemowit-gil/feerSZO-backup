<?php
/**
 * panel/terms_accept.php — Ekran akceptacji regulaminu panelu.
 * Wyświetlany automatycznie gdy użytkownik nie zaakceptował aktualnej wersji.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/panel_terms.php';

require_login();
$user = current_user();
panel_terms_migrate();

$term = panel_term_get();

// Jeśli brak aktywnego regulaminu — wróć do panelu
if (!$term || empty($term['is_active'])) {
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}

// Jeśli już zaakceptowany — wróć do panelu (lub żądanego URL)
if (panel_term_accepted((int)$user['id'])) {
    $back = filter_var($_GET['back'] ?? '', FILTER_SANITIZE_URL);
    $back = (str_starts_with($back, APP_URL) || str_starts_with($back, '/')) ? $back : APP_URL . '/panel/index.php';
    header('Location: ' . $back); exit;
}

// Obsługa POST — akceptacja
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['accept']) && (int)$_POST['term_id'] === (int)$term['id']) {
        panel_term_accept((int)$user['id'], (int)$term['id']);
        $back = filter_var($_POST['back'] ?? '', FILTER_SANITIZE_URL);
        $back = (str_starts_with($back, APP_URL) || str_starts_with($back, '/')) ? $back : APP_URL . '/panel/index.php';
        header('Location: ' . $back); exit;
    }
}

$back_url = filter_var($_GET['back'] ?? '', FILTER_SANITIZE_URL);
$back_url = (str_starts_with($back_url, APP_URL) || str_starts_with($back_url, '/')) ? $back_url : APP_URL . '/panel/index.php';

$PAGE_TITLE = 'Regulamin panelu';
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($PAGE_TITLE) ?> — <?= h(defined('ORG_NAME') ? ORG_NAME : 'System') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background: #f1f5f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .terms-card { max-width: 680px; width: 100%; }
  .terms-body { max-height: 400px; overflow-y: auto; }
</style>
</head>
<body>
<div class="terms-card mx-auto p-3">
  <div class="card shadow-lg border-0">
    <div class="card-header bg-primary text-white d-flex align-items-center gap-2 py-3">
      <i class="bi bi-file-earmark-text fs-4" aria-hidden="true"></i>
      <div>
        <div class="fw-bold fs-5"><?= h($term['title']) ?></div>
        <div class="small opacity-75"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?> · wersja <?= (int)$term['version'] ?></div>
      </div>
    </div>
    <div class="card-body">
      <p class="text-body-secondary mb-3">
        Aby korzystać z panelu, zapoznaj się z treścią regulaminu i potwierdź jego akceptację.
      </p>

      <div class="terms-body border rounded p-3 mb-4 small bg-light"
           tabindex="0" aria-label="Treść regulaminu">
        <?= $term['body_html'] ?>
      </div>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
        <input type="hidden" name="back" value="<?= h($back_url) ?>">

        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" id="cb-accept" name="accept" value="1" required>
          <label class="form-check-label" for="cb-accept">
            Przeczytałem/am powyższy regulamin i akceptuję jego treść.
          </label>
        </div>

        <div class="d-flex justify-content-between align-items-center">
          <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zaakceptuj i przejdź do panelu
          </button>
          <a href="<?= APP_URL ?>/auth/logout.php" class="btn btn-link text-body-secondary small">
            Wyloguj
          </a>
        </div>
      </form>
    </div>
  </div>
</div>
</body>
</html>
