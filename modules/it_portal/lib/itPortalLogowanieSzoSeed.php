<?php
/**
 * modules/it_portal/lib/itPortalLogowanieSzoSeed.php — artykuł „Logowanie do SZO: ekran logowania krok po kroku” w usłudze „Mój login”
 * (polecenie 2026-11-14: „przebuduj widok i zrób instrukcje” dla auth/login.php; „dopisz, że to aktualne na dzień, godz. 5:30, z uwagi na
 * migracje może się zmienić”). Zrzut img/szo-logowanie.jpg: nowy ekran z 2026-11-14 (lokalny render z przyciskiem logowania jednokrotnego).
 * Stała ITP_STAN_NA wspólna dla artykułów o logowaniu (też itPortalAktywacjaSeed.php, itPortalCplIkaSeed.php).
 * Na ten artykuł wskazują odnośniki „Instrukcja logowania” na ekranie logowania. Używa migracja '2026-11_it_portal_logowanie_szo'.
 */

require_once __DIR__ . '/itPortalCloudSeed.php';

if (!defined('ITP_STAN_NA')) define('ITP_STAN_NA', 'Stan na 14 listopada 2026 r., godz. 5:30. Trwa migracja, więc wygląd ekranów i kolejne kroki mogą się jeszcze zmienić.');

/** Ramka „stan na” do artykułów o logowaniu. */
function itPortalStanNaBlock(): array {
    return ['type' => 'callout', 'kind' => 'info', 'html' => '<p>' . ITP_STAN_NA . '</p>'];
}

function itPortalLogowanieSzoApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) return 'brak usługi „Mój login” — pominięto';
    $catId = (int)$cat['id'];
    $slug = 'logowanie-do-szo';
    $title = 'Logowanie do SZO: ekran logowania krok po kroku';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
    foreach (db_all("SELECT id FROM itp_articles WHERE category_id = ? AND slug IN ('aktywacja-konta','pierwsze-logowanie')", [$catId]) as $r) {
        if (!in_array((int)$r['id'], $related, true)) $related[] = (int)$r['id'];
    }
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'logowanie, zaloguj się, ekran logowania, cpl, centralny portal logowania, logowanie jednokrotne, iam, e-mail i hasło, nie pamiętasz hasła, pierwszy raz w szo, aktywuj konto, oświadczenia, inne metody logowania, kod sms, kod jednorazowy, microsoft 365 administratorzy, odchodzimy od microsoft 365',
        'blocks' => json_encode(itPortalLogowanieSzoBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1, 'sort_order' => 1,
        'related' => $related], $old ? (int)$old['id'] : 0);
    return "moj-login/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalLogowanieSzoBlocks(string $app): array {
    $login = $app . '/auth/login.php';
    $hd = $app . '/helpdesk/new.php';
    $b = $app . '/uslugi-it/uslugi/moj-login/';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return array_merge(
        [itPortalStanNaBlock()],
        [['type' => 'text', 'html' => '<p>Do SZO logujesz się na <a href="' . $login . '">stronie logowania</a>. Ekran ma dwie części. Po prawej jest karta logowania, a po lewej informacja o odejściu od Microsoft 365 i o tym, co masz zrobić. '
            . 'Na telefonie karta jest na górze, a informacja pod nią.</p>']],
        itPortalCloudShot('szo-logowanie.jpg', 'Ekran logowania do SZO. Po lewej informacja „Odchodzimy od Microsoft 365” z krokami, po prawej karta CPL Portal z przyciskiem „Zaloguj przez logowanie jednokrotne”, formularzem e-mail i hasło oraz kaflami „Pierwszy raz w SZO?”', 'Ekran logowania do SZO'),
        [
            ['type' => 'wizard', 'title' => 'Karta logowania od góry do dołu', 'intro' => '', 'steps' => [
                $step('CPL, Centralny Portal Logowania', '<p>Nagłówek „CPL Portal” oznacza wspólne logowanie Fundacji. Z tej samej strony wchodzisz do SZO. Jeśli masz kilka profili, na przykład pracownika i dydaktyka, inny profil wybierzesz w pełnym oknie CPL. Kursanci nie mają dostępu do SZO, logują się do swojego panelu kursanta.</p>'),
                $step('Zaloguj przez logowanie jednokrotne', '<p>To podstawowy sposób logowania. Używasz jednego konta Fundacji (IAM), tego samego co do chmury plików. Kliknij przycisk i wpisz e-mail oraz hasło IAM.</p>'),
                $step('E-mail i hasło', '<p>Tak logują się zwykle zleceniobiorcy: prywatnym adresem e-mail i nadanym hasłem. Nie masz jeszcze konta IAM? Wpisz adres e-mail i hasło do SZO i kliknij „Zaloguj”. Po zalogowaniu panel zaproponuje założenie konta IAM.</p>'),
                $step('Nie pamiętasz hasła?', '<p>Odnośnik pod formularzem prowadzi do odzyskiwania dostępu. Potwierdzisz się danymi z umowy i kodem SMS, a potem ustawisz nowe hasło. Obok jest odnośnik „Instrukcja logowania”, który prowadzi do tego artykułu.</p>'),
                $step('Pierwszy raz w SZO?', '<p>Tu są kafle dla nowych osób:</p><ul>'
                    . '<li>„Nowy wolontariusz, Aktywuj konto”: aktywacja danymi z umowy i kodem SMS.</li>'
                    . '<li>„Kod aktywacyjny”: gdy koordynator dał Ci kod. Ten kafel widać tylko wtedy, gdy logowanie kodem jest włączone.</li>'
                    . '<li>„Nowy zleceniobiorca, Oświadczenia”: tylko dla osób, które zawierają z Fundacją umowę zlecenie.</li></ul>'
                    . '<p>Szczegóły są w instrukcji <a href="' . $b . 'aktywacja-konta">Aktywacja konta w SZO</a>.</p>'),
                $step('Inne metody logowania i administratorzy', '<p>Na dole karty jest rozwijana lista. Znajdziesz w niej logowanie kodem SMS albo kodem jednorazowym, jeśli są włączone. '
                    . 'Jest tam też przycisk Microsoft 365, ale tylko dla administratorów z kontem @feer.org.pl. Pozostałe osoby z niego nie korzystają.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Zleceniobiorcy często logują się do SZO prywatnym adresem e-mail, tym z umowy, i hasłem nadanym przez Fundację. Jeśli to Twoja sytuacja, wpisz ten adres i hasło w polach „Adres e-mail” i „Hasło”, a potem kliknij „Zaloguj”. Nie pamiętasz hasła? Kliknij „Nie pamiętasz hasła?”.</p>'],
            ['type' => 'text', 'html' => '<h2>Lewa część: odchodzimy od Microsoft 365</h2>'
                . '<p>Ramka po lewej mówi, kiedy wyłączymy Microsoft 365, i podaje trzy kroki: zaloguj się e-mailem i hasłem, załóż konto IAM, a następnym razem używaj logowania jednokrotnego. '
                . 'Powody zmiany są pod odnośnikiem „Dlaczego odchodzimy od Microsoft 365?”. Kliknij go, żeby je rozwinąć.</p>'
                . '<p>Co zobaczysz po pierwszym zalogowaniu, opisuje instrukcja <a href="' . $b . 'pierwsze-logowanie">Pierwsze logowanie krok po kroku</a>.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Przed wpisaniem hasła sprawdź, czy adres strony zaczyna się od https://szo.feer.org.pl. Nikt z Fundacji nie poprosi Cię o hasło ani kod z SMS-a. '
                . 'Nie możesz się zalogować? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
        ]
    );
}
