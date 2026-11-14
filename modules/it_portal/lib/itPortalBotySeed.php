<?php
/**
 * modules/it_portal/lib/itPortalBotySeed.php — pozostałe skrypty IT z ludzkim imieniem w Portalu Usług IT, opisane tak jak Staszek
 * (itPortalStaszekSeed.php): artykuł dla wszystkich + artykuł „Dla IT” (it_only = 1). Polecenie 2026-11-15 „tak samo opisz pozostałe boty”.
 *
 *   Mietek (AccountWarden, modules/warden)  → usługa „Bezpieczeństwo”: mietek (dla wszystkich), mietek-it (IT).
 *   Gosposia (modules/gosposia)             → usługa „Moja poczta”: gosposia-it (IT); artykuł dla wszystkich już jest (itPortalGosposiaSeed.php).
 * Fakty z kodu: WardenEngine (cykl 7 dni, e-mail dzień 0 i 4, SMS dzień 6, próg domyślnie 30 dni, limit 30 nowych cykli), WardenController,
 * cron/agents/warden.php; Gosposia, GosposiaImap, GosposiaClassifier, GosposiaStore, GosposiaNotify, router (gosposia.access).
 * „Zobacz też”: artykuły jednego skryptu nawzajem, Mietek ↔ Staszek (dla wszystkich), logowanie jednokrotne; gosposia-it ↔ gosposia.
 * Forma wg zasad pomocy: bez tabel i myślników, przyciski w „cudzysłowie”, polecenia w <pre><code>. Bezpieczny do ponownego uruchomienia.
 * Używają: migracja '2026-11_it_portal_boty', cli/it_portal_boty_seed.php.
 */

/** @return list<string> */
function itPortalBotyApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = [];
    $art = static fn(string $cat, string $slug): ?array =>
        db_one("SELECT a.id, a.title, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$cat, $slug]) ?: null;

    $saved = [];   // "cat/slug" => id
    foreach (itPortalBotyArticles($app) as $key => [$catSlug, $title, $keywords, $blocks, $itOnly]) {
        [, $slug] = explode('/', $key, 2);
        $cat = db_one("SELECT id FROM itp_categories WHERE slug = ?", [$catSlug]);
        if (!$cat) { $rep[] = "brak usługi „{$catSlug}”, pominięto {$slug}"; continue; }
        $catId = (int)$cat['id'];
        $old = db_one("SELECT id, related, sort_order FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $rep[] = "{$key} — {$title} (" . ($old ? 'nadpisany' : 'nowy') . ($itOnly ? ', tylko IT' : '') . ')';
        if ($dryRun) continue;
        $sort = $old ? (int)$old['sort_order'] : (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM itp_articles WHERE category_id = ?", [$catId])['s'] ?? 10);
        $saved[$key] = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0, 'sort_order' => $sort,
            'it_only' => $itOnly ? 1 : 0, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
    }
    if ($dryRun) return $rep;

    // Powiązania [z, do]; dopisujemy, cudzych nie ruszamy. Brakujące artykuły pomijamy.
    $ids = $saved;
    foreach (['bezpieczenstwo/staszek', 'bezpieczenstwo/staszek-it', 'moja-poczta/gosposia', 'moj-login/logowanie-jednokrotne'] as $k) {
        [$c, $s] = explode('/', $k, 2);
        if (!isset($ids[$k]) && ($r = $art($c, $s))) $ids[$k] = (int)$r['id'];
    }
    $links = [
        ['bezpieczenstwo/mietek', 'bezpieczenstwo/mietek-it'], ['bezpieczenstwo/mietek-it', 'bezpieczenstwo/mietek'],
        ['bezpieczenstwo/mietek', 'bezpieczenstwo/staszek'], ['bezpieczenstwo/staszek', 'bezpieczenstwo/mietek'],
        ['bezpieczenstwo/mietek-it', 'bezpieczenstwo/staszek-it'], ['bezpieczenstwo/staszek-it', 'bezpieczenstwo/mietek-it'],
        ['bezpieczenstwo/mietek', 'moj-login/logowanie-jednokrotne'],
        ['moja-poczta/gosposia-it', 'moja-poczta/gosposia'], ['moja-poczta/gosposia', 'moja-poczta/gosposia-it'],
    ];
    foreach ($links as [$from, $to]) {
        if (!isset($ids[$from], $ids[$to])) continue;
        $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$ids[$from]]);
        $cur = itPortalIds($r['related'] ?? '[]');
        if (in_array($ids[$to], $cur, true)) continue;
        $cur[] = $ids[$to];
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode(array_values(array_unique($cur))), $ids[$from]]);
        $rep[] = "Zobacz też: {$from} → {$to}";
    }
    return $rep;
}

/**
 * Artykuły: "usługa/slug" => [usługa, tytuł, słowa kluczowe, bloki, tylko IT].
 * @return array<string, array{0:string,1:string,2:string,3:array,4:bool}>
 */
function itPortalBotyArticles(string $app): array {
    $hd    = $app . '/helpdesk/new.php';
    $pass  = $app . '/uslugi-it/uslugi/konto-i-hasla/zmiana-hasel';
    $wpanel = $app . '/modules/warden/router.php';
    $g     = $app . '/pomocne/gosposia';
    $gUser = $app . '/uslugi-it/uslugi/moja-poczta/gosposia';
    $c   = static fn(string $s): string => '<code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code>';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $t   = static fn(string $h): array => ['type' => 'text', 'html' => $h];
    $step = static fn(string $title, string $h): array => ['title' => $title, 'html' => $h, 'images' => []];

    return [
        // ── Mietek: dla wszystkich ──
        'bezpieczenstwo/mietek' => ['bezpieczenstwo', 'Mietek: pilnuje nieużywanych kont',
            'mietek, accountwarden, warden, nieaktywne konto, nieużywane konto, brak logowania, brak aktywności, ostrzeżenie, wyłączymy konto, '
            . 'konto zawieszone, konto zablokowane, odblokowanie, sms, przypomnienie, system tożsamości, iam, staszek, gosposia', [
            $t('<p>Mietek to skrypt zespołu IT. Raz dziennie sprawdza, kiedy ostatnio logowałeś/aś się do SZO albo przez System Tożsamości '
                . '(logowanie jednokrotne, na przykład do chmury). Wystarczy jedno z tych miejsc.</p>'
                . '<p>Pilnuje, żeby nieużywane konta nie leżały otwarte. Zapomniane konto łatwo przejąć, bo nikt nie zauważy, że loguje się ktoś obcy.</p>'),

            $t('<h2>Jak działa</h2>'
                . '<p>Jeśli przez 30 dni nie zalogujesz się nigdzie, Mietek zaczyna siedmiodniowe odliczanie. Próg ustala IT, więc może być inny. W tym czasie dostaniesz:</p><ul>'
                . '<li>pierwszego dnia e-mail z przypomnieniem,</li>'
                . '<li>czwartego dnia drugi e-mail, 3 dni przed wyłączeniem,</li>'
                . '<li>szóstego dnia SMS, jeśli w SZO jest Twój numer telefonu.</li></ul>'
                . '<p>Maile przychodzą na adres konta w SZO albo na adres zapasowy, jeśli ustawiłeś/aś go do powiadomień.</p>'
                . '<p>Jedno logowanie do SZO albo do chmury kończy odliczanie i konto zostaje aktywne. Jeśli logowania nie będzie, konto zostanie zawieszone '
                . 'w SZO i w Systemie Tożsamości.</p>'),

            ['type' => 'wizard', 'title' => 'Konto zostało zawieszone przez Mietka', 'intro' => '<p>Wracasz po dłuższej przerwie i nie możesz się zalogować?</p>', 'steps' => [
                $step('Napisz do IT', '<p>Zgłoś się w <a href="' . $hd . '">Helpdesku IT</a> albo napisz do kogoś z IT. Podaj swój adres e-mail w Fundacji.</p>'),
                $step('IT odblokowuje', '<p>Administrator odblokowuje konto jednym kliknięciem, w SZO i w Systemie Tożsamości naraz.</p>'),
                $step('Zaloguj się', '<p>Zaloguj się jak zwykle. Mietek liczy nieaktywność od dnia odblokowania, więc nie dostaniesz od razu kolejnego ostrzeżenia. '
                    . 'Jeśli nie pamiętasz hasła, zobacz <a href="' . $pass . '">Zmiana haseł</a>.</p>'),
            ]],

            $t('<h2>Czy muszę coś robić?</h2>'
                . '<p>Jeśli regularnie zaglądasz do SZO albo do chmury, nie. Jeśli Twój udział w projektach Fundacji się skończył i konto nie jest Ci potrzebne, '
                . 'też nic nie rób: po terminie zostanie zawieszone.</p>'
                . '<p>Konto po zakończeniu umowy wyłącza się osobno. Mietek zajmuje się kontami, które mają ważną umowę, ale od dawna nikt z nich nie korzysta.</p>'),

            $t('<h2>Co Mietek widzi</h2>'
                . '<p>Tylko daty ostatnich logowań do SZO i do Systemu Tożsamości. Nie czyta poczty, dokumentów ani plików.</p>'),

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Mietek ma ludzkie imię tak jak Staszek, który pilnuje podejrzanych logowań, i Gosposia, która sprząta pocztę. '
                . 'Łatwiej powiedzieć „Mietek wyłączył mi konto” niż podawać nazwę skryptu.</p>'],
        ], false],

        // ── Mietek: dla IT ──
        'bezpieczenstwo/mietek-it' => ['bezpieczenstwo', 'Mietek (AccountWarden): obsługa dla IT',
            'mietek, accountwarden, warden, panel, uruchom, zatrzymaj, wznów, przebieg teraz, podgląd, próg nieaktywności, limit nowych cykli, wyjątek, odblokuj, '
            . 'cykl, ostrzeżenie, sms, warden_inactivity, logto, lastsigninat, is-suspended, login_log, user_sessions, cron, dyspozytor, sqlite', [
            $t('<p>Mietek to moduł ' . $c('modules/warden') . ' (AccountWarden) w SZO. Panel: <a href="' . $wpanel . '">AccountWarden</a> '
                . '(też w Ctrl+K i na kafelku w panelu administratora). Dostęp ma tylko rola admin.</p>'),

            $t('<h2>Włączanie i wyłączanie</h2><ul>'
                . '<li>„Uruchom AccountWarden”: włącza tryb na żywo od następnej doby i wysyła ogłoszenie „Poznajcie Mietka” do wszystkich aktywnych kont (każdemu raz). '
                . 'Wymaga połączenia z Logto (aplikacja Machine-to-machine w Ustawieniach logowania jednokrotnego) i zaznaczenia potwierdzenia.</li>'
                . '<li>„Zatrzymaj”: nic nie wysyła i nie blokuje. Otwarte cykle czekają.</li>'
                . '<li>„Wznów”: włącza z powrotem, bez ponownego ogłoszenia.</li>'
                . '<li>„Przebieg teraz”: przebieg od razu, poza harmonogramem.</li>'
                . '<li>„Podgląd (bez wysyłki)”: pokazuje, co zrobiłby przebieg, niczego nie zapisuje.</li></ul>'),

            $t('<h2>Skąd bierze ostatnią aktywność</h2>'
                . '<p>Bierze najnowszą z dat: udane logowanie w ' . $c('login_log') . ', ostatnia aktywność sesji w ' . $c('user_sessions')
                . ', ' . $c('lastSignInAt') . ' z Logto i data odblokowania. Gdy nie ma żadnej, liczy od założenia konta. '
                . 'Najnowszą widzianą datę zapisuje u siebie, więc czyszczenie dzienników SZO jej nie cofa.</p>'
                . '<p>Sprawdza tylko aktywne konta SZO powiązane z kontem w Logto.</p>'),

            $t('<h2>Cykl</h2><ul>'
                . '<li>Brak aktywności dłużej niż próg (domyślnie 30 dni): start cyklu i pierwszy e-mail. Termin blokady: start plus 7 dni.</li>'
                . '<li>Dzień 4: drugi e-mail. Jeśli wyszedł po terminie (na przykład cron nie działał), termin przesuwa się na co najmniej 3 dni od wysyłki.</li>'
                . '<li>Dzień 6: SMS (rodzaj ' . $c('warden_inactivity') . ', ASCII, do 160 znaków). Bez numeru albo przy błędzie wysyłki SMS jest pomijany, a powód trafia do historii.</li>'
                . '<li>Po terminie: zawieszenie w Logto (' . $c('PATCH /api/users/{id}/is-suspended') . '), potem blokada w SZO (' . $c('is_active = 0') . ' i usunięcie sesji).</li>'
                . '<li>Logowanie w trakcie cyklu (SZO albo Logto) zamyka cykl, a źródło logowania widać w panelu.</li></ul>'),

            $t('<h2>Bezpieczniki</h2><ul>'
                . '<li>Jeden krok cyklu na osobę na przebieg.</li>'
                . '<li>Blokada dopiero po drugim e-mailu i po SMS-ie albo jego pominięciu.</li>'
                . '<li>Błąd Logto przy odczycie pomija osobę: nigdy nie uznajemy jej wtedy za nieaktywną.</li>'
                . '<li>Błąd zawieszenia w Logto zostawia cykl otwarty, żeby następny przebieg spróbował jeszcze raz.</li>'
                . '<li>Limit nowych cykli na przebieg (domyślnie 30), resztę bierze następny przebieg.</li>'
                . '<li>Blokada pliku przed dwoma przebiegami naraz.</li></ul>'),

            $t('<h2>Codzienna obsługa</h2>'
                . '<p>„Odblokuj” przy osobie w sekcji zablokowanych kont zdejmuje zawieszenie w Logto i blokadę w SZO, a nieaktywność liczy od dziś. '
                . 'Jeśli ktoś odblokuje konto poza Mietkiem, Mietek to zauważy i też zacznie liczyć od nowa.</p>'
                . '<p>„Dodaj wyjątek” wyłącza Mietka dla wybranej osoby, na przykład dla konta serwisowego albo zarządu. Trzeba podać powód. '
                . 'Otwarty cykl tej osoby zamknie się przy najbliższym przebiegu.</p>'
                . '<p>W „Ustawieniach” zmienisz próg nieaktywności (od 14 do 365 dni), limit nowych cykli (od 1 do 1000) i adres logowania z maili (tylko https).</p>'),

            $t('<h2>Uruchamianie</h2>'
                . '<p>Raz na dobę, między 9:00 a 12:00, uruchamia go dyspozytor CRON SZO (klucz ' . $c('warden') . '), żeby maile i SMS-y nie szły w nocy. Ręcznie:</p>'
                . $pre("php cron/agents/warden.php --preview\nphp cron/agents/warden.php")
                . '<p>' . $c('--preview') . ' pokazuje plan bez wysyłki. Bez flagi przebieg idzie według trybu z panelu.</p>'),

            $t('<h2>Dane</h2>'
                . '<p>Baza Mietka: ' . $c('storage/warden/warden_<losowe znaki>.sqlite') . ', katalog zamknięty dla WWW. Są w niej cykle, wyjątki, historia działań i lista osób, '
                . 'które dostały ogłoszenie. Teksty maili i SMS-a: ' . $c('modules/warden/lib/WardenTemplates.php') . '.</p>'),
        ], true],

        // ── Gosposia: dla IT ──
        'moja-poczta/gosposia-it' => ['moja-poczta', 'Gosposia: obsługa dla IT',
            'gosposia, it, imap, dpoczta, imap.dpoczta.pl, inbox, kosz, uid move, expunge, claude, anthropic, anthropic_api_key, gosposia_model, '
            . 'gosposia_scan_limit, heurystyka, biała lista, gosposia_whitelist, gosposia_accounts, fcencrypt, audit_logs, powiadomienia, dostęp, umowa', [
            $t('<p>Gosposia to moduł ' . $c('modules/gosposia') . ' pod adresem <a href="' . $g . '">' . $c('/pomocne/gosposia') . '</a>. '
                . 'W przeciwieństwie do Mietka i Staszka nie działa w tle: rusza tylko wtedy, gdy ktoś sam ją otworzy. '
                . 'Opis dla użytkowników: <a href="' . $gUser . '">Gosposia: sprzątanie reklam z poczty</a>.</p>'),

            $t('<h2>Kto ma dostęp</h2>'
                . '<p>Administrator i osoby z umową w SZO. Pozostali dostają komunikat, że Gosposia jest dla administratora i współpracowników Fundacji (kod 403).</p>'),

            $t('<h2>Jak działa</h2><ul>'
                . '<li>Łączy się z ' . $c('imap.dpoczta.pl:993') . ' (SSL, z weryfikacją certyfikatu) własnym klientem IMAP, bez rozszerzenia ' . $c('ext-imap') . '.</li>'
                . '<li>Czyta tylko nagłówki 1500 najnowszych wiadomości z folderu Odebrane (ustawienie ' . $c('gosposia_scan_limit') . ', najwyżej 5000). '
                . 'Używa ' . $c('BODY.PEEK') . ', więc nie oznacza wiadomości jako przeczytanych.</li>'
                . '<li>Odfiltrowuje białą listę, resztę ocenia AI w paczkach po 40 nadawców. Nadawców ocenionych z pewnością co najmniej 0,7 zaznacza od razu.</li>'
                . '<li>Przenosi do Kosza tylko wiadomości z listy, którą osoba widziała i zatwierdziła (' . $c('UID MOVE') . ' albo kopia, flaga i '
                . $c('UID EXPUNGE') . ' tylko dla tych wiadomości). Nigdy nie wywołuje zwykłego ' . $c('EXPUNGE') . ' ani ' . $c('CLOSE') . '.</li></ul>'),

            $t('<h2>AI</h2>'
                . '<p>Ocena idzie przez Claude (Anthropic API). Klucz: ustawienie ' . $c('anthropic_api_key') . ', ten sam co w innych funkcjach AI SZO, '
                . 'awaryjnie zmienna ' . $c('ANTHROPIC_API_KEY') . '. Model: ustawienie ' . $c('gosposia_model') . ', potem zmienna ' . $c('ANTHROPIC_GOSPOSIA_MODEL')
                . ', domyślnie ' . $c('claude-haiku-5-5') . '.</p>'
                . '<p>Do AI idą tylko metadane: adres i nazwa nadawcy, liczba wiadomości, do 5 tematów (po 120 znaków) i nagłówki listy mailingowej. '
                . 'Bez treści i załączników. Gdy API nie odpowiada (brak klucza, limit, błąd serwera), Gosposia ocenia nadawców prostymi regułami, więc działa dalej.</p>'),

            $t('<h2>Dane</h2><ul>'
                . '<li>Hasło do poczty: w sesji, zaszyfrowane. Po zaznaczeniu „Zapamiętaj” także w tabeli ' . $c('gosposia_accounts') . ', zaszyfrowane. '
                . '„Odłącz pocztę” usuwa je z sesji i z bazy. Hasło nie trafia do dzienników.</li>'
                . '<li>Biała lista: tabela ' . $c('gosposia_whitelist') . '. Wpisy globalne (urzędy ' . $c('.gov.pl') . ', ' . $c('feer.org.pl')
                . ', dostawca poczty, banki) i prywatne listy użytkowników. Domena obejmuje subdomeny.</li>'
                . '<li>Ślad: ' . $c('audit_logs') . ', akcje ' . $c('gosposia.*') . ': kto, kiedy, ile wiadomości i od jakich adresów. Bez tematów i treści.</li>'
                . '<li>Powiadomienia e-mail do osoby: start przeglądu, wynik przeglądu i podsumowanie sprzątania. Same liczby, bez tematów i adresów nadawców.</li></ul>'),

            $t('<h2>Typowe problemy</h2><ul>'
                . '<li>Logowanie do poczty się nie udaje: najczęściej osoba wpisuje hasło do SZO zamiast hasła do poczty. Niech sprawdzi je, logując się do poczty w przeglądarce.</li>'
                . '<li>Brak połączenia: sprawdź, czy serwer widzi ' . $c('imap.dpoczta.pl:993') . '.</li>'
                . '<li>Ocena wygląda gorzej niż zwykle: AI mogło być niedostępne i zadziałały reguły awaryjne. Sprawdź klucz API.</li>'
                . '<li>Ktoś chce odzyskać wiadomości: są w Koszu skrzynki. Gosposia niczego nie usuwa na stałe.</li></ul>'),

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Gosposia ma ludzkie imię tak jak Mietek, który pilnuje nieużywanych kont, i Staszek, który pilnuje podejrzanych logowań.</p>'],
        ], true],
    ];
}
