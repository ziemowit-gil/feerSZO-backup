<?php
/**
 * modules/it_portal/lib/itPortalDpocztaSeed.php — instrukcje webmaila dPoczta.pl dla usługi „Moja poczta” w Portalu Usług IT.
 * Na podstawie pomocy dostawcy (dhosting.pl/pomoc, kategoria „Webmail dpoczta.pl”, stan 2026-11-13), przepisane prostym językiem.
 * Zasady logowania wg polecenia 2026-11-13: login imie.nazwisko@feer.org.pl; konta sprzed 13.11.2026 (migracja z Microsoft 365)
 * dostały hasło SMS-em po migracji, konta założone później dostają je SMS-em po podpisaniu umowy.
 * Używa migracja '2026-11_it_portal_dpoczta_articles' (modules/selfrepairDB/logic/migrations.php): dodaje tylko brakujące artykuły.
 */

/**
 * Zasiew: wstęp „Moja poczta”, artykuły dPoczta.pl (ten sam adres = nadpisanie), ukrycie dawnych artykułów o poczcie
 * w Office 365 (is_published = 0, bez kasowania). Bezpieczne do ponownego uruchomienia.
 * @return array{category:string, hidden:list<string>, created:list<string>, updated:list<string>}
 */
function itPortalDpocztaApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'hidden' => [], 'created' => [], 'updated' => []];
    $intro = itPortalSanitizeHtml(itPortalDpocztaIntro($app));
    $articles = itPortalDpocztaArticles($app);

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moja-poczta'");
    if ($cat) {
        $catId = (int)$cat['id'];
        $rep['category'] = 'wstęp nadpisany';
        if (!$dryRun) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, updated_at = datetime('now') WHERE id = ?",
                              [$intro, itPortalPlain($intro), $catId]);
    } else {
        $rep['category'] = 'usługa utworzona';
        if ($dryRun) { $catId = 0; }
        else {
            $aud = array_column(itPortalAudiences(), 'id', 'name');
            $catId = itPortalCategorySave(['name' => 'Moja poczta', 'slug' => 'moja-poczta', 'icon' => 'mail', 'sort_order' => 20, 'is_active' => 1,
                'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
        }
    }

    // dawne instrukcje poczty w Office 365: w „Mojej poczcie” każdy artykuł o Office/M365/Outlooku/Exchange,
    // w innych usługach tylko taki, którego tytuł mówi o poczcie, skrzynce lub Outlooku
    $o365 = static fn(string $s): bool => (bool)preg_match('/office\s*365|microsoft\s*365|\bm365\b|outlook|exchange/iu', $s);
    foreach (db_all("SELECT a.id, a.category_id, a.slug, a.title, a.keywords, a.body_plain, c.slug AS cslug
                       FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE a.is_published = 1") as $a) {
        $own = (int)$a['category_id'] === $catId;
        if ($own && isset($articles[$a['slug']])) continue;
        $text = $a['title'] . ' ' . $a['keywords'] . ' ' . $a['body_plain'];
        $hit = $own ? $o365($text) : ($o365($text) && preg_match('/poczt|outlook|skrzynk|e-?mail/iu', (string)$a['title']));
        if (!$hit) continue;
        $rep['hidden'][] = "#{$a['id']} {$a['cslug']}/{$a['slug']} — {$a['title']}";
        if (!$dryRun) db_exec("UPDATE itp_articles SET is_published = 0, updated_at = datetime('now') WHERE id = ?", [$a['id']]);
    }

    $i = 0;
    foreach ($articles as $slug => [$title, $keywords, $blocks, $pinned]) {
        $old = $catId ? db_one("SELECT id FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
        $rep[$old ? 'updated' : 'created'][] = "moja-poczta/{$slug} — {$title}";
        if ($dryRun) continue;
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10], $old ? (int)$old['id'] : 0);
    }
    return $rep;
}

/** Wstęp strony usługi „Moja poczta”. */
function itPortalDpocztaIntro(string $app): string {
    return '<p>Poczta służbowa działa na serwerach dhosting. W przeglądarce otwierasz ją pod adresem <a href="https://dpoczta.pl">dpoczta.pl</a>. '
         . 'Logujesz się pełnym adresem <code>imie.nazwisko@feer.org.pl</code> i hasłem do skrzynki.</p>'
         . '<p>Wybierz instrukcję z listy. Jeśli czegoś w niej brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>';
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty na Start].
 * @return array<string, array{0:string,1:string,2:array,3:bool}>
 */
function itPortalDpocztaArticles(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $gear = 'Kliknij ikonę koła zębatego po prawej stronie (ustawienia).';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];

    return [
        'logowanie-dpoczta' => ['Jak zalogować się do poczty', 'logowanie, login, hasło, sms, dpoczta, webmail, migracja, nowa poczta, imie.nazwisko', [
            ['type' => 'text', 'html' => '<p>Pocztę w przeglądarce otwierasz na stronie <a href="https://dpoczta.pl">dpoczta.pl</a>. Działa na komputerze i w telefonie, bez instalowania czegokolwiek.</p>'],
            ['type' => 'wizard', 'title' => 'Logowanie krok po kroku', 'intro' => '', 'steps' => [
                $step('Otwórz stronę poczty', '<p>Wejdź na <a href="https://dpoczta.pl">dpoczta.pl</a>.</p>'),
                $step('Wpisz login', '<p>Loginem jest pełny adres służbowy: <code>imie.nazwisko@feer.org.pl</code>, małymi literami i bez polskich znaków (np. <code>jan.kowalski@feer.org.pl</code>).</p>'),
                $step('Wpisz hasło z SMS-a', '<p>Hasło do skrzynki dostałeś SMS-em:</p><ul><li>jeśli konto pocztowe istniało przed 13.11.2026, SMS przyszedł po przeniesieniu poczty z Microsoft 365;</li><li>jeśli konto założyliśmy później, SMS przychodzi po podpisaniu umowy.</li></ul>'),
                $step('Zmień hasło na własne', '<p>Po pierwszym logowaniu ustaw własne hasło. Opis jest w instrukcji „Jak zmienić hasło do poczty”.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Hasło do poczty jest osobne. To inne hasło niż do Panelu współpracownika (SZO), do Microsoft 365 i do logowania jednokrotnego.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie masz SMS-a albo hasło nie działa? <a href="' . $hd . '">Zgłoś to w Helpdesku</a>, nadamy nowe hasło.</p>'],
        ], true],

        'zmiana-hasla-poczty' => ['Jak zmienić hasło do poczty', 'hasło, zmiana hasła, nowe hasło, dpoczta, wymagania hasła', [
            ['type' => 'wizard', 'title' => 'Zmiana hasła', 'intro' => '', 'steps' => [
                $step('Otwórz ustawienia', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . '</p>'),
                $step('Wybierz „Hasło”', '<p>Na liście po lewej kliknij „Hasło”.</p>'),
                $step('Wpisz stare i nowe hasło', '<p>W pierwszym polu wpisz obecne hasło (np. to z SMS-a). Nowe hasło wpisz dwa razy w kolejnych polach i kliknij „Zapisz”.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nowe hasło musi mieć co najmniej 12 znaków, w tym małą literę, wielką literę i cyfrę.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Masz pocztę też w telefonie albo w Outlooku? Po zmianie hasła wpisz nowe także tam, inaczej program przestanie pobierać wiadomości.</p>'],
        ], false],

        'poczta-w-telefonie' => ['Poczta w telefonie, Outlooku i Thunderbirdzie', 'imap, smtp, pop3, telefon, outlook, thunderbird, apple mail, konfiguracja, serwer, port', [
            ['type' => 'text', 'html' => '<p>Pocztę możesz dodać do aplikacji w telefonie albo do programu na komputerze. Wybierz konto typu IMAP i wpisz dane z tabeli.</p>'
                . '<table><thead><tr><th>Ustawienie</th><th>Wartość</th></tr></thead><tbody>'
                . '<tr><td>Nazwa użytkownika</td><td><code>imie.nazwisko@feer.org.pl</code></td></tr>'
                . '<tr><td>Hasło</td><td>hasło do skrzynki pocztowej</td></tr>'
                . '<tr><td>Poczta przychodząca (IMAP)</td><td><code>imap.dpoczta.pl</code>, port 993, SSL/TLS</td></tr>'
                . '<tr><td>Poczta wychodząca (SMTP)</td><td><code>smtp.dpoczta.pl</code>, port 587, STARTTLS</td></tr>'
                . '<tr><td>Serwer wychodzący wymaga logowania</td><td>tak, ten sam login i hasło</td></tr>'
                . '</tbody></table>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Wybieraj IMAP. Wiadomości zostają wtedy na serwerze i widzisz je tak samo w telefonie, na komputerze i w przeglądarce.</p>'],
            ['type' => 'text', 'html' => '<p>POP3 (<code>pop3.dpoczta.pl</code>, port 995, SSL/TLS) pobiera wiadomości na jedno urządzenie i zwykle usuwa je z serwera. Jeśli to urządzenie się zepsuje, wiadomości przepadną.</p>'],
        ], true],

        'weryfikacja-dwuetapowa' => ['Weryfikacja dwuetapowa (2FA) w poczcie', '2fa, weryfikacja dwuetapowa, uwierzytelnienie, authenticator, kod, bezpieczeństwo, kody zapasowe', [
            ['type' => 'text', 'html' => '<p>Weryfikacja dwuetapowa chroni skrzynkę, nawet gdy ktoś pozna Twoje hasło. Przy logowaniu oprócz hasła podajesz kod z aplikacji w telefonie.</p>'],
            ['type' => 'wizard', 'title' => 'Włączanie 2FA', 'intro' => '<p>Najpierw zainstaluj w telefonie aplikację z kodami, np. Google Authenticator albo Microsoft Authenticator.</p>', 'steps' => [
                $step('Otwórz ustawienia', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' W lewej kolumnie wybierz „Uwierzytelnienie dwuetapowe (2FA)”.</p>'),
                $step('Włącz i potwierdź hasłem', '<p>Kliknij „Włącz”, wpisz hasło do skrzynki i kliknij „Kontynuuj”.</p>'),
                $step('Zeskanuj kod QR', '<p>Otwórz aplikację w telefonie i zeskanuj kod QR z ekranu. Wpisz kod, który pokaże aplikacja, i kliknij „Zakończ”.</p><p>Zamiast aplikacji możesz podać inny adres e-mail (nie ten służbowy). Kod przyjdzie tam i jest ważny 5 minut.</p>'),
                $step('Zapisz kody zapasowe', '<p>Kliknij „Pokaż kody zapasowe” i zapisz je albo wydrukuj. Przydadzą się, gdy zgubisz telefon. Zużyte kody wymienisz przyciskiem „Wygeneruj nowe kody zapasowe”.</p>'),
            ]],
        ], false],

        'podpis-w-poczcie' => ['Jak ustawić podpis (stopkę) w poczcie', 'podpis, stopka, sygnatura, tożsamość, projektant podpisu, logo, html', [
            ['type' => 'wizard', 'title' => 'Podpis w projektancie', 'intro' => '', 'steps' => [
                $step('Otwórz tożsamość', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' W lewej kolumnie wybierz „Tożsamość” i kliknij swój adres.</p>'),
                $step('Włącz projektanta podpisu', '<p>Włącz suwak „Włącz projektanta podpisu”. Pojawią się zakładki do ustawienia stopki.</p>'),
                $step('Uzupełnij dane', '<p>W „Szablony” wybierz układ. W „Użytkownik” wpisz imię i nazwisko, stanowisko i nazwę organizacji. W „Logo” możesz przesłać obraz, a w „Szczegóły” dodać telefon, stronę WWW i adres. „Zastrzeżenie” to drobny szary tekst pod podpisem.</p>'),
                $step('Zapisz', '<p>Kliknij „Zapisz” na dole strony. Podpis pojawi się w nowych wiadomościach.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Używasz też Outlooka albo Thunderbirda? W projektancie jest eksport kodu HTML podpisu, który wkleisz w tym programie.</p>'],
            ['type' => 'text', 'html' => '<p>W „Tożsamości” zmienisz też nazwę nadawcy, którą widzą odbiorcy. Jeśli masz dostęp do innej skrzynki, np. działu, dodasz ją przyciskiem „Nowa tożsamość” (ikona ołówka) i wybierzesz przy pisaniu wiadomości.</p>'],
        ], false],

        'autoresponder' => ['Autoodpowiedź na czas nieobecności', 'autoresponder, autoodpowiedź, urlop, nieobecność, poza biurem, automatyczna odpowiedź', [
            ['type' => 'text', 'html' => '<p>Autoodpowiedź wysyła nadawcom wiadomość, że jesteś na urlopie albo poza biurem.</p>'],
            ['type' => 'wizard', 'title' => 'Ustawienie autoodpowiedzi', 'intro' => '', 'steps' => [
                $step('Otwórz ustawienia', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>. ' . $gear . ' Wybierz „Autoresponder”.</p>'),
                $step('Włącz i wpisz treść', '<p>Na górze formularza włącz autoresponder. Wpisz temat i treść odpowiedzi oraz okres, w którym ma działać. Kliknij „Zapisz”.</p>'),
                $step('Wyłącz po powrocie', '<p>W tym samym miejscu wybierz „Wyłączona” i zapisz. Jeśli ustawiłeś daty, odpowiedź wyłączy się sama.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie widzisz „Autorespondera” w ustawieniach? <a href="' . $hd . '">Napisz w Helpdesku</a>, włączymy go z panelu administratora.</p>'],
        ], false],

        'filtry-i-przekierowanie' => ['Filtry i przekierowanie poczty', 'filtr, filtry, reguły, przekierowanie, przekazywanie, forward, folder, sortowanie', [
            ['type' => 'text', 'html' => '<p>Filtr sam przenosi wybrane wiadomości do folderu albo przekazuje je na inny adres.</p>'],
            ['type' => 'wizard', 'title' => 'Filtr z otwartej wiadomości', 'intro' => '<p>Najszybciej zrobisz filtr z wiadomości, która ma być filtrowana.</p>', 'steps' => [
                $step('Zaznacz wiadomość', '<p>Zaznacz wiadomość na liście, a w górnym pasku wybierz „Więcej”, potem „Utwórz filtr”.</p>'),
                $step('Wybierz, po czym filtrować', '<p>Wskaż, czy filtr ma działać np. po nadawcy albo temacie, i kliknij „Następny krok”.</p>'),
                $step('Ustaw akcję', '<p>Nadaj filtrowi nazwę, sprawdź warunki i wybierz folder, do którego mają trafiać wiadomości. Zapisz.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h3>Przekierowanie na inny adres</h3><p>Wejdź w ustawienia, wybierz „Filtry” i dodaj nowy filtr (ikona ołówka pod listą „Moje filtry”). Jako warunek ustaw wiadomości kierowane do Twojej skrzynki, a jako akcję przekazanie na adres docelowy. Jeśli kopia ma nie zostawać w skrzynce, dodaj drugą akcję (ikona plusa): usunięcie wiadomości. Zapisz.</p>'
                . '<p>Filtr wyłączysz bez usuwania, zaznaczając „Filtr wyłączony”.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie przekierowuj poczty służbowej na prywatne skrzynki (Gmail, Onet, WP itp.). W wiadomościach bywają dane osobowe, które muszą zostać w systemach organizacji.</p>'],
        ], false],

        'spam-i-zaufani-nadawcy' => ['Spam, zablokowani i zaufani nadawcy', 'spam, antyspam, cleanbox, blokowanie, czarna lista, biała lista, zaufani nadawcy, obrazki, wirusy', [
            ['type' => 'text', 'html' => '<p>Każda skrzynka ma włączone filtry antyspamowe, a poczta jest sprawdzana pod kątem wirusów. Możesz sam zablokować natrętnego nadawcę albo oznaczyć zaufanego.</p>'],
            ['type' => 'wizard', 'title' => 'Blokowanie i zaufane adresy', 'intro' => '', 'steps' => [
                $step('Otwórz Cleanbox', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a>, wejdź w „Ustawienia” i wybierz „Cleanbox”.</p>'),
                $step('Ustaw poziom filtra', '<p>W zakładce „Filtr antyspamowy” ustawisz, jak ostro ma działać filtr.</p>'),
                $step('Dodaj adresy', '<p>W zakładce „Reguły adresów” są dwie listy: „Zaufane adresy” i „Zablokowane adresy”. Wpis <code>*@nazwadomeny.pl</code> obejmuje wszystkie adresy z tej domeny.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h3>Obrazki w wiadomościach</h3><p>Poczta domyślnie może blokować obrazki z obcych serwerów, bo służą do śledzenia i oszustw. W otwartej wiadomości kliknij „Zezwól”, żeby pokazać je raz, albo „Zawsze zezwalaj od…”, żeby dodać nadawcę do zaufanych. Ustawienie zmienisz w „Ustawienia” → „Preferencje” → „Wyświetlanie wiadomości”. Polecamy opcję „od zaufanych nadawców”.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Dostałeś podejrzaną wiadomość z prośbą o hasło, kod albo przelew? Nie klikaj linków i <a href="' . $hd . '">zgłoś ją w Helpdesku</a>.</p>'],
        ], false],

        'kontakty-import-eksport' => ['Kontakty: import i eksport', 'kontakty, książka adresowa, import, eksport, vcard, csv, outlook', [
            ['type' => 'text', 'html' => '<p>Kontakty przeniesiesz z innego programu plikiem vCard (.vcf) albo CSV.</p>'],
            ['type' => 'wizard', 'title' => 'Import kontaktów', 'intro' => '', 'steps' => [
                $step('Przygotuj plik', '<p>Wyeksportuj kontakty ze starego programu do pliku vCard albo CSV. W pliku CSV pola rozdziela przecinek, a pierwszy wiersz to nagłówek, np. <code>First Name,Last Name,E-mail Address</code>.</p>'),
                $step('Otwórz Kontakty', '<p>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a> i przejdź do „Kontakty”.</p>'),
                $step('Zaimportuj', '<p>W górnym menu kliknij ikonę chmurki do importu (podpowiedź pojawi się po najechaniu kursorem) i wskaż plik.</p>'),
            ]],
            ['type' => 'text', 'html' => '<p>Eksport działa tak samo: w „Kontaktach” kliknij drugą ikonę chmurki, do eksportu.</p>'],
        ], false],

        'kalendarz' => ['Kalendarz: spotkania, udostępnianie, telefon', 'kalendarz, spotkanie, zaproszenie, udostępnianie, caldav, synchronizacja, telefon, cykliczne', [
            ['type' => 'wizard', 'title' => 'Nowe spotkanie', 'intro' => '', 'steps' => [
                $step('Wybierz termin', '<p>Otwórz kalendarz z menu po prawej stronie i kliknij komórkę przy wybranym dniu i godzinie.</p>'),
                $step('Opisz spotkanie', '<p>Wpisz tytuł, godzinę zakończenia, miejsce i opis. Możesz wybrać kategorię, np. służbowe.</p>'),
                $step('Zaproś uczestników', '<p>W zakładce „Uczestnicy” wpisz adres e-mail, zaznacz wysłanie powiadomienia i kliknij „Dodaj uczestnika”. Uczestnicy dostaną zaproszenie i mogą je przyjąć, odrzucić albo wybrać „Może”.</p>'),
                $step('Powtarzanie i zapis', '<p>Spotkanie co tydzień albo co miesiąc ustawisz w zakładce „Cykl”. Na koniec kliknij „Zapisz”.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h3>Udostępnienie kalendarza</h3><p>W lewej kolumnie najedź kursorem na kalendarz i kliknij ikonę ołówka. W zakładce „Udostępnij kalendarz” wpisz adres współpracownika, zaznacz uprawnienia i kliknij „Udostępnij”.</p>'
                . '<h3>Kalendarz w telefonie i Outlooku</h3><p>Kliknij ołówek przy kalendarzu i przejdź do sekcji „Synchronizacja”. Utwórz nowe połączenie: wpisz nazwę i hasło, wybierz pełny dostęp albo tylko odczyt i kliknij „Dodaj”. Pojawi się adres kalendarza i nazwa użytkownika. Wpisz je w telefonie albo w programie jako konto CalDAV, z hasłem ustawionym przed chwilą.</p>'],
        ], false],

        'szablony-odpowiedzi' => ['Szablony odpowiedzi', 'szablon, szablony, gotowa odpowiedź, powtarzalna wiadomość', [
            ['type' => 'text', 'html' => '<p>Na te same pytania odpowiadasz wiele razy? Zapisz odpowiedź jako szablon.</p>'],
            ['type' => 'wizard', 'title' => 'Szablon krok po kroku', 'intro' => '', 'steps' => [
                $step('Dodaj szablon', '<p>' . $gear . ' Wybierz „Szablony odpowiedzi”, kliknij ikonę ołówka, wpisz tytuł i treść, potem „Zapisz”.</p>'),
                $step('Użyj szablonu', '<p>Odpowiadając na wiadomość, kliknij u góry okna ikonę zakładki i wybierz szablon z listy.</p>'),
            ]],
        ], false],

        'powiadomienia-o-poczcie' => ['Powiadomienia o nowej poczcie', 'powiadomienia, nowa wiadomość, dźwięk, przeglądarka, alert', [
            ['type' => 'text', 'html' => '<p>Przeglądarka może pokazywać powiadomienie, gdy przyjdzie nowa wiadomość, nawet gdy pracujesz w innej karcie.</p>'],
            ['type' => 'wizard', 'title' => 'Włączenie powiadomień', 'intro' => '', 'steps' => [
                $step('Otwórz preferencje', '<p>' . $gear . ' Wybierz „Preferencje”, potem „Widok skrzynki pocztowej”.</p>'),
                $step('Włącz sprawdzanie poczty', '<p>W części „Nowa wiadomość” włącz „Sprawdzaj, czy nadeszły nowe wiadomości we wszystkich folderach”.</p>'),
                $step('Zezwól przeglądarce', '<p>Kliknij „Konfiguracja przeglądarki” i w okienku przeglądarki wybierz „Zezwalaj”. Kliknij „Test”, żeby sprawdzić, czy powiadomienie się pojawia. Zapisz ustawienia.</p>'),
            ]],
        ], false],

        'duze-zalaczniki' => ['Duże załączniki', 'załącznik, załączniki, limit, rozmiar, duży plik, zip', [
            ['type' => 'text', 'html' => '<p>Dostawca poczty podaje limit 2 GB na załączniki. Serwer odbiorcy często przyjmuje jednak dużo mniej, zwykle kilkadziesiąt MB, i wtedy wiadomość wraca do Ciebie.</p>'
                . '<p>Duże pliki spakuj do ZIP albo wyślij link do pliku w chmurze organizacji zamiast załącznika.</p>'],
        ], false],
    ];
}
