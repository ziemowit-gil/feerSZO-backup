<?php
/**
 * karty30/ti/dydaktyk/konsultacjeStart.php — strona powitalna trybu Konsultacje: kafle Start (Metro) jak na Pulpicie.
 * Górny rząd — liczby (dziś, najbliższe 7 dni, kolejka oczekujących, wstępne do potwierdzenia), każdy kafel prowadzi
 * do miejsca pracy; niżej szybki wybór najczęstszych czynności. Konsultant widzi swoje terminy, kierownik wszystkie.
 * Wejście do trybu Konsultacje (choose_mode.php, osoby tylko z Konsultacjami) prowadzi tutaj.
 */
require __DIR__ . '/_rezerwacje_nav.php';
require_once dirname(__DIR__, 3) . '/modules/ti_dyd_welcome/logic/dydWelcome.php';

$today = date('Y-m-d');
$own = $staff ? '' : ' AND s.assigned_to = ' . (int)$uid;
$live = "s.status NOT IN ('cancelled','cancelled_by_feer','cancelled_by_client')";
$nToday = (int)(db_one("SELECT COUNT(*) n FROM k30_schedules s WHERE DATE(s.start_time) = ? AND $live$own", [$today])['n'] ?? 0);
$nWeek  = (int)(db_one("SELECT COUNT(*) n FROM k30_schedules s WHERE DATE(s.start_time) > ? AND DATE(s.start_time) <= ? AND $live$own",
                       [$today, date('Y-m-d', strtotime('+7 days'))])['n'] ?? 0);
$nPrelim = (int)(db_one("SELECT COUNT(*) n FROM k30_schedules s WHERE s.status = 'preliminary' AND DATE(s.start_time) >= ?$own", [$today])['n'] ?? 0);
$nWait = 0;
try { $nWait = (int)(db_one("SELECT COUNT(*) n FROM k30_waiting_list WHERE status IN ('waiting','contacted')")['n'] ?? 0); } catch (\Throwable $e) {}
$next = db_one("SELECT s.start_time, c.name AS client_name FROM k30_schedules s LEFT JOIN k30_clients c ON c.id = s.client_id
                WHERE s.start_time >= ? AND $live$own ORDER BY s.start_time LIMIT 1", [date('Y-m-d H:i:s')]);

$canBook = $staff;
if (!$canBook) { try { $canBook = (bool)db_one("SELECT 1 FROM ti_room_srs_bookers WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {} }

// [kolor, ikona, liczba, etykieta, podpis, adres] — kolory ≥ 4,5:1 z białym tekstem
$stats = [
    [$nToday ? '#0f6fa8' : '#6d6d6d', 'calendar-day', $nToday, 'Dziś konsultacji',
        $next && substr((string)$next['start_time'], 0, 10) === $today ? 'najbliższa o ' . substr((string)$next['start_time'], 11, 5) : ($nToday ? 'na dziś to wszystko' : 'dziś bez konsultacji'),
        'rezerwacje.php?' . http_build_query(['from' => $today, 'to' => $today] + ($staff ? [] : ['consultant' => $uid]))],
    ['#00796b', 'calendar-week', $nWeek, 'Najbliższe 7 dni', 'zobacz w kalendarzu', 'rezerwacjeKalendarz.php'],
    [$nPrelim ? '#c25100' : '#007a00', $nPrelim ? 'hourglass-split' : 'check2-circle', $nPrelim, 'Wstępne', $nPrelim ? 'do potwierdzenia' : 'wszystkie potwierdzone',
        'rezerwacje.php?' . http_build_query(['status' => 'preliminary', 'from' => $today] + ($staff ? [] : ['consultant' => $uid]))],
    [$nWait ? '#a4262c' : '#1d1d1d', 'people', $nWait, 'Kolejka oczekujących', $nWait ? 'czekają na termin' : 'nikt nie czeka', 'kolejka.php'],
];
// [kolor, ikona, etykieta, podpis, adres]
$quick = [
    ['#0f6fa8', 'lightning-charge', 'Szybka rezerwacja', 'najbliższe wolne terminy', 'rezerwacja.php?szybka=1'],
    ['#0b5582', 'calendar-plus', 'Nowa rezerwacja', 'dowolny termin i seria', 'rezerwacja.php'],
    ['#5c2d91', 'clipboard2-plus', 'Nowa konsultacja', 'karta konsultacji', 'konsultacja.php?nowa=1'],
    ['#6a1b9a', 'clipboard2-check', 'Karty konsultacji', 'dokumentacja spotkań', 'konsultacje.php'],
    ['#00796b', 'people-fill', 'Klienci', 'beneficjenci konsultacji', 'konsultacjeKlienci.php'],
    ['#ad1457', 'send', 'E-mail / SMS', 'do beneficjentów', 'konsultacjeKomunikacja.php'],
];
if ($canBook) $quick[] = ['#c25100', 'door-open', 'Rezerwuj przestrzeń', 'sala na spotkanie', 'saleFormularz.php?_route=/nowa'];
$quick[] = ['#1d1d1d', 'printer', 'Wydruki i zestawienia', 'raporty konsultacji', 'konsultacjeZestawienia.php'];
$quick[] = ['#2b579a', 'building-fill-check', 'Umowy PFRON', 'konsultacje w umowach', 'konsultacjePfron.php'];

$_day_pl = ['Mon' => 'Poniedziałek', 'Tue' => 'Wtorek', 'Wed' => 'Środa', 'Thu' => 'Czwartek', 'Fri' => 'Piątek', 'Sat' => 'Sobota', 'Sun' => 'Niedziela'];
$_mon_gen = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];

$RZ_TITLE = 'Konsultacje — start'; $RZ_CUR = 'konsultacjeStart.php';
include __DIR__ . '/_rezerwacje_head.php';
?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<?= flash_html() ?>
<?= dydWelcomeHero($byName, ($_day_pl[date('D')] ?? '') . ', ' . date('j') . ' ' . $_mon_gen[(int)date('n')] . ' ' . date('Y'), []) ?>

<h1 class="visually-hidden">Konsultacje — start</h1>
<nav class="kst-tiles" aria-label="Konsultacje w liczbach">
  <?php foreach ($stats as [$c, $i, $n, $l, $s, $href]): ?>
  <a class="kst-tile" href="<?= h($href) ?>" style="--k:<?= h($c) ?>">
    <i class="bi bi-<?= h($i) ?> kst-ic" aria-hidden="true"></i>
    <span class="kst-n"><?= (int)$n ?></span>
    <span class="kst-l"><?= h($l) ?></span>
    <span class="kst-s"><?= h($s) ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<h2 class="h6 fw-bold text-uppercase mb-2" style="letter-spacing:.06em">Szybki wybór</h2>
<nav class="kst-tiles kst-quick" aria-label="Szybki wybór">
  <?php foreach ($quick as [$c, $i, $l, $s, $href]): ?>
  <a class="kst-tile" href="<?= h($href) ?>" style="--k:<?= h($c) ?>">
    <i class="bi bi-<?= h($i) ?> kst-ic" aria-hidden="true"></i>
    <span class="kst-l"><?= h($l) ?></span>
    <span class="kst-s"><?= h($s) ?></span>
  </a>
  <?php endforeach; ?>
</nav>
</div>
</main>
<style>
/* Kafle Start (Metro) jak na Pulpicie: pełny kolor, biały tekst, zero zaokrągleń; szybki wybór niższy, bez liczby */
.kst-tiles { display:grid; gap:.5rem; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); margin:0 0 1.25rem; }
.kst-tile { display:flex; flex-direction:column; min-height:132px; padding:.85rem 1rem .8rem; background:var(--k);
  color:#fff !important; text-decoration:none !important; border-radius:0 !important; transition:filter .12s; }
.kst-tile:hover, .kst-tile:focus-visible { color:#fff !important; filter:brightness(1.12); outline:3px solid #1d1d1d; outline-offset:2px; }
.kst-ic { font-size:1.45rem; line-height:1; }
.kst-n { margin-top:auto; font-size:2.6rem; font-weight:300; line-height:1; }
.kst-l { font-size:.9rem; font-weight:600; margin-top:.3rem; }
.kst-s { font-size:.74rem; opacity:.9; }
.kst-quick .kst-tile { min-height:104px; }
.kst-quick .kst-ic { font-size:1.8rem; margin-bottom:auto; }
@media (max-width:575.98px) { .kst-tiles { grid-template-columns:repeat(2, 1fr); } .kst-n { font-size:2.1rem; } }
@media (prefers-reduced-motion:reduce) { .kst-tile { transition:none; } }
</style>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
