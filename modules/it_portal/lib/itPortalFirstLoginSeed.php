<?php
/**
 * modules/it_portal/lib/itPortalFirstLoginSeed.php — artykuł „Pierwsze logowanie” w usłudze „Mój login” Portalu Usług IT
 * (/uslugi-it/uslugi/moj-login/pierwsze-logowanie), przebudowany po odejściu od Microsoft 365 (polecenie 2026-11-13:
 * „przebuduj też to”). Dawna wersja (migracja '2026-10_it_portal_first_login_article') kazała logować się przez Microsoft
 * i łączyć konto Microsoft 365.
 *
 * Opis zgodny z kodem na 2026-11-13: ekran logowania auth/login.php (logowanie jednokrotne Logto, Microsoft tylko dla
 * administratorów, e-mail i hasło, „Aktywuj konto”, „Oświadczenie” zleceniobiorcy), kolejka firstLoginGate()
 * (modules/first_run/lib/firstRun.php): hasło startowe → regulamin → kreator (telefon, Microsoft 365 i Outlook, profil,
 * stopka) → oświadczenie RODO → prośba o konto logowania jednokrotnego i chmury (ssoPromptGate, /sso/start).
 * Zasady haseł: /nowehaslo (IAM + chmura jedno hasło, poczta zawsze inne; od 1 grudnia jedno hasło do aplikacji Fundacji).
 *
 * Nadpisuje treść, tytuł i słowa kluczowe; zostawia id, przypięcie, publikację, powiązane i kolejność. Bezpieczne do ponownego
 * uruchomienia. Używa migracja '2026-11_it_portal_first_login_v2' i cli/it_portal_first_login_seed.php.
 */

/** @return array{status:string, article:string} */
function itPortalFirstLoginApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) return ['status' => 'brak usługi „Mój login” — pominięto', 'article' => ''];
    $old = db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = 'pierwsze-logowanie'", [(int)$cat['id']]);
    $title = 'Pierwsze logowanie krok po kroku';
    $rep = ['status' => $old ? 'nadpisany' : 'utworzony', 'article' => "moj-login/pierwsze-logowanie — {$title}"];
    if ($dryRun) return $rep;
    itPortalArticleSave([
        'title' => $title, 'slug' => 'pierwsze-logowanie', 'category_id' => (int)$cat['id'],
        'keywords' => 'pierwsze logowanie, logowanie jednokrotne, IAM, Logto, hasło, aktywacja konta, regulamin, zgody, RODO, kreator, chmura, nextcloud, nowy wolontariusz, zleceniobiorca, microsoft',
        'blocks' => json_encode(itPortalFirstLoginBlocks($app), JSON_UNESCAPED_UNICODE),
        'related' => $old ? (json_decode((string)$old['related'], true) ?: []) : [],
        'is_published' => $old ? (int)$old['is_published'] : 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : 1,
        'sort_order' => $old ? (int)$old['sort_order'] : 10,
    ], $old ? (int)$old['id'] : 0);
    return $rep;
}

/** Bloki artykułu. */
function itPortalFirstLoginBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $login = $app . '/auth/login.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];

    return [
        ['type' => 'text', 'html' => '<p>Przy pierwszym wejściu do SZO panel poprowadzi Cię przez kilka ekranów: hasło, regulamin, krótki kreator, oświadczenie o ochronie danych i konto w chmurze. Zajmuje to kilka minut, a każdy krok robisz tylko raz.</p>'],
        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Fundacja odchodzi od Microsoft 365. Do SZO logujesz się <strong>logowaniem jednokrotnym</strong> (jedno konto Fundacji, to samo co do chmury plików) albo <strong>adresem e-mail i hasłem</strong>. Przycisk Microsoft 365 jest tylko dla administratorów.</p>'],
        ['type' => 'wizard', 'title' => 'Pierwsze logowanie krok po kroku', 'intro' => '<p>Kolejność jest zawsze taka sama. Panel pokazuje tylko kroki, które Cię dotyczą.</p>', 'steps' => [
            $step('Otwórz stronę logowania', '<p>Wejdź na <a href="' . $login . '">stronę logowania SZO</a>.</p><ul>'
                . '<li>Masz już konto logowania jednokrotnego? Kliknij „Zaloguj przez logowanie jednokrotne”.</li>'
                . '<li>Nie masz? Wpisz adres e-mail i hasło do SZO, np. hasło startowe z wiadomości od nas.</li></ul>'),
            $step('Pierwszy raz i nie masz hasła?', '<p>Pod formularzem logowania wybierz swoją sytuację:</p><ul>'
                . '<li><strong>Nowy wolontariusz</strong>: kliknij „Aktywuj konto” albo „Kod aktywacyjny”. Potwierdzisz się danymi z umowy i kodem SMS, a potem ustawisz hasło.</li>'
                . '<li><strong>Nowy zleceniobiorca</strong>: kliknij „Oświadczenie” i wypełnij formularz.</li>'
                . '<li><strong>Nie pamiętasz hasła</strong>: kliknij „Nie pamiętasz hasła?”</li></ul>'),
            $step('Ustaw własne hasło', '<p>Jeśli logujesz się hasłem startowym, panel od razu poprosi o nowe hasło. Wymyśl własne i zapisz je.</p>'),
            $step('Zaakceptuj regulamin panelu', '<p>Na ekranie „Witaj w panelu” przeczytaj pouczenie i regulamin. Zaznacz zgodę na dokumentową formę umów i korzystanie z panelu, potem kliknij „Akceptuję — przejdź do panelu”. Bez tej zgody dalsze kroki są niedostępne.</p>'
                . '<p>Możesz też podać zapasowy e-mail i telefon do powiadomień.</p>'),
            $step('Przejdź kreator pierwszych kroków', '<p>Kreator ma do czterech kroków. Każdy możesz odłożyć przyciskiem „Później”.</p><ul>'
                . '<li><strong>Telefon</strong>: wpisz numer komórkowy i potwierdź go kodem z SMS-a. Na ten numer wysyłamy hasła i kody.</li>'
                . '<li><strong>Microsoft 365 i Outlook</strong>: jeśli zobaczysz ten krok, kliknij „Dalej”. Konta Microsoft nie musisz łączyć. Pocztę służbową otwierasz na <a href="https://dpoczta.pl">dpoczta.pl</a>.</li>'
                . '<li><strong>Profil w katalogu</strong>: zdjęcie, krótki opis i kontakt dla współpracowników.</li>'
                . '<li><strong>Stopka e-mail</strong>: stanowisko i telefon pod Twoimi wiadomościami (tylko gdy masz skrzynkę służbową).</li></ul>'),
            $step('Złóż oświadczenie o ochronie danych (RODO)', '<p>Przeczytaj treść, zaznacz potwierdzenie i podpisz elektronicznie. Zapisujemy datę i adres IP.</p>'),
            $step('Załóż konto logowania jednokrotnego i chmury', '<p>Na koniec panel zaproponuje konto logowania jednokrotnego (IAM) i konto w chmurze plików Fundacji. Ustawiasz jedno hasło do obu. Login jest taki sam jak w SZO.</p>'
                . '<p>Od tej chwili możesz logować się do SZO przyciskiem „Zaloguj przez logowanie jednokrotne”, a pliki trzymać w chmurze zamiast w OneDrive.</p>'),
        ]],
        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p><strong>Masz dwa hasła.</strong> Jedno do logowania jednokrotnego i chmury plików, drugie do poczty na dpoczta.pl. Hasło do poczty musi być inne. Od 1 grudnia hasło logowania jednokrotnego posłuży do wszystkich aplikacji Fundacji, poczta zostaje osobno. Oba hasła zmienisz na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>.</p>'],
        ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Administratorzy z adresem @feer.org.pl logują się wyłącznie przyciskiem „Microsoft 365 — administratorzy”. Pozostałe metody są dla nich zablokowane.</p>'],
        ['type' => 'text', 'html' => '<h2>Gdy coś nie działa</h2><ul>'
            . '<li>Terminy i powody zmian opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/migracja/migracja">Migracja z Microsoft 365</a>, a różnicę między kontami artykuł <a href="' . $app . '/uslugi-it/uslugi/migracja/iam-i-poczta">Czym jest IAM, a czym poczta</a>.</li>'
            . '<li>Instrukcje poczty są w usłudze <a href="' . $app . '/uslugi-it/uslugi/moja-poczta">Moja poczta</a>.</li>'
            . '<li>Nie przyszedł SMS, konto jest zablokowane albo kreator się zapętla? <a href="' . $hd . '">Zgłoś to w Helpdesku</a>.</li></ul>'],
    ];
}
