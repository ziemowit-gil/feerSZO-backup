<?php
/**
 * karty30/ti/sso.php — strona informacyjna „Czym jest SSO” (bez logowania) w wyglądzie portalu FEER
 * (modules/ti/view/portal: nagłówek z logo, pasek ułatwień dostępu, menu, stopka).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
$_org = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja FEER'));
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Czym jest SSO — Panel zajęć — <?= h($_org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include dirname(__DIR__, 2) . '/modules/ti/view/portal/_style.php'; ?>
<style>
  .ss-lead { font-size: 1.1rem; color: var(--pp-muted); margin: 0 0 1.5rem; max-width: 46rem; }
  .ss-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; margin-top: 1rem; }
  .ss-card { border: 1px solid var(--pp-line); border-radius: var(--pp-radius); padding: 1rem 1.2rem; }
  .ss-card h2 { font-size: 1.1rem; margin: 0 0 .5rem; display: flex; gap: .5rem; align-items: baseline; }
  .ss-card h2 .bi { color: var(--pp-blue); }
  .ss-card p, .ss-card li { margin: 0 0 .45rem; }
  .ss-card ul { padding-left: 1.1rem; margin: 0; }
  .ss-warn { background: #fff4e5; border-left: 5px solid #e8890c; border-radius: .8rem; padding: .9rem 1.1rem; margin-top: 1.4rem; }
  .ss-steps { counter-reset: s; list-style: none; padding: 0; margin: 0; display: grid; gap: .7rem; }
  .ss-steps li { counter-increment: s; display: flex; gap: .7rem; align-items: flex-start; }
  .ss-steps li::before { content: counter(s); flex: none; width: 1.8rem; height: 1.8rem; border-radius: 50%; background: var(--pp-blue); color: #fff;
                         display: grid; place-items: center; font-weight: 700; font-size: .9rem; }
</style>
</head>
<body class="pp">
<?php
$ppTitle = 'Panel zajęć'; $ppOrg = $_org; $ppCrumb = 'Czym jest SSO?'; $ppLogo = ''; $ppLoginUrl = '/login.php'; $ppAccount = null;
include dirname(__DIR__, 2) . '/modules/ti/view/portal/_header.php';
?>
<main id="pp-main" class="pp-wrap">
  <h1 class="pp-h1">Czym jest SSO?</h1>
  <p class="ss-lead"><strong>SSO</strong> (Single Sign-On) to jedno logowanie do wielu systemów: dane wpisujesz raz, a kolejne systemy nie pytają już o hasło.</p>

  <div class="ss-grid">
    <section class="ss-card" aria-labelledby="ss-now">
      <h2 id="ss-now"><i class="bi bi-info-circle" aria-hidden="true"></i> Co działa teraz?</h2>
      <p>Logowanie odbywa się przez jedną stronę — wybierasz, kim jesteś (kursant, rodzic, osoba upoważniona, kandydat, prowadzący), a System Tożsamości sprawdza Twoje dane.</p>
      <p>Każde konto ma swoje hasło, więc do niektórych systemów nadal logujesz się osobno. Pełne SSO nie jest jeszcze wdrożone.</p>
    </section>

    <section class="ss-card" aria-labelledby="ss-how">
      <h2 id="ss-how"><i class="bi bi-person-lock" aria-hidden="true"></i> Jak loguje się kursant?</h2>
      <ol class="ss-steps">
        <li><span><strong>Pierwsze logowanie:</strong> wpisujesz login — kod przychodzi SMS-em na Twój telefon.</span></li>
        <li><span><strong>Obrazek bezpieczeństwa:</strong> po wpisaniu loginu pojawia się Twój obrazek z opisem dla czytnika ekranu.</span></li>
        <li><span><strong>Hasło:</strong> po pierwszym logowaniu ustawiasz własne hasło (albo dodajesz klucz bezpieczeństwa).</span></li>
      </ol>
    </section>

    <section class="ss-card" aria-labelledby="ss-why">
      <h2 id="ss-why"><i class="bi bi-shield-check" aria-hidden="true"></i> Dlaczego to ma znaczenie?</h2>
      <ul>
        <li>Jedno miejsce logowania dla wszystkich ról.</li>
        <li>Obrazek pomaga sprawdzić, że strona jest prawdziwa.</li>
        <li>Administratorzy zarządzają kontami z jednego miejsca.</li>
      </ul>
    </section>
  </div>

  <div class="ss-warn" role="note"><strong><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Uwaga:</strong> jeśli nie widzisz swojego obrazka albo widzisz inny, nie wpisuj hasła i skontaktuj się z placówką.</div>

  <div class="pp-links" style="margin-top:1.8rem">
    <a class="pp-submit" href="/login.php" style="color:#fff">Wróć do logowania</a>
    <a href="/karty30/ti/nowosci.php">Co nowego w panelu</a>
  </div>
</main>
<?php
$ppFootLinks = ['Logowanie' => '/login.php', 'Co nowego w panelu' => '/karty30/ti/nowosci.php', 'Konto kandydata' => '/nabor/logowanie', 'Strona Fundacji FEER' => 'https://feer.org.pl'];
include dirname(__DIR__, 2) . '/modules/ti/view/portal/_footer.php';
?>
</body>
</html>
