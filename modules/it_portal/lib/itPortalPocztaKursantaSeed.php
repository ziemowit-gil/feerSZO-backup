<?php
/**
 * modules/it_portal/lib/itPortalPocztaKursantaSeed.php — usługa „Poczta kursanta” w Portalu Usług IT (/uslugi-it/uslugi/poczta-kursanta),
 * grupa odbiorców „Kursant” (/uslugi-it/dla/kursant). Polecenie 2026-11-13: „zrób to /uslugi-it dla kursanta pomoc, że to odrębny serwer,
 * a maile z edu.feer.org.pl będą kierowane na stud.feer.org.pl na adres imienny” oraz „informacja, że rezygnujemy z MS365 i wyjaśnienie dlaczego”.
 * Tekst prostym językiem (skill humanizer: bez myślników i ozdobników).
 *
 * Źródła faktów: modules/ti_poczta_stud (login pierwszaliteraimienia.nazwisko@stud.feer.org.pl, hasło startowe SMS-em, panel: 14 dni,
 * 5 pokazań, „Mam już własne hasło”), dane serwera od użytkownika (titan.sdata.net.pl, IMAP SSL 993, SMTP SSL 465), powody odejścia
 * od Microsoft 365 z artykułu migracja/dlaczego-migrujemy (itPortalMigracjaSeed.php). Konta @edu.feer.org.pl wyłączamy 20.11.2026,
 * a lead mówi, że to skutek ostatnich wydarzeń — awarii Microsoft 365 z 12–13.11.2026 (polecenie 2026-11-13).
 * Używa migracja '2026-11_it_portal_poczta_kursanta' (modules/selfrepairDB/logic/migrations.php) i cli/it_portal_poczta_kursanta_seed.php.
 */

/**
 * Zasiew: usługa „Poczta kursanta” (nowa albo nadpisany wstęp, odbiorcy: Kursant) i jej artykuły (nowe albo nadpisane).
 * Bezpieczne do ponownego uruchomienia. @return array{category:string, created:list<string>, updated:list<string>}
 */
function itPortalPocztaKursantaApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'created' => [], 'updated' => []];
    $intro = itPortalSanitizeHtml(itPortalPocztaKursantaIntro($app));
    $aud = array_column(itPortalAudiences(), 'id', 'name');
    $audIds = array_values(array_filter([(int)($aud['Kursant'] ?? 0)]));

    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'poczta-kursanta'");
    if ($cat) {
        $catId = (int)$cat['id'];
        $rep['category'] = 'wstęp nadpisany';
        if (!$dryRun) db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?",
                              [$intro, itPortalPlain($intro), $catId]);
    } else {
        $rep['category'] = 'usługa utworzona';
        $catId = 0;
        if (!$dryRun) {
            $catId = itPortalCategorySave(['name' => 'Poczta kursanta', 'slug' => 'poczta-kursanta', 'icon' => 'envelope', 'sort_order' => 6, 'is_active' => 1,
                'audiences' => $audIds, 'intro_html' => $intro]);
        }
    }

    $i = 0;
    foreach (itPortalPocztaKursantaArticles($app) as $slug => [$title, $keywords, $blocks, $pinned]) {
        $old = $catId ? db_one("SELECT id FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]) : null;
        $rep[$old ? 'updated' : 'created'][] = "poczta-kursanta/{$slug} — {$title}";
        if ($dryRun) continue;
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10], $old ? (int)$old['id'] : 0);
    }
    return $rep;
}

/** Wstęp strony usługi „Poczta kursanta”. */
function itPortalPocztaKursantaIntro(string $app): string {
    return '<p>Po ostatnich awariach Microsoft 365 Fundacja rezygnuje z jego usług. Konta Microsoft uczestników w domenie <code>@edu.feer.org.pl</code> '
         . 'zostały ze względów bezpieczeństwa wyłączone. Adres <code>@edu.feer.org.pl</code> działa dalej jako alias poczty <code>@stud.feer.org.pl</code>.</p>'
         . '<p>W zamian uczestnicy szkoleń dostają imienny adres e-mail w domenie <code>@stud.feer.org.pl</code>. '
         . 'Ta poczta działa na odrębnym serwerze i ma własne hasło, inne niż do panelu kursanta.</p>'
         . '<p>Jak się zalogować i jak dodać pocztę do telefonu, opisuje artykuł '
         . '<a href="' . $app . '/uslugi-it/uslugi/poczta-kursanta/logowanie">Poczta kursanta: login, hasło i logowanie</a>. '
         . 'Powody zmian znajdziesz w artykule <a href="' . $app . '/uslugi-it/uslugi/poczta-kursanta/rezygnujemy-z-microsoft-365">Dlaczego rezygnujemy z Microsoft 365</a>.</p>';
}

/**
 * Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty na Start].
 * @return array<string, array{0:string,1:string,2:array,3:bool}>
 */
function itPortalPocztaKursantaArticles(string $app): array {
    $panel = $app . '/karty30/ti/kursant/index.php?tab=poczta';
    $pomoc = $app . '/karty30/ti/kursant/index.php?tab=problem';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'logowanie' => ['Poczta kursanta: login, hasło i logowanie',
            'poczta kursanta, stud.feer.org.pl, stud, edu.feer.org.pl, edu, e-mail, skrzynka, login, hasło, hasło startowe, sms, titan, titan.sdata.net.pl, sdata, imap, smtp, 993, 465, thunderbird, outlook, telefon, przekierowanie, odrębny serwer, 20 listopada, wyłączenie', [
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Konto Microsoft <code>@edu.feer.org.pl</code> zostało ze względów bezpieczeństwa wyłączone. Adres <code>@edu.feer.org.pl</code> działa dalej jako alias: wiadomości na niego trafiają na Twój adres <code>@stud.feer.org.pl</code>. '
                . 'Twoją pocztą w Fundacji jest poczta kursanta, opisana poniżej. Dlaczego tak, wyjaśnia artykuł '
                . '<a href="' . $app . '/uslugi-it/uslugi/poczta-kursanta/rezygnujemy-z-microsoft-365">Dlaczego rezygnujemy z Microsoft 365</a>.</p>'],
            ['type' => 'text', 'html' => '<p>Poczta kursanta to Twoja skrzynka e-mail w Fundacji, w domenie <code>@stud.feer.org.pl</code>. '
                . 'Działa na odrębnym serwerze pocztowym, który prowadzi sdata.net.pl. Ma własne hasło, inne niż hasło do panelu kursanta. '
                . 'Pocztę otwierasz w przeglądarce na stronie <a href="https://titan.sdata.net.pl/">titan.sdata.net.pl</a>.</p>'],

            ['type' => 'text', 'html' => '<h2>Twój adres i login</h2>'
                . '<p>Adres składa się z pierwszej litery imienia, kropki i nazwiska, bez polskich znaków, na przykład <code>j.kowalski@stud.feer.org.pl</code>. '
                . 'Gdy taki adres ma już inna osoba, na końcu dodajemy cyfrę, na przykład <code>j.kowalski2@stud.feer.org.pl</code>.</p>'
                . '<p>Pełny adres jest jednocześnie loginem. Przyślemy go e-mailem, a zobaczysz go też w <a href="' . $panel . '">panelu kursanta, w zakładce „Poczta kursanta”</a>.</p>'],

            ['type' => 'text', 'html' => '<h2>Hasło startowe</h2>'
                . '<p>Hasło startowe wysyłamy SMS-em na numer telefonu, który podałeś(-aś) Fundacji. E-mailem haseł nie wysyłamy.</p>'
                . '<p>Gdy SMS nie dotrze albo go usuniesz, hasło pokaże panel kursanta w zakładce „Poczta kursanta”. Najpierw wpiszesz kod jednorazowy, który przyjdzie SMS-em. '
                . 'Panel pokazuje hasło startowe najwyżej 5 razy i trzyma je przez 14 dni od wpisania. Potem usuwa je ze swojej bazy.</p>'],

            ['type' => 'wizard', 'title' => 'Pierwsze logowanie', 'intro' => '<p>Przygotuj login z e-maila od Fundacji i hasło z SMS-a.</p>', 'steps' => [
                $step('Otwórz pocztę', '<p>W przeglądarce wejdź na <a href="https://titan.sdata.net.pl/">https://titan.sdata.net.pl/</a>.</p>'),
                $step('Zaloguj się', '<p>Jako login wpisz pełny adres, na przykład <code>j.kowalski@stud.feer.org.pl</code>. Hasło przepisz z SMS-a.</p>'),
                $step('Ustaw własne hasło', '<p>Po zalogowaniu zmień hasło startowe na własne w ustawieniach poczty. Wybierz hasło, którego nie używasz nigdzie indziej.</p>'),
                $step('Usuń hasło startowe z panelu', '<p>W <a href="' . $panel . '">panelu kursanta, w zakładce „Poczta kursanta”</a>, kliknij „Mam już własne hasło”. '
                    . 'Hasło startowe zniknie z panelu.</p>'),
            ]],

            ['type' => 'text', 'html' => '<h2>Poczta w telefonie albo w programie pocztowym</h2>'
                . '<p>Pocztę dodasz do telefonu, Thunderbirda albo Outlooka jako konto IMAP. Podaj takie dane:</p>'
                . '<table><thead><tr><th>Ustawienie</th><th>Wartość</th></tr></thead><tbody>'
                . '<tr><td>Adres serwera poczty przychodzącej i wychodzącej</td><td><code>titan.sdata.net.pl</code></td></tr>'
                . '<tr><td>Nazwa użytkownika</td><td>pełny adres, np. <code>j.kowalski@stud.feer.org.pl</code></td></tr>'
                . '<tr><td>Hasło</td><td>hasło do poczty kursanta</td></tr>'
                . '<tr><td>Poczta przychodząca (IMAP)</td><td>szyfrowanie SSL/TLS: tak, port 993</td></tr>'
                . '<tr><td>Poczta wychodząca (SMTP)</td><td>szyfrowanie SSL/TLS: tak, port 465</td></tr>'
                . '</tbody></table>'],

            ['type' => 'text', 'html' => '<h2>Konta i wiadomości @edu.feer.org.pl</h2>'
                . '<p>Konto Microsoft w domenie <code>@edu.feer.org.pl</code> zostało ze względów bezpieczeństwa wyłączone. Nie zalogujesz się już nim do Outlooka, Teams ani OneDrive.</p>'
                . '<p>Adres <code>@edu.feer.org.pl</code> działa dalej jako alias Twojego imiennego adresu <code>@stud.feer.org.pl</code>: wiadomości na niego trafiają do nowej skrzynki. '
                . 'Nowy adres podawaj już osobom i instytucjom, z którymi piszesz.</p>'],

            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nikt z Fundacji nie poprosi Cię o hasło ani o kod z SMS-a. '
                . 'Jeśli przyjdzie SMS z kodem, o który nie prosiłeś(-aś), napisz do nas przez <a href="' . $pomoc . '">zakładkę Pomoc w panelu kursanta</a>.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie możesz się zalogować albo nie dostałeś(-aś) SMS-a z hasłem? Napisz przez '
                . '<a href="' . $pomoc . '">zakładkę Pomoc w panelu kursanta</a>. Biuro może wysłać dane ponownie.</p>'],
        ], false],

        'rezygnujemy-z-microsoft-365' => ['Dlaczego rezygnujemy z Microsoft 365',
            'microsoft 365, office 365, ms365, m365, rezygnacja, dlaczego, powód, licencje, ceny, awaria, 12 listopada, 13 listopada, 20 listopada, wyłączenie, edu.feer.org.pl, stud.feer.org.pl, poczta kursanta, imap, smtp', [
            ['type' => 'text', 'html' => '<p>Po ostatnich wydarzeniach Fundacja rezygnuje z Microsoft 365. 12 i 13 listopada 2026 r. przyszła jedna z największych awarii '
                . 'tej usługi: poczta w Outlooku, Teams i SharePoint nie działały w 74 krajach, a Microsoft jeszcze następnego dnia przywracał ich działanie. '
                . 'Konta Microsoft uczestników w domenie <code>@edu.feer.org.pl</code> zostały ze względów bezpieczeństwa wyłączone, a adres <code>@edu.feer.org.pl</code> działa jako alias poczty <code>@stud.feer.org.pl</code>.</p>'
                . '<p>Pocztę, pliki i logowanie przenosimy do usług, nad którymi mamy kontrolę. Uczestnicy szkoleń dostają w zamian pocztę kursanta: '
                . 'imienny adres <code>@stud.feer.org.pl</code> na odrębnym serwerze.</p>'],

            ['type' => 'text', 'html' => '<h2>Dlaczego</h2><ul>'
                . '<li>1 lipca 2025 r. Microsoft zakończył bezpłatne licencje Microsoft 365 Business Premium i Office 365 E1 dla organizacji pozarządowych. '
                . 'Za darmo zostaje tylko pakiet podstawowy. Pełny pakiet trzeba kupować za każdą osobę i co miesiąc.</li>'
                . '<li>1 lipca 2026 r. Microsoft podniósł ceny części pakietów Microsoft 365.</li>'
                . '<li>Listopadowa awaria nie była pierwsza. 22 stycznia Outlook i Teams nie działały u dziesiątek tysięcy osób, '
                . 'a 31 sierpnia przez kilka godzin na całym świecie nie dało się korzystać z poczty.</li></ul>'
                . '<p>Gdy poczta, pliki i spotkania są u jednego dostawcy, jego awaria albo zmiana cennika zatrzymuje pracę całej Fundacji. '
                . 'Nowa poczta działa na otwartych standardach IMAP i SMTP. W razie potrzeby przeniesiemy ją do innego dostawcy, a Twój adres zostanie ten sam.</p>'],

            ['type' => 'text', 'html' => '<h2>Co to zmienia dla Ciebie</h2><ul>'
                . '<li>Konto Microsoft <code>@edu.feer.org.pl</code> zostało ze względów bezpieczeństwa wyłączone.</li>'
                . '<li>Dostajesz imienny adres <code>@stud.feer.org.pl</code>. Login przyjdzie e-mailem, a hasło startowe SMS-em.</li>'
                . '<li>Adres <code>@edu.feer.org.pl</code> jest aliasem nowego adresu: wiadomości na niego trafiają do skrzynki <code>@stud.feer.org.pl</code>.</li>'
                . '<li>Pocztę otwierasz na <a href="https://titan.sdata.net.pl/">titan.sdata.net.pl</a> albo w programie pocztowym.</li></ul>'
                . '<p>Szczegóły logowania opisuje artykuł <a href="' . $app . '/uslugi-it/uslugi/poczta-kursanta/logowanie">Poczta kursanta: login, hasło i logowanie</a>.</p>'],

            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Pełny opis zmian w Fundacji, z awariami Microsoft 365 w 2026 r., jest w artykule '
                . '<a href="' . $app . '/uslugi-it/uslugi/migracja/dlaczego-migrujemy">Dlaczego odchodzimy od Microsoft 365</a>.</p>'],
        ], false],
    ];
}
