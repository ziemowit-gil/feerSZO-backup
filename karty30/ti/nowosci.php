<?php
/**
 * karty30/ti/nowosci.php — „Co nowego w panelu” (strona publiczna, bez logowania), w wyglądzie portalu FEER
 * (modules/ti/view/portal: nagłówek z logo, pasek ułatwień dostępu, menu, stopka).
 * Treść: nowe logowanie (System Tożsamości, konta sprzed 10.10.2026, hasło 12 znaków, SMS Sendly, klucz),
 * nowa strona logowania i konto kandydata (zapisy), nowy wygląd panelu, lekcje, konto, inne zmiany.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

$_login = '/login.php';
$_panel = '/karty30/ti/kursant/index.php';
$_open  = org_setting('ti_nabor_enabled') === '1';
$_org   = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja FEER'));

/** Karta w siatce: ikona, tytuł, treść (HTML), podpowiedź. */
function nwCard(string $icon, string $title, string $html, string $tip = ''): string {
    return '<article class="nw-card"><h3><i class="bi ' . $icon . '" aria-hidden="true"></i> ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
         . '<div>' . $html . '</div>' . ($tip !== '' ? '<p class="nw-tip"><i class="bi bi-lightbulb" aria-hidden="true"></i> ' . $tip . '</p>' : '') . '</article>';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Co nowego w panelu — <?= h($_org) ?></title>
<meta name="description" content="Nowe logowanie, konto kandydata, nowy wygląd panelu kursanta i ułatwienia dostępu.">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php include dirname(__DIR__, 2) . '/modules/ti/view/portal/_style.php'; ?>
<style>
  .nw-hero { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 2rem; align-items: center; }
  .nw-date { display: inline-block; background: var(--pp-bg); color: var(--pp-blue); font-weight: 700; font-size: .85rem; border-radius: 999px; padding: .2rem .8rem; margin-bottom: .6rem; }
  .nw-lead { font-size: 1.12rem; color: var(--pp-muted); margin: 0 0 1.2rem; }
  .nw-actions { display: flex; flex-wrap: wrap; gap: .6rem; }
  .nw-mosaic { display: grid; grid-template-columns: repeat(3, 1fr); grid-auto-rows: 78px; gap: 8px; }
  .nw-mosaic span { display: flex; align-items: flex-end; padding: .55rem .7rem; border-radius: .8rem; font-weight: 700; font-size: .85rem; background: var(--pp-bg); color: var(--pp-ink); }
  .nw-mosaic .nw-wide { grid-column: span 2; background: var(--pp-blue); color: #fff; }
  .nw-toc { display: flex; flex-wrap: wrap; gap: .4rem; list-style: none; padding: .3rem; margin: 2rem 0 1.5rem; background: var(--pp-bg); border-radius: .9rem; }
  .nw-toc a { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem 1rem; border-radius: .7rem; text-decoration: none; color: var(--pp-ink); font-weight: 500; }
  .nw-toc a:hover { background: #fff; color: var(--pp-blue); }
  .nw-sec { margin-top: 2.4rem; scroll-margin-top: 1rem; }
  .nw-sec > h2 { font-size: 1.45rem; font-weight: 500; display: flex; align-items: center; gap: .55rem; margin: 0 0 1rem; }
  .nw-sec > h2 .bi { color: var(--pp-blue); }
  .nw-alert { background: var(--pp-bg); border-left: 5px solid var(--pp-blue); border-radius: .8rem; padding: 1rem 1.2rem; margin-bottom: 1.2rem; }
  .nw-alert h3 { margin: 0 0 .4rem; font-size: 1.05rem; }
  .nw-alert p { margin: 0 0 .5rem; } .nw-alert ol { margin: .4rem 0 0; padding-left: 1.3rem; }
  .nw-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
  .nw-card { border: 1px solid var(--pp-line); border-radius: var(--pp-radius); padding: 1rem 1.2rem; background: #fff; }
  .nw-card h3 { font-size: 1.02rem; margin: 0 0 .45rem; display: flex; gap: .5rem; align-items: baseline; }
  .nw-card h3 .bi { color: var(--pp-blue); }
  .nw-card p { margin: 0 0 .4rem; }
  .nw-tip { font-size: .88rem; color: var(--pp-muted); margin-top: .5rem !important; }
  .nw-card code, .nw-alert code { background: var(--pp-bg); padding: .05rem .35rem; border-radius: .3rem; }
  .nw-new { display: inline-block; background: var(--pp-green); color: #fff; font-size: .7rem; font-weight: 700; border-radius: 999px; padding: .05rem .5rem; margin-left: .3rem; vertical-align: middle; }
  .nw-end { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid var(--pp-line); }
  @media (max-width: 860px) { .nw-hero { grid-template-columns: 1fr; } .nw-mosaic { display: none; } }
</style>
</head>
<body class="pp">
<?php
$ppTitle = 'Panel Kursanta SSO'; $ppOrg = $_org; $ppCrumb = 'Co nowego'; $ppLogo = ''; $ppLoginUrl = $_login; $ppAccount = null;
include dirname(__DIR__, 2) . '/modules/ti/view/portal/_header.php';
?>
<main id="pp-main" class="pp-wrap">
  <div class="nw-hero">
    <div>
      <span class="nw-date">Październik 2026</span>
      <h1 class="pp-h1" style="margin-bottom:.6rem">Co nowego w panelu</h1>
      <p class="nw-lead">Nowa strona logowania dla wszystkich ról, konto kandydata do zapisów na zajęcia, prostszy panel kursanta i ułatwienia dostępu na każdej stronie.</p>
      <div class="nw-actions">
        <a class="pp-submit" href="#logowanie">Nowe zasady logowania</a>
        <a class="pp-btn-log" href="#kandydat">Konto kandydata</a>
      </div>
    </div>
    <div class="nw-mosaic" aria-hidden="true">
      <span class="nw-wide">Najbliższe zajęcia — za 1 godz.</span><span>Zadania</span>
      <span>Oceny</span><span>Testy</span><span>Komunikaty</span>
    </div>
  </div>

  <nav aria-label="Spis treści">
    <ul class="nw-toc">
      <li><a href="#logowanie"><i class="bi bi-shield-lock" aria-hidden="true"></i> Logowanie</a></li>
      <li><a href="#kandydat"><i class="bi bi-person-badge" aria-hidden="true"></i> Konto kandydata</a></li>
      <li><a href="#dostepnosc"><i class="bi bi-universal-access" aria-hidden="true"></i> Dostępność</a></li>
      <li><a href="#wyglad"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Wygląd panelu</a></li>
      <li><a href="#nauka"><i class="bi bi-calendar-check" aria-hidden="true"></i> Lekcje i testy</a></li>
      <li><a href="#konto"><i class="bi bi-person-gear" aria-hidden="true"></i> Konto</a></li>
      <li><a href="#inne"><i class="bi bi-info-circle" aria-hidden="true"></i> Inne</a></li>
    </ul>
  </nav>

  <section class="nw-sec" id="logowanie" aria-labelledby="h-log">
    <h2 id="h-log"><i class="bi bi-shield-lock" aria-hidden="true"></i> Nowe zasady logowania</h2>
    <div class="nw-alert">
      <h3>Konto założone przed 10.10.2026? Zaloguj się jak za pierwszym razem</h3>
      <p>Od 10.10.2026 działa nowy System Tożsamości. Dotychczasowych haseł nie da się do niego przenieść, dlatego konta założone przed 10 października 2026 traktujemy jak nowe. Dane, lekcje, oceny i zadania zostają bez zmian.</p>
      <ol>
        <li>Wejdź na <a href="<?= h($_login) ?>">stronę logowania</a>, wybierz <strong>Kursant</strong> i wpisz login.</li>
        <li>Na numer telefonu z Twojego konta przyjdzie kod SMS — wpisz go. Starego hasła nie potrzebujesz.</li>
        <li>Ustaw nowe hasło (zasady niżej).</li>
        <li>Jeśli chcesz, dodaj klucz bezpieczeństwa — logowanie będzie szybsze i bezpieczniejsze.</li>
      </ol>
    </div>
    <div class="nw-grid">
      <?= nwCard('bi-door-open', 'Jedna strona logowania' , '<p>Na stronie logowania wybierasz, kim jesteś: <strong>Kursant, Rodzic / opiekun, Osoba upoważniona, Kandydat (zapisy)</strong> albo <strong>Prowadzący / kadra</strong>. Obok formularza są inne sposoby: Microsoft 365 (kadra), kod SMS (rodzic), założenie konta kandydata.</p><span class="nw-new">nowe</span>') ?>
      <?= nwCard('bi-key', 'Mocniejsze hasło', '<p>Co najmniej <strong>12 znaków</strong>, w tym duża i mała litera, cyfra oraz znak specjalny (np. <code>!</code>, <code>#</code>, <code>?</code>).</p>', 'Hasło zmienisz w Systemie Tożsamości: Konto → Ustawienia → Bezpieczeństwo.') ?>
      <?= nwCard('bi-fingerprint', 'Klucz bezpieczeństwa (passkey)', '<p>Logowanie odciskiem palca, twarzą, kodem PIN telefonu albo kluczem USB — bez wpisywania hasła. Klucz zostaje na Twoim urządzeniu.</p>', 'Zalecamy, ale nie jest obowiązkowy — hasło nadal działa.') ?>
      <?= nwCard('bi-image', 'Obrazek bezpieczeństwa', '<p>Po wpisaniu loginu zobaczysz swój obrazek z opisem dla czytnika ekranu. <strong>Jeśli go nie widzisz albo widzisz inny — nie wpisuj hasła.</strong></p>') ?>
      <?= nwCard('bi-chat-dots', 'Kody SMS wysyła Sendly', '<p>Wiadomości z kodami wysyła w naszym imieniu firma Sendly — to ona jest nadawcą SMS-a.</p>', 'Nigdy nie podawaj kodu nikomu. Pracownicy Fundacji o niego nie proszą.') ?>
      <?= nwCard('bi-envelope-slash', 'Hasła nie wysyłamy e-mailem', '<p>Ze względów bezpieczeństwa nie przesyłamy haseł pocztą. Gdy zapomnisz hasła, zaloguj się kodem SMS i ustaw nowe.</p>') ?>
    </div>
  </section>

  <section class="nw-sec" id="kandydat" aria-labelledby="h-kand">
    <h2 id="h-kand"><i class="bi bi-person-badge" aria-hidden="true"></i> Zapisy na zajęcia i konto kandydata <span class="nw-new">nowe</span></h2>
    <div class="nw-grid">
      <?= nwCard('bi-pencil-square', 'Zgłoszenie przez internet', '<p>Rodzic lub opiekun zgłasza dziecko w kilku krokach: grupa (albo „termin do ustalenia”), dane uczestnika, dane opiekuna, zgody, podsumowanie. Numer telefonu potwierdzasz kodem SMS, a adres e-mail — linkiem.</p>'
          . ($_open ? '<p><a href="/nabor">Przejdź do formularza zapisów</a></p>' : '<p>Formularz pojawi się, gdy otworzymy nabór.</p>')) ?>
      <?= nwCard('bi-person-check', 'Konto kandydata', '<p>Na koncie widzisz <strong>historię zgłoszenia i komentarze</strong>, dokumenty, o które prosimy, a po przyjęciu — potwierdzasz miejsce, akceptujesz warunki i przechodzisz do płatności.</p><p><a href="/nabor/rejestracja">Załóż konto</a> · <a href="/nabor/logowanie">Zaloguj się</a></p>') ?>
      <?= nwCard('bi-lightning', 'Kolejne zgłoszenia szybciej', '<p>Zalogowany opiekun nie musi ponownie potwierdzać telefonu ani e-maila — dane uzupełniamy z konta, a zgłoszenie od razu trafia do rozpatrzenia.</p>') ?>
      <?= nwCard('bi-box-arrow-in-right', 'Po przyjęciu', '<p>Uczestnik dostaje login do panelu kursanta, a opiekun małoletniego — login do panelu rodzica. Dane logowania przychodzą SMS-em.</p>') ?>
    </div>
  </section>

  <section class="nw-sec" id="dostepnosc" aria-labelledby="h-dost">
    <h2 id="h-dost"><i class="bi bi-universal-access" aria-hidden="true"></i> Ułatwienia dostępu</h2>
    <div class="nw-grid">
      <?= nwCard('bi-sliders', 'Pasek „Ułatwienia dostępu”', '<p>Na górze stron logowania, zapisów i konta kandydata: <strong>Kontrast</strong> (żółte i białe na czarnym), <strong>tekst A / A+ / A++</strong>, <strong>Odstępy</strong> i <strong>Podkreśl linki</strong>. Przeglądarka zapamiętuje wybór.</p><span class="nw-new">nowe</span>') ?>
      <?= nwCard('bi-keyboard', 'Klawiatura i czytnik ekranu', '<p>Wyraźna ramka przy obsłudze klawiaturą, link „Przejdź do treści”, opisy dla czytnika ekranu, komunikaty błędów odczytywane od razu.</p>') ?>
      <?= nwCard('bi-gear', 'Ułatwienia w panelu', '<p>W panelu kursanta: Ustawienia → Ułatwienia — większy tekst, wysoki kontrast i wyłączenie animacji. Stany opisane słowem i ikoną, nie samym kolorem.</p>') ?>
    </div>
  </section>

  <section class="nw-sec" id="wyglad" aria-labelledby="h-wyg">
    <h2 id="h-wyg"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Nowy wygląd panelu kursanta</h2>
    <div class="nw-alert"><p style="margin:0">Od października 2026 panel kursanta działa tylko w nowym wyglądzie — nie trzeba niczego włączać. Funkcje i dane są na swoim miejscu. Czegoś nie widzisz? Użyj wyszukiwarki na ekranie startowym albo napisz przez Pomoc.</p></div>
    <div class="nw-grid">
      <?= nwCard('bi-grid-3x3-gap', 'Ekran startowy z kafelkami', '<p>Od razu widzisz <strong>Na dziś</strong>: najbliższe zajęcia z odliczaniem czasu, zadania do oddania i oddane, a niżej najważniejsze moduły.</p>', 'Wyszukiwarka na ekranie startowym: np. „oceny”, „zapisy”.') ?>
      <?= nwCard('bi-camera-video', 'Okienko „Masz dziś zajęcia”', '<p>W dniu lekcji panel przypomni o zajęciach i pokaże duży przycisk „Dołącz do zajęć” albo przejście do „Moich lekcji”.</p>') ?>
      <?= nwCard('bi-list', 'Menu takie, jakie lubisz', '<p>Trzy układy: zakładki, rozwijana sekcja albo menu rozwijane.</p>', 'Konto → Ustawienia → Menu panelu.') ?>
    </div>
  </section>

  <section class="nw-sec" id="nauka" aria-labelledby="h-nau">
    <h2 id="h-nau"><i class="bi bi-calendar-check" aria-hidden="true"></i> Lekcje, zadania i testy</h2>
    <div class="nw-grid">
      <?= nwCard('bi-journal-text', 'Moje lekcje', '<p>Lista lekcji po lewej, narzędzia po prawej (statystyki, pobieranie, druk, kalendarz). Czytelne statusy: Planowana, Zakończona, Odwołana — oraz widoczne przyciski Odwołaj i Przesuń.</p>') ?>
      <?= nwCard('bi-folder2-open', 'Zadania i materiały', '<p>Zadania w równych kafelkach — od razu widać, co oddać i do kiedy. Możesz najpierw wybrać lekcję, a potem jej materiały.</p>') ?>
      <?= nwCard('bi-ui-checks', 'Testy w jednym miejscu', '<p>Testy, kartkówki i egzaminy w jednej zakładce — najpierw te w toku. Nowe pytania: dopasuj pary i ułóż w kolejności, w pełni z klawiatury.</p>') ?>
      <?= nwCard('bi-people', 'Kilka grup? Łatwa zmiana', '<p>U góry każdej strony aktywna grupa i przycisk „Zmień grupę” — bez wylogowania.</p>') ?>
      <?= nwCard('bi-send', 'Napisz do prowadzącego', '<p>Wiadomość i prośbę o odwołanie lub przesunięcie zajęć wyślesz jednym kliknięciem z ekranu startowego.</p>') ?>
    </div>
  </section>

  <section class="nw-sec" id="konto" aria-labelledby="h-kon">
    <h2 id="h-kon"><i class="bi bi-person-gear" aria-hidden="true"></i> Konto i ustawienia</h2>
    <div class="nw-grid">
      <?= nwCard('bi-person-check', 'Sam upoważnisz zaufaną osobę', '<p>Dostęp do podglądu panelu dla np. członka rodziny lub asystenta — widzi lekcje, frekwencję i rozliczenia, ale niczego nie zmienia. Zablokujesz lub odwołasz go w każdej chwili.</p>', 'Sprawy → Upoważnieni.') ?>
      <?= nwCard('bi-microsoft', 'Microsoft 365 (EDU)', '<p>Masz szkolne konto Microsoft 365? W panelu jest zakładka z loginem i skrótami do Outlooka, Teams, OneDrive, Worda i Excela.</p>') ?>
      <?= nwCard('bi-sliders2', 'Uporządkowane ustawienia', '<p>Wszystko na jednej stronie, w kafelkach: Menu panelu, Ułatwienia, Bezpieczeństwo, Powiadomienia, Push i Kalendarz.</p>') ?>
    </div>
  </section>

  <section class="nw-sec" id="inne" aria-labelledby="h-inn">
    <h2 id="h-inn"><i class="bi bi-info-circle" aria-hidden="true"></i> Inne zmiany</h2>
    <div class="nw-grid">
      <?= nwCard('bi-wallet2', 'Płatności w portalu płatności', '<p>Wpłaty, saldo i portfel są w osobnym portalu płatności. W zakładkach „Rozliczenia” i „Portfel” znajdziesz link.</p>') ?>
      <?= nwCard('bi-stars', 'Licencja Claude', '<p>Od 17 października 2026 licencja Claude nie jest już rozliczana przez Fundację. Szczegóły w zakładce Moje licencje.</p>') ?>
    </div>
  </section>

  <div class="nw-end">
    <a class="pp-submit" href="<?= h($_login) ?>">Zaloguj się do panelu</a>
    <a class="pp-btn-log" href="<?= h($_panel) ?>?tab=problem">Potrzebuję pomocy</a>
  </div>
</main>
<?php
$ppFootLinks = ['Logowanie' => $_login, 'Konto kandydata' => '/nabor/logowanie', 'Czym jest SSO' => '/karty30/ti/sso.php', 'Strona Fundacji FEER' => 'https://feer.org.pl'];
include dirname(__DIR__, 2) . '/modules/ti/view/portal/_footer.php';
?>
</body>
</html>
