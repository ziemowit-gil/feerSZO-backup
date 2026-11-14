<?php
/**
 * modules/it_portal/lib/itPortalAnkieterSeed.php — usługa „Ankieter” w Portalu Usług IT (/uslugi-it/uslugi/ankieter).
 * Polecenie 2026-11-14: dokumentacja IT systemu ankiet i formularzy Ankieter (https://formularz.feer.org.pl, Laravel 13 na MyDevil),
 * z naciskiem na logowanie jednokrotne przez Logto, „w feerSZO pod /uslugi-it”.
 * 2026-11-15 (repozytorium ankieter, commit 10b2f19): instrukcja IT ze zrzutami (przycisk SSO „Logowanie jednokrotne”, stan Logto,
 * nowy objaw w problemach) i nowa instrukcja dla prowadzących ankiety (docs/uslugi-it/ankieter-uzytkownik.md).
 * Fakty (adresy, ścieżki, polecenia) bez zmian, forma wg zasad pomocy: bez tabel i myślników, nazwy przycisków w „cudzysłowie”,
 * polecenia w <pre><code>. Zrzuty: modules/it_portal/lib/img/ank-*.jpg (itPortalCloudShot kopiuje je do uploads/it_portal/).
 * Artykuły: „Dla wszystkich” (instrukcja dla prowadzących) i „Dla IT” (it_only = 1: treść widzą administratorzy i konta z rolą IT).
 * Bezpieczny do ponownego uruchomienia; „Zobacz też” między artykułami Ankietera jest dopisywane, cudze powiązania zostają.
 * Używają: migracje '2026-11_it_portal_ankieter*', cli/it_portal_ankieter_seed.php.
 */

/** @return list<string> */
function itPortalAnkieterApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $f = 'https://formularz.feer.org.pl';
    $b = $app . '/uslugi-it/uslugi/ankieter/';
    $intro = itPortalSanitizeHtml('<p>Ankieter to system ankiet i formularzy Fundacji pod adresem <a href="' . $f . '">formularz.feer.org.pl</a>. '
        . 'Ankiety służą do badań, a formularze zbierają zgłoszenia, na przykład zapisy na szkolenia.</p>'
        . '<p>Jak prowadzić ankietę, opisuje <a href="' . $b . 'instrukcja">Ankieter: instrukcja dla prowadzących ankiety</a>. '
        . 'Pozostałe artykuły są dla działu IT.</p>');

    $rep = [];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'ankieter'");
    if ($dryRun) {
        $rep[] = 'usługa ankieter: ' . ($cat ? 'aktualizacja wstępu' : 'nowa');
        foreach (itPortalAnkieterArticles($app, true) as $slug => [$title]) $rep[] = "ankieter/{$slug} — {$title}";
        return $rep;
    }
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Ankieter', 'slug' => 'ankieter', 'icon' => 'document', 'sort_order' => 27, 'is_active' => 1,
            'tile_group' => 'Pliki, dokumenty i zadania',
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }

    $ids = []; $i = 0;
    foreach (itPortalAnkieterArticles($app) as $slug => [$title, $keywords, $blocks, $pinned, $group]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10, 'menu_group' => $group, 'it_only' => $group === 'Dla IT' ? 1 : 0, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $ids[] = $id;
        $rep[] = "ankieter/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ", #{$id})";
    }
    // „Zobacz też”: każdy artykuł Ankietera wskazuje pozostałe
    foreach ($ids as $id) {
        $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$id]);
        $rel = array_values(array_unique(array_merge(itPortalIds($r['related'] ?? '[]'), array_values(array_diff($ids, [$id])))));
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($rel), $id]);
    }
    return $rep;
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty, grupa w menu].
 * $noShots: bez kopiowania zrzutów (podgląd --dry-run).
 */
function itPortalAnkieterArticles(string $app, bool $noShots = false): array {
    if (!function_exists('itPortalCloudShot')) require_once __DIR__ . '/itPortalCloudSeed.php';
    $app = rtrim($app, '/');
    $f  = 'https://formularz.feer.org.pl';
    $b  = $app . '/uslugi-it/uslugi/ankieter/';
    $hd = $app . '/helpdesk/new.php';
    $c   = static fn(string $s): string => '<code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code>';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $t   = static fn(string $h): array => ['type' => 'text', 'html' => $h];
    $img = static fn(string $name, string $alt): array => $noShots ? [] : itPortalCloudShot('ank-' . $name, $alt);
    $g1 = 'Dla wszystkich'; $g2 = 'Dla IT';

    return [
        // ── Dla wszystkich: instrukcja dla prowadzących ankiety (docs/uslugi-it/ankieter-uzytkownik.md) ──
        'instrukcja' => ['Ankieter: instrukcja dla prowadzących ankiety',
            'ankieter, ankieta, ankiety, formularz, formularze, formularz.feer.org.pl, nowa ankieta, szablon, wzór, nps, ocena szkolenia, zapisy, kreator, pytania, '
            . 'sekcje, warunek, logika, opublikuj, podgląd, link do ankiety, kopiuj link, hasło do ankiety, zaproszenia, przypomnienie, rodo, klauzula, zgody, '
            . 'wyniki, podsumowanie, wykresy, odpowiedzi, kwerendy, filtr, eksport, excel, xlsx, ods, csv, json, duplikuj', array_merge(
            [$t('<p>Ankieter to system ankiet i formularzy Fundacji pod adresem <a href="' . $f . '">' . $f . '</a>. '
                . 'Ankieta służy do badania opinii: odpowiedzi są domyślnie anonimowe, a wyniki widzisz na wykresach. '
                . 'Formularz zbiera zgłoszenia, na przykład zapisy na szkolenie, i pokazuje je jako listę odpowiedzi.</p>'
                . '<p>Jeśli czegoś tu brakuje albo coś nie działa, napisz do IT (<a href="https://it.feer.org.pl">it.feer.org.pl</a>).</p>')],

            [$t('<h2>1. Logowanie</h2>'
                . '<p>Wejdź na <a href="' . $f . '/admin">' . $f . '/admin</a>. Kliknij „Zaloguj się przez Logowanie jednokrotne” i podaj '
                . 'swój e-mail w Fundacji oraz hasło logowania jednokrotnego, to samo co do SZO i chmury.</p>'
                . '<p>Jeśli IT założyło Ci osobne konto w Ankieterze, wpisz e-mail i hasło w części „albo hasłem”.</p>')],
            $img('01-logowanie.jpg', 'Strona logowania'),
            [$t('<p>Panel zobaczysz tylko wtedy, gdy masz rolę administratora Ankietera. Nadaje ją IT.</p>')],

            [$t('<h2>2. Nowa ankieta lub formularz</h2>'
                . '<p>Na pulpicie wybierz pusty szablon albo gotowy wzór: szybkie NPS, ocenę szkolenia, zapisy na wydarzenie '
                . 'lub formularz kontaktowy. Wzór możesz potem dowolnie zmienić. Niżej są ostatnio edytowane ankiety i wyszukiwarka po tytule.</p>')],
            $img('02-pulpit.jpg', 'Pulpit z szablonami i ostatnimi ankietami'),
            [$t('<p>Wszystkie ankiety i formularze są w menu „Wszystkie”. Kolorowe znaczki obok tytułu pokazują stan '
                . '(kropka: zbiera odpowiedzi, kółko: szkic, kwadrat: zakończona) i sposób dostępu. Legenda jest nad listą.</p>')],
            $img('03-lista.jpg', 'Lista ankiet i formularzy'),
            [$t('<p>Przycisk „Duplikuj” robi kopię ankiety bez odpowiedzi. Przydaje się przy kolejnej edycji tego samego szkolenia.</p>')],

            [$t('<h2>3. Kreator pytań</h2>'
                . '<p>W zakładce „Kreator” dodajesz sekcje i pytania. Do wyboru są między innymi: krótka i długa odpowiedź, '
                . 'jednokrotny i wielokrotny wybór, lista, skala, NPS, siatka (macierz), data, załącznik i blok z samym tekstem. '
                . 'Kolejność zmieniasz strzałkami.</p>')],
            $img('04-kreator.jpg', 'Kreator ankiety z sekcjami i pytaniami'),
            [$t('<p>Zmiany zapisują się jako wersja robocza. Respondenci zobaczą je dopiero po kliknięciu „Opublikuj”. '
                . 'Przycisk „Podgląd” pokazuje ankietę tak, jak zobaczy ją respondent, ale nic nie zapisuje.</p>'
                . '<p>W pytaniu możesz ustawić warunek, na przykład „pokaż tylko, gdy ktoś odpowiedział Nie”, albo przejście '
                . 'do innej sekcji. Dzięki temu każdy widzi tylko pytania, które go dotyczą.</p>'),
             // Rodzaje pytań i ustawienia: z kreatora Ankietera (resources/js/form-builder.js QUESTION_TYPES, admin/forms/edit.blade.php), 2026-11-15
             $t('<h3>Rodzaje pytań</h3>'
                . '<p>Rodzaj wybierasz z listy „Typ nowego pytania” na dole sekcji. Później zmienisz go w polu „Typ pytania”.</p><ul>'
                . '<li>„Krótki tekst”: odpowiedź w jednej linii, na przykład imię, e-mail albo numer. W „Format odpowiedzi” wybierzesz: dowolny tekst, '
                . 'adres e-mail, numer telefonu, PESEL, NIP albo własny wzorzec. Możesz ustawić najmniejszą i największą liczbę znaków.</li>'
                . '<li>„Długi tekst”: odpowiedź w kilku liniach, na przykład uwagi albo opis. Też z limitem znaków.</li>'
                . '<li>„Jednokrotny wybór”: osoba zaznacza jedną odpowiedź z listy.</li>'
                . '<li>„Wielokrotny wybór”: osoba zaznacza kilka odpowiedzi. Możesz ustawić, ile najmniej i najwięcej.</li>'
                . '<li>„Lista rozwijana”: jedna odpowiedź z rozwijanej listy. Przydaje się, gdy odpowiedzi jest dużo.</li>'
                . '<li>„Skala liniowa”: ocena na skali, domyślnie od 1 do 5. Zakres zmienisz w polach „Od” i „Do”, a końce skali opiszesz, '
                . 'na przykład „bardzo źle” i „bardzo dobrze”.</li>'
                . '<li>„NPS (0 do 10)”: pytanie, czy osoba poleciłaby coś innym. W wynikach Ankieter liczy wskaźnik NPS.</li>'
                . '<li>„Siatka jednokrotnego wyboru”: kilka wierszy z tymi samymi odpowiedziami w kolumnach, w każdym wierszu jedna odpowiedź. '
                . 'Dobra do oceny kilku rzeczy naraz, na przykład prowadzącego, materiałów i sali.</li>'
                . '<li>„Siatka wielokrotnego wyboru”: jak wyżej, ale w wierszu można zaznaczyć kilka kolumn.</li>'
                . '<li>„Data”: wybór daty. Możesz podać najwcześniejszą i najpóźniejszą datę.</li>'
                . '<li>„Przesyłanie pliku”: osoba dołącza plik. Ustawiasz największy rozmiar (domyślnie 5 MB) i dozwolone rozszerzenia (domyślnie pdf, jpg, png).</li>'
                . '<li>„Blok informacyjny”: nagłówek i tekst bez odpowiedzi, na przykład wyjaśnienie przed kolejną częścią.</li>'
                . '</ul>'),
             $t('<h3>Ustawienia pytania</h3><ul>'
                . '<li>„Treść pytania” i „Podpowiedź dla respondenta”, czyli krótki tekst pod pytaniem.</li>'
                . '<li>„Pytanie wymagane”: bez odpowiedzi nie da się przejść dalej.</li>'
                . '<li>„Komunikat błędu z przykładem” przy własnym wzorcu, żeby osoba wiedziała, jak poprawić odpowiedź.</li>'
                . '<li>„Autouzupełnianie” przy krótkim tekście, na przykład imię i nazwisko, e-mail, telefon albo organizacja. Przeglądarka podpowie wtedy dane osoby.</li>'
                . '<li>„Dane wrażliwe”: przeglądarka respondenta nie zapamięta tej odpowiedzi, nawet gdy wolno przerwać wypełnianie i wrócić później.</li>'
                . '</ul>'),
             $t('<h3>Warunki i sekcje</h3>'
                . '<p>W „Warunek wyświetlenia” wybierasz wcześniejsze pytanie i porównanie: jest równe, jest różne od, zawiera, '
                . 'jest większe albo mniejsze niż, ma odpowiedź, nie ma odpowiedzi. Kilka warunków łączysz jako „wszystkie warunki” albo „dowolny z warunków”.</p>'
                . '<p>Sekcja ma tytuł i opis. W polu „Po tej sekcji” zostawiasz „Przejdź do następnej sekcji” albo wskazujesz inną. '
                . 'Możesz też dodać przejście zależne od odpowiedzi („to przejdź do”) i ustawić, co dzieje się „W pozostałych przypadkach”.</p>'
                . '<p>W „Tło ankiety” wybierzesz fale, kropki albo jednolite tło.</p>')],

            [$t('<h2>4. Udostępnianie i ustawienia</h2>'
                . '<p>W zakładce „Udostępnianie i ustawienia” jest link do ankiety. Kopiujesz go przyciskiem „Kopiuj”, '
                . 'a z każdego miejsca w panelu także przyciskiem „Kopiuj link” w nagłówku.</p>')],
            $img('05-link.jpg', 'Link do ankiety i podstawowe ustawienia'),
            [$t('<p>Niżej ustawiasz, kto może wypełnić ankietę:</p><ul>'
                . '<li>„Każdy z linkiem”: wystarczy link.</li>'
                . '<li>„Wspólne hasło”: podajesz hasło razem z linkiem.</li>'
                . '<li>„Tylko zalogowani”: osoba loguje się kontem Fundacji i może wypełnić ankietę raz.</li>'
                . '<li>„Tylko zaproszeni”: Ankieter wysyła mailem osobiste linki. Każdy działa raz, a osobom, które nie '
                . 'odpowiedziały, możesz wysłać przypomnienie.</li></ul>'
                . '<p>W tym samym miejscu zmieniasz status (szkic, zbiera odpowiedzi, zakończona), ustawiasz limit odpowiedzi '
                . 'i datę zamknięcia oraz decydujesz, czy można przerwać wypełnianie i wrócić później.</p>')],
            $img('06-dostep.jpg', 'Ustawienia dostępu i zbierania odpowiedzi'),
            [$t('<p>W części „Treści i RODO” dopisujesz tekst wstępu, podziękowanie albo adres strony, na którą osoba trafi '
                . 'po wysłaniu. Tam też wklejasz klauzulę informacyjną RODO (przycisk „Wstaw wzór” wstawia wzór FEER '
                . 'do uzupełnienia) i dodajesz zgody, które respondent musi zaznaczyć. Każda wysłana odpowiedź zapisuje, '
                . 'na jakie zgody i jaką treść klauzuli osoba się zgodziła.</p>')],
            $img('07-tresci-rodo.jpg', 'Wstęp, podziękowanie, klauzula RODO i zgody'),
            [$t('<p>Jeśli zaznaczysz ankietę jako wzór, pojawi się na pulpicie obok szablonów jako „Wzór FEER”.</p>')],

            [$t('<h2>5. Co widzi respondent</h2>'
                . '<p>Ankieta otwiera się na każdym urządzeniu, także na telefonie i z czytnikiem ekranu. Pasek u góry pokazuje, '
                . 'na którym kroku jest osoba. Błędy pojawiają się przy polu i w podsumowaniu nad formularzem.</p>')],
            $img('08-ankieta-respondent.jpg', 'Ankieta oczami respondenta'),

            [$t('<h2>6. Wyniki</h2>'
                . '<p>Zakładka „Podsumowanie” pokazuje liczbę odpowiedzi i wykres dla każdego pytania. Pod każdym wykresem '
                . 'jest przycisk „Pokaż jako tabelę”. Wyniki zawęzisz szybkim filtrem („tylko osoby, które w pytaniu…”) '
                . 'albo zapisaną kwerendą.</p>')],
            $img('09-podsumowanie.jpg', 'Podsumowanie wyników'),
            [$t('<p>Zakładka „Odpowiedzi” to lista pojedynczych wypełnień ze szczegółami i załącznikami. W zakładce '
                . '„Kwerendy” zapisujesz warunki, których często używasz, na przykład „uczestnicy, którzy ocenili szkolenie na 3 lub mniej”.</p>')],

            [$t('<h2>7. Eksport</h2>'
                . '<p>W zakładce „Eksport” zaznaczasz potrzebne pola i wybierasz format: Excel (XLSX), LibreOffice (ODS), '
                . 'CSV albo JSON. Odpowiedzi mogą być opisane etykietami („Tak”) albo kodami („yes”), co ułatwia analizę '
                . 'w programach statystycznych. Na liście „Które odpowiedzi” wybierzesz kwerendę.</p>')],
            $img('10-eksport.jpg', 'Eksport wybranych pól'),
            [['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Pliki z danymi osobowymi przechowuj tylko w miejscach Fundacji '
                . '(np. w chmurze nc.feer.org.pl), nie na prywatnych dyskach.</p>']],
        ), false, $g1],

        // ── Dla IT (docs/uslugi-it/ankieter.md) ──
        'ankieter-podstawy' => ['Ankieter: najważniejsze informacje, funkcje i role',
            'ankieter, formularz.feer.org.pl, mydevil, s89, serwer, katalog, laravel, sqlite, dns, cloudflare, feer-dns, github, funkcje, '
            . 'kreator, szablony, eksport, administrator, respondent, rola, użytkownicy, logowanie, sso, hasło, wcag', array_merge(
            [$t('<p>Ankieter to system ankiet i formularzy FEER, dostępny pod adresem <a href="' . $f . '">' . $f . '</a>. '
                . 'Instrukcja dla osób prowadzących ankiety: <a href="' . $b . 'instrukcja">Ankieter: instrukcja dla prowadzących ankiety</a>.</p>'
                . '<p>Ankiety służą do badań (domyślnie anonimowe, z wykresami i podsumowaniami). Formularze zbierają zgłoszenia, '
                . 'na przykład zapisy na szkolenia, z listą wpisów i załącznikami. Interfejs respondenta i panel spełniają WCAG 2.2 AA.</p>'),
             $t('<h2>Najważniejsze informacje</h2><ul>'
                . '<li>Adres: <a href="' . $f . '">' . $f . '</a> (panel: ' . $c('/admin') . ', logowanie: ' . $c('/logowanie') . ').</li>'
                . '<li>Serwer: MyDevil s89 (' . $c('s89.mydevil.net') . '), konto ' . $c('feer') . '.</li>'
                . '<li>Katalog: ' . $c('~/domains/formularz.feer.org.pl/app') . ', a ' . $c('public_html') . ' jest dowiązaniem do ' . $c('app/public') . '.</li>'
                . '<li>Technologia: Laravel 13, PHP 8.4, Blade, Alpine.js i Tailwind CSS.</li>'
                . '<li>Baza danych: SQLite w trybie WAL, ' . $c('app/database/database.sqlite') . ' (możliwa zmiana na MySQL).</li>'
                . '<li>DNS: ' . $c('formularz.feer.org.pl A 212.91.26.67') . ' (Cloudflare, bez proxy), zarządzany w repozytorium ' . $c('feer-dns') . '.</li>'
                . '<li>Logowanie: hasło lub SSO przez Logto (' . $c('logto.feer.org.pl') . '), opcjonalnie Authentik, Microsoft 365, Google, Nextcloud. '
                . 'Zobacz <a href="' . $b . 'logowanie-sso-logto">Ankieter: logowanie jednokrotne (SSO) przez Logto</a>.</li>'
                . '<li>Kod: GitHub, repozytorium ' . $c('ankieter') . '.</li>'
                . '</ul>'),
             $t('<h2>Funkcje</h2><ul>'
                . '<li>Kreator z 12 typami pól, sekcjami, logiką warunkową i walidacją (e-mail, telefon, PESEL, NIP, wzorce). '
                . 'Kolejność zmienia się przyciskami, co działa także z klawiatury.</li>'
                . '<li>Szablony: pusta ankieta, pusty formularz, NPS, ocena szkolenia, zapisy, kontakt.</li>'
                . '<li>Dostęp do ankiety: publiczny, wspólne hasło, tylko zalogowani (jedno wypełnienie na konto), '
                . 'jednorazowe linki w zaproszeniach e-mail z przypomnieniami.</li>'
                . '<li>Wyniki: wykresy z tabelami danych, NPS, filtry i zapisane kwerendy, lista zgłoszeń.</li>'
                . '<li>Eksport wybranych pól do XLSX, ODS, CSV i JSON. Opcjonalny automatyczny zapis wyników do Nextclouda.</li>'
                . '<li>Ustawienia w panelu: logowanie SSO, poczta SMTP z przyciskiem „Testuj wysyłkę maili”, użytkownicy i role.</li>'
                . '</ul>'),
             $t('<h2>Role</h2>'
                . '<p>Administrator tworzy ankiety, widzi wyniki, zarządza kontami i ustawieniami.</p>'
                . '<p>Respondent potrzebuje konta tylko do ankiet „dla zalogowanych”. Nie widzi panelu.</p>'
                . '<p>Pierwszego administratora zakłada IT (zobacz <a href="' . $b . 'serwer-i-wdrozenie">Ankieter: serwer MyDevil, cron i wdrożenie</a>, część „Pierwsze uruchomienie”). '
                . 'Kolejnych dodaje się w panelu (Użytkownicy) albo automatycznie przez SSO z rolą z Logto. Kolumna „Logowanie” pokazuje, '
                . 'czy konto loguje się hasłem, czy przez SSO.</p>')],
            $img('it-04-uzytkownicy.jpg', 'Lista użytkowników z rolą i sposobem logowania'),
        ), false, $g2],

        'logowanie-sso-logto' => ['Ankieter: logowanie jednokrotne (SSO) przez Logto',
            'ankieter, sso, logowanie jednokrotne, logto, logto-admin, oidc, openid connect, client id, client secret, app id, app secret, redirect uri, adres powrotu, ankieter-admin, rola, authentik, microsoft 365, google, nextcloud, pkce', array_merge(
            [$t('<p>Logto działa na MyDevil. Konsola administracyjna jest pod <a href="https://logto-admin.feer.org.pl">https://logto-admin.feer.org.pl</a>, '
                . 'a punkt logowania pod <a href="https://logto.feer.org.pl">https://logto.feer.org.pl</a>.</p>'),
             $t('<h2>Krok 1. Aplikacja w Logto</h2><ol>'
                . '<li>Zaloguj się do konsoli <a href="https://logto-admin.feer.org.pl">https://logto-admin.feer.org.pl</a>.</li>'
                . '<li>Wybierz „Applications → Create application”. Jako typ wybierz „Traditional web” (aplikacja serwerowa z sekretem).</li>'
                . '<li>Nazwa: ' . $c('Ankieter') . '.</li>'
                . '<li>W ustawieniach aplikacji wpisz:<ul>'
                . '<li>„Redirect URIs”: ' . $c('https://formularz.feer.org.pl/logowanie/sso/logto/powrot') . '</li>'
                . '<li>„Post sign-out redirect URIs”: ' . $c('https://formularz.feer.org.pl/logowanie') . '</li>'
                . '<li>„CORS allowed origins”: zostaw puste (logowanie odbywa się po stronie serwera).</li></ul></li>'
                . '<li>Zapisz. Skopiuj trzy wartości z sekcji „Endpoints &amp; Credentials”:<ul>'
                . '<li>„Issuer endpoint”: ' . $c('https://logto.feer.org.pl/oidc') . '</li>'
                . '<li>„App ID” (to client ID)</li>'
                . '<li>„App secret” (to client secret)</li></ul></li>'
                . '</ol>'),
             $t('<h2>Krok 2. Rola administratora (opcjonalnie)</h2><ol>'
                . '<li>W Logto wybierz „Roles → Create role”, typ „User”, nazwa ' . $c('ankieter-admin') . '.</li>'
                . '<li>Przypisz rolę osobom, które mają zarządzać ankietami („User management → użytkownik → Roles”).</li>'
                . '<li>Ankieter prosi o zakres ' . $c('roles') . ', więc rola przychodzi w tokenie w polu ' . $c('roles') . '.</li>'
                . '</ol>'),
             $t('<h2>Krok 3. Konfiguracja w Ankieterze</h2><ol>'
                . '<li>Zaloguj się jako administrator: <a href="' . $f . '/admin">' . $f . '/admin</a>.</li>'
                . '<li>Wejdź w „Ustawienia → Logowanie SSO → Logto” i wypełnij pola:<ul>'
                . '<li>zaznacz „Pokazuj na stronie logowania”,</li>'
                . '<li>„Napis na przycisku”: ' . $c('Logowanie jednokrotne') . ' (tak jest ustawione teraz),</li>'
                . '<li>„Adres wystawcy”: ' . $c('https://logto.feer.org.pl/oidc') . ',</li>'
                . '<li>„Client ID” i „Client secret”: wartości z Logto.</li></ul></li>'
                . '</ol>')],
            $img('it-01-sso-logto.jpg', 'Karta Logto w ustawieniach SSO: włączona, z wpisanym Client ID i wystawcą'),
            [$t('<ol start="3">'
                . '<li>Kliknij „Zapisz”, a potem „Sprawdź połączenie”. Poprawny wynik to komunikat „Połączenie działa”.</li>'
                . '<li>W sekcji „Zasady” ustaw:<ul>'
                . '<li>„Dozwolone domeny e-mail”: ' . $c('feer.org.pl') . ' (tylko konta organizacji),</li>'
                . '<li>„Rola administratora (Logto)”: ' . $c('ankieter-admin') . ',</li>'
                . '<li>„Zakładaj konto przy pierwszym logowaniu”: włączone, jeśli każdy pracownik może wypełniać ankiety dla zalogowanych.</li></ul></li>'
                . '</ol>')],
            $img('it-02-sso-zasady.jpg', 'Sekcja Zasady: domena feer.org.pl i rola ankieter-admin'),
            [$t('<ol start="5"><li>Wyloguj się i sprawdź przycisk „Zaloguj się przez Logowanie jednokrotne” na stronie ' . $c('/logowanie') . '.</li></ol>')],
            $img('01-logowanie.jpg', 'Strona logowania Ankietera z przyciskiem logowania jednokrotnego i polami na hasło'),
            [['type' => 'callout', 'kind' => 'info', 'html' => '<p>Stan na 15.11.2026: aplikacja „Ankieter” w Logto ma App ID ' . $c('p6gp60gispy4x3klkmj9v') . ', '
                . 'rola ' . $c('ankieter-admin') . ' istnieje, a provider Logto jest włączony.</p>'],
             $t('<h2>Jak to działa i bezpieczeństwo</h2><ul>'
                . '<li>Przepływ OpenID Connect: Authorization Code z PKCE oraz sprawdzanie ' . $c('state') . ', ' . $c('nonce') . ', wystawcy (' . $c('iss') . '), '
                . 'odbiorcy (' . $c('aud') . ') i ważności (' . $c('exp') . ').</li>'
                . '<li>Sekret klienta jest zaszyfrowany w bazie kluczem aplikacji (' . $c('APP_KEY') . ') i nie wraca do formularza ustawień.</li>'
                . '<li>Konto jest wiązane na stałe z identyfikatorem osoby w Logto. Istniejące konto z tym samym e-mailem zostanie '
                . 'powiązane tylko przy potwierdzonym adresie albo ograniczonych domenach. To chroni przed przejęciem konta.</li>'
                . '<li>Rola administratora jest przez SSO tylko nadawana. Odebranie roli w Logto nie odbiera jej w Ankieterze, '
                . 'więc zrób to ręcznie w „Użytkownicy”.</li>'
                . '<li>Logowanie hasłem działa dalej i jest drogą awaryjną, gdyby Logto było niedostępne.</li>'
                . '</ul>'),
             $t('<h2>Inni dostawcy</h2>'
                . '<p>Ten sam ekran obsługuje Authentik (' . $c('https://<authentik>/application/o/<aplikacja>/') . '), Microsoft 365 '
                . '(' . $c('https://login.microsoftonline.com/<ID-dzierżawy>/v2.0') . '), Google i Nextcloud (aplikacja „OIDC Identity Provider”). '
                . 'Adres powrotu zawsze ma postać ' . $c('https://formularz.feer.org.pl/logowanie/sso/<dostawca>/powrot') . '. '
                . 'Można włączyć kilku dostawców naraz, a każdy dostaje własny przycisk.</p>'),
             ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Adres powrotu w Logto musi dokładnie pasować do adresu Ankietera: https i bez ukośnika na końcu. '
                . 'Inaczej przy logowaniu pojawi się „Sesja logowania wygasła”. Więcej w artykule <a href="' . $b . 'rozwiazywanie-problemow">Ankieter: rozwiązywanie problemów</a>.</p>']],
        ), false, $g2],

        'poczta-i-nextcloud' => ['Ankieter: poczta i zapis wyników w Nextcloud',
            'ankieter, poczta, smtp, smtp.dpoczta.pl, mail, maile, testuj wysyłkę maili, kolejka, zaproszenia, przypomnienia, powiadomienia, nextcloud, nc.feer.org.pl, xlsx, wyślij do nextcloud, hasło aplikacji, env', array_merge(
            [$t('<h2>Poczta</h2>'
                . '<p>Ustawienia są w „Ustawienia → Poczta e-mail”: serwer SMTP, port, szyfrowanie, użytkownik, hasło i nadawca. '
                . 'Hasło jest szyfrowane. Przycisk „Testuj wysyłkę maili” wysyła wiadomość na wskazany adres od razu, bez kolejki, '
                . 'więc ewentualny błąd widać na ekranie.</p>')],
            $img('it-03-poczta.jpg', 'Ustawienia poczty: serwer smtp.dpoczta.pl, nadawca Formularze FEER'),
            [$t('<p>Maile (zaproszenia, przypomnienia, powiadomienia o nowych wpisach) idą przez kolejkę. Na MyDevil kolejkę '
                . 'przetwarza cron co minutę (zobacz „Zadania cykliczne” w artykule <a href="' . $b . 'serwer-i-wdrozenie">Ankieter: serwer MyDevil, cron i wdrożenie</a>). '
                . 'Powiadomienia nie zawierają treści odpowiedzi.</p>'),
             $t('<h2>Nextcloud</h2>'
                . '<p>Wyniki mogą trafiać do ' . $c('nc.feer.org.pl') . ' jako plik XLSX (' . $c('Ankieter/<adres-ankiety>/<adres-ankiety>.xlsx') . '). '
                . 'Konfiguracja w ' . $c('.env') . ':</p>'
                . $pre("NEXTCLOUD_ENABLED=true\nNEXTCLOUD_URL=https://nc.feer.org.pl\nNEXTCLOUD_USERNAME=<konto techniczne>\n"
                    . "NEXTCLOUD_APP_PASSWORD=<hasło aplikacji z Ustawienia → Bezpieczeństwo>\nNEXTCLOUD_FOLDER=Ankieter")
                . '<p>Zapis włącza się dla każdej ankiety osobno („Udostępnianie i ustawienia → Zapisuj wyniki w Nextcloud”) '
                . 'albo uruchamia ręcznie przyciskiem „Wyślij do Nextcloud”.</p>')],
        ), false, $g2],

        'serwer-i-wdrozenie' => ['Ankieter: serwer MyDevil, cron i wdrożenie',
            'ankieter, mydevil, s89, serwer, hosting, devil, php84, open_basedir, htaccess, addtype, ssl, lets encrypt, cron, kolejka, queue, artisan, migrate, wdrożenie, deploy, mydevil.sh, pierwsze uruchomienie, env, app_key', [
            $t('<h2>Hosting MyDevil: szczegóły</h2><ul>'
                . '<li>Wersję PHP dla WWW ustawia linia ' . $c('AddType application/x-httpd-php84 .php') . ' na początku ' . $c('app/public/.htaccess') . '. '
                . 'Skrypt wdrożenia dopisuje ją po każdej aktualizacji. Nie dodawaj jej do repozytorium, bo na innym serwerze '
                . 'pliki PHP byłyby pobierane zamiast wykonywane.</li>'
                . '<li>' . $c('open_basedir') . ' musi obejmować cały katalog domeny, bo kod leży poza ' . $c('public_html') . ':'
                . $pre('devil www options formularz.feer.org.pl php_openbasedir /usr/home/feer/domains/formularz.feer.org.pl:/tmp') . '</li>'
                . '<li>SSL: Let\'s Encrypt przez polecenie poniżej (działa, gdy DNS wskazuje już na 212.91.26.67):'
                . $pre('devil ssl www add 212.91.26.67 le le formularz.feer.org.pl') . '</li>'
                . '<li>Konsola PHP: zawsze ' . $c('/usr/local/bin/php84') . ', na przykład ' . $c('php84 artisan migrate --force') . '.</li>'
                . '</ul>'),
            $t('<h3>Zadania cykliczne (cron)</h3>'
                . $pre('* * * * * cd ~/domains/formularz.feer.org.pl/app && /usr/local/bin/php84 artisan queue:work --stop-when-empty --max-time=50 >/dev/null 2>&1')),
            $t('<h2>Wdrożenie aktualizacji</h2>'
                . '<p>Z katalogu projektu na komputerze z dostępem SSH (host ' . $c('mydevil') . ' w ' . $c('~/.ssh/config') . '):</p>'
                . $pre('./deploy/mydevil.sh')
                . '<p>Skrypt buduje assety, wysyła kod (bez ' . $c('.env') . ', bazy, logów i ' . $c('vendor') . '), instaluje zależności, uruchamia migracje, '
                . 'odświeża cache i restartuje kolejkę.</p>'),
            $t('<h2>Pierwsze uruchomienie (już wykonane 14.11.2026)</h2><ol>'
                . '<li>' . $c('devil www add formularz.feer.org.pl php') . ', a potem ' . $c('public_html') . ' jako dowiązanie do ' . $c('app/public') . '.</li>'
                . '<li>' . $c('.env') . ' utworzone z ' . $c('.env.example') . ': ' . $c('APP_ENV=production') . ', ' . $c('APP_DEBUG=false') . ', '
                . $c('APP_URL=https://formularz.feer.org.pl') . ', ' . $c('SESSION_SECURE_COOKIE=true') . ', ścieżka bazy SQLite. '
                . 'Klucz utworzony na serwerze przez ' . $c('php84 artisan key:generate') . '.</li>'
                . '<li>' . $c('php84 artisan migrate --force') . ', potem założenie konta administratora:'
                . $pre("php84 artisan tinker\n\$u = App\\Models\\User::create([...]); \$u->forceFill(['is_admin' => true])->save();") . '</li>'
                . '</ol>'),
        ], false, $g2],

        'kopie-i-bezpieczenstwo' => ['Ankieter: kopie zapasowe i bezpieczeństwo',
            'ankieter, kopia zapasowa, backup, sqlite, backup bazy, załączniki, storage, app_key, env, szyfrowanie, bezpieczeństwo, prywatność, csp, csrf, referrer-policy, limity prób, sha-256, anonimowe ankiety, rodo', [
            $t('<h2>Kopie zapasowe</h2>'
                . '<p>Wszystkie dane są w jednym pliku bazy i w katalogu załączników:</p>'
                . $pre("cd ~/domains/formularz.feer.org.pl/app\nsqlite3 database/database.sqlite \".backup '/home/feer/backup/ankieter-\$(date +%F).sqlite'\"\n"
                    . "tar czf ~/backup/ankieter-pliki-\$(date +%F).tgz storage/app/private")),
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Bez pliku ' . $c('.env') . ', a dokładniej ' . $c('APP_KEY') . ', nie da się odczytać zaszyfrowanych ustawień '
                . '(sekrety SSO, hasło SMTP). Przechowuj kopię ' . $c('.env') . ' osobno, w bezpiecznym miejscu.</p>'],
            $t('<h2>Bezpieczeństwo i prywatność</h2><ul>'
                . '<li>Brak zewnętrznych skryptów, fontów i trackerów. Nagłówki CSP, ' . $c('Referrer-Policy: no-referrer') . ', ochrona CSRF.</li>'
                . '<li>Limity prób: wysyłka ankiety, hasło do ankiety, logowanie, SSO.</li>'
                . '<li>Tokeny zaproszeń przechowywane wyłącznie jako skrót SHA-256. Hasła do ankiet i kont są hashowane.</li>'
                . '<li>Ankiety anonimowe nie wiążą odpowiedzi z osobą, także przy zaproszeniach i logowaniu.</li>'
                . '<li>Załączniki leżą na prywatnym dysku i są dostępne tylko dla administratorów.</li>'
                . '</ul>'),
        ], false, $g2],

        'rozwiazywanie-problemow' => ['Ankieter: rozwiązywanie problemów',
            'ankieter, problem, błąd, błąd 500, biała strona, laravel.log, open_basedir, kod php, addtype, nie przychodzą maile, cron, crontab, logowanie hasłem, hasło nie działa, nie udało się połączyć z dostawcą logowania, sesja logowania wygasła, sso, logto, adres powrotu', [
            $t('<h2>Biała strona albo błąd 500</h2>'
                . '<p>Sprawdź ' . $c('app/storage/logs/laravel.log') . ' oraz uprawnienia ' . $c('storage/') . ' i ' . $c('database/') . '.</p>'
                . '<h2>Komunikat open_basedir</h2>'
                . '<p>Sprawdź ustawienie ' . $c('php_openbasedir') . ' (część „Hosting MyDevil: szczegóły” w artykule <a href="' . $b . 'serwer-i-wdrozenie">Ankieter: serwer MyDevil, cron i wdrożenie</a>).</p>'
                . '<h2>Strona pokazuje kod PHP</h2>'
                . '<p>Brakuje linii ' . $c('AddType … php84') . ' w ' . $c('public/.htaccess') . '. Uruchom wdrożenie ponownie.</p>'
                . '<h2>Nie przychodzą maile</h2>'
                . '<p>Użyj przycisku „Testuj wysyłkę maili” i sprawdź, czy cron kolejki działa (' . $c('crontab -l') . ').</p>'
                . '<h2>„Nie udało się połączyć z dostawcą logowania”</h2>'
                . '<p>Kliknij „Sprawdź połączenie” w ustawieniach SSO. Adres wystawcy musi być identyczny jak w Logto.</p>'
                . '<h2>Logowanie hasłem nie przechodzi</h2>'
                . '<p>Sprawdź, czy w adresie konta nie ma literówki („Użytkownicy”). Konto SSO nie ma hasła lokalnego.</p>'
                . '<h2>„Sesja logowania wygasła” przy SSO</h2>'
                . '<p>Adres powrotu w Logto musi dokładnie pasować (https, bez ukośnika na końcu).</p>'),
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie pomogło? Zgłoś problem w <a href="' . $hd . '">Helpdesku IT</a>.</p>'],
        ], false, $g2],
    ];
}
