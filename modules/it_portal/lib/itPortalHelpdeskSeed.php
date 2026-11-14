<?php
/**
 * modules/it_portal/lib/itPortalHelpdeskSeed.php — wstęp i artykuły usługi „Helpdesk” w Portalu Usług IT (polecenie 2026-11-14: „opisz Helpdesk”).
 * Źródła: helpdesk/new.php (Temat, Kategoria, Pilność, Opis, Załączniki, dane kontaktowe), HD_STATUSES / HD_PRIORITIES (czasy reakcji)
 * i HD_CATEGORIES w includes/helpdesk.php, track.php (podgląd z linku bez logowania), csat.php (ankieta), auth/report_login_issue.php
 * (zgłoszenie problemu z logowaniem bez logowania), zgłoszenia z panelu kursanta (KUR). Artykuł „czat-pomocy-it” zostaje bez zmian.
 * Usługa ma external_url do formularza — kafel prowadzi prosto do zgłoszenia; artykuły są osiągalne z wyszukiwarki i linków.
 * Używa migracja '2026-11_it_portal_helpdesk'.
 */

function itPortalHelpdeskApply(string $app): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'helpdesk'");
    if (!$cat) return ['brak usługi „Helpdesk” — pominięto'];
    $catId = (int)$cat['id'];
    $b = $app . '/uslugi-it/uslugi/helpdesk/';
    $intro = itPortalSanitizeHtml('<p>Helpdesk IT to miejsce, w którym zgłaszasz problemy z komputerem, kontem, pocztą, chmurą i innymi usługami IT Fundacji. '
        . 'Każde zgłoszenie dostaje numer, a Ty dostajesz e-mailem link, pod którym śledzisz postęp i odpisujesz, bez logowania.</p>'
        . '<p><a href="' . $app . '/helpdesk/new.php">Zgłoś problem</a>. Jak dobrze opisać zgłoszenie, mówi instrukcja '
        . '<a href="' . $b . 'jak-zglosic">Jak zgłosić problem w Helpdesku IT</a>.</p>');
    db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    $rep = [];
    foreach (itPortalHelpdeskArticles($app) as $slug => [$title, $keywords, $blocks, $pinned, $sort]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => $sort, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $rep[] = "helpdesk/{$slug} — {$title}";
    }
    return $rep;
}

/** slug => [tytuł, słowa kluczowe, bloki, przypięty, kolejność] */
function itPortalHelpdeskArticles(string $app): array {
    $new = $app . '/helpdesk/new.php';
    $b = $app . '/uslugi-it/uslugi/helpdesk/';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'jak-zglosic' => ['Jak zgłosić problem w Helpdesku IT', 'helpdesk, zgłoszenie, zgłoś problem, awaria, pomoc it, nowe zgłoszenie, kategoria, pilność, załącznik, zrzut ekranu, problem z logowaniem, nie mogę się zalogować, kursant', [
            ['type' => 'wizard', 'title' => 'Zgłoszenie krok po kroku', 'intro' => '', 'steps' => [
                $step('Otwórz formularz', '<p>Wejdź na stronę <a href="' . $new . '">nowego zgłoszenia</a>. Prowadzi do niej też kafel „Helpdesk” w Portalu Usług IT i odnośniki „napisz do Helpdesku” w instrukcjach.</p>'),
                $step('Temat i kategoria', '<p>W polu „Temat zgłoszenia” napisz krótko, czego dotyczy problem, na przykład „Nie mogę zalogować się do chmury”. '
                    . 'W polu „Kategoria” wybierz najbliższą: Sprzęt IT, Oprogramowanie, Sieć / Internet, Dostęp / Uprawnienia, Konto / Logowanie, Microsoft 365, Drukarki / Urządzenia, Kopia zapasowa / Dane albo Inne IT.</p>'),
                $step('Pilność', '<p>Wybierz, jak pilna jest sprawa: niska, normalna, wysoka albo krytyczna. Krytyczna to sytuacja, w której nie możesz pracować albo problem dotyczy wielu osób. '
                    . 'Pilność wpływa na to, jak szybko odpowiemy.</p>'),
                $step('Opis i załączniki', '<p>W „Opisie problemu” napisz, co się dzieje, od kiedy, na jakim urządzeniu i co już próbowałeś. Dołącz zrzut ekranu z komunikatem błędu. '
                    . 'Nie wpisuj w zgłoszeniu haseł ani kodów z SMS-a.</p>'),
                $step('Dane kontaktowe i wysłanie', '<p>Sprawdź imię i nazwisko, e-mail i telefon. Na ten e-mail przyjdzie potwierdzenie z numerem zgłoszenia. Wyślij formularz.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Gdy nie możesz się zalogować</h2>'
                . '<p>Na stronie logowania do SZO jest odnośnik do zgłoszenia problemu z logowaniem. Działa bez logowania: podajesz imię i nazwisko, e-mail i opis, a my odezwiemy się do Ciebie. '
                . 'Nigdy nie prosimy w nim o numer umowy ani hasło.</p>'
                . '<p>Kursanci zgłaszają problemy techniczne w swoim panelu, w zakładce „Zgłoś problem techniczny”.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Na proste pytania odpowie od razu <a href="' . $b . 'czat-pomocy-it">Czat Pomocy IT</a>, a wiele odpowiedzi jest w instrukcjach Portalu Usług IT. '
                . 'Jeden problem to jedno zgłoszenie. Dwie różne sprawy zgłoś osobno, wtedy szybciej załatwimy każdą z nich.</p>'],
        ], true, 10],

        'co-dalej-ze-zgloszeniem' => ['Co dzieje się ze zgłoszeniem: numer, statusy, odpowiedzi', 'numer zgłoszenia, status zgłoszenia, podgląd zgłoszenia, link do zgłoszenia, odpowiedź, czas reakcji, nowe, otwarte, oczekuje, przekazano do firmy zewnętrznej, wymaga prac programistycznych, rozwiązane, zamknięte, ankieta, ocena, udostępnij podgląd, scalone', [
            ['type' => 'text', 'html' => '<p>Po wysłaniu zgłoszenie dostaje numer, a Ty e-mail od „FEER | Helpdesk” z linkiem do podglądu. Pod tym linkiem, bez logowania, widzisz całą rozmowę z nami i odpisujesz. '
                . 'Zachowaj tę wiadomość. Każda nasza odpowiedź też przychodzi e-mailem.</p>'],
            ['type' => 'text', 'html' => '<h2>Statusy</h2><ul>'
                . '<li>„Nowe”: zgłoszenie czeka, aż ktoś je przejmie.</li>'
                . '<li>„Otwarte”: zajmujemy się nim.</li>'
                . '<li>„Oczekuje”: czekamy na Twoją odpowiedź albo informację. Odpisz w podglądzie, wtedy wrócimy do pracy.</li>'
                . '<li>„Krytyczne, wymaga interwencji”: sprawa ma najwyższy priorytet.</li>'
                . '<li>„Przekazano do firmy zewnętrznej”: problem rozwiązuje dostawca usługi, na przykład dostawca poczty. Damy znać, gdy odpowie.</li>'
                . '<li>„Wymaga prac programistycznych”: potrzebna jest zmiana w systemie, która wejdzie w jednej z kolejnych wersji.</li>'
                . '<li>„Rozwiązane”: uważamy, że problem jest rozwiązany. Sprawdź, czy wszystko działa.</li>'
                . '<li>„Zamknięte”: sprawa jest zakończona.</li></ul>'],
            ['type' => 'text', 'html' => '<h2>Jak szybko odpowiadamy</h2>'
                . '<p>Czas pierwszej odpowiedzi zależy od pilności: przy krytycznej do pół godziny, przy wysokiej do 2 godzin, przy normalnej do 8 godzin, a przy niskiej do doby. '
                . 'Rozwiązanie zajmuje zwykle dłużej, zwłaszcza gdy sprawa trafia do firmy zewnętrznej albo wymaga zmian w systemie. Wtedy czas jest wstrzymany do odpowiedzi dostawcy.</p>'],
            ['type' => 'text', 'html' => '<h2>Inne sytuacje</h2>'
                . '<p>Jeśli wyślesz dwa zgłoszenia w tej samej sprawie, połączymy je w jedno. Dostaniesz informację, pod którym numerem toczy się sprawa.</p>'
                . '<p>Na Twoją prośbę możemy udostępnić podgląd zgłoszenia innej osobie, na przykład przełożonemu. Dostanie ona link do tej samej rozmowy.</p>'
                . '<p>Po zakończeniu sprawy możesz dostać krótką ankietę z pytaniem, jak oceniasz pomoc. Odpowiedź zajmuje chwilę i pomaga nam poprawiać obsługę.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Link do podglądu zgłoszenia działa bez logowania, więc nie przesyłaj go dalej osobom, które nie powinny widzieć rozmowy. '
                . 'Jeśli problem wrócił po zamknięciu zgłoszenia, odpisz w podglądzie albo <a href="' . $new . '">załóż nowe zgłoszenie</a> i podaj numer poprzedniego.</p>'],
        ], false, 20],
    ];
}
