<?php
/**
 * modules/it_portal/lib/itPortalKontoKursantaSeed.php — usługa „Konto kursanta” w Portalu Usług IT (/uslugi-it/uslugi/konto-kursanta),
 * tylko dla grupy odbiorców „Kursant” (/uslugi-it/dla/kursant). Polecenie 2026-11-14: „W kursant zrób też instrukcje dla kursantów
 * i wrzuć do pomoc IT. Chodzi konkretnie o nowe konta”. Instrukcje dla uczestników, którzy dostają nowe konta: panel kursanta
 * (login j.kowalski / k1234, pierwsze logowanie kodem SMS) i poczta kursanta @stud.feer.org.pl (odrębny serwer, Zimbra na titan.sdata.net.pl).
 * Tekst prostym językiem (skill humanizer).
 *
 * Źródła faktów: modules/ti_login/view/loginPage.php (login, obrazek bezpieczeństwa, puste hasło = kod SMS, klucz sprzętowy),
 * includes/ti_student_password.php (hasło panelu: min. 12 znaków, wielka i mała litera, cyfra, znak specjalny),
 * modules/ti_poczta_stud (e-mail „Poczta kursanta — dane do logowania”, SMS „FEER: poczta kursanta …”, hasło startowe 14 dni,
 * 5 pokazań, „Mam już własne hasło”, IMAP 993 / SMTP 465), webmail titan.sdata.net.pl to Zimbra Web Client.
 * Używa migracja '2026-11_it_portal_konto_kursanta' (modules/selfrepairDB/logic/migrations.php) i cli/it_portal_konto_kursanta_seed.php.
 */

/**
 * Zasiew: usługa „Konto kursanta” (nowa albo nadpisany wstęp, odbiorcy: Kursant) i jej artykuły (nowe albo nadpisane).
 * Bezpieczne do ponownego uruchomienia. @return array{category:string, created:list<string>, updated:list<string>}
 */
function itPortalKontoKursantaApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'created' => [], 'updated' => []];
    $intro = itPortalSanitizeHtml(itPortalKontoKursantaIntro($app));
    $aud = array_column(itPortalAudiences(), 'id', 'name');
    $audIds = array_values(array_filter([(int)($aud['Kursant'] ?? 0)]));

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'konto-kursanta'");
    if ($cat) {
        $catId = (int)$cat['id'];
        $rep['category'] = 'wstęp nadpisany';
        if (!$dryRun) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, audiences = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?",
                              [$intro, itPortalPlain($intro), json_encode($audIds), $catId]);
    } else {
        $rep['category'] = 'usługa utworzona';
        $catId = 0;
        if (!$dryRun) {
            $catId = itPortalCategorySave(['name' => 'Konto kursanta', 'slug' => 'konto-kursanta', 'icon' => 'user', 'sort_order' => 5, 'is_active' => 1,
                'audiences' => $audIds, 'intro_html' => $intro]);
        }
    }

    $i = 0;
    foreach (itPortalKontoKursantaArticles($app) as $slug => [$title, $keywords, $blocks, $pinned]) {
        $old = $catId ? db_one("SELECT id FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
        $rep[$old ? 'updated' : 'created'][] = "konto-kursanta/{$slug} — {$title}";
        if ($dryRun) continue;
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10], $old ? (int)$old['id'] : 0);
    }
    return $rep;
}

/** Wstęp strony usługi „Konto kursanta”. */
function itPortalKontoKursantaIntro(string $app): string {
    $u = $app . '/uslugi-it/uslugi/konto-kursanta/';
    return '<p>Jako uczestnik szkoleń albo konsultacji masz w Fundacji dwa konta: <strong>panel kursanta</strong> i <strong>pocztę kursanta</strong> '
         . 'z adresem w domenie <code>@stud.feer.org.pl</code>. Każde ma osobne hasło. '
         . 'Konta poczty zakłada zewnętrzny administrator na polecenie kierownika szkolenia.</p>'
         . '<p>Dostałeś(-aś) właśnie nowe konto? Zacznij od artykułu <a href="' . $u . 'nowe-konta">Nowe konta: od czego zacząć</a>. '
         . 'Prowadzi krok po kroku od pierwszej wiadomości do własnych haseł.</p>'
         . '<p>Word i Excel z konta <code>@edu.feer.org.pl</code> zastąpisz darmowym programem. Zobacz <a href="' . $u . 'pakiet-biurowy">Word i Excel po wyłączeniu konta @edu</a>.</p>';
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty na Start].
 * @return array<string, array{0:string,1:string,2:array,3:bool}>
 */
function itPortalKontoKursantaArticles(string $app): array {
    $login  = $app . '/karty30/ti/login.php';
    $panel  = $app . '/karty30/ti/kursant/index.php';
    $poczta = $panel . '?tab=poczta';
    $pomoc  = $panel . '?tab=problem';
    $u      = $app . '/uslugi-it/uslugi/konto-kursanta/';
    $pk     = $app . '/uslugi-it/uslugi/poczta-kursanta/';
    $web    = 'https://titan.sdata.net.pl/';
    $step   = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    $safe   = ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nikt z Fundacji nie poprosi Cię o hasło ani o kod z SMS-a, ani przez telefon, ani e-mailem. '
        . 'Jeśli przyjdzie kod, o który nie prosiłeś(-aś), nikomu go nie podawaj i napisz do nas przez <a href="' . $pomoc . '">zakładkę Pomoc w panelu kursanta</a>.</p>'];

    return [
        'nowe-konta' => ['Nowe konta: od czego zacząć',
            'nowe konto, nowe konta, kursant, uczestnik, panel kursanta, poczta kursanta, stud.feer.org.pl, login, hasło, hasło startowe, sms, kod, pierwsze logowanie, dwa hasła, titan, zimbra, edu.feer.org.pl', [
            ['type' => 'text', 'html' => '<p>Ten artykuł jest dla uczestników szkoleń i konsultacji, którzy dostali od Fundacji nowe konto. '
                . 'Masz u nas dwa konta. Każde ma inny login, inne hasło i inną stronę logowania.</p>'
                . '<table><thead><tr><th></th><th>Panel kursanta</th><th>Poczta kursanta</th></tr></thead><tbody>'
                . '<tr><td>Do czego służy</td><td>plan zajęć, obecności, dokumenty, zgody, kontakt z biurem</td><td>Twoja skrzynka e-mail w Fundacji</td></tr>'
                . '<tr><td>Login</td><td>krótki, np. <code>j.kowalski</code>, a przy konsultacjach np. <code>k1234</code></td><td>pełny adres, np. <code>j.kowalski@stud.feer.org.pl</code></td></tr>'
                . '<tr><td>Pierwsze hasło</td><td>nie ma go: przy pierwszym logowaniu dostajesz kod SMS-em</td><td>hasło startowe przychodzi SMS-em</td></tr>'
                . '<tr><td>Gdzie się logujesz</td><td><a href="' . $login . '">strona logowania panelu kursanta</a></td><td><a href="' . $web . '">titan.sdata.net.pl</a></td></tr>'
                . '</tbody></table>'],

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p><strong>Kto zakłada konta.</strong> Konta poczty kursanta zakłada zewnętrzny administrator na polecenie kierownika szkolenia. '
                . 'Nie zakładasz konta sam(a) i nie trzeba o nie prosić. Jeśli uważasz, że powinieneś(-aś) mieć konto, a nie dostałeś(-aś) danych, zapytaj kierownika szkolenia.</p>'],

            ['type' => 'text', 'html' => '<h2>Jakie wiadomości dostaniesz</h2><ul>'
                . '<li><strong>Login do panelu kursanta</strong> przychodzi SMS-em albo dostajesz go od nas na wydruku.</li>'
                . '<li><strong>E-mail „Poczta kursanta: dane do logowania”</strong> z adresem nowej skrzynki. Wysyłamy go na prywatny adres e-mail, który podałeś(-aś) Fundacji.</li>'
                . '<li><strong>SMS „FEER: poczta kursanta”</strong> z hasłem startowym do poczty. Haseł nie wysyłamy e-mailem.</li></ul>'
                . '<p>Login i hasło do poczty zobaczysz też w panelu kursanta, w zakładce „Poczta kursanta”.</p>'],

            ['type' => 'wizard', 'title' => 'Nowe konta krok po kroku', 'intro' => '<p>Zrób kroki po kolei. Całość zajmie kilkanaście minut. Miej przy sobie telefon, na który przyjdą kody.</p>', 'steps' => [
                $step('Zaloguj się do panelu kursanta', '<p>Wejdź na <a href="' . $login . '">stronę logowania</a>, wpisz login i kliknij „Dalej”. '
                    . 'Pole hasła zostaw puste. Kod przyjdzie SMS-em. Szczegóły opisuje artykuł <a href="' . $u . 'pierwsze-logowanie-panel">Pierwsze logowanie do panelu kursanta</a>.</p>'),
                $step('Ustaw własne hasło do panelu', '<p>Po kodzie z SMS-a panel poprosi o własne hasło. Musi mieć co najmniej 12 znaków, wielką i małą literę, cyfrę i znak specjalny.</p>'),
                $step('Sprawdź dane poczty', '<p>W panelu otwórz zakładkę <a href="' . $poczta . '">„Poczta kursanta”</a>. Zobaczysz tam swój adres. '
                    . 'Gdy nie masz SMS-a z hasłem startowym, kliknij „Pokaż hasło” i wpisz kod, który przyjdzie SMS-em.</p>'),
                $step('Zaloguj się do poczty', '<p>Wejdź na <a href="' . $web . '">titan.sdata.net.pl</a>. Jako nazwę użytkownika wpisz pełny adres, np. <code>j.kowalski@stud.feer.org.pl</code>, a hasło przepisz z SMS-a.</p>'),
                $step('Ustaw własne hasło do poczty', '<p>W poczcie zmień hasło startowe na własne. Jak to zrobić, opisuje artykuł <a href="' . $u . 'haslo-do-poczty">Własne hasło do poczty kursanta</a>. '
                    . 'Wybierz inne hasło niż do panelu kursanta.</p>'),
                $step('Potwierdź w panelu', '<p>Wróć do zakładki <a href="' . $poczta . '">„Poczta kursanta”</a> i kliknij „Mam już własne hasło”. Hasło startowe zniknie z panelu.</p>'),
                $step('Dodaj pocztę do telefonu (jeśli chcesz)', '<p>Instrukcję znajdziesz w artykule <a href="' . $u . 'poczta-w-telefonie">Poczta kursanta w telefonie</a>.</p>'),
                $step('Zainstaluj darmowy pakiet biurowy', '<p>Word i Excel z konta <code>@edu.feer.org.pl</code> przestaną działać 20 listopada 2026 r. '
                    . 'Co zainstalować zamiast nich, opisuje artykuł <a href="' . $u . 'pakiet-biurowy">Word i Excel po wyłączeniu konta @edu</a>.</p>'),
            ]],

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p><strong>Termin.</strong> Hasło startowe do poczty czeka w panelu 14 dni i można je pokazać najwyżej 5 razy. '
                . 'Potem znika. Gdy nie zdążysz, napisz do nas, a wyślemy nowe.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Miałeś(-aś) konto w domenie <code>@edu.feer.org.pl</code>? Wyłączamy je 20 listopada 2026 r., a wiadomości z niego trafią na nowy adres. '
                . 'Więcej w artykule <a href="' . $pk . 'logowanie">Poczta kursanta: login, hasło i logowanie</a>.</p>'],
            $safe,
        ], true],

        'pierwsze-logowanie-panel' => ['Pierwsze logowanie do panelu kursanta',
            'panel kursanta, pierwsze logowanie, login, j.kowalski, k1234, alias, obrazek bezpieczeństwa, kod sms, hasło, nowe hasło, 12 znaków, klucz sprzętowy, passkey, nie pamiętam hasła, nie pamiętam loginu, rodzic, opiekun', [
            ['type' => 'text', 'html' => '<p>Panel kursanta to Twoje konto uczestnika: plan zajęć, dokumenty, zgody i kontakt z biurem. '
                . 'Przy pierwszym logowaniu nie masz jeszcze hasła. Zamiast niego dostajesz kod SMS-em i ustawiasz własne hasło.</p>'],
            ['type' => 'text', 'html' => '<h2>Twój login</h2><ul>'
                . '<li><strong>Uczestnik szkoleń</strong>: pierwsza litera imienia, kropka i nazwisko bez polskich znaków, np. <code>j.kowalski</code>. '
                . 'Gdy ktoś ma już taki login, na końcu jest cyfra, np. <code>j.kowalski2</code>.</li>'
                . '<li><strong>Osoba korzystająca z konsultacji</strong>: litera „k” i cztery cyfry, np. <code>k1234</code>. Dostajesz go SMS-em po umówieniu pierwszej konsultacji.</li></ul>'
                . '<p>Login do panelu nie jest adresem e-mail. Adres <code>@stud.feer.org.pl</code> służy tylko do poczty.</p>'],
            ['type' => 'wizard', 'title' => 'Pierwsze logowanie', 'intro' => '<p>Potrzebujesz loginu i telefonu, którego numer podałeś(-aś) Fundacji.</p>', 'steps' => [
                $step('Wpisz login', '<p>Otwórz <a href="' . $login . '">stronę logowania panelu kursanta</a>, wpisz login i kliknij „Dalej”.</p>'),
                $step('Sprawdź obrazek bezpieczeństwa', '<p>Po wpisaniu loginu zobaczysz swój obrazek bezpieczeństwa. Czytnik ekranu go opisze. '
                    . 'Przy pierwszym logowaniu wybierzesz go sam(a). Jeśli później zobaczysz inny obrazek, nie wpisuj hasła i skontaktuj się z nami.</p>'),
                $step('Zostaw hasło puste i wpisz kod z SMS-a', '<p>Przy pierwszym logowaniu pole hasła zostaw puste. Przyjdzie SMS z kodem. Przepisz go. '
                    . 'Jeśli dostałeś(-aś) od nas hasło jednorazowe na wydruku, możesz wpisać je zamiast kodu.</p>'),
                $step('Ustaw własne hasło', '<p>Wymyśl hasło, które ma co najmniej 12 znaków, wielką i małą literę, cyfrę i znak specjalny, np. <code>!</code> albo <code>#</code>. '
                    . 'Formularz na bieżąco pokazuje, które warunki już spełniasz. Tym hasłem logujesz się przy kolejnych wejściach.</p>'),
                $step('Klucz sprzętowy (nieobowiązkowy)', '<p>Panel zaproponuje dodanie klucza sprzętowego albo klucza w telefonie. Możesz go dodać albo kliknąć „Nie mam klucza albo nie chcę korzystać”. '
                    . 'Bez klucza logujesz się dalej hasłem i kodem z SMS-a.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Nie pamiętasz hasła albo loginu?</h2>'
                . '<p>Wpisz login na stronie logowania. Dostaniesz kod SMS-em na numer zapisany w Twoim koncie i ustawisz nowe hasło. '
                . 'Jeśli nie pamiętasz loginu albo zmieniłeś(-aś) numer telefonu, zadzwoń albo napisz do placówki. Wyślemy nowe dane.</p>'
                . '<h2>Inne osoby logują się gdzie indziej</h2>'
                . '<p>Rodzic lub opiekun małoletniego kursanta, osoba upoważniona przez kursanta i prowadzący zajęcia mają osobne logowanie. Wybierają je na tej samej stronie logowania.</p>'],
            $safe,
        ], false],

        'haslo-do-poczty' => ['Własne hasło do poczty kursanta',
            'poczta kursanta, hasło, zmiana hasła, hasło startowe, własne hasło, zimbra, titan, titan.sdata.net.pl, preferencje, ustawienia, mam już własne hasło, stud.feer.org.pl', [
            ['type' => 'text', 'html' => '<p>Hasło startowe z SMS-a służy tylko do pierwszego logowania. Zaraz po nim ustaw własne hasło. '
                . 'Poczta działa na odrębnym serwerze, więc jej hasło zmieniasz w samej poczcie, a nie w panelu kursanta.</p>'],
            ['type' => 'wizard', 'title' => 'Zmiana hasła w poczcie', 'intro' => '<p>Poczta na titan.sdata.net.pl to program Zimbra. Nazwy przycisków mogą być po polsku albo po angielsku.</p>', 'steps' => [
                $step('Zaloguj się', '<p>Wejdź na <a href="' . $web . '">titan.sdata.net.pl</a>. Wpisz pełny adres e-mail i hasło startowe.</p>'),
                $step('Otwórz ustawienia', '<p>Kliknij kartę <strong>Preferencje</strong> (w niektórych wersjach <strong>Właściwości</strong>, ang. Preferences) u góry ekranu. W nowszym wyglądzie to ikona koła zębatego.</p>'),
                $step('Zmień hasło', '<p>W sekcji <strong>Ogólne</strong> (General), w części <strong>Logowanie</strong> (Sign in), kliknij <strong>Zmień hasło</strong> (Change Password). '
                    . 'Wpisz stare hasło, potem dwa razy nowe, i zapisz.</p>'),
                $step('Potwierdź w panelu kursanta', '<p>W <a href="' . $poczta . '">zakładce „Poczta kursanta”</a> kliknij „Mam już własne hasło”. Hasło startowe zniknie z panelu.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Jakie hasło wybrać</h2><ul>'
                . '<li>Inne niż do panelu kursanta i inne niż do innych stron.</li>'
                . '<li>Długie: najlepiej kilka słów połączonych cyfrą albo znakiem, np. trzy niepowiązane słowa.</li>'
                . '<li>Zapisz je w menedżerze haseł albo w bezpiecznym miejscu.</li></ul>'
                . '<p>Gdy masz pocztę w telefonie albo w programie pocztowym, po zmianie hasła wpisz tam nowe hasło.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Zapomniałeś(-aś) hasła do poczty albo nie widzisz przycisku „Zmień hasło”? Napisz przez '
                . '<a href="' . $pomoc . '">zakładkę Pomoc w panelu kursanta</a>. Biuro ustawi nowe hasło startowe.</p>'],
            $safe,
        ], false],

        'poczta-w-telefonie' => ['Poczta kursanta w telefonie',
            'poczta kursanta, telefon, android, iphone, ios, gmail, poczta, mail, dodaj konto, imap, smtp, 993, 465, titan.sdata.net.pl, stud.feer.org.pl, thunderbird, outlook', [
            ['type' => 'text', 'html' => '<p>Pocztę kursanta możesz czytać w telefonie. Dodajesz ją jako konto <strong>IMAP</strong>. Przygotuj takie dane:</p>'
                . '<table><thead><tr><th>Ustawienie</th><th>Wartość</th></tr></thead><tbody>'
                . '<tr><td>Adres e-mail i nazwa użytkownika</td><td>pełny adres, np. <code>j.kowalski@stud.feer.org.pl</code></td></tr>'
                . '<tr><td>Hasło</td><td>hasło do poczty kursanta (nie do panelu)</td></tr>'
                . '<tr><td>Serwer poczty przychodzącej (IMAP)</td><td><code>titan.sdata.net.pl</code>, port 993, SSL/TLS</td></tr>'
                . '<tr><td>Serwer poczty wychodzącej (SMTP)</td><td><code>titan.sdata.net.pl</code>, port 465, SSL/TLS, wymaga logowania</td></tr>'
                . '</tbody></table>'
                . '<p>Najpierw ustaw własne hasło w poczcie. Inaczej po zmianie hasła telefon przestanie odbierać wiadomości.</p>'],
            ['type' => 'wizard', 'title' => 'Telefon z Androidem (aplikacja Gmail)', 'intro' => '', 'steps' => [
                $step('Dodaj konto', '<p>Otwórz Gmail, dotknij swojego zdjęcia w prawym górnym rogu i wybierz „Dodaj kolejne konto”, a potem „Inne”.</p>'),
                $step('Wpisz adres', '<p>Wpisz pełny adres <code>@stud.feer.org.pl</code> i wybierz „Konfiguracja ręczna”, a potem „Osobiste (IMAP)”.</p>'),
                $step('Wpisz hasło i serwery', '<p>Podaj hasło. Jako serwer przychodzący i wychodzący wpisz <code>titan.sdata.net.pl</code>, '
                    . 'typ zabezpieczeń SSL/TLS, porty 993 i 465.</p>'),
                $step('Zakończ', '<p>Zatwierdź ustawienia synchronizacji. Wiadomości pojawią się po chwili.</p>'),
            ]],
            ['type' => 'wizard', 'title' => 'iPhone (aplikacja Mail)', 'intro' => '', 'steps' => [
                $step('Dodaj konto', '<p>Otwórz Ustawienia, potem „Aplikacje”, „Mail”, „Konta pocztowe” i „Dodaj konto”. Wybierz „Inne”, a potem „Dodaj konto Mail”.</p>'),
                $step('Wpisz dane', '<p>Podaj imię i nazwisko, pełny adres <code>@stud.feer.org.pl</code>, hasło i opis, np. „Poczta kursanta”. Dotknij „Dalej”.</p>'),
                $step('Wybierz IMAP i serwery', '<p>Zostaw zaznaczone „IMAP”. W polach serwera poczty przychodzącej i wychodzącej wpisz <code>titan.sdata.net.pl</code>, '
                    . 'a jako nazwę użytkownika pełny adres e-mail. Dotknij „Dalej” i „Zachowaj”.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>W starszych telefonach nazwy przycisków mogą się trochę różnić. Dane z tabeli są zawsze te same. '
                . 'W programach Thunderbird i Outlook podajesz je tak samo.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Telefon nie łączy się z pocztą? Sprawdź, czy wpisujesz pełny adres jako nazwę użytkownika i hasło do poczty, a nie do panelu. '
                . 'Jeśli to nie pomoże, napisz przez <a href="' . $pomoc . '">zakładkę Pomoc w panelu kursanta</a>.</p>'],
        ], false],

        'pakiet-biurowy' => ['Word i Excel po wyłączeniu konta @edu: darmowy pakiet biurowy',
            'office, ooffice, libreoffice, openoffice, onlyoffice, word, excel, powerpoint, pakiet biurowy, dokumenty, arkusze, prezentacje, docx, xlsx, pptx, odt, ods, odp, pdf, microsoft 365, edu.feer.org.pl, darmowy, instalacja', [
            ['type' => 'text', 'html' => '<p>Z kontem <code>@edu.feer.org.pl</code> mogłeś(-aś) korzystać z Worda, Excela i PowerPointa z Microsoft 365. '
                . '20 listopada 2026 r. wyłączamy te konta. Programy Microsoft zalogowane tym kontem przestaną wtedy pozwalać na edycję albo w ogóle się nie otworzą.</p>'
                . '<p>Zamiast nich zainstaluj darmowy pakiet biurowy. Nie potrzebujesz do niego konta ani licencji z Fundacji.</p>'],
            ['type' => 'text', 'html' => '<h2>Który program wybrać</h2>'
                . '<table><thead><tr><th>Program</th><th>Dla kogo</th><th>Skąd pobrać</th></tr></thead><tbody>'
                . '<tr><td><strong>LibreOffice</strong></td><td>pełny pakiet: dokumenty, arkusze, prezentacje, rysunki; po polsku; Windows, macOS i Linux</td>'
                . '<td><a href="https://pl.libreoffice.org/">pl.libreoffice.org</a></td></tr>'
                . '<tr><td><strong>ONLYOFFICE Desktop Editors</strong></td><td>wygląd podobny do Worda i Excela, dobrze zachowuje układ plików .docx i .xlsx</td>'
                . '<td><a href="https://www.onlyoffice.com/">onlyoffice.com</a></td></tr>'
                . '</tbody></table>'
                . '<p>Oba programy otwierają i zapisują pliki Worda, Excela i PowerPointa (<code>.docx</code>, <code>.xlsx</code>, <code>.pptx</code>) oraz zapisują do PDF. '
                . 'Pobieraj je tylko z oficjalnych stron podanych wyżej.</p>'],
            ['type' => 'wizard', 'title' => 'Instalacja LibreOffice', 'intro' => '', 'steps' => [
                $step('Pobierz', '<p>Wejdź na <a href="https://pl.libreoffice.org/">pl.libreoffice.org</a> i kliknij „Pobierz”. Strona sama dobierze wersję do Twojego komputera.</p>'),
                $step('Zainstaluj', '<p>Otwórz pobrany plik i klikaj „Dalej”. Ustawienia domyślne są dobre.</p>'),
                $step('Zapisuj w formacie, którego potrzebujesz', '<p>Gdy wysyłasz plik prowadzącemu albo do biura, przy zapisie wybierz format Worda (<code>.docx</code>) albo PDF. '
                    . 'W LibreOffice: „Plik”, „Zapisz jako” i typ pliku „Word 2007–365”, albo „Plik”, „Eksportuj jako PDF”.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Masz pliki w OneDrive na koncie <code>@edu.feer.org.pl</code>? Pobierz je na swój komputer przed 20 listopada 2026 r. Po wyłączeniu konta nie da się ich odzyskać.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Na telefonie dokumenty otworzysz w bezpłatnej aplikacji ONLYOFFICE Documents albo w podglądzie wbudowanym w telefon. '
                . 'Kłopot z instalacją? Napisz przez <a href="' . $pomoc . '">zakładkę Pomoc w panelu kursanta</a>.</p>'],
        ], false],

        'problemy-z-nowym-kontem' => ['Nie dostałem(-am) loginu, hasła albo kodu',
            'nie przyszedł sms, brak sms, brak hasła, brak loginu, nie dostałem, nie dostałam, kod nie działa, hasło nie działa, zablokowane konto, zmiana numeru telefonu, nowe konto, poczta kursanta, panel kursanta', [
            ['type' => 'text', 'html' => '<p>Tu znajdziesz najczęstsze kłopoty z nowym kontem i co wtedy zrobić.</p>'
                . '<h2>Nie przyszedł SMS z hasłem do poczty</h2>'
                . '<p>Zaloguj się do panelu kursanta i otwórz zakładkę <a href="' . $poczta . '">„Poczta kursanta”</a>. Kliknij „Pokaż hasło”. '
                . 'Przyjdzie SMS z kodem, ważnym 10 minut. Po jego wpisaniu panel pokaże hasło startowe. '
                . 'Nie wysyłamy SMS-ów z hasłem w nocy, między 22:00 a 7:00.</p>'
                . '<h2>Nie przyszedł e-mail z adresem poczty</h2>'
                . '<p>Sprawdź folder ze spamem. Adres poczty zobaczysz też w panelu, w zakładce „Poczta kursanta”. '
                . 'Gdy tej zakładki nie ma, konto jeszcze nie powstało. Konta zakłada zewnętrzny administrator na polecenie kierownika szkolenia, więc zapytaj kierownika.</p>'
                . '<h2>Nie przyszedł kod przy logowaniu do panelu</h2>'
                . '<p>Kod idzie na numer telefonu zapisany w Twoim koncie. Odczekaj chwilę i spróbuj jeszcze raz. '
                . 'Jeśli zmieniłeś(-aś) numer telefonu, zadzwoń albo napisz do placówki.</p>'
                . '<h2>Hasło do poczty nie działa</h2><ul>'
                . '<li>Jako nazwę użytkownika wpisz pełny adres, razem z <code>@stud.feer.org.pl</code>.</li>'
                . '<li>Hasło do poczty jest inne niż hasło do panelu kursanta.</li>'
                . '<li>Przepisuj hasło startowe dokładnie, zwracaj uwagę na wielkie i małe litery. Najlepiej skopiuj je przyciskiem „Kopiuj” w panelu.</li></ul>'
                . '<h2>Hasło startowe zniknęło z panelu</h2>'
                . '<p>Panel trzyma hasło startowe 14 dni i pokazuje je najwyżej 5 razy. Potem usuwa je ze swojej bazy. Napisz do nas, a biuro wyśle nowe.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Zgłoś problem przez <a href="' . $pomoc . '">zakładkę Pomoc w panelu kursanta</a>. '
                . 'Jeśli nie możesz się zalogować do panelu, zadzwoń albo napisz do placówki, w której masz zajęcia.</p>'],
            $safe,
        ], false],
    ];
}

/**
 * Krótki artykuł „Logowanie do skrzynki fundacja@stud.feer.org.pl” w usłudze „Moja poczta” (odbiorcy kadry, nie kursanci;
 * polecenie 2026-11-14: „zrób małą instrukcję zaloguj się jako fundacja@stud.feer.org.pl”). Hasła w artykule celowo nie ma —
 * przekazuje je kierownik. Pomija, gdy nie ma usługi „Moja poczta”. @return array{status:string, article:string}
 */
function itPortalFundacjaStudApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moja-poczta'");
    if (!$cat) return ['status' => 'brak usługi „Moja poczta” — pominięto', 'article' => ''];
    $old = db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = 'fundacja-stud'", [(int)$cat['id']]);
    $title = 'Logowanie do skrzynki fundacja@stud.feer.org.pl';
    $rep = ['status' => $old ? 'nadpisany' : 'utworzony', 'article' => "moja-poczta/fundacja-stud — {$title}"];
    if ($dryRun) return $rep;
    $web = 'https://titan.sdata.net.pl/';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    $blocks = [
        ['type' => 'text', 'html' => '<p>Skrzynka <code>fundacja@stud.feer.org.pl</code> to konto Fundacji w domenie poczty kursantów. '
            . 'Działa na tym samym odrębnym serwerze co poczta kursantów (sdata.net.pl), a nie na dpoczta.pl.</p>'],
        ['type' => 'wizard', 'title' => 'Zaloguj się jako fundacja@stud.feer.org.pl', 'intro' => '', 'steps' => [
            $step('Otwórz pocztę', '<p>W przeglądarce wejdź na <a href="' . $web . '">https://titan.sdata.net.pl</a>.</p>'),
            $step('Wpisz login', '<p>Jako nazwę użytkownika wpisz pełny adres: <code>fundacja@stud.feer.org.pl</code>.</p>'),
            $step('Wpisz hasło', '<p>Hasło przekazuje kierownik. Nie wysyłamy go e-mailem i nie zapisujemy w Pomocy IT.</p>'),
            $step('Przy pierwszym logowaniu zmień hasło', '<p>Jeśli to hasło startowe, zmień je w <strong>Preferencjach</strong> (Właściwościach), '
                . 'w sekcji Ogólne, przyciskiem <strong>Zmień hasło</strong>. Nowe hasło przekaż kierownikowi bezpiecznym kanałem, nie e-mailem.</p>'),
        ]],
        ['type' => 'text', 'html' => '<h2>W programie pocztowym albo w telefonie</h2>'
            . '<p>Serwer poczty przychodzącej i wychodzącej: <code>titan.sdata.net.pl</code>. IMAP z szyfrowaniem SSL/TLS na porcie 993, '
            . 'SMTP z szyfrowaniem SSL/TLS na porcie 465. Nazwa użytkownika to pełny adres <code>fundacja@stud.feer.org.pl</code>.</p>'],
        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>To konto wspólne. Nie zapisuj hasła w przeglądarce na cudzym komputerze i po pracy wyloguj się. '
            . 'Konta w domenie <code>@stud.feer.org.pl</code> zakłada zewnętrzny administrator na polecenie kierownika.</p>'],
    ];
    itPortalArticleSave([
        'title' => $title, 'slug' => 'fundacja-stud', 'category_id' => (int)$cat['id'],
        'keywords' => 'fundacja@stud.feer.org.pl, stud.feer.org.pl, skrzynka fundacji, konto wspólne, poczta kursanta, titan, titan.sdata.net.pl, zimbra, logowanie, imap, smtp',
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        'related' => $old ? (json_decode((string)$old['related'], true) ?: []) : [],
        'is_published' => 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : 0,
        'sort_order' => $old ? (int)$old['sort_order'] : 900,
    ], $old ? (int)$old['id'] : 0);
    return $rep;
}
