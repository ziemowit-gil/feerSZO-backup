<?php
/**
 * modules/ti/view/portal/parentLogin.php — logowanie do panelu rodzica (karty30/ti/kursant/parent.php, gdy opiekun
 * niezalogowany) w wyglądzie FEER (portal: _style, _header, _footer).
 * Tu zostaje logowanie bez hasła (kod SMS na telefon opiekuna): _op = otp_request | otp_verify | choose (obsługa w parent.php).
 * Login i hasło (rodzic, osoba upoważniona) — wspólne logowanie TI (karty30/ti/login.php). Wejście: $stage (phone|code|choose),
 * $err, $info, $org.
 */
$ppTitle = 'Panel Kursanta SSO'; $ppOrg = $org; $ppCrumb = 'Panel rodzica'; $ppLogo = ''; $ppLoginUrl = '/login.php'; $ppAccount = null;
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
  <?php if ($err): ?><div class="pp-error" role="alert"><?= h($err) ?></div><?php endif; ?>
  <?php if ($info): ?><div class="pp-ok" role="status"><?= h($info) ?></div><?php endif; ?>

  <div class="pp-cols">
    <div>
    <?php if ($stage === 'choose'): $kids = $_SESSION['k30_parent_choose'] ?? []; ?>
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
      <?php $_plUrl = tiPanelUrl('ti', 'login.php'); ?>
      <ul class="pp-tabs" aria-label="Sposób logowania" style="background:transparent;padding:0">
        <li><a href="parent.php" aria-current="page"><i class="bi bi-chat-dots" aria-hidden="true"></i> Kod SMS</a></li>
        <li><a href="<?= h($_plUrl) ?>?tab=rodzic"><i class="bi bi-person-lock" aria-hidden="true"></i> Login i hasło</a></li>
        <li><a href="<?= h($_plUrl) ?>?tab=up"><i class="bi bi-person-check" aria-hidden="true"></i> Osoba upoważniona</a></li>
      </ul>
      <?php if ($stage === 'code'): ?>
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
      <a class="pp-alt" href="<?= h(tiPanelUrl('ti', 'login.php')) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Logowanie loginem i hasłem</a>
      <p>Jedno logowanie dla uczestnika, rodzica (login z końcówką „-r”), osoby upoważnionej i kadry.</p>
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
