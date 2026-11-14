<?php
/**
 * modules/it_portal/lib/itPortalVpnSeed.php — wstęp i artykuły usługi „VPN” w Portalu Usług IT (polecenie 2026-11-14: „opisz VPN”;
 * „korzystamy z NordVPN”; „OpenVPN, adresy serwerów są poufne i nie znajdują się tu”). Celowo BEZ adresów serwerów i nazw hostów.
 * Źródła: vpn/index.php (wniosek z uzasadnieniem, po akceptacji nazwa użytkownika, instrukcja i pobranie konfiguracji),
 * vpn/config.php (własny plik .ovpn/.conf tylko dla aktywnego dostępu), includes/vpn.php (statusy oczekuje/aktywny/odrzucony/cofnięty,
 * powiadomienia e-mail), require_module_vpn() (moduły dostępne tylko przez VPN, np. EZD). Używa migracja '2026-11_it_portal_vpn'.
 */

function itPortalVpnApply(string $app): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'vpn'");
    if (!$cat) return ['brak usługi „VPN” — pominięto'];
    $catId = (int)$cat['id'];
    $b = $app . '/uslugi-it/uslugi/vpn/';
    $intro = itPortalSanitizeHtml('<p>VPN to bezpieczne, szyfrowane połączenie z siecią Fundacji przez internet. Korzystamy z usługi NordVPN, a łączysz się programem obsługującym OpenVPN. '
        . 'Dostęp przyznajemy na wniosek, osobno dla każdej osoby.</p>'
        . '<p>Jak go uzyskać i jak się połączyć, opisuje instrukcja <a href="' . $b . 'dostep-do-vpn">Dostęp do VPN: wniosek i połączenie</a>. '
        . 'Jeśli coś nie działa, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>');
    db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    $rep = [];
    foreach (itPortalVpnArticles($app) as $slug => [$title, $keywords, $blocks, $pinned, $sort]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => $sort, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $rep[] = "vpn/{$slug} — {$title}";
    }
    return $rep;
}

/** slug => [tytuł, słowa kluczowe, bloki, przypięty, kolejność] */
function itPortalVpnArticles(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $vpn = $app . '/vpn/index.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'dostep-do-vpn' => ['Dostęp do VPN: wniosek i połączenie', 'vpn, nordvpn, openvpn, openvpn connect, dostęp vpn, wniosek o vpn, plik konfiguracji, ovpn, zdalny dostęp, praca zdalna, ezd przez vpn, tylko przez vpn, połączenie szyfrowane', [
            ['type' => 'text', 'html' => '<p>VPN szyfruje połączenie między Twoim komputerem a siecią Fundacji. Niektóre części SZO, na przykład EZD Wirtualne biurko, mogą być dostępne tylko przez VPN. '
                . 'Jeśli przy wejściu do modułu zobaczysz komunikat, że wymaga on połączenia VPN, połącz się i spróbuj jeszcze raz.</p>'
                . '<p>Korzystamy z usługi NordVPN. Łączysz się programem obsługującym OpenVPN, z osobistym plikiem konfiguracji pobranym z SZO.</p>'],
            ['type' => 'wizard', 'title' => 'Wniosek o dostęp', 'intro' => '', 'steps' => [
                $step('Otwórz Dostęp VPN', '<p>Zaloguj się do SZO i otwórz stronę <a href="' . $vpn . '">Dostęp VPN</a>. W menu SZO jest w sekcji IT.</p>'),
                $step('Napisz uzasadnienie', '<p>W polu „Uzasadnienie” napisz krótko, do czego potrzebujesz VPN, na przykład do zdalnej pracy w Wirtualnym biurku. Wyślij wniosek.</p>'),
                $step('Poczekaj na decyzję', '<p>Wniosek ma status „oczekuje”, dopóki administrator go nie rozpatrzy. O decyzji dostaniesz e-mail. '
                    . 'Po akceptacji dostęp jest aktywny, a po odrzuceniu zobaczysz powód.</p>'),
            ]],
            ['type' => 'wizard', 'title' => 'Pierwsze połączenie', 'intro' => '<p>Po akceptacji wniosku wróć na stronę <a href="' . $vpn . '">Dostęp VPN</a>. Znajdziesz tam swoją nazwę użytkownika, instrukcję i przycisk pobrania pliku konfiguracji.</p>', 'steps' => [
                $step('Pobierz plik konfiguracji', '<p>Pobierz swój plik konfiguracji. Jest przygotowany tylko dla Ciebie i działa tylko przy aktywnym dostępie.</p>'),
                $step('Zainstaluj program', '<p>Zainstaluj program obsługujący OpenVPN, na przykład OpenVPN Connect, ze strony producenta albo ze sklepu z aplikacjami. Na komputerze służbowym instalację może wykonać IT.</p>'),
                $step('Zaimportuj plik', '<p>Otwórz program i zaimportuj pobrany plik konfiguracji. Zwykle wystarczy przeciągnąć go do okna programu albo wybrać import pliku.</p>'),
                $step('Połącz się', '<p>Kliknij połączenie i, jeśli program zapyta, podaj nazwę użytkownika i hasło z instrukcji w SZO. Gdy połączenie jest aktywne, program pokazuje zielony albo podobny znacznik.</p>'),
            ]],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Adresy serwerów VPN, plik konfiguracji i dane logowania są poufne. Nie przekazuj ich nikomu, nie wysyłaj e-mailem i nie wklejaj do czatów. '
                . 'Dlatego nie ma ich w tej instrukcji. Każda osoba dostaje własny plik w SZO. Gdy zgubisz komputer albo telefon z konfiguracją, od razu <a href="' . $hd . '">zgłoś to w Helpdesku IT</a>, a zablokujemy dostęp.</p>'],
            ['type' => 'text', 'html' => '<h2>Kiedy dostęp się kończy</h2>'
                . '<p>Administrator może cofnąć dostęp, na przykład gdy kończysz współpracę albo nie potrzebujesz już VPN. Wtedy plik konfiguracji przestaje działać. '
                . 'Jeśli dostęp znów będzie potrzebny, złóż nowy wniosek.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>VPN łączy się, ale strony SZO się nie otwierają albo połączenie co chwilę się zrywa? Rozłącz VPN, sprawdź, czy działa zwykły internet, i połącz się jeszcze raz. '
                . 'Gdy to nie pomaga, <a href="' . $hd . '">napisz do Helpdesku IT</a> i dołącz zrzut ekranu z komunikatem programu.</p>'],
        ], true, 10],
    ];
}
