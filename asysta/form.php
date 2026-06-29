<?php
/**
 * asysta/form.php — Publiczny formularz „Zgłoszenie asysty i specjalnych potrzeb".
 *
 * Dostępny BEZ logowania. Strona celowo NIE używa includes/header.php.
 * Zabezpieczenie antyspamowe: honeypot (ukryte pole) + token czasu.
 * W pełni dostępny (WCAG 2.1 AA): etykiety for/id, grupy fieldset/legend,
 * widoczny focus, komunikaty błędów powiązane przez aria-describedby.
 *
 * Sekcja administracyjna (przypisanie wolontariusza, kanały powiadomień,
 * notatki wewnętrzne) NIE jest częścią tego formularza — patrz asysta/view.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/assistance.php';

asr_migrate();
auth_start();

// URL publicznego formularza — ten sam adres do wyświetlenia, POST i przekierowania.
// Punkt wejścia /extforms/asystaFEER/ ustawia własną wartość przed include.
$ASR_FORM_URL = $ASR_FORM_URL ?? (APP_URL . '/asysta/form.php');

$errors = [];
$old = [
    'participant_name'  => '',
    'participant_email' => '',
    'participant_phone' => '',
    'is_guardian'       => 'no',
    'event_name'        => '',
    'event_when_where'  => '',
    'needs'             => [],
    'needs_other'       => '',
    'details'           => '',
];
$saved_id = null;
$saved_no = '';

/* ── Token czasu (anty-bot) ───────────────────────────────────────────────── */
if (empty($_SESSION['asr_form_ts'])) {
    $_SESSION['asr_form_ts'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // 1) Honeypot — ukryte pole „website" musi pozostać puste.
    $is_spam = trim((string)($_POST['website'] ?? '')) !== '';
    // 2) Formularz wysłany szybciej niż 2 s = najpewniej bot.
    $started = (int)($_SESSION['asr_form_ts'] ?? 0);
    if ($started && (time() - $started) < 2) $is_spam = true;

    if ($is_spam) {
        $saved_id = 0; // cisza dla bota — udajemy sukces, nic nie zapisujemy
    } else {
        [$clean, $errors] = asr_validate($_POST);
        // zachowaj wpisane wartości na wypadek błędów
        $old['participant_name']  = trim((string)($_POST['participant_name'] ?? ''));
        $old['participant_email'] = trim((string)($_POST['participant_email'] ?? ''));
        $old['participant_phone'] = trim((string)($_POST['participant_phone'] ?? ''));
        $old['is_guardian']       = ($_POST['is_guardian'] ?? 'no') === 'yes' ? 'yes' : 'no';
        $old['event_name']        = trim((string)($_POST['event_name'] ?? ''));
        $old['event_when_where']  = trim((string)($_POST['event_when_where'] ?? ''));
        $old['needs']             = (array)($_POST['needs'] ?? []);
        $old['needs_other']       = trim((string)($_POST['needs_other'] ?? ''));
        $old['details']           = trim((string)($_POST['details'] ?? ''));

        if (!$errors) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $saved_id = asr_create($clean, current_user()['id'] ?? null, $ip);
            $rec = asr_get($saved_id);
            $saved_no = $rec ? asr_number($rec) : '';
            // PRG: pokaż ekran potwierdzenia (GET), aby F5 nie dublowało zgłoszenia.
            $_SESSION['asr_last_no'] = $saved_no;
            header('Location: ' . $ASR_FORM_URL . '?saved=1');
            exit;
        }
    }
}

/* ── Ekran potwierdzenia po zapisie (GET ?saved=1) ────────────────────────── */
if ($saved_id === null && isset($_GET['saved'])) {
    $saved_id = 1;
    $saved_no = (string)($_SESSION['asr_last_no'] ?? '');
    unset($_SESSION['asr_last_no'], $_SESSION['asr_form_ts']);
}

$needs_map = asr_needs();
$err = fn($k) => isset($errors[$k]);
$checked = fn($k) => in_array($k, (array)$old['needs'], true);
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Zgłoszenie asysty i specjalnych potrzeb — <?= h(defined('ORG_NAME') ? ORG_NAME : 'FEER') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#f1f5f9; }
  .asr-card { max-width:780px; }
  /* Czytelny focus (WCAG 2.4.7) */
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
  <div class="asr-card mx-auto">

    <header class="text-center mb-4">
      <i class="bi bi-universal-access-circle text-primary" style="font-size:2.6rem" aria-hidden="true"></i>
      <h1 class="h3 fw-bold mt-2 mb-1">Zgłoszenie asysty i specjalnych potrzeb</h1>
      <p class="text-secondary mb-0"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?></p>
    </header>

    <?php if ($saved_id !== null): ?>
      <div class="card border-success shadow-sm" role="status">
        <div class="card-body text-center p-4 p-md-5">
          <i class="bi bi-check-circle-fill text-success" style="font-size:3rem" aria-hidden="true"></i>
          <h2 class="h4 fw-bold mt-3">Dziękujemy — zgłoszenie zostało przyjęte</h2>
          <?php if ($saved_id && $saved_no !== ''): ?>
            <p class="text-secondary mb-1">Twoje zgłoszenie trafiło do zespołu dostępności.</p>
            <p class="mb-4">Numer zgłoszenia: <strong class="fs-5"><?= h($saved_no) ?></strong></p>
            <p class="text-secondary small mb-4">
              Zapisz ten numer — ułatwi kontakt w sprawie zgłoszenia. Skontaktujemy się
              z Tobą na podany adres e-mail lub telefon.
            </p>
          <?php else: ?>
            <p class="text-secondary mb-4">Twoje zgłoszenie zostało odnotowane.</p>
          <?php endif; ?>
          <a href="<?= h($ASR_FORM_URL) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Wyślij kolejne zgłoszenie
          </a>
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
      <form method="post" action="<?= h($ASR_FORM_URL) ?>" novalidate
            class="card shadow-sm" aria-describedby="asr-intro">
        <div class="card-body p-4">
          <p id="asr-intro" class="text-secondary small mb-4">
            Pola oznaczone gwiazdką (<span class="text-danger">*</span>) są wymagane.
            Formularz służy do zgłoszenia potrzeby asysty lub wsparcia dostępności
            podczas naszych wydarzeń.
          </p>

          <?= csrf_field() ?>

          <!-- Honeypot: niewidoczne dla ludzi, wypełniane przez boty -->
          <div class="hp-field" aria-hidden="true">
            <label for="website">Nie wypełniaj tego pola</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
          </div>

          <!-- ════ Dane uczestnika / zgłaszającego ════ -->
          <fieldset class="mb-4">
            <legend>Dane uczestnika / zgłaszającego</legend>

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

            <fieldset class="border-0 p-0 m-0 mt-3">
              <legend class="form-label fs-6 mb-2" style="border:0">
                Czy jesteś opiekunem zgłaszającym inną osobę?
              </legend>
              <div role="radiogroup" aria-label="Czy jesteś opiekunem zgłaszającym inną osobę?">
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="is_guardian" id="guardian_yes"
                         value="yes" <?= $old['is_guardian'] === 'yes' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="guardian_yes">Tak</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" name="is_guardian" id="guardian_no"
                         value="no" <?= $old['is_guardian'] !== 'yes' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="guardian_no">Nie</label>
                </div>
              </div>
            </fieldset>
          </fieldset>

          <!-- ════ Szczegóły wydarzenia ════ -->
          <fieldset class="mb-4">
            <legend>Szczegóły wydarzenia</legend>
            <div class="mb-3">
              <label for="event_name" class="form-label">Nazwa wydarzenia</label>
              <input type="text" class="form-control" id="event_name" name="event_name"
                     maxlength="255" value="<?= h($old['event_name']) ?>">
            </div>
            <div class="mb-1">
              <label for="event_when_where" class="form-label">Data i miejsce</label>
              <input type="text" class="form-control" id="event_when_where" name="event_when_where"
                     maxlength="255" placeholder="np. 15.07.2026, Kraków, ul. Przykładowa 1"
                     value="<?= h($old['event_when_where']) ?>">
            </div>
          </fieldset>

          <!-- ════ Zakres asysty i specjalne potrzeby ════ -->
          <fieldset class="mb-4">
            <legend>Zakres asysty i specjalne potrzeby</legend>

            <div class="<?= $err('needs') ? 'is-invalid' : '' ?>" role="group"
                 aria-label="Zakres asysty i specjalne potrzeby"
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
              <label for="details" class="form-label">Dodatkowe informacje / uwagi szczegółowe</label>
              <textarea class="form-control" id="details" name="details" rows="4"
                        aria-describedby="details-help"><?= h($old['details']) ?></textarea>
              <div id="details-help" class="form-text">
                Opisz, co pomoże nam się przygotować — np. rodzaj wsparcia, godziny, kontakt do opiekuna.
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
      &copy; <?= date('Y') ?> <?= h(defined('ORG_NAME') ? ORG_NAME : '') ?>
    </p>
  </div>
</div>

<script>
  // Progresywne usprawnienie: pokaż pole „Inne — opis" tylko gdy zaznaczono „Inne".
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
