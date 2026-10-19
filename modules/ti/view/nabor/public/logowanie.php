<?php
/**
 * modules/ti/view/nabor/public/logowanie.php — dedykowana strona „Logowanie kandydata” (/nabor/logowanie, krótko /kandydat).
 * Układ portalowy: login i hasło po lewej, zakładanie konta i odesłanie do panelu zajęć po prawej.
 * Strona logowania do panelu zajęć (karty30/ti/login.php) tylko informuje o tej podstronie i linkuje tutaj.
 * Zmienne: $error, $email, $next, $org, $acc, $form. Kontroler: NaborCandidateController::loginForm / login.
 */
$title = 'Logowanie kandydata';
include __DIR__ . '/_top.php';
?>
<h1 class="pp-h1">Logowanie kandydata</h1>
<p class="pp-note" style="margin:-.6rem 0 1.4rem;max-width:62ch">Strona dla osób, które zapisują się na zajęcia (nabór do grup).
  Sprawdzisz tu status zgłoszenia, wiadomości od organizatora i dokumenty.</p>
<?php if ($error): ?><div class="pp-error" role="alert"><?= h($error) ?></div><?php endif; ?>
<div class="pp-cols">
  <form method="post" action="<?= h(naborUrl('/logowanie')) ?>">
    <?= Router::csrfField() ?><input type="hidden" name="next" value="<?= h($next) ?>">
    <div class="pp-field"><label for="lg-e">E-mail (login)</label><input id="lg-e" type="email" name="email" class="pp-input" value="<?= h($email) ?>" autocomplete="username" required autofocus></div>
    <div class="pp-field"><label for="lg-p">Hasło</label><input id="lg-p" type="password" name="password" class="pp-input" autocomplete="current-password" required></div>
    <button class="pp-submit">Zaloguj</button>
    <div class="pp-links">
      <a href="<?= h(naborUrl('/rejestracja')) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Utwórz konto</a>
      <a href="<?= h(naborUrl('/przypomnij')) ?>"><i class="bi bi-question-circle-fill" aria-hidden="true"></i> Zapomniałem hasła</a>
    </div>
  </form>
  <aside aria-labelledby="lg-alt">
    <p id="lg-alt" class="pp-note" style="margin:0 0 .3rem">Konto kandydata:</p>
    <?php if (org_setting('ti_nabor_enabled') === '1'): ?>
      <a class="pp-alt" href="<?= h(naborUrl('/zapisy')) ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> Zapisz na zajęcia</a>
      <p class="pp-note">Po złożeniu i potwierdzeniu pierwszego zgłoszenia konto zakładamy automatycznie — na e-mail przyjdzie link do ustawienia hasła.</p>
    <?php else: ?>
      <a class="pp-alt" href="<?= h(naborUrl('/rejestracja')) ?>"><i class="bi bi-person-plus" aria-hidden="true"></i> Załóż konto</a>
      <p class="pp-note">Załóż konto teraz — zgłoszenie złożysz, gdy otworzymy nabór.</p>
    <?php endif; ?>
    <div style="border-top:1px solid var(--pp-line);margin-top:1.4rem;padding-top:1.1rem">
      <p class="pp-note" style="margin:0 0 .3rem"><strong style="color:var(--pp-ink)">Nie jesteś kandydatem?</strong> Zaloguj się do panelu zajęć:</p>
      <ul style="padding-left:1.1rem;margin:.2rem 0 0">
        <li><a href="/login.php?tab=kursant">Uczestnik zajęć</a></li>
        <li><a href="/login.php?tab=rodzic">Rodzic lub opiekun</a></li>
        <li><a href="/login.php?tab=dydaktyk">Prowadzący i kadra</a></li>
      </ul>
    </div>
  </aside>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
