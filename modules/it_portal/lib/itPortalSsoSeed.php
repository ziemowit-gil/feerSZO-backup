<?php
/**
 * modules/it_portal/lib/itPortalSsoSeed.php — artykuł „Logowanie jednokrotne (SSO): jak się zalogować” w usłudze „Mój login”
 * (/uslugi-it/uslugi/moj-login/logowanie-jednokrotne). Polecenie 2026-11-15: „jak ktoś wejdzie w logto.feer.org.pl, przekieruj go
 * na stronę, jak ma się logować i że to SSO CAS”. Na ten adres wskazuje „Unknown session redirect URL” w Logto
 * (Sign-in & account → Sign-up and sign-in → Advanced options), czyli to, co zobaczy ktoś, kto otworzy logto.feer.org.pl bez aplikacji.
 * Nazwa „CAS” (centralna usługa uwierzytelniania) jest tu określeniem funkcji; sam serwer to Logto (OpenID Connect).
 * Nadpisuje treść; zostawia id, publikację i przypięcie. Używa migracja '2026-11_it_portal_sso_article' i cli/it_portal_sso_seed.php.
 */

/** @return array{status:string, article:string} */
function itPortalSsoApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) return ['status' => 'brak usługi „Mój login” — pominięto', 'article' => ''];
    $old = db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = 'logowanie-jednokrotne'", [(int)$cat['id']]);
    $title = 'Logowanie jednokrotne (SSO): jak się zalogować';
    $rep = ['status' => $old ? 'nadpisany' : 'utworzony', 'article' => "moj-login/logowanie-jednokrotne: {$title}"];
    if ($dryRun) return $rep;
    itPortalArticleSave([
        'title' => $title, 'slug' => 'logowanie-jednokrotne', 'category_id' => (int)$cat['id'],
        'keywords' => 'sso, cas, logowanie jednokrotne, centralna usługa uwierzytelniania, logto, iam, logto.feer.org.pl, jak się zalogować, hasło, login z kreską dolną',
        'blocks' => json_encode(itPortalSsoBlocks($app), JSON_UNESCAPED_UNICODE),
        'related' => $old ? (json_decode((string)$old['related'], true) ?: []) : [],
        'is_published' => $old ? (int)$old['is_published'] : 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : 0,
        'sort_order' => $old ? (int)$old['sort_order'] : 15,
    ], $old ? (int)$old['id'] : 0);
    return $rep;
}

/** Bloki artykułu. */
function itPortalSsoBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Na adres logto.feer.org.pl nie loguje się wprost. Wejdź do aplikacji, której potrzebujesz, na przykład do SZO albo do chmury plików. Aplikacja sama przeniesie Cię na ekran logowania.</p>'],
        ['type' => 'text', 'html' => '<p>Logto to centralna usługa uwierzytelniania Fundacji (CAS), czyli logowanie jednokrotne (SSO). Jedno konto i jedno hasło wystarczają do kilku aplikacji Fundacji. Trafiłeś tu, bo otworzyłeś adres tej usługi bez aplikacji. To się zdarza i nic się nie zepsuło.</p>'],
        ['type' => 'wizard', 'title' => 'Jak się zalogować', 'intro' => '', 'steps' => [
            $step('Otwórz aplikację Fundacji', '<p>Wejdź na stronę aplikacji, do której chcesz się dostać: <a href="' . $app . '/auth/login.php">SZO</a>, chmura plików, CRM albo Wirtualne biurko.</p>'),
            $step('Kliknij logowanie jednokrotne', '<p>Na ekranie logowania wybierz „Zaloguj przez logowanie jednokrotne”. Przeglądarka pokaże ekran usługi Logto.</p>'),
            $step('Wpisz login i hasło', '<p>Wpisz swój adres e-mail albo login z kreską dolną zamiast kropki, na przykład jan_kowalski. Potem wpisz hasło logowania jednokrotnego.</p>'),
            $step('Wróć do aplikacji', '<p>Po zalogowaniu usługa odeśle Cię z powrotem do aplikacji. Kolejne aplikacje Fundacji otworzą się bez ponownego wpisywania hasła, dopóki sesja trwa.</p>'),
        ]],
        ['type' => 'text', 'html' => '<h2>Hasło i konto</h2><ul>'
            . '<li>Konta nie założysz na ekranie logowania. Pierwsze konto powstaje przy pierwszym wejściu do SZO, opisuje to artykuł <a href="' . $app . '/uslugi-it/uslugi/moj-login/pierwsze-logowanie">Pierwsze logowanie krok po kroku</a>.</li>'
            . '<li>Hasło logowania jednokrotnego zmienisz na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. Poczta na dpoczta.pl ma osobne hasło.</li>'
            . '<li>Nikt z Fundacji nie poprosi Cię o hasło.</li></ul>'],
        ['type' => 'text', 'html' => '<p>Nie możesz się zalogować albo ekran logowania zgłasza błąd? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
    ];
}
