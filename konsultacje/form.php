<?php
/**
 * konsultacje/form.php — Publiczny formularz „Karta konsultacyjna".
 *
 * Dostępny BEZ logowania. Strona celowo NIE używa includes/header.php
 * (brak sidebaru/sesji wymaganej). Zabezpieczenie antyspamowe: honeypot
 * (ukryte pole) + token czasu (formularz wysłany zbyt szybko = bot).
 *
 * Jeśli użytkownik jest zalogowany, pole „Podpis konsultanta" jest wstępnie
 * wypełnione jego imieniem i nazwiskiem.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/consultations.php';

cc_migrate();
auth_start();

$_user = current_user();              // null gdy niezalogowany — to jest OK
$errors = [];
$old = [
    'org_name'            => '',
    'consultation_date'   => date('Y-m-d'),
    'area_type'           => '',
    'form'                => '',
    'problem_description' => '',
    'actions_taken'       => '',
    'next_steps'          => '',
    'hours'               => '',
    'consultant'          => $_user['name'] ?? '',
];
$saved_id = null;

/* ── Token czasu (anty-bot) ───────────────────────────────────────────────── */
if (empty($_SESSION['cc_form_ts'])) {
    $_SESSION['cc_form_ts'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // 1) Honeypot — ukryte pole „website" musi pozostać puste.
    $is_spam = trim((string)($_POST['website'] ?? '')) !== '';

    // 2) Czas wypełniania — krócej niż 2 s to najpewniej bot.
    $started = (int)($_SESSION['cc_form_ts'] ?? 0);
    if ($started && (time() - $started) < 2) {
        $is_spam = true;
    }

    if ($is_spam) {
        // Cisza dla bota — udajemy sukces, nic nie zapisujemy.
        $saved_id = 0;
    } else {
        [$clean, $errors] = cc_validate($_POST);
        // zachowaj wpisane wartości na wypadek błędów
        foreach ($old as $k => $_) {
            if (isset($_POST[$k])) $old[$k] = trim((string)$_POST[$k]);
        }
        if (!$errors) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $saved_id = cc_create($clean, $_user['id'] ?? null, $ip);
            unset($_SESSION['cc_form_ts']);          // świeży token na kolejny wpis
        }
    }
}

$areas = cc_areas();
$forms = cc_forms();
$suggest = cc_org_suggestions();
$err = fn($k) => isset($errors[$k]);
$base = APP_URL . '/konsultacje';
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Karta konsultacyjna — <?= h(defined('ORG_NAME') ? ORG_NAME : 'Konsultacje') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  :root { --brand:#0d6efd; }
  body { background:#f1f5f9; }
  .cc-card { max-width:760px; }
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
  <div class="cc-card mx-auto">

    <header class="text-center mb-4">
      <i class="bi bi-clipboard2-pulse text-primary" style="font-size:2.4rem" aria-hidden="true"></i>
      <h1 class="h3 fw-bold mt-2 mb-1">Karta konsultacyjna</h1>
      <p class="text-secondary mb-0"><?= h(defined('ORG_NAME') ? ORG_NAME : '') ?></p>
    </header>

    <?php if ($saved_id !== null): ?>
      <div class="card border-success shadow-sm" role="status">
        <div class="card-body text-center p-4 p-md-5">
          <i class="bi bi-check-circle-fill text-success" style="font-size:3rem" aria-hidden="true"></i>
          <h2 class="h4 fw-bold mt-3">Dziękujemy — karta została zapisana</h2>
          <p class="text-secondary mb-4">
            Konsultacja została odnotowana w rejestrze.<?php if ($saved_id): ?>
            Numer karty: <strong>#<?= (int)$saved_id ?></strong>.<?php endif; ?>
          </p>
          <a href="<?= h($base) ?>/form.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj kolejną kartę
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
      <form method="post" action="<?= h($base) ?>/form.php" novalidate
            class="card shadow-sm" aria-describedby="cc-intro">
        <div class="card-body p-4">
          <p id="cc-intro" class="text-secondary small mb-4">
            Pola oznaczone gwiazdką (<span class="text-danger">*</span>) są wymagane.
          </p>

          <?= csrf_field() ?>

          <!-- Honeypot: niewidoczne dla ludzi, wypełniane przez boty -->
          <div class="hp-field" aria-hidden="true">
            <label for="website">Nie wypełniaj tego pola</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
          </div>

          <fieldset class="mb-4">
            <legend>Organizacja i termin</legend>

            <div class="mb-3">
              <label for="org_name" class="form-label req">Nazwa organizacji</label>
              <input type="text" class="form-control <?= $err('org_name') ? 'is-invalid' : '' ?>"
                     id="org_name" name="org_name" list="org_list" maxlength="255"
                     autocomplete="organization" required
                     value="<?= h($old['org_name']) ?>"
                     <?= $err('org_name') ? 'aria-describedby="e-org_name"' : '' ?>>
              <?php if ($suggest): ?>
              <datalist id="org_list">
                <?php foreach ($suggest as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?>
              </datalist>
              <?php endif; ?>
              <?php if ($err('org_name')): ?>
                <div id="e-org_name" class="invalid-feedback"><?= h($errors['org_name']) ?></div>
              <?php endif; ?>
            </div>

            <div class="mb-1">
              <label for="consultation_date" class="form-label req">Data konsultacji</label>
              <input type="date" class="form-control <?= $err('consultation_date') ? 'is-invalid' : '' ?>"
                     id="consultation_date" name="consultation_date" required
                     value="<?= h($old['consultation_date']) ?>"
                     <?= $err('consultation_date') ? 'aria-describedby="e-date"' : '' ?>>
              <?php if ($err('consultation_date')): ?>
                <div id="e-date" class="invalid-feedback"><?= h($errors['consultation_date']) ?></div>
              <?php endif; ?>
            </div>
          </fieldset>

          <fieldset class="mb-4">
            <legend>Rodzaj konsultacji</legend>

            <div class="mb-3">
              <label for="area_type" class="form-label req">Obszar wsparcia</label>
              <select class="form-select <?= $err('area_type') ? 'is-invalid' : '' ?>"
                      id="area_type" name="area_type" required
                      <?= $err('area_type') ? 'aria-describedby="e-area"' : '' ?>>
                <option value="">— wybierz —</option>
                <?php foreach ($areas as $k => $lbl): ?>
                  <option value="<?= h($k) ?>" <?= $old['area_type'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($err('area_type')): ?>
                <div id="e-area" class="invalid-feedback"><?= h($errors['area_type']) ?></div>
              <?php endif; ?>
            </div>

            <fieldset class="border-0 p-0 m-0">
              <legend class="form-label req fs-6 mb-2" style="border:0">Forma konsultacji</legend>
              <div class="<?= $err('form') ? 'is-invalid' : '' ?>" role="radiogroup"
                   aria-label="Forma konsultacji">
                <?php foreach ($forms as $k => $lbl): ?>
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="form"
                           id="form_<?= h($k) ?>" value="<?= h($k) ?>"
                           <?= $old['form'] === $k ? 'checked' : '' ?>
                           <?= $err('form') ? 'aria-describedby="e-form"' : '' ?>>
                    <label class="form-check-label" for="form_<?= h($k) ?>"><?= h($lbl) ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($err('form')): ?>
                <div id="e-form" class="invalid-feedback d-block"><?= h($errors['form']) ?></div>
              <?php endif; ?>
            </fieldset>
          </fieldset>

          <fieldset class="mb-4">
            <legend>Przebieg konsultacji</legend>

            <div class="mb-3">
              <label for="problem_description" class="form-label req">Problem / zagadnienie</label>
              <textarea class="form-control <?= $err('problem_description') ? 'is-invalid' : '' ?>"
                        id="problem_description" name="problem_description" rows="4" required
                        <?= $err('problem_description') ? 'aria-describedby="e-problem"' : '' ?>><?= h($old['problem_description']) ?></textarea>
              <?php if ($err('problem_description')): ?>
                <div id="e-problem" class="invalid-feedback"><?= h($errors['problem_description']) ?></div>
              <?php endif; ?>
            </div>

            <div class="mb-3">
              <label for="actions_taken" class="form-label">Podjęte czynności</label>
              <textarea class="form-control" id="actions_taken" name="actions_taken"
                        rows="4"><?= h($old['actions_taken']) ?></textarea>
            </div>

            <div class="mb-1">
              <label for="next_steps" class="form-label">Dalsze kroki</label>
              <textarea class="form-control" id="next_steps" name="next_steps"
                        rows="3"><?= h($old['next_steps']) ?></textarea>
            </div>
          </fieldset>

          <fieldset class="mb-4">
            <legend>Podsumowanie</legend>

            <div class="row g-3">
              <div class="col-sm-4">
                <label for="hours" class="form-label req">Liczba godzin</label>
                <input type="number" inputmode="decimal" step="0.5" min="0" max="999"
                       class="form-control <?= $err('hours') ? 'is-invalid' : '' ?>"
                       id="hours" name="hours" required
                       value="<?= h($old['hours']) ?>"
                       <?= $err('hours') ? 'aria-describedby="e-hours"' : '' ?>>
                <?php if ($err('hours')): ?>
                  <div id="e-hours" class="invalid-feedback"><?= h($errors['hours']) ?></div>
                <?php endif; ?>
              </div>
              <div class="col-sm-8">
                <label for="consultant" class="form-label req">Podpis konsultanta (imię i nazwisko)</label>
                <input type="text" class="form-control <?= $err('consultant') ? 'is-invalid' : '' ?>"
                       id="consultant" name="consultant" maxlength="255"
                       autocomplete="name" required
                       value="<?= h($old['consultant']) ?>"
                       <?= $err('consultant') ? 'aria-describedby="e-consultant"' : '' ?>>
                <?php if ($err('consultant')): ?>
                  <div id="e-consultant" class="invalid-feedback"><?= h($errors['consultant']) ?></div>
                <?php elseif ($_user): ?>
                  <div class="form-text">Pole wypełnione na podstawie Twojego konta — możesz je zmienić.</div>
                <?php endif; ?>
              </div>
            </div>
          </fieldset>

          <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">
              <i class="bi bi-send me-1" aria-hidden="true"></i>Zapisz kartę konsultacyjną
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
</body>
</html>
