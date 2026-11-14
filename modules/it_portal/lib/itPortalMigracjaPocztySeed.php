<?php
/**
 * modules/it_portal/lib/itPortalMigracjaPocztySeed.php — artykuł „Migracja poczty krok po kroku i gdzie są Twoje pliki” w usłudze
 * „Migracja” (polecenie 2026-11-14: „opisz /migracjapoczty krok po kroku i opisz, gdzie są pliki”). Napisy z
 * modules/dhosting_migrator/view/self.php i guest.php (lista „Numer komórkowy w SZO / Nowa skrzynka i hasło SMS-em / Kopia poczty
 * z Microsoft 365”, „Załóż skrzynkę i wyślij hasło SMS-em”, „Skopiuj też moją pocztę z Microsoft 365”, „Wznów kopiowanie”,
 * „Pobierz PDF z danymi logowania”, „Załóż konto w chmurze”), pliki: CloudFilesCopy::TARGET = folder „OneDrive” w chmurze
 * (kopiowanie zleca administrator), foldery „Google Drive” / „Kopia konta” = dane z dawnych serwerów. Bez nazwy dostawcy poczty
 * (decyzja 2026-11-13). Sekcja „Dlaczego 5 GB, skoro w Microsoft 365 był 1 TB” (polecenie 2026-11-14): 1 TB = OneDrive, poczta 5 GB
 * (kadra kierownicza bez zmian), chmura 10 GB na osobę (itPortalCloudQuotaNote). Ramka ITP_STAN_NA. Używa migracja '2026-11_it_portal_migracja_poczty'.
 */

require_once __DIR__ . '/itPortalLogowanieSzoSeed.php';

function itPortalMigracjaPocztyApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'migracja'");
    if (!$cat) return 'brak usługi „Migracja” — pominięto';
    $catId = (int)$cat['id'];
    $slug = 'migracja-poczty-krok-po-kroku';
    $title = 'Migracja poczty krok po kroku i gdzie są Twoje pliki';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
    foreach (db_all("SELECT id FROM itp_articles WHERE category_id = ? AND slug IN ('migracja','iam-i-hasla','zmien-haslo-startowe')", [$catId]) as $r) {
        if (!in_array((int)$r['id'], $related, true)) $related[] = (int)$r['id'];
    }
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => '5 gb, 1 tb, pojemność, limit skrzynki, miejsce w poczcie, limit chmury, 10 gb, migracja poczty, migracjapoczty, przeniesienie poczty, nowa skrzynka, załóż skrzynkę, hasło sms, kopia poczty, kopiowanie poczty, stara poczta, gdzie są moje maile, gdzie są pliki, onedrive, google drive, kopia konta, chmura plików, foldery outlooka, wznów kopiowanie, pdf z danymi logowania',
        'blocks' => json_encode(itPortalMigracjaPocztyBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1, 'sort_order' => 3,
        'related' => $related], $old ? (int)$old['id'] : 0);
    return "migracja/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalMigracjaPocztyBlocks(string $app): array {
    $mp = $app . '/migracjapoczty';
    $hd = $app . '/helpdesk/new.php';
    $nh = $app . '/nowehaslo';
    $nc = $app . '/uslugi-it/uslugi/chmura-plikow/';
    $mail = $app . '/uslugi-it/uslugi/moja-poczta/';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>Na stronie <a href="' . $mp . '">Migracja poczty</a> przenosisz pocztę z Microsoft 365 do nowej skrzynki. Adres się nie zmienia, zmienia się tylko hasło. '
            . 'Zajmuje to około 5 minut. Stare wiadomości kopiują się same, w tle, razem z folderami.</p>'
            . '<p>Na stronę wejdziesz z Panelu współpracownika, kaflem „Migracja poczty”, albo bez logowania do SZO. Przygotuj telefon komórkowy, bo hasło do nowej skrzynki wysyłamy tylko SMS-em.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Jeśli zobaczysz napis „Twoja poczta jest już w nowej skrzynce”, migrację zrobił już administrator. Nic nie musisz przenosić. '
            . 'Na stronie masz login, czyli swój adres e-mail, przycisk do otwarcia poczty i dane do programu pocztowego. Hasło masz w SMS-ie. Zmień je na własne na stronie <a href="' . $nh . '">Nowe hasło</a>.</p>'],

        ['type' => 'wizard', 'title' => 'Gdy jesteś zalogowany do SZO', 'intro' => '<p>Na górze strony jest lista z trzema punktami: „Numer komórkowy w SZO”, „Nowa skrzynka i hasło SMS-em” i „Kopia poczty z Microsoft 365”. Przy każdym widać, czy jest gotowe, czy do zrobienia.</p>', 'steps' => [
            $step('Otwórz stronę', '<p>W Panelu współpracownika kliknij kafel „Migracja poczty” albo wejdź na <a href="' . $mp . '">Migrację poczty</a>. Na początku strony jest krótkie wyjaśnienie, dlaczego przenosimy pocztę.</p>'),
            $step('Sprawdź numer telefonu', '<p>Jeśli przy „Numer komórkowy w SZO” jest „Do zrobienia”, wpisz numer komórkowy, 9 cyfr, i kliknij „Wyślij kod”. Wpisz sześciocyfrowy kod z SMS-a i kliknij „Potwierdź numer”. Numer zapiszemy w SZO.</p>'),
            $step('Załóż skrzynkę', '<p>Zaznacz, że numer jest Twój i masz przy sobie ten telefon. Zaznacz też „Skopiuj też moją pocztę z Microsoft 365”. Kliknij „Załóż skrzynkę i wyślij hasło SMS-em”. Po chwili dostaniesz SMS z hasłem.</p>'),
            $step('Poczekaj na kopię', '<p>W sekcji „Kopiowanie poczty z Microsoft 365” widać pasek postępu i liczbę skopiowanych wiadomości. Strona odświeża się sama co minutę. Kopia trwa od kilku minut do kilku godzin. Możesz w tym czasie zamknąć stronę.</p>'),
            $step('Otwórz nową pocztę', '<p>Kliknij „Otwórz pocztę w przeglądarce” i zaloguj się adresem e-mail i hasłem z SMS-a. Foldery z Outlooka są w nowej skrzynce pod tymi samymi nazwami.</p>'),
            $step('Ustaw telefon i komputer', '<p>W sekcji „Ustawienia programu pocztowego” są dane do telefonu, Outlooka i Thunderbirda. Przyciskiem „Pobierz PDF z danymi logowania” zapiszesz je w pliku, bez haseł. '
                . 'Instrukcje są w usłudze <a href="' . $mail . '">Moja poczta</a>.</p>'),
            $step('Zmień hasło na własne', '<p>Hasło z SMS-a zmień na stronie <a href="' . $nh . '">Nowe hasło</a>. Na dole strony migracji możesz też od razu założyć konto w chmurze plików, przyciskiem „Załóż konto w chmurze”. To osobne hasło, inne niż do poczty.</p>'),
        ]],

        ['type' => 'wizard', 'title' => 'Gdy nie możesz zalogować się do SZO', 'intro' => '', 'steps' => [
            $step('Podaj swoje dane', '<p>Wejdź na <a href="' . $mp . '">Migrację poczty</a>. W polu „Prywatny e-mail albo PESEL” wpisz prywatny adres z umowy, nie służbowy, albo PESEL. W drugim polu wpisz adres, którego pocztę przenosimy, na przykład imie.nazwisko@feer.org.pl.</p>'),
            $step('Potwierdź się kodem', '<p>Wpisz kod z SMS-a. Jeśli nie mamy Twojego numeru, podasz go teraz. Wtedy dla bezpieczeństwa drugi kod wyślemy e-mailem na Twój prywatny adres. Nie ma e-maila? Sprawdź folder ze spamem.</p>'),
            $step('Dalej tak samo', '<p>Po potwierdzeniu zobaczysz tę samą stronę co po zalogowaniu. Załóż skrzynkę, zaznacz kopię poczty i poczekaj na SMS z hasłem.</p>'),
        ]],

        ['type' => 'text', 'html' => '<h2>Gdzie jest Twoja stara poczta</h2><ul>'
            . '<li>Wiadomości z Microsoft 365 kopiujemy do nowej skrzynki, do folderów o tych samych nazwach co w Outlooku. Wiadomości już przeniesione się nie zdublują.</li>'
            . '<li>Nowe wiadomości, które jeszcze trafią do Microsoft 365, dosyłamy automatycznie co kilka godzin przez kilka dni po przełączeniu.</li>'
            . '<li>Oryginały zostają w starej skrzynce Microsoft 365 do jej wyłączenia 20 listopada 2026 r. Gdy jakaś wiadomość przeniesie się niecałkowicie, otwórz ją tam i prześlij do siebie.</li></ul>'],

        ['type' => 'text', 'html' => '<h2>Gdzie są Twoje pliki</h2><ul>'
            . '<li>Pliki z OneDrive kopiujemy do chmury plików Fundacji, do folderu „OneDrive”. Kopię uruchamia administrator, a pliki przenoszą się w tle. Nic nie musisz robić.</li>'
            . '<li>Foldery „Google Drive” albo „Kopia konta” w chmurze to dane z dawnych serwerów, z których kiedyś korzystała Fundacja.</li>'
            . '<li>Wspólne pliki zespołów są w chmurze w folderach zespołów.</li>'
            . '<li>Załączniki starych wiadomości są tam, gdzie wiadomości, czyli w nowej skrzynce.</li></ul>'
            . '<p>Jak zalogować się do chmury i znaleźć foldery, opisuje usługa <a href="' . $nc . 'nextcloud">Chmura plików</a>. Brakuje pliku, który był w OneDrive? Do 20 listopada możesz go jeszcze pobrać z OneDrive i wgrać do chmury.</p>'],

        ['type' => 'text', 'html' => '<h2>Dlaczego 5 GB, skoro w Microsoft 365 był 1 TB</h2>'
            . '<p>Te liczby dotyczą różnych rzeczy. W Microsoft 365 1 TB to było miejsce na pliki w OneDrive. Sama skrzynka pocztowa miała osobny, dużo mniejszy limit.</p>'
            . '<p>Teraz poczta i pliki też są osobno:</p><ul>'
            . '<li>Skrzynka pocztowa wolontariusza i pracownika ma 5 GB. Wystarcza to na lata zwykłej korespondencji, jeśli nie trzymasz w niej dużych załączników. Skrzynki kadry kierowniczej zachowują dotychczasowy limit.</li>'
            . '<li>Pliki trzymasz w chmurze plików Fundacji. Każde konto ma tam 10 GB na pliki osobiste, a większy limit może przyznać administracja.</li>'
            . '<li>Foldery zespołów i pliki udostępnione Ci przez innych nie zabierają Twoich 10 GB. Wspólne dokumenty trzymaj więc w folderach zespołów.</li></ul>'
            . '<p>Większość osób nie zbliżała się nawet do 1 TB, a płacenie za puste miejsce nie ma sensu. Duży plik wyślij linkiem z chmury zamiast załącznika, a skrzynka nie będzie się zapełniać. '
            . 'Brakuje Ci miejsca w poczcie albo w chmurze? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Kopia stanęła z komunikatem „Nowa skrzynka nie przyjęła hasła”? Wpisz aktualne hasło do nowej skrzynki w sekcji „Kopiowanie poczty” i kliknij „Wznów kopiowanie”. Kopia ruszy od miejsca, w którym stanęła. '
            . 'Po zakończonej migracji nowe hasło i ponowne kopiowanie ustawia już tylko administrator, więc wtedy <a href="' . $hd . '">napisz do Helpdesku IT</a>. Hasło do skrzynki wysyłamy tylko SMS-em, nigdy e-mailem.</p>'],
    ];
}
