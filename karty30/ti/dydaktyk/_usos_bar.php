<?php
/**
 * karty30/ti/dydaktyk/_usos_bar.php — pasek sekcji + okruszki widoku USOS.
 *
 * Renderowany tylko w widoku USOS (body.dyd-usos). Sekcje odpowiadają grupom
 * menu panelu, żeby nawigacja była taka sama jak w widoku klasycznym — zmienia
 * się prezentacja, nie zestaw funkcji.
 *
 * Zmienne z index.php: $tab, $cur_course, $course, $me.
 */

// Zakładka → sekcja paska
$_usos_sections = [
    'zajecia'    => ['label' => 'Moje zajęcia', 'tabs' => ['lekcje','uczestnicy','plan','protokol','oceny','zadania','materialy','nieobecnosci','program','testy','rozliczenia'], 'href' => null],
    'planowanie' => ['label' => 'Planowanie',   'tabs' => ['frekwencja_grup','dostepnosc','zoom','cykliczne','praca_wlasna'], 'href' => 'index.php?tab=frekwencja_grup'],
    'komunikacja'=> ['label' => 'Komunikacja',  'tabs' => ['wiadomosci','komunikaty','komunikacja'], 'href' => 'index.php?tab=wiadomosci'],
    'sprawy'     => ['label' => 'Moje sprawy',  'tabs' => ['formalnosci','wypłaty','dysk'], 'href' => 'index.php?tab=formalnosci'],
];
$_usos_cur = 'start';
foreach ($_usos_sections as $_k => $_s) {
    if (in_array($tab, $_s['tabs'], true)) { $_usos_cur = $_k; break; }
}

// Domyślne wejście do sekcji „Moje zajęcia" zależy od wybranego kursu
$_usos_sections['zajecia']['href'] = $cur_course
    ? 'index.php?course=' . (int)$cur_course . '&tab=lekcje'
    : 'index.php?tab=lekcje';

// Okruszki: Panel → sekcja → kurs → zakładka
$_usos_tab_labels = [
    'pulpit' => 'Pulpit', 'lekcje' => 'Zajęcia', 'uczestnicy' => 'Uczestnicy', 'plan' => 'Plan zajęć',
    'protokol' => 'Protokoły ocen', 'oceny' => 'Oceny', 'zadania' => 'Zadania', 'materialy' => 'Materiały',
    'nieobecnosci' => 'Nieobecności', 'program' => 'Program', 'testy' => 'Testy',
    'rozliczenia' => 'Rozliczenia grupy', 'frekwencja_grup' => 'Frekwencja grup', 'dostepnosc' => 'Dostępność',
    'zoom' => 'Zajętość Zoom', 'cykliczne' => 'Plan cykliczny', 'praca_wlasna' => 'Praca własna',
    'wiadomosci' => 'Wiadomości', 'komunikaty' => 'Komunikaty', 'komunikacja' => 'Komunikacja',
    'formalnosci' => 'Formalności', 'wypłaty' => 'Wypłaty', 'dysk' => 'Mój dysk',
    'grupy' => 'Przegląd grup', 'billing' => 'Rozliczenia kursantów', 'kursy' => 'Kursy',
];
?>
<nav class="usos-sections" aria-label="Sekcje panelu">
  <a href="index.php?tab=pulpit" <?= $_usos_cur === 'start' ? 'aria-current="page"' : '' ?>>Mój panel</a>
  <?php foreach ($_usos_sections as $_k => $_s): ?>
  <a href="<?= h($_s['href']) ?>" <?= $_usos_cur === $_k ? 'aria-current="page"' : '' ?>><?= h($_s['label']) ?></a>
  <?php endforeach; ?>
</nav>
<div class="usos-crumbs">
  <a href="index.php?tab=pulpit">Panel dydaktyka</a>
  <?php if ($_usos_cur !== 'start'): ?>
    &rsaquo; <a href="<?= h($_usos_sections[$_usos_cur]['href']) ?>"><?= h($_usos_sections[$_usos_cur]['label']) ?></a>
  <?php endif; ?>
  <?php if ($_usos_cur === 'zajecia' && !empty($course['name'])): ?>
    &rsaquo; <?= h($course['name']) ?>
  <?php endif; ?>
  <?php if (!empty($_usos_tab_labels[$tab]) && $tab !== 'pulpit'): ?>
    &rsaquo; <strong><?= h($_usos_tab_labels[$tab]) ?></strong>
  <?php endif; ?>
</div>
