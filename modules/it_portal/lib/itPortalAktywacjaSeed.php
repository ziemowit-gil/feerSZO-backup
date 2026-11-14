<?php
/**
 * modules/it_portal/lib/itPortalAktywacjaSeed.php — artykuł „Aktywacja konta w SZO” w usłudze „Mój login” (polecenie 2026-11-14:
 * „opisz aktywację konta w SZO” ze zrzutami „Odzyskiwanie dostępu” i „Oświadczenia zleceniobiorcy”; „oświadczenie online służy tylko
 * osobom, które podpisują i zamierzają zawrzeć umowę zlecenie”).
 * Źródła: auth/login.php (kafle „Nowy wolontariusz: Kod aktywacyjny / Aktywuj konto”, „Nowy zleceniobiorca: Oświadczenie”),
 * kod jednorazowy z karty umowy (contracts/wolontariat/view.php, auth_login_by_code), user/verify_reset.php (e-mail + numer umowy +
 * 5 ostatnich cyfr PESEL albo numer dokumentu; „Nie znam numeru umowy” = kod odzyskiwania 8 cyfr dla umów sprzed 1.06.2026; kod SMS 6 cyfr
 * ważny 10 min, 3 błędne = od nowa; konto zakładane automatycznie; zakładki „Nie masz konta?”, „Sprawdź numer umowy”),
 * modules/zlecenie_oswiadczenie (/zlecenie: kreator, wydruk, skan, konto tylko do oświadczeń, link do hasła e-mailem).
 * Używa migracja '2026-11_it_portal_aktywacja'; ramka „stan na” (ITP_STAN_NA) od migracji '2026-11_it_portal_logowanie_szo'.
 */

require_once __DIR__ . '/itPortalLogowanieSzoSeed.php';

function itPortalAktywacjaApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) return 'brak usługi „Mój login” — pominięto';
    $catId = (int)$cat['id'];
    $slug = 'aktywacja-konta';
    $title = 'Aktywacja konta w SZO: nowy wolontariusz i nowy zleceniobiorca';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
    $first = db_one("SELECT id FROM itp_articles WHERE category_id = ? AND slug = 'pierwsze-logowanie'", [$catId]);
    if ($first && !in_array((int)$first['id'], $related, true)) $related[] = (int)$first['id'];
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'aktywacja konta, aktywuj konto, kod aktywacyjny, kod jednorazowy, nowy wolontariusz, nowy zleceniobiorca, oświadczenie, oświadczenia zleceniobiorcy, umowa zlecenie, odzyskiwanie dostępu, weryfikacja danych, numer umowy, pesel, numer dokumentu, kod sms, kod odzyskiwania, nie znam numeru umowy, nie mam konta, założenie konta, pierwsze hasło',
        'blocks' => json_encode(itPortalAktywacjaBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1, 'sort_order' => 5,
        'related' => $related], $old ? (int)$old['id'] : 0);
    return "moj-login/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalAktywacjaBlocks(string $app): array {
    $login = $app . '/auth/login.php';
    $vr = $app . '/user/verify_reset.php';
    $zl = $app . '/zlecenie';
    $hd = $app . '/helpdesk/new.php';
    $issue = $app . '/auth/report_login_issue.php';
    $first = $app . '/uslugi-it/uslugi/moj-login/pierwsze-logowanie';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>Konto w SZO zakładamy na podstawie umowy z Fundacją. Gdy umowa jest już w systemie, aktywujesz konto sam, bez pisania do administratora. '
            . 'Pod formularzem na <a href="' . $login . '">stronie logowania</a> są kafle „Pierwsze logowanie”. Wybierz ten, który pasuje do Twojej sytuacji:</p><ul>'
            . '<li>„Nowy wolontariusz, Kod aktywacyjny”, gdy koordynator dał Ci kod.</li>'
            . '<li>„Nowy wolontariusz, Aktywuj konto”, gdy kodu nie masz. Potwierdzisz się danymi z umowy i SMS-em.</li>'
            . '<li>„Nowy zleceniobiorca, Oświadczenie”, tylko gdy zawierasz z Fundacją umowę zlecenie.</li></ul>'
            . '<p>Po aktywacji panel poprowadzi Cię przez pierwsze ekrany. Opisuje je instrukcja <a href="' . $first . '">Pierwsze logowanie krok po kroku</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Kod aktywacyjny od koordynatora</h2>'
            . '<p>Koordynator może wygenerować kod na karcie Twojej umowy i przekazać Ci go osobiście albo telefonicznie. Na stronie logowania kliknij „Kod aktywacyjny”, wpisz kod i zatwierdź. '
            . 'Kod działa tylko raz. Po wejściu do panelu od razu ustaw własne hasło.</p>'],

        ['type' => 'wizard', 'title' => 'Aktywuj konto danymi z umowy', 'intro' => '<p>Ten sam formularz służy do pierwszej aktywacji i do odzyskania dostępu, gdy nie pamiętasz hasła. Nazywa się „Odzyskiwanie dostępu”. '
            . 'Jeśli to Twoja pierwsza wizyta i nie masz jeszcze konta, formularz założy je automatycznie.</p>', 'steps' => [
            $step('Otwórz formularz', '<p>Na stronie logowania kliknij „Aktywuj konto” albo „Odzyskiwanie dostępu”. Możesz też wejść prosto na <a href="' . $vr . '">formularz odzyskiwania dostępu</a>.</p>'),
            $step('Podaj dane z umowy', '<p>Wpisz trzy rzeczy, dokładnie tak jak w umowie:</p><ul>'
                . '<li>adres e-mail podany w umowie,</li>'
                . '<li>numer umowy, na przykład RU/0001/2024/AB,</li>'
                . '<li>ostatnie 5 cyfr numeru PESEL albo pełny numer dokumentu tożsamości.</li></ul>'
                . '<p>Kliknij „Weryfikuj dane”.</p>'),
            $step('Wpisz kod z SMS-a', '<p>Jeśli dane się zgadzają, dostaniesz SMS z sześciocyfrowym kodem na numer telefonu z umowy. Osoby niepełnoletnie dostają kod na numer opiekuna. '
                . 'Kod jest ważny 10 minut. Wpisz go i kliknij „Potwierdź kod”. Po trzech błędnych kodach trzeba zacząć od początku.</p>'),
            $step('Ustaw hasło', '<p>Wymyśl hasło i wpisz je dwa razy. Musi mieć co najmniej 8 znaków, wielką literę, małą literę i cyfrę. Kliknij „Ustaw hasło i zaloguj się”, a potem zaloguj się adresem e-mail i nowym hasłem.</p>'),
        ]],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Formularz zawsze odpowiada tak samo, czy dane pasują, czy nie. Dzięki temu nikt obcy nie sprawdzi, czy masz u nas umowę. '
            . 'Jeśli SMS nie przychodzi, sprawdź literówki w adresie e-mail i numerze umowy. Kliknij „Wróć i popraw dane” i spróbuj jeszcze raz.</p>'],

        ['type' => 'text', 'html' => '<h2>Nie znasz numeru umowy</h2>'
            . '<p>Umowy zawarte przed 1 czerwca 2026 często nie mają numeru. Wtedy włącz przełącznik „Nie znam numeru umowy”. Zamiast numeru wpiszesz ośmiocyfrowy kod odzyskiwania z dokumentów umowy.</p>'
            . '<p>Pod formularzem są też zakładki pomocnicze. „Nie masz konta?” sprawdza po adresie e-mail albo nazwisku, czy jest umowa bez konta. '
            . '„Sprawdź numer umowy” potwierdza, że umowa o danym numerze jest w rejestrze, bez podawania PESEL-u.</p>'],

        ['type' => 'text', 'html' => '<h2>Nowy zleceniobiorca: oświadczenia online</h2>'
            . '<p>Kafel „Oświadczenie” prowadzi do strony <a href="' . $zl . '">Oświadczenia zleceniobiorcy</a>. Jest przeznaczony tylko dla osób, które podpisują i zamierzają zawrzeć z Fundacją umowę zlecenie. '
            . 'Wolontariusze i pracownicy z niego nie korzystają.</p>'
            . '<p>Na tej stronie wypełniasz dwa dokumenty potrzebne do umowy zlecenia: oświadczenie dla celów podatkowych i ubezpieczenia ZUS oraz oświadczenie o numerze rachunku bankowego.</p>'],

        ['type' => 'wizard', 'title' => 'Oświadczenia zleceniobiorcy krok po kroku', 'intro' => '', 'steps' => [
            $step('Wypełnij kreator', '<p>Kliknij „Rozpocznij wypełnianie”. Kreator ma 6 kroków i zajmuje około 10 minut. Przygotuj PESEL, adres zamieszkania, urząd skarbowy i numer rachunku. '
                . 'Każdy krok zapisuje się od razu. Adres strony kreatora jest Twoim kluczem do niego, więc nie udostępniaj go innym osobom.</p>'),
            $step('Wydrukuj i podpisz', '<p>Pobierz i wydrukuj oświadczenia razem z instrukcją. Sprawdź dane, wpisz datę i podpisz.</p>'),
            $step('Prześlij skan', '<p>Prześlij przez tę samą stronę skan albo zdjęcie podpisanych dokumentów.</p>'),
            $step('Ustaw hasło do konta', '<p>Po przesłaniu skanu zakładamy Ci konto z dostępem tylko do Twoich oświadczeń. Link do ustawienia hasła przyjdzie na podany adres e-mail. '
                . 'Na koncie sprawdzisz status dokumentów i wydrukujesz je ponownie. Później wejdziesz tam kaflem „Zaloguj się” na stronie oświadczeń.</p>'),
        ]],
        ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Zleceniobiorcy często logują się do SZO prywatnym adresem e-mail, tym z umowy, i hasłem nadanym przez Fundację. Jeśli to Twoja sytuacja, wpisz ten adres i hasło w polach „Adres e-mail” i „Hasło”, a potem kliknij „Zaloguj”. Nie pamiętasz hasła? Kliknij „Nie pamiętasz hasła?”.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nikomu nie podawaj kodu z SMS-a, kodu aktywacyjnego ani hasła. Fundacja nigdy nie prosi o nie e-mailem ani telefonicznie. '
            . 'Nie możesz aktywować konta, mimo że masz umowę? <a href="' . $issue . '">Zgłoś problem z logowaniem</a>, to działa bez logowania, albo <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
    ];
}
