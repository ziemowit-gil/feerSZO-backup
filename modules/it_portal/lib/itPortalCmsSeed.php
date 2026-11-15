<?php
/**
 * modules/it_portal/lib/itPortalCmsSeed.php — usługa „Strona feer.org.pl (CMS)” w Portalu Usług IT (/uslugi-it/uslugi/strona-cms).
 * Polecenie 2026-11-15: „Do pomocy IT /uslugi-it zrób pomoc do tego … ze zrzutami ekranu” — po zmianach w CMS (repo feer-web):
 * poprawiator dostępności treści (commit 6744bc5d), formularze przez zwykły SMTP zamiast Microsoft Graph i zadanie antyspamowe
 * w formularzach publicznych (commit 53f35d92).
 * Forma wg zasad pomocy: bez tabel i myślników, nazwy przycisków w „cudzysłowie”, polecenia w <pre><code>.
 * Zrzuty: modules/it_portal/lib/img/cms-*.jpg (lokalna kopia CMS w stylu Metro, dane przykładowe; itPortalCloudShot kopiuje je do uploads/it_portal/).
 * Artykuły: „Dla redakcji” (poprawiator, zadanie antyspamowe) i „Dla IT” (poczta formularzy przez SMTP, it_only = 1).
 * Bezpieczny do ponownego uruchomienia. Używają: migracja '2026-11_it_portal_cms_docs', cli/it_portal_cms_seed.php.
 */

/** @return list<string> */
function itPortalCmsApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $b = $app . '/uslugi-it/uslugi/strona-cms/';
    $intro = itPortalSanitizeHtml('<p>Strona <a href="https://feer.org.pl">feer.org.pl</a> ma panel (CMS), w którym redakcja dodaje aktualności, strony i działania. '
        . 'Tu znajdziesz instrukcje do panelu: <a href="' . $b . 'poprawiator">poprawiator dostępności treści</a> i '
        . '<a href="' . $b . 'zadanie-antyspamowe">zadanie antyspamowe w formularzach</a>. Logowanie do panelu opisuje artykuł '
        . '<a href="' . $app . '/uslugi-it/uslugi/moj-login/logowanie-do-cms">Logowanie do panelu strony feer.org.pl (CMS)</a>. '
        . 'Artykuły w grupie „Dla IT” są dla działu IT.</p>');

    $rep = [];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'strona-cms'");
    if ($dryRun) {
        $rep[] = 'usługa strona-cms: ' . ($cat ? 'aktualizacja wstępu' : 'nowa');
        foreach (itPortalCmsArticles($app, true) as $slug => [$title]) $rep[] = "strona-cms/{$slug} — {$title}";
        return $rep;
    }
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Strona feer.org.pl (CMS)', 'slug' => 'strona-cms', 'icon' => 'globe', 'sort_order' => 28, 'is_active' => 1,
            'tile_group' => 'Pliki, dokumenty i zadania',
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }

    $ids = []; $i = 0;
    foreach (itPortalCmsArticles($app) as $slug => [$title, $keywords, $blocks, $group]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0,
            'sort_order' => (++$i) * 10, 'menu_group' => $group, 'it_only' => $group === 'Dla IT' ? 1 : 0,
            'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $ids[] = $id;
        $rep[] = "strona-cms/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ", #{$id})";
    }
    // „Zobacz też”: artykuły usługi wskazują się nawzajem i artykuł o logowaniu do CMS
    $login = db_one("SELECT a.id FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = 'moj-login' AND a.slug = 'logowanie-do-cms'");
    $all = $login ? array_merge($ids, [(int)$login['id']]) : $ids;
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
function itPortalCmsArticles(string $app, bool $noShots = false): array {
    if (!function_exists('itPortalCloudShot')) require_once __DIR__ . '/itPortalCloudSeed.php';
    $app = rtrim($app, '/');
    $hd  = $app . '/helpdesk/new.php';
    $cms = 'https://feer.org.pl/admin';
    $pre = static fn(string $s): string => '<pre><code>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</code></pre>';
    $t   = static fn(string $h): array => ['type' => 'text', 'html' => $h];
    $box = static fn(string $kind, string $h): array => ['type' => 'callout', 'kind' => $kind, 'html' => $h];
    $img = static fn(string $name, string $alt): array => $noShots ? [] : itPortalCloudShot('cms-' . $name, $alt);
    $ul  = static fn(array $items): string => '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
    $step = static fn(string $title, string $h): array => ['title' => $title, 'html' => $h, 'images' => []];
    $g1 = 'Dla redakcji'; $g2 = 'Dla IT';

    return [
        'poprawiator' => ['Poprawiator: automatyczne poprawki dostępności treści',
            'poprawiator, dostępność treści, dostępność, wcag, czytnik ekranu, nagłówki, h2, h3, pogrubienie, śródtytuł, lista, myślniki, '
            . 'tabela, nagłówek tabeli, osadzenie, film, youtube, popraw automatycznie, cms, panel strony, feer.org.pl, redakcja', array_merge(
            [$t('<p>W panelu strony feer.org.pl jest raport <strong>Dostępność treści</strong> (menu „Treści”, grupa „Kontrola jakości”). '
                . 'Pokazuje strony, aktualności i działania, w których coś utrudnia czytanie osobom korzystającym z czytnika ekranu: '
                . 'źle ułożone nagłówki, pogrubienia zamiast śródtytułów, listy pisane myślnikami, tabele bez nagłówków, filmy bez tytułu.</p>'
                . '<p><strong>Poprawiator</strong> poprawia te rzeczy za Ciebie, jednym przyciskiem.</p>')],
            $img('01-poprawiator.jpg', 'Raport Dostępność treści: liczba błędów i uwag, ramka Poprawiator z przyciskiem „Popraw automatycznie 3 treści” i lista treści z przyciskami „Popraw automatycznie” i „Edytuj”'),
            [['type' => 'wizard', 'title' => 'Jak użyć poprawiatora', 'intro' => '', 'steps' => [
                $step('Otwórz raport', '<p>Zaloguj się do <a href="' . $cms . '">panelu strony</a> i wybierz „Treści”, potem „Dostępność treści”.</p>'),
                $step('Zobacz, co zostanie poprawione', '<p>Przy każdej treści jest zdanie <strong>„Poprawiator zrobi: …”</strong>, na przykład „pogrubienia zamienione na śródtytuły (2), myślniki zamienione na listy (1)”.</p>'),
                $step('Popraw jedną treść albo wszystkie', '<p>Kliknij „Popraw automatycznie” przy wybranej treści albo przycisk w ramce „Poprawiator”, który poprawi wszystkie treści z widocznej listy. '
                    . 'Jeśli wybierzesz filtr, na przykład „Pogrubienie zamiast nagłówka”, poprawiator zrobi tylko tę jedną rzecz.</p>'),
                $step('Popraw resztę ręcznie', '<p>Po poprawkach na górze pojawi się podsumowanie, a na liście zostaną tylko uwagi, których nie da się poprawić automatycznie. '
                    . 'Kliknij „Edytuj” i popraw je w edytorze.</p>'),
            ]]],
            $img('02-po-poprawce.jpg', 'Raport po poprawkach: komunikat „Poprawiono automatycznie 3 treści” i dwie pozostałe uwagi o tekstach linków'),
            [$t('<h2>Co poprawiator zmienia</h2>' . $ul([
                    'Usuwa puste nagłówki.',
                    'Układa poziomy nagłówków: treść zaczyna się od „Nagłówka 2”, kolejne poziomy idą po kolei, bez przeskoków, a „Nagłówek 1” w treści zmienia na „Nagłówek 2” (nagłówkiem 1 jest tytuł strony).',
                    'Krótki pogrubiony akapit, który wygląda jak śródtytuł, zamienia na prawdziwy nagłówek. Pod nagłówkiem 2 staje się nagłówkiem 3.',
                    'Kilka akapitów zaczynających się od myślnika albo numeru zamienia na listę punktowaną albo numerowaną.',
                    'W tabeli z co najmniej dwoma wierszami oznacza pierwszy wiersz jako nagłówek kolumn.',
                    'Osadzonemu filmowi albo mapie nadaje tytuł, na przykład „Film YouTube” albo „Mapa Google”.',
                ])
                . '<h2>Czego nie zmienia</h2>'
                . '<p>Tekstów linków (na przykład „kliknij tutaj” albo długi adres zamiast opisu) i opisów obrazów. Tu trzeba wiedzieć, co jest celem linku i co pokazuje obraz, '
                . 'więc decyduje redaktor. Opisy obrazów z biblioteki poprawisz w „Multimedia”, w audycie opisów alternatywnych.</p>'),
             $box('info', '<p><strong>Zmianę da się cofnąć.</strong> Każda poprawiona treść dostaje nową wersję. Jeśli wynik Ci się nie podoba, '
                . 'otwórz treść do edycji, wejdź w historię wersji i przywróć poprzednią. Treści z edytora blokowego poprawiator poprawia w blokach, więc bloki zostają.</p>'),
             $box('tip', '<p>Pogrubiony akapit nie zawsze jest śródtytułem. Po poprawkach zajrzyj na stronę i sprawdź, czy nagłówki mają sens. '
                . 'Jeśli coś ma zostać zwykłym pogrubieniem, przywróć poprzednią wersję albo zmień styl akapitu w edytorze.</p>')],
            ), $g1],

        'zadanie-antyspamowe' => ['Zadanie antyspamowe w formularzach strony',
            'antyspam, spam, zadanie antyspamowe, ile wynosi, captcha, formularz, formularz kontaktowy, zgłoszenie bariery, umów spotkanie, '
            . 'dołącz do federacji, współpraca, nie mogę wysłać, błędna odpowiedź, feer.org.pl, strona', array_merge(
            [$t('<p>Formularze na stronie feer.org.pl chronią się przed automatycznym spamem. Na dole formularza jest krótkie pytanie, '
                . 'na przykład <strong>„Zadanie antyspamowe: Ile wynosi 3 + 7?”</strong>. Odpowiedź wpisuje się cyfrą (10) albo słownie (dziesięć).</p>'
                . '<p>To zwykły tekst, a nie obrazek z literami, więc działa z czytnikiem ekranu i na telefonie. Oprócz pytania formularz ma też ukryte zabezpieczenia: '
                . 'odrzuca wysyłkę w ułamku sekundy po otwarciu strony, treść z dużą liczbą linków i dwa takie same zgłoszenia pod rząd.</p>'
                . '<p>Zadanie jest w formularzu kontaktowym, w zgłoszeniu bariery w deklaracji dostępności, w zapisie na spotkanie i „Umów spotkanie”, '
                . 'w „Dołącz do federacji”, w formularzu współpracy, w formularzach z kreatora „Formularze” i w zapisach na szkolenia.</p>')],
            $img('04-antyspam.jpg', 'Formularz „Umów spotkanie” z polem „Zadanie antyspamowe: Ile wynosi 2 + 5?” i czerwonym komunikatem o nieprawidłowej odpowiedzi'),
            [$t('<h2>Gdy ktoś pisze, że nie może wysłać formularza</h2>' . $ul([
                    'Komunikat „Odpowiedź na zadanie antyspamowe jest nieprawidłowa” oznacza zły wynik. Pod spodem jest nowe pytanie: trzeba wpisać nowy wynik, a reszta formularza zostaje wypełniona.',
                    'Komunikat o zbyt wielu odnośnikach: zgłoszenie miało więcej niż dwa adresy internetowe. Trzeba usunąć część adresów.',
                    'Komunikat „To zgłoszenie zostało już wysłane”: ta sama treść poszła drugi raz w ciągu 10 minut. Pierwsza wysyłka doszła.',
                    'Strona otwarta dłużej niż dobę: trzeba ją odświeżyć i wypełnić formularz jeszcze raz.',
                ])),
             $box('info', '<p>Jeśli ktoś mimo to nie może wysłać zgłoszenia, poproś go o kontakt mailem albo telefonicznie, a sprawę zgłoś w <a href="' . $hd . '">Helpdesku IT</a> '
                . '(adres strony z formularzem i godzina). Zablokowane zgłoszenia IT widzi w logu serwera.</p>')],
            ), $g1],

        'poczta-smtp' => ['Poczta z formularzy strony przez SMTP',
            'smtp, poczta, formularze, powiadomienia, mail, e-mail, microsoft graph, graph, msgraph, wysyłka, tryb wysyłki, własny serwer smtp, '
            . 'starttls, port 587, 465, wiadomość testowa, mail_timeout, cms, feer.org.pl, ustawienia poczta, nie przychodzą maile', array_merge(
            [$t('<p>Od 15 listopada 2026 powiadomienia z formularzy strony feer.org.pl nie idą osobno przez Microsoft Graph. '
                . 'Wychodzą tym samym trybem co cała poczta strony, ustawianym w panelu: <strong>„Ustawienia”, „Poczta”, karta „Sposób wysyłki”</strong>. '
                . 'Docelowo jest to zwykły serwer SMTP. Przełącznik „Formularze: powiadomienia przez Graph” i wybór Graph w ustawieniach pojedynczego formularza zostały usunięte; '
                . 'w edycji formularza widać tylko, którym trybem idą powiadomienia.</p>')],
            $img('03-poczta-smtp.jpg', 'Ustawienia, Poczta, karta „Sposób wysyłki”: tryb „Własny serwer SMTP”, adres i nazwa nadawcy, host, port 587, użytkownik, hasło, szyfrowanie TLS (STARTTLS) i sekcja „Wyślij wiadomość testową”'),
            [['type' => 'wizard', 'title' => 'Jak ustawić SMTP', 'intro' => '', 'steps' => [
                $step('Otwórz ustawienia poczty', '<p>W <a href="' . $cms . '">panelu strony</a> wybierz „Ustawienia”, „Poczta” i kartę „Sposób wysyłki”.</p>'),
                $step('Wybierz tryb', '<p>W polu „Tryb wysyłki” wybierz <strong>„Własny serwer SMTP”</strong>. Wpisz „Adres nadawcy” (skrzynka, z której wychodzi poczta) i „Nazwę nadawcy”.</p>'),
                $step('Wpisz dane serwera', '<p>Host, port, użytkownik i hasło skrzynki. Zwykle port 587 z szyfrowaniem „TLS (STARTTLS)” albo port 465 z „SSL”. '
                    . 'Hasło jest zapisane w bazie w postaci zaszyfrowanej; puste pole przy kolejnym zapisie zostawia stare hasło.</p>'),
                $step('Zapisz i sprawdź', '<p>Kliknij „Zapisz zmiany”, a potem w sekcji „Wyślij wiadomość testową” wpisz swój adres i kliknij „Wyślij test”. '
                    . 'Na koniec wyślij próbne zgłoszenie z dowolnego formularza na stronie.</p>'),
            ]]],
            [$t('<h2>Dobrze wiedzieć</h2>' . $ul([
                    'Dopóki „Tryb wysyłki” to „Microsoft 365 (Graph API)”, cała poczta strony, razem z formularzami, idzie dalej przez Graph. Formularze nie mają już osobnego ustawienia.',
                    'Karta „Autokonfigurator” podpowiada host i port po wpisaniu adresu skrzynki. Przy skrzynce Microsoft 365 SMTP działa tylko, gdy skrzynka ma włączone uwierzytelnianie SMTP (SMTP AUTH).',
                    'Połączenie z serwerem SMTP ma limit 15 sekund. Gdy serwer nie odpowiada, zgłoszenie i tak zapisuje się w panelu, a błąd trafia do logu. Limit zmienia zmienna MAIL_TIMEOUT w pliku .env.',
                    'Zamiast ustawień w panelu można użyć pliku .env (tryb „Dziedzicz z serwera (.env)”):',
                ])
                . $pre("MAIL_MAILER=smtp\nMAIL_HOST=smtp.example.org\nMAIL_PORT=587\nMAIL_USERNAME=formularze@feer.org.pl\nMAIL_PASSWORD=…\nMAIL_FROM_ADDRESS=formularze@feer.org.pl\nMAIL_TIMEOUT=15")
                . '<h2>Wdrożenie zmiany</h2><p>Na serwerze strony (h68) w katalogu aplikacji:</p>'
                . $pre('./deploy.sh')
                . '<p>Bez migracji i bez budowania. Potem ustaw SMTP w panelu jak wyżej.</p>'
                . '<h2>Maile nie przychodzą</h2>' . $ul([
                    'Sprawdź „Wyślij test”. Komunikat błędu mówi, co jest nie tak (zły host, port, hasło albo blokada po stronie serwera pocztowego).',
                    'Sprawdź log aplikacji, wpisy „Formularz: nie udało się wysłać powiadomienia o zgłoszeniu”. Są w nich nazwa formularza, numer zgłoszenia i użyty mailer.',
                    'Zgłoszenia nie giną: są w panelu w „Formularze”, w zgłoszeniach danego formularza.',
                ])),
             $box('info', '<p>Antyspam formularzy (zadanie tekstowe i ukryte zabezpieczenia) opisuje artykuł „Zadanie antyspamowe w formularzach strony”. '
                . 'Po stronie kodu to klasa App\\Support\\SpamGuard i fragment widoku partials/spam-protection w repozytorium feer-web.</p>')],
            ), $g2],
    ];
}
