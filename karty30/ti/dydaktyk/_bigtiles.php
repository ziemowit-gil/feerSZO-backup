<?php
/**
 * karty30/ti/dydaktyk/_bigtiles.php — duże kafle skrótów (Metro) używane na pulpicie i na stronie „Raporty”.
 * dydBigTilesCss() — styl (drukować raz na stronie); dydBigTiles($tiles, $label) — siatka kafli [kolor, ikona bi-*, etykieta, adres].
 * Pełny kolor, biały tekst (kolory dobrane na kontrast ≥ 4,5:1), zero zaokrągleń, ikona u góry, nazwa u dołu.
 */
function dydBigTilesCss(): void { ?>
<style>
.dyd-bt-h { font-size: .74rem; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #3a3a3a; margin: 0 0 .5rem; }
.dyd-bigtiles { display: grid; gap: .5rem; grid-template-columns: repeat(auto-fill, minmax(176px, 1fr)); margin: 0 0 1.25rem; }
.dyd-bt { display: flex; flex-direction: column; justify-content: space-between; min-height: 112px; padding: .85rem 1rem; background: var(--k); color: #fff !important;
  text-decoration: none !important; border-radius: 0 !important; transition: filter .12s; }
.dyd-bt .bi { font-size: 2rem; line-height: 1; }
.dyd-bt span { font-size: .95rem; font-weight: 600; line-height: 1.2; margin-top: .6rem; }
.dyd-bt small { display: block; font-size: .74rem; font-weight: 400; opacity: .92; margin-top: .15rem; }
.dyd-bt:hover, .dyd-bt:focus-visible { color: #fff !important; filter: brightness(1.12); outline: 3px solid #1d1d1d; outline-offset: 2px; }
@media (max-width: 575.98px) { .dyd-bigtiles { grid-template-columns: repeat(2, 1fr); } .dyd-bt { min-height: 96px; } }
@media (prefers-reduced-motion: reduce) { .dyd-bt { transition: none; } }
</style>
<?php }

/** @param list<array{0:string,1:string,2:string,3:string,4?:string}> $tiles [kolor, ikona, etykieta, adres, podpis (opcjonalny)] */
function dydBigTiles(array $tiles, string $label): void { ?>
<nav class="dyd-bigtiles" aria-label="<?= h($label) ?>">
  <?php foreach ($tiles as $t): ?>
  <a class="dyd-bt" href="<?= h($t[3]) ?>" style="--k:<?= h($t[0]) ?>"><i class="bi bi-<?= h($t[1]) ?>" aria-hidden="true"></i><span><?= h($t[2]) ?><?php if (!empty($t[4])): ?><small><?= h($t[4]) ?></small><?php endif; ?></span></a>
  <?php endforeach; ?>
</nav>
<?php }

/**
 * Zestawienia i raporty wg tematów dla zalogowanego: ['Godziny' => [...kafle], 'Finanse' => [...], …]. Kierownik widzi dodatkowo raporty instytucji.
 * @return array<string, list<array>>
 */
function dydReportGroups(bool $staff): array
{
    $g = ['Moje godziny' => [
        ['#303f9f', 'hourglass-split', 'Moje godziny',        'godziny.php', 'podsumowanie okresu'],
        ['#00695c', 'people',          'Godziny wg grup',     'godziny.php?r=grupy'],
        ['#0b5582', 'calendar-week',   'Godziny wg tygodni',  'godziny.php?r=tygodnie'],
        ['#c25100', 'graph-up',        'Trend 12 miesięcy',   'godziny.php?r=trend'],
        ['#a20025', 'calendar-x',      'Odwołania i zmiany',  'godziny.php?r=odwolania'],
        ['#00807e', 'graph-up-arrow',  'Frekwencja grup',     'index.php?tab=frekwencja_grup'],
        ['#6a00ff', 'printer',         'Wydruki',             'index.php?tab=wydruki'],
    ]];
    if (!$staff) return $g;
    $inst = [
        ['#0d47a1', 'calendar3',       'Godziny TI w miesiącach', 'raportGodzin.php', 'wg prowadzących i grup · tabela przestawna'],
        ['#880e4f', 'calendar-x',      'Raport odwołanych',       'raport_odwolanych.php'],
        ['#37474f', 'receipt',         'Rozliczenia kursantów',   'billing.php'],
    ];
    if (is_file(__DIR__ . '/raportPrzychodu.php')) array_unshift($inst, ['#2e7d32', 'cash-coin', 'Przychód i prognozy', 'raportPrzychodu.php', 'naliczone, sugerowane, frekwencja · tabela przestawna']);
    if (function_exists('dyd_can_analytics') && dyd_can_analytics()) $inst[] = ['#1b5e20', 'speedometer2', 'Analityka TI', 'analityka.php'];
    return ['Instytucja' => $inst] + $g;
}
