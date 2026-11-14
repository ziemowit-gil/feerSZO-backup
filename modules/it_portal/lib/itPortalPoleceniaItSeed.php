<?php
/**
 * modules/it_portal/lib/itPortalPoleceniaItSeed.php — usługa „Polecenia administracyjne” w Portalu Usług IT
 * (/uslugi-it/uslugi/polecenia-it): polecenia, które dział IT uruchamia na serwerze SZO (skrypty cli/*.php, occ Nextclouda,
 * ręczne uruchomienie agentów CRON). Polecenia użytkownika 2026-11-14: „Dodaj to do /uslugi-it”, „i ogólnie tego typu
 * polecenia dodawaj”, „i tylko dla IT”.
 *
 * Tylko dla IT: jedyną grupą „Dla kogo” usługi jest IT, więc jej artykuły (it_only = 0, „jak usługa”) widzą wyłącznie
 * administratorzy i konta z rolą IT (itPortalItOnlyCategoryIds, commit c98d12a3).
 * Nowe polecenie administracyjne = nowy artykuł albo akapit tutaj + nowa migracja w modules/selfrepairDB/logic/migrations.php.
 * Tekst prostym językiem (skill humanizer): bez myślników, bez tabel. Uruchamia migracja '2026-11_it_portal_polecenia_it'
 * i cli/it_portal_polecenia_it_seed.php.
 */

const ITP_POLECENIA_SLUG = 'polecenia-it';

/**
 * Zasiew: usługa (nowa albo nadpisany wstęp) i artykuły (nowe albo nadpisane). Można uruchamiać wielokrotnie.
 * @return array{category:string, created:list<string>, updated:list<string>}
 */
function itPortalPoleceniaItApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'created' => [], 'updated' => []];
    $intro = itPortalSanitizeHtml(itPortalPoleceniaItIntro($app));
    $it = db_one("SELECT id FROM itp_audiences WHERE slug = 'it' OR name = 'IT' ORDER BY slug = 'it' DESC LIMIT 1");

    $cat = db_one("SELECT id, audiences FROM itp_categories WHERE slug = ?", [ITP_POLECENIA_SLUG]);
    if ($cat) {
        $catId = (int)$cat['id'];
        $rep['category'] = 'wstęp nadpisany';
        if (!$dryRun) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?",
                              [$intro, itPortalPlain($intro), $catId]);
    } else {
        $rep['category'] = 'usługa utworzona' . ($it ? ' (tylko dla grupy IT)' : ' (UWAGA: brak grupy IT, usługa bez grup)');
        $catId = 0;
        if (!$dryRun) {
            $sort = (int)(db_one("SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM itp_categories")['s'] ?? 100);
            $catId = itPortalCategorySave(['name' => 'Polecenia administracyjne', 'slug' => ITP_POLECENIA_SLUG, 'icon' => 'server',
                'sort_order' => $sort, 'is_active' => 1, 'audiences' => $it ? [(int)$it['id']] : [], 'intro_html' => $intro]);
        }
    }

    $i = 0;
    foreach (itPortalPoleceniaItArticles($app) as $slug => [$title, $keywords, $blocks, $pinned]) {
        $old = $catId ? db_one("SELECT id FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
        $rep[$old ? 'updated' : 'created'][] = ITP_POLECENIA_SLUG . "/{$slug} — {$title}";
        if ($dryRun) continue;
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10, 'it_only' => 0], $old ? (int)$old['id'] : 0);   // 0 = jak usługa, czyli tylko dla IT
    }
    return $rep;
}

/** Polecenie w bloku kodu (wywołanie przez SSH, tak jak się je wpisuje). */
function itPortalPoleceniaPre(string $cmd): string {
    return '<pre><code>' . htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8') . '</code></pre>';
}

/** Polecenie SZO na serwerze: ssh mydevil 'cd ~/feerSZO && …'. */
function itPortalPoleceniaSzo(string $cmd): string {
    return itPortalPoleceniaPre("ssh mydevil 'cd ~/feerSZO && " . $cmd . "'");
}

function itPortalPoleceniaItIntro(string $app): string {
    return '<p>Polecenia dla działu IT, które uruchamia się na serwerze SZO. Każde z nich działa na systemie produkcyjnym, '
         . 'więc tam, gdzie to możliwe, najpierw uruchom je z <code>--dry-run</code> i przejrzyj, co zostanie zmienione.</p>'
         . '<p>Usługę widzą tylko administratorzy i osoby z rolą IT. Nowe polecenia dopisujemy tutaj na bieżąco. '
         . 'Od czego zacząć, opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/' . ITP_POLECENIA_SLUG . '/jak-uruchamiac">Jak uruchamiać polecenia na serwerze SZO</a>.</p>';
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty].
 * @return array<string, array{0:string,1:string,2:array,3:bool}>
 */
function itPortalPoleceniaItArticles(string $app): array {
    $szo = 'itPortalPoleceniaSzo';
    $pre = 'itPortalPoleceniaPre';
    $text = static fn(string $h): array => ['type' => 'text', 'html' => $h];
    $warn = static fn(string $h): array => ['type' => 'callout', 'kind' => 'warn', 'html' => $h];
    $info = static fn(string $h): array => ['type' => 'callout', 'kind' => 'info', 'html' => $h];
    $sso   = $app . '/ssoadmin';
    $seed  = $app . '/seeder-test';
    $notif = $app . '/tasks/notification_settings.php?view=admin';
    $intake = $app . '/tasks/router.php?_route=/skrzynka-obszaru';
    $files = $app . '/tasks/files.php';

    return [
        'jak-uruchamiac' => ['Jak uruchamiać polecenia na serwerze SZO',
            'serwer, ssh, mydevil, terminal, konsola, cli, polecenie, skrypt, dry-run, podgląd, wdrożenie, git, feerSZO', [
            $text('<p>SZO działa na serwerze MyDevil (s89.mydevil.net, konto feer). Kod jest w katalogu <code>~/feerSZO</code> '
                . 'i obsługuje domeny szo, kursant i ti.feer.org.pl. Na komputerze z kluczem SSH skrót <code>mydevil</code> prowadzi od razu na to konto, '
                . 'więc każde polecenie z tej usługi wpisujesz w terminalu na swoim komputerze w takiej postaci:</p>'
                . $pre("ssh mydevil 'cd ~/feerSZO && php cli/nazwa.php --dry-run'")
                . '<p>Polecenia Nextclouda (<code>occ</code>) uruchamiasz tak samo, tylko bez przechodzenia do katalogu SZO:</p>'
                . $pre("ssh mydevil '~/nextcloud/bin/occ user:info szo-api'")),
            $text('<h2>Najpierw podgląd</h2>'
                . '<p>Większość poleceń ma przełącznik <code>--dry-run</code>. Polecenie wypisuje wtedy, co by zrobiło, i niczego nie zmienia. '
                . 'Przejrzyj listę, a potem uruchom to samo polecenie bez <code>--dry-run</code>. Gdy polecenie nie ma podglądu, artykuł o tym mówi.</p>'
                . '<p>Część poleceń jest też w SZO na stronie <a href="' . $seed . '">Seedery (dane testowe)</a>. Działają tam tak samo, '
                . 'a zgłoszenia i wpisy w historii zapisują się na zalogowanego administratora.</p>'),
            $text('<h2>Nowa wersja na serwerze</h2>'
                . '<p>Serwer sam pobiera kod z GitHuba kilka minut po każdej zmianie. Wersję na serwerze sprawdzisz tak:</p>'
                . $szo('git log -1 --oneline')
                . '<p>Jeśli polecenie opisane tutaj jeszcze nie istnieje na serwerze, odczekaj kilka minut i sprawdź wersję ponownie.</p>'),
            $warn('<p>MyDevil na chwilę blokuje połączenia SSH, gdy jest ich dużo w krótkim czasie. Kilka poleceń połącz w jednym wywołaniu, '
                . 'oddzielając je <code>&amp;&amp;</code>, zamiast łączyć się z serwerem kilka razy pod rząd.</p>'),
        ], true],

        'foldery-zadan-w-chmurze' => ['Foldery obszarów Zadań w chmurze',
            'zadania, obszar, obszary, folder, foldery, pliki, nextcloud, chmura, udostępnianie, administratorzy folderów, wsCloudAdmins, '
            . 'ws_cloud_shares, sharepoint, przenosiny, koszulki, ws_cloud_root, transfer-ownership', [
            $text('<p>Pliki obszarów z <a href="' . $files . '">modułu Zadań</a> leżą w chmurze Nextcloud, w folderze „Obszary zadań”. '
                . 'Każdy obszar ma tam swój folder, a w nim foldery wspólne i foldery zadań „Zadania/tytuł zadania (ZAD-numer)”. '
                . 'Właścicielem wszystkich jest konto API chmury, którym łączy się SZO (dziś szo-api).</p>'
                . '<p>Folder obszaru dostają w udostępnieniu osoby, które mają dostęp do obszaru w SZO, dopisane wprost albo przez zespół, '
                . 'jeśli mają konto w chmurze. Rola „viewer” daje tylko odczyt. Pozostałe role pozwalają też zmieniać, dodawać i usuwać pliki. '
                . 'Udostępnienia uzgadniają się przy zmianie członków obszaru i co godzinę.</p>'),
            $text('<h2>Administratorzy folderów</h2>'
                . '<p>Administrator folderów dostaje cały folder „Obszary zadań” ze wszystkimi uprawnieniami, także do dalszego udostępniania. '
                . 'Widzi w nim wszystkie obszary, również te założone później. Dziś są to konta administrator i ziemowit.gil.</p>'
                . '<p>Udostępnienie folderu obszaru dodane ręcznie w Nextcloudzie zniknie przy najbliższej synchronizacji, '
                . 'bo agent zdejmuje dostęp osobom spoza obszaru. Dlatego listę administratorów ustawia się poleceniem. Obecną listę pokaże:</p>'
                . $szo('php cli/wsCloudAdmins.php')
                . '<p>Dopisanie osoby (login w chmurze, kilka loginów po przecinku):</p>'
                . $szo('php cli/wsCloudAdmins.php --add=login.w.chmurze')
                . '<p><code>--remove=login</code> odbiera dostęp, <code>--set=login1,login2</code> zastępuje całą listę, '
                . 'a <code>--sync</code> tylko uzgadnia udostępnienia bez zmiany listy. Każda zmiana listy od razu uzgadnia udostępnienia. '
                . 'Gdy loginu nie ma w chmurze, polecenie niczego nie zmieni. To polecenie nie ma podglądu.</p>'),
            $text('<h2>Udostępnienia od razu</h2>'
                . '<p>Agent CRON ws_cloud_shares uzgadnia udostępnienia co godzinę. Żeby zrobić to od razu, uruchom go ręcznie. '
                . 'Z <code>--dry-run</code> wypisze tylko, co by zmienił:</p>'
                . $szo('php cron/agents/ws_cloud_shares.php --dry-run')
                . '<p>Bez zmian polecenie nic nie wypisuje. Osoba bez konta w chmurze zobaczy folder przy pierwszym przebiegu po założeniu konta.</p>'),
            $text('<h2>Przenosiny z SharePointa</h2>'
                . '<p>Foldery i pliki z SharePointa przeniesiono 14 listopada 2026 roku: 18 folderów i 2 pliki. Polecenie zostaje na wypadek, '
                . 'gdyby w bazie znalazło się jeszcze coś z SharePointa. Najpierw podgląd:</p>'
                . $szo('php cli/wsSharepointToCloud.php --dry-run')
                . '<p>Foldery przenoszą się bez Microsoftu. Do pobrania plików potrzebna jest działająca konfiguracja Microsoft 365. '
                . 'Gdy jej już nie ma, polecenie wypisze pliki z linkami do ręcznego pobrania.</p>'),
            $warn('<p>Nie zmieniaj konta API chmury w <a href="' . $sso . '#chmura">/ssoadmin</a> bez przygotowania. Foldery obszarów należą do konta szo-api, '
                . 'a SZO po zmianie konta pokaże błąd „Folder obszaru należy do konta…”. Najprościej przywrócić poprzednie konto. '
                . 'Przeniesienie plików na nowe konto poleceniem <code>occ files:transfer-ownership</code> zmienia ich ścieżki, '
                . 'więc trzeba wtedy poprawić także zapisy w bazie SZO.</p>'
                . '<p>Nazwę folderu głównego ustawia ustawienie <code>ws_cloud_root</code> (domyślnie „Obszary zadań”). Nie zmieniaj jej, gdy foldery już istnieją.</p>'),
        ], false],

        'chmura-konto-api' => ['Chmura: konto API, administratorzy i grupy',
            'nextcloud, chmura, occ, konto api, szo-api, konto techniczne, administrator, grupa admin, sub admin, 403, '
            . 'seedCloudTechAccount, grupy, zespoły, ssoCloudTeamsSync, nc_uid, logowanie jednokrotne, ssoNcUidSync, migracja', [
            $text('<p>SZO łączy się z Nextcloudem kontem technicznym szo-api. Login i hasło są w <a href="' . $sso . '#chmura">/ssoadmin</a>, na karcie Chmura. '
                . 'Konto musi być w grupie admin w Nextcloudzie. Inaczej chmura odrzuca polecenia SZO komunikatem '
                . '„Logged in account must be at least a sub admin”, a nie działają wtedy grupy, zakładanie kont ani reset haseł w chmurze.</p>'),
            $text('<h2>Konto techniczne</h2>'
                . '<p>Stan konta technicznego i bieżące ustawienia SZO pokaże:</p>'
                . $szo('php cli/seedCloudTechAccount.php --status')
                . '<p><code>--create</code> zakłada konto (domyślnie szo-api, inny login podasz w <code>--uid=</code>). Jeśli obecne konto API jest administratorem chmury, '
                . 'polecenie od razu przełącza SZO na nowe konto. Jeśli nie jest, wypisze jednorazowo polecenie <code>occ</code> do uruchomienia, '
                . 'a po nim <code>--activate</code> sprawdzi logowanie i przełączy SZO. <code>--forget</code> porzuca konto czekające na aktywację. '
                . 'To samo zrobisz na stronie <a href="' . $seed . '">Seedery (dane testowe)</a>.</p>'),
            $text('<h2>Administratorzy w Nextcloudzie</h2>'
                . '<p>Nextcloud działa na tym samym serwerze. Dane konta i jego grupy pokaże:</p>'
                . $pre("ssh mydevil '~/nextcloud/bin/occ user:info login'")
                . '<p>Dopisanie do grupy admin i odebranie tej grupy:</p>'
                . $pre("ssh mydevil '~/nextcloud/bin/occ group:adduser admin login'")
                . $pre("ssh mydevil '~/nextcloud/bin/occ group:removeuser admin login'")
                . '<p>Konto migracja dopisano do grupy admin 14 listopada 2026 roku, żeby naprawić błąd 403. SZO już go nie używa, '
                . 'więc można je z tej grupy usunąć.</p>'),
            $text('<h2>Grupy z SZO</h2>'
                . '<p>Zespoły i obszary z Zadań, Działania i jednostki organizacyjne mają w chmurze grupy z przedrostkami szo-zespol-, szo-obszar-, '
                . 'szo-dzialanie- i szo-jednostka-. Agent CRON sso_cloud_teams uzgadnia je co 2 minuty. Ręcznie, najpierw w podglądzie:</p>'
                . $szo('php cli/ssoCloudTeamsSync.php --dry-run')
                . '<p>Polecenie przy okazji sprawdza połączenie: gdy konto API nie działa, od razu wypisze błąd logowania. '
                . 'To samo jest w <a href="' . $sso . '#zespoly">/ssoadmin</a>.</p>'),
            $text('<h2>Login w chmurze przy logowaniu jednokrotnym</h2>'
                . '<p>Nextcloud przy logowaniu jednokrotnym rozpoznaje osobę po loginie zapisanym w koncie Logto (custom_data.nc_uid). '
                . 'Nowe konta dostają go same. Brakujące uzupełni:</p>'
                . $szo('php cli/ssoNcUidSync.php --dry-run')
                . '<p>Polecenie nie zmienia haseł i niczego nie wysyła.</p>'),
        ], false],

        'poczta-zadan' => ['Poczta Zadań: odpowiedzi e-mailem i skrzynki obszarów',
            'zadania, poczta, e-mail, imap, smtp, powiadomienia, odpowiedź e-mailem, komentarz, skrzynka obszaru, tasks_mail_reply, '
            . 'tasks_mail_intake, cron, graph, microsoft', [
            $text('<p>Zadania nie korzystają już z Microsoft Graph. Powiadomienia idą przez kolejkę poczty SZO, czyli SMTP nowej poczty, '
                . 'a odpowiedzi e-mailem i skrzynki obszarów są czytane przez IMAP. Pierwsze sprawdzenie skrzynki po podłączeniu tylko zapamiętuje jej koniec, '
                . 'więc system bierze pod uwagę wiadomości, które przyjdą później.</p>'),
            $text('<h2>Odpowiedź e-mailem jako komentarz</h2>'
                . '<p>Skrzynkę odpowiedzi ustawia administrator w <a href="' . $notif . '">Zadania, Powiadomienia, widok administratora</a>: '
                . 'adres skrzynki, serwer IMAP (puste pole oznacza imap.dpoczta.pl), login i hasło. Zapis od razu sprawdza logowanie. '
                . 'Cron tasks_mail_reply czyta skrzynkę co 5 minut. Ręcznie, bez zapisywania komentarzy:</p>'
                . $szo('php cron/tasks_mail_reply.php --dry-run')
                . '<p>„uwaga: wyłączone” w wyniku znaczy, że opcja jest wyłączona albo brakuje danych skrzynki. Inny komunikat po słowie „uwaga” to zwykle błąd logowania do skrzynki.</p>'),
            $text('<h2>Skrzynka obszaru</h2>'
                . '<p>Skrzynkę, z której wiadomości stają się zadaniami, ustawia lider obszaru na stronie <a href="' . $intake . '">Skrzynka obszaru</a>. '
                . 'Cron tasks_mail_intake sprawdza wszystkie skrzynki obszarów co 5 minut. Ręcznie:</p>'
                . $szo('php cron/tasks_mail_intake.php --dry-run')
                . '<p>Wynik pokazuje liczbę nowych zadań, komentarzy, pominiętych wiadomości i skrzynek z błędem. '
                . 'Treść błędu widać też na stronie ustawień skrzynki, pod przyciskiem „Zapisz i sprawdź połączenie”.</p>'),
        ], false],

        'hasla-i-konta-testowe' => ['Hasła osoby i konto testowe kursanta',
            'reset haseł, hasło, hasła, resetHasel, sms, logowanie jednokrotne, chmura, poczta, dhosting, kursant testowy, '
            . 'seedKursantPoczta, poczta kursanta, stud', [
            $text('<h2>Reset wszystkich haseł osoby</h2>'
                . '<p>Polecenie zmienia hasła osoby po jej adresie e-mail z SZO: hasło SZO, wspólne hasło logowania jednokrotnego i chmury '
                . 'oraz osobne hasło poczty. Bez <code>--apply</code> tylko pokazuje, jakie konta osoba ma:</p>'
                . $szo('php cli/resetHasel.php jan.kowalski@feer.org.pl')
                . '<p>Z <code>--apply</code> ustawia losowe hasła i wysyła je SMS-em na numer z konta albo z umowy. Osoba dostaje też e-mail bez haseł, '
                . 'a w Helpdesku zostaje notatka. <code>--tylko=sso,szo</code> zawęża zmianę, <code>--zapytaj</code> pozwala wpisać hasła ręcznie, '
                . 'a <code>--showpassword</code> pokazuje je na ekranie, co jest potrzebne, gdy osoba nie ma numeru komórkowego.</p>'),
            $text('<h2>Testowy kursant poczty</h2>'
                . '<p>Do sprawdzania „Poczty kursanta” na prawdziwych kontaktach służy testowy kursant. Stan pokaże:</p>'
                . $szo('php cli/seedKursantPoczta.php status')
                . '<p><code>create</code> zakłada albo aktualizuje kursanta z podanym e-mailem i telefonem, a <code>delete</code> usuwa go razem z grupą testową. '
                . 'Ten kursant nie jest wirtualny, więc e-maile i SMS-y naprawdę wychodzą. Pełną listę opcji wypisuje polecenie bez argumentów.</p>'),
        ], false],

        'tresci-pomocy-it' => ['Treści Pomocy IT z poleceń',
            'pomoc it, portal usług it, uslugi-it, seed, zasiew, artykuły, it_portal, migracja, aktualizacja treści', [
            $text('<p>Część artykułów Pomocy IT powstaje z kodu. Każdy taki zestaw ma polecenie, które zapisuje treść od nowa, '
                . 'na przykład po ręcznej zmianie, którą trzeba cofnąć. Nowe treści wchodzą na serwer same, raz, przy aktualizacji SZO.</p>'
                . '<p>Ta usługa ma swoje polecenie:</p>'
                . $szo('php cli/it_portal_polecenia_it_seed.php --dry-run')
                . '<p>Pozostałe mają nazwy <code>cli/it_portal_…_seed.php</code>, na przykład <code>cli/it_portal_hasla_seed.php</code> dla usługi „Konto i hasła”. '
                . 'Listę pokaże:</p>'
                . $szo('ls cli/it_portal_*'))
            ,
            $info('<p>Polecenie nadpisuje artykuły ze swojego zestawu. Zmiany wprowadzone w nich ręcznie w panelu Portalu Usług IT znikną. '
                . 'Pozostałych artykułów usługi nie rusza.</p>'),
        ], false],
    ];
}
