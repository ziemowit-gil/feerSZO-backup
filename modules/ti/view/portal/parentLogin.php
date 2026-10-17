<?php
/**
 * modules/ti/view/portal/parentLogin.php — logowanie do panelu rodzica (karty30/ti/kursant/parent.php, gdy opiekun
 * niezalogowany) w wyglądzie FEER (portal: _style, _header, _footer).
 * Formularze i pola bez zmian względem dawnego widoku: _op = otp_request | otp_verify | choose | pwd_login | up_login
 * (obsługa w parent.php). Wejście: $stage (phone|code|choose|pwd), $role (rodzic|up), $err, $info, $org.
 */
$ppTitle = 'Panel zajęć'; $ppOrg = $org; $ppCrumb = 'Panel rodzica'; $ppLogo = ''; $ppLoginUrl = '/login.php'; $ppAccount = null;
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Panel rodzica — logowanie — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include __DIR__ . '/_style.php'; ?>
</head>
<body class="pp">
<?php include __DIR__ . '/_header.php'; ?>
<main id="pp-main" class="pp-wrap">
  <h1 class="pp-h1">Panel rodzica</h1>
  <ul class="pp-tabs" aria-label="Kim jesteś">
    <li><a href="parent.php"<?= $role === 'rodzic' ? ' aria-current="page"' : '' ?>><i class="bi bi-people" aria-hidden="true"></i> Rodzic / opiekun</a></li>
    <li><a href="parent.php?role=up"<?= $role === 'up' ? ' aria-current="page"' : '' ?>><i class="bi bi-person-check" aria-hidden="true"></i> Osoba upoważniona</a></li>
  </ul>
  <?php if ($err): ?><div class="pp-error" role="alert"><?= h($err) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="pp-ok" role="status"><?= h($info) ?></div><?php endif; ?>

  <div class="pp-cols">
    <div>
    <?php if ($role === 'up'): ?>
      <p class="pp-note" style="margin-top:0">Zaloguj się danymi podanymi w upoważnieniu (login i hasło nadane przez kursanta).</p>
      <form method="post" autocomplete="on">
        <input type="hidden" name="_op" value="up_login">
        <div class="pp-field"><label for="up-login">Login</label><input class="pp-input" id="up-login" name="login" autocomplete="username" value="<?= h($_POST['login'] ?? '') ?>" required autofocus></div>
        <div class="pp-field"><label for="up-pass">Hasło</label><input class="pp-input" id="up-pass" name="password" type="password" autocomplete="current-password" required></div>
        <button class="pp-submit">Zaloguj</button>
      </form>

    <?php elseif ($stage === 'choose'): $kids = $_SESSION['k30_parent_choose'] ?? []; ?>
      <form method="post">
        <input type="hidden" name="_op" value="choose">
        <fieldset style="border:0;padding:0;margin:0 0 1.3rem">
          <legend class="pp-label">Wybierz dziecko:</legend>
          <?php foreach ($kids as $k): ?>
            <label style="display:flex;gap:.6rem;align-items:center;font-weight:400;padding:.4rem 0"><input type="radio" name="student_id" value="<?= (int)$k['id'] ?>" required style="width:1.2rem;height:1.2rem"> <?= h($k['name']) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <button class="pp-submit">Przejdź do panelu</button>
      </form>

    <?php else: ?>
      <ul class="pp-tabs" aria-label="Sposób logowania" style="background:transparent;padding:0">
        <li><a href="parent.php"<?= $stage !== 'pwd' ? ' aria-current="page"' : '' ?>><i class="bi bi-chat-dots" aria-hidden="true"></i> Kod SMS</a></li>
        <li><a href="parent.php?m=pwd"<?= $stage === 'pwd' ? ' aria-current="page"' : '' ?>><i class="bi bi-person-lock" aria-hidden="true"></i> Login i hasło</a></li>
      </ul>
      <?php if ($stage === 'pwd'): ?>
        <form method="post" autocomplete="on">
          <input type="hidden" name="_op" value="pwd_login">
          <div class="pp-field"><label for="plogin">Login rodzica</label><input class="pp-input" id="plogin" name="login" autocomplete="username" value="<?= h($_POST['login'] ?? '') ?>" placeholder="np. j.kowalski-r" required autofocus></div>
          <div class="pp-field"><label for="ppass">Hasło</label><input class="pp-input" id="ppass" name="password" type="password" autocomplete="current-password" required></div>
          <button class="pp-submit">Zaloguj</button>
          <div class="pp-links"><a href="parent.php"><i class="bi bi-chat-dots" aria-hidden="true"></i> Nie pamiętam hasła — zaloguj kodem SMS</a></div>
        </form>
      <?php elseif ($stage === 'code'): ?>
        <form method="post">
          <input type="hidden" name="_op" value="otp_verify">
          <div class="pp-field"><label for="code">Kod z SMS-a</label><input class="pp-input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="6 cyfr" maxlength="6" required autofocus style="max-width:14rem;letter-spacing:.3em"></div>
          <button class="pp-submit">Zaloguj</button>
        </form>
        <form method="post" class="pp-links">
          <input type="hidden" name="_op" value="otp_request"><input type="hidden" name="phone" value="<?= h($_POST['phone'] ?? '') ?>">
          <button style="background:none;border:0;padding:0;color:var(--pp-blue);cursor:pointer;font:inherit">Wyślij kod ponownie</button>
        </form>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="_op" value="otp_request">
          <div class="pp-field"><label for="phone">Numer telefonu opiekuna</label>
            <input class="pp-input" id="phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="np. 600 100 200" required autofocus aria-describedby="phone-h">
            <p id="phone-h" class="pp-note" style="margin:.4rem 0 0">Numer zapisany w placówce. Wyślemy 6-cyfrowy kod.</p></div>
          <button class="pp-submit"><i class="bi bi-chat-dots" aria-hidden="true" style="margin-right:.4rem"></i> Wyślij kod SMS</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    </div>

    <aside class="pp-note" aria-label="Informacje">
      <p style="margin-top:0"><strong style="color:var(--pp-ink)">W panelu rodzica:</strong> rozliczenia i płatności, frekwencja, oceny i postępy, plan zajęć, wiadomości do prowadzących, dostęp dziecka do panelu.</p>
      <a class="pp-alt" href="/login.php"><i class="bi bi-pc-display" aria-hidden="true"></i> Jestem kursantem</a>
      <p>Uczestnik zajęć loguje się do panelu zajęć własnym loginem.</p>
      <a class="pp-alt" href="/nabor/logowanie"><i class="bi bi-person-badge" aria-hidden="true"></i> Konto kandydata</a>
      <p>Zgłoszenie dziecka na zajęcia, status zgłoszenia i dokumenty.<?= org_setting('ti_nabor_enabled') === '1' ? ' <a href="/nabor">Zapisz na zajęcia</a>.' : '' ?></p>
    </aside>
  </div>
</main>
<?php
$ppFootLinks = ['Panel zajęć' => '/login.php', 'Konto kandydata' => '/nabor/logowanie', 'Strona Fundacji FEER' => 'https://feer.org.pl'];
include __DIR__ . '/_footer.php';
?>
</body>
</html>
