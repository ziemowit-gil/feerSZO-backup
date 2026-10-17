<?php
/**
 * modules/ti/view/portal/_header.php — nagłówek portalu TI w stylu METRO: cienki biały pasek z logo i tytułem,
 * po prawej „Ułatwienia dostępu” (rozwijane), konto kandydata i logowanie / wylogowanie.
 * Wejście: $ppTitle, $ppOrg, $ppLogo (URL albo '' = z ustawień), $ppCrumb (podtytuł strony — tylko w <title>/okruszkach
 * niewidocznych), $ppAccount (zalogowany kandydat albo null), $ppCsrf, $ppLoginUrl (domyślnie /login.php).
 * Adresy względne — zostają na bieżącym hoście (kursant.*, ti.*, szo.*).
 */
$ppLoginUrl = $ppLoginUrl ?? '/login.php';
if (empty($ppLogo)) {
    try {
        if (!function_exists('branding_load')) require_once dirname(__DIR__, 4) . '/includes/branding.php';
        $ppLogo = (string)(branding_load()['logo_url'] ?? '');
    } catch (\Throwable $e) { $ppLogo = ''; }
}
// Adres względny do /assets/… — na kursant.* i ti.* katalog assets jest wyłączony z przepisywania
if ($ppLogo !== '') $ppLogo = (string)(parse_url($ppLogo, PHP_URL_PATH) ?: $ppLogo);
?>
<a class="pp-skip" href="#pp-main">Przejdź do treści</a>
<header class="pp-top">
  <div class="pp-wrap pp-top-in">
    <a class="pp-brand" href="<?= h($ppLoginUrl) ?>">
      <?php if (!empty($ppLogo)): ?><img src="<?= h($ppLogo) ?>" alt="FEER — Fundacja Edukacji Empatii Rozwoju"><span class="pp-title-sep" aria-hidden="true"></span>
      <?php else: ?><span class="pp-mark" aria-hidden="true">FEER</span><?php endif; ?>
      <span class="pp-title"><?= h($ppTitle) ?></span>
    </a>
    <nav class="pp-top-nav" aria-label="Menu">
      <details class="pp-a11y-menu">
        <summary><i class="bi bi-universal-access" aria-hidden="true"></i> <span>Ułatwienia dostępu</span></summary>
        <div class="pp-a11y-pop"><?php include __DIR__ . '/_a11y.php'; ?></div>
      </details>
      <?php if (!empty($ppAccount)): ?>
        <a class="pp-top-link" href="/nabor/konto"><i class="bi bi-person-badge" aria-hidden="true"></i> Moje konto</a>
        <form method="post" action="/nabor/wyloguj" class="pp-top-form"><input type="hidden" name="_csrf" value="<?= h($ppCsrf ?? '') ?>"><button class="pp-btn-log">Wyloguj</button></form>
      <?php else: ?>
        <a class="pp-top-link" href="/nabor/logowanie"><i class="bi bi-person-badge" aria-hidden="true"></i> Konto kandydata</a>
        <a class="pp-btn-acc" href="/nabor/rejestracja">Załóż konto</a>
        <a class="pp-btn-log" href="<?= h($ppLoginUrl) ?>">Zaloguj</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
