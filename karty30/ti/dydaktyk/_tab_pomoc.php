<?php /* ═══════════════════════ TAB: GDZIE CO JEST — przewodnik po panelu ═══════════════════════ */ ?>
<?php
/**
 * Spis wszystkich funkcji panelu: co robi, gdzie jest, jeden klik.
 * Powstał, bo po ujednoliceniu układu trudno było znaleźć rzeczy znane
 * z poprzedniego panelu — dlatego przy pozycjach, które zmieniły miejsce
 * albo nazwę, jest osobna wzmianka „dawniej”.
 *
 * Zmienne z index.php: $cur_course, $course, $me.
 */
$_c = (int)$cur_course;
$_ct = fn(string $tab) => $_c ? 'index.php?course=' . $_c . '&tab=' . $tab : 'index.php?tab=' . $tab;
$_g  = fn(string $tab) => 'index.php?tab=' . $tab;

// [nazwa, adres, co robi, „dawniej” / uwaga]
$_guide = [
    'Mój panel' => [
        ['Pulpit',         $_g('pulpit'), 'Co czeka na dziś: zajęcia, obecności do uzupełnienia, protokoły bez zatwierdzenia, nieprzeczytane wiadomości.', 'Dawniej kafelki i wykresy — teraz zestawienia w tabelach.'],
        ['Gdzie co jest',  $_g('pomoc'),  'Ta strona: spis wszystkich funkcji panelu z opisem i wejściem.', ''],
        ['Frekwencja grup', $_g('frekwencja_grup'), 'Zestawienie frekwencji we wszystkich Twoich grupach.', 'Dawniej w sekcji „Planowanie” — sekcja zniknęła, pozycja została.'],
        ['Dostępność',      $_g('dostepnosc'),      'Twoje okna godzinowe w tygodniu — zajęcia można ustawiać tylko w nich.', 'Dawniej siedem kafelków dni; teraz jedna tabela z sumą godzin.'],
        ['Zapisy na zajęcia', 'rekrutacja.php',      'Zapisy kursantów na wolne terminy i tury zajęć.', ''],
        ['Plan cykliczny',  $_g('cykliczne'),       'Zajęcia stałe: reguły powtarzania i generowanie terminów.', ''],
        ['Planner',         'planner.php',                   'Układanie harmonogramu z bloków — osobne narzędzie.', ''],
    ],
    'Kurs — praca z grupą' => [
        ['Zajęcia',       $_ct('lekcje'),      'Terminy zajęć: dodawanie, edycja, obecność, odwołanie i zmiana terminu. Klik w datę (albo „Wejdź”) otwiera kartę lekcji z listą obecności.', 'Dawniej dwa widoki (lista i tabela) — został jeden, tabelaryczny.'],
        ['Uczestnicy',    $_ct('uczestnicy'),  'Kartoteka grupy: kontakt, frekwencja liczona z zajęć odbytych, liczba ocen i średnia.', 'Nowa zakładka — wcześniej dane były rozsypane po innych ekranach.'],
        ['Plan zajęć',    $_ct('plan'),        'Siatka tygodnia i wykaz terminów w miesiącu — zestawienie do wglądu, bez edycji.', 'Nowa zakładka.'],
        ['Protokoły',     $_ct('protokol'),    'Oceny końcowe całej grupy w jednej tabeli, zatwierdzenie i wydruk z ewidencją godzin oraz naliczeniem wypłaty.', 'Nowa zakładka — oceny końcowe wyszły z dziennika do protokołu.'],
        ['Oceny',         $_ct('oceny'),       'E-dziennik: oceny bieżące z kategoriami i wagami, średnia ważona.', 'Ocena końcowa jest osobno, w Protokołach.'],
        ['Zadania',       $_ct('zadania'),     'Zadania domowe: wystawianie, sprawdzanie oddanych prac i ocenianie.', ''],
        ['Materiały',     $_ct('materialy'),   'Materiały dla kursantów (eLearning): pliki i linki.', ''],
        ['Nieobecności',  $_ct('nieobecnosci'),'Usprawiedliwienia i prośby kursantów o odwołanie udziału do rozpatrzenia.', ''],
        ['Sylabus',       $_ct('program'),     'Tematy realizowane w tym kursie i ich powiązanie z zajęciami.', 'Dawniej „Program zajęć” — ta sama rzecz i te same dane, zmieniła się nazwa. Wzorzec przedmiotu prowadzi administracja.'],
        ['Testy',         $_ct('testy'),       'Testy i quizy: budowanie, udostępnianie, wyniki.', ''],
    ],
    'Komunikacja' => [
        ['Wiadomości', $_g('wiadomosci'), 'Rozmowy z kursantami, opiekunami i administracją.', ''],
        ['Komunikaty', $_g('komunikaty'), 'Ogłoszenia placówki. Nieprzeczytany komunikat pokazuje się na całą stronę przy wejściu do panelu.', ''],
    ],
    'Zasoby' => [
        ['Mój dysk',       $_g('dysk'), 'Twoje pliki w chmurze organizacji.', 'Dawniej w sekcji „Moje sprawy”.'],
        ['Zajętość Zoom',  $_g('zoom'), 'Kalendarz zajętości konta Zoom i wyjaśnienie, dlaczego termin bywa zablokowany.', 'Dawniej w sekcji „Planowanie” — sekcja zniknęła, pozycja została.'],
        ['Pełny moduł TI', rtrim(APP_URL, '/') . '/karty30/ti/index.php', 'Moduł administracyjny Zajęć TI — otwiera się w nowej karcie.', 'Wymaga uprawnień do modułu.'],
    ],
];
// Formalności to sprawa prowadzącego, ale w nawigacji stoi w sekcji Kurs
$_guide['Kurs — praca z grupą'][] = ['Formalności', $_g('formalnosci'), 'Twoje umowy i dokumenty związane z prowadzeniem zajęć.', ''];

if (dyd_is_staff()) {
    $_guide['Kierownik — tylko pracownicy D3'] = [
        ['Przegląd grup',           $_g('grupy'),        'Wszystkie grupy w placówce z podstawowymi liczbami.', ''],
        ['Rozliczenia grupy',       $_ct('rozliczenia'),          'Rozliczenia kursantów wybranej grupy.', ''],
        ['Rozliczenia kursantów',   $_g('billing'),      'Rozliczenia wszystkich kursantów, niezależnie od grupy.', ''],
        ['Zarządzanie kursami',     $_g('kursy'),        'Zakładanie i edycja kursów oraz przypisywanie prowadzących.', ''],
        ['Wypłaty prowadzących',    $_g('wypłaty'),      'Naliczenia wypłat za zajęcia — te same liczby, które trafiają na protokół.', ''],
        ['Praca własna',            $_g('praca_wlasna'), 'Zajęcia typu „praca własna prowadzącego” i ich rozliczenie.', ''],
        ['Komunikacja',             $_g('komunikacja'),  'Masowa wysyłka e-mail i SMS do grup i prowadzących.', ''],
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
    <p class="small mb-1">
      Cała nawigacja jest <strong>u góry</strong>, w dwóch rzędach — panel nie ma już menu po lewej.
      W pierwszym rzędzie wybierasz <strong>sekcję</strong> (Mój panel, Kurs, Komunikacja,
      Zasoby<?= dyd_is_staff() ? ', Kierownik' : '' ?>), w drugim — <strong>pozycję</strong> tej sekcji.
      Pod nimi <strong>okruszki</strong> pokazują, gdzie jesteś.
    </p>
    <p class="small mb-0">
      Pozycje sekcji <strong>Kurs</strong> dotyczą grupy wybranej w pasku użytkownika, na samej górze
      <?= !empty($course['name']) ? '(teraz: <strong>' . h($course['name']) . '</strong>)' : '(żadna nie jest wybrana)' ?>.
      Liczby przy pozycjach to liczniki — np. nieprzeczytane wiadomości albo nieobecności do rozpatrzenia.
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
      <li><strong>Menu po lewej</strong> — nawigacja przeniosła się na górę, do dwóch rzędów paska.</li>
      <li><strong>Sylabusy przedmiotów i okresy nauczania</strong> — prowadzi je administracja w module Zajęć TI. W panelu widzisz tematy tego kursu (zakładka Sylabus) i protokoły za okresy.</li>
      <li><strong>Zakładanie kont kursantom</strong> — po stronie administracji.</li>
    </ul>
    <p class="small text-body-secondary mb-0 mt-2">
      Czegoś brakuje albo coś działa niezrozumiale? Napisz przez
      <a href="index.php?tab=wiadomosci">Wiadomości</a> — trafi do administracji.
    </p>
  </div>
</div>
