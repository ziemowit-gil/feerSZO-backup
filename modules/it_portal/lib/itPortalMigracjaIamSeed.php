<?php
/**
 * modules/it_portal/lib/itPortalMigracjaIamSeed.php — artykuł „IAM i hasła w migracji: co się dzieje krok po kroku” w usłudze „Migracja”
 * (polecenie 2026-11-14: „w migracji wyjaśnij IAM i proces zmiany haseł”). Spina istniejące teksty: migracja/zmien-haslo-startowe
 * (hasła startowe SMS-em, 7 dni), migracja/iam-i-poczta, konto-i-hasla/zmiana-hasel (/nowehaslo, kody), moj-login/iam.
 * Terminy jak w itPortalMigracjaSeed.php: 14.11 poczta, 20.11 wyłączenie Microsoft 365, 1.12 jedno hasło IAM do wszystkich aplikacji.
 * Sekcja „Jak się nie pogubić w hasłach i loginach” (polecenie 2026-11-14).
 * Blok „legitymacja i klucz” z itPortalIamSeed.php, ramka ITP_STAN_NA. Używa migracja '2026-11_it_portal_migracja_iam'.
 */

require_once __DIR__ . '/itPortalIamSeed.php';

function itPortalMigracjaIamApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'migracja'");
    if (!$cat) return 'brak usługi „Migracja” — pominięto';
    $catId = (int)$cat['id'];
    $slug = 'iam-i-hasla';
    $title = 'IAM i hasła w migracji: co się dzieje krok po kroku';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
    foreach (db_all("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id
                     WHERE (c.slug = 'migracja' AND a.slug IN ('zmien-haslo-startowe','iam-i-poczta'))
                        OR (c.slug = 'konto-i-hasla' AND a.slug = 'zmiana-hasel') OR (c.slug = 'moj-login' AND a.slug = 'iam')") as $r) {
        if (!in_array((int)$r['id'], $related, true)) $related[] = (int)$r['id'];
    }
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'jak się nie pogubić, ściągawka, login, loginy, menedżer haseł, migracja, iam, logowanie jednokrotne, hasła, zmiana haseł, hasło startowe, nowe hasło, dwa hasła, hasło do poczty, hasło iam, hasło chmury, 7 dni, 1 grudnia, 20 listopada, microsoft 365, sms z hasłem',
        'blocks' => json_encode(itPortalMigracjaIamBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1, 'sort_order' => 2,
        'related' => $related], $old ? (int)$old['id'] : 0);
    return "migracja/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalMigracjaIamBlocks(string $app): array {
    $nh = $app . '/nowehaslo';
    $sso = $app . '/tozsamosc/index.php#sso';
    $hd = $app . '/helpdesk/new.php';
    $m = $app . '/uslugi-it/uslugi/migracja/';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>Do tej pory jedno konto Microsoft 365 otwierało pocztę, pliki i spotkania. Po migracji zamiast niego masz dwa konta, każde z własnym hasłem:</p><ul>'
            . '<li>konto IAM, czyli logowanie jednokrotne, do SZO, chmury plików i kolejnych aplikacji Fundacji,</li>'
            . '<li>skrzynkę pocztową, do poczty na dpoczta.pl, w telefonie i w programie pocztowym.</li></ul>'
            . '<p>Hasła do tych kont muszą być różne. Gdy ktoś pozna jedno, nie wejdzie na drugie konto.</p>'],

        itPortalIamTozsamoscBlock($app),

        ['type' => 'wizard', 'title' => 'Co dzieje się z Twoimi hasłami', 'intro' => '<p>Tak wygląda cała droga, od przeniesienia poczty do jednego hasła IAM.</p>', 'steps' => [
            $step('Przenieś pocztę', '<p>Od 14 listopada 2026 r. poczta działa u nowego dostawcy. Adres się nie zmienia, nadal jest to imie.nazwisko@feer.org.pl.</p>'
                . '<p>Wejdź na stronę <a href="' . $app . '/migracjapoczty">Migracja poczty</a>. Jeśli poczta jest już przeniesiona, zobaczysz to od razu, a hasło do nowej skrzynki masz w SMS-ie. '
                . 'Jeśli nie, założysz skrzynkę sam: hasło przyjdzie SMS-em, a stare wiadomości skopiujemy w tle.</p>'),
            $step('Dostajesz hasła startowe SMS-em', '<p>Po przeniesieniu poczty przychodzi SMS z hasłami startowymi: jednym do poczty i drugim do IAM i chmury plików. To hasła tymczasowe. '
                . 'Jeśli konto IAM zakładasz sam, hasła startowego do IAM nie dostajesz, tylko od razu wybierasz własne.</p>'),
            $step('Zmieniasz oba hasła na własne', '<p>Wejdź na stronę <a href="' . $nh . '">Nowe hasło</a> i zmień oba hasła, jedno po drugim. Masz na to 7 dni. Potem SZO samo otworzy stronę zmiany hasła i nie puści dalej, dopóki go nie zmienisz.</p>'),
            $step('Wpisujesz nowe hasła w urządzeniach', '<p>W telefonie i w programie pocztowym wpisz nowe hasło do poczty. W aplikacji chmury plików wpisz nowe hasło IAM.</p>'),
            $step('20 listopada wyłączamy Microsoft 365', '<p>Od tego dnia nie zalogujesz się już kontem Microsoft. Do SZO wchodzisz przyciskiem „Zaloguj przez logowanie jednokrotne” albo e-mailem i hasłem.</p>'),
            $step('Od 1 grudnia jedno hasło IAM', '<p>Hasłem IAM zalogujesz się do wszystkich aplikacji Fundacji. Poczta zostaje osobno, z własnym hasłem.</p>'),
        ]],

        ['type' => 'text', 'html' => '<h2>Gdzie zmieniasz które hasło</h2><ul>'
            . '<li>Hasło do poczty zmieniasz tylko na stronie <a href="' . $nh . '">Nowe hasło</a>. Kod potwierdzenia przychodzi SMS-em, a gdy nie mamy Twojego numeru, na prywatny e-mail.</li>'
            . '<li>Hasło IAM i chmury zmieniasz na stronie Nowe hasło albo w Systemie Tożsamości, w sekcji <a href="' . $sso . '">Logowanie jednokrotne</a>. Kod przychodzi e-mailem.</li>'
            . '<li>Gdy jesteś zalogowany do SZO, kod nie jest potrzebny. Nowe hasło ustawiasz od razu.</li></ul>'
            . '<p>Wymagania co do hasła pokaże strona, na której je zmieniasz. Możesz też poprosić o hasło losowe, które przyjdzie SMS-em.</p>'],

        ['type' => 'text', 'html' => '<h2>Jak się nie pogubić w hasłach i loginach</h2>'
            . '<p>Ściągawka. Loginem prawie wszędzie jest Twój adres e-mail, zmieniają się tylko hasła.</p><ul>'
            . '<li>Poczta: login to pełny adres imie.nazwisko@feer.org.pl, hasło to hasło do poczty. Wpisujesz je na dpoczta.pl, w telefonie i w programie pocztowym.</li>'
            . '<li>SZO, chmura plików i inne aplikacje Fundacji: login to adres e-mail zapisany w SZO albo zapasowy login imie.nazwisko, hasło to hasło IAM. Do SZO wchodzisz przyciskiem „Zaloguj przez logowanie jednokrotne”.</li>'
            . '<li>Zleceniobiorcy bez poczty służbowej logują się do SZO prywatnym adresem e-mail z umowy.</li></ul>'
            . '<p>Kilka prostych zasad:</p><ul>'
            . '<li>Zapamiętaj regułę: poczta ma swoje hasło, a wszystko inne hasło IAM.</li>'
            . '<li>Zapisuj hasła w menedżerze haseł, na przykład w tym wbudowanym w przeglądarkę albo telefon. Nazwij wpisy jasno, na przykład „Poczta Fundacji” i „IAM Fundacji”.</li>'
            . '<li>Nie zapisuj haseł na karteczkach przy komputerze ani w zwykłych notatkach w telefonie.</li>'
            . '<li>Nie używaj tych haseł nigdzie poza Fundacją i nie ustawiaj jednego hasła do poczty i do IAM. Strona Nowe hasło i tak na to nie pozwoli.</li>'
            . '<li>Nie wiesz, jaki masz login IAM? Sprawdź go w Systemie Tożsamości, w sekcji <a href="' . $sso . '">Logowanie jednokrotne</a>.</li>'
            . '<li>Pomyliłeś hasła i nic nie działa? Nie zgaduj w kółko, bo po kilku próbach konto się blokuje. Ustaw nowe hasło na stronie <a href="' . $nh . '">Nowe hasło</a>.</li></ul>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie zmieniaj haseł w ustawieniach dpoczta.pl ani w samej chmurze. Takie hasło nie zsynchronizuje się z SZO i usługi przestaną działać. '
            . 'Nikt z Fundacji nie poprosi Cię o hasło ani kod. Hasła wysyłamy tylko SMS-em, nigdy e-mailem.</p>'],

        ['type' => 'text', 'html' => '<h2>Więcej szczegółów</h2><ul>'
            . '<li><a href="' . $m . 'zmien-haslo-startowe">Po migracji: zmień hasła startowe</a>, z instrukcją krok po kroku dla strony Nowe hasło.</li>'
            . '<li><a href="' . $m . 'iam-i-poczta">Czym jest IAM, a czym poczta</a>.</li>'
            . '<li><a href="' . $app . '/uslugi-it/uslugi/moj-login/iam">IAM: czym jest i jakie są moje dane logowania</a>.</li></ul>'
            . '<p>Nie przyszedł SMS albo zmiana się nie udaje? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
    ];
}
