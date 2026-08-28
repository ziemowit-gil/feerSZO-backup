<?php
/**
 * karty30/ti/dydaktyk/_kierownik_bar.php — MENU BOCZNE ekranów kierownika
 * (żetony, zapisy, dostępności, okresy, wyłączenia).
 *
 * Przebudowane z dwóch poziomych rzędów na sidebar: na szerokich ekranach
 * przyklejona kolumna po lewej (main.dyd-wrap dostaje margines), na wąskich
 * zwykły blok nad treścią ze zwijaną listą (details). Pozycje i adresy bez
 * zmian; index.php panelu ma własny _usos_bar.php.
 *
 * Zmienne wejściowe: $KIER_CUR — plik bieżącej strony (np. 'zetony.php'),
 *                    $KIER_LABEL — nazwa strony do okruszków.
 */
$KIER_CUR   = $KIER_CUR   ?? '';
$KIER_LABEL = $KIER_LABEL ?? '';

/** [etykieta, adres, ikona bi-*] */
$_kier_items = [
    ['Przegląd grup',             'index.php?tab=grupy',        'people'],
    ['Rozliczenia kursantów',     'index.php?tab=billing',      'receipt'],
    ['Zarządzanie kursami',       'index.php?tab=kursy',        'collection'],
    ['Wypłaty prowadzących',      'index.php?tab=wypłaty',      'cash-stack'],
    ['Praca własna',              'index.php?tab=praca_wlasna', 'journal-text'],
    ['Komunikacja',               'index.php?tab=komunikacja',  'megaphone'],
    ['Żetony SZO',                'zetony.php',                 'ticket-detailed'],
    ['Zapisy na zajęcia',         'rekrutacja.php',             'ticket-perforated'],
    ['Dostępności prowadzących',  'dostepnosci.php',            'clock-history'],
    ['Okresy nauczania',          'okresy.php',                 'calendar-range'],
    ['Wyłączenia panelu',         'wylaczenia.php',             'moon'],
];

$_kier_sections = [
    ['Mój panel',    'index.php?tab=pulpit'],
    ['Kurs',         'index.php?tab=lekcje'],
    ['Planowanie',   'index.php?tab=frekwencja_grup'],
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
  .kier-sidenav .kier-crumbs { font-size: .75rem; color: var(--bs-secondary-color);
                               border-top: 1px solid var(--bs-border-color); margin-top: .8rem; padding-top: .6rem; }
  .kier-sidenav .kier-crumbs a { display: inline; padding: 0; min-height: 0; font-size: inherit; }
  .kier-sidenav details > summary { cursor: pointer; font-weight: 700; padding: .3rem .2rem;
                                    list-style: none; display: flex; align-items: center; gap: .5rem; }
  .kier-sidenav details > summary::-webkit-details-marker { display: none; }

  @media (min-width: 992px) {
    .kier-sidenav { position: fixed; top: 4.2rem; left: .75rem; bottom: .75rem; width: 240px;
                    overflow-y: auto; margin: 0; z-index: 1030; }
    .kier-sidenav details > summary { display: none; }   /* na desktopie lista zawsze otwarta */
    .kier-sidenav details { display: contents; }
    main.dyd-wrap { margin-left: 264px !important; }
  }
</style>

<nav class="kier-sidenav" aria-label="Menu kierownika">
  <details open>
    <summary aria-hidden="true"><i class="bi bi-list"></i> Menu kierownika</summary>
    <div>
      <h2>Kierownik</h2>
      <?php foreach ($_kier_items as [$_lbl, $_href, $_ico]): $_act = ($_href === $KIER_CUR); ?>
      <a href="<?= h($_href) ?>"<?= $_act ? ' class="active" aria-current="page"' : '' ?>>
        <i class="bi bi-<?= h($_ico) ?>" aria-hidden="true"></i><?= h($_lbl) ?>
      </a>
      <?php endforeach; ?>

      <h2>Sekcje panelu</h2>
      <?php foreach ($_kier_sections as [$_lbl, $_href]): ?>
      <a href="<?= h($_href) ?>"><i class="bi bi-arrow-return-right" aria-hidden="true"></i><?= h($_lbl) ?></a>
      <?php endforeach; ?>

      <div class="kier-crumbs">
        <a href="index.php?tab=pulpit">Panel dydaktyka</a>
        &rsaquo; <a href="index.php?tab=grupy">Kierownik</a>
        <?php if ($KIER_LABEL !== ''): ?>&rsaquo; <strong><?= h($KIER_LABEL) ?></strong><?php endif; ?>
      </div>
    </div>
  </details>
</nav>
