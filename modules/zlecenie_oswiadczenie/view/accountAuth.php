<?php
/**
 * modules/zlecenie_oswiadczenie/view/accountAuth.php — logowanie, potwierdzenie z e-maila (ustaw hasło / dodaj do konta)
 * i „Nie pamiętam hasła” dla konta oświadczeń. Wymaga: $screen ('login'|'confirm'|'reset'); dla confirm: $token, $acc (null = link nieważny).
 */
$_needPass = $screen === 'confirm' && !empty($acc) && (empty($acc['password_hash']) || $acc['token_purpose'] === 'reset');
?>
<?php include __DIR__ . '/_head.php'; ?>
<div class="card" style="max-width:520px;margin:0 auto 16px">
<?php if ($screen === 'login'): ?>
  <h1>Logowanie</h1>
  <p class="muted small">Konto z oświadczeniami zleceniobiorcy. Login to adres e-mail podany w oświadczeniu.</p>
  <form method="post" action="<?= h(route('osw.login')) ?>">
    <?= Router::csrfField() ?>
    <div class="fld"><label for="f-email">E-mail</label><input type="email" id="f-email" name="email" required autocomplete="username" inputmode="email"></div>
    <div class="fld"><label for="f-pass">Hasło</label><input type="password" id="f-pass" name="password" required autocomplete="current-password"></div>
    <button type="submit" class="btn">Zaloguj się</button>
  </form>
  <p class="small" style="margin:14px 0 0"><a href="<?= h(route('osw.reset')) ?>">Nie pamiętam hasła</a> · <a href="<?= h(route('osw.public')) ?>">Wypełnij nowe oświadczenie</a></p>
<?php elseif ($screen === 'reset'): ?>
  <h1>Nie pamiętam hasła</h1>
  <p class="muted small">Wpisz adres e-mail konta — wyślemy link do ustawienia nowego hasła.</p>
  <form method="post" action="<?= h(route('osw.reset')) ?>">
    <?= Router::csrfField() ?>
    <div class="fld"><label for="f-email">E-mail</label><input type="email" id="f-email" name="email" required autocomplete="email" inputmode="email"></div>
    <button type="submit" class="btn">Wyślij link</button>
  </form>
  <p class="small" style="margin:14px 0 0"><a href="<?= h(route('osw.login')) ?>">← Logowanie</a></p>
<?php elseif (empty($acc)): ?>
  <h1>Link nieaktywny</h1>
  <p>Link wygasł albo był już użyty. <a href="<?= h(route('osw.login')) ?>">Zaloguj się</a> albo użyj „<a href="<?= h(route('osw.reset')) ?>">Nie pamiętam hasła</a>”.</p>
<?php else: ?>
  <h1><?= $_needPass ? ($acc['token_purpose'] === 'reset' ? 'Ustaw nowe hasło' : 'Ustaw hasło do konta') : 'Dodaj oświadczenie do konta' ?></h1>
  <p class="muted small">Konto: <?= h($acc['email']) ?>.<?= $acc['token_purpose'] === 'attach' ? ' Konto daje dostęp wyłącznie do Twoich oświadczeń.' : '' ?></p>
  <form method="post" action="<?= h(route('osw.confirm')) ?>">
    <?= Router::csrfField() ?><input type="hidden" name="t" value="<?= h($token) ?>">
    <?php if ($_needPass): ?>
    <input type="hidden" name="username" value="<?= h($acc['email']) ?>" autocomplete="username">
    <div class="fld"><label for="f-p1">Hasło</label><span class="hint" id="h-p1">Co najmniej <?= OswAccount::PASS_MIN ?> znaków.</span>
      <input type="password" id="f-p1" name="password" required minlength="<?= OswAccount::PASS_MIN ?>" autocomplete="new-password" aria-describedby="h-p1"></div>
    <div class="fld"><label for="f-p2">Powtórz hasło</label><input type="password" id="f-p2" name="password2" required autocomplete="new-password"></div>
    <button type="submit" class="btn">Zapisz hasło i przejdź do konta</button>
    <?php else: ?>
    <p>Kliknij, aby dodać oświadczenie do swojego konta. Potem zaloguj się swoim hasłem.</p>
    <button type="submit" class="btn">Potwierdzam — dodaj do konta</button>
    <?php endif; ?>
  </form>
<?php endif; ?>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
