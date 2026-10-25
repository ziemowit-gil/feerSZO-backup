<?php
/**
 * helpdesk/csat.php — publiczna ankieta zadowolenia (dostęp po tokenie z e-maila, bez logowania).
 * Ocena zapisuje się dopiero po wysłaniu formularza. Logika: modules/helpdeskCsat/lib/helpdeskCsat.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/modules/helpdeskCsat/lib/helpdeskCsat.php';

$org   = defined('ORG_NAME') ? ORG_NAME : 'Helpdesk';
$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$csat  = hdCsatByToken($token);
$error = null;
$done  = false;
if ($csat && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $error = hdCsatAnswer($token, (int)($_POST['rating'] ?? 0), (string)($_POST['comment'] ?? ''));
    $done = $error === null;
    $csat = hdCsatByToken($token);
}
$answered = $csat && $csat['answered_at'];
$labels = [1 => 'Bardzo źle', 2 => 'Słabo', 3 => 'Średnio', 4 => 'Dobrze', 5 => 'Świetnie'];
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Ocena obsługi zgłoszenia · <?= h($org) ?></title>
<style>
  body{margin:0;background:#faf9f8;color:#242424;font:16px/1.5 "Segoe UI",system-ui,sans-serif}
  .bar{background:#fff;border-bottom:1px solid #e1dfdd;padding:.8rem 1.2rem;font-weight:600}
  .box{max-width:560px;margin:2rem auto;background:#fff;border:1px solid #e1dfdd;padding:1.5rem}
  h1{font-size:1.3rem;margin:0 0 .25rem}.muted{color:#605e5c;font-size:.9rem}
  .rates{display:grid;grid-template-columns:repeat(5,1fr);gap:.5rem;margin:1.2rem 0}
  .rates label{display:block;text-align:center;border:1px solid #8a8886;padding:.7rem .2rem;cursor:pointer;font-size:.85rem}
  .rates label b{display:block;font-size:1.4rem}
  .rates input{position:absolute;opacity:0}
  .rates input:checked + label,.rates label:hover{background:#0f6cbd;border-color:#0f6cbd;color:#fff}
  .rates input:focus-visible + label{outline:3px solid #0f6cbd;outline-offset:2px}
  textarea{width:100%;box-sizing:border-box;border:1px solid #8a8886;padding:.5rem;font:inherit;min-height:90px}
  button{margin-top:1rem;background:#0f6cbd;color:#fff;border:0;padding:.6rem 1.4rem;font:inherit;font-weight:600;cursor:pointer}
  .msg{padding:.7rem 1rem;margin-bottom:1rem}.err{background:#fde7e9;color:#a4262c}.ok{background:#dff6dd;color:#0e700e}
  @media (max-width:480px){.rates{grid-template-columns:repeat(5,1fr);gap:.25rem}.rates label{font-size:.7rem}}
</style></head><body>
<div class="bar"><?= h($org) ?> · Helpdesk</div>
<div class="box">
<?php if (!$csat): ?>
  <h1>Nie znaleziono ankiety</h1><p class="muted">Link jest nieprawidłowy lub wygasł.</p>
<?php elseif ($answered): ?>
  <?php if ($done): ?><div class="msg ok" role="status">Dziękujemy za ocenę!</div><?php endif; ?>
  <h1>Zgłoszenie <?= h($csat['number']) ?></h1>
  <p class="muted"><?= $done ? 'Twoja opinia pomoże nam poprawić obsługę.' : 'Ankieta została już wypełniona — dziękujemy.' ?></p>
<?php else: ?>
  <h1>Jak oceniasz obsługę?</h1>
  <p class="muted">Zgłoszenie <strong><?= h($csat['number']) ?></strong> — <?= h($csat['title']) ?></p>
  <?php if ($error): ?><div class="msg err" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="t" value="<?= h($token) ?>">
    <fieldset style="border:0;padding:0;margin:0"><legend class="muted">Ocena (1–5)</legend>
      <div class="rates">
        <?php foreach ($labels as $n => $l): ?>
        <div><input type="radio" name="rating" id="r<?= $n ?>" value="<?= $n ?>" required><label for="r<?= $n ?>"><b><?= $n ?></b><?= h($l) ?></label></div>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <label for="cm" class="muted">Komentarz (opcjonalnie)</label>
    <textarea id="cm" name="comment" maxlength="1000"></textarea>
    <button type="submit">Wyślij ocenę</button>
  </form>
<?php endif; ?>
</div></body></html>
