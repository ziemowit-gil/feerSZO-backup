<?php
/**
 * modules/it_portal/lib/itPortalIamSeed.php — artykuł „IAM: czym jest i jakie są moje dane logowania” w usłudze „Mój login”
 * (polecenie 2026-11-14: „o IAM opisz i jakie są moje dane do IAM”). Źródła: modules/sso_logto/view/userSection.php (sekcja #sso
 * w Tożsamości: login = adres e-mail z SZO, hasło min. 10 znaków wybierane samodzielnie, SZO go nie zapisuje; poczta osobno),
 * loginPrompt.php (/sso/start po zalogowaniu, login e-mail albo zapasowy login), modules/nowe_haslo (/nowehaslo, hasła startowe
 * z SMS-a, po 7 dniach wymuszona zmiana), artykuł migracja/iam-i-poczta. Ramka ITP_STAN_NA z itPortalLogowanieSzoSeed.php.
 * Używa migracja '2026-11_it_portal_iam'.
 */

require_once __DIR__ . '/itPortalLogowanieSzoSeed.php';

/** Prosty opis powiązania IAM z Systemem Tożsamości (polecenie 2026-11-14: „powiązanie między IAM a Tożsamością”, „wyjaśnij prosto”). Też w itPortalTozsamoscSeed.php. */
function itPortalIamTozsamoscBlock(string $app): array {
    return ['type' => 'text', 'html' => '<h2>IAM i System Tożsamości: jak to się łączy</h2>'
        . '<p>Pomyśl o tym jak o legitymacji i kluczu.</p><ul>'
        . '<li>System Tożsamości to Twoja legitymacja w Fundacji. Zapisane są w niej Twoje dane, numer UID i to, do czego masz dostęp.</li>'
        . '<li>IAM to klucz. Otwierasz nim aplikacje: SZO, chmurę plików i kolejne.</li>'
        . '<li>Klucz jest przypisany do legitymacji przez Twój adres e-mail. Dlatego login do IAM to ten sam e-mail, który masz w SZO.</li>'
        . '<li>Klucz zakładasz i zmieniasz w Systemie Tożsamości, w sekcji <a href="' . $app . '/tozsamosc/index.php#sso">Logowanie jednokrotne</a>.</li></ul>'
        . '<p>Krótko: w Systemie Tożsamości zarządzasz kontem, a kontem IAM się logujesz.</p>'];
}

function itPortalIamApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) return 'brak usługi „Mój login” — pominięto';
    $catId = (int)$cat['id'];
    $slug = 'iam';
    $title = 'IAM: czym jest i jakie są moje dane logowania';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
    foreach (db_all("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id
                     WHERE (c.slug = 'moj-login' AND a.slug IN ('logowanie-do-szo','cpl')) OR (c.slug = 'migracja' AND a.slug = 'iam-i-poczta')
                        OR (c.slug = 'konto-i-hasla' AND a.slug = 'zmiana-hasel')") as $r) {
        if (!in_array((int)$r['id'], $related, true)) $related[] = (int)$r['id'];
    }
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'iam, logowanie jednokrotne, logto, system tożsamości, konto iam, login iam, hasło iam, moje dane logowania, jaki mam login, jakie mam hasło, dane do iam, hasło startowe, nowe hasło, chmura plików, jedno hasło, logto.feer.org.pl',
        'blocks' => json_encode(itPortalIamBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1, 'sort_order' => 2,
        'related' => $related], $old ? (int)$old['id'] : 0);
    return "moj-login/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalIamBlocks(string $app): array {
    $tz = $app . '/tozsamosc/index.php#sso';
    $nh = $app . '/nowehaslo';
    $login = $app . '/auth/login.php';
    $hd = $app . '/helpdesk/new.php';
    $mig = $app . '/uslugi-it/uslugi/migracja/iam-i-poczta';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>IAM to system tożsamości Fundacji, czyli Twoje jedno konto do aplikacji Fundacji. Na ekranach nazywamy go też logowaniem jednokrotnym. '
            . 'Działa jak jeden klucz do wielu drzwi: logujesz się raz, a kolejne aplikacje nie pytają już o hasło, dopóki nie wylogujesz się w przeglądarce.</p>'
            . '<p>Kontem IAM logujesz się dziś do SZO i do chmury plików. Od 1 grudnia 2026 r. posłuży do wszystkich aplikacji Fundacji. Poczta zostaje osobno, z własnym hasłem.</p>'],

        itPortalIamTozsamoscBlock($app),

        ['type' => 'text', 'html' => '<h2>Jakie są moje dane do IAM</h2><ul>'
            . '<li>Login to Twój adres e-mail zapisany w SZO. Zwykle jest to adres służbowy imie.nazwisko@feer.org.pl. Jeśli nie masz poczty służbowej, na przykład jako zleceniobiorca, loginem jest prywatny adres e-mail z umowy.</li>'
            . '<li>Przy zakładaniu konta ekran może podać też zapasowy login, na przykład imie.nazwisko. Zapisz go, przyda się przy logowaniu do chmury plików.</li>'
            . '<li>Hasło wybierasz sam przy zakładaniu konta. Ma co najmniej 10 znaków. Fundacja go nie zna i nie przechowuje.</li>'
            . '<li>Jeśli konto założyliśmy za Ciebie, dostałeś SMS-em hasło startowe. Zmień je w ciągu 7 dni, potem SZO samo poprosi o zmianę.</li></ul>'
            . '<p>To samo hasło działa w IAM i w chmurze plików. Hasło do poczty jest inne i musi takie zostać.</p>'],

        ['type' => 'wizard', 'title' => 'Jak sprawdzić swoje dane', 'intro' => '', 'steps' => [
            $step('Zaloguj się do SZO', '<p>Wejdź na <a href="' . $login . '">stronę logowania</a> i zaloguj się przyciskiem „Zaloguj przez logowanie jednokrotne” albo e-mailem i hasłem.</p>'),
            $step('Otwórz System Tożsamości', '<p>Otwórz sekcję <a href="' . $tz . '">Logowanie jednokrotne w Systemie Tożsamości</a>. Zobaczysz tam swój login, informację „Hasło ustawione”, datę ostatniego logowania i link do chmury plików.</p>'),
            $step('Nie masz konta?', '<p>W tej samej sekcji założysz je od razu: login to Twój adres e-mail, a hasło wymyślasz sam. Panel zaproponuje to też sam po zalogowaniu do SZO.</p>'),
        ]],

        ['type' => 'text', 'html' => '<h2>Nie pamiętasz hasła IAM</h2>'
            . '<p>Wejdź na stronę <a href="' . $nh . '">Nowe hasło</a>. Gdy jesteś zalogowany do SZO, zmienisz hasło od razu. Gdy nie jesteś, podasz dane, o które poprosi strona, a kod potwierdzenia przyjdzie e-mailem. '
            . 'Nowe hasło od razu działa w IAM i w chmurze plików.</p>'
            . '<p>Różnicę między kontem IAM a pocztą opisuje też artykuł <a href="' . $mig . '">Czym jest IAM, a czym poczta</a>.</p>'],

        itPortalLogto404Block($app),

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Hasło IAM wpisuj tylko na stronie logowania SZO, na stronie logowania IAM pod adresem logto.feer.org.pl i w aplikacji chmury plików. '
            . 'Nie używaj go nigdzie poza Fundacją i nikomu go nie podawaj. Administrator go nie zna i nigdy o nie nie poprosi. Coś Cię zaniepokoiło? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
    ];
}
