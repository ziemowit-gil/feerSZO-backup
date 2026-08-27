<?php /* ═══════════════════════ TAB: GDZIE CO JEST — przewodnik po panelu ═══════════════════════ */ ?>
<?php
/**
 * Spis wszystkich funkcji panelu: co robi, gdzie jest, jeden klik.
 * Powstał, bo po ujednoliceniu układu trudno było znaleźć rzeczy znane
 * z poprzedniego panelu — dlatego przy pozycjach, które zmieniły miejsce
 * albo nazwę, jest osobna wzmianka „dawniej".
 *
 * Zmienne z index.php: $cur_course, $course, $me.
 */
$_c = (int)$cur_course;
$_ct = fn(string $tab) => $_c ? 'index.php?course=' . $_c . '&tab=' . $tab : 'index.php?tab=' . $tab;

// [nazwa, adres, co robi, „dawniej" / uwaga]
$_guide = [
    'Kurs — praca z grupą' => [
        ['Zajęcia',       $_ct('lekcje'),      'Terminy zajęć: dodawanie, edycja, obecność, odwołanie i zmiana terminu. Jedna tabela z grupowaniem po miesiącach.', 'Dawniej dwa widoki (lista i tabela) — został jeden, tabelaryczny.'],
        ['Uczestnicy',    $_ct('uczestnicy'),  'Kartoteka grupy: kontakt, frekwencja liczona z zajęć odbytych, liczba ocen i średnia.', 'Nowa zakładka — wcześniej dane były rozsypane po innych ekranach.'],
        ['Plan zajęć',    $_ct('plan'),        'Siatka tygodnia i wykaz terminów w miesiącu — zestawienie do wglądu, bez edycji.', 'Nowa zakładka.'],
        ['Protokoły',     $_ct('protokol'),    'Oceny końcowe całej grupy w jednej tabeli, zatwierdzenie i wydruk z ewidencją godzin oraz naliczeniem wypłaty.', 'Nowa zakładka — oceny końcowe wyszły z dziennika do protokołu.'],
        ['Oceny',         $_ct('oceny'),       'E-dziennik: oceny bieżące z kategoriami i wagami, średnia ważona.', 'Ocena końcowa jest osobno, w Protokołach.'],
        ['Zadania',       $_ct('zadania'),     'Zadania domowe: wystawianie, sprawdzanie oddanych prac i ocenianie.', ''],
        ['Materiały',     $_ct('materialy'),   'Materiały dla kursantów (eLearning): pliki i linki.', ''],
        ['Nieobecności',  $_ct('nieobecnosci'),'Usprawiedliwienia i prośby kursantów o odwołanie udziału do rozpatrzenia.', ''],
        ['Program',       $_ct('program'),     'Plan nauczania kursu — punkty programu i powiązanie z zajęciami.', 'Wzorcem jest sylabus przedmiotu, prowadzony przez administrację.'],
        ['Testy',         $_ct('testy'),       'Testy i quizy: budowanie, udostępnianie, wyniki.', ''],
    ],
    'Planowanie' => [
        ['Frekwencja grup', 'index.php?tab=frekwencja_grup', 'Zestawienie frekwencji we wszystkich Twoich grupach.', ''],
        ['Dostępność',      'index.php?tab=dostepnosc',      'Twoje okna godzinowe w tygodniu — zajęcia można ustawiać tylko w nich.', 'Dawniej siedem kafelków dni; teraz jedna tabela z sumą godzin.'],
        ['Zajętość Zoom',   'index.php?tab=zoom',            'Kalendarz zajętości konta Zoom i wyjaśnienie, dlaczego termin bywa zablokowany.', 'Nowa zakładka — jeden host Zoom nie prowadzi dwóch spotkań naraz.'],
        ['Plan cykliczny',  'index.php?tab=cykliczne',       'Zajęcia stałe: reguły powtarzania i generowanie terminów.', ''],
        ['Planner',         'planner.php',                   'Układanie harmonogramu z bloków — osobne narzędzie.', ''],
    ],
    'Komunikacja' => [
        ['Wiadomości', 'index.php?tab=wiadomosci', 'Rozmowy z kursantami, opiekunami i administracją.', ''],
        ['Komunikaty', 'index.php?tab=komunikaty', 'Ogłoszenia placówki. Nieprzeczytany komunikat pokazuje się na całą stronę przy wejściu do panelu.', ''],
    ],
    'Moje sprawy' => [
        ['Formalności', 'index.php?tab=formalnosci', 'Twoje umowy i dokumenty związane z prowadzeniem zajęć.', ''],
        ['Mój dysk',    'index.php?tab=dysk',        'Twoje pliki w chmurze organizacji.', ''],
    ],
];

if (dyd_is_staff()) {
    $_guide['Kierownik — tylko pracownicy D3'] = [
        ['Przegląd grup',           'index.php?tab=grupy',        'Wszystkie grupy w placówce z podstawowymi liczbami.', ''],
        ['Rozliczenia grupy',       $_ct('rozliczenia'),          'Rozliczenia kursantów wybranej grupy.', ''],
        ['Rozliczenia kursantów',   'index.php?tab=billing',      'Rozliczenia wszystkich kursantów, niezależnie od grupy.', ''],
        ['Zarządzanie kursami',     'index.php?tab=kursy',        'Zakładanie i edycja kursów oraz przypisywanie prowadzących.', ''],
        ['Wypłaty prowadzących',    'index.php?tab=wypłaty',      'Naliczenia wypłat za zajęcia — te same liczby, które trafiają na protokół.', ''],
        ['Praca własna',            'index.php?tab=praca_wlasna', 'Zajęcia typu „praca własna prowadzącego" i ich rozliczenie.', ''],
        ['Komunikacja',             'index.php?tab=komunikacja',  'Masowa wysyłka e-mail i SMS do grup i prowadzących.', ''],
        ['Pełny panel TI',          '../index.php',               'Moduł administracyjny Zajęć TI: sylabusy, okresy nauczania, dziennik ocen, raporty.', 'Tam zamyka się okres nauczania — wymaga zatwierdzonych protokołów.'],
    ];
}
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-compass me-2" aria-hidden="true"></i>Gdzie co jest</h1>
  <span class="badge bg-secondary"><?= array_sum(array_map('count', $_guide)) ?> funkcji</span>
</div>

<div class="card">
  <div class="card-body py-2">
    <p class="small mb-0">
      Panel ma trzy stałe elementy: <strong>pasek sekcji</strong> na górze (Mój panel, Moje zajęcia,
      Planowanie, Komunikacja, Moje sprawy<?= dyd_is_staff() ? ', Kierownik' : '' ?>),
      pod nim <strong>okruszki</strong> pokazujące, gdzie jesteś, i <strong>menu</strong> po lewej.
      Zakładki oznaczone „KURS" dotyczą grupy wybranej w pasku u góry
      <?= !empty($course['name']) ? '(teraz: <strong>' . h($course['name']) . '</strong>)' : '(żadna nie jest wybrana)' ?>.
    </p>
  </div>
</div>

<?php foreach ($_guide as $_sec => $_items): ?>
<div class="card">
  <div class="card-header"><?= h($_sec) ?></div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Funkcje w sekcji <?= h($_sec) ?> z opisem i odnośnikiem</caption>
      <thead><tr>
        <th scope="col" style="width:14rem">Funkcja</th>
        <th scope="col">Co robi</th>
        <th scope="col" style="width:6rem" class="text-end">Wejście</th>
      </tr></thead>
      <tbody>
        <?php foreach ($_items as [$_name, $_href, $_what, $_note]): ?>
        <tr>
          <th scope="row" class="fw-semibold small"><?= h($_name) ?></th>
          <td class="small">
            <?= h($_what) ?>
            <?php if ($_note !== ''): ?>
            <div class="text-body-secondary"><i class="bi bi-arrow-return-right me-1" aria-hidden="true"></i><?= h($_note) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a href="<?= h($_href) ?>" class="btn btn-sm btn-outline-primary" aria-label="Otwórz: <?= h($_name) ?>">Otwórz</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<div class="card">
  <div class="card-header">Czego tu nie ma</div>
  <div class="card-body">
    <ul class="small mb-0">
      <li><strong>Wybór wyglądu panelu</strong> — panel ma jeden układ; przełącznik widoku został wycofany.</li>
      <li><strong>Sylabusy przedmiotów i okresy nauczania</strong> — prowadzi je administracja w module Zajęć TI. W panelu widzisz program kursu (zakładka Program) i protokoły za okresy.</li>
      <li><strong>Zakładanie kont kursantom</strong> — po stronie administracji.</li>
    </ul>
    <p class="small text-body-secondary mb-0 mt-2">
      Czegoś brakuje albo coś działa niezrozumiale? Napisz przez
      <a href="index.php?tab=wiadomosci">Wiadomości</a> — trafi do administracji.
    </p>
  </div>
</div>
