<?php
/**
 * karty30/ti/dydaktyk/_kierownik_bar.php — paski nawigacji dla samodzielnych
 * ekranów kierownika (żetony, okresy, wyłączenia).
 *
 * index.php ma własny _usos_bar.php, ale ten liczy na kilkanaście zmiennych
 * stamtąd (kurs, liczniki, zakładki), więc na osobnych stronach byłby nie do
 * użycia. Tu wystarczy to samo, co widzi kierownik: rząd sekcji, pozycje sekcji
 * „Kierownik" i okruszki. Wygląd biorą z tych samych klas `.skin-*`.
 *
 * Zmienne wejściowe: $KIER_CUR — plik bieżącej strony (np. 'zetony.php'),
 *                    $KIER_LABEL — nazwa strony do okruszków.
 */
$KIER_CUR   = $KIER_CUR   ?? '';
$KIER_LABEL = $KIER_LABEL ?? '';

$_kier_items = [
    ['Przegląd grup',         'index.php?tab=grupy'],
    ['Rozliczenia kursantów', 'index.php?tab=billing'],
    ['Zarządzanie kursami',   'index.php?tab=kursy'],
    ['Wypłaty prowadzących',  'index.php?tab=wypłaty'],
    ['Praca własna',          'index.php?tab=praca_wlasna'],
    ['Komunikacja',           'index.php?tab=komunikacja'],
    ['Żetony SZO',            'zetony.php'],
    ['Okresy nauczania',      'okresy.php'],
    ['Wyłączenia panelu',     'wylaczenia.php'],
];
?>
<nav class="skin-sections" aria-label="Sekcje panelu">
  <a href="index.php?tab=pulpit">Mój panel</a>
  <a href="index.php?tab=lekcje">Kurs</a>
  <a href="index.php?tab=frekwencja_grup">Planowanie</a>
  <a href="index.php?tab=wiadomosci">Komunikacja</a>
  <a href="index.php?tab=dysk">Zasoby</a>
  <a href="index.php?tab=grupy" aria-current="page">Kierownik</a>
</nav>

<nav class="skin-subnav" aria-label="Pozycje sekcji Kierownik">
  <?php foreach ($_kier_items as [$_lbl, $_href]): $_act = ($_href === $KIER_CUR); ?>
  <a href="<?= h($_href) ?>"<?= $_act ? ' class="active" aria-current="page"' : '' ?>><?= h($_lbl) ?></a>
  <?php endforeach; ?>
</nav>

<div class="skin-crumbs">
  <a href="index.php?tab=pulpit">Panel dydaktyka</a>
  &rsaquo; <a href="index.php?tab=grupy">Kierownik</a>
  <?php if ($KIER_LABEL !== ''): ?>&rsaquo; <strong><?= h($KIER_LABEL) ?></strong><?php endif; ?>
</div>
