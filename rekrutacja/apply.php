<?php
/**
 * rekrutacja/apply.php — Publiczny formularz aplikacyjny (bez logowania)
 * Wolontariat / etat, upload CV (PDF/DOCX) + listu motywacyjnego, zgoda RODO.
 * Zabezpieczenia: CSRF (token sesyjny), honeypot, limit zgłoszeń per IP/dobę.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rekrutacja.php';

rekr_migrate();
if (!module_enabled('rekrutacja_enabled')) { http_response_code(404); die('Rekrutacja jest obecnie zamknięta.'); }

$positions = rekr_positions();
$errors = [];
$sent = false;
$old = ['imie' => '', 'nazwisko' => '', 'email' => '', 'telefon' => '', 'type' => 'wolontariat', 'position_id' => 0, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Honeypot: pole ukryte dla ludzi — bot je wypełni; udaj sukces bez zapisu
    if (trim((string)($_POST['www'] ?? '')) !== '') {
        $sent = true;
    } else {
        // Limit: max 5 zgłoszeń z jednego IP na dobę
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $cnt = (int)(db_one(
            "SELECT COUNT(*) AS c FROM rekr_status_history
             WHERE note LIKE ? AND created_at > ?",
            ['%[ip:' . $ip . ']%', date('Y-m-d H:i:s', time() - 86400)])['c'] ?? 0);
        if ($ip !== '' && $cnt >= 5) {
            $errors[] = 'Przekroczono limit zgłoszeń. Spróbuj ponownie jutro lub napisz do nas e-mailem.';
        } else {
            $old = array_merge($old, array_intersect_key($_POST, $old));
            $res = rekr_application_create($_POST, ['cv' => $_FILES['cv'] ?? [], 'list' => $_FILES['list'] ?? []]);
            if ($res['ok']) {
                // Znacznik IP w notce pierwszego wpisu historii — na potrzeby rate-limitu
                if ($ip !== '') {
                    db_exec("UPDATE rekr_status_history SET note = note || ' [ip:' || ? || ']'
                             WHERE application_id=? AND from_status=''", [$ip, $res['id']]);
                }
                $sent = true;
                $errors = $res['errors']; // ewentualne problemy z plikami — informacyjnie
            } else {
                $errors = $res['errors'];
            }
        }
    }
}
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Aplikuj — <?= h(defined('ORG_NAME') ? ORG_NAME : 'Rekrutacja') ?></title>
<link rel="stylesheet" href="<?= h(APP_URL) ?>/assets/bootstrap/bootstrap.min.css">
<style>
  body { background: #f6f7f9; }
  .apply-card { max-width: 720px; margin: 3rem auto; }
</style>
</head>
<body>
<main class="apply-card px-3">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 mb-1">Dołącz do nas</h1>
      <p class="text-muted">Formularz rekrutacyjny — wolontariat i praca w <?= h(defined('ORG_NAME') ? ORG_NAME : 'naszej organizacji') ?>.</p>

      <?php if ($sent): ?>
        <div class="alert alert-success" role="status">
          <strong>Dziękujemy!</strong> Twoje zgłoszenie zostało przyjęte. Potwierdzenie wyślemy na podany adres e-mail.
        </div>
        <?php foreach ($errors as $e): ?>
          <div class="alert alert-warning" role="alert"><?= h($e) ?></div>
        <?php endforeach; ?>
      <?php else: ?>

      <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger" role="alert"><?= h($e) ?></div>
      <?php endforeach; ?>

      <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <!-- honeypot: niewidoczne dla ludzi, boty wypełniają -->
        <div style="position:absolute;left:-9999px" aria-hidden="true">
          <label for="www">Strona WWW (zostaw puste)</label>
          <input type="text" id="www" name="www" tabindex="-1" autocomplete="off">
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="a-imie">Imię <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="a-imie" name="imie" maxlength="100" required value="<?= h($old['imie']) ?>" autocomplete="given-name">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="a-nazwisko">Nazwisko <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="a-nazwisko" name="nazwisko" maxlength="100" required value="<?= h($old['nazwisko']) ?>" autocomplete="family-name">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="a-email">E-mail <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="email" class="form-control" id="a-email" name="email" maxlength="200" required value="<?= h($old['email']) ?>" autocomplete="email">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="a-telefon">Telefon</label>
            <input type="tel" class="form-control" id="a-telefon" name="telefon" maxlength="30" value="<?= h($old['telefon']) ?>" autocomplete="tel">
          </div>
          <div class="col-md-6">
            <fieldset>
              <legend class="form-label fs-6">Rodzaj współpracy <span class="text-danger" aria-hidden="true">*</span></legend>
              <?php foreach (REKR_TYPES as $k => $t): ?>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="type" id="a-type-<?= h($k) ?>" value="<?= h($k) ?>" <?= $old['type'] === $k ? 'checked' : '' ?>>
                <label class="form-check-label" for="a-type-<?= h($k) ?>"><?= h($t['label']) ?></label>
              </div>
              <?php endforeach; ?>
            </fieldset>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="a-pos">Stanowisko / obszar</label>
            <select class="form-select" id="a-pos" name="position_id">
              <option value="">— wybierz (opcjonalnie) —</option>
              <?php foreach ($positions as $p): ?>
              <option value="<?= (int)$p['id'] ?>" data-type="<?= h($p['type']) ?>" <?= (int)$old['position_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                <?= h($p['name']) ?> (<?= h(REKR_TYPES[$p['type']]['label'] ?? $p['type']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label" for="a-message">Kilka słów o sobie</label>
            <textarea class="form-control" id="a-message" name="message" rows="4" maxlength="10000"><?= h($old['message']) ?></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="a-cv">CV (PDF lub DOCX, do 10 MB) <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="file" class="form-control" id="a-cv" name="cv" accept=".pdf,.docx" required>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="a-list">List motywacyjny (opcjonalnie)</label>
            <input type="file" class="form-control" id="a-list" name="list" accept=".pdf,.docx">
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="a-consent" name="consent" value="1" required>
              <label class="form-check-label small" for="a-consent">
                Wyrażam zgodę na przetwarzanie moich danych osobowych zawartych w zgłoszeniu na potrzeby
                procesu rekrutacji prowadzonego przez <?= h(defined('ORG_NAME') ? ORG_NAME : 'organizację') ?>. <span class="text-danger" aria-hidden="true">*</span>
              </label>
            </div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary btn-lg">Wyślij zgłoszenie</button>
          </div>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
