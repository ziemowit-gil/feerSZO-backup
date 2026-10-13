<?php
/**
 * karty30/ti/kursant/_alt_bar.php — nawigacja panelu kursanta w widoku
 * alternatywnym.
 *
 * Ten sam układ co pasek dydaktyka (karty30/ti/dydaktyk/_usos_bar.php): cała
 * nawigacja siedzi nad treścią w dwóch rzędach — pierwszy to sekcje (Mój panel,
 * Szkolenia, Komunikacja, Dostępy, Sprawy, Konto), drugi to pozycje sekcji
 * otwartej — a pod nimi okruszki. Zakładki i adresy są te same co w widoku
 * klasycznym (`?tab=`), zmienia się tylko sposób ich podania: zamiast paska
 * zakładek z rozwijanymi grupami wszystko widać wprost.
 *
 * Zmienne z index.php: $tab, $is_minor, $hw_pending_total, $notices_unread,
 * $msg_unread, $my_licenses, $terms_pending, $ui_switch_url, $KUR_SIMPLE (kursant uproszczony: 0–3, patrz kursant/auth.php).
 */

$_g = fn(string $t) => 'index.php?tab=' . $t;

/** Pozycja nawigacji: [zakładka, etykieta, adres, licznik, wariant plakietki] */
$_it = fn(string $tab_key, string $label, string $href, int $n = 0, string $variant = 'secondary')
    => ['tab' => $tab_key, 'label' => $label, 'href' => $href, 'n' => $n, 'v' => $variant];

$_n_hw     = (int)($hw_pending_total ?? 0);
$_n_notice = (int)($notices_unread ?? 0);
$_n_msg    = (int)($msg_unread ?? 0);
$_n_lic    = isset($my_licenses) && is_array($my_licenses) ? count($my_licenses) : 0;
$_n_terms  = isset($terms_pending) && is_array($terms_pending) ? count($terms_pending) : 0;
$_minor    = !empty($is_minor);

$_sprawy_items = [];
if (!$_minor) {
    $_sprawy_items[] = $_it('rozliczenia', 'Rozliczenia', $_g('rozliczenia'));
    $_sprawy_items[] = $_it('portfel',     'Portfel',     $_g('portfel'));
    $_sprawy_items[] = $_it('upowaznieni', 'Upoważnieni', $_g('upowaznieni'));
}
$_sprawy_items[] = $_it('regulaminy', 'Regulaminy', $_g('regulaminy'), $_n_terms, 'danger');

/** Ikony kafli (Bootstrap Icons) wg zakładki; nieznana zakładka dostaje kółko. */
$_ikony = [
  'dane' => 'person-badge', 'aktywnosc' => 'activity', 'lekcje' => 'calendar-check',
  'zadania' => 'journal-check', 'oceny' => 'award', 'plan' => 'diagram-3', 'egzaminy' => 'pencil-square',
  'testy' => 'pencil-square', 'komunikaty' => 'megaphone', 'wiadomosci' => 'chat-dots',
  'problem' => 'question-circle', 'online' => 'camera-video', 'vlab' => 'pc-display', 'dysk' => 'cloud',
  'licencje' => 'key', 'pfron' => 'people', 'zapisy' => 'person-plus', 'rozliczenia' => 'receipt',
  'portfel' => 'wallet2', 'upowaznieni' => 'person-check', 'regulaminy' => 'file-text', 'ustawienia' => 'gear', 'office' => 'microsoft',
];

$_alt_sections = [
    'start' => [
        'label' => 'Mój panel',
        'href'  => $_g('dane'),
        'items' => [
            $_it('dane',      'Dane kursanta', $_g('dane')),
            $_it('aktywnosc', 'Aktywność',     $_g('aktywnosc')),
            // Akcje (bez własnej zakładki): pisanie do prowadzącego i prośba o odwołanie/przesunięcie
            $_it('',          'Napisz do prowadzącego', $_g('wiadomosci')),
            $_it('',          'Odwołaj lub przesuń zajęcia', 'index.php?tab=lekcje&akcja=zmien#najblizsze-lekcje'),
        ],
    ],
    'nauka' => [
        'label' => 'Szkolenia',
        'href'  => $_g('lekcje'),
        'items' => [
            $_it('lekcje',   'Moje lekcje',           $_g('lekcje')),
            $_it('zadania',  'Dydaktyka / eLearning', $_g('zadania'), $_n_hw, 'warning'),
            $_it('',         'Biblioteka materiałów', '../ext/index.php?as=student'),
            $_it('zapisy',   'Zapisy na zajęcia',     $_g('zapisy')),
            $_it('oceny',    'Oceny',                 $_g('oceny')),
            $_it('plan',     'Plan nauczania',        $_g('plan')),
            $_it('egzaminy', 'Testy',                 $_g('egzaminy')),
        ],
    ],
    'komunikacja' => [
        'label' => 'Komunikacja',
        'href'  => $_g('komunikaty'),
        'items' => [
            $_it('komunikaty', 'Komunikaty', $_g('komunikaty'), $_n_notice, 'danger'),
            $_it('wiadomosci', 'Wiadomości', $_g('wiadomosci'), $_n_msg,    'danger'),
            $_it('problem',    'Pomoc',      $_g('problem')),
        ],
    ],
    'dostepy' => [
        'label' => 'Dostępy',
        'href'  => $_g('licencje'),
        'items' => [
            $_it('licencje', 'Licencje',           $_g('licencje'), $_n_lic),
            ...(!empty($account['ms_upn']) ? [$_it('office', 'Microsoft 365 (EDU)', $_g('office'))] : []),   // tylko z aktywnym kontem EDU
            $_it('pfron',    'PFRON (konsultacje)', $_g('pfron')),
            $_it('',         'Biblioteka materiałów', '../ext/index.php?as=student'),
        ],
    ],
    'sprawy' => [
        'label' => 'Sprawy',
        'href'  => $_sprawy_items[0]['href'],
        'items' => $_sprawy_items,
    ],
    'konto' => [
        'label' => 'Konto',
        'href'  => $_g('ustawienia'),
        'items' => [
            $_it('ustawienia', 'Ustawienia', $_g('ustawienia')),
            $_it('',           'Menu panelu: zakładki czy rozwijane', $_g('ustawienia') . '#ust-menu'),
        ],
    ],
];

// Beneficjent konsultacji (tryb 4, konto „k1234”): tylko Moje konsultacje i Rozliczenia
if ((int)($KUR_SIMPLE ?? 0) === 4) {
    $_alt_sections = ['konsultacje' => [
        'label' => 'Moje konsultacje',
        'href'  => $_g('konsultacje'),
        'items' => [
            $_it('konsultacje', 'Moje konsultacje', $_g('konsultacje')),
            $_it('rozliczenia', 'Rozliczenia', $_g('rozliczenia')),
            ...($_n_terms > 0 ? [$_it('regulaminy', 'Regulaminy', $_g('regulaminy'), $_n_terms, 'danger')] : []),
        ],
    ]];
    $_ikony['konsultacje'] = 'clipboard2-check';
}
// „Kursant uproszczony” — sekcja terminów zajęć (+ regulaminy do akceptacji); w trybie 2 także sekcja płatności
elseif (!empty($KUR_SIMPLE)) {
    $_alt_sections = ['nauka' => [
        'label' => 'Moje lekcje',
        'href'  => $_g('lekcje'),
        'items' => [
            $_it('lekcje', 'Moje lekcje', $_g('lekcje')),
            $_it('',       'Odwołaj lub przesuń zajęcia', 'index.php?tab=lekcje&akcja=zmien#najblizsze-lekcje'),
            ...($_n_terms > 0 ? [$_it('regulaminy', 'Regulaminy', $_g('regulaminy'), $_n_terms, 'danger')] : []),
        ],
    ]];
    if ((int)$KUR_SIMPLE === 2 && !$_minor) {
        $_alt_sections['sprawy'] = [
            'label' => 'Płatności',
            'href'  => $_g('rozliczenia'),
            'items' => [
                $_it('rozliczenia', 'Rozliczenia', $_g('rozliczenia')),
                $_it('portfel',     'Portfel',     $_g('portfel')),
                $_it('platnosci',   'Numer konta do wpłat', $_g('platnosci')),
            ],
        ];
        $_ikony['platnosci'] = 'cash-coin';
    }
    if ((int)$KUR_SIMPLE === 3 && !$_minor) {   // płatności uproszczone: jeden ekran „Opłaty” w sekcji lekcji
        $_alt_sections['nauka']['items'][] = $_it('oplaty', 'Opłaty', $_g('oplaty'));
        $_ikony['oplaty'] = 'cash-coin';
    }
}

// Sekcja otwarta = ta, która zawiera bieżącą zakładkę
$_alt_cur = array_key_first($_alt_sections);   // domyślnie pierwsza sekcja (Mój panel; u kursanta uproszczonego — Moje lekcje)
foreach ($_alt_sections as $_k => $_s) {
    foreach ($_s['items'] as $_i) {
        if ($_i['tab'] !== '' && $_i['tab'] === $tab) { $_alt_cur = $_k; break 2; }
    }
}

$_cur_sec   = $_alt_sections[$_alt_cur];
$_cur_label = '';
foreach ($_cur_sec['items'] as $_i) {
    if ($_i['tab'] === $tab) { $_cur_label = $_i['label']; break; }
}
?>
<?php if (($KUR_NAV ?? 'zakladki') === 'dropdown'): ?>
<nav class="skin-dropdowns" aria-label="Sekcje panelu">
  <?php foreach ($_alt_sections as $_k => $_s): ?>
  <div class="dropdown">
    <button class="skin-dd-btn<?= $_alt_cur === $_k ? ' active' : '' ?>" type="button" id="dd-<?= h($_k) ?>" data-bs-toggle="dropdown" aria-expanded="false"><?= h($_s['label']) ?></button>
    <ul class="dropdown-menu rounded-0" aria-labelledby="dd-<?= h($_k) ?>">
      <?php foreach ($_s['items'] as $_i): $_act = ($_i['tab'] !== '' && $_i['tab'] === $tab); ?>
      <li><a class="dropdown-item<?= $_act ? ' active' : '' ?>" href="<?= h($_i['href']) ?>"<?= $_act ? ' aria-current="page"' : '' ?>><?= h($_i['label']) ?><?php if ($_i['n'] > 0): ?> <span class="badge text-bg-<?= h($_i['v']) ?>"><?= (int)$_i['n'] ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
      <?php if ($_alt_cur === 'konto' && $_k === 'konto'): ?>
      <?php if (!STUDENT_UI_FORCE_ALT): ?><li><a class="dropdown-item" href="<?= h($ui_switch_url ?? 'index.php?ui=klasyczny') ?>">Wróć do widoku klasycznego</a></li><?php endif; ?>
      <?php endif; ?>
    </ul>
  </div>
  <?php endforeach; ?>
</nav>
<?php elseif (($KUR_NAV ?? 'zakladki') === 'rozwijane'): ?>
<details class="skin-menu-rozwijane" aria-label="Menu panelu">
  <summary><?= h($_cur_sec['label']) ?><?= $_cur_label !== '' ? ' — ' . h($_cur_label) : '' ?></summary>
  <ul class="skin-menu-lista">
    <?php foreach ($_alt_sections as $_k => $_s): ?>
    <li><a href="<?= h($_s['href']) ?>" <?= $_alt_cur === $_k ? 'aria-current="page"' : '' ?>><?= h($_s['label']) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <ul class="skin-menu-lista">
    <?php foreach ($_cur_sec['items'] as $_i): $_act = ($_i['tab'] !== '' && $_i['tab'] === $tab); ?>
    <li><a href="<?= h($_i['href']) ?>" <?= $_act ? 'class="active" aria-current="page"' : '' ?>>
      <?= h($_i['label']) ?>
      <?php if ($_i['n'] > 0): ?><span class="badge text-bg-<?= h($_i['v']) ?>"><?= (int)$_i['n'] ?></span><?php endif; ?>
    </a></li>
    <?php endforeach; ?>
    <?php if ($_alt_cur === 'konto'): ?>
    <?php if (!STUDENT_UI_FORCE_ALT): ?><li><a href="<?= h($ui_switch_url ?? 'index.php?ui=klasyczny') ?>">Wróć do widoku klasycznego</a></li><?php endif; ?>
    <?php endif; ?>
  </ul>
</details>
<?php else: ?>
<nav class="skin-sections" aria-label="Sekcje panelu">
  <?php foreach ($_alt_sections as $_k => $_s): ?>
  <a href="<?= h($_s['href']) ?>" <?= $_alt_cur === $_k ? 'aria-current="page"' : '' ?>><?= h($_s['label']) ?></a>
  <?php endforeach; ?>
</nav>

<nav class="skin-subnav" aria-label="Pozycje sekcji <?= h($_cur_sec['label']) ?>">
  <?php foreach ($_cur_sec['items'] as $_i): $_act = ($_i['tab'] !== '' && $_i['tab'] === $tab); ?>
  <a href="<?= h($_i['href']) ?>" <?= $_act ? 'class="active" aria-current="page"' : '' ?>>
    <?= h($_i['label']) ?>
    <?php if ($_i['n'] > 0): ?><span class="badge text-bg-<?= h($_i['v']) ?>"><?= (int)$_i['n'] ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
  <?php if ($_alt_cur === 'konto'): ?>
  <?php if (!STUDENT_UI_FORCE_ALT): ?><a href="<?= h($ui_switch_url ?? 'index.php?ui=klasyczny') ?>">Wróć do widoku klasycznego</a><?php endif; ?>
  <?php endif; ?>
</nav>
<?php endif; ?>

<div class="skin-crumbs d-flex flex-wrap align-items-center gap-2">
  <span>
  <a href="index.php?tab=dane">Panel kursanta</a>
  <?php if ($_alt_cur !== 'start'): ?>
    &rsaquo; <a href="<?= h($_cur_sec['href']) ?>"><?= h($_cur_sec['label']) ?></a>
  <?php endif; ?>
  <?php if ($_cur_label !== '' && $tab !== 'dane'): ?>
    &rsaquo; <strong><?= h($_cur_label) ?></strong>
  <?php endif; ?>
  </span>
  <?php if (!empty($ti_ctx_multi)): /* kursant w kilku grupach: aktywna grupa + zmiana bez wylogowania */
    $_ctx_nazwa = '';
    foreach (($ti_ctx_courses ?? []) as $_cc) if ((int)$_cc['course_id'] === (int)($ti_ctx_course_id ?? 0)) $_ctx_nazwa = (string)$_cc['course_name']; ?>
  <span class="kp-grupa-ctx ms-auto">
    <i class="bi bi-people" aria-hidden="true"></i>
    <span>Grupa: <strong><?= h($_ctx_nazwa) ?></strong></span>
    <a class="kp-grupa-zmien" href="choose_group.php?tab=<?= h((string)($_GET['tab'] ?? '')) ?>">Zmień grupę<span class="visually-hidden"> (masz <?= count($ti_ctx_courses) ?> grupy)</span></a>
  </span>
  <?php endif; ?>
</div>

<?php /* Układ kafelkowy: pozycje bieżącej sekcji jako kafle, aktualna wyróżniona */ ?>
<nav class="kp-sekcja-kafle container-xl px-3 pt-3" aria-label="Pozycje sekcji <?= h($_cur_sec['label']) ?>">
  <ul class="kp-kafle kp-kafle-mini">
    <?php foreach ($_cur_sec['items'] as $_i): $_act = ($_i['tab'] !== '' && $_i['tab'] === $tab); ?>
    <li>
      <a title="<?= h($_i['label']) ?>" class="kp-kafel kp-kafel-mini<?= in_array($_i['tab'], ['lekcje', 'zadania', 'komunikaty', 'oceny'], true) ? ' kp-kafel-glowny' : '' ?><?= $_act ? ' active' : '' ?>" href="<?= h($_i['href']) ?>"<?= $_act ? ' aria-current="page"' : '' ?>>
        <i class="bi bi-<?= h($_ikony[$_i['tab']] ?? (mb_stripos($_i['label'], 'Biblioteka') !== false ? 'book' : (mb_stripos($_i['label'], 'Menu panelu') !== false ? 'layout-text-sidebar' : (mb_stripos($_i['label'], 'Napisz') !== false ? 'chat-dots' : (mb_stripos($_i['label'], 'Odwołaj') !== false ? 'calendar2-x' : 'circle'))))) ?> kp-kafel-ikona" aria-hidden="true"></i>
        <span class="kp-kafel-tytul"><?= h($_i['label']) ?></span>
        <?php if ($_i['n'] > 0): ?><span class="badge text-bg-<?= h($_i['v']) ?>"><?= (int)$_i['n'] ?></span><?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</nav>
