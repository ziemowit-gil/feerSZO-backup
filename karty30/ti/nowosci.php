<?php
/**
 * karty30/ti/nowosci.php — „Co nowego w panelu kursanta” (strona publiczna, bez logowania).
 * Opis zmian: nowy wygląd METRO, ekran startowy, menu, Moje lekcje, testy, upoważnienia,
 * oraz nowe zasady logowania (System Tożsamości, konta sprzed 10.10.2026, hasło 12 znaków, SMS Sendly, klucz).
 * Linki: login.php (stopka) i ekran startowy panelu (_alt_start.php).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

$_login = 'login.php';
$_panel = 'kursant/index.php';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Co nowego w panelu kursanta — Fundacja FEER</title>
<meta name="description" content="Nowy wygląd panelu kursanta (METRO) i nowe zasady logowania.">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  /* Wygląd jak logowanie TI (login.php): METRO, ciemna kolumna, jasne tło, kwadratowe karty, przyciski #1ba1e2. */
  :root { --tekst: #1d1d1d; --tlo: #f3f4f6; --akcent: #1ba1e2; --przycisk: #1ba1e2; --link: #1d4ed8; --fokus: #facc15; --szary: #4b5563; --cien: 0 2px 10px rgba(0,0,0,.08); }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Segoe UI", system-ui, -apple-system, sans-serif; font-size: 1rem; line-height: 1.6; color: var(--tekst); background: var(--tlo); }
  a { color: var(--link); }
  .nw-baner { background: var(--akcent); color: #fff; padding: .6rem 1rem; text-align: center; font-weight: 600; font-size: .95rem; }
  .nw-baner a { color: #fff; text-decoration: underline; margin-left: .5rem; }
  a:focus-visible, .nw-btn:focus-visible { outline: 3px solid var(--fokus); outline-offset: 2px; }
  .nw-skip { position: absolute; left: -9999px; top: 0; background: var(--fokus); color: var(--tekst); padding: .6rem 1rem; font-weight: 700; z-index: 10; }
  .nw-skip:focus { left: 1rem; top: 1rem; }
  .nw-wrap { max-width: 1100px; margin: 0 auto; padding: 0 16px; }

  .nw-top { background: #fff; box-shadow: var(--cien); }
  .nw-top .nw-wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; min-height: 64px; }
  .nw-marka { font-weight: 700; font-size: 1.05rem; color: var(--tekst); text-decoration: none; display: inline-flex; align-items: center; gap: .5rem; }
  .nw-btn { display: inline-flex; align-items: center; gap: .5rem; min-height: 48px; padding: .55rem 1.2rem; font-weight: 700; text-decoration: none;
            background: var(--przycisk); color: #fff; border: 2px solid var(--przycisk); }
  .nw-btn:hover { background: #1790c9; border-color: #1790c9; color: #fff; }
  .nw-btn-jasny { background: #fff; color: var(--tekst); border-color: var(--akcent); }
  .nw-btn-jasny:hover { background: #e6f6fc; color: var(--tekst); }

  .nw-hero { background: #1d1d1d; color: #fff; }
  .nw-hero h1 { color: #fff; }
  .nw-hero a:focus-visible { outline-color: var(--fokus); }
  .nw-hero .nw-wrap { padding-top: 2.5rem; padding-bottom: 2.5rem; display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 2rem; align-items: center; }
  .nw-data { display: inline-block; font-size: .85rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #7dd3fc; margin-bottom: .5rem; }
  h1 { font-size: clamp(1.8rem, 4vw, 2.6rem); line-height: 1.15; margin: 0 0 .8rem; }
  .nw-lead { font-size: 1.15rem; color: #cbd5e1; margin: 0 0 1.5rem; }
  .nw-akcje { display: flex; flex-wrap: wrap; gap: .6rem; }
  .nw-mozaika { display: grid; grid-template-columns: repeat(3, 1fr); grid-auto-rows: 84px; gap: 6px; }
  .nw-mozaika span { display: flex; align-items: flex-end; padding: .5rem .6rem; font-weight: 700; font-size: .85rem; background: #fff; color: var(--tekst); border-bottom: 5px solid var(--k, var(--akcent)); }
  .nw-mozaika .nw-szer { grid-column: span 2; }
  .nw-mozaika .nw-nieb { background: var(--akcent); color: #fff; }

  main { padding: 2rem 0 3rem; }
  .nw-spis { display: flex; flex-wrap: wrap; gap: .5rem; margin: 0 0 2rem; padding: 0; list-style: none; }
  .nw-spis a { display: inline-flex; align-items: center; gap: .4rem; min-height: 44px; padding: .4rem .9rem; background: #fff; color: var(--tekst);
               box-shadow: var(--cien); border-bottom: 4px solid var(--akcent); font-weight: 600; text-decoration: none; }
  .nw-spis a:hover { background: #e6f6fc; text-decoration: underline; }

  section.nw-sekcja { margin-bottom: 2.5rem; scroll-margin-top: 1rem; }
  h2 { font-size: 1.5rem; margin: 0 0 1rem; padding-bottom: .3rem; border-bottom: 3px solid var(--akcent); display: flex; align-items: center; gap: .6rem; }
  h2 .bi { color: var(--akcent); }
  .nw-kafle { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: .75rem; }
  .nw-kafel { background: #fff; box-shadow: var(--cien); border-bottom: 5px solid var(--k, var(--akcent)); padding: 1rem 1.1rem; }
  .nw-kafel h3 { font-size: 1.05rem; margin: .3rem 0 .4rem; display: flex; align-items: center; gap: .5rem; }
  .nw-kafel h3 .bi { font-size: 1.4rem; color: var(--akcent); }
  .nw-kafel p { margin: 0 0 .4rem; }
  .nw-kafel p:last-child { margin-bottom: 0; }
  .nw-gdzie { font-size: .88rem; color: var(--szary); }
  .nw-gdzie strong { color: var(--tekst); }

  .nw-wazne { background: #fff; box-shadow: var(--cien); border-left: 10px solid #f0a30a; padding: 1.1rem 1.25rem; margin-bottom: 1rem; }
  .nw-wazne h3 { margin: 0 0 .4rem; font-size: 1.1rem; }
  .nw-wazne p { margin: 0; }
  .nw-kroki { counter-reset: krok; list-style: none; padding: 0; margin: 0 0 1rem; display: grid; gap: .5rem; }
  .nw-kroki li { counter-increment: krok; background: #fff; box-shadow: var(--cien); padding: .75rem 1rem .75rem 3.6rem; position: relative; min-height: 56px; }
  .nw-kroki li::before { content: counter(krok); position: absolute; left: 0; top: 0; bottom: 0; width: 2.8rem; display: flex; align-items: center; justify-content: center;
                         background: #1d1d1d; color: #fff; font-weight: 800; font-size: 1.2rem; }

  .nw-stopka { padding: 1rem 0 1.5rem; font-size: .85rem; color: var(--szary); }
  .nw-stopka .nw-wrap { display: flex; flex-wrap: wrap; gap: .5rem 2rem; justify-content: center; text-align: center; }

  @media (max-width: 767.98px) {
    .nw-hero .nw-wrap { grid-template-columns: minmax(0, 1fr); }
    .nw-mozaika { display: none; }
  }
  @media (prefers-reduced-motion: reduce) { * { scroll-behavior: auto !important; transition: none !important; } }
  html { scroll-behavior: smooth; }
</style>
</head>
<body>
<a class="nw-skip" href="#tresc">Przejdź do treści</a>
<div class="nw-baner" role="region" aria-label="Informacja o logowaniu">
  Logowanie centralne przez System Tożsamości — adresy i zapisane linki bez zmian.
  <a href="sso.php">Czym jest SSO?</a>
</div>

<header class="nw-top">
  <div class="nw-wrap">
    <a class="nw-marka" href="<?= h($_login) ?>"><i class="bi bi-mortarboard" aria-hidden="true"></i>Panel kursanta — Fundacja FEER</a>
    <a class="nw-btn" href="<?= h($_login) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Zaloguj się</a>
  </div>
</header>

<div class="nw-hero">
  <div class="nw-wrap">
    <div>
      <span class="nw-data">Październik 2026</span>
      <h1>Co nowego w panelu kursanta</h1>
      <p class="nw-lead">Panel ma nowy, prostszy wygląd w stylu METRO — z kafelkami, dużymi przyciskami i lepszą obsługą z klawiatury i czytnika ekranu. Zmieniły się też zasady logowania.</p>
      <div class="nw-akcje">
        <a class="nw-btn" href="#logowanie"><i class="bi bi-shield-lock" aria-hidden="true"></i>Nowe zasady logowania</a>
        <a class="nw-btn nw-btn-jasny" href="#wyglad"><i class="bi bi-grid-1x2" aria-hidden="true"></i>Nowy wygląd</a>
      </div>
    </div>
    <div class="nw-mozaika" aria-hidden="true">
      <span class="nw-szer" style="--k:#fcd116">Najbliższe zajęcia — za 1 godz.</span>
      <span style="--k:#f0a30a">Zadania</span>
      <span class="nw-nieb" style="--k:#1d1d1d">Oceny</span>
      <span style="--k:#60a917">Testy</span>
      <span class="nw-nieb" style="--k:#1d1d1d">Komunikaty</span>
    </div>
  </div>
</div>

<main id="tresc" tabindex="-1">
  <div class="nw-wrap">

    <nav aria-label="Spis treści">
      <ul class="nw-spis">
        <li><a href="#logowanie"><i class="bi bi-shield-lock" aria-hidden="true"></i>Logowanie</a></li>
        <li><a href="#wyglad"><i class="bi bi-grid-1x2" aria-hidden="true"></i>Nowy wygląd</a></li>
        <li><a href="#nauka"><i class="bi bi-calendar-check" aria-hidden="true"></i>Lekcje, zadania i testy</a></li>
        <li><a href="#konto"><i class="bi bi-person-gear" aria-hidden="true"></i>Konto i ustawienia</a></li>
        <li><a href="#inne"><i class="bi bi-info-circle" aria-hidden="true"></i>Inne zmiany</a></li>
      </ul>
    </nav>

    <!-- ── Logowanie ─────────────────────────────────────────────── -->
    <section class="nw-sekcja" id="logowanie" aria-labelledby="h-logowanie">
      <h2 id="h-logowanie"><i class="bi bi-shield-lock" aria-hidden="true"></i>Nowe zasady logowania</h2>

      <div class="nw-wazne" role="note">
        <h3>Konto założone przed 10.10.2026? Zaloguj się jak za pierwszym razem</h3>
        <p>Przeszliśmy na nowy System Tożsamości. Dotychczasowych haseł nie da się do niego przenieść, dlatego wszystkie konta założone przed 10 października 2026 traktujemy jak nowe. Dane, lekcje, oceny i zadania zostają bez zmian.</p>
      </div>

      <p><strong>Jak zalogować się pierwszy raz:</strong></p>
      <ol class="nw-kroki">
        <li>Wejdź na stronę logowania, wybierz <strong>Kursant</strong> i wpisz swój login.</li>
        <li>Na numer telefonu zapisany w Twoim koncie przyjdzie <strong>kod SMS</strong>. Wpisz go — nie musisz pamiętać starego hasła.</li>
        <li>Ustaw <strong>nowe hasło</strong> (zasady poniżej).</li>
        <li>Jeśli chcesz, dodaj <strong>klucz bezpieczeństwa</strong> — wtedy logowanie będzie szybsze i bezpieczniejsze.</li>
      </ol>

      <ul class="nw-kafle">
        <li class="nw-kafel">
          <h3><i class="bi bi-key" aria-hidden="true"></i>Mocniejsze hasło</h3>
          <p>Hasło ma co najmniej <strong>12 znaków</strong>, w tym dużą i małą literę, cyfrę oraz znak specjalny (np. <code>!</code>, <code>#</code>, <code>?</code>).</p>
          <p class="nw-gdzie">Hasło zmienisz w <strong>Systemie Tożsamości</strong> — link jest w panelu: <strong>Konto → Ustawienia → Bezpieczeństwo</strong>.</p>
        </li>
        <li class="nw-kafel" style="--k:#60a917">
          <h3><i class="bi bi-fingerprint" aria-hidden="true"></i>Klucz bezpieczeństwa (passkey)</h3>
          <p>To logowanie odciskiem palca, twarzą, kodem PIN telefonu albo kluczem USB — bez wpisywania hasła. Klucz zostaje na Twoim urządzeniu i nie da się go podejrzeć ani wyłudzić.</p>
          <p class="nw-gdzie">Zalecamy, ale <strong>nie jest obowiązkowy</strong>. Hasło nadal działa.</p>
        </li>
        <li class="nw-kafel" style="--k:#f0a30a">
          <h3><i class="bi bi-chat-dots" aria-hidden="true"></i>Kody SMS wysyła Sendly</h3>
          <p>Wiadomości z kodami wysyła w naszym imieniu firma <strong>Sendly</strong> — i to ona jest widoczna jako nadawca SMS-a.</p>
          <p class="nw-gdzie">Nigdy nie podawaj kodu nikomu przez telefon ani e-mail. Pracownicy Fundacji o niego nie proszą.</p>
        </li>
        <li class="nw-kafel" style="--k:#e51400">
          <h3><i class="bi bi-envelope-slash" aria-hidden="true"></i>Hasła nie wysyłamy e-mailem</h3>
          <p>Ze względów bezpieczeństwa nie przesyłamy haseł pocztą. Jeśli zapomnisz hasła, zaloguj się kodem SMS i ustaw nowe.</p>
        </li>
      </ul>
    </section>

    <!-- ── Wygląd ─────────────────────────────────────────────────── -->
    <section class="nw-sekcja" id="wyglad" aria-labelledby="h-wyglad">
      <h2 id="h-wyglad"><i class="bi bi-grid-1x2" aria-hidden="true"></i>Nowy wygląd METRO</h2>
      <div class="nw-wazne" role="note" style="border-left-color:#1ba1e2">
        <h3>Nowy wygląd działa już dla wszystkich</h3>
        <p>Od października 2026 panel kursanta jest dostępny tylko w nowym wyglądzie METRO — nie trzeba niczego włączać. Wszystkie funkcje i dane zostały na swoim miejscu; zmienił się sposób ich pokazania. Jeśli czegoś nie możesz znaleźć, użyj wyszukiwarki na ekranie startowym albo napisz przez <strong>Pomoc</strong>.</p>
      </div>
      <ul class="nw-kafle">
        <li class="nw-kafel" style="--k:#fcd116">
          <h3><i class="bi bi-house" aria-hidden="true"></i>Ekran startowy z kafelkami</h3>
          <p>Po zalogowaniu od razu widzisz <strong>Na dziś</strong>: najbliższe zajęcia z odliczaniem czasu, zadania do oddania i oddane. Niżej: najważniejsze moduły i wszystkie sekcje panelu.</p>
          <p class="nw-gdzie">Szukasz czegoś? Użyj wyszukiwarki na ekranie startowym, np. „oceny”, „zapisy”.</p>
        </li>
        <li class="nw-kafel">
          <h3><i class="bi bi-calendar-event" aria-hidden="true"></i>Okienko „Masz dziś zajęcia”</h3>
          <p>W dniu lekcji panel przypomni o zajęciach i pokaże duży przycisk <strong>„Dołącz do zajęć”</strong> albo przejście do „Moich lekcji”.</p>
        </li>
        <li class="nw-kafel" style="--k:#60a917">
          <h3><i class="bi bi-layout-text-sidebar" aria-hidden="true"></i>Menu takie, jakie lubisz</h3>
          <p>Wybierz jeden z trzech układów: <strong>zakładki</strong> (wszystko widać), <strong>rozwijana sekcja</strong> albo <strong>menu rozwijane</strong>.</p>
          <p class="nw-gdzie">Zmienisz to w <strong>Konto → Ustawienia → Menu panelu</strong>.</p>
        </li>
        <li class="nw-kafel" style="--k:#1d1d1d">
          <h3><i class="bi bi-universal-access" aria-hidden="true"></i>Dostępność (WCAG)</h3>
          <p>Duże pola do klikania, wyraźna żółta ramka przy obsłudze klawiaturą, stany opisane słowem i ikoną — nie samym kolorem.</p>
          <p class="nw-gdzie">W <strong>Ustawienia → Ułatwienia</strong> włączysz większy tekst, wysoki kontrast i wyłączysz animacje.</p>
        </li>
      </ul>
    </section>

    <!-- ── Nauka ──────────────────────────────────────────────────── -->
    <section class="nw-sekcja" id="nauka" aria-labelledby="h-nauka">
      <h2 id="h-nauka"><i class="bi bi-calendar-check" aria-hidden="true"></i>Lekcje, zadania i testy</h2>
      <ul class="nw-kafle">
        <li class="nw-kafel">
          <h3><i class="bi bi-calendar-check" aria-hidden="true"></i>Moje lekcje</h3>
          <p>Lista lekcji po lewej, narzędzia po prawej (statystyki, pobieranie, druk, kalendarz). Statusy są czytelne: <strong>Planowana</strong>, <strong>Zakończona</strong>, <strong>Odwołana</strong>.</p>
          <p>Zamiast ukrytego menu „⋯” są widoczne przyciski <strong>Odwołaj</strong> i <strong>Przesuń</strong>.</p>
        </li>
        <li class="nw-kafel" style="--k:#f0a30a">
          <h3><i class="bi bi-journal-check" aria-hidden="true"></i>Zadania i materiały</h3>
          <p>Zadania w równych kafelkach — od razu widać, co trzeba oddać i do kiedy. Możesz najpierw wybrać lekcję, a potem jej materiały.</p>
        </li>
        <li class="nw-kafel" style="--k:#60a917">
          <h3><i class="bi bi-card-checklist" aria-hidden="true"></i>Testy w jednym miejscu</h3>
          <p>Wszystkie testy, kartkówki i egzaminy w jednej zakładce — najpierw te w toku i do zrobienia. Nowe rodzaje pytań: <strong>dopasuj pary</strong> i <strong>ułóż w kolejności</strong>, w pełni obsługiwane z klawiatury.</p>
        </li>
        <li class="nw-kafel" style="--k:#1d1d1d">
          <h3><i class="bi bi-people" aria-hidden="true"></i>Kilka grup? Łatwa zmiana</h3>
          <p>Jeśli uczysz się w więcej niż jednej grupie, u góry każdej strony widzisz aktywną grupę i przycisk <strong>„Zmień grupę”</strong> — bez wylogowania.</p>
        </li>
        <li class="nw-kafel">
          <h3><i class="bi bi-chat-text" aria-hidden="true"></i>Napisz do prowadzącego</h3>
          <p>Wiadomość do prowadzącego i prośbę o odwołanie lub przesunięcie zajęć wyślesz jednym kliknięciem z ekranu startowego.</p>
        </li>
      </ul>
    </section>

    <!-- ── Konto ──────────────────────────────────────────────────── -->
    <section class="nw-sekcja" id="konto" aria-labelledby="h-konto">
      <h2 id="h-konto"><i class="bi bi-person-gear" aria-hidden="true"></i>Konto i ustawienia</h2>
      <ul class="nw-kafle">
        <li class="nw-kafel">
          <h3><i class="bi bi-person-check" aria-hidden="true"></i>Sam upoważnisz zaufaną osobę</h3>
          <p>Możesz dać dostęp do podglądu panelu np. członkowi rodziny lub asystentowi. Taka osoba widzi lekcje, frekwencję i rozliczenia, ale niczego nie zmienia.</p>
          <p class="nw-gdzie">W każdej chwili zablokujesz albo odwołasz dostęp — działa to od razu. <strong>Sprawy → Upoważnieni</strong>.</p>
        </li>
        <li class="nw-kafel" style="--k:#1ba1e2">
          <h3><i class="bi bi-microsoft" aria-hidden="true"></i>Microsoft 365 (EDU)</h3>
          <p>Masz szkolne konto Microsoft 365? W panelu jest osobna zakładka z loginem i skrótami do Outlooka, Teams, OneDrive, Worda i Excela.</p>
        </li>
        <li class="nw-kafel" style="--k:#60a917">
          <h3><i class="bi bi-gear" aria-hidden="true"></i>Uporządkowane ustawienia</h3>
          <p>Wszystkie ustawienia na jednej stronie, z kafelkami sekcji: Menu panelu, Ułatwienia, Bezpieczeństwo, Powiadomienia, Push i Kalendarz.</p>
        </li>
      </ul>
    </section>

    <!-- ── Inne ───────────────────────────────────────────────────── -->
    <section class="nw-sekcja" id="inne" aria-labelledby="h-inne">
      <h2 id="h-inne"><i class="bi bi-info-circle" aria-hidden="true"></i>Inne zmiany</h2>
      <ul class="nw-kafle">
        <li class="nw-kafel" style="--k:#f0a30a">
          <h3><i class="bi bi-credit-card" aria-hidden="true"></i>Płatności w portalu płatności</h3>
          <p>Wpłaty, saldo i portfel przenieśliśmy do osobnego portalu płatności. W panelu w zakładkach „Rozliczenia” i „Portfel” znajdziesz link do niego.</p>
        </li>
        <li class="nw-kafel" style="--k:#e51400">
          <h3><i class="bi bi-key" aria-hidden="true"></i>Licencja Claude</h3>
          <p>Od <strong>17 października 2026</strong> licencja Claude nie będzie już rozliczana przez Fundację. Szczegóły w zakładce <strong>Moje licencje</strong>.</p>
        </li>
      </ul>
    </section>

    <p class="nw-akcje">
      <a class="nw-btn" href="<?= h($_login) ?>"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Zaloguj się do panelu</a>
      <a class="nw-btn nw-btn-jasny" href="<?= h($_panel) ?>?tab=problem"><i class="bi bi-question-circle" aria-hidden="true"></i>Potrzebuję pomocy</a>
    </p>
  </div>
</main>

<footer class="nw-stopka">
  <div class="nw-wrap">
    <span>Fundacja Edukacji Empatii Rozwoju FEER</span>
    <span><a href="https://feer.org.pl">feer.org.pl</a> · <a href="<?= h($_login) ?>">Logowanie</a></span>
  </div>
</footer>
</body>
</html>
