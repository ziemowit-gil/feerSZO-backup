<?php
/**
 * modules/it_portal/public/_layout.php — wspólny szkielet stron publicznych Portalu Usług IT.
 *
 * itPortalPageStart($title, $active) / itPortalPageEnd(). Bez logowania, bez Bootstrapa —
 * własny arkusz portal.css (wersjonowany filemtime, żeby nie trafić na stary cache).
 * WCAG 2.1 AA: link „Przejdź do treści”, aria-current w menu, widoczny fokus, kontrast ≥ 4.5:1.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__) . '/logic/itPortal.php';

itPortalMigrate();
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function itPortalPageStart(string $title, string $active = '', string $description = ''): void {
    $siteTitle = itPortalSetting('title');
    $subtitle  = itPortalSetting('subtitle') ?: ORG_NAME;
    $logo      = itPortalSetting('logo_url');
    $css       = APP_URL . '/modules/it_portal/public/portal.css?v=' . @filemtime(__DIR__ . '/portal.css');
    $q         = (string)($_GET['q'] ?? '');
    $nav = [
        'start'      => ['Start', itPortalUrl()],
        'uslugi'     => ['Usługi', itPortalUrl('uslugi')],
        'komunikaty' => ['Komunikaty', itPortalUrl('komunikaty')],
        'kontakt'    => ['Kontakt', itPortalUrl('kontakt')],
    ];
    ?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title !== '' ? $title . ' — ' . $siteTitle : $siteTitle . ' — ' . $subtitle) ?></title>
<?php if ($description !== ''): ?><meta name="description" content="<?= h(itPortalExcerpt($description, 160)) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600&family=Cormorant+SC:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h($css) ?>">
<link rel="icon" href="<?= h(APP_URL) ?>/assets/img/icon-192.svg">
</head>
<body>
<a class="itp-skip" href="#tresc">Przejdź do treści</a>
<header class="itp-brand">
  <div class="itp-wrap">
    <a class="itp-brand__link" href="<?= h(itPortalUrl()) ?>">
      <?php if ($logo !== ''): ?>
        <img class="itp-brand__logo" src="<?= h($logo) ?>" alt="">
      <?php else: ?>
        <svg class="itp-brand__logo" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
          <g fill="none" stroke-width="5"><circle cx="20" cy="16" r="7" stroke="#19b3c4"/><circle cx="44" cy="30" r="7" stroke="#8c8178"/><circle cx="18" cy="46" r="7" stroke="#3a5fae"/></g>
          <path d="M25 20l13 7M38 34 24 42" stroke="#3a5fae" stroke-width="5" stroke-linecap="round"/>
        </svg>
      <?php endif; ?>
      <span class="itp-brand__text">
        <span class="itp-brand__title"><?= h($siteTitle) ?></span>
        <span class="itp-brand__sub"><?= h($subtitle) ?></span>
      </span>
    </a>
  </div>
</header>
<nav class="itp-nav" aria-label="Menu portalu">
  <div class="itp-wrap itp-nav__inner">
    <ul class="itp-nav__list">
      <?php foreach ($nav as $key => [$label, $href]): ?>
        <li><a href="<?= h($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= h($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($active !== 'start'): ?>
    <form class="itp-nav__search" role="search" action="<?= h(itPortalUrl('szukaj')) ?>" method="get">
      <label class="itp-sr" for="itp-q-top">Szukaj na stronie</label>
      <input id="itp-q-top" type="search" name="q" value="<?= h($q) ?>" placeholder="Szukaj na stronie…" autocomplete="off">
      <button type="submit" aria-label="Szukaj"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="m15 15 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
    </form>
    <?php endif; ?>
  </div>
</nav>
<main id="tresc" class="itp-wrap itp-main" tabindex="-1">
<?php
}

function itPortalPageEnd(): void {
    $links = itPortalLinkLines(itPortalSetting('footer_links'));
    ?>
</main>
<footer class="itp-footer">
  <div class="itp-wrap itp-footer__inner">
    <?php if ($links): ?>
      <ul class="itp-footer__links">
        <?php foreach ($links as $l): ?>
          <li><?= $l['href'] ? '<a href="' . h($l['href']) . '">' . h($l['label']) . '</a>' : h($l['label']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="itp-footer__org">© <?= date('Y') ?> <?= h(ORG_NAME) ?></p>
  </div>
</footer>
</body>
</html>
<?php
}

/** Kropka statusu komunikatu + legenda (tekst dla czytników ekranu). */
function itPortalStatusDot(string $status): string {
    $s = ITP_NOTICE_STATUSES[$status] ?? ITP_NOTICE_STATUSES['info'];
    return '<span class="itp-dot" style="background:' . h($s['color']) . '" title="' . h($s['label']) . '"></span>'
         . '<span class="itp-sr">Status: ' . h($s['label']) . '. </span>';
}

function itPortalNotFound(string $msg = 'Nie znaleźliśmy tej strony.'): void {
    http_response_code(404);
    itPortalPageStart('Nie znaleziono', '');
    echo '<div class="itp-empty"><h1>Nie znaleziono</h1><p>' . h($msg) . '</p><p><a href="' . h(itPortalUrl('uslugi')) . '">Przejdź do listy usług</a></p></div>';
    itPortalPageEnd();
    exit;
}
