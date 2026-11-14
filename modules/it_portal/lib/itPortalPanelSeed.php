<?php
/**
 * modules/it_portal/lib/itPortalPanelSeed.php — usługa „Panel współpracownika” w Portalu Usług IT (/uslugi-it/uslugi/panel-wspolpracownika).
 * Polecenia 2026-11-14: „i zmianę danych w SZO”, „ogólnie pulpit w SZO”, „ten panel współpracownika”, „opisz też formularze zmiany danych”.
 * Źródła: panel/launcher.php (strona startowa po logowaniu), panel/includes/pv_staff_home.php (kafle kadry, „Grupuj wg funkcji”),
 * panel/includes/pv_home.php (kafle wolontariusza i umowy), modules/collaborator_data (Zmiana danych: kontakt, adres, rozliczenia,
 * wniosek SZD podpisywany kodem SMS albo e-mail ważnym 10 minut, rachunek bankowy z akceptacją, dziennik zmian, wnioski i upoważnienia).
 * Usługa „Zmiana danych w systemie” założona ręcznie w panelu portalu zostaje bez zmian. Używa migracja '2026-11_it_portal_panel'.
 */

function itPortalPanelApply(string $app): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $intro = itPortalSanitizeHtml('<p>Panel współpracownika to Twoja strona startowa w SZO. Po zalogowaniu widzisz na niej kafle z tym, czego potrzebujesz w pracy: '
        . 'zadania, wiadomości, wnioski, dane, pomoc IT i inne moduły. Zestaw kafli zależy od tego, czy jesteś pracownikiem, wolontariuszem czy masz umowę zlecenie.</p>'
        . '<p>Jeśli czegoś w instrukcjach brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'panel-wspolpracownika'");
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Panel współpracownika', 'slug' => 'panel-wspolpracownika', 'icon' => 'user', 'sort_order' => 15, 'is_active' => 1,
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0, $aud['Zleceniobiorca'] ?? 0])),
            'intro_html' => $intro, 'tile_group' => 'Konto i logowanie']);
    }
    $rep = []; $i = 0;
    foreach (itPortalPanelArticles($app) as $slug => [$title, $keywords, $blocks, $pinned]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $rep[] = "panel-wspolpracownika/{$slug} — {$title}";
    }
    return $rep;
}

/** slug => [tytuł, słowa kluczowe, bloki, przypięty] */
function itPortalPanelArticles(string $app): array {
    $b = $app . '/uslugi-it/uslugi/panel-wspolpracownika/';
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'moj-panel' => ['Mój panel w SZO: strona startowa i kafle', 'mój panel, panel współpracownika, pulpit szo, strona startowa, launcher, kafle, grupuj wg funkcji, moduły, menu, moje zadania, komunikaty, katalog, wiadomości, wnioski, zmiana danych, helpdesk, zmiana haseł, ewidencja godzin, pisma, zgody, kalendarz, procedury', [
            ['type' => 'text', 'html' => '<p>Po zalogowaniu do SZO trafiasz na stronę startową, a z niej do swojego panelu. Panel to plansza kafli. Każdy kafel otwiera jedną funkcję, '
                . 'a liczba na kaflu pokazuje, ile rzeczy na Ciebie czeka, na przykład nieprzeczytanych wiadomości albo otwartych zadań.</p>'
                . '<p>Do innych modułów SZO, takich jak EZD czy Zadania, przejdziesz też ikoną z kropkami w górnym pasku. Otwiera ona listę wszystkich modułów, do których masz dostęp.</p>'],
            ['type' => 'text', 'html' => '<h2>Kafle pracownika</h2>'
                . '<p>Pracownicy i kadra widzą między innymi: „Moje zadania”, „Komunikaty”, „Katalog” współpracowników, „Wiadomości”, „Wnioski”, „Zmiana danych”, „Helpdesk IT”, „Czat Pomocy IT”, '
                . '„Zmiana haseł”, „Kalendarz” i „Moduł Zadania”. Przełącznik „Grupuj wg funkcji” układa kafle w grupy: praca, komunikacja, konto, pomoc i administracja. '
                . 'Gdy go wyłączysz, kafle tworzą jedną planszę.</p>'],
            ['type' => 'text', 'html' => '<h2>Kafle wolontariusza i osoby z umową</h2>'
                . '<p>Wolontariusze i osoby z umową widzą kafle związane ze swoją umową, między innymi: „Zmiana danych”, „Ewidencja godzin”, „Godziny i rozliczenia” przy umowie zlecenie, '
                . '„Pisma”, „Zgody i oświadczenia”, „Twoje dane (RODO)”, „Asystent AI”, „Kalendarz”, „Procedury”, „Dokumenty organizacji”, „Katalog”, „Ustawienia konta” i „Czat Pomocy IT”. '
                . 'Dydaktycy mają też kafel do swojego panelu dydaktyka.</p>'
                . '<p>Zestaw kafli zależy od Twojej roli i umowy, więc u Ciebie może ich być więcej albo mniej.</p>'],
            ['type' => 'text', 'html' => '<h2>Najczęstsze sprawy</h2><ul>'
                . '<li>Zmiana telefonu, adresu albo rachunku: kafel „Zmiana danych”, opisany w artykule <a href="' . $b . 'zmiana-danych">Zmiana swoich danych w SZO</a>.</li>'
                . '<li>Zmiana hasła: kafel „Zmiana haseł”, który prowadzi na stronę <a href="' . $app . '/nowehaslo">Nowe hasło</a>.</li>'
                . '<li>Wniosek albo pismo do Fundacji: kafel „Wnioski”.</li>'
                . '<li>Problem z komputerem, kontem albo pocztą: kafel „Helpdesk IT” albo „Czat Pomocy IT”.</li>'
                . '<li>Zadania zespołu: kafel „Moje zadania” albo „Moduł Zadania”, opisane w usłudze <a href="' . $app . '/uslugi-it/uslugi/zadania-szo">Zadania w SZO</a>.</li></ul>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie widzisz kafla, którego potrzebujesz? Część funkcji włącza administrator dla wybranych osób albo umów. <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
        ], true],

        'zmiana-danych' => ['Zmiana swoich danych w SZO: telefon, adres, rachunek', 'zmiana danych, formularz zmiany danych, moje dane, zmiana telefonu, urząd skarbowy, adres do korespondencji, szd, skk, upo1, upo2, upo3, dyd1, upoważnienie osoby trzeciej, odwołanie upoważnienia, zmiana adresu, zmiana e-maila, rachunek bankowy, numer konta, dane do rozliczeń, wniosek szd, podpis kodem, sms, dziennik zmian, upoważnienia, kanał kontaktu', [
            ['type' => 'text', 'html' => '<p>Swoje dane kontaktowe, adres i dane do rozliczeń zmieniasz sam w panelu, kaflem „Zmiana danych”. Każda zmiana jest wnioskiem, który podpisujesz kodem, '
                . 'a wszystkie zmiany zapisują się w dzienniku: kto, kiedy i co zmienił.</p>'],
            ['type' => 'wizard', 'title' => 'Zmiana danych krok po kroku', 'intro' => '', 'steps' => [
                $step('Otwórz Zmianę danych', '<p>W swoim panelu kliknij kafel „Zmiana danych”. Zobaczysz formularz podzielony na części: kontakt, adres i dane do rozliczeń.</p>'),
                $step('Popraw dane', '<p>Zmień tylko te pola, które się zmieniły, na przykład numer telefonu albo adres. Pola oznaczone jako wymagające akceptacji, na przykład rachunek bankowy, sprawdzi jeszcze administracja.</p>'),
                $step('Zapisz i podpisz', '<p>Kliknij „Zapisz i podpisz wniosek SZD”. Na ekranie zobaczysz podsumowanie zmian, a na telefon przyjdzie SMS z kodem. Czasem kod przychodzi e-mailem, wtedy strona mówi, na jaki adres.</p>'),
                $step('Wpisz kod', '<p>Wpisz kod i zatwierdź. Kod jest ważny 10 minut. Jeśli nie przyszedł, użyj przycisku ponownej wysyłki. Jeśli się rozmyśliłeś, anuluj wniosek.</p>'),
                $step('Sprawdź dziennik', '<p>Po podpisaniu zmiany wchodzą od razu, a zmiana rachunku bankowego dopiero po akceptacji. W części „Dziennik zmian” widzisz swoje zmiany i ich status.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Pola formularza</h2>'
                . '<p>Część „Kontakt”: „Telefon” i „Dodatkowy adres e-mail”. Na telefon przychodzą kody i SMS-y z Fundacji, więc wpisz numer, który masz zawsze przy sobie.</p>'
                . '<p>Część „Adres”: „Ulica”, „Nr domu”, „Nr lokalu”, „Kod pocztowy”, „Miejscowość”, „Kraj” i „Adres do korespondencji (jeśli inny)”. '
                . 'Jeśli nie masz jeszcze karty w rejestrze osób, założymy ją przy pierwszym zapisie adresu albo danych rozliczeniowych.</p>'
                . '<p>Część „Dane rozliczeniowe”: „Numer rachunku bankowego” i „Urząd skarbowy”. Zmiana rachunku wymaga akceptacji, bo na ten rachunek wypłacamy pieniądze. '
                . 'Przy takich polach jest oznaczenie „akceptacja”.</p>'],
            ['type' => 'text', 'html' => '<h2>Wnioski i upoważnienia</h2>'
                . '<p>Obok formularza jest część „Wnioski i upoważnienia” z kaflami wniosków, które otwierają kreator w module „Wnioski”:</p><ul>'
                . '<li>SZD, wniosek o zmianę danych w systemie, gdy chcesz zmienić dane, których nie ma w formularzu, na przykład imię albo nazwisko.</li>'
                . '<li>SKK, wniosek o zmianę kanału kontaktu, czyli tego, jak Fundacja ma się z Tobą kontaktować.</li>'
                . '<li>UPO1, wniosek o wydanie upoważnienia.</li>'
                . '<li>UPO2, upoważnienie osoby trzeciej do kontaktu, na przykład członka rodziny, który może w Twoim imieniu załatwiać sprawy.</li>'
                . '<li>UPO3, odwołanie upoważnienia.</li>'
                . '<li>DYD1, upoważnienie do wglądu w panel.</li></ul>'
                . '<p>Lista wniosków może być krótsza albo dłuższa, zależnie od tego, które rodzaje są włączone.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Hasła nie zmieniasz w „Zmianie danych”, tylko na stronie <a href="' . $app . '/nowehaslo">Nowe hasło</a>. '
                . 'Jeśli zmieniasz numer telefonu, a stary numer już nie działa i kod nie przychodzi, <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Wszystkie kafle panelu opisuje artykuł <a href="' . $b . 'moj-panel">Mój panel w SZO</a>.</p>'],
        ], false],
    ];
}
