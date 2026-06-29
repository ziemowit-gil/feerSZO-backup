<?php
/**
 * extforms/wolontariuszPotrzeby/index.php
 * Publiczny formularz zgłoszenia specjalnych potrzeb wolontariusza.
 *
 * Dostępny BEZ logowania. Wolontariusz zgłasza potrzeby dostępności
 * związane ze swoim wolontariatem (nie z konkretnym wydarzeniem).
 * Dane trafiają do tabeli szo_assistance_requests — obsługa przez
 * panel asysta/admin.php. Rekord ma event_name = "Wolontariat"
 * a is_guardian = 0.
 *
 * Zabezpieczenia: honeypot + token czasu (anty-bot).
 * Dostępność: WCAG 2.1 AA (label/id, fieldset/legend, aria-describedby,
 * skip-link, focus ring, kontrast).
 */
$_root = dirname(__DIR__, 2);
require_once $_root . '/config.php';
require_once $_root . '/includes/db.php';
require_once $_root . '/includes/functions.php';
require_once $_root . '/includes/auth.php';
require_once $_root . '/includes/assistance.php';

asr_migrate();
auth_start();

$FORM_URL = APP_URL . '/extforms/wolontariuszPotrzeby/';

$errors = [];
$old = [
    'participant_name'  => '',
    'participant_email' => '',
    'participant_phone' => '',
    'vol_context'       => '',   // zakres / obszar wolontariatu
    'needs'             => [],
    'needs_other'       => '',
    'details'           => '',
];
$saved_no = null;

/* ── Token czasu (anty-bot) ───────────────────────────────────────────────── */
if (empty($_SESSION['asrv_form_ts'])) {
    $_SESSION['asrv_form_ts'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $is_spam = trim((string)($_POST['website'] ?? '')) !== '';
    $started = (int)($_SESSION['asrv_form_ts'] ?? 0);
    if ($started && (time() - $started) < 2) $is_spam = true;

    if ($is_spam) {
        $saved_no = ''; // cicha symulacja sukcesu
    } else {
        // Przygotuj tablicę do walidatora: mapuj pola formularza na oczekiwane klucze.
        $post_mapped = $_POST;
        $post_mapped['participant_name']  = trim((string)($_POST['participant_name'] ?? ''));
        $post_mapped['participant_email'] = trim((string)($_POST['participant_email'] ?? ''));
        $post_mapped['participant_phone'] = trim((string)($_POST['participant_phone'] ?? ''));
        $post_mapped['is_guardian']       = 'no';
        $post_mapped['event_name']        = 'Wolontariat';
        $vol_context                       = mb_substr(trim((string)($_POST['vol_context'] ?? '')), 0, 255);
        $post_mapped['event_when_where']  = $vol_context;
        $post_mapped['needs']             = (array)($_POST['needs'] ?? []);
        $post_mapped['needs_other']       = trim((string)($_POST['needs_other'] ?? ''));
        $post_mapped['details']           = trim((string)($_POST['details'] ?? ''));

        [$clean, $errors] = asr_validate($post_mapped);

        // Zachowaj wpisane wartości na wypadek błędów.
        $old['participant_name']  = $post_mapped['participant_name'];
        $old['participant_email'] = $post_mapped['participant_email'];
        $old['participant_phone'] = $post_mapped['participant_phone'];
        $old['vol_context']       = $vol_context;
        $old['needs']             = $post_mapped['needs'];
        $old['needs_other']       = $post_mapped['needs_other'];
        $old['details']           = $post_mapped['details'];

        if (!$errors) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $id = asr_create($clean, current_user()['id'] ?? null, $ip);
            $rec = asr_get($id);
            $saved_no = $rec ? asr_number($rec) : '';
            $_SESSION['asrv_last_no'] = $saved_no;
            header('Location: ' . $FORM_URL . '?saved=1');
            exit;
        }
    }
}

if ($saved_no === null && isset($_GET['saved'])) {
    $saved_no = (string)($_SESSION['asrv_last_no'] ?? '');
    unset($_SESSION['asrv_last_no'], $_SESSION['asrv_form_ts']);
}

$needs_map = asr_needs();
$err     = fn($k) => isset($errors[$k]);
$checked = fn($k) => in_array($k, (array)$old['needs'], true);
$org = defined('ORG_NAME') ? ORG_NAME : 'FEER';
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Zgłoszenie specjalnych potrzeb wolontariusza — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#f1f5f9; }
  .vol-card { max-width:780px; }
  a:focus-visible, button:focus-visible, input:focus-visible,
  select:focus-visible, textarea:focus-visible {
    outline:3px solid #1d4ed8; outline-offset:2px; box-shadow:none;
  }
  .form-label { font-weight:600; }
  .req::after { content:" *"; color:#dc2626; font-weight:700; }
  .skip-link {
    position:absolute; left:-999px; top:0; background:#1d4ed8; color:#fff;
    padding:.5rem .9rem; border-radius:0 0 .4rem 0; z-index:1000;
  }
  .skip-link:focus { left:0; }
  fieldset { border:1px solid #e2e8f0; border-radius:.6rem; padding:1rem 1.1rem; }
  legend { font-size:1rem; font-weight:700; width:auto; padding:0 .4rem; }
  .hp-field { position:absolute; left:-5000px; height:0; overflow:hidden; }
</style>
</head>
<body>
<a href="#main" class="skip-link">Przejdź do formularza</a>

<div class="container py-4 py-md-5">
<div class="vol-card mx-auto">

  <header class="text-center mb-4">
    <i class="bi bi-person-heart text-primary" style="font-size:2.6rem" aria-hidden="true"></i>
    <h1 class="h3 fw-bold mt-2 mb-1">Zgłoszenie specjalnych potrzeb wolontariusza</h1>
    <p class="text-secondary mb-0"><?= h($org) ?></p>
  </header>

  <?php if ($saved_no !== null): ?>

    <div class="card border-success shadow-sm" role="status">
      <div class="card-body text-center p-4 p-md-5">
        <i class="bi bi-check-circle-fill text-success" style="font-size:3rem" aria-hidden="true"></i>
        <h2 class="h4 fw-bold mt-3">Dziękujemy — zgłoszenie zostało przyjęte</h2>
        <?php if ($saved_no !== ''): ?>
          <p class="text-secondary mb-1">Twoje potrzeby zostały przekazane do koordynatora dostępności.</p>
          <p class="mb-4">Numer zgłoszenia: <strong class="fs-5"><?= h($saved_no) ?></strong></p>
          <p class="text-secondary small mb-4">
            Zapisz ten numer — ułatwi kontakt w sprawie zgłoszenia.
            Skontaktujemy się z Tobą na podany adres e-mail lub telefon.
          </p>
        <?php else: ?>
          <p class="text-secondary mb-4">Twoje zgłoszenie zostało odnotowane.</p>
        <?php endif; ?>
        <div class="d-flex flex-wrap justify-content-center gap-2">
          <a href="<?= h(APP_URL) ?>/asysta/status.php" class="btn btn-outline-primary">
            <i class="bi bi-search me-1" aria-hidden="true"></i>Sprawdź status zgłoszenia
          </a>
          <a href="<?= h($FORM_URL) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Wyślij kolejne zgłoszenie
          </a>
        </div>
      </div>
    </div>

  <?php else: ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
        Formularz zawiera błędy. Sprawdź zaznaczone pola poniżej.
      </div>
    <?php endif; ?>

    <main id="main">
    <form method="post" action="<?= h($FORM_URL) ?>" novalidate
          class="card shadow-sm" aria-describedby="vol-intro">
      <div class="card-body p-4">
        <p id="vol-intro" class="text-secondary small mb-4">
          Pola oznaczone gwiazdką (<span class="text-danger">*</span>) są wymagane.
          Formularz służy do zgłoszenia potrzeb dostępności związanych
          z Twoim wolontariatem — abyśmy mogli zapewnić Ci odpowiednie wsparcie.
        </p>

        <?= csrf_field() ?>

        <div class="hp-field" aria-hidden="true">
          <label for="website">Nie wypełniaj tego pola</label>
          <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <!-- ════ Dane wolontariusza ════ -->
        <fieldset class="mb-4">
          <legend>Dane wolontariusza</legend>

          <div class="mb-3">
            <label for="participant_name" class="form-label req">Imię i nazwisko</label>
            <input type="text" class="form-control <?= $err('participant_name') ? 'is-invalid' : '' ?>"
                   id="participant_name" name="participant_name" maxlength="255"
                   autocomplete="name" required value="<?= h($old['participant_name']) ?>"
                   <?= $err('participant_name') ? 'aria-describedby="e-name"' : '' ?>>
            <?php if ($err('participant_name')): ?>
              <div id="e-name" class="invalid-feedback"><?= h($errors['participant_name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="row g-3">
            <div class="col-sm-6">
              <label for="participant_email" class="form-label req">E-mail</label>
              <input type="email" class="form-control <?= $err('participant_email') ? 'is-invalid' : '' ?>"
                     id="participant_email" name="participant_email" maxlength="255"
                     autocomplete="email" required value="<?= h($old['participant_email']) ?>"
                     <?= $err('participant_email') ? 'aria-describedby="e-email"' : '' ?>>
              <?php if ($err('participant_email')): ?>
                <div id="e-email" class="invalid-feedback"><?= h($errors['participant_email']) ?></div>
              <?php endif; ?>
            </div>
            <div class="col-sm-6">
              <label for="participant_phone" class="form-label req">Telefon</label>
              <input type="tel" class="form-control <?= $err('participant_phone') ? 'is-invalid' : '' ?>"
                     id="participant_phone" name="participant_phone" maxlength="64"
                     autocomplete="tel" required value="<?= h($old['participant_phone']) ?>"
                     <?= $err('participant_phone') ? 'aria-describedby="e-phone"' : '' ?>>
              <?php if ($err('participant_phone')): ?>
                <div id="e-phone" class="invalid-feedback"><?= h($errors['participant_phone']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </fieldset>

        <!-- ════ Kontekst wolontariatu ════ -->
        <fieldset class="mb-4">
          <legend>Kontekst wolontariatu</legend>
          <div class="mb-1">
            <label for="vol_context" class="form-label">Obszar / zakres wolontariatu</label>
            <input type="text" class="form-control" id="vol_context" name="vol_context"
                   maxlength="255"
                   placeholder="np. pomoc w biurze, koordynacja wydarzeń, praca zdalna…"
                   value="<?= h($old['vol_context']) ?>">
            <div class="form-text">
              Opcjonalnie — opisz, czego dotyczy Twój wolontariat.
              Pomoże nam lepiej przygotować wsparcie.
            </div>
          </div>
        </fieldset>

        <!-- ════ Specjalne potrzeby ════ -->
        <fieldset class="mb-4">
          <legend>Specjalne potrzeby i dostępność</legend>

          <div class="<?= $err('needs') ? 'is-invalid' : '' ?>" role="group"
               aria-label="Rodzaj specjalnych potrzeb"
               <?= $err('needs') ? 'aria-describedby="e-needs"' : '' ?>>
            <?php foreach ($needs_map as $k => $lbl): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="needs[]"
                       id="need_<?= h($k) ?>" value="<?= h($k) ?>"
                       <?= $checked($k) ? 'checked' : '' ?>
                       <?= $k === 'other' ? 'data-toggle-other="1"' : '' ?>>
                <label class="form-check-label" for="need_<?= h($k) ?>"><?= h($lbl) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($err('needs')): ?>
            <div id="e-needs" class="invalid-feedback d-block"><?= h($errors['needs']) ?></div>
          <?php endif; ?>

          <div class="mt-3" id="needs_other_wrap">
            <label for="needs_other" class="form-label">Inne — opis</label>
            <input type="text" class="form-control <?= $err('needs_other') ? 'is-invalid' : '' ?>"
                   id="needs_other" name="needs_other" maxlength="500"
                   value="<?= h($old['needs_other']) ?>"
                   <?= $err('needs_other') ? 'aria-describedby="e-other"' : '' ?>>
            <?php if ($err('needs_other')): ?>
              <div id="e-other" class="invalid-feedback"><?= h($errors['needs_other']) ?></div>
            <?php endif; ?>
          </div>

          <div class="mt-3">
            <label for="details" class="form-label">Dodatkowe informacje</label>
            <textarea class="form-control" id="details" name="details" rows="4"
                      aria-describedby="details-help"><?= h($old['details']) ?></textarea>
            <div id="details-help" class="form-text">
              Opisz szczegółowo, jakiego wsparcia potrzebujesz — im więcej napiszesz,
              tym lepiej możemy się przygotować.
            </div>
          </div>
        </fieldset>

        <div class="d-grid">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij zgłoszenie
          </button>
        </div>
      </div>
    </form>
    </main>

  <?php endif; ?>

  <p class="text-center text-secondary small mt-4 mb-0">
    &copy; <?= date('Y') ?> <?= h($org) ?>
  </p>
</div>
</div>

<script>
  (function () {
    var cb = document.getElementById('need_other');
    var wrap = document.getElementById('needs_other_wrap');
    if (!cb || !wrap) return;
    function sync() { wrap.style.display = cb.checked ? '' : 'none'; }
    cb.addEventListener('change', sync);
    sync();
  })();
</script>
</body>
</html>
