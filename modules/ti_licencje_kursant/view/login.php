<?php
if (!isset($org)) { header('Location: /licencje/', true, 302); exit; }   // widok tylko przez router modułu
/**
 * modules/ti_licencje_kursant/view/login.php — logowanie do panelu licencji (jak Portal Kontrahenta): login „l” + 8 cyfr i hasło.
 * Zmienne: $org, $err, $old, $flash, $adminOn, $adminSzo, $adminMs (logowanie Administratora Licencji przez Microsoft 365 / SZO).
 */
$PAGE_TITLE = 'Logowanie do panelu licencji';
include __DIR__ . '/_top.php';
?>
<style>
  /* dwa panele (polecenie 2026-11-11): po lewej, do czego służy panel; po prawej logowanie */
  .lk-split { display: grid; grid-template-columns: 1fr; gap: 24px; max-width: 1060px; margin: 0 auto; }
  .lk-split > * { min-width: 0; }
  @media (max-width: 600px) { .lk-intro { padding: 24px 20px; } .lk-intro h1 { font-size: 1.6rem; } }
  @media (min-width: 860px) { .lk-split { grid-template-columns: 1fr 1fr; align-items: stretch; } }
  .lk-intro { background: #1d1d1d; color: #fff; border-top: 4px solid var(--mt-accent); padding: 32px 32px 28px; display: flex; flex-direction: column; }
  .lk-intro h1 { font-size: 2rem; font-weight: 300; margin: 0 0 14px; line-height: 1.2; }
  .lk-intro .lk-lead { font-size: 1.05rem; line-height: 1.55; margin: 0 0 18px; color: #f3f4f6; }
  .lk-intro ul { list-style: none; margin: 0 0 24px; padding: 0; }
  .lk-intro li { position: relative; padding: 10px 0 10px 30px; border-top: 1px solid #3a3a3a; line-height: 1.5; }
  .lk-intro li:last-child { border-bottom: 1px solid #3a3a3a; }
  .lk-intro li::before { content: ""; position: absolute; left: 4px; top: 16px; width: 12px; height: 7px; border-left: 2px solid var(--mt-accent); border-bottom: 2px solid var(--mt-accent); transform: rotate(-45deg); }
  .lk-intro .lk-help { margin-top: auto; font-size: .92rem; line-height: 1.55; color: #e5e5e5; }
  .lk-intro .lk-help p { margin: 0 0 8px; }
  .lk-intro a { color: #fff; text-decoration: underline; }
  html[data-contrast="high"] .lk-intro { background: #000; border: 2px solid #ffe600; }
  html[data-contrast="high"] .lk-intro a { color: #ffe600; }
  .lk-side .m-tile:last-child { margin-bottom: 0; }
</style>
<div class="lk-split">
  <section class="lk-intro" aria-labelledby="lk-intro-h">
    <h1 id="lk-intro-h">Panel Licencji</h1>
    <p class="lk-lead">Panel Licencji to jedno miejsce na programy i usługi, które masz dzięki Fundacji FEER. Tutaj:</p>
    <ul>
      <li>sprawdzisz swoje licencje i konto Office, a gdy go nie masz, założysz je,</li>
      <li>złożysz wniosek o Claude, Office z aplikacjami na komputer albo Dysk Online,</li>
      <li>zapłacisz online albo przelewem, pobierzesz proformy i faktury, podepniesz kartę,</li>
      <li>zobaczysz swoje sprawy licencyjne i odpiszesz na wiadomości od nas.</li>
    </ul>
    <div class="lk-help">
      <p>Login i hasło wysyła biuro: login e-mailem, hasło SMS-em. Jeśli ich nie masz albo nie pamiętasz hasła, napisz do biura.</p>
      <p>Uczestnik zajęć TI może też wejść z <a href="/karty30/ti/login.php">panelu uczestnika</a> (zakładka Licencje) bez drugiego logowania.</p>
    </div>
  </section>
  <div class="lk-side">
    <section class="m-tile" aria-labelledby="lk-login-h">
      <h2 class="m-head" id="lk-login-h">Zaloguj się</h2>
      <?php if ($err !== ''): ?><div class="pk-alert pk-alert-err" role="alert" id="blad"><?= h($err) ?></div><?php endif; ?>
      <form method="post" action="<?= h(route('lk.login.post')) ?>" novalidate>
        <?= Router::csrfField() ?>
        <div class="tw-mb-4"><label class="m-label" for="l-login">Numer w panelu licencji (litera l i 8 cyfr)</label>
          <input class="m-input tw-font-mono" id="l-login" name="login" value="<?= h($old) ?>" autocomplete="username" placeholder="l12345678" inputmode="text"<?= $err !== '' ? ' aria-invalid="true" aria-describedby="blad"' : '' ?>></div>
        <div class="tw-mb-4"><label class="m-label" for="l-pass">Hasło</label>
          <input class="m-input" type="password" id="l-pass" name="password" autocomplete="current-password"<?= $err !== '' ? ' aria-invalid="true" aria-describedby="blad"' : '' ?>></div>
        <button class="m-btn" type="submit">Zaloguj się</button>
      </form>
    </section>
    <section class="m-tile" aria-labelledby="adm-h">
      <h2 class="m-sect" id="adm-h">Dla kadry: Administrator Licencji</h2>
      <?php if (!empty($adminOn)): ?>
        <p class="tw-text-sm tw-mb-2">Jesteś zalogowany jako Administrator Licencji.</p>
        <a class="m-btn" href="<?= h(route('lk.admin')) ?>">Przejdź do części administratora</a>
      <?php else: ?>
        <p class="tw-text-sm tw-mb-3">Zaloguj się kontem służbowym. Wejście mają osoby z rolą Administrator Licencji w SZO. Logowanie odbywa się w SZO, z kluczem albo kodem jak zwykle, a potem wrócisz tutaj.</p>
        <div class="tw-flex tw-flex-wrap tw-gap-2">
          <a class="m-btn" href="<?= h($adminMs) ?>">Zaloguj przez Microsoft 365</a>
          <a class="m-btn-ghost" href="<?= h($adminSzo) ?>">Zaloguj przez SZO</a>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
