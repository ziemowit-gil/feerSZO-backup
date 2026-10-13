<?php
/**
 * karty30/ti/dydaktyk/_kierownik_bar.php — MENU BOCZNE ekranów kierownika.
 *
 * Sidebar: na szerokich ekranach przyklejona kolumna po lewej (main.dyd-wrap
 * dostaje margines), na wąskich zwykły blok nad treścią ze zwijaną listą
 * (details). Używany w dwóch trybach:
 *   1) strony samodzielne (zetony.php, billing.php, okresy.php…) — domyślna
 *      lista pozycji, aktywna po dopasowaniu href do $KIER_CUR;
 *   2) osadzony z _usos_bar.php (zakładki kierownika w index.php) — pozycje
 *      przychodzą w $KIER_ITEMS (etykieta/adres/ikona/licznik/aktywna/blank),
 *      a $KIER_EMBED=true chowa grupę „Sekcje panelu” i okruszki (index ma
 *      własny pasek sekcji i .skin-crumbs).
 *
 * Zmienne wejściowe: $KIER_CUR — plik bieżącej strony (np. 'zetony.php'),
 *                    $KIER_LABEL — nazwa strony do okruszków,
 *                    $KIER_ITEMS — opcjonalne pozycje (tryb osadzony),
 *                    $KIER_EMBED — tryb osadzony (bool),
 *                    $KIER_FLAT  — pozycje jedną listą, bez grup (sekcje panelu inne niż Kierownik,
 *                                  gdy kierownik pracuje w roli „Kierownik Instytucji”),
 *                    $KIER_EXTRA_HTML — dodatkowy HTML pod listą (np. przyciski narzędzi sekcji).
 */
$KIER_CUR   = $KIER_CUR   ?? '';
$KIER_LABEL = $KIER_LABEL ?? '';
$KIER_EMBED = !empty($KIER_EMBED);
$KIER_FLAT  = !empty($KIER_FLAT);
$KIER_EXTRA_HTML = $KIER_EXTRA_HTML ?? '';
$KIER_TITLE = $KIER_TITLE ?? 'Kierownik';                       // nazwa menu (np. „Planista”)
$KIER_CRUMB = $KIER_CRUMB ?? ['Kierownik', 'index.php?tab=grupy']; // drugi okruszek

if (!empty($KIER_ITEMS)) {
    $_kier_norm = $KIER_ITEMS;
} else {
    /** [etykieta, adres, ikona bi-*, nowa karta?] — pełny zestaw ekranów kierownika */
    $_kier_defaults = [
        ['Przegląd grup',             'index.php?tab=grupy',        'people'],
        ['Podgląd klientów',          'klienci.php',                'person-lines-fill'],
        ['Konta kursantów',           'konta.php',                  'person-badge'],
        ['Microsoft 365',             'microsoft365.php',           'microsoft'],
        ['Licencje',                  'licencjeKursantow.php',      'key'],
        ['Rozliczenia grupy',         'index.php?tab=rozliczenia',  'receipt-cutoff'],
        ['Rozliczenia kursantów',     'billing.php',                'receipt'],
        ['Nadpłaty uczestników',      'overpayments.php',           'piggy-bank'],
        ['Przypomnienia o wpłatach',  'przypomnieniaWplat.php',     'cash-coin'],
        ['Cenniki i rabaty',          'pricing.php',                'tags'],
        ['Zaległe protokoły',         'protokoly.php',              'exclamation-octagon'],
        ['Audyt dzienników',          'audyt_dziennikow.php',       'clipboard2-check'],
        ['Hospitacje i wizytacje',    'hospitacje.php',             'clipboard2-pulse'],
        ['Program poleceń',          'polecenia.php',               'gift'],
        ['Zarządzanie kursami',       'index.php?tab=kursy',        'collection'],
        ['Grupy uproszczone (KON)',   'grupaUproszczona.php',       'lightning-charge'],
        ['Log operacji na grupach',   'log_grup.php',               'clock-history'],
        ['Wydruki i raporty',         'wydruki.php',                 'printer'],
        ['Linki do planów grup',      'planLinki.php',              'link-45deg'],
        ['Godziny prowadzących',      'godziny.php',                'hourglass-split'],
        ...(function_exists('dyd_can_analytics') && dyd_can_analytics() ? [['Analityka TI', 'analityka.php', 'speedometer2']] : []),
        ['Wypłaty prowadzących',      'index.php?tab=wypłaty',      'cash-stack'],
        ['Praca własna',              'index.php?tab=praca_wlasna', 'journal-text'],
        ['SMS i mailing do kursantów', 'index.php?tab=komunikacja', 'send'],
        ['Szablony e-mail',           'szablonyEmail.php',          'envelope-paper'],
        ['Szablony SMS',              'szablonySms.php',            'chat-left-text'],
        ['Żetony SZO',                'zetony.php',                 'ticket-detailed'],
        ['Zapisy na zajęcia',         'rekrutacja.php',             'ticket-perforated'],
        ['Dostępności prowadzących',  'dostepnosci.php',            'clock-history'],
        ['Urlopy prowadzących',       'urlopy.php',                 'airplane'],
        ['Planista zajęć',            'planista.php',               'calendar3-range'],
        ['Raporty planisty',          'raportyPlanisty.php',        'bar-chart-line'],
        ['Generator ciągów zajęć',    'generatorCiagow.php',        'magic'],
        ['Zgłoszenia trenerów',       'zgloszenia.php',             'inbox'],
        ['Limity i kwalifikacje kadry','kadraLimity.php',           'person-gear'],
        ['Sale / lokalizacje',        'sale.php',                   'geo-alt'],
        ['Okresy nauczania',          'okresy.php',                 'calendar-range'],
        ['Rodzaje zajęć',             'przedmioty.php',             'tags'],
        ['Dni wolne',                 'dni_wolne.php',              'calendar-x'],
        ['Raport odwołanych',         'raport_odwolanych.php',      'calendar-x'],
        ['Wyłączenia panelu',         'wylaczenia.php',             'moon'],
        ['Zespół i role',             'zespol.php',                 'person-gear'],
        ['Role w panelu TI',          APP_URL . '/tozsamosc/ti_role.php', 'person-gear'],
        ['Certyfikaty prowadzących',  'certyfikaty.php',            'shield-check'],
        ['Mail onboardingowy kursantów', 'kursanci_onboarding.php', 'envelope-paper'],
        ['Testy',                     'testy.php',                  'bug'],
        ['Pełny panel TI',            '../index.php',               'box-arrow-up-right',  true],
    ];
    $_kier_norm = [];
    foreach ($_kier_defaults as $_d) {
        $_kier_norm[] = ['label' => $_d[0], 'href' => $_d[1], 'icon' => $_d[2],
                         'n' => 0, 'v' => 'secondary', 'blank' => !empty($_d[3]),
                         'active' => ($_d[1] === $KIER_CUR)];
    }
}

// Sekcje menu kierownika (zwijane). Pozycja trafia do sekcji po adresie (href); nieznane — do „Inne”.
$_kier_grupy = [
    'Grupy i kursy'          => ['index.php?tab=grupy', 'index.php?tab=kursy', 'grupaUproszczona.php', 'index.php?tab=praca_wlasna', 'log_grup.php',
                                 'planLinki.php', 'godziny.php', 'dostepnosci.php', 'urlopy.php'],
    'Kursanci i konta'       => ['klienci.php', 'konta.php', 'microsoft365.php', 'licencjeKursantow.php',
                                 'rekrutacja.php', 'polecenia.php', 'kursanci_onboarding.php'],
    'Finanse'                => ['index.php?tab=rozliczenia', 'billing.php', 'overpayments.php', 'przypomnieniaWplat.php',
                                 'pricing.php', 'index.php?tab=wypłaty'],
    'Protokoły i raporty'    => ['protokoly.php', 'audyt_dziennikow.php', 'wydruki.php', 'analityka.php', 'raport_odwolanych.php'],
    'Planowanie'             => ['planista.php', 'raportyPlanisty.php', 'generatorCiagow.php', 'zgloszenia.php', 'kadraLimity.php',
                                 'sale.php', 'okresy.php', 'przedmioty.php', 'dni_wolne.php', 'wylaczenia.php'],
    'Komunikacja'            => ['index.php?tab=komunikacja', 'szablonyEmail.php', 'szablonySms.php'],
    'Ustawienia i narzędzia' => ['zetony.php', 'certyfikaty.php', 'zespol.php', APP_URL . '/tozsamosc/ti_role.php', 'testy.php', '../index.php'],
];
$_kier_map = [];
foreach ($_kier_grupy as $_g => $_hrefs) foreach ($_hrefs as $_h) $_kier_map[$_h] = $_g;
$_kier_by_grp = [];
foreach ($_kier_norm as $_i) {
    $_kier_by_grp[$_kier_map[$_i['href']] ?? 'Inne'][] = $_i;
}
$_kier_order = array_merge(array_keys($_kier_grupy), ['Inne']);

$_kier_sections = [
    ['Mój panel',    'index.php?tab=pulpit'],
    ['Kurs',         'index.php?tab=lekcje'],
    ['Komunikacja',  'index.php?tab=wiadomosci'],
    ['Zasoby',       'index.php?tab=dysk'],
];
?>
<style>
  /* Ten sam styl co pasek górny (ti_skin.css): granat, złota krawędź, białe
     pozycje, aktywna jako biała „zakładka” z niebieskim napisem, bez zaokrągleń.
     Każdy selektor ma bliźniaka z prefiksem body.ti-skin — inaczej ogólne reguły
     skórki (body.ti-skin a:not(.btn) → niebieski, body.ti-skin h2 → granat)
     wygrywają specyficznością i menu jest niebieskie na granacie. */
  .kier-sidenav, body.ti-skin .kier-sidenav { background: var(--ti-navy, #10335c); border: 0; border-top: 3px solid #c8a11a;
                  border-radius: 0; padding: .5rem .5rem .75rem; margin: .75rem 1rem 0; color: #fff; }
  .kier-sidenav h2, body.ti-skin .kier-sidenav h2 { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
                     color: #ffd863; margin: .9rem .5rem .3rem; }
  .kier-sidenav h2:first-of-type, body.ti-skin .kier-sidenav h2:first-of-type { margin-top: .25rem; }
  .kier-sidenav a, body.ti-skin .kier-sidenav a { display: flex; align-items: center; gap: .55rem; padding: .36rem .6rem;
                    border: 1px solid transparent; border-radius: 2px; font-size: .8rem; font-weight: 500;
                    color: #fff; text-decoration: none; min-height: 34px; }
  .kier-sidenav a:hover, body.ti-skin .kier-sidenav a:hover { background: rgba(255,255,255,.1); text-decoration: underline; }
  .kier-sidenav a.active, body.ti-skin .kier-sidenav a.active { background: #fff; border-color: #fff; color: var(--ti-blue, #1d4ed8); font-weight: 700; }
  .kier-sidenav a .bi, body.ti-skin .kier-sidenav a .bi { width: 1.1rem; text-align: center; flex-shrink: 0; opacity: .9; }
  .kier-sidenav a.active .bi, body.ti-skin .kier-sidenav a.active .bi { opacity: 1; }
  .kier-sidenav a .badge, body.ti-skin .kier-sidenav a .badge { margin-left: auto; font-size: .62rem; }
  /* Czerwień Bootstrapa na granacie ma za mały kontrast — jasne tło, ciemny napis */
  .kier-sidenav a .badge.text-bg-danger, body.ti-skin .kier-sidenav a .badge.text-bg-danger { background: #ffd6d6 !important; color: #8a1c1c !important; }
  .kier-sidenav a .kier-ext, body.ti-skin .kier-sidenav a .kier-ext { font-size: .62rem; margin-left: auto; width: auto; }
  .kier-sidenav .kier-crumbs, body.ti-skin .kier-sidenav .kier-crumbs { font-size: .75rem; color: rgba(255,255,255,.75);
                               border-top: 1px solid rgba(255,255,255,.2); margin-top: .8rem; padding-top: .6rem; }
  .kier-sidenav .kier-crumbs a, body.ti-skin .kier-sidenav .kier-crumbs a { display: inline; padding: 0; min-height: 0; font-size: inherit; color: #fff; }
  .kier-sidenav details > summary, body.ti-skin .kier-sidenav details > summary { cursor: pointer; font-weight: 700; padding: .3rem .2rem; color: #fff;
                                    list-style: none; display: flex; align-items: center; gap: .5rem; }
  .kier-sidenav details > summary::-webkit-details-marker, body.ti-skin .kier-sidenav details > summary::-webkit-details-marker { display: none; }
  /* Sekcje menu (zwijane): nagłówek jak przycisk, znacznik stanu tekstowy (dostępność: nie tylko kolor/ikona). */
  .kier-sidenav details.kier-grp, body.ti-skin .kier-sidenav details.kier-grp { margin-top: .35rem; }
  .kier-sidenav details.kier-grp > summary, body.ti-skin .kier-sidenav details.kier-grp > summary { font-size: .74rem; font-weight: 700;
                    text-transform: uppercase; letter-spacing: .06em; color: #ffd863; padding: .45rem .5rem; min-height: 36px;
                    border-bottom: 1px solid rgba(255,255,255,.18); border-radius: 2px; }
  .kier-sidenav details.kier-grp > summary:hover, body.ti-skin .kier-sidenav details.kier-grp > summary:hover { background: rgba(255,255,255,.08); }
  .kier-sidenav details.kier-grp > summary:focus-visible, body.ti-skin .kier-sidenav details.kier-grp > summary:focus-visible { outline: 3px solid #ffd863; outline-offset: 1px; }
  .kier-sidenav details.kier-grp > summary::after, body.ti-skin .kier-sidenav details.kier-grp > summary::after { content: '▾ zwiń'; margin-left: auto; font-size: .66rem; font-weight: 600; text-transform: none; letter-spacing: 0; color: #fff; }
  .kier-sidenav details.kier-grp:not([open]) > summary::after, body.ti-skin .kier-sidenav details.kier-grp:not([open]) > summary::after { content: '▸ rozwiń'; }
  .kier-sidenav details.kier-grp > div, body.ti-skin .kier-sidenav details.kier-grp > div { padding-top: .2rem; }

  @media (min-width: 992px) {
    /* Menu jest częścią strony: przewija się razem z treścią (nie „pływa” przyklejone do ekranu). */
    .kier-sidenav, body.ti-skin .kier-sidenav { position: absolute; top: 4.2rem; left: .75rem; width: 240px;
                    margin: 0; z-index: 1030; }
    /* Tryb osadzony (index.php): sekcje stoją już w pasku górnym, nad treścią
       zostaje tylko rząd okruszków — zejdź o jego wysokość. */
    .kier-sidenav.kier-embed, body.ti-skin .kier-sidenav.kier-embed { top: 5.9rem; }
    .kier-sidenav details:not(.kier-grp) > summary, body.ti-skin .kier-sidenav details:not(.kier-grp) > summary { display: none; }   /* na desktopie zewnętrzna lista zawsze otwarta */
    .kier-sidenav details:not(.kier-grp), body.ti-skin .kier-sidenav details:not(.kier-grp) { display: contents; }
    /* width:auto — main bywa .container-fluid (width:100%); 100% + margines wypychało treść poza ekran. */
    main.dyd-wrap { margin-left: 264px !important; width: auto !important; }
    .skin-crumbs { margin-left: 264px; }
    /* index.php (skórka USOS): main ma też .dyd-content, a ti_skin.css zeruje mu
       margines selektorem body.dyd-usos .dyd-content !important — przebijamy
       silniejszym selektorem, inaczej sidebar wjeżdża na treść. */
    body.dyd-usos main.dyd-content.dyd-wrap { margin-left: 264px !important; width: auto !important; }
    /* Menu schowane (przycisk „Ukryj menu”, pamiętane w przeglądarce): treść na całą szerokość. */
    html.kier-side-off .kier-sidenav { display: none; }
    html.kier-side-off main.dyd-wrap, html.kier-side-off body.dyd-usos main.dyd-content.dyd-wrap { margin-left: 0 !important; }
    html.kier-side-off .skin-crumbs { margin-left: 0; }
    .kier-side-show { display: none; }
    html.kier-side-off .kier-side-show { display: inline-flex; }
  }
  .kier-side-hide, body.ti-skin .kier-side-hide { display: none; }
  @media (min-width: 992px) {
    .kier-side-hide, body.ti-skin .kier-side-hide { display: flex; align-items: center; gap: .35rem; width: 100%; margin: 0 0 .25rem;
      padding: .3rem .6rem; background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.4); border-radius: 2px;
      font-size: .78rem; font-weight: 600; cursor: pointer; }
    .kier-side-hide:hover { background: rgba(255,255,255,.1); }
  }
  .kier-side-show { align-items: center; gap: .35rem; margin: .5rem 0 0 1rem; padding: .3rem .7rem; background: var(--ti-navy, #10335c);
    color: #fff; border: 0; border-radius: 2px; font-size: .8rem; font-weight: 600; cursor: pointer; }
  .kier-side-hide:focus-visible, .kier-side-show:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>

<script>try{if(localStorage.getItem('kierSideOff')==='1')document.documentElement.classList.add('kier-side-off');}catch(e){}</script>
<button type="button" class="kier-side-show" aria-controls="kierSidenav" aria-expanded="false" onclick="kierMenu(true)">
  <i class="bi bi-layout-sidebar" aria-hidden="true"></i>Pokaż menu: <?= h(mb_strtolower($KIER_TITLE)) ?>
</button>
<nav class="kier-sidenav<?= $KIER_EMBED ? ' kier-embed' : '' ?>" id="kierSidenav" aria-label="Menu: <?= h($KIER_TITLE) ?>">
  <button type="button" class="kier-side-hide" aria-controls="kierSidenav" aria-expanded="true" onclick="kierMenu(false)">
    <i class="bi bi-chevron-double-left" aria-hidden="true"></i>Ukryj menu
  </button>
  <details open>
    <summary><i class="bi bi-list" aria-hidden="true"></i> Menu: <?= h(mb_strtolower($KIER_TITLE)) ?></summary>
    <div>
      <h2><?= h($KIER_TITLE) ?></h2>
      <?php if ($KIER_FLAT): ?>
        <?php foreach ($_kier_norm as $_i): ?>
        <a href="<?= h($_i['href']) ?>"<?= $_i['active'] ? ' class="active" aria-current="page"' : '' ?><?= !empty($_i['blank']) ? ' target="_blank" rel="noopener"' : '' ?>>
          <i class="bi bi-<?= h($_i['icon']) ?>" aria-hidden="true"></i><?= h($_i['label']) ?>
          <?php if (!empty($_i['n'])): ?><span class="badge text-bg-<?= h($_i['v'] ?: 'secondary') ?>"><?= (int)$_i['n'] ?></span><?php endif; ?>
          <?php if (!empty($_i['blank']) && empty($_i['n'])): ?><i class="bi bi-box-arrow-up-right kier-ext" aria-hidden="true"></i><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?= $KIER_EXTRA_HTML ?>
      <?php else: ?>
      <?php foreach ($_kier_order as $_g):
        if (empty($_kier_by_grp[$_g])) continue;
        $_grp_open = array_filter($_kier_by_grp[$_g], fn($x) => !empty($x['active'])) ? ' open' : '';
      ?>
      <details class="kier-grp"<?= $_grp_open ?>>
        <summary><?= h($_g) ?></summary>
        <div>
        <?php foreach ($_kier_by_grp[$_g] as $_i): ?>
        <a href="<?= h($_i['href']) ?>"<?= $_i['active'] ? ' class="active" aria-current="page"' : '' ?><?= !empty($_i['blank']) ? ' target="_blank" rel="noopener"' : '' ?>>
          <i class="bi bi-<?= h($_i['icon']) ?>" aria-hidden="true"></i><?= h($_i['label']) ?>
          <?php if (!empty($_i['n'])): ?><span class="badge text-bg-<?= h($_i['v'] ?: 'secondary') ?>"><?= (int)$_i['n'] ?></span><?php endif; ?>
          <?php if (!empty($_i['blank']) && empty($_i['n'])): ?><i class="bi bi-box-arrow-up-right kier-ext" aria-hidden="true"></i><?php endif; ?>
        </a>
        <?php endforeach; ?>
        </div>
      </details>
      <?php endforeach; ?>
      <?php endif; /* $KIER_FLAT */ ?>

      <?php if (!$KIER_EMBED): ?>
      <?php if (empty($GLOBALS['DYD_HEADER_NAV'])): // sekcje zwykle są już w pasku górnym (_nav.php) ?>
      <h2>Sekcje panelu</h2>
      <?php foreach ($_kier_sections as [$_lbl, $_href]): ?>
      <a href="<?= h($_href) ?>"><i class="bi bi-arrow-return-right" aria-hidden="true"></i><?= h($_lbl) ?></a>
      <?php endforeach; ?>
      <?php endif; ?>

      <div class="kier-crumbs">
        <a href="index.php?tab=pulpit">Panel dydaktyka</a>
        &rsaquo; <a href="<?= h($KIER_CRUMB[1]) ?>"><?= h($KIER_CRUMB[0]) ?></a>
        <?php if ($KIER_LABEL !== ''): ?>&rsaquo; <strong><?= h($KIER_LABEL) ?></strong><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </details>
</nav>
<script>
// Chowanie menu (pamiętane w przeglądarce); fokus wraca na przycisk drugiego stanu.
function kierMenu(pokaz) {
  document.documentElement.classList.toggle('kier-side-off', !pokaz);
  try { localStorage.setItem('kierSideOff', pokaz ? '0' : '1'); } catch (e) {}
  var b = document.querySelector(pokaz ? '.kier-side-hide' : '.kier-side-show'); if (b) b.focus();
  window.dispatchEvent(new Event('resize'));
}
// Menu nie jest przyklejone do ekranu — gdy jest dłuższe niż treść, strona musi być co najmniej tak wysoka jak menu.
(function () {
  var nav = document.currentScript.previousElementSibling, main = document.querySelector('main.dyd-wrap');
  // Telefon / wąski ekran: menu zwinięte na start (rozwija się przyciskiem „Menu: …”).
  if (nav && window.matchMedia && !window.matchMedia('(min-width: 992px)').matches) {
    var zew = nav.querySelector(':scope > details'); if (zew) zew.open = false;
  }
  if (!nav || !main || !window.matchMedia) return;
  function wyrownaj() {
    main.style.minHeight = (window.matchMedia('(min-width: 992px)').matches && !document.documentElement.classList.contains('kier-side-off'))
      ? Math.max(0, nav.getBoundingClientRect().bottom - main.getBoundingClientRect().top) + 'px' : '';
  }
  window.addEventListener('load', wyrownaj); window.addEventListener('resize', wyrownaj);
  nav.addEventListener('toggle', wyrownaj, true);   // zwijanie grup menu zmienia wysokość
})();
</script>
