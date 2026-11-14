<?php
/**
 * modules/it_portal/lib/itPortalNoweHasloSeed.php — artykuł „Nowe hasło krok po kroku” w usłudze „Konto i hasła”
 * (polecenie 2026-11-14: „opisz Nowe hasło krok po kroku”). Napisy z modules/nowe_haslo/view/index.php: ścieżka zalogowana
 * (1. Wybierz hasło, 2. Nowe hasło, 3. Gotowe; bez kodu) i gość (1. Twoje dane: prywatny e-mail albo PESEL + adres @feer.org.pl,
 * 2. Kod i nowe hasło: „Które hasło zmieniasz?”, „Wyślij kod”, kod 6 cyfr ważny 10 minut, „Ustawię własne” / „Wyślij mi losowe SMS-em”).
 * Wymagania: NoweHaslo::MIN_LEN = 12, mała i wielka litera, cyfra, bez imienia/nazwiska/loginu. Ramka ITP_STAN_NA.
 * Używa migracja '2026-11_it_portal_nowe_haslo'.
 */

require_once __DIR__ . '/itPortalLogowanieSzoSeed.php';

function itPortalNoweHasloApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'konto-i-hasla'");
    if (!$cat) return 'brak usługi „Konto i hasła” — pominięto';
    $catId = (int)$cat['id'];
    $slug = 'nowe-haslo-krok-po-kroku';
    $title = 'Nowe hasło krok po kroku';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
    foreach (db_all("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id
                     WHERE (c.slug = 'konto-i-hasla' AND a.slug = 'zmiana-hasel') OR (c.slug = 'migracja' AND a.slug IN ('zmien-haslo-startowe','iam-i-hasla'))") as $r) {
        if (!in_array((int)$r['id'], $related, true)) $related[] = (int)$r['id'];
    }
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'nowe hasło, nowehaslo, zmiana hasła, zmień hasło, hasło startowe, hasło do poczty, hasło iam, hasło chmury, kod potwierdzenia, wyślij kod, losowe hasło, sms, nie pamiętam hasła, authenticate plain, prywatny e-mail, pesel',
        'blocks' => json_encode(itPortalNoweHasloBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1, 'sort_order' => 5,
        'related' => $related], $old ? (int)$old['id'] : 0);
    return "konto-i-hasla/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalNoweHasloBlocks(string $app): array {
    $nh = $app . '/nowehaslo';
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    $req = '<p>Pod polami odhaczają się wymagania: co najmniej 12 znaków, mała litera, wielka litera, cyfra i oba pola takie same. Hasło nie może zawierać imienia, nazwiska ani loginu. '
        . 'Hasło do poczty musi być inne niż hasło logowania jednokrotnego, strona to sprawdzi.</p>';
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>Na stronie <a href="' . $nh . '">Nowe hasło</a> zmieniasz oba swoje hasła w Fundacji:</p><ul>'
            . '<li>hasło logowania jednokrotnego i chmury plików, czyli hasło IAM,</li>'
            . '<li>hasło do poczty.</li></ul>'
            . '<p>Na stronę wejdziesz na dwa sposoby. Gdy jesteś zalogowany do SZO, zmiana zajmuje chwilę i nie potrzebujesz kodu. Gdy nie możesz się zalogować, potwierdzisz się danymi z umowy i kodem.</p>'],

        ['type' => 'wizard', 'title' => 'Gdy jesteś zalogowany do SZO', 'intro' => '<p>Na górze strony widać trzy etapy: „Wybierz hasło”, „Nowe hasło” i „Gotowe”.</p>', 'steps' => [
            $step('Otwórz stronę', '<p>Zaloguj się do SZO i wejdź na <a href="' . $nh . '">Nowe hasło</a>. W panelu współpracownika prowadzi do niej kafel „Zmiana haseł”. Jeśli masz hasło startowe, w panelu zobaczysz też pasek „Zmień hasło startowe”.</p>'),
            $step('Wybierz hasło', '<p>Pod pytaniem „Które hasło chcesz zmienić?” kliknij kafel z hasłem. Hasło, które jest jeszcze startowe, ma oznaczenie „hasło startowe”. Przy kaflu widzisz też swój login.</p>'),
            $step('Wpisz nowe hasło', '<p>Wpisz nowe hasło w polach „Nowe hasło” i „Powtórz nowe hasło”. Przyciskiem „Pokaż hasło” sprawdzisz, co wpisałeś.</p>' . $req
                . '<p>Kliknij „Zmień hasło”. Nie chcesz wymyślać? Kliknij „Wyślij mi losowe hasło SMS-em”.</p>'),
            $step('Gotowe', '<p>Dostaniesz SMS i e-mail z potwierdzeniem. Na ekranie „Gotowe” jest przycisk do zmiany drugiego hasła. Jeśli zmieniasz hasło startowe, od razu zmień też drugie.</p>'),
        ]],

        ['type' => 'wizard', 'title' => 'Gdy nie możesz się zalogować', 'intro' => '<p>Tu są dwa etapy: „Twoje dane” oraz „Kod i nowe hasło”.</p>', 'steps' => [
            $step('Podaj swoje dane', '<p>Wejdź na <a href="' . $nh . '">Nowe hasło</a>. W polu „Prywatny e-mail albo PESEL” wpisz prywatny adres e-mail podany w umowie z Fundacją, nie służbowy, albo swój PESEL. '
                . 'W drugim polu wpisz swój adres w domenie feer.org.pl, na przykład imie.nazwisko@feer.org.pl. Kliknij „Dalej”.</p>'),
            $step('Wybierz hasło i wyślij kod', '<p>Pod pytaniem „Które hasło zmieniasz?” wybierz hasło. Kliknij „Wyślij kod”.</p><ul>'
                . '<li>Kod do hasła IAM i chmury przychodzi e-mailem.</li>'
                . '<li>Kod do poczty przychodzi SMS-em na numer z SZO albo z umowy, a gdy go nie mamy, na Twój prywatny e-mail.</li></ul>'
                . '<p>Strona zawsze odpowiada „Jeśli dane się zgadzają, kod już do Ciebie idzie”. Dzięki temu nikt obcy nie sprawdzi, czy masz u nas konto.</p>'),
            $step('Wybierz rodzaj hasła', '<p>Pod pytaniem „Jakie ma być nowe hasło?” wybierz „Ustawię własne” albo „Wyślij mi losowe SMS-em”. Hasła losowego nie pokazujemy na ekranie i nigdy nie wysyłamy e-mailem. Jeśli nie mamy Twojego numeru, ta opcja jest niedostępna.</p>'),
            $step('Wpisz kod i nowe hasło', '<p>Wpisz nowe hasło dwa razy, jeśli ustawiasz własne.</p>' . $req
                . '<p>W polu „Kod potwierdzenia” albo „Kod z e-maila” wpisz 6 cyfr. Kod jest ważny 10 minut i działa przy jednej zmianie. Kliknij „Zmień hasło”.</p>'),
            $step('Hasło zmienione', '<p>Zobaczysz komunikat „Hasło zmienione”. Kliknij „OK, rozumiem”. Login, który pokaże strona, zapisz: to Twój login do SZO, logowania jednokrotnego i chmury plików.</p>'),
        ]],

        ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Kod nie przyszedł? Sprawdź folder ze spamem. Nowy kod zamówisz przyciskiem „Wyślij nowy kod”, najwcześniej po minucie. '
            . 'Pomyliłeś wybór? Kliknij „Zmień wybór” albo „Zacznij od nowa”.</p>'],

        ['type' => 'text', 'html' => '<h2>Po zmianie hasła</h2><ul>'
            . '<li>Masz otwartą pocztę na dpoczta.pl? Wyloguj się i zaloguj nowym hasłem. Inaczej zobaczysz błąd „AUTHENTICATE PLAIN”.</li>'
            . '<li>Jeśli telefon, program pocztowy albo aplikacja chmury pamięta stare hasło, wpisz w nich nowe.</li>'
            . '<li>Hasło IAM działa od razu w SZO i w chmurze plików. Hasło do poczty działa tylko w poczcie.</li></ul>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Hasła zmieniaj tylko na stronie Nowe hasło, nie w ustawieniach dpoczta.pl ani w chmurze. Hasło zmienione gdzie indziej się nie zsynchronizuje. '
            . 'To nowy system, więc mogą zdarzać się błędy. Gdy coś nie działa, <a href="' . $hd . '">napisz do Helpdesku IT</a>. Nikt z Fundacji nie poprosi Cię o hasło ani kod.</p>'],
    ];
}
