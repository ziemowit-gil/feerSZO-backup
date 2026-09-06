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
        ['Rozliczenia grupy',         'index.php?tab=rozliczenia',  'receipt-cutoff'],
        ['Rozliczenia kursantów',     'billing.php',                'receipt'],
        ['Zaległe protokoły',         'protokoly.php',              'exclamation-octagon'],
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
  .kier-sidenav { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color);
                  border-radius: .5rem; padding: .75rem; margin: .75rem 1rem 0; }
  .kier-sidenav h2 { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em;
                     color: var(--bs-secondary-color); margin: .9rem 0 .35rem; }
  .kier-sidenav h2:first-of-type { margin-top: .25rem; }
  .kier-sidenav a { display: flex; align-items: center; gap: .55rem; padding: .42rem .6rem;
                    border-radius: .4rem; font-size: .88rem; font-weight: 500;
                    color: var(--bs-body-color); text-decoration: none; min-height: 38px; }
  .kier-sidenav a:hover { background: var(--bs-tertiary-bg); }
  .kier-sidenav a.active { background: #dbeafe; color: #1d4ed8; font-weight: 700; }
  .kier-sidenav a .bi { width: 1.1rem; text-align: center; flex-shrink: 0; }
  .kier-sidenav a .badge { margin-left: auto; }
  .kier-sidenav a .kier-ext { font-size: .62rem; margin-left: auto; width: auto; }
  .kier-sidenav .kier-crumbs { font-size: .75rem; color: var(--bs-secondary-color);
                               border-top: 1px solid var(--bs-border-color); margin-top: .8rem; padding-top: .6rem; }
  .kier-sidenav .kier-crumbs a { display: inline; padding: 0; min-height: 0; font-size: inherit; }
  .kier-sidenav details > summary { cursor: pointer; font-weight: 700; padding: .3rem .2rem;
                                    list-style: none; display: flex; align-items: center; gap: .5rem; }
  .kier-sidenav details > summary::-webkit-details-marker { display: none; }

  @media (min-width: 992px) {
    .kier-sidenav { position: fixed; top: 4.2rem; left: .75rem; bottom: .75rem; width: 240px;
                    overflow-y: auto; margin: 0; z-index: 1030; }
    /* Tryb osadzony (index.php): nad treścią jest jeszcze rząd sekcji USOS — zejdź niżej */
    .kier-sidenav.kier-embed { top: 6.6rem; }
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
      <h2>Sekcje panelu</h2>
      <?php foreach ($_kier_sections as [$_lbl, $_href]): ?>
      <a href="<?= h($_href) ?>"><i class="bi bi-arrow-return-right" aria-hidden="true"></i><?= h($_lbl) ?></a>
      <?php endforeach; ?>

      <div class="kier-crumbs">
        <a href="index.php?tab=pulpit">Panel dydaktyka</a>
        &rsaquo; <a href="index.php?tab=grupy">Kierownik</a>
        <?php if ($KIER_LABEL !== ''): ?>&rsaquo; <strong><?= h($KIER_LABEL) ?></strong><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </details>
</nav>
