<?php
/**
 * asysta/status.php — Publiczny podgląd statusu zgłoszenia asysty.
 *
 * Dostępny BEZ logowania. Uczestnik wpisuje numer zgłoszenia (ZA/XXXX/RRRR)
 * i widzi aktualny status oraz datę ostatniej zmiany.
 * Nie ujawnia danych osobowych ani notatek wewnętrznych.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/assistance.php';

asr_migrate();
auth_start();

$STATUS_URL = APP_URL . '/asysta/status.php';

$found  = null;
$no_raw = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $no_raw = trim((string)($_POST['nr'] ?? ''));

    // Wyciągnij ID z formatu ZA/0001/2026 lub akceptuj samo ID numeryczne.
    $req_id = 0;
    if (preg_match('/^ZA\/(\d+)\/\d{4}$/i', $no_raw, $m)) {
        $req_id = (int)$m[1];
    } elseif (ctype_digit($no_raw)) {
        $req_id = (int)$no_raw;
    }

    if ($req_id > 0) {
        $rec = asr_get($req_id);
        // Weryfikacja: wygenerowany numer musi pasować do wpisanego (ochrona przed enumeration).
        if ($rec && (strcasecmp(asr_number($rec), $no_raw) === 0 || ctype_digit($no_raw))) {
            $found = $rec;
        }
    }
    if (!$found) {
        $error = 'Nie znaleziono zgłoszenia o podanym numerze. Sprawdź pisownię i spróbuj ponownie.';
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Sprawdź status zgłoszenia asysty — <?= h(defined('ORG_NAME') ? ORG_NAME : 'FEER') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#f1f5f9; }
  .status-card { max-width:560px; }
  a:focus-visible, button:focus-visible, input:focus-visible {
    outline:3px solid #1d4ed8; outline-offset:2px; box-shadow:none;
  }
  .skip-link {
    position:absolute; left:-999px; top:0; background:#1d4ed8; color:#fff;
    padding:.5rem .9rem; border-radius:0 0 .4rem 0; z-index:1000;
  }
  .skip-link:focus { left:0; }
</style>
</head>
<body>
<a href="#main" class="skip-link">Przejdź do treści</a>
<div class="container py-4 py-md-5">
  <div class="status-card mx-auto">

    <header class="text-center mb-4">
      <i class="bi bi-universal-access-circle text-primary" style="font-size:2.4rem" aria-hidden="true"></i>
      <h1 class="h4 fw-bold mt-2 mb-1">Sprawdź status zgłoszenia asysty</h1>
      <p class="text-secondary mb-0"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?></p>
    </header>

    <main id="main">

      <?php if ($found): ?>

        <?php
        $statuses  = asr_statuses();
        $no        = asr_number($found);
        $status_lbl = asr_label($statuses, $found['status']);
        $status_cls = asr_status_class($found['status']);
        $updated   = $found['updated_at'] ?? $found['created_at'];
        ?>
        <div class="card border-0 shadow-sm mb-3" role="region" aria-label="Wynik wyszukiwania">
          <div class="card-body p-4">
            <div class="row g-3 mb-3">
              <div class="col-6">
                <div class="text-secondary small text-uppercase">Numer zgłoszenia</div>
                <div class="fw-semibold"><?= h($no) ?></div>
              </div>
              <div class="col-6">
                <div class="text-secondary small text-uppercase">Data zgłoszenia</div>
                <div class="fw-semibold"><?= h(substr((string)$found['created_at'], 0, 10)) ?></div>
              </div>
            </div>
            <div class="mb-3">
              <div class="text-secondary small text-uppercase mb-1">Aktualny status</div>
              <span class="badge fs-6 <?= h($status_cls) ?>"><?= h($status_lbl) ?></span>
            </div>
            <?php if (!empty($found['event_name'])): ?>
            <div class="mb-1">
              <div class="text-secondary small text-uppercase">Wydarzenie</div>
              <div><?= h($found['event_name']) ?><?= !empty($found['event_when_where']) ? ' — ' . h($found['event_when_where']) : '' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($updated): ?>
            <div class="mt-3 text-secondary small">
              Ostatnia aktualizacja: <?= h(substr((string)$updated, 0, 16)) ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="text-center">
          <a href="<?= h($STATUS_URL) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-search me-1" aria-hidden="true"></i>Sprawdź inne zgłoszenie
          </a>
        </div>

      <?php else: ?>

        <?php if ($error): ?>
          <div class="alert alert-warning" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
            <?= h($error) ?>
          </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <p class="text-secondary mb-3">
              Podaj numer zgłoszenia (np. <code>ZA/0001/2026</code>), który otrzymałeś/aś
              po wysłaniu formularza asysty.
            </p>
            <form method="post" action="<?= h($STATUS_URL) ?>" novalidate>
              <?= csrf_field() ?>
              <div class="mb-3">
                <label for="nr" class="form-label fw-semibold">Numer zgłoszenia</label>
                <input type="text" class="form-control" id="nr" name="nr"
                       placeholder="ZA/0001/2026"
                       value="<?= h($no_raw) ?>"
                       autocomplete="off" required
                       aria-describedby="nr-help">
                <div id="nr-help" class="form-text">Format: ZA/XXXX/RRRR</div>
              </div>
              <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-search me-1" aria-hidden="true"></i>Sprawdź status
              </button>
            </form>
          </div>
        </div>

      <?php endif; ?>

    </main>

    <p class="text-center text-secondary small mt-4 mb-0">
      &copy; <?= date('Y') ?> <?= h(defined('ORG_NAME') ? ORG_NAME : '') ?>
    </p>
  </div>
</div>
</body>
</html>
