<?php
/**
 * crm/mobile/index.php — „Dzwoń": mobilny dialer kontaktów CRM (PWA).
 *
 * Jeden ekran: szukaj → naciśnij → rozmowa. Po powrocie z połączenia aplikacja
 * pyta o wynik i zapisuje go jako aktywność „call" w kartotece kontaktu.
 *
 * Dostęp identyczny jak w całym CRM (logowanie + crm_enabled + IKA + uprawnienia)
 * — patrz crm_mobile_guard() w includes/mobile.php.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/mobile.php';

crm_mobile_guard();

require_once dirname(__DIR__, 2) . '/includes/mobile_auth.php';

$can_write = can_write('crm') || is_admin();
// Sesja z PIN-u nie ma dostępu do reszty CRM — zamiast linku „wróć do CRM"
// dajemy jej wyjście z sesji.
$pin_only  = mobile_session_is_scoped();
$base      = crm_mobile_base();   // /crm/mobile lub krótki alias /mobilna
$asset_v   = ['css' => (int)@filemtime(__DIR__ . '/assets/app.css'),
              'js'  => (int)@filemtime(__DIR__ . '/assets/app.js')];

/** Wstawka SVG z zestawu ikon aplikacji (bez zależności od Bootstrap Icons). */
function d_icon(string $name): string
{
    static $paths = [
        'back'   => 'M15.7 3.3a1 1 0 0 1 0 1.4L8.4 12l7.3 7.3a1 1 0 1 1-1.4 1.4l-8-8a1 1 0 0 1 0-1.4l8-8a1 1 0 0 1 1.4 0z',
        'search' => 'M10.5 3a7.5 7.5 0 1 0 4.55 13.46l4.24 4.25a1 1 0 0 0 1.42-1.42l-4.25-4.24A7.5 7.5 0 0 0 10.5 3zm0 2a5.5 5.5 0 1 1 0 11 5.5 5.5 0 0 1 0-11z',
        'close'  => 'M6.2 4.8 4.8 6.2 10.6 12l-5.8 5.8 1.4 1.4L12 13.4l5.8 5.8 1.4-1.4L13.4 12l5.8-5.8-1.4-1.4L12 10.6z',
        'phone'  => 'M6.6 3h-.2C5 3 3.8 4.1 3.6 5.5c-.5 3.6 1.2 7.6 4 10.4 2.8 2.8 6.8 4.5 10.4 4 1.4-.2 2.5-1.4 2.5-2.8v-1.6c0-.9-.6-1.7-1.5-1.9l-2.6-.6a2 2 0 0 0-2 .7l-.7.9a12.4 12.4 0 0 1-4.3-4.3l.9-.7c.6-.5.9-1.3.7-2l-.6-2.6A2 2 0 0 0 8.5 3H6.6z',
        'sms'    => 'M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9.4l-4.1 3.3A1 1 0 0 1 3.7 19.5V17H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm3 5a1.2 1.2 0 1 0 0 2.4A1.2 1.2 0 0 0 7 9zm5 0a1.2 1.2 0 1 0 0 2.4A1.2 1.2 0 0 0 12 9zm5 0a1.2 1.2 0 1 0 0 2.4A1.2 1.2 0 0 0 17 9z',
        'refresh'=> 'M12 4a8 8 0 0 1 7.4 5 1 1 0 1 1-1.85.76A6 6 0 0 0 6.7 9.6l1.6.1a1 1 0 1 1-.14 2l-4-.3A1 1 0 0 1 3.2 10.4l.3-4a1 1 0 1 1 2 .15l-.07.98A8 8 0 0 1 12 4zm7.8 7.8a1 1 0 0 1 .93 1.07l-.3 4a1 1 0 1 1-2-.15l.07-.98A8 8 0 0 1 4.6 15a1 1 0 0 1 1.85-.76A6 6 0 0 0 17.3 14.4l-1.6-.1a1 1 0 1 1 .14-2l4 .3z',
        'nores'  => 'M10.5 3a7.5 7.5 0 0 1 5.96 12.04l4.25 4.25a1 1 0 0 1-1.42 1.42l-4.24-4.25A7.5 7.5 0 1 1 10.5 3zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11zm-.7 2.3h1.4v4h-1.4zm0 5.2h1.4v1.4H9.8z',
        'wifi'   => 'M2.3 5.5a1 1 0 0 1 1.4-.1 13 13 0 0 1 16.6 0 1 1 0 0 1-1.3 1.5 11 11 0 0 0-14 0 1 1 0 0 1-1.4-.1zm3 3.4a1 1 0 0 1 1.4-.2 8 8 0 0 1 10.6 0 1 1 0 0 1-1.3 1.5 6 6 0 0 0-8 0 1 1 0 0 1-1.4-.1zM12 15a2 2 0 1 1 0 4 2 2 0 0 1 0-4z',
        'logout' => 'M10 3a1 1 0 0 1 0 2H6a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h4a1 1 0 1 1 0 2H6a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h4zm5.3 3.3 4.4 4.4a1 1 0 0 1 0 1.4l-4.4 4.4a1 1 0 1 1-1.4-1.4l2.7-2.7H10a1 1 0 1 1 0-2h6.6l-2.7-2.7a1 1 0 1 1 1.4-1.4z',
    ];
    $p = $paths[$name] ?? '';
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $p . '"/></svg>';
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#2E844A" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1F1F1F" media="(prefers-color-scheme: dark)">
<meta name="robots" content="noindex, nofollow">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Dzwoń">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>Dzwoń — kontakty CRM</title>
<link rel="manifest" href="<?= h($base) ?>/manifest.php">
<link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/img/icon-192.svg">
<link rel="stylesheet" href="<?= h($base) ?>/assets/app.css?v=<?= $asset_v['css'] ?>">
</head>
<body>

<header class="d-head">
  <div class="d-head-row">
    <?php if ($pin_only): ?>
      <a class="d-iconbtn" href="<?= APP_URL ?>/auth/logout.php" aria-label="Wyloguj"><?= d_icon('logout') ?></a>
    <?php else: ?>
      <a class="d-iconbtn" href="<?= APP_URL ?>/crm/dashboard.php" aria-label="Wróć do CRM"><?= d_icon('back') ?></a>
    <?php endif; ?>
    <h1 class="d-title">Dzwoń</h1>
    <button type="button" class="d-iconbtn" id="dRefresh" aria-label="Odśwież listę kontaktów"><?= d_icon('refresh') ?></button>
  </div>

  <div class="d-search">
    <?= str_replace('<svg', '<svg class="d-search-ico"', d_icon('search')) ?>
    <input type="search" id="dQuery" inputmode="search" enterkeyhint="search"
           autocomplete="off" autocapitalize="off" spellcheck="false"
           placeholder="Nazwa, firma lub numer…" aria-label="Szukaj kontaktu">
    <button type="button" class="d-iconbtn d-search-clear" id="dClear" aria-label="Wyczyść wyszukiwanie" hidden><?= d_icon('close') ?></button>
  </div>

  <div class="d-tabs" role="tablist" aria-label="Zakres listy">
    <button type="button" class="d-tab" id="dTabRecent" role="tab" aria-selected="true"  data-mode="recent">Ostatnie</button>
    <button type="button" class="d-tab" id="dTabAll"    role="tab" aria-selected="false" data-mode="all">Wszystkie</button>
  </div>
</header>

<div class="d-banner" id="dBanner" role="status">
  <?= d_icon('wifi') ?><span id="dBannerText"></span>
</div>

<main>
  <ul class="d-list" id="dList" aria-live="polite" aria-busy="true">
    <li class="d-skeleton"></li>
    <li class="d-skeleton"></li>
    <li class="d-skeleton"></li>
  </ul>
  <noscript>
    <p class="d-empty">Ten widok wymaga włączonego JavaScriptu.
       Lista kontaktów jest też dostępna w <a href="<?= APP_URL ?>/crm/index.php">pełnym CRM</a>.</p>
  </noscript>
</main>

<!-- Arkusz „jak poszło" — pokazywany po powrocie z połączenia -->
<div class="d-sheet-backdrop" id="dSheetBackdrop" hidden></div>
<section class="d-sheet" id="dSheet" role="dialog" aria-modal="true" aria-labelledby="dSheetTitle" hidden>
  <div class="d-sheet-grip"></div>
  <h2 id="dSheetTitle">Jak poszło?</h2>
  <p class="d-sheet-sub" id="dSheetSub"></p>

  <div class="d-outcomes" id="dOutcomes">
    <button type="button" class="d-outcome" data-outcome="answered"  aria-pressed="false">Odebrał</button>
    <button type="button" class="d-outcome" data-outcome="no_answer" aria-pressed="false">Nie odebrał</button>
    <button type="button" class="d-outcome" data-outcome="callback"  aria-pressed="false">Oddzwonić</button>
    <button type="button" class="d-outcome" data-outcome="wrong"     aria-pressed="false">Błędny numer</button>
  </div>

  <label class="d-sr" for="dNote">Notatka z rozmowy</label>
  <textarea id="dNote" placeholder="Notatka (opcjonalnie)…" maxlength="2000"></textarea>

  <div class="d-sheet-foot">
    <button type="button" class="d-btn" id="dSkip">Pomiń</button>
    <button type="button" class="d-btn d-btn-primary" id="dSave">Zapisz</button>
  </div>
</section>

<div class="d-toast" id="dToast" role="status" aria-live="polite"></div>

<script>
window.D_CFG = {
    base:     <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>,
    crmBase:  <?= json_encode(APP_URL . '/crm', JSON_UNESCAPED_SLASHES) ?>,
    csrf:     <?= json_encode(csrf_token()) ?>,
    canWrite: <?= $can_write ? 'true' : 'false' ?>,
    icons: {
        phone:  <?= json_encode(d_icon('phone'),  JSON_UNESCAPED_SLASHES) ?>,
        sms:    <?= json_encode(d_icon('sms'),    JSON_UNESCAPED_SLASHES) ?>,
        nores:  <?= json_encode(d_icon('nores'),  JSON_UNESCAPED_SLASHES) ?>
    }
};
</script>
<script src="<?= h($base) ?>/assets/app.js?v=<?= $asset_v['js'] ?>" defer></script>

</body>
</html>
