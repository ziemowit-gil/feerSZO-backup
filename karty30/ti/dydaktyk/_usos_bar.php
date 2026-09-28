<?php
/**
 * karty30/ti/dydaktyk/_usos_bar.php — nawigacja panelu u góry (dwa poziomy).
 *
 * Cała nawigacja siedzi w pasku nad treścią: pierwszy rząd to sekcje
 * (Mój panel, Kurs, Komunikacja, Zasoby, Kierownik), drugi —
 * pozycje sekcji otwartej. Od 2026-09-28 rząd sekcji stoi w granatowym pasku
 * użytkownika (_nav.php, DYD_HEADER_NAV) — tu rysowany tylko awaryjnie, gdy
 * nagłówek go nie dostał; podmenu sekcji i okruszki zawsze stąd. Wcześniej te grupy stały w menu bocznym; po
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
$_n_protocols = isset($_my_pending_protocols) ? count($_my_pending_protocols) : 0;

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
        'nieobecnosci' => 'Nieobecności', 'oceny' => 'Oceny', 'program' => 'Sylabus',
        'egzaminy' => 'Testy i egzaminy', 'testy' => 'Testy (starsze)',
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
            $_it('pulpit',           'Pulpit',            $_g('pulpit')),
            $_it('pomoc',            'Gdzie co jest',     $_g('pomoc')),
            $_it('frekwencja_grup',  'Frekwencja grup',   $_g('frekwencja_grup')),
            $_it('dostepnosc',       'Dostępność',        $_g('dostepnosc'), $_n_avail),
            $_it('',                 'Zapisy na zajęcia', 'rekrutacja.php'),
            $_it('',                 'Protokoły',         'protokoly_moje.php', $_n_protocols, 'warning'),
        ],
    ],
    'kurs' => [
        'label' => 'Kurs',
        'href'  => $_c ? $_ct('lekcje') : $_g('lekcje'),
        'items' => $_kurs_items,
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
            $_it('zoom', 'Zajętość Zoom',  $_g('zoom')),
            $_it('',     'Biblioteka materiałów', '../ext/index.php?as=dyd'),
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
            $_it('',             'Podgląd klientów',      'klienci.php'),
            $_it('',             'Konta kursantów',       'konta.php'),
            $_it('rozliczenia',  'Rozliczenia grupy',     $_ct('rozliczenia'), (int)($_sb_roz_debt ?? 0), 'danger'),
            $_it('',             'Rozliczenia kursantów', 'billing.php'),
            $_it('',             'Zaległe protokoły',     'protokoly.php'),
            $_it('',             'Program poleceń',       'polecenia.php'),
            $_it('kursy',        'Zarządzanie kursami',   $_g('kursy')),
            $_it('',             'Log operacji na grupach', 'log_grup.php'),
            $_it('',             'Wydruki i raporty',     'wydruki.php'),
            $_it('wypłaty',      'Wypłaty prowadzących',  $_g('wypłaty')),
            $_it('praca_wlasna', 'Praca własna',          $_g('praca_wlasna')),
            $_it('komunikacja',  'Komunikacja',           $_g('komunikacja')),
            $_it('',             'Żetony SZO',            'zetony.php'),
            $_it('',             'Zapisy — tury',         'rekrutacja.php?tab=tury'),
            $_it('',             'Dostępności prowadzących', 'dostepnosci.php'),
            $_it('',             'Urlopy prowadzących',   'urlopy.php'),
            $_it('',             'Okresy nauczania',      'okresy.php'),
            $_it('',             'Rodzaje zajęć',         'przedmioty.php'),
            $_it('',             'Dni wolne',             'dni_wolne.php'),
            $_it('',             'Wyłączenia panelu',     'wylaczenia.php'),
            $_it('',             'Zespół i role',         'zespol.php'),
            $_it('',             'Testy',                 'testy.php'),
            $_it('',             'Pełny panel TI',        '../index.php',   0, 'secondary', true),
        ],
    ];
    // „Rozliczenia” grupy stoi w sekcji Kierownik — nie dublujemy go w sekcji Kurs
    $_usos_sections['kurs']['items'] = array_values(array_filter(
        $_usos_sections['kurs']['items'], fn($i) => $i['tab'] !== 'rozliczenia'
    ));
}

// Plan cykliczny — tylko gdy włączony (dyd_plan_cykliczny_enabled(), auth.php)
if (dyd_plan_cykliczny_enabled()) {
    $_usos_sections['start']['items'][] = $_it('cykliczne', 'Plan cykliczny', $_g('cykliczne'));
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
<?php if (empty($GLOBALS['DYD_HEADER_NAV'])): // sekcje zwykle stoją już w pasku górnym (_nav.php) ?>
<nav class="skin-sections" aria-label="Sekcje panelu">
  <?php foreach ($_usos_sections as $_k => $_s): ?>
  <a href="<?= h($_s['href']) ?>" <?= $_usos_cur === $_k ? 'aria-current="page"' : '' ?>><?= h($_s['label']) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($_usos_cur === 'kierownik'):
  // Pozycje sekcji Kierownik idą do MENU BOCZNEGO (jak na stronach zetony/okresy/
  // billing), nie do poziomego rzędu — lista jest za długa na jeden wiersz.
  $_kier_icons = [
      'Przegląd grup'             => 'people',
      'Podgląd klientów'          => 'person-lines-fill',
      'Konta kursantów'           => 'person-badge',
      'Rozliczenia grupy'         => 'receipt-cutoff',
      'Rozliczenia kursantów'     => 'receipt',
      'Zaległe protokoły'         => 'exclamation-octagon',
      'Program poleceń'          => 'gift',
      'Zarządzanie kursami'       => 'collection',
      'Log operacji na grupach'   => 'clock-history',
      'Wydruki i raporty'         => 'printer',
      'Wypłaty prowadzących'      => 'cash-stack',
      'Praca własna'              => 'journal-text',
      'Komunikacja'               => 'megaphone',
      'Żetony SZO'                => 'ticket-detailed',
      'Zapisy — tury'             => 'ticket-perforated',
      'Dostępności prowadzących'  => 'clock-history',
      'Urlopy prowadzących'       => 'airplane',
      'Okresy nauczania'          => 'calendar-range',
      'Rodzaje zajęć'             => 'tags',
      'Dni wolne'                 => 'calendar-x',
      'Wyłączenia panelu'         => 'moon',
      'Zespół i role'             => 'person-gear',
      'Pełny panel TI'            => 'box-arrow-up-right',
  ];
  $KIER_ITEMS = [];
  foreach ($_cur_sec['items'] as $_i) {
      $KIER_ITEMS[] = ['label' => $_i['label'], 'href' => $_i['href'],
                       'icon'  => $_kier_icons[$_i['label']] ?? 'dot',
                       'n'     => (int)$_i['n'], 'v' => $_i['v'], 'blank' => $_i['blank'],
                       'active'=> ($_i['tab'] !== '' && $_i['tab'] === $tab)];
  }
  $KIER_EMBED = true;
  $KIER_LABEL = $_cur_label;
  include __DIR__ . '/_kierownik_bar.php';
else: ?>
<nav class="skin-subnav" aria-label="Pozycje sekcji <?= h($_cur_sec['label']) ?>">
  <?php foreach ($_cur_sec['items'] as $_i): $_act = ($_i['tab'] !== '' && $_i['tab'] === $tab); ?>
  <a href="<?= h($_i['href']) ?>"<?= $_i['blank'] ? ' target="_blank" rel="noopener"' : '' ?>
     <?= $_act ? 'class="active" aria-current="page"' : '' ?>>
    <?= h($_i['label']) ?>
    <?php if ($_i['n'] > 0): ?><span class="badge text-bg-<?= h($_i['v']) ?>"><?= (int)$_i['n'] ?></span><?php endif; ?>
    <?php if ($_i['blank']): ?><i class="bi bi-box-arrow-up-right" style="font-size:.62rem" aria-hidden="true"></i><?php endif; ?>
  </a>
  <?php endforeach; ?>
  <?php if ($_usos_cur === 'start'): ?>
  <span class="skin-subnav-tools">
    <button type="button" class="skin-subnav-btn" onclick="window.dydShowFlashPref && window.dydShowFlashPref()"><i class="bi bi-bell" aria-hidden="true"></i>Powiadomienia</button>
    <button type="button" class="skin-subnav-btn" onclick="window.dydStartTour && window.dydStartTour()"><i class="bi bi-signpost" aria-hidden="true"></i>Tour powitalny</button>
  </span>
  <?php endif; ?>
</nav>
<?php endif; ?>

<?php // Okruszki tylko gdy coś mówią — na Pulpicie byłoby samo „Panel dydaktyka”
if ($tab !== 'pulpit'): ?>
<div class="skin-crumbs">
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
<?php endif; ?>
