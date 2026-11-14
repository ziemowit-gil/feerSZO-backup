<?php
/**
 * modules/it_portal/lib/itPortalAnkieterSeed.php — usługa „Ankieter” w Portalu Usług IT (/uslugi-it/uslugi/ankieter).
 * Polecenie 2026-11-14: dokumentacja IT systemu ankiet i formularzy Ankieter (https://formularz.feer.org.pl, Laravel 13 na MyDevil),
 * z naciskiem na logowanie jednokrotne przez Logto, „w feerSZO pod /uslugi-it”. Treść źródłowa przygotowana w sesji wdrożenia Ankietera
 * (docs/uslugi-it/ankieter.md); fakty (adresy, ścieżki, polecenia) bez zmian, forma wg zasad pomocy: bez tabel i myślników,
 * nazwy przycisków w „cudzysłowie”, polecenia w <pre><code>.
 * Artykuły: dla wszystkich (co to jest, funkcje, role) i dla IT (SSO, poczta i Nextcloud, serwer, kopie i bezpieczeństwo, problemy).
 * Artykuły „Dla IT” mają it_only = 1: treść widzą administratorzy i konta z rolą „IT” (users.it_staff), pozostali komunikat.
 * Bezpieczny do ponownego uruchomienia; „Zobacz też” między artykułami Ankietera jest dopisywane, cudze powiązania zostają.
 * Używają: migracja '2026-11_it_portal_ankieter', cli/it_portal_ankieter_seed.php.
 */

/** @return list<string> */
function itPortalAnkieterApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $f = 'https://formularz.feer.org.pl';
    $intro = itPortalSanitizeHtml('<p>Ankieter to system ankiet i formularzy Fundacji, dostępny pod adresem <a href="' . $f . '">formularz.feer.org.pl</a>. '
        . 'Ankiety służą do badań, a formularze zbierają zgłoszenia, na przykład zapisy na szkolenia.</p>'
        . '<p>Zacznij od artykułu „Ankieter: ankiety i formularze Fundacji”. Pozostałe artykuły są dla działu IT: logowanie jednokrotne przez Logto, '
        . 'poczta, Nextcloud, serwer, kopie zapasowe i rozwiązywanie problemów. Jeśli czegoś brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>');

    $rep = [];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'ankieter'");
    if ($dryRun) {
        $rep[] = 'usługa ankieter: ' . ($cat ? 'aktualizacja wstępu' : 'nowa');
        foreach (itPortalAnkieterArticles($app) as $slug => [$title]) $rep[] = "ankieter/{$slug} — {$title}";
        return $rep;
    }
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Ankieter', 'slug' => 'ankieter', 'icon' => 'document', 'sort_order' => 27, 'is_active' => 1,
            'tile_group' => 'Pliki, dokumenty i zadania',
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }

    $ids = []; $i = 0;
    foreach (itPortalAnkieterArticles($app) as $slug => [$title, $keywords, $blocks, $pinned, $group]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10, 'menu_group' => $group, 'it_only' => $group === 'Dla IT' ? 1 : 0, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $ids[] = $id;
        $rep[] = "ankieter/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ", #{$id})";
    }
    // „Zobacz też”: każdy artykuł Ankietera wskazuje pozostałe
    foreach ($ids as $id) {
        $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$id]);
        $rel = array_values(array_unique(array_merge(itPortalIds($r['related'] ?? '[]'), array_values(array_diff($ids, [$id])))));
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($rel), $id]);
    }
    return $rep;
}

/** Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty, grupa w menu]. */
function itPortalAnkieterArticles(string $app): array {
    $app = rtrim($app, '/');
    $f  = 'https://formularz.feer.org.pl';
    $b  = $app . '/uslugi-it/uslugi/ankieter/';
    $hd = $app . '/helpdesk/new.php';
    $c   = static fn(string $s): string => '<code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code>';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $g1 = 'Dla wszystkich'; $g2 = 'Dla IT';

    return [
        'ankieter-podstawy' => ['Ankieter: ankiety i formularze Fundacji',
            'ankieter, ankieta, ankiety, formularz, formularze, formularz.feer.org.pl, badanie, zapisy, zgłoszenia, nps, ocena szkolenia, wyniki, wykresy, eksport, xlsx, csv, administrator, respondent, wcag', [
            ['type' => 'text', 'html' => '<p>Ankieter to system ankiet i formularzy FEER, dostępny pod adresem <a href="' . $f . '">' . $f . '</a>. '
                . 'Ankiety służą do badań. Domyślnie są anonimowe, z wykresami i podsumowaniami. Formularze zbierają zgłoszenia, '
                . 'na przykład zapisy na szkolenia, z listą wpisów i załącznikami. Interfejs respondenta i panel spełniają WCAG 2.2 AA.</p>'
                . '<p>Panel jest pod adresem ' . $c('/admin') . ', a strona logowania pod ' . $c('/logowanie') . '.</p>'],
            ['type' => 'text', 'html' => '<h2>Funkcje</h2><ul>'
                . '<li>Kreator z 12 typami pól, sekcjami, logiką warunkową i walidacją (e-mail, telefon, PESEL, NIP, wzorce). '
                . 'Kolejność zmienia się przyciskami, co działa także z klawiatury.</li>'
                . '<li>Szablony: pusta ankieta, pusty formularz, NPS, ocena szkolenia, zapisy, kontakt.</li>'
                . '<li>Dostęp do ankiety: publiczny, wspólne hasło, tylko zalogowani (jedno wypełnienie na konto), '
                . 'jednorazowe linki w zaproszeniach e-mail z przypomnieniami.</li>'
                . '<li>Wyniki: wykresy z tabelami danych, NPS, filtry i zapisane kwerendy, lista zgłoszeń.</li>'
                . '<li>Eksport wybranych pól do XLSX, ODS, CSV i JSON. Opcjonalny automatyczny zapis wyników do Nextclouda.</li>'
                . '<li>Ustawienia w panelu: logowanie SSO, poczta SMTP z przyciskiem „Testuj wysyłkę maili”, użytkownicy i role.</li>'
                . '</ul>'],
            ['type' => 'text', 'html' => '<h2>Role</h2>'
                . '<p>Administrator tworzy ankiety, widzi wyniki, zarządza kontami i ustawieniami.</p>'
                . '<p>Respondent potrzebuje konta tylko do ankiet „dla zalogowanych”. Nie widzi panelu.</p>'
                . '<p>Pierwszego administratora zakłada IT (zobacz <a href="' . $b . 'serwer-i-wdrozenie">Ankieter: serwer MyDevil, cron i wdrożenie</a>, część „Pierwsze uruchomienie”). '
                . 'Kolejnych dodaje się w panelu (Użytkownicy) albo automatycznie przez SSO z rolą z Logto.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Potrzebujesz konta administratora w Ankieterze albo coś nie działa? Napisz do <a href="' . $hd . '">Helpdesku IT</a>.</p>'],
        ], false, $g1],

        'logowanie-sso-logto' => ['Ankieter: logowanie jednokrotne (SSO) przez Logto',
            'ankieter, sso, logowanie jednokrotne, logto, logto-admin, oidc, openid connect, client id, client secret, app id, app secret, redirect uri, adres powrotu, ankieter-admin, rola, authentik, microsoft 365, google, nextcloud, pkce', [
            ['type' => 'text', 'html' => '<p>Logto działa na MyDevil. Konsola administracyjna jest pod <a href="https://logto-admin.feer.org.pl">https://logto-admin.feer.org.pl</a>, '
                . 'a punkt logowania pod <a href="https://logto.feer.org.pl">https://logto.feer.org.pl</a>. '
                . 'Do Ankietera można się logować hasłem albo przez Logto, a opcjonalnie także przez Authentik, Microsoft 365, Google i Nextcloud.</p>'],
            ['type' => 'text', 'html' => '<h2>Krok 1. Aplikacja w Logto</h2><ol>'
                . '<li>Zaloguj się do konsoli <a href="https://logto-admin.feer.org.pl">https://logto-admin.feer.org.pl</a>.</li>'
                . '<li>Wybierz „Applications → Create application”. Jako typ wybierz „Traditional web” (aplikacja serwerowa z sekretem).</li>'
                . '<li>Nazwa: ' . $c('Ankieter') . '.</li>'
                . '<li>W ustawieniach aplikacji wpisz:<ul>'
                . '<li>„Redirect URIs”: ' . $c('https://formularz.feer.org.pl/logowanie/sso/logto/powrot') . '</li>'
                . '<li>„Post sign-out redirect URIs”: ' . $c('https://formularz.feer.org.pl/logowanie') . '</li>'
                . '<li>„CORS allowed origins”: zostaw puste (logowanie odbywa się po stronie serwera).</li></ul></li>'
                . '<li>Zapisz. Skopiuj trzy wartości z sekcji „Endpoints &amp; Credentials”:<ul>'
                . '<li>„Issuer endpoint”: ' . $c('https://logto.feer.org.pl/oidc') . '</li>'
                . '<li>„App ID” (to client ID)</li>'
                . '<li>„App secret” (to client secret)</li></ul></li>'
                . '</ol>'],
            ['type' => 'text', 'html' => '<h2>Krok 2. Rola administratora (opcjonalnie)</h2><ol>'
                . '<li>W Logto wybierz „Roles → Create role”, typ „User”, nazwa ' . $c('ankieter-admin') . '.</li>'
                . '<li>Przypisz rolę osobom, które mają zarządzać ankietami („User management → użytkownik → Roles”).</li>'
                . '<li>Ankieter prosi o zakres ' . $c('roles') . ', więc rola przychodzi w tokenie w polu ' . $c('roles') . '.</li>'
                . '</ol>'],
            ['type' => 'text', 'html' => '<h2>Krok 3. Konfiguracja w Ankieterze</h2><ol>'
                . '<li>Zaloguj się jako administrator: <a href="' . $f . '/admin">' . $f . '/admin</a>.</li>'
                . '<li>Wejdź w „Ustawienia → Logowanie SSO → Logto” i wypełnij pola:<ul>'
                . '<li>zaznacz „Pokazuj na stronie logowania”,</li>'
                . '<li>„Napis na przycisku”: na przykład ' . $c('Konto FEER') . ',</li>'
                . '<li>„Adres wystawcy”: ' . $c('https://logto.feer.org.pl/oidc') . ',</li>'
                . '<li>„Client ID” i „Client secret”: wartości z Logto.</li></ul></li>'
                . '<li>Kliknij „Zapisz”, a potem „Sprawdź połączenie”. Poprawny wynik to komunikat „Połączenie działa”.</li>'
                . '<li>W sekcji „Zasady” ustaw:<ul>'
                . '<li>„Dozwolone domeny e-mail”: ' . $c('feer.org.pl') . ' (tylko konta organizacji),</li>'
                . '<li>„Rola administratora (Logto)”: ' . $c('ankieter-admin') . ',</li>'
                . '<li>„Zakładaj konto przy pierwszym logowaniu”: włączone, jeśli każdy pracownik może wypełniać ankiety dla zalogowanych.</li></ul></li>'
                . '<li>Wyloguj się i sprawdź przycisk „Zaloguj się przez Konto FEER” na stronie ' . $c('/logowanie') . '.</li>'
                . '</ol>'],
            ['type' => 'text', 'html' => '<h2>Jak to działa i bezpieczeństwo</h2><ul>'
                . '<li>Przepływ OpenID Connect: Authorization Code z PKCE oraz sprawdzanie ' . $c('state') . ', ' . $c('nonce') . ', wystawcy (' . $c('iss') . '), '
                . 'odbiorcy (' . $c('aud') . ') i ważności (' . $c('exp') . ').</li>'
                . '<li>Sekret klienta jest zaszyfrowany w bazie kluczem aplikacji (' . $c('APP_KEY') . ') i nie wraca do formularza ustawień.</li>'
                . '<li>Konto jest wiązane na stałe z identyfikatorem osoby w Logto. Istniejące konto z tym samym e-mailem zostanie '
                . 'powiązane tylko przy potwierdzonym adresie albo ograniczonych domenach. To chroni przed przejęciem konta.</li>'
                . '<li>Rola administratora jest przez SSO tylko nadawana. Odebranie roli w Logto nie odbiera jej w Ankieterze, '
                . 'więc zrób to ręcznie w „Użytkownicy”.</li>'
                . '<li>Logowanie hasłem działa dalej i jest drogą awaryjną, gdyby Logto było niedostępne.</li>'
                . '</ul>'],
            ['type' => 'text', 'html' => '<h2>Inni dostawcy</h2>'
                . '<p>Ten sam ekran obsługuje Authentik (' . $c('https://<authentik>/application/o/<aplikacja>/') . '), Microsoft 365 '
                . '(' . $c('https://login.microsoftonline.com/<ID-dzierżawy>/v2.0') . '), Google i Nextcloud (aplikacja „OIDC Identity Provider”). '
                . 'Adres powrotu zawsze ma postać ' . $c('https://formularz.feer.org.pl/logowanie/sso/<dostawca>/powrot') . '. '
                . 'Można włączyć kilku dostawców naraz, a każdy dostaje własny przycisk.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Adres powrotu w Logto musi dokładnie pasować do adresu Ankietera: https i bez ukośnika na końcu. '
                . 'Inaczej przy logowaniu pojawi się „Sesja logowania wygasła”. Więcej w artykule <a href="' . $b . 'rozwiazywanie-problemow">Ankieter: rozwiązywanie problemów</a>.</p>'],
        ], false, $g2],

        'poczta-i-nextcloud' => ['Ankieter: poczta i zapis wyników w Nextcloud',
            'ankieter, poczta, smtp, mail, maile, testuj wysyłkę maili, kolejka, zaproszenia, przypomnienia, powiadomienia, nextcloud, nc.feer.org.pl, xlsx, wyślij do nextcloud, hasło aplikacji, env', [
            ['type' => 'text', 'html' => '<h2>Poczta</h2>'
                . '<p>Ustawienia są w „Ustawienia → Poczta e-mail”: serwer SMTP, port, szyfrowanie, użytkownik, hasło i nadawca. '
                . 'Hasło jest szyfrowane. Przycisk „Testuj wysyłkę maili” wysyła wiadomość na wskazany adres od razu, bez kolejki, '
                . 'więc ewentualny błąd widać na ekranie.</p>'
                . '<p>Maile (zaproszenia, przypomnienia, powiadomienia o nowych wpisach) idą przez kolejkę. Na MyDevil kolejkę '
                . 'przetwarza cron co minutę (zobacz „Zadania cykliczne” w artykule <a href="' . $b . 'serwer-i-wdrozenie">Ankieter: serwer MyDevil, cron i wdrożenie</a>). '
                . 'Powiadomienia nie zawierają treści odpowiedzi.</p>'],
            ['type' => 'text', 'html' => '<h2>Nextcloud</h2>'
                . '<p>Wyniki mogą trafiać do ' . $c('nc.feer.org.pl') . ' jako plik XLSX (' . $c('Ankieter/<adres-ankiety>/<adres-ankiety>.xlsx') . '). '
                . 'Konfiguracja w ' . $c('.env') . ':</p>'
                . $pre("NEXTCLOUD_ENABLED=true\nNEXTCLOUD_URL=https://nc.feer.org.pl\nNEXTCLOUD_USERNAME=<konto techniczne>\n"
                    . "NEXTCLOUD_APP_PASSWORD=<hasło aplikacji z Ustawienia → Bezpieczeństwo>\nNEXTCLOUD_FOLDER=Ankieter")
                . '<p>Zapis włącza się dla każdej ankiety osobno („Udostępnianie i ustawienia → Zapisuj wyniki w Nextcloud”) '
                . 'albo uruchamia ręcznie przyciskiem „Wyślij do Nextcloud”.</p>'],
        ], false, $g2],

        'serwer-i-wdrozenie' => ['Ankieter: serwer MyDevil, cron i wdrożenie',
            'ankieter, mydevil, s89, serwer, hosting, devil, php84, open_basedir, htaccess, addtype, ssl, lets encrypt, cron, kolejka, queue, artisan, migrate, wdrożenie, deploy, mydevil.sh, laravel, sqlite, dns, cloudflare, feer-dns, github, pierwsze uruchomienie, env, app_key', [
            ['type' => 'text', 'html' => '<h2>Najważniejsze informacje</h2><ul>'
                . '<li>Adres: <a href="' . $f . '">' . $f . '</a> (panel: ' . $c('/admin') . ', logowanie: ' . $c('/logowanie') . ').</li>'
                . '<li>Serwer: MyDevil s89 (' . $c('s89.mydevil.net') . '), konto ' . $c('feer') . '.</li>'
                . '<li>Katalog: ' . $c('~/domains/formularz.feer.org.pl/app') . ', a ' . $c('public_html') . ' jest dowiązaniem do ' . $c('app/public') . '.</li>'
                . '<li>Technologia: Laravel 13, PHP 8.4, Blade, Alpine.js i Tailwind CSS.</li>'
                . '<li>Baza danych: SQLite w trybie WAL, ' . $c('app/database/database.sqlite') . ' (możliwa zmiana na MySQL).</li>'
                . '<li>DNS: ' . $c('formularz.feer.org.pl A 212.91.26.67') . ' (Cloudflare, bez proxy), zarządzany w repozytorium ' . $c('feer-dns') . '.</li>'
                . '<li>Logowanie: hasło lub SSO przez Logto (' . $c('logto.feer.org.pl') . '), opcjonalnie Authentik, Microsoft 365, Google, Nextcloud. '
                . 'Zobacz <a href="' . $b . 'logowanie-sso-logto">Ankieter: logowanie jednokrotne (SSO) przez Logto</a>.</li>'
                . '<li>Kod: GitHub, repozytorium ' . $c('ankieter') . '.</li>'
                . '</ul>'],
            ['type' => 'text', 'html' => '<h2>Hosting MyDevil: szczegóły</h2><ul>'
                . '<li>Wersję PHP dla WWW ustawia linia ' . $c('AddType application/x-httpd-php84 .php') . ' na początku ' . $c('app/public/.htaccess') . '. '
                . 'Skrypt wdrożenia dopisuje ją po każdej aktualizacji. Nie dodawaj jej do repozytorium, bo na innym serwerze '
                . 'pliki PHP byłyby pobierane zamiast wykonywane.</li>'
                . '<li>' . $c('open_basedir') . ' musi obejmować cały katalog domeny, bo kod leży poza ' . $c('public_html') . ':'
                . $pre('devil www options formularz.feer.org.pl php_openbasedir /usr/home/feer/domains/formularz.feer.org.pl:/tmp') . '</li>'
                . '<li>SSL: Let\'s Encrypt przez polecenie poniżej (działa, gdy DNS wskazuje już na 212.91.26.67):'
                . $pre('devil ssl www add 212.91.26.67 le le formularz.feer.org.pl') . '</li>'
                . '<li>Konsola PHP: zawsze ' . $c('/usr/local/bin/php84') . ', na przykład ' . $c('php84 artisan migrate --force') . '.</li>'
                . '</ul>'],
            ['type' => 'text', 'html' => '<h3>Zadania cykliczne (cron)</h3>'
                . $pre('* * * * * cd ~/domains/formularz.feer.org.pl/app && /usr/local/bin/php84 artisan queue:work --stop-when-empty --max-time=50 >/dev/null 2>&1')],
            ['type' => 'text', 'html' => '<h2>Wdrożenie aktualizacji</h2>'
                . '<p>Z katalogu projektu na komputerze z dostępem SSH (host ' . $c('mydevil') . ' w ' . $c('~/.ssh/config') . '):</p>'
                . $pre('./deploy/mydevil.sh')
                . '<p>Skrypt buduje assety, wysyła kod (bez ' . $c('.env') . ', bazy, logów i ' . $c('vendor') . '), instaluje zależności, uruchamia migracje, '
                . 'odświeża cache i restartuje kolejkę.</p>'],
            ['type' => 'text', 'html' => '<h2>Pierwsze uruchomienie (już wykonane 14.11.2026)</h2><ol>'
                . '<li>' . $c('devil www add formularz.feer.org.pl php') . ', a potem ' . $c('public_html') . ' jako dowiązanie do ' . $c('app/public') . '.</li>'
                . '<li>' . $c('.env') . ' utworzone z ' . $c('.env.example') . ': ' . $c('APP_ENV=production') . ', ' . $c('APP_DEBUG=false') . ', '
                . $c('APP_URL=https://formularz.feer.org.pl') . ', ' . $c('SESSION_SECURE_COOKIE=true') . ', ścieżka bazy SQLite. '
                . 'Klucz utworzony na serwerze przez ' . $c('php84 artisan key:generate') . '.</li>'
                . '<li>' . $c('php84 artisan migrate --force') . ', potem założenie konta administratora:'
                . $pre("php84 artisan tinker\n\$u = App\\Models\\User::create([...]); \$u->forceFill(['is_admin' => true])->save();") . '</li>'
                . '</ol>'],
        ], false, $g2],

        'kopie-i-bezpieczenstwo' => ['Ankieter: kopie zapasowe i bezpieczeństwo',
            'ankieter, kopia zapasowa, backup, sqlite, backup bazy, załączniki, storage, app_key, env, szyfrowanie, bezpieczeństwo, prywatność, csp, csrf, referrer-policy, limity prób, sha-256, anonimowe ankiety, rodo', [
            ['type' => 'text', 'html' => '<h2>Kopie zapasowe</h2>'
                . '<p>Wszystkie dane są w jednym pliku bazy i w katalogu załączników:</p>'
                . $pre("cd ~/domains/formularz.feer.org.pl/app\nsqlite3 database/database.sqlite \".backup '/home/feer/backup/ankieter-\$(date +%F).sqlite'\"\n"
                    . "tar czf ~/backup/ankieter-pliki-\$(date +%F).tgz storage/app/private")],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Bez pliku ' . $c('.env') . ', a dokładniej ' . $c('APP_KEY') . ', nie da się odczytać zaszyfrowanych ustawień '
                . '(sekrety SSO, hasło SMTP). Przechowuj kopię ' . $c('.env') . ' osobno, w bezpiecznym miejscu.</p>'],
            ['type' => 'text', 'html' => '<h2>Bezpieczeństwo i prywatność</h2><ul>'
                . '<li>Brak zewnętrznych skryptów, fontów i trackerów. Nagłówki CSP, ' . $c('Referrer-Policy: no-referrer') . ', ochrona CSRF.</li>'
                . '<li>Limity prób: wysyłka ankiety, hasło do ankiety, logowanie, SSO.</li>'
                . '<li>Tokeny zaproszeń przechowywane wyłącznie jako skrót SHA-256. Hasła do ankiet i kont są hashowane.</li>'
                . '<li>Ankiety anonimowe nie wiążą odpowiedzi z osobą, także przy zaproszeniach i logowaniu.</li>'
                . '<li>Załączniki leżą na prywatnym dysku i są dostępne tylko dla administratorów.</li>'
                . '</ul>'],
        ], false, $g2],

        'rozwiazywanie-problemow' => ['Ankieter: rozwiązywanie problemów',
            'ankieter, problem, błąd, błąd 500, biała strona, laravel.log, open_basedir, kod php, addtype, nie przychodzą maile, cron, crontab, nie udało się połączyć z dostawcą logowania, sesja logowania wygasła, sso, logto, adres powrotu', [
            ['type' => 'text', 'html' => '<p>Poniżej najczęstsze objawy i to, co wtedy sprawdzić.</p>'
                . '<h2>Biała strona albo błąd 500</h2>'
                . '<p>Sprawdź ' . $c('app/storage/logs/laravel.log') . ' oraz uprawnienia ' . $c('storage/') . ' i ' . $c('database/') . '.</p>'
                . '<h2>Komunikat open_basedir</h2>'
                . '<p>Sprawdź ustawienie ' . $c('php_openbasedir') . ' (część „Hosting MyDevil: szczegóły” w artykule <a href="' . $b . 'serwer-i-wdrozenie">Ankieter: serwer MyDevil, cron i wdrożenie</a>).</p>'
                . '<h2>Strona pokazuje kod PHP</h2>'
                . '<p>Brakuje linii ' . $c('AddType … php84') . ' w ' . $c('public/.htaccess') . '. Uruchom wdrożenie ponownie.</p>'
                . '<h2>Nie przychodzą maile</h2>'
                . '<p>Użyj przycisku „Testuj wysyłkę maili” i sprawdź, czy cron kolejki działa (' . $c('crontab -l') . ').</p>'
                . '<h2>„Nie udało się połączyć z dostawcą logowania”</h2>'
                . '<p>Kliknij „Sprawdź połączenie” w ustawieniach SSO. Adres wystawcy musi być identyczny jak w Logto.</p>'
                . '<h2>„Sesja logowania wygasła” przy SSO</h2>'
                . '<p>Adres powrotu w Logto musi dokładnie pasować (https, bez ukośnika na końcu).</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie pomogło? Zgłoś problem w <a href="' . $hd . '">Helpdesku IT</a> i dopisz, co już sprawdziłeś(-aś).</p>'],
        ], false, $g2],
    ];
}
