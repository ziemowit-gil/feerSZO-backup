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
 *                    $KIER_EMBED — tryb osadzony (bool).
 */
$KIER_CUR   = $KIER_CUR   ?? '';
$KIER_LABEL = $KIER_LABEL ?? '';
$KIER_EMBED = !empty($KIER_EMBED);

if (!empty($KIER_ITEMS)) {
    $_kier_norm = $KIER_ITEMS;
} else {
    /** [etykieta, adres, ikona bi-*, nowa karta?] — pełny zestaw ekranów kierownika */
    $_kier_defaults = [
        ['Przegląd grup',             'index.php?tab=grupy',        'people'],
        ['Podgląd klientów',          'klienci.php',                'person-lines-fill'],
        ['Konta kursantów',           'konta.php',                  'person-badge'],
        ['Microsoft 365',             'microsoft365.php',           'microsoft'],
        ['Licencje',                  'licencje.php',               'key'],
        ['Rozliczenia grupy',         'index.php?tab=rozliczenia',  'receipt-cutoff'],
        ['Rozliczenia kursantów',     'billing.php',                'receipt'],
        ['Zaległe protokoły',         'protokoly.php',              'exclamation-octagon'],
        ['Audyt dzienników',          'audyt_dziennikow.php',       'clipboard2-check'],
        ['Program poleceń',          'polecenia.php',               'gift'],
        ['Zarządzanie kursami',       'index.php?tab=kursy',        'collection'],
        ['Log operacji na grupach',   'log_grup.php',               'clock-history'],
        ['Wydruki i raporty',         'wydruki.php',                 'printer'],
        ['Wypłaty prowadzących',      'index.php?tab=wypłaty',      'cash-stack'],
        ['Praca własna',              'index.php?tab=praca_wlasna', 'journal-text'],
        ['Komunikacja',               'index.php?tab=komunikacja',  'megaphone'],
        ['Żetony SZO',                'zetony.php',                 'ticket-detailed'],
        ['Zapisy na zajęcia',         'rekrutacja.php',             'ticket-perforated'],
        ['Dostępności prowadzących',  'dostepnosci.php',            'clock-history'],
        ['Urlopy prowadzących',       'urlopy.php',                 'airplane'],
        ['Sale / lokalizacje',        'sale.php',                   'geo-alt'],
        ['Okresy nauczania',          'okresy.php',                 'calendar-range'],
        ['Rodzaje zajęć',             'przedmioty.php',             'tags'],
        ['Dni wolne',                 'dni_wolne.php',              'calendar-x'],
        ['Wyłączenia panelu',         'wylaczenia.php',             'moon'],
        ['Zespół i role',             'zespol.php',                 'person-gear'],
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

$_kier_sections = [
    ['Mój panel',    'index.php?tab=pulpit'],
    ['Kurs',         'index.php?tab=lekcje'],
    ['Komunikacja',  'index.php?tab=wiadomosci'],
    ['Zasoby',       'index.php?tab=dysk'],
];
?>
<style>
  /* Ten sam styl co pasek górny (ti_skin.css): granat, złota krawędź, białe
     pozycje, aktywna jako biała „zakładka” z niebieskim napisem, bez zaokrągleń. */
  .kier-sidenav { background: var(--ti-navy, #10335c); border: 0; border-top: 3px solid #c8a11a;
                  border-radius: 0; padding: .5rem .5rem .75rem; margin: .75rem 1rem 0; color: #fff; }
  .kier-sidenav h2 { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em;
                     color: #ffd863; margin: .9rem .5rem .3rem; }
  .kier-sidenav h2:first-of-type { margin-top: .25rem; }
  .kier-sidenav a { display: flex; align-items: center; gap: .55rem; padding: .36rem .6rem;
                    border: 1px solid transparent; border-radius: 2px; font-size: .8rem; font-weight: 500;
                    color: #fff; text-decoration: none; min-height: 34px; }
  .kier-sidenav a:hover { background: rgba(255,255,255,.1); text-decoration: underline; }
  .kier-sidenav a.active { background: #fff; border-color: #fff; color: var(--ti-blue, #1d4ed8); font-weight: 700; }
  .kier-sidenav a .bi { width: 1.1rem; text-align: center; flex-shrink: 0; opacity: .9; }
  .kier-sidenav a.active .bi { opacity: 1; }
  .kier-sidenav a .badge { margin-left: auto; font-size: .62rem; }
  /* Czerwień Bootstrapa na granacie ma za mały kontrast — jasne tło, ciemny napis */
  .kier-sidenav a .badge.text-bg-danger { background: #ffd6d6 !important; color: #8a1c1c !important; }
  .kier-sidenav a .kier-ext { font-size: .62rem; margin-left: auto; width: auto; }
  .kier-sidenav .kier-crumbs { font-size: .75rem; color: rgba(255,255,255,.75);
                               border-top: 1px solid rgba(255,255,255,.2); margin-top: .8rem; padding-top: .6rem; }
  .kier-sidenav .kier-crumbs a { display: inline; padding: 0; min-height: 0; font-size: inherit; color: #fff; }
  .kier-sidenav details > summary { cursor: pointer; font-weight: 700; padding: .3rem .2rem; color: #fff;
                                    list-style: none; display: flex; align-items: center; gap: .5rem; }
  .kier-sidenav details > summary::-webkit-details-marker { display: none; }

  @media (min-width: 992px) {
    .kier-sidenav { position: fixed; top: 4.2rem; left: .75rem; bottom: .75rem; width: 240px;
                    overflow-y: auto; margin: 0; z-index: 1030; }
    /* Tryb osadzony (index.php): sekcje stoją już w pasku górnym, nad treścią
       zostaje tylko rząd okruszków — zejdź o jego wysokość. */
    .kier-sidenav.kier-embed { top: 5.9rem; }
    .kier-sidenav details > summary { display: none; }   /* na desktopie lista zawsze otwarta */
    .kier-sidenav details { display: contents; }
    main.dyd-wrap { margin-left: 264px !important; }
    /* index.php (skórka USOS): main ma też .dyd-content, a ti_skin.css zeruje mu
       margines selektorem body.dyd-usos .dyd-content !important — przebijamy
       silniejszym selektorem, inaczej sidebar wjeżdża na treść. */
    body.dyd-usos main.dyd-content.dyd-wrap { margin-left: 264px !important; }
  }
</style>

<nav class="kier-sidenav<?= $KIER_EMBED ? ' kier-embed' : '' ?>" aria-label="Menu kierownika">
  <details open>
    <summary aria-hidden="true"><i class="bi bi-list"></i> Menu kierownika</summary>
    <div>
      <h2>Kierownik</h2>
      <?php foreach ($_kier_norm as $_i): ?>
      <a href="<?= h($_i['href']) ?>"<?= $_i['active'] ? ' class="active" aria-current="page"' : '' ?><?= !empty($_i['blank']) ? ' target="_blank" rel="noopener"' : '' ?>>
        <i class="bi bi-<?= h($_i['icon']) ?>" aria-hidden="true"></i><?= h($_i['label']) ?>
        <?php if (!empty($_i['n'])): ?><span class="badge text-bg-<?= h($_i['v'] ?: 'secondary') ?>"><?= (int)$_i['n'] ?></span><?php endif; ?>
        <?php if (!empty($_i['blank']) && empty($_i['n'])): ?><i class="bi bi-box-arrow-up-right kier-ext" aria-hidden="true"></i><?php endif; ?>
      </a>
      <?php endforeach; ?>

      <?php if (!$KIER_EMBED): ?>
      <?php if (empty($GLOBALS['DYD_HEADER_NAV'])): // sekcje zwykle są już w pasku górnym (_nav.php) ?>
      <h2>Sekcje panelu</h2>
      <?php foreach ($_kier_sections as [$_lbl, $_href]): ?>
      <a href="<?= h($_href) ?>"><i class="bi bi-arrow-return-right" aria-hidden="true"></i><?= h($_lbl) ?></a>
      <?php endforeach; ?>
      <?php endif; ?>

      <div class="kier-crumbs">
        <a href="index.php?tab=pulpit">Panel dydaktyka</a>
        &rsaquo; <a href="index.php?tab=grupy">Kierownik</a>
        <?php if ($KIER_LABEL !== ''): ?>&rsaquo; <strong><?= h($KIER_LABEL) ?></strong><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </details>
</nav>
