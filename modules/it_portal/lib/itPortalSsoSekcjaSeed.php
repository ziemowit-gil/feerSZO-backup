<?php
/**
 * modules/it_portal/lib/itPortalSsoSekcjaSeed.php — artykuł „Logowanie jednokrotne w Systemie Tożsamości” w usłudze „Mój login”
 * (polecenie 2026-11-14: „opisz Logowanie jednokrotne w Tożsamości”). Źródło: modules/sso_logto/view/userSection.php (karty
 * „Co to jest?”, „Twoje hasła w organizacji”, „Twoje konto”: stany nieuruchomione / brak konta + „Utwórz konto(a)” albo
 * „napisz do helpdesku” / konto: Login, Hasło ustawione, Ostatnie logowanie, Chmura plików, „Zmień hasło” albo
 * „Załóż konto w chmurze”, ostrzeżenie po zmianie hasła na stronie logowania). Ramka ITP_STAN_NA. Migracja '2026-11_it_portal_sso_sekcja'.
 */

require_once __DIR__ . '/itPortalLogowanieSzoSeed.php';

function itPortalSsoSekcjaApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) return 'brak usługi „Mój login” — pominięto';
    $catId = (int)$cat['id'];
    $slug = 'logowanie-jednokrotne';
    $title = 'Logowanie jednokrotne w Systemie Tożsamości: konto IAM i hasło';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
    foreach (db_all("SELECT id FROM itp_articles WHERE category_id = ? AND slug IN ('iam','system-tozsamosci')", [$catId]) as $r) {
        if (!in_array((int)$r['id'], $related, true)) $related[] = (int)$r['id'];
    }
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'logowanie jednokrotne, sso, iam, logto, system tożsamości, utwórz konto, utwórz konta, zmień hasło, hasło iam, hasło chmury, załóż konto w chmurze, hasło ustawione, ostatnie logowanie, login iam, chmura plików, jedno hasło',
        'blocks' => json_encode(itPortalSsoSekcjaBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1, 'sort_order' => 4,
        'related' => $related], $old ? (int)$old['id'] : 0);
    return "moj-login/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalSsoSekcjaBlocks(string $app): array {
    $sso = $app . '/tozsamosc/index.php#sso';
    $nh = $app . '/nowehaslo';
    $hd = $app . '/helpdesk/new.php';
    $b = $app . '/uslugi-it/uslugi/moj-login/';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>Sekcja Logowanie jednokrotne w Systemie Tożsamości to miejsce, w którym zakładasz konto IAM, sprawdzasz swój login i zmieniasz hasło. '
            . 'Otworzysz ją od razu odnośnikiem <a href="' . $sso . '">Logowanie jednokrotne</a> albo z menu po lewej stronie Systemu Tożsamości.</p>'
            . '<p>Sekcja ma trzy części: „Co to jest?”, „Twoje hasła w organizacji” i „Twoje konto”.</p>'],

        ['type' => 'text', 'html' => '<h2>Twoje hasła w organizacji</h2>'
            . '<p>Ta część przypomina, że masz kilka haseł i każde zmieniasz gdzie indziej:</p><ul>'
            . '<li>Logowanie jednokrotne i chmura plików mają jedno hasło. Zmieniasz je w tej sekcji i od razu działa w obu miejscach.</li>'
            . '<li>Poczta ma osobne hasło. Zmiana hasła tutaj go nie rusza.</li>'
            . '<li>Hasło do SZO przy logowaniu e-mailem i hasłem jest osobne. Zmieniasz je w sekcji Zabezpieczenia.</li></ul>'],

        ['type' => 'text', 'html' => '<h2>Twoje konto: co możesz zobaczyć</h2>'
            . '<p>Część „Twoje konto” wygląda różnie, zależnie od tego, czy masz już konto IAM.</p><ul>'
            . '<li>Nie masz konta: zobaczysz formularz do założenia konta. Login to Twój adres e-mail z SZO, a hasło wymyślasz sam.</li>'
            . '<li>Masz konto: zobaczysz swój login, datę ustawienia hasła, datę ostatniego logowania i informację o koncie w chmurze plików, z odnośnikiem „Otwórz chmurę”.</li>'
            . '<li>Jeśli zamiast formularza jest prośba o kontakt z Helpdeskiem, konto zakłada administrator. Wtedy <a href="' . $hd . '">napisz do Helpdesku IT</a>.</li></ul>'
            . '<p>Login może mieć dwie formy, na przykład imie.nazwisko albo pełny adres e-mail. Obie działają.</p>'],

        ['type' => 'wizard', 'title' => 'Załóż konto IAM', 'intro' => '', 'steps' => [
            $step('Otwórz sekcję', '<p>Zaloguj się do SZO i otwórz <a href="' . $sso . '">Logowanie jednokrotne w Systemie Tożsamości</a>.</p>'),
            $step('Wymyśl hasło', '<p>W polu „Hasło” wpisz nowe hasło, co najmniej 10 znaków. Najłatwiej zapamiętać kilka słów połączonych w jedno. Nie używaj hasła do SZO ani do poczty. Wpisz je jeszcze raz w polu „Powtórz hasło”.</p>'),
            $step('Utwórz konto', '<p>Kliknij „Utwórz konto” albo „Utwórz konta”. Ten drugi napis oznacza, że razem z kontem IAM powstanie konto w chmurze plików z tym samym hasłem.</p>'),
            $step('Zaloguj się', '<p>Od teraz na stronie logowania SZO możesz kliknąć „Zaloguj przez logowanie jednokrotne”. Tym samym loginem i hasłem zalogujesz się do chmury plików.</p>'),
        ]],

        ['type' => 'wizard', 'title' => 'Zmień hasło IAM', 'intro' => '', 'steps' => [
            $step('Wpisz nowe hasło', '<p>W części „Twoje konto”, pod nagłówkiem „Zmień hasło”, wpisz nowe hasło, co najmniej 10 znaków, i powtórz je.</p>'),
            $step('Zapisz', '<p>Kliknij „Zmień hasło”. Nowe hasło działa od razu w IAM i w chmurze plików. Hasło do poczty zostaje bez zmian.</p>'),
        ]],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie masz jeszcze konta w chmurze? Zamiast „Zmień hasło” zobaczysz „Załóż konto w chmurze”. Ustaw nowe hasło, a konto w chmurze powstanie od razu i hasło będzie działać w obu miejscach.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Jeśli zmienisz hasło na stronie logowania IAM, a nie w Systemie Tożsamości, w chmurze plików może dalej działać stare hasło. '
            . 'Sekcja pokaże wtedy ostrzeżenie. Ustaw hasło jeszcze raz w Systemie Tożsamości, a znów będzie jedno w obu miejscach. Nie pamiętasz hasła i nie możesz się zalogować? Skorzystaj ze strony <a href="' . $nh . '">Nowe hasło</a>.</p>'],

        ['type' => 'text', 'html' => '<p>Czym jest IAM i jak łączy się z Systemem Tożsamości, wyjaśnia artykuł <a href="' . $b . 'iam">IAM: czym jest i jakie są moje dane logowania</a>.</p>'],
    ];
}
