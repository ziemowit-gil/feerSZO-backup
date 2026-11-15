<?php
/**
 * modules/it_portal/lib/itPortalSekretySeed.php — usługa „Sekrety (przekazywanie haseł)” w Portalu Usług IT (/uslugi-it/uslugi/sekrety).
 * Polecenie 2026-11-15: „do pomocy IT i do uslugi-it dopisz pomoc do sekretów, użyj humanizatora” po wdrożeniu sekrety.feer.org.pl
 * (aplikacja alancwoo/secret w wersji FEER: po polsku, generator haseł, tworzenie bez hasła, limit na adres IP, skracanie linków wyłączone).
 * Kod wersji FEER: lokalnie ~/sekrety (gałąź feer), na serwerze MyDevil s89 ~/domains/sekrety.feer.org.pl/secret.
 * Forma wg zasad pomocy: bez tabel i myślników, nazwy przycisków w „cudzysłowie”, polecenia w <pre><code>; tekst przez skill humanizer.
 * Zrzuty: lib/img/sek-*.jpg (lokalna kopia aplikacji z przykładowymi danymi, Chrome bez okna).
 * Artykuły: „Dla wszystkich” (wysyłanie, odczyt, prośba, generator, dobre praktyki), „Dla IT” (instalacja i utrzymanie, it_only = 1).
 * Bezpieczny do ponownego uruchomienia. Używają: migracja '2026-11_it_portal_sekrety', cli/it_portal_sekrety_seed.php.
 */

/** @return list<string> */
function itPortalSekretyApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $b = $app . '/uslugi-it/uslugi/sekrety/';
    $intro = itPortalSanitizeHtml('<p><a href="https://sekrety.feer.org.pl">sekrety.feer.org.pl</a> służy do przekazywania haseł, danych logowania '
        . 'i poufnych plików. Zamiast wpisywać hasło w mailu albo na czacie, gdzie zostaje na zawsze, wysyłasz link, który działa raz i potem znika. '
        . 'Nie trzeba się logować. Zacznij od artykułu <a href="' . $b . 'wyslij-haslo">Jak bezpiecznie wysłać hasło lub plik</a>.</p>');

    $rep = [];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'sekrety'");
    if ($dryRun) {
        $rep[] = 'usługa sekrety: ' . ($cat ? 'aktualizacja wstępu' : 'nowa');
        foreach (itPortalSekretyArticles($app, true) as $slug => [$title]) $rep[] = "sekrety/{$slug} — {$title}";
        return $rep;
    }
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Sekrety (przekazywanie haseł)', 'slug' => 'sekrety', 'icon' => 'key', 'sort_order' => 27, 'is_active' => 1,
            'tile_group' => 'Konto i logowanie',
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }

    $ids = []; $i = 0;
    foreach (itPortalSekretyArticles($app) as $slug => [$title, $keywords, $blocks, $group]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $slug === 'wyslij-haslo' ? 1 : 0,
            'sort_order' => (++$i) * 10, 'menu_group' => $group, 'it_only' => $group === 'Dla IT' ? 1 : 0,
            'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $ids[] = $id;
        $rep[] = "sekrety/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ", #{$id})";
    }
    // „Zobacz też”: artykuły usługi wskazują się nawzajem i artykuł o zmianie haseł
    $pw = db_one("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = 'konto-i-hasla' AND a.slug = 'zmiana-hasel'");
    $all = $pw ? array_merge($ids, [(int)$pw['id']]) : $ids;
    foreach ($all as $id) {
        $r = db_one("SELECT related FROM itp_articles WHERE id = ?", [$id]);
        $rel = array_values(array_unique(array_merge(itPortalIds($r['related'] ?? '[]'), array_values(array_diff($all, [$id])))));
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode($rel), $id]);
    }
    return $rep;
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, grupa w menu]. $noShots: bez kopiowania zrzutów (--dry-run).
 */
function itPortalSekretyArticles(string $app, bool $noShots = false): array {
    if (!function_exists('itPortalCloudShot')) require_once __DIR__ . '/itPortalCloudSeed.php';
    $app = rtrim($app, '/');
    $hd  = $app . '/helpdesk/new.php';
    $sek = '<a href="https://sekrety.feer.org.pl">sekrety.feer.org.pl</a>';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $t   = static fn(string $h): array => ['type' => 'text', 'html' => $h];
    $box = static fn(string $kind, string $h): array => ['type' => 'callout', 'kind' => $kind, 'html' => $h];
    $img = static fn(string $name, string $alt): array => $noShots ? [] : itPortalCloudShot('sek-' . $name, $alt);
    $ul  = static fn(array $items): string => '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
    $step = static fn(string $title, string $h): array => ['title' => $title, 'html' => $h, 'images' => []];
    $g1 = 'Dla wszystkich'; $g2 = 'Dla IT';
    $b   = $app . '/uslugi-it/uslugi/sekrety/';
    $a   = static fn(string $slug, string $title): string => '<a href="' . $b . $slug . '">' . $title . '</a>';

    return [
        'wyslij-haslo' => ['Jak bezpiecznie wysłać hasło lub plik',
            'sekrety, sekret, wyślij hasło, przekaż hasło, dane logowania, login, hasło mailem, bezpiecznie, jednorazowy link, klucz, plik, '
            . 'poufny plik, szyfrowanie, sekrety.feer.org.pl, onetimesecret, wygasa, ważność, liczba odczytów, usuń sekret', array_merge(
            [$t('<p>Wejdź na ' . $sek . '. Nie musisz się logować ani mieć hasła dostępu. '
                . 'Treść jest szyfrowana w Twojej przeglądarce, zanim trafi na serwer, więc serwer przechowuje tylko zaszyfrowane dane i nie zna klucza.</p>')],
            $img('01-wyslij.jpg', 'Strona sekrety.feer.org.pl: zakładki „Wyślij sekret” i „Poproś o sekret”, pole na treść, rozwinięty „Wygeneruj silne hasło”, pola „Ważność” (1 dzień) i „Liczba odczytów” (1 odczyt) oraz przycisk „Zaszyfruj i utwórz link”'),
            [['type' => 'wizard', 'title' => 'Jak wysłać sekret', 'intro' => '', 'steps' => [
                $step('Wpisz treść albo dodaj plik', '<p>Na zakładce „Wyślij sekret” wpisz treść, na przykład adres panelu, login i hasło. '
                    . 'Plik do 10 MB dodasz po wybraniu „Plik”: przeciągnij go, wklej albo kliknij pole. Nowe hasło możesz wygenerować od razu na stronie, '
                    . 'opisuje to ' . $a('generator-hasel', 'Generator silnych haseł') . '.</p>'),
                $step('Ustaw ważność i liczbę odczytów', '<p>„Ważność” mówi, po jakim czasie sekret zniknie, nawet jeśli nikt go nie otworzy. Domyślnie to 1 dzień; '
                    . 'wybierz najkrótszy czas, który wystarczy odbiorcy. „Liczbę odczytów” zostaw na „1 odczyt”, chyba że ten sam sekret ma otworzyć kilka osób.</p>'),
                $step('Utwórz link', '<p>Kliknij „Zaszyfruj i utwórz link”. Zobaczysz trzy pola: „Pełny link (link + klucz)”, osobno „Link” i „Klucz”. '
                    . 'Kliknięcie pola kopiuje je do schowka.</p>'),
                $step('Wyślij link i klucz osobno', '<p>Najbezpieczniej wysłać link i klucz dwoma różnymi drogami, na przykład link mailem, a klucz SMS-em albo na Teams. '
                    . 'Kto przechwyci tylko jedną wiadomość, nie odczyta sekretu. Przy mniej ważnych danych wystarczy „Pełny link”.</p>'),
            ]]],
            [$t('<p>Sekret trafił do złej osoby albo nie jest już potrzebny? Na ekranie z linkiem kliknij „Usuń ten sekret teraz”. Sekret zniknie od razu.</p>'),
             $box('warn', '<p>W wiadomości z linkiem nie pisz, czego dotyczy sekret (na przykład „hasło do banku”). '
                . 'Nie przekazuj tą drogą danych o zdrowiu ani niepełnosprawności podopiecznych bez uzgodnienia z działem IT i inspektorem ochrony danych.</p>')],
            ), $g1],

        'odbierz-sekret' => ['Jak odczytać otrzymany sekret',
            'odczytaj sekret, otwórz sekret, odbierz hasło, link nie działa, klucz, krzyżyk, hash, sekret nie istnieje, wygasł, nieprawidłowy klucz, '
            . 'pobierz plik, sekrety.feer.org.pl, zapisz hasło, menedżer haseł', array_merge(
            [['type' => 'wizard', 'title' => 'Jak odczytać sekret', 'intro' => '', 'steps' => [
                $step('Otwórz link', '<p>Kliknij otrzymany link. Jeśli klucz przyszedł osobno, dopisz go na końcu adresu w przeglądarce razem ze znakiem #, '
                    . 'na przykład <code>…/s/abc123#XyZ</code>, i naciśnij Enter.</p>'),
                $step('Przeczytaj treść', '<p>Treść odszyfruje się w Twojej przeglądarce. Przy pliku zobaczysz przycisk „Pobierz plik”.</p>'),
                $step('Od razu zapisz', '<p>Skopiuj hasło do menedżera haseł albo zapisz plik. Po jednym odczycie sekret znika z serwera i po zamknięciu strony nie da się go otworzyć ponownie. '
                    . 'Strona zapyta, czy na pewno chcesz ją zamknąć.</p>'),
            ]]],
            $img('03-odczyt.jpg', 'Odczytany sekret: czerwony komunikat, że sekret usunięto z serwera i trzeba zapisać treść, a pod nim adres panelu, login i hasło'),
            [$t('<h2>Komunikat „Ten sekret nie istnieje, wygasł albo klucz jest nieprawidłowy”</h2>' . $ul([
                    'Ktoś już otworzył ten link. Jeśli to nie Ty, napisz o tym nadawcy: ktoś inny mógł zobaczyć treść, więc hasło trzeba zmienić.',
                    'Minął czas ważności. Poproś nadawcę o nowy sekret.',
                    'Link jest niepełny: brakuje klucza albo znaku #. Sprawdź, czy skopiowany adres jest cały.',
                ]))],
            ), $g1],

        'popros-o-haslo' => ['Jak poprosić kogoś o hasło lub plik',
            'poproś o sekret, prośba o hasło, potrzebuję hasła, kontrahent, dostawca, zewnętrzna firma, przyślij hasło, link z prośbą, request, '
            . 'sekrety.feer.org.pl, jednorazowy link', array_merge(
            [$t('<p>Gdy to Ty potrzebujesz od kogoś hasła albo dokumentu, na przykład od firmy hostingowej, wyślij tej osobie link z prośbą. '
                . 'Ta osoba nie musi mieć konta w Fundacji. Jej sekret zostanie zaszyfrowany w jej przeglądarce.</p>'),
             ['type' => 'wizard', 'title' => 'Jak poprosić o sekret', 'intro' => '', 'steps' => [
                $step('Otwórz zakładkę „Poproś o sekret”', '<p>Na ' . $sek . ' kliknij zakładkę „Poproś o sekret”.</p>'),
                $step('Opisz, czego potrzebujesz', '<p>W polu „Czego potrzebujesz (opcjonalnie)” wpisz na przykład „Hasło do panelu hostingu”. Odbiorca zobaczy ten opis. '
                    . 'W polu „Link ważny przez” wybierz czas od 1 godziny do 7 dni.</p>'),
                $step('Wyślij link', '<p>Kliknij „Utwórz link z prośbą”, skopiuj link i wyślij go tej osobie. Link działa tylko raz.</p>'),
                $step('Odbierz sekret', '<p>Osoba wpisuje sekret, klika „Zaszyfruj i wyślij” i dostaje link z kluczem. Ten link odsyła Tobie, najlepiej link i klucz osobno. '
                    . 'Otwierasz go tak, jak opisuje ' . $a('odbierz-sekret', 'Jak odczytać otrzymany sekret') . '.</p>'),
             ]]],
            $img('02-prosba-odbiorca.jpg', 'Strona, którą widzi osoba proszona o sekret: „Wyślij sekret”, zdanie „Poproszono Cię o bezpieczne przekazanie: Hasło do panelu hostingu”, pole na treść, generator haseł i przycisk „Zaszyfruj i wyślij”'),
            ), $g1],

        'generator-hasel' => ['Generator silnych haseł',
            'generator haseł, wygeneruj hasło, silne hasło, losowe hasło, nowe hasło, długość hasła, znaki specjalne, podobne znaki, bity, siła hasła, '
            . 'wstaw do sekretu, kopiuj, sekrety.feer.org.pl', [
            $t('<p>Pod polem na treść sekretu jest „Wygeneruj silne hasło”. Kliknij, a pojawi się losowe hasło. Przydaje się, gdy zakładasz komuś konto '
                . 'albo zmieniasz hasło do wspólnej usługi i od razu chcesz je przekazać.</p>'
                . $ul([
                    'Suwak „Długość” ustawia od 8 do 64 znaków. Domyślnie 20.',
                    'Pola wyboru decydują, jakie znaki są w haśle: wielkie litery, małe litery, cyfry i znaki specjalne. Każdy zaznaczony rodzaj pojawi się w haśle co najmniej raz.',
                    '„Bez podobnych znaków” pomija O i 0, I, l i 1, które łatwo pomylić przy przepisywaniu.',
                    'Pod hasłem widać jego siłę w bitach losowości. 20 znaków ze wszystkich rodzajów to około 127 bitów, czyli hasło bardzo silne.',
                    '„Wstaw do sekretu” dopisuje hasło do treści sekretu w miejscu kursora, „Kopiuj” kopiuje je do schowka, a „Wygeneruj inne” losuje nowe.',
                ])),
            $box('info', '<p>Hasło losuje Twoja przeglądarka i nie wysyła go nigdzie, dopóki nie utworzysz z nim sekretu. '
                . 'Niektóre systemy nie przyjmują znaków specjalnych albo mają limit długości. Wtedy odznacz „Znaki specjalne” albo skróć hasło.</p>'),
            $box('tip', '<p>Hasła do kont Fundacji (SZO, poczta, chmura) zmieniasz na stronie <a href="' . $app . '/nowehaslo">zmiany haseł</a>. Tam też możesz dostać hasło losowe SMS-em.</p>'),
            ], $g1],

        'dobre-praktyki' => ['Sekrety: dobre praktyki i częste pytania',
            'sekrety, dobre praktyki, bezpieczeństwo, czy fundacja widzi, kto widzi, szyfrowanie, limit pliku, 10 mb, duży plik, za dużo prób, '
            . 'skracanie linków, pytania, faq, sekrety.feer.org.pl', [
            $t('<h2>Dobre praktyki</h2>' . $ul([
                    'Link i klucz wysyłaj osobno, różnymi drogami.',
                    'Ustaw krótki czas ważności i jeden odczyt.',
                    'Po otrzymaniu hasła zmień je przy pierwszym logowaniu, jeśli system na to pozwala.',
                    'Sekret nie jest kopią zapasową. Zniknie, więc hasło zapisz w menedżerze haseł.',
                ])
                . '<h2>Częste pytania</h2>'
                . '<h3>Czy dział IT albo Fundacja może przeczytać moje sekrety?</h3>'
                . '<p>Nie. Treść szyfruje przeglądarka, a klucz (część adresu po znaku #) nie trafia na serwer. Na serwerze są tylko zaszyfrowane dane, '
                . 'a po odczycie albo po czasie ważności znikają.</p>'
                . '<h3>Plik jest większy niż 10 MB</h3>'
                . '<p>Udostępnij go z chmury Fundacji linkiem chronionym hasłem, a samo hasło do linku wyślij przez Sekrety.</p>'
                . '<h3>Komunikat „Za dużo prób w krótkim czasie”</h3>'
                . '<p>Z jednego adresu internetowego można utworzyć 30 sekretów na godzinę. Odczekaj chwilę. Jeśli to blokuje Twoją pracę, napisz w <a href="' . $hd . '">Helpdesku IT</a>.</p>'
                . '<h3>Gdzie jest skracanie linków?</h3>'
                . '<p>W wersji Fundacji jest wyłączone. Krótkie linki pod naszym adresem łatwo wykorzystać do podszywania się (phishingu).</p>'),
            ], $g1],

        'sekrety-dla-it' => ['Sekrety: instalacja, konfiguracja i aktualizacja',
            'sekrety, secret, alancwoo, mydevil, s89, sekrety.feer.org.pl, .env, admin_password, new_item_password, rate_limit, shortener, '
            . 'sqlite, panel admin, /admin, aktualizacja, git, gałąź feer, dnscontrol, certyfikat, let\'s encrypt, open_basedir, php 8.4, kopia', [
            $t('<p>Sekrety to aplikacja open source <a href="https://github.com/alancwoo/secret">alancwoo/secret</a> (PHP bez frameworka, SQLite, licencja GPL-2.0) '
                . 'w wersji Fundacji: interfejs po polsku, generator haseł, tworzenie bez hasła, limit tworzenia na adres IP, osobne hasło administratora i wyłączone skracanie linków. '
                . 'Zmiany są na gałęzi <code>feer</code>, opisane w commitach.</p>'
                . '<h2>Gdzie co jest</h2>' . $ul([
                    'Serwer: MyDevil s89, konto feer, strona <code>sekrety.feer.org.pl</code> typu php.',
                    'Kod: <code>~/domains/sekrety.feer.org.pl/secret</code> (repozytorium git, gałąź <code>feer</code>). <code>public_html</code> to dowiązanie do <code>secret/public</code>, więc baza i <code>.env</code> są poza katalogiem dostępnym z sieci.',
                    'Baza: <code>secret/data/secrets.db</code> (SQLite). Kopii nie robimy: sekrety mają być krótkotrwałe.',
                    'PHP 8.4 ustawia <code>.htaccess</code> nad <code>public_html</code>. Katalog <code>secret</code> jest dopisany do <code>open_basedir</code>. <code>secret/public/.user.ini</code> wyłącza wyświetlanie błędów.',
                    'DNS: rekord A <code>sekrety</code> na 212.91.26.67 bez proxy Cloudflare, w repozytorium feer-dns (DNSControl). Certyfikat Let\'s Encrypt z MyDevil, odnawiany sam, wymuszone HTTPS.',
                ])
                . '<h2>Ustawienia w <code>secret/.env</code></h2>' . $ul([
                    '<code>NEW_ITEM_PASSWORD</code>: puste, więc sekrety i prośby tworzy każdy bez hasła.',
                    '<code>ADMIN_PASSWORD</code>: hasło panelu <code>/admin</code> (lista sekretów bez treści, zmiana ważności, usuwanie). Bez niego panel i edycja są wyłączone. Hasła nie podajemy w zgłoszeniach.',
                    '<code>RATE_LIMIT=30</code>: ile sekretów, próśb i odpowiedzi na prośby można utworzyć z jednego adresu IP w godzinę. W bazie jest tylko skrót adresu, kasowany po godzinie. 0 wyłącza limit.',
                    '<code>SHORTENER=0</code>: skracanie linków wyłączone.',
                    '<code>ALLOWED_TAGS=br,a</code>: znaczniki HTML dozwolone przy wyświetlaniu odszyfrowanej treści.',
                ])
                . '<p>Aplikacja czyta <code>.env</code> przy każdym żądaniu, więc zmiana działa od razu.</p>'
                . '<h2>Wdrożenie zmian</h2><p>Kopia robocza jest na komputerze IT w <code>~/sekrety</code> z remote <code>serwer</code>. '
                . 'Repozytorium na serwerze przyjmuje push na wybraną gałąź (<code>receive.denyCurrentBranch updateInstead</code>):</p>'
                . $pre("cd ~/sekrety\nphp tests/run.php\ngit push serwer feer")
                . '<p>Test „returns 413 for oversized content” nie przechodzi lokalnie także w wersji oryginalnej (limit <code>post_max_size</code> lokalnego PHP).</p>'
                . '<h2>Aktualizacja z oryginału</h2>'
                . $pre("cd ~/sekrety\ngit fetch origin\ngit merge origin/main\nphp tests/run.php\ngit push serwer feer")
                . '<p>Przy konfliktach w widokach zostaw polskie teksty. Po zmianie CSS albo JS podbij <code>?v=feer…</code> w <code>views/layout.php</code>, żeby przeglądarki pobrały nowe pliki.</p>'),
            $box('warn', '<p>Nie włączaj skracania linków bez hasła. Otwarte przekierowanie pod adresem feer.org.pl szybko trafi do kampanii phishingowych. '
                . 'Przy nadużyciach (dużo sekretów z jednego adresu) zmniejsz <code>RATE_LIMIT</code> albo ustaw <code>NEW_ITEM_PASSWORD</code>.</p>'),
            ], $g2],
    ];
}
