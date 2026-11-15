<?php
/**
 * modules/it_portal/lib/itPortalKuferSeed.php — usługa „Menedżer haseł (Kufer)” w Portalu Usług IT (/uslugi-it/uslugi/menedzer-hasel).
 * Polecenie 2026-11-15: „dopisz pomoc do Passwords w Pomocy IT” po instalacji aplikacji Passwords (marius-wieschollek, 2026.11.20)
 * w Nextcloudzie nc.feer.org.pl; krótki adres kufer.feer.org.pl (przekierowanie). Opis serwera: ~/nextcloud/README.md na MyDevil s89.
 * Nazwy przycisków wzięte z polskiego tłumaczenia aplikacji (apps/passwords/l10n/pl.js, settings/pl.json, apps/pl.json), także
 * niezręczne („Śmieci” zamiast kosza, „Url” zamiast strony), żeby zgadzały się z ekranem.
 * Fakty z tłumaczeń: udostępnienie wyłącza szyfrowanie end-to-end danego hasła (BatchActionShareDialogCseDisableWarning),
 * udostępnienie grupie trafia do każdej osoby z grupy osobno (ShareGroupShareWarning), uprawnienia „Może wyświetlać”, „Można edytować”, „Może dzielić”.
 * Bez zrzutów (produkcja wymaga logowania). Forma wg zasad pomocy: bez tabel i myślników; tekst przez skill humanizer.
 * Bezpieczny do ponownego uruchomienia. Używają: migracja '2026-11_it_portal_kufer', cli/it_portal_kufer_seed.php.
 */

/** @return list<string> */
function itPortalKuferApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $b = $app . '/uslugi-it/uslugi/menedzer-hasel/';
    $intro = itPortalSanitizeHtml('<p>Kufer to menedżer haseł Fundacji: aplikacja „Hasła” w naszej chmurze plików. Trzymasz w nim hasła do serwisów, '
        . 'z których korzystasz w pracy, i udostępniasz je osobom z zespołu. Wchodzisz przez <a href="https://kufer.feer.org.pl">kufer.feer.org.pl</a>, '
        . 'logujesz się kontem chmury. Zacznij od artykułu <a href="' . $b . 'pierwsze-kroki">Kufer: pierwsze kroki</a>.</p>');

    $rep = [];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'menedzer-hasel'");
    if ($dryRun) {
        $rep[] = 'usługa menedzer-hasel: ' . ($cat ? 'aktualizacja wstępu' : 'nowa');
        foreach (itPortalKuferArticles($app) as $slug => [$title]) $rep[] = "menedzer-hasel/{$slug} — {$title}";
        return $rep;
    }
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Menedżer haseł (Kufer)', 'slug' => 'menedzer-hasel', 'icon' => 'key', 'sort_order' => 26, 'is_active' => 1,
            'tile_group' => 'Konto i logowanie',
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }

    $ids = []; $i = 0;
    foreach (itPortalKuferArticles($app) as $slug => [$title, $keywords, $blocks, $group]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $slug === 'pierwsze-kroki' ? 1 : 0,
            'sort_order' => (++$i) * 10, 'menu_group' => $group, 'it_only' => $group === 'Dla IT' ? 1 : 0,
            'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $ids[] = $id;
        $rep[] = "menedzer-hasel/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ", #{$id})";
    }
    // „Zobacz też”: artykuły usługi nawzajem oraz logowanie do chmury i wysyłanie haseł przez Sekrety
    $extra = [];
    foreach ([['chmura-plikow', 'logowanie'], ['sekrety', 'wyslij-haslo']] as [$c, $s]) {
        $r = db_one("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$c, $s]);
        if ($r) $extra[] = (int)$r['id'];
    }
    foreach ($ids as $id) {
        $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$id]);
        $rel = array_values(array_unique(array_merge(itPortalIds($r['related'] ?? '[]'), array_values(array_diff($ids, [$id])), $extra)));
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($rel), $id]);
    }
    // Sekrety → Kufer (tylko pierwsze kroki, żeby nie zaśmiecać)
    if ($extra && isset($ids[0])) {
        foreach ($extra as $eid) {
            $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$eid]);
            $rel = array_values(array_unique(array_merge(itPortalIds($r['related'] ?? '[]'), [$ids[0]])));
            db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($rel), $eid]);
        }
    }
    return $rep;
}

/** Artykuły: slug => [tytuł, słowa kluczowe, bloki, grupa w menu]. */
function itPortalKuferArticles(string $app): array {
    $app = rtrim($app, '/');
    $hd  = $app . '/helpdesk/new.php';
    $b   = $app . '/uslugi-it/uslugi/menedzer-hasel/';
    $chm = $app . '/uslugi-it/uslugi/chmura-plikow/logowanie';
    $sek = $app . '/uslugi-it/uslugi/sekrety/wyslij-haslo';
    $kuf = '<a href="https://kufer.feer.org.pl">kufer.feer.org.pl</a>';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $t   = static fn(string $h): array => ['type' => 'text', 'html' => $h];
    $box = static fn(string $kind, string $h): array => ['type' => 'callout', 'kind' => $kind, 'html' => $h];
    $ul  = static fn(array $items): string => '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
    $step = static fn(string $title, string $h): array => ['title' => $title, 'html' => $h, 'images' => []];
    $a   = static fn(string $slug, string $title): string => '<a href="' . $b . $slug . '">' . $title . '</a>';
    $g1 = 'Dla wszystkich'; $g2 = 'Dla IT';

    return [
        'pierwsze-kroki' => ['Kufer: pierwsze kroki',
            'kufer, menedżer haseł, hasła, passwords, nextcloud, chmura, kufer.feer.org.pl, logowanie, menu, foldery, ulubione, najnowsze, '
            . 'udostępnione, tagi, śmieci, kosz, szukaj, gdzie trzymać hasła, bitwarden, keepass, lastpass', [
            $t('<p>Kufer to aplikacja „Hasła” w chmurze plików Fundacji. Zapisujesz w niej loginy i hasła do serwisów, z których korzystasz w pracy: '
                . 'paneli, profili w mediach społecznościowych, kont w urzędach i u dostawców. Hasła możesz udostępniać innym osobom z Fundacji, '
                . 'więc nikt nie musi ich już wysyłać mailem.</p>'
                . '<h2>Jak wejść</h2><p>Otwórz ' . $kuf . '. Jeśli nie jesteś zalogowany(-a) do chmury, zobaczysz jej stronę logowania. '
                . 'Logujesz się tym samym kontem co do chmury, opisuje to artykuł <a href="' . $chm . '">Logowanie do chmury plików</a>. '
                . 'Aplikację znajdziesz też w chmurze, w górnym menu, pod ikoną klucza i nazwą „Hasła”.</p>'
                . '<h2>Menu po lewej</h2>' . $ul([
                    '„Wszystkie”: wszystkie Twoje hasła i te, które ktoś Ci udostępnił.',
                    '„Foldery”: hasła poukładane w folderach, które sam(a) zakładasz.',
                    '„Najnowsze”: ostatnio dodane i zmieniane.',
                    '„Ulubione”: hasła oznaczone gwiazdką.',
                    '„Współdzielony”: hasła udostępnione Tobie („Udostępnione”) i przez Ciebie („Udostępnione przez Ciebie”).',
                    '„Tagi”: kolorowe etykiety, na przykład nazwa projektu albo działu.',
                    '„Bezpieczeństwo”: hasła słabe, powtórzone albo takie, które wyciekły. Więcej w ' . $a('kontrola-bezpieczenstwa', 'Kontroli bezpieczeństwa haseł') . '.',
                    '„Śmieci”: usunięte hasła. Tak aplikacja nazywa kosz. Stąd hasło przywrócisz albo usuniesz na zawsze.',
                ])
                . '<p>Na dole menu są „Ustawienia”, „Kopia zapasowa i przywracanie”, „Aplikacje i rozszerzenia” oraz „Pomoc”. '
                . 'Pole „Szukaj …” u góry przeszukuje nazwy, użytkowników i adresy stron.</p>'),
            $box('tip', '<p>Kufer służy do haseł, których używasz na co dzień. Gdy chcesz jednorazowo przekazać komuś hasło, także osobie spoza Fundacji, '
                . 'użyj <a href="' . $sek . '">Sekretów</a>.</p>'),
            ], $g1],

        'dodaj-haslo' => ['Kufer: dodawanie haseł i generator',
            'nowe hasło, dodaj hasło, zapisz hasło, generator, wygeneruj hasło, użytkownik, url, strona, notatki, pola własne, folder, nowy folder, '
            . 'tag, ulubione, kopiuj hasło, kopiuj użytkownika, otwórz url, edytuj, usuń, zmiany, historia, kod qr, kufer', [
            ['type' => 'wizard', 'title' => 'Jak dodać hasło', 'intro' => '', 'steps' => [
                $step('Zacznij nowe', '<p>Kliknij „+” u góry listy i wybierz „Nowe hasło”. Otworzy się okno „Utwórz hasło”.</p>'),
                $step('Wpisz dane', '<p>Wpisz nazwę (na przykład „Panel hostingu”), w polu „Użytkownik” login, a w polu „Url” adres strony logowania. '
                    . 'Gdy zakładasz nowe konto, kliknij przy polu „Hasło” ikonę generatora („Wygeneruj hasło”). Dostaniesz silne, losowe hasło.</p>'),
                $step('Dodaj resztę', '<p>W notatkach opisz, czego dotyczy konto. „Pola własne” przydają się na kody zapasowe, numer klienta albo PIN. '
                    . '„Dodaj tagi…” przypisuje etykiety.</p>'),
                $step('Zapisz', '<p>Kliknij „Zapisz”. Hasło pojawi się na liście, w folderze, który był otwarty.</p>'),
            ]],
            $t('<h2>Co robisz z hasłem na liście</h2>' . $ul([
                    'Kliknięcie w nazwę domyślnie kopiuje hasło do schowka (zmienisz to w „Ustawieniach”). Menu z trzema kropkami ma też „Kopiuj użytkownika”, „Kopiuj URL” i „Otwórz URL”.',
                    'Gwiazdka dodaje hasło do „Ulubionych”.',
                    '„Szczegóły” pokazują hasło, notatki, udostępnienia i „Zmiany”, czyli poprzednie wersje hasła. Z historii odczytasz stare hasło, gdy nowe nie działa.',
                    '„Edytuj” zmienia dane. „Edytuj jako nowy” tworzy kopię z poprawkami, a stara zostaje.',
                    '„Pokaż QrCode” wyświetla kod QR z hasłem do zeskanowania telefonem.',
                    'Hasło przeciągniesz myszą do innego folderu. Nowy folder założysz przez „+” i „Nowy folder”.',
                ])
                . '<h2>Generator</h2><p>Zasady generatora (długość, cyfry, znaki specjalne) ustawisz w „Ustawienia”, w części „Generator haseł”. '
                . 'Domyślne ustawienia wystarczą do większości serwisów. Gdy serwis nie przyjmuje znaków specjalnych, wyłącz je tam i wygeneruj hasło ponownie.</p>'),
            ], $g1],

        'udostepnianie' => ['Kufer: udostępnianie haseł zespołowi',
            'udostępnij, udostępnianie, współdzielony, udostępnione, zespół, grupa, uprawnienia, może wyświetlać, można edytować, może dzielić, '
            . 'data ważności, wygasa, cofnij dostęp, wspólne konto, facebook, instagram, hasło zespołu, kufer', [
            $t('<p>Hasło do wspólnego konta (na przykład profilu Fundacji w mediach społecznościowych) zapisuje jedna osoba i udostępnia je reszcie zespołu. '
                . 'Gdy ktoś zmieni hasło, inni od razu widzą nowe.</p>'),
            ['type' => 'wizard', 'title' => 'Jak udostępnić hasło', 'intro' => '', 'steps' => [
                $step('Otwórz udostępnianie', '<p>W menu z trzema kropkami przy haśle wybierz „Udostępnij”. Otworzą się szczegóły hasła na karcie „Udostępnienia”.</p>'),
                $step('Wybierz osobę', '<p>Wpisz imię, nazwisko albo login osoby z Fundacji i wybierz ją z listy.</p>'),
                $step('Ustaw uprawnienia', '<p>Domyślnie osoba „Może wyświetlać”. Zaznacz „Można edytować”, jeśli ma też zmieniać hasło, '
                    . 'i „Może dzielić”, jeśli może je przekazać dalej. W „Opcjach zaawansowanych” jest „Ustaw datę ważności”: '
                    . 'po tej dacie dostęp sam wygaśnie, co przydaje się przy zastępstwach i umowach na czas określony.</p>'),
                $step('Zapisz', '<p>Kliknij „Zapisz udział”. Osoba zobaczy hasło u siebie w „Współdzielony”, „Udostępnione” i dostanie powiadomienie.</p>'),
            ]],
            $t('<h2>Dobrze wiedzieć</h2>' . $ul([
                    'Kilka haseł naraz udostępnisz, zaznaczając je na liście i wybierając udostępnianie dla zaznaczonych.',
                    'Udostępnienie grupie trafia do każdej osoby, która jest w grupie w tej chwili. Osoba dodana do grupy później hasła nie dostanie: trzeba jej je udostępnić osobno.',
                    'Udostępnienie cofasz na tej samej karcie „Udostępnienia”, przy nazwisku osoby.',
                    'Gdy ktoś odchodzi z Fundacji albo kończy projekt, cofnij mu udostępnienia i zmień hasła do kont, które znał.',
                ])),
            $box('warn', '<p>Udostępnione hasło traci szyfrowanie end-to-end, jeśli było nim objęte; zostaje szyfrowanie po stronie serwera. '
                . 'Opisuje to ' . $a('szyfrowanie', 'Kufer: szyfrowanie i hasło szyfrowania') . '.</p>'),
            ], $g1],

        'szyfrowanie' => ['Kufer: szyfrowanie i hasło szyfrowania',
            'szyfrowanie, end-to-end, e2e, kompleksowe szyfrowanie, hasło szyfrowania, hasło główne, master password, szyfrowanie po stronie serwera, '
            . 'tryb szyfrowania klienta, zapomniałem hasła szyfrowania, odzyskanie, webauthn, kufer', [
            $t('<p>Kufer zawsze szyfruje hasła. Są dwa sposoby:</p>' . $ul([
                    'Szyfrowanie po stronie serwera (domyślne). Hasła są zaszyfrowane w bazie chmury, a odczytasz je po zwykłym zalogowaniu.',
                    'Szyfrowanie end-to-end, w aplikacji „Kompleksowe szyfrowanie”. Hasła szyfruje Twoja przeglądarka przed wysłaniem na serwer, '
                    . 'kluczem z hasła szyfrowania, które znasz tylko Ty. Nawet administrator chmury ich nie odczyta.',
                ])
                . '<h2>Czy włączać end-to-end?</h2><p>Tak, jeśli trzymasz w Kuferze hasła, których nikomu nie udostępniasz. '
                . 'Po włączeniu, przy każdym otwarciu aplikacji, wpisujesz hasło szyfrowania. Hasła udostępniane zespołowi i tak są szyfrowane po stronie serwera, '
                . 'bo udostępnienie wyłącza dla nich szyfrowanie end-to-end.</p>'
                . '<h2>Jak włączyć</h2><p>Otwórz w aplikacji „Ustawienia” i znajdź część o szyfrowaniu („Kompleksowe szyfrowanie”). Aplikacja przeprowadzi Cię przez konfigurację '
                . 'i poprosi o hasło szyfrowania oraz jego powtórzenie. Słabego hasła nie przyjmie. Hasło szyfrowania zmienisz później w tych samych ustawieniach '
                . '(„Zmiana hasła szyfrowania”). Jeśli coś wygląda inaczej, napisz w <a href="' . $app . '/helpdesk/new.php">Helpdesku IT</a>.</p>'),
            $box('warn', '<p>Hasła szyfrowania nie da się odzyskać. Dział IT go nie zna i nie zresetuje. Kto je zapomni, traci dostęp do haseł zaszyfrowanych end-to-end. '
                . 'Wybierz zdanie, które zapamiętasz, i nie używaj go nigdzie indziej.</p>'),
            $box('info', '<p>Na zaufanym komputerze aplikacja może zapamiętać hasło szyfrowania i odblokowywać się kluczem sprzętowym albo odciskiem palca (WebAuthn). '
                . 'Na komputerach współdzielonych tego nie włączaj.</p>'),
            ], $g1],

        'kontrola-bezpieczenstwa' => ['Kufer: kontrola bezpieczeństwa haseł',
            'bezpieczeństwo, słaby, słabe hasło, duplikat, powtórzone hasło, przestarzały, naruszony, wyciek, have i been pwned, hibp, '
            . 'zmień hasło, otwórz stronę zmiany hasła, kufer', [
            $t('<p>Kufer sam sprawdza Twoje hasła. W menu „Bezpieczeństwo” zobaczysz, które wymagają zmiany:</p>' . $ul([
                    '„Słaby”: za krótkie albo łatwe do odgadnięcia.',
                    '„Słaby (Duplikat)”: to samo hasło jest użyte w kilku miejscach. Gdy wycieknie z jednego serwisu, ktoś zaloguje się też do pozostałych.',
                    '„Słaby (przestarzały)”: hasło od dawna się nie zmieniło.',
                    '„Naruszony”: hasło jest w znanych wyciekach danych. Zmień je od razu.',
                ])
                . '<p>Przy haśle do zmiany menu z trzema kropkami ma „Otwórz stronę zmiany hasła”, jeśli serwis ją podaje. Po zmianie zapisz nowe hasło w Kuferze; '
                . 'stare zostanie w „Zmianach”.</p>'),
            $box('info', '<p>Wycieki aplikacja sprawdza w bazie Have I Been Pwned. Nie wysyła tam hasła, tylko kilka pierwszych znaków jego skrótu.</p>'),
            ], $g1],

        'przegladarka-i-telefon' => ['Kufer w przeglądarce i na telefonie',
            'rozszerzenie, wtyczka, przeglądarka, firefox, chrome, edge, autouzupełnianie, telefon, android, iphone, ios, aplikacja, passlink, '
            . 'kod qr, połącz urządzenie, aplikacje i rozszerzenia, kufer', [
            $t('<p>Kufer działa też poza stroną: rozszerzenie w przeglądarce podpowiada hasło na stronie logowania, a aplikacja na telefonie daje dostęp w terenie.</p>'),
            ['type' => 'wizard', 'title' => 'Jak podłączyć rozszerzenie albo aplikację', 'intro' => '', 'steps' => [
                $step('Wybierz klienta', '<p>W Kuferze otwórz „Aplikacje i rozszerzenia” (na dole menu po lewej). Na liście są rozszerzenia do przeglądarek i aplikacje na telefon. '
                    . 'Oznaczone jako „oficjalny” robią autorzy aplikacji. Zainstaluj wybrane ze sklepu z rozszerzeniami albo aplikacjami.</p>'),
                $step('Połącz przez PassLink', '<p>Na tej samej stronie kliknij „Połącz się z PassLink”, potem „Podłącz nowe urządzenie”. '
                    . 'Na telefonie zeskanuj kod QR w aplikacji. Na tym samym komputerze wybierz „Połączenie przez link”.</p>'),
                $step('Sprawdź kody', '<p>Kufer pokaże kody, a rozszerzenie albo aplikacja swoje. Jeśli są takie same, potwierdź. Na koniec „Skończyłem”.</p>'),
            ]],
            $box('tip', '<p>Jeśli klient nie obsługuje PassLink, podłączysz go adresem chmury, loginem i hasłem aplikacji. '
                . 'Hasło aplikacji tworzysz w chmurze w ustawieniach osobistych, w części o bezpieczeństwie. Nie wpisuj tam swojego zwykłego hasła.</p>'),
            ], $g1],

        'dla-it' => ['Kufer: instalacja i utrzymanie',
            'passwords, marius wieschollek, nextcloud, occ, kopia zapasowa, backup, restore, mydevil, s89, kufer.feer.org.pl, dnscontrol, '
            . 'passwords:system:report, passwords:backup, app:disable, vaultwarden', [
            $t('<p>Kufer to aplikacja <a href="https://apps.nextcloud.com/apps/passwords">Passwords</a> (autor Marius Wieschollek) w Nextcloudzie nc.feer.org.pl na MyDevil s89, '
                . 'zainstalowana 15 listopada 2026 (wersja 2026.11.20). Nie ma osobnego procesu ani bazy: dane są w bazie Nextclouda <code>m1045_nc</code>. '
                . 'Pełny opis jest w <code>~/nextcloud/README.md</code> na serwerze, w części „Menedżer haseł”.</p>'
                . '<h2>Adresy</h2>' . $ul([
                    '<code>https://nc.feer.org.pl/apps/passwords/</code>: sama aplikacja.',
                    '<code>https://kufer.feer.org.pl</code>: strona php na MyDevil z przekierowaniem 302 w <code>public_html/.htaccess</code>, certyfikat Let\'s Encrypt, wymuszone HTTPS. Rekord A <code>kufer</code> jest w repozytorium feer-dns (DNSControl).',
                ])
                . '<h2>Polecenia</h2><p>Zawsze przez nakładkę <code>occ</code> z katalogu Nextclouda:</p>'
                . $pre("~/nextcloud/bin/occ passwords:system:report      # wersje, usługi, stan kopii\n~/nextcloud/bin/occ passwords:backup:list\n~/nextcloud/bin/occ passwords:backup:create\n~/nextcloud/bin/occ passwords:backup:restore\n~/nextcloud/bin/occ app:update passwords            # przed tym ~/nextcloud/bin/backup.sh\n~/nextcloud/bin/occ app:disable passwords           # dane zostają w bazie")
                . '<h2>Kopie</h2><p>Hasła są w kopii bazy Nextclouda robionej co noc o 3:50 (<code>~/nextcloud/bin/backup.sh</code>, 7 dni). '
                . 'Aplikacja robi też własne kopie (<code>passwords:backup:list</code>). Hasła zaszyfrowane end-to-end da się po przywróceniu odczytać tylko hasłem szyfrowania właściciela.</p>'
                . '<h2>Odejście osoby</h2><p>Hasła osoby przeniesiesz na inne konto poleceniem <code>passwords:user:move</code>, a usuniesz <code>passwords:user:delete</code>. '
                . 'Hasła zaszyfrowane end-to-end po przeniesieniu bez hasła szyfrowania są nieczytelne.</p>'),
            $box('info', '<p>Wcześniej rozważano Vaultwarden. Kompilacja na s89 zajmowała ponad 4 GB pamięci, więc zostaliśmy przy aplikacji w istniejącym Nextcloudzie. '
                . 'Problemy z Kuferem zgłaszaj w <a href="' . $hd . '">Helpdesku IT</a>.</p>'),
            ], $g2],
    ];
}
