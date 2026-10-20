<?php
/**
 * karty30/ti/dydaktyk/zaproszenieKonsultanta.php — publiczna strona zaproszenia konsultanta zewnętrznego
 * (modules/ti_consultant_access). Link z tokenem od kierownika → osoba sama ustawia hasło → konto panelu
 * tylko w trybie Konsultacje → logowanie e-mailem i hasłem na stronie logowania TI, przy pierwszym wejściu kod TOTP.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_consultant_access/logic/consultantAccess.php';
require_once dirname(__DIR__, 3) . '/modules/ti_login/logic/TiLogin.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$inv   = TiConsultantAccess::inviteFind($token);
$error = ''; $done = false;

if ($inv && $_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $r = TiConsultantAccess::inviteAccept($inv, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
    if ($r['ok']) $done = true; else $error = (string)$r['error'];
}

$_tok = dyd_token();   // przed wyjściem — otwiera sesję panelu (bez ostrzeżeń „headers already sent”)
$_org = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'FEER'));
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Konto konsultanta — Panel TI — <?= h($_org) ?></title>
<meta name="robots" content="noindex,nofollow">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include dirname(__DIR__, 3) . '/modules/ti/view/portal/_style.php'; /* szata METRO jak logowanie TI */ ?>
<style>.zk-lead { font-size: 1.08rem; margin: -.3rem 0 1.4rem; color: var(--pp-muted); max-width: 46rem; }</style>
</head>
<body class="pp">
<?php
$ppTitle = 'Panel TI'; $ppOrg = $_org; $ppCrumb = 'Konto konsultanta'; $ppLogo = ''; $ppLoginUrl = TiLogin::url(); $ppAccount = null;
include dirname(__DIR__, 3) . '/modules/ti/view/portal/_header.php';
?>
<main id="pp-main" class="pp-wrap" tabindex="-1">
  <h1 class="pp-h1">Konto konsultanta</h1>
  <?php if ($done): ?>
    <div class="pp-ok" role="status">Konto gotowe. Zaloguj się adresem <strong><?= h($inv['email']) ?></strong> i ustawionym hasłem.
      Przy pierwszym wejściu skonfigurujesz kod z aplikacji uwierzytelniającej (np. Microsoft Authenticator).</div>
    <p><a class="pp-submit d-inline-flex align-items-center text-decoration-none" href="<?= h(TiLogin::url()) ?>">Przejdź do logowania</a></p>
  <?php elseif (!$inv): ?>
    <div class="pp-error" role="alert">Link zaproszenia jest nieważny — wygasł, został już użyty albo cofnięty. Poproś osobę, która Cię zaprosiła, o nowy link.</div>
    <p><a href="<?= h(TiLogin::url()) ?>">Strona logowania</a></p>
  <?php else: ?>
    <p class="zk-lead">Dzień dobry, <strong><?= h($inv['name']) ?></strong>. Zapraszamy Cię do panelu konsultacji jako konsultant(kę).
      Ustaw hasło — loginem będzie adres <strong><?= h($inv['email']) ?></strong>.</p>
    <?php if ($error !== ''): ?><div class="pp-error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <div class="pp-cols">
      <div>
        <form method="post" autocomplete="off">
          <input type="hidden" name="_token" value="<?= h($_tok) ?>"><input type="hidden" name="t" value="<?= h($token) ?>">
          <input type="email" class="visually-hidden" name="username" value="<?= h($inv['email']) ?>" autocomplete="username" tabindex="-1" aria-hidden="true">
          <div class="pp-field"><label for="zk-p1">Hasło (min. <?= TiConsultantAccess::MIN_PASS ?> znaków)</label>
            <input class="pp-input" type="password" id="zk-p1" name="password" required minlength="<?= TiConsultantAccess::MIN_PASS ?>" maxlength="72" autocomplete="new-password"></div>
          <div class="pp-field"><label for="zk-p2">Powtórz hasło</label>
            <input class="pp-input" type="password" id="zk-p2" name="password2" required maxlength="72" autocomplete="new-password"></div>
          <button class="pp-submit">Załóż konto</button>
        </form>
      </div>
      <aside class="pp-note">
        <p style="margin-top:0"><strong>Co dalej?</strong></p>
        <p>Dostęp obejmuje tylko konsultacje: karty, rezerwacje terminów, klientów.</p>
        <p style="margin-bottom:0">Link jest ważny do <?= h(date('d.m.Y H:i', strtotime((string)$inv['expires_at']))) ?> i działa jeden raz.</p>
      </aside>
    </div>
  <?php endif; ?>
</main>
<?php
$ppFootLinks = ['Logowanie TI' => TiLogin::url(), 'Strona Fundacji FEER' => 'https://feer.org.pl'];
include dirname(__DIR__, 3) . '/modules/ti/view/portal/_footer.php';
?>
</body>
</html>
