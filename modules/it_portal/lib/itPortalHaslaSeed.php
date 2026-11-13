<?php
/**
 * modules/it_portal/lib/itPortalHaslaSeed.php — usługa „Konto i hasła” w Portalu Usług IT (/uslugi-it/uslugi/konto-i-hasla) z artykułem
 * „zmiana-hasel” o samodzielnej zmianie haseł na /nowehaslo (polecenie 2026-11-13: kafel „Zmiana haseł” w panelu współpracownika
 * i artykuł o tym na /uslugi-it, „pamiętaj, że 2 różne hasła”). Tekst prostym językiem (skill humanizer: bez myślników i ozdobników).
 *
 * Źródła faktów: modules/nowe_haslo (kody: poczta SMS-em albo na prywatny e-mail, logowanie jednokrotne + chmura e-mailem; hasło
 * min. 12 znaków albo losowe SMS-em; hasło do poczty ≠ hasło logowania jednokrotnego; wejście bez logowania: prywatny e-mail albo
 * PESEL + adres służbowy) — te same, co artykuł migracja/migracja (itPortalMigracjaSeed.php); reset przez administratora:
 * SsoAccounts::resetPassword (karta umowy, /ssoadmin#hasla) i karta „Skrzynka pocztowa”.
 * Bez tabel (polecenie 2026-11-13 „bez tabeli”): każde hasło opisuje osobny akapit.
 * Używa migracje '2026-11_it_portal_hasla', '2026-11_it_portal_hasla_bez_tabeli' (modules/selfrepairDB/logic/migrations.php) i cli/it_portal_hasla_seed.php.
 */

/**
 * Zasiew: usługa „Konto i hasła” (nowa albo nadpisany wstęp) i artykuł „zmiana-hasel” (nowy albo nadpisany). Można uruchamiać wielokrotnie.
 * @return array{category:string, created:list<string>, updated:list<string>}
 */
function itPortalHaslaApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'created' => [], 'updated' => []];
    $intro = itPortalSanitizeHtml(itPortalHaslaIntro($app));

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'konto-i-hasla'");
    if ($cat) {
        $catId = (int)$cat['id'];
        $rep['category'] = 'wstęp nadpisany';
        if (!$dryRun) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?",
                              [$intro, itPortalPlain($intro), $catId]);
    } else {
        $rep['category'] = 'usługa utworzona';
        $catId = 0;
        if (!$dryRun) {
            $aud = array_column(itPortalAudiences(), 'id', 'name');
            $catId = itPortalCategorySave(['name' => 'Konto i hasła', 'slug' => 'konto-i-hasla', 'icon' => 'key', 'sort_order' => 4, 'is_active' => 1,
                'audiences' => array_values(array_filter([(int)($aud['Pracownik'] ?? 0), (int)($aud['Wolontariusz'] ?? 0), (int)($aud['IT'] ?? 0)])),
                'intro_html' => $intro]);
        }
    }

    $i = 0;
    foreach (itPortalHaslaArticles($app) as $slug => [$title, $keywords, $blocks, $pinned]) {
        $old = $catId ? db_one("SELECT id FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
        $rep[$old ? 'updated' : 'created'][] = "konto-i-hasla/{$slug} — {$title}";
        if ($dryRun) continue;
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10], $old ? (int)$old['id'] : 0);
    }
    return $rep;
}

/** Wstęp strony usługi „Konto i hasła”. */
function itPortalHaslaIntro(string $app): string {
    return '<p>Masz w Fundacji dwa osobne hasła: do poczty oraz do logowania jednokrotnego i chmury plików. '
         . 'Każde zmienisz sam na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. W panelu współpracownika prowadzi do niej kafel „Zmiana haseł”.</p>'
         . '<p>Jak to zrobić krok po kroku, opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/konto-i-hasla/zmiana-hasel">Zmiana haseł</a>.</p>';
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty na Start].
 * @return array<string, array{0:string,1:string,2:array,3:bool}>
 */
function itPortalHaslaArticles(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $nh = $app . '/nowehaslo';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'zmiana-hasel' => ['Zmiana haseł: poczta oraz logowanie jednokrotne i chmura',
            'hasło, hasła, zmiana hasła, nowe hasło, nowehaslo, zapomniałem hasła, nie pamiętam hasła, reset hasła, poczta, iam, logowanie jednokrotne, chmura, nextcloud, kod sms, panel współpracownika, kafel', [
            ['type' => 'text', 'html' => '<p>W Fundacji masz dwa osobne hasła. Pierwsze otwiera pocztę. Drugie jest wspólne dla logowania jednokrotnego '
                . 'i chmury plików. Muszą być różne, więc gdy ktoś pozna jedno, nie wejdzie na drugie konto.</p>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p><strong>Ważne: oba hasła zmieniaj wyłącznie w SZO, na stronie <a href="' . $nh . '">Nowe hasło</a>.</strong> '
                . 'Nie zmieniaj hasła w ustawieniach dpoczta.pl ani w chmurze. Hasło zmienione poza SZO nie zsynchronizuje się z pozostałymi systemami '
                . 'i usługi przestaną działać.</p>'],

            ['type' => 'text', 'html' => '<h2>Które hasło do czego</h2>'
                . '<p>Hasło do poczty otwiera skrzynkę <code>imie.nazwisko@feer.org.pl</code> w przeglądarce, w telefonie i w programie pocztowym. '
                . 'Kod do jego zmiany przychodzi SMS-em na numer zapisany w SZO albo w umowie, a gdy go nie mamy, e-mailem na Twój prywatny adres.</p>'
                . '<p>Hasło logowania jednokrotnego otwiera SZO (przycisk „Zaloguj przez logowanie jednokrotne”), chmurę plików i kolejne aplikacje Fundacji. '
                . 'Kod do jego zmiany przychodzi e-mailem.</p>'],

            ['type' => 'wizard', 'title' => 'Jak zmienić hasło', 'intro' => '<p>Zmiana zajmuje kilka minut. Przygotuj telefon albo skrzynkę, na którą przyjdzie kod.</p>', 'steps' => [
                $step('Otwórz stronę Nowe hasło', '<p>Wejdź na <a href="' . $nh . '">stronę Nowe hasło</a>. W panelu współpracownika kliknij kafel „Zmiana haseł”.</p>'
                    . '<p>Wejdziesz tam też bez logowania do SZO. Podaj wtedy prywatny e-mail albo PESEL z umowy oraz adres służbowy.</p>'),
                $step('Wybierz hasło', '<p>Zaznacz, które hasło zmieniasz: do poczty albo do logowania jednokrotnego i chmury.</p>'),
                $step('Wpisz kod', '<p>Przepisz kod z SMS-a albo z e-maila. Kod jest jednorazowy.</p>'),
                $step('Ustaw nowe hasło', '<p>Wpisz własne hasło: co najmniej 12 znaków, małe i wielkie litery oraz cyfry. '
                    . 'Możesz też wybrać hasło losowe, które przyjdzie SMS-em.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Po zmianie</h2>'
                . '<p>Dostaniesz SMS i e-mail z informacją, że hasło się zmieniło. Samo hasło wysyłamy tylko SMS-em, nigdy e-mailem.</p>'
                . '<p>Hasło do chmury zmienia się razem z hasłem logowania jednokrotnego. Jeśli telefon albo program pamięta stare hasło, '
                . 'na przykład poczta w telefonie albo aplikacja chmury, wpisz w nim nowe.</p>'],

            ['type' => 'text', 'html' => '<h2>Nie pamiętasz hasła albo kod nie przychodzi</h2>'
                . '<p>Napisz do <a href="' . $hd . '">Helpdesku IT</a>. Administrator nada nowe hasło i wyśle je SMS-em. '
                . 'Potem zmień je na własne na stronie Nowe hasło.</p>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nikt z Fundacji nie poprosi Cię o hasło ani o kod. '
                . 'Strona nie przyjmie hasła do poczty takiego samego jak hasło logowania jednokrotnego, i odwrotnie.</p>'],
        ], false],
    ];
}
