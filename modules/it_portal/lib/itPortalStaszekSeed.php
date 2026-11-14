<?php
/**
 * modules/it_portal/lib/itPortalStaszekSeed.php — Staszek (LoginSentry, modules/login_sentry) w Portalu Usług IT, usługa „Bezpieczeństwo”
 * (/uslugi-it/uslugi/bezpieczenstwo/…). Polecenie 2026-11-15: „dopisz go do usług IT, dokumentacja”.
 *
 * Dwa artykuły: „Staszek: pilnuje logowań do Twojego konta” (dla wszystkich) i „Staszek (LoginSentry): obsługa dla IT”
 * (it_only = 1: treść widzą administratorzy i konta z rolą IT). Forma wg zasad pomocy: bez tabel i myślników, nazwy przycisków
 * w „cudzysłowie”, polecenia w <pre><code>. Fakty zgodne z modules/login_sentry (LoginSentryConfig::DEFAULTS, LoginSentryEngine).
 * „Zobacz też”: artykuł IT → ogólny (nie odwrotnie) + zmiana haseł i logowanie jednokrotne (dopisywane, cudze powiązania zostają).
 * Artykuł ogólny ma it_only = -1 (dla wszystkich także wtedy, gdy usługa „Bezpieczeństwo” jest tylko dla IT), polecenie 2026-11-15.
 * Bezpieczny do ponownego uruchomienia. Używają: migracja '2026-11_it_portal_staszek', cli/it_portal_staszek_seed.php.
 */

/** Artykuły spoza usługi „Bezpieczeństwo” łączone ze Staszkiem: [slug usługi, slug artykułu, w obie strony]. */
const ITP_STASZEK_RELATED = [['konto-i-hasla', 'zmiana-hasel', true], ['moj-login', 'logowanie-jednokrotne', false]];

/** @return list<string> */
function itPortalStaszekApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = [];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'bezpieczenstwo'");
    if (!$cat) return ['brak usługi „Bezpieczeństwo” (slug bezpieczenstwo), nic nie zapisano'];
    $catId = (int)$cat['id'];

    $ext = [];
    foreach (ITP_STASZEK_RELATED as [$cslug, $aslug, $both]) {
        $r = db_one("SELECT a.id, a.title, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cslug, $aslug]);
        if ($r) $ext[(int)$r['id']] = $r + ['both' => $both];
    }

    $ids = [];
    $base = (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) AS s FROM itp_articles WHERE category_id = ? AND slug NOT LIKE 'staszek%'", [$catId])['s'] ?? 0);
    $i = 0;
    foreach (itPortalStaszekArticles($app) as $slug => [$title, $keywords, $blocks, $itOnly]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $rep[] = "bezpieczenstwo/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'nowy') . ($itOnly ? ', tylko IT' : '') . ')';
        if ($dryRun) continue;
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0,
            'sort_order' => $base + (++$i) * 10, 'it_only' => $itOnly ? 1 : -1,   // -1: dla wszystkich, nawet gdy usługa jest tylko dla IT
            'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $ids[] = $id;
    }
    if ($dryRun) {
        foreach ($ext as $r) $rep[] = 'Zobacz też: ' . $r['title'] . ($r['both'] ? ' (w obie strony)' : '');
        return $rep;
    }
    // Artykuł dla wszystkich ($ids[0]) nie wskazuje artykułu IT (osoba spoza IT zobaczyłaby tylko kłódkę); artykuł IT wskazuje ogólny.
    foreach ($ids as $n => $id) {
        $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$id]);
        $own = $n === 0 ? [] : [$ids[0]];
        $rel = array_values(array_unique(array_merge(itPortalIds($r['related'] ?? '[]'), $own, array_keys($ext))));
        if ($n === 0) $rel = array_values(array_diff($rel, array_slice($ids, 1)));
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($rel), $id]);
    }
    // w drugą stronę tylko artykuł dla wszystkich (pierwszy), żeby artykuł IT nie wisiał przy tekstach dla pracowników
    foreach ($ext as $eid => $r) {
        if (!$r['both'] || !$ids) continue;
        $cur = itPortalIds($r['related']);
        if (in_array($ids[0], $cur, true)) continue;
        $cur[] = $ids[0];
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode(array_values(array_unique($cur))), $eid]);
        $rep[] = 'Staszek dopisany do „Zobacz też”: ' . $r['title'];
    }
    return $rep;
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, tylko IT].
 * @return array<string, array{0:string,1:string,2:array,3:bool}>
 */
function itPortalStaszekArticles(string $app): array {
    $hd   = $app . '/helpdesk/new.php';
    $pass = $app . '/uslugi-it/uslugi/konto-i-hasla/zmiana-hasel';
    $panel = $app . '/modules/login_sentry/router.php';
    $c   = static fn(string $s): string => '<code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code>';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $t   = static fn(string $h): array => ['type' => 'text', 'html' => $h];

    return [
        // ── Dla wszystkich ──
        'staszek' => ['Staszek: pilnuje logowań do Twojego konta',
            'staszek, loginsentry, login sentry, podejrzane logowanie, włamanie, przejęcie konta, ktoś zna moje hasło, konto zawieszone, konto zablokowane, '
            . 'nie mogę się zalogować, blokada konta, adres ip, nowe urządzenie, nowa przeglądarka, wyjazd, za granicą, bezpieczeństwo, mietek, gosposia', [
            $t('<p>Staszek to skrypt zespołu IT. Co 6 godzin przegląda logowania do SZO i do Systemu Tożsamości (logowanie jednokrotne) '
                . 'i sprawdza, czy na czyjeś konto nie wszedł ktoś obcy, na przykład z hasłem, które wyciekło z innego serwisu.</p>'
                . '<p>Działa w tle. Na co dzień nic nie musisz robić.</p>'),

            $t('<h2>Na co patrzy</h2>'
                . '<p>Staszek zna Twoje zwykłe logowania: z jakich sieci i z jakiej przeglądarki wchodzisz. Uważa za podejrzane między innymi:</p><ul>'
                . '<li>serię błędnych haseł tuż przed udanym logowaniem,</li>'
                . '<li>logowanie z nowej sieci i z nowej przeglądarki jednocześnie,</li>'
                . '<li>logowania z kilku różnych miejsc w ciągu kilku godzin,</li>'
                . '<li>logowanie przez program lub skrypt zamiast zwykłej przeglądarki.</li></ul>'
                . '<p>Samo logowanie z nowego miejsca, na przykład z hotelu albo z telefonu u rodziny, nie wystarczy do blokady.</p>'),

            $t('<h2>Co się dzieje, gdy coś mu nie pasuje</h2>'
                . '<p>Przy drobnych sygnałach Staszek tylko pisze do administratora, a konto działa dalej. '
                . 'Przy mocnych sygnałach zawiesza konto w SZO i w Systemie Tożsamości i od razu daje znać administratorowi. '
                . 'Wtedy nie zalogujesz się do SZO ani do usług, do których wchodzisz przez logowanie jednokrotne, na przykład do chmury.</p>'),

            ['type' => 'wizard', 'title' => 'Konto zostało zawieszone', 'intro' => '<p>Jeśli hasło na pewno jest dobre, a SZO Cię nie wpuszcza:</p>', 'steps' => [
                ['title' => 'Daj znać IT', 'html' => '<p>Napisz zgłoszenie w <a href="' . $hd . '">Helpdesku IT</a> albo zadzwoń do kogoś z IT. '
                    . 'Napisz, skąd i kiedy się logowałeś/aś, na przykład „wczoraj wieczorem z telefonu, z wyjazdu”.</p>', 'images' => []],
                ['title' => 'IT sprawdza', 'html' => '<p>Administrator widzi, co zauważył Staszek. Jeśli to Ty się logowałeś/aś, przywraca dostęp jednym kliknięciem, '
                    . 'a Staszek zapamiętuje tę sieć i przeglądarkę jako Twoje.</p>', 'images' => []],
                ['title' => 'To nie Ty?', 'html' => '<p>Jeśli to nie Ty się logowałeś/aś, ktoś zna Twoje hasło. IT pomoże ustawić nowe. '
                    . 'Jak zmienić hasła, opisuje <a href="' . $pass . '">Zmiana haseł</a>.</p>', 'images' => []],
            ]],

            $t('<h2>Wyjazd albo praca z nietypowego miejsca</h2>'
                . '<p>Jeśli wiesz, że przez jakiś czas będziesz się logować z zupełnie innych miejsc, napisz do IT przed wyjazdem. '
                . 'Administrator może ustawić dla Ciebie wyjątek na określoną liczbę dni.</p>'),

            $t('<h2>Co Staszek widzi, a czego nie</h2>'
                . '<p>Staszek widzi tylko dane o logowaniu: czas, adres IP, sieć, z której przyszło logowanie, i rodzaj przeglądarki (na przykład „Chrome / Windows”). '
                . 'Nie czyta poczty, dokumentów ani plików. Zapisy są w osobnej bazie, do której dostęp ma tylko administrator.</p>'),

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Staszek ma ludzkie imię tak jak Mietek, który pilnuje nieużywanych kont, i Gosposia, która sprząta pocztę. '
                . 'Łatwiej powiedzieć „Staszek zablokował mi konto” niż podawać nazwę skryptu.</p>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Najlepsza ochrona to hasło, którego nie używasz nigdzie indziej. '
                . 'Jeśli to samo hasło masz w innym serwisie, zmień je: <a href="' . $pass . '">Zmiana haseł</a>.</p>'],
        ], false],

        // ── Dla IT ──
        'staszek-it' => ['Staszek (LoginSentry): obsługa dla IT',
            'staszek, loginsentry, login_sentry, panel, tryb podglądu, preview, live, off, punkty, próg, cofnij, odblokuj, wyjątek, zaufana sieć, cidr, '
            . 'dry-run, force-id, stats, cron, dyspozytor, logto, api logs, is-suspended, cloudflare, remote_addr, geoip, mmdb, sqlite', [
            $t('<p>Staszek to moduł ' . $c('modules/login_sentry') . ' w SZO. Panel: <a href="' . $panel . '">LoginSentry</a> '
                . '(też w Ctrl+K i na kafelku w panelu administratora). Dostęp ma tylko rola admin.</p>'),

            $t('<h2>Tryby</h2><ul>'
                . '<li>„Wyłączony”: nic nie robi.</li>'
                . '<li>„Podgląd (bez działań)”: ocenia logowania i zapisuje wyniki w panelu, ale nie wysyła maili i nie blokuje. Od tego trybu startuje.</li>'
                . '<li>„Działa”: wysyła maile do administratora i zawiesza konta.</li></ul>'
                . '<p>Tryb zmieniasz w panelu, w sekcji „Ustawienia”. „Działa” wymaga połączenia z Logto (Ustawienia logowania jednokrotnego, aplikacja Machine-to-machine).</p>'),

            $t('<h2>Jak liczy punkty</h2>'
                . '<p>Każde udane logowanie z ostatnich 7 godzin dostaje punkty:</p><ul>'
                . '<li>sieć z listy podejrzanych: 100,</li>'
                . '<li>co najmniej 5 błędnych haseł w ciągu 60 minut przed sukcesem: 45,</li>'
                . '<li>kraj spoza listy (tylko z bazą GeoIP): 40,</li>'
                . '<li>sieć /24 (IPv6: /48), z której osoba się nie logowała: 25,</li>'
                . '<li>klient-skrypt (curl, python i podobne): 20,</li>'
                . '<li>nowa przeglądarka lub system: 15,</li>'
                . '<li>noc od 1:00 do 5:00: 10.</li></ul>'
                . '<p>Do najwyższego wyniku dochodzą premie z całego okna: co najmniej 3 różne sieci (40) i zmiana kraju w ciągu 2 godzin (50, tylko z GeoIP). '
                . '„Nowa sieć” i „nowa przeglądarka” liczą się dopiero, gdy osoba ma co najmniej 3 znane logowania. Wzorce buduje pierwszy przebieg z 90 dni historii SZO.</p>'
                . '<p>Od 40 punktów idzie e-mail do administratora. Od 70 punktów konto jest zawieszane w Logto, blokowane w SZO (wylogowanie wszystkich sesji) i idzie e-mail. '
                . 'Progi i liczbę błędnych haseł zmienisz w panelu.</p>'),

            $t('<h2>Bezpieczniki</h2><ul>'
                . '<li>Konta z rolą admin w SZO dostają tylko e-mail, bez blokady. Zmienisz to polem „Blokuj też konta administratorów”.</li>'
                . '<li>Najwyżej 5 blokad na przebieg. Powyżej limitu tylko e-mail, bo masowe blokady to raczej awaria niż atak.</li>'
                . '<li>Jedno zdarzenie na osobę na dzień. Ponowny przebieg nie dubluje maili ani blokad; ostrzeżenie może tylko urosnąć do blokady.</li>'
                . '<li>Adresy prywatne i adresy Cloudflare nie liczą się jako sieć.</li>'
                . '<li>Błąd przy jednej osobie trafia do dziennika jako ERROR i nie przerywa przebiegu.</li></ul>'),

            $t('<h2>Codzienna obsługa</h2>'
                . '<p>Po mailu o blokadzie skontaktuj się z osobą innym kanałem niż poczta FEER. Jeśli to była ona, w tabeli „Zdarzenia” kliknij '
                . '„To była ta osoba, cofnij”: konto wraca w Logto i w SZO, a sieć i przeglądarka z tego logowania stają się zaufane. '
                . 'Przy samym ostrzeżeniu ten sam przycisk nazywa się „To była ta osoba”.</p>'
                . '<p>Jeśli to nie była ta osoba, zostaw blokadę, ustaw nowe hasło w Systemie Tożsamości i sprawdź, co zmieniło się na koncie.</p>'
                . '<p>W panelu są też „Wymuś sprawdzenie osoby” (ocena jeszcze raz, także logowań już sprawdzonych), wyjątki dla osób (na wybraną liczbę dni) '
                . 'i zaufane sieci (adres albo zakres CIDR, na przykład biuro lub VPN) oraz dziennik audytu.</p>'),

            $t('<h2>Uruchamianie</h2>'
                . '<p>Przebieg co 6 godzin uruchamia dyspozytor CRON SZO (klucz ' . $c('login_sentry') . '). Ręcznie, w katalogu aplikacji:</p>'
                . $pre("php bin/login_sentry.php --dry-run\nphp bin/login_sentry.php --force-id=12\nphp bin/login_sentry.php --stats\nphp bin/login_sentry.php")
                . '<p>' . $c('--dry-run') . ' wypisuje pełny raport i niczego nie zapisuje ani nie wysyła. ' . $c('--force-id') . ' sprawdza jedno konto SZO. '
                . 'Dwa uruchomienia naraz się nie nałożą: drugie kończy się kodem 2.</p>'
                . '<p>Bez dyspozytora wpis w crontab wygląda tak:</p>'
                . $pre('15 0,6,12,18 * * *  cd /ścieżka/do/feerSZO && php bin/login_sentry.php >> storage/login_sentry/cron.log 2>&1')),

            $t('<h2>Konfiguracja i dane</h2>'
                . '<p>Wzór ustawień: ' . $c('config/login_sentry.example.php') . '. Skopiuj go jako ' . $c('config/login_sentry.php') . ' (poza repozytorium). '
                . 'Tam ustawisz zaufane i podejrzane sieci, listę krajów, bazę GeoIP i klucz dziennika Logto. Ustawienia z panelu mają pierwszeństwo przed plikiem.</p>'
                . '<p>Baza Staszka: ' . $c('storage/login_sentry/login_sentry_<losowe znaki>.sqlite') . ', katalog zamknięty dla WWW. '
                . 'Testy: ' . $c('php modules/login_sentry/tests/run_tests.php') . '.</p>'),

            $t('<h2>Znane ograniczenia</h2>'
                . '<p>SZO zapisuje adres IP z ' . $c('REMOTE_ADDR') . '. Jeśli serwer stoi za Cloudflare i nie odtwarza prawdziwego adresu (nagłówek '
                . $c('CF-Connecting-IP') . '), Staszek widzi adresy Cloudflare, pomija je i pokazuje ostrzeżenie w panelu.</p>'
                . '<p>Logowania z Logto czyta z ' . $c('GET /api/logs') . ', domyślnie z kluczem ' . $c('Interaction.SignIn.Submit') . '. '
                . 'Jeśli Logto zapisuje logowania pod innym kluczem, zmień ' . $c('logto_log_keys') . ' w pliku konfiguracji.</p>'),
        ], true],
    ];
}
