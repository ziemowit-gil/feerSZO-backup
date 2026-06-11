<?php
/**
 * first_login_consent.php — Blokujący onboarding z akceptacją zgody.
 *
 * Strona STANDALONE — nie includuje header.php, by uniknąć pętli przekierowań
 * przy sprawdzaniu consent_check(). Własna minimalna struktura HTML z Bootstrap CDN.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cpc.php';
require_once dirname(__DIR__) . '/includes/approval.php';

// ── Weryfikacja sesji ─────────────────────────────────────────────────────────
auth_start();
$user = current_user();
if (!$user) {
    header('Location: ' . APP_URL . '/auth/login.php');
    exit;
}

// ── Migracja kolumn ───────────────────────────────────────────────────────────
cpc_migrate();

// ── Wczytanie tekstów ze settings ────────────────────────────────────────────
$info_text    = org_setting('onboarding_info_text');
$consent_text = consent_get_text();

// ── Obsługa POST ──────────────────────────────────────────────────────────────
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'accept') {
    csrf_check();

    // Walidacja checkboxa zgody
    if (empty($_POST['consent_accept'])) {
        $error = 'Musisz zaakceptować zgodę na dokumentową formę umów, aby kontynuować.';
    } else {
        $uid = (int) $user['id'];

        // Zapis alternatywnych kanałów powiadomień (opcjonalne)
        $alt_email  = trim($_POST['alt_email']        ?? '');
        $alt_phone  = trim($_POST['alt_phone_number'] ?? '');

        $set_clauses = [];
        $set_params  = [];

        if ($alt_email !== '') {
            if (!filter_var($alt_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Podany alternatywny adres e-mail jest nieprawidłowy.';
            } else {
                $set_clauses[] = 'alt_email = ?';
                $set_params[]  = $alt_email;
            }
        }

        if ($error === '' && $alt_phone !== '') {
            $normalized = sms_normalize_phone($alt_phone);
            if (strlen($normalized) < 9) {
                $error = 'Podany alternatywny numer telefonu jest nieprawidłowy.';
            } else {
                $set_clauses[] = 'alt_phone_number = ?';
                $set_params[]  = $normalized;
            }
        }

        if ($error === '' && !empty($set_clauses)) {
            $set_params[] = $uid;
            db()->prepare('UPDATE users SET ' . implode(', ', $set_clauses) . ' WHERE id = ?')
                 ->execute($set_params);
        }

        if ($error === '') {
            // Zapis zgody
            consent_save($uid, $_SERVER['REMOTE_ADDR'] ?? '');

            // Log zdarzenia
            log_system_action(
                $uid,
                'consent_accepted',
                'Użytkownik zaakceptował regulamin panelu (hash: ' . consent_current_hash() . ')'
            );

            // Zaktualizuj sesję
            auth_start();
            $_SESSION['user']['document_form_consent'] = 1;

            header('Location: ' . APP_URL . '/panel/index.php');
            exit;
        }
    }
}

// ── Pomocnicze zmienne dla szablonu ──────────────────────────────────────────
$org_name = defined('ORG_NAME') ? ORG_NAME : '';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Akceptacja regulaminu — <?= h($org_name) ?></title>
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <style>
    body {
      background: #f0f4f8;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .consent-wrapper {
      max-width: 640px;
      width: 100%;
    }
    .info-box {
      background: #e8f0fe;
      border-left: 4px solid #0d6efd;
      border-radius: 0 .375rem .375rem 0;
      padding: 1rem 1.25rem;
      font-size: .9rem;
      line-height: 1.6;
      white-space: pre-wrap;
    }
    .consent-box {
      border: 1.5px solid #dee2e6;
      border-radius: .5rem;
      padding: 1rem 1.25rem;
      background: #fff;
    }
    .toggle-alt-link {
      font-size: .875rem;
      cursor: pointer;
      text-decoration: none;
    }
  </style>
</head>
<body>
<div class="consent-wrapper">

  <!-- Nagłówek -->
  <div class="text-center mb-4">
    <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary bg-opacity-10 mb-3"
         style="width:64px;height:64px">
      <i class="bi bi-envelope-check-fill text-primary fs-2"></i>
    </div>
    <h4 class="fw-bold mb-1">Witaj w panelu <?= h($org_name) ?></h4>
    <p class="text-muted small mb-2">
      To Twoje miejsce do <strong>komunikacji z organizacją</strong>
      i&nbsp;obsługi <strong>formalności</strong> — umów, dokumentów i&nbsp;powiadomień.
    </p>
    <p class="text-muted small mb-0">Zanim przejdziesz dalej, potwierdź poniższe.</p>
  </div>

  <?php if ($error !== ''): ?>
  <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0 mt-1"></i>
    <div><?= h($error) ?></div>
  </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="accept">

    <!-- Pouczenie -->
    <?php if ($info_text !== ''): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi bi-info-circle-fill text-primary"></i>
          <span class="fw-semibold small text-uppercase text-muted" style="letter-spacing:.04em">Pouczenie</span>
        </div>
        <div class="info-box"><?= h($info_text) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Zgoda -->
    <div class="card shadow-sm mb-3">
      <div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-file-earmark-check-fill text-success"></i>
          <span class="fw-semibold small text-uppercase text-muted" style="letter-spacing:.04em">Regulamin korzystania z panelu</span>
        </div>
        <div class="consent-box mb-3">
          <p class="mb-0 small" style="line-height:1.6"><?= h($consent_text) ?></p>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox"
                 name="consent_accept" id="consent_accept"
                 value="1" required
                 <?= !empty($_POST['consent_accept']) ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="consent_accept">
            Zapoznałem/am się z regulaminem i akceptuję warunki korzystania z panelu.
          </label>
        </div>
      </div>
    </div>

    <!-- Alternatywne kanały powiadomień (opcjonalne, zwijane) -->
    <div class="card shadow-sm mb-4">
      <div class="card-body p-3">
        <a class="toggle-alt-link d-flex align-items-center gap-2 text-secondary"
           data-bs-toggle="collapse" href="#altChannelsCollapse"
           role="button" aria-expanded="false" aria-controls="altChannelsCollapse">
          <i class="bi bi-chevron-right" id="altChevron" style="transition:transform .2s"></i>
          <span>Zdefiniuj alternatywne kanały powiadomień <span class="text-muted fw-normal">(opcjonalne)</span></span>
        </a>

        <div class="collapse mt-3" id="altChannelsCollapse">
          <p class="text-muted small mb-3">
            Możesz podać dodatkowy adres e-mail i/lub numer telefonu, na które będą
            kierowane powiadomienia systemowe zamiast Twoich danych podstawowych.
          </p>
          <div class="mb-3">
            <label for="alt_email" class="form-label fw-semibold small">Alternatywny adres e-mail</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-envelope"></i></span>
              <input type="email" class="form-control" id="alt_email" name="alt_email"
                     placeholder="np. prywatny@domena.pl"
                     value="<?= h($_POST['alt_email'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-1">
            <label for="alt_phone_number" class="form-label fw-semibold small">Alternatywny numer telefonu</label>
            <div class="input-group input-group-sm">
              <span class="input-group-text"><i class="bi bi-phone"></i></span>
              <input type="tel" class="form-control" id="alt_phone_number" name="alt_phone_number"
                     placeholder="np. 600 100 200"
                     value="<?= h($_POST['alt_phone_number'] ?? '') ?>">
            </div>
            <div class="form-text">Format: 9 cyfr (PL) lub z prefiksem +48.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Przycisk zatwierdzenia -->
    <div class="d-grid">
      <button type="submit" class="btn btn-primary btn-lg">
        <i class="bi bi-check-circle me-2"></i>Akceptuję — przejdź do panelu
      </button>
    </div>

  </form>

  <p class="text-center text-muted small mt-3">
    <i class="bi bi-lock me-1"></i>
    Twoje dane są przetwarzane zgodnie z polityką prywatności <?= h($org_name) ?>.
  </p>

</div><!-- /.consent-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmY+ASSbXX+/Y0DmfhVpJkJEYJA3"
        crossorigin="anonymous"></script>
<script>
  // Obróć chevron przy rozwinięciu
  var el = document.getElementById('altChannelsCollapse');
  if (el) {
    el.addEventListener('show.bs.collapse', function () {
      document.getElementById('altChevron').style.transform = 'rotate(90deg)';
    });
    el.addEventListener('hide.bs.collapse', function () {
      document.getElementById('altChevron').style.transform = 'rotate(0deg)';
    });
  }
</script>
</body>
</html>
