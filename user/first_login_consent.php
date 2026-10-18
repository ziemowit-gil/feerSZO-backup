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
    /* METRO jak System Tożsamości: ciemny app-bar, Segoe UI, lekka duża typografia,
       płaskie szare grupy, zero zaokrągleń i cieni. Formularz i logika bez zmian. */
    :root{--m-acc:#0f6fa8;--m-acc-d:#0b5683;--m-green:#007a00;--m-red:#a20025;--m-dark:#1d1d1d;
          --m-light:#f2f2f2;--m-line:#e1e1e1;--m-ink:#111827;--m-muted:#5f5f5f}
    html,body{margin:0;min-height:100vh;background:#fff;color:var(--m-ink);-webkit-font-smoothing:antialiased;
      font-family:"Segoe UI","Segoe UI Web (West European)",system-ui,-apple-system,Roboto,"Helvetica Neue",Arial,sans-serif}
    *:focus-visible{outline:3px solid #fbbf24!important;outline-offset:2px}
    .fl-skip{position:absolute;left:-999px}.fl-skip:focus{left:1rem;top:1rem;z-index:1080;background:#fff;padding:.5rem 1rem;font-weight:600}
    .flbar{background:var(--m-dark);color:#fff}
    .flbar .inner{max-width:760px;margin:0 auto;padding:.45rem 1rem;display:flex;align-items:center;gap:.6rem}
    .flbar .mark{width:34px;height:34px;background:var(--m-acc);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
    .flbar b{font-weight:700;font-size:.95rem;line-height:1.05}
    .flbar small{display:block;font-size:.65rem;color:rgba(255,255,255,.65);font-weight:500}
    .consent-wrapper{max-width:760px;margin:0 auto;padding:2.25rem 1rem 3rem}
    .fl-kicker{font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.18em;color:var(--m-muted);margin-bottom:.35rem}
    .fl-title{margin:0;font-size:clamp(1.9rem,4vw,2.5rem);font-weight:300;line-height:1.05;letter-spacing:-.02em}
    .fl-lead{margin:.6rem 0 0;font-size:.95rem;font-weight:300;color:var(--m-muted);max-width:70ch}
    .fl-h2{margin:0 0 .75rem;font-size:1.35rem;font-weight:300;letter-spacing:-.01em;display:flex;align-items:center;gap:.6rem}
    .fl-h2 b{font-weight:600}
    .fl-h2 .ico{width:34px;height:34px;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
    .fl-sec{margin-top:2rem}
    .fl-group{background:var(--m-light);padding:1.25rem}
    .info-box{background:#fff;border-left:6px solid var(--m-acc);padding:1rem 1.25rem;font-size:.92rem;line-height:1.6;white-space:pre-wrap}
    .consent-box{background:#fff;padding:1rem 1.25rem;font-size:.92rem;line-height:1.6;margin-bottom:1rem}
    .form-check{display:flex;align-items:flex-start;gap:.75rem;padding-left:0}
    .form-check-input{float:none;margin:.15rem 0 0;width:1.4rem;height:1.4rem;border:2px solid var(--m-ink);border-radius:0!important;flex-shrink:0;cursor:pointer}
    .form-check-input:checked{background-color:var(--m-green);border-color:var(--m-green)}
    .form-check-input:focus{box-shadow:none}
    .form-check-label{font-weight:600;cursor:pointer}
    .toggle-alt-link{display:flex;align-items:center;gap:.75rem;color:var(--m-ink);text-decoration:none;font-weight:600;cursor:pointer}
    .toggle-alt-link:hover{color:var(--m-ink)}
    .toggle-alt-link .box{width:1.6rem;height:1.6rem;border:2px solid var(--m-ink);display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0}
    .toggle-alt-link[aria-expanded="true"] .box{background:var(--m-ink);color:#fff}
    .toggle-alt-link .opt{color:var(--m-muted);font-weight:400}
    .form-label{font-size:.8rem;font-weight:600;margin-bottom:.25rem}
    .form-control,.input-group-text{border-radius:0!important;border:2px solid #8a8a8a}
    .input-group-text{background:#fff;border-right:0}
    .form-control:focus{border-color:var(--m-acc);box-shadow:none}
    .form-text{font-size:.78rem;color:var(--m-muted)}
    .fl-alert{background:var(--m-red);color:#fff;padding:.9rem 1.1rem;margin-top:1.5rem;display:flex;gap:.6rem;align-items:flex-start;font-size:.92rem}
    .fl-submit{margin-top:2rem;width:100%;background:var(--m-acc);color:#fff;border:2px solid var(--m-acc);border-radius:0;
      padding:1rem 1.25rem;font:inherit;font-size:1.15rem;font-weight:600;display:flex;align-items:center;justify-content:center;gap:.6rem;cursor:pointer}
    .fl-submit:hover{background:var(--m-dark);border-color:var(--m-dark)}
    .fl-foot{margin-top:1.25rem;font-size:.82rem;color:var(--m-muted);display:flex;gap:.4rem;align-items:flex-start}
  </style>
</head>
<body>
<a href="#fl-main" class="fl-skip">Przejdź do treści</a>
<header class="flbar">
  <div class="inner">
    <span class="mark" aria-hidden="true"><i class="bi bi-person-vcard-fill"></i></span>
    <span><b><?= h($org_name) ?></b><small>Pierwsze logowanie</small></span>
  </div>
</header>

<main id="fl-main" class="consent-wrapper">

  <!-- Nagłówek -->
  <div class="fl-kicker">Panel współpracownika · witaj</div>
  <h1 class="fl-title">Witaj w panelu <?= h($org_name) ?></h1>
  <p class="fl-lead">
    To Twoje miejsce do <strong>komunikacji z organizacją</strong>
    i&nbsp;obsługi <strong>formalności</strong> — umów, dokumentów i&nbsp;powiadomień.
    Zanim przejdziesz dalej, potwierdź poniższe.
  </p>

  <?php if ($error !== ''): ?>
  <div class="fl-alert" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0" aria-hidden="true"></i>
    <div><?= h($error) ?></div>
  </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_action" value="accept">

    <!-- Pouczenie -->
    <?php if ($info_text !== ''): ?>
    <section class="fl-sec" aria-labelledby="fl-info-h">
      <h2 class="fl-h2" id="fl-info-h"><span class="ico" style="background:var(--m-acc)" aria-hidden="true"><i class="bi bi-info-lg"></i></span><b>Pouczenie</b></h2>
      <div class="fl-group">
        <div class="info-box"><?= h($info_text) ?></div>
      </div>
    </section>
    <?php endif; ?>

    <!-- Zgoda -->
    <section class="fl-sec" aria-labelledby="fl-consent-h">
      <h2 class="fl-h2" id="fl-consent-h"><span class="ico" style="background:var(--m-green)" aria-hidden="true"><i class="bi bi-file-earmark-check"></i></span>Regulamin <b>korzystania z panelu</b></h2>
      <div class="fl-group">
        <div class="consent-box">
          <p class="mb-0"><?= h($consent_text) ?></p>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox"
                 name="consent_accept" id="consent_accept"
                 value="1" required
                 <?= !empty($_POST['consent_accept']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="consent_accept">
            Zapoznałem/am się z regulaminem i akceptuję warunki korzystania z panelu.
          </label>
        </div>
      </div>
    </section>

    <!-- Alternatywne kanały powiadomień (opcjonalne, zwijane) -->
    <section class="fl-sec">
      <div class="fl-group">
        <a class="toggle-alt-link"
           data-bs-toggle="collapse" href="#altChannelsCollapse"
           role="button" aria-expanded="false" aria-controls="altChannelsCollapse">
          <span class="box" aria-hidden="true"><i class="bi bi-chevron-right" id="altChevron" style="transition:transform .2s"></i></span>
          <span>Zdefiniuj alternatywne kanały powiadomień <span class="opt">(opcjonalne)</span></span>
        </a>

        <div class="collapse mt-3" id="altChannelsCollapse">
          <p class="small mb-3" style="color:var(--m-muted)">
            Możesz podać dodatkowy adres e-mail i/lub numer telefonu, na które będą
            kierowane powiadomienia systemowe zamiast Twoich danych podstawowych.
          </p>
          <div class="mb-3">
            <label for="alt_email" class="form-label">Alternatywny adres e-mail</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-envelope" aria-hidden="true"></i></span>
              <input type="email" class="form-control" id="alt_email" name="alt_email"
                     placeholder="np. prywatny@domena.pl"
                     value="<?= h($_POST['alt_email'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-1">
            <label for="alt_phone_number" class="form-label">Alternatywny numer telefonu</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-phone" aria-hidden="true"></i></span>
              <input type="tel" class="form-control" id="alt_phone_number" name="alt_phone_number"
                     placeholder="np. 600 100 200"
                     value="<?= h($_POST['alt_phone_number'] ?? '') ?>">
            </div>
            <div class="form-text">Format: 9 cyfr (PL) lub z prefiksem +48.</div>
          </div>
        </div>
      </div>
    </section>

    <!-- Przycisk zatwierdzenia -->
    <button type="submit" class="fl-submit">
      <i class="bi bi-check2-square" aria-hidden="true"></i>Akceptuję — przejdź do panelu
    </button>

  </form>

  <p class="fl-foot">
    <i class="bi bi-lock" aria-hidden="true"></i>
    <span>Twoje dane są przetwarzane zgodnie z polityką prywatności <?= h($org_name) ?>.</span>
  </p>

</main><!-- /.consent-wrapper -->

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
