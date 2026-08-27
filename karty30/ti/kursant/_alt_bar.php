<?php
/**
 * karty30/ti/kursant/_alt_bar.php — nawigacja panelu kursanta w widoku
 * alternatywnym.
 *
 * Ten sam układ co pasek dydaktyka (karty30/ti/dydaktyk/_usos_bar.php): cała
 * nawigacja siedzi nad treścią w dwóch rzędach — pierwszy to sekcje (Mój panel,
 * Nauka, Komunikacja, Dostępy, Sprawy, Konto), drugi to pozycje sekcji
 * otwartej — a pod nimi okruszki. Zakładki i adresy są te same co w widoku
 * klasycznym (`?tab=`), zmienia się tylko sposób ich podania: zamiast paska
 * zakładek z rozwijanymi grupami wszystko widać wprost.
 *
 * Zmienne z index.php: $tab, $is_minor, $hw_pending_total, $notices_unread,
 * $msg_unread, $my_licenses, $terms_pending, $ui_switch_url.
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
    $_sprawy_items[] = $_it('upowaznieni', 'Upoważnieni', $_g('upowaznieni'));
}
$_sprawy_items[] = $_it('regulaminy', 'Regulaminy', $_g('regulaminy'), $_n_terms, 'danger');

$_alt_sections = [
    'start' => [
        'label' => 'Mój panel',
        'href'  => $_g('dane'),
        'items' => [
            $_it('dane',      'Dane kursanta', $_g('dane')),
            $_it('aktywnosc', 'Aktywność',     $_g('aktywnosc')),
        ],
    ],
    'nauka' => [
        'label' => 'Nauka',
        'href'  => $_g('lekcje'),
        'items' => [
            $_it('lekcje',   'Moje lekcje',           $_g('lekcje')),
            $_it('zadania',  'Dydaktyka / eLearning', $_g('zadania'), $_n_hw, 'warning'),
            $_it('oceny',    'Oceny',                 $_g('oceny')),
            $_it('plan',     'Plan nauczania',        $_g('plan')),
            $_it('egzaminy', 'Testy',                 $_g('egzaminy')),
            $_it('testy',    'Testy (starsze)',       $_g('testy')),
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
        'href'  => $_g('online'),
        'items' => [
            $_it('online',   'Szkolenia online',   $_g('online')),
            $_it('vlab',     'VLab',               $_g('vlab')),
            $_it('dysk',     'Mój dysk',           $_g('dysk')),
            $_it('licencje', 'Licencje',           $_g('licencje'), $_n_lic),
            $_it('pfron',    'PFRON (konsultacje)', $_g('pfron')),
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
        ],
    ],
];

// Sekcja otwarta = ta, która zawiera bieżącą zakładkę
$_alt_cur = 'start';
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
  <a href="<?= h($ui_switch_url ?? 'index.php?ui=klasyczny') ?>">Wróć do widoku klasycznego</a>
  <?php endif; ?>
</nav>

<div class="skin-crumbs">
  <a href="index.php?tab=dane">Panel kursanta</a>
  <?php if ($_alt_cur !== 'start'): ?>
    &rsaquo; <a href="<?= h($_cur_sec['href']) ?>"><?= h($_cur_sec['label']) ?></a>
  <?php endif; ?>
  <?php if ($_cur_label !== '' && $tab !== 'dane'): ?>
    &rsaquo; <strong><?= h($_cur_label) ?></strong>
  <?php endif; ?>
</div>
