<?php
/**
 * modules/ti/view/portal/_header.php — nagłówek portalu (tytuł, ZAŁÓŻ KONTO / ZALOGUJ, nawigacja, okruszki).
 * Wejście: $ppTitle (np. „Panel zajęć”), $ppOrg, $ppLogo (URL albo ''), $ppCrumb (ostatni element okruszków),
 *          $ppAccount (zalogowany kandydat albo null), $ppLoginUrl (domyślnie /login.php).
 * Adresy względne — zostają na bieżącym hoście (kursant.*, ti.*, szo.*).
 */
$ppLoginUrl = $ppLoginUrl ?? '/login.php';
$ppOpen = function_exists('org_setting') && org_setting('ti_nabor_enabled') === '1';
?>
<a class="pp-skip" href="#pp-main">Przejdź do treści</a>
<div class="pp-wrap">
  <div class="pp-util" aria-label="Narzędzia">
    <a href="https://feer.org.pl/deklaracja-dostepnosci/">Dostępność</a>
    <a href="https://feer.org.pl">feer.org.pl</a>
  </div>
  <header class="pp-head">
    <a class="pp-brand" href="<?= h($ppLoginUrl) ?>">
      <?php if (!empty($ppLogo)): ?><img src="<?= h($ppLogo) ?>" alt=""><?php else: ?><span class="pp-mark" aria-hidden="true">FEER</span><?php endif; ?>
      <span class="pp-title"><?= h($ppTitle) ?><small><?= h($ppOrg) ?></small></span>
    </a>
    <div class="pp-cta">
      <?php if (!empty($ppAccount)): ?>
        <a class="pp-btn-acc" href="/nabor/konto"><i class="bi bi-person-badge" aria-hidden="true"></i> Moje konto</a>
        <form method="post" action="/nabor/wyloguj" style="margin:0;display:flex"><input type="hidden" name="_csrf" value="<?= h($ppCsrf ?? '') ?>"><button class="pp-btn-log" style="cursor:pointer;font-family:inherit">Wyloguj</button></form>
      <?php else: ?>
        <a class="pp-btn-acc" href="/nabor/rejestracja">Załóż konto</a>
        <a class="pp-btn-log" href="<?= h($ppLoginUrl) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Zaloguj</a>
      <?php endif; ?>
    </div>
  </header>
  <nav class="pp-nav" aria-label="Menu główne">
    <div class="pp-menu">
      <a href="<?= h($ppLoginUrl) ?>"><i class="bi bi-house" aria-hidden="true"></i> Panel zajęć</a>
      <?php if ($ppOpen): ?><a href="/nabor">Zapisy na zajęcia</a><?php endif; ?>
      <a href="/nabor/logowanie">Konto kandydata</a>
      <a href="https://feer.org.pl/kontakt/">Kontakt</a>
    </div>
  </nav>
  <div class="pp-crumb"><a href="<?= h($ppLoginUrl) ?>" style="text-decoration:none"><?= h($ppTitle) ?></a> › <span><?= h($ppCrumb) ?></span></div>
</div>
