<?php
/**
 * karty30/ti/dydaktyk/_usos_bar.php — nawigacja panelu u góry (dwa poziomy).
 *
 * Cała nawigacja siedzi w pasku nad treścią: pierwszy rząd to sekcje
 * (Mój panel, Kurs, Planowanie, Komunikacja, Zasoby, Kierownik), drugi —
 * pozycje sekcji otwartej. Wcześniej te grupy stały w menu bocznym; po
 * przeniesieniu do góry treść ma całą szerokość, a widać wprost, gdzie jesteśmy.
 * Menu boczne jest w tym widoku ukryte (usos.css), a nie usunięte — układ
 * klasyczny nadal go używa.
 *
 * Zmienne z index.php: $tab, $cur_course, $course, $me, $_sb_ctabs (etykiety i
 * liczniki zakładek kursowych), $_sb_roz_debt, $my_avail, $dyd_msg_unread_total,
 * $dyd_notices_unread, $dyd_contracts.
 */

$_c  = (int)$cur_course;
$_ct = fn(string $t) => $_c ? 'index.php?course=' . $_c . '&tab=' . $t : 'index.php?tab=' . $t;
$_g  = fn(string $t) => 'index.php?tab=' . $t;

// Liczniki, które mamy pod ręką w tym miejscu żądania
$_n_avail     = isset($my_avail) ? count($my_avail) : 0;
$_n_msg       = (int)($dyd_msg_unread_total ?? 0);
$_n_notices   = (int)($dyd_notices_unread ?? 0);
$_n_contracts = isset($dyd_contracts) && is_array($dyd_contracts)
    ? count(array_filter($dyd_contracts, fn($c) => in_array($c['status'] ?? '', ['podpisana','w realizacji'], true)))
    : 0;

/** Pozycja nawigacji: [zakładka albo '', etykieta, adres, licznik, wariant plakietki, nowa karta?] */
$_it = fn(string $tab_key, string $label, string $href, int $n = 0, string $variant = 'secondary', bool $blank = false)
    => ['tab' => $tab_key, 'label' => $label, 'href' => $href, 'n' => $n, 'v' => $variant, 'blank' => $blank];

// ── Kurs: etykiety i liczniki z $_sb_ctabs, żeby nie dublować definicji ──────
$_kurs_items = [];
foreach (($_sb_ctabs ?? []) as $_k => $_meta) {
    [$_ico, $_lbl, $_n, $_v] = $_meta;
    $_kurs_items[] = $_it((string)$_k, (string)$_lbl, $_ct((string)$_k), (int)$_n, $_v !== '' ? (string)$_v : 'secondary');
}
if (!$_kurs_items) {   // brak wybranego kursu — same wejścia, bez liczników
    foreach ([
        'lekcje' => 'Zajęcia', 'uczestnicy' => 'Uczestnicy', 'plan' => 'Plan zajęć',
        'protokol' => 'Protokoły', 'zadania' => 'Zadania', 'materialy' => 'Materiały',
        'nieobecnosci' => 'Nieobecności', 'oceny' => 'Oceny', 'program' => 'Sylabus', 'testy' => 'Testy',
    ] as $_k => $_lbl) {
        $_kurs_items[] = $_it($_k, $_lbl, $_g($_k));
    }
}
$_kurs_items[] = $_it('formalnosci', 'Formalności', $_g('formalnosci'), $_n_contracts, 'success');

// ── Sekcje: kolejność i zawartość jak w dawnym menu bocznym ──────────────────
$_usos_sections = [
    'start' => [
        'label' => 'Mój panel',
        'href'  => $_g('pulpit'),
        'items' => [
            $_it('pulpit', 'Pulpit',        $_g('pulpit')),
            $_it('pomoc',  'Gdzie co jest', $_g('pomoc')),
        ],
    ],
    'kurs' => [
        'label' => 'Kurs',
        'href'  => $_c ? $_ct('lekcje') : $_g('lekcje'),
        'items' => $_kurs_items,
    ],
    'planowanie' => [
        'label' => 'Planowanie',
        'href'  => $_g('frekwencja_grup'),
        'items' => [
            $_it('frekwencja_grup', 'Frekwencja grup', $_g('frekwencja_grup')),
            $_it('dostepnosc',      'Dostępność',      $_g('dostepnosc'), $_n_avail),
            $_it('zoom',            'Zajętość Zoom',   $_g('zoom')),
            $_it('cykliczne',       'Plan cykliczny',  $_g('cykliczne')),
            $_it('',                'Planner',         'planner.php'),
        ],
    ],
    'komunikacja' => [
        'label' => 'Komunikacja',
        'href'  => $_g('wiadomosci'),
        'items' => [
            $_it('wiadomosci', 'Wiadomości', $_g('wiadomosci'), $_n_msg, 'danger'),
            $_it('komunikaty', 'Komunikaty', $_g('komunikaty'), $_n_notices, 'warning'),
        ],
    ],
    'zasoby' => [
        'label' => 'Zasoby',
        'href'  => $_g('dysk'),
        'items' => [
            $_it('dysk', 'Mój dysk',       $_g('dysk')),
            $_it('',     'Pełny moduł TI', rtrim(APP_URL, '/') . '/karty30/ti/index.php', 0, 'secondary', true),
        ],
    ],
];

if (dyd_is_staff()) {
    $_usos_sections['kierownik'] = [
        'label' => 'Kierownik',
        'href'  => $_g('grupy'),
        'items' => [
            $_it('grupy',        'Przegląd grup',         $_g('grupy')),
            $_it('rozliczenia',  'Rozliczenia grupy',     $_ct('rozliczenia'), (int)($_sb_roz_debt ?? 0), 'danger'),
            $_it('billing',      'Rozliczenia kursantów', $_g('billing')),
            $_it('kursy',        'Zarządzanie kursami',   $_g('kursy')),
            $_it('wypłaty',      'Wypłaty prowadzących',  $_g('wypłaty')),
            $_it('praca_wlasna', 'Praca własna',          $_g('praca_wlasna')),
            $_it('komunikacja',  'Komunikacja',           $_g('komunikacja')),
            $_it('',             'Żetony SZO',            '../zetony.php',  0, 'secondary', true),
            $_it('',             'Raporty i WUP',         '../raporty.php', 0, 'secondary', true),
            $_it('',             'Pełny panel TI',        '../index.php',   0, 'secondary', true),
        ],
    ];
    // „Rozliczenia" grupy stoi w sekcji Kierownik — nie dublujemy go w sekcji Kurs
    $_usos_sections['kurs']['items'] = array_values(array_filter(
        $_usos_sections['kurs']['items'], fn($i) => $i['tab'] !== 'rozliczenia'
    ));
}

// Sekcja otwarta = ta, która zawiera bieżącą zakładkę
$_usos_cur = 'start';
foreach ($_usos_sections as $_k => $_s) {
    foreach ($_s['items'] as $_i) {
        if ($_i['tab'] !== '' && $_i['tab'] === $tab) { $_usos_cur = $_k; break 2; }
    }
}

$_cur_sec   = $_usos_sections[$_usos_cur];
$_cur_label = '';
foreach ($_cur_sec['items'] as $_i) {
    if ($_i['tab'] === $tab) { $_cur_label = $_i['label']; break; }
}
?>
<nav class="usos-sections" aria-label="Sekcje panelu">
  <?php foreach ($_usos_sections as $_k => $_s): ?>
  <a href="<?= h($_s['href']) ?>" <?= $_usos_cur === $_k ? 'aria-current="page"' : '' ?>><?= h($_s['label']) ?></a>
  <?php endforeach; ?>
</nav>

<nav class="usos-subnav" aria-label="Pozycje sekcji <?= h($_cur_sec['label']) ?>">
  <?php foreach ($_cur_sec['items'] as $_i): $_act = ($_i['tab'] !== '' && $_i['tab'] === $tab); ?>
  <a href="<?= h($_i['href']) ?>"<?= $_i['blank'] ? ' target="_blank" rel="noopener"' : '' ?>
     <?= $_act ? 'class="active" aria-current="page"' : '' ?>>
    <?= h($_i['label']) ?>
    <?php if ($_i['n'] > 0): ?><span class="badge text-bg-<?= h($_i['v']) ?>"><?= (int)$_i['n'] ?></span><?php endif; ?>
    <?php if ($_i['blank']): ?><i class="bi bi-box-arrow-up-right" style="font-size:.62rem" aria-hidden="true"></i><?php endif; ?>
  </a>
  <?php endforeach; ?>
  <?php if ($_usos_cur === 'start'): ?>
  <button type="button" class="usos-subnav-btn" onclick="window.dydShowFlashPref && window.dydShowFlashPref()">Powiadomienia</button>
  <button type="button" class="usos-subnav-btn" onclick="window.dydStartTour && window.dydStartTour()">Tour powitalny</button>
  <?php endif; ?>
</nav>

<div class="usos-crumbs">
  <a href="index.php?tab=pulpit">Panel dydaktyka</a>
  <?php if ($_usos_cur !== 'start'): ?>
    &rsaquo; <a href="<?= h($_cur_sec['href']) ?>"><?= h($_cur_sec['label']) ?></a>
  <?php endif; ?>
  <?php if ($_usos_cur === 'kurs' && !empty($course['name'])): ?>
    &rsaquo; <?= h($course['name']) ?>
  <?php elseif ($_usos_cur === 'kierownik' && $tab === 'rozliczenia' && !empty($course['name'])): ?>
    &rsaquo; <?= h($course['name']) ?>
  <?php endif; ?>
  <?php if ($_cur_label !== '' && $tab !== 'pulpit'): ?>
    &rsaquo; <strong><?= h($_cur_label) ?></strong>
  <?php endif; ?>
</div>
