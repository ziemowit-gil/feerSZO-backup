<?php
/**
 * modules/it_portal/lib/itPortalVcardySeed.php — artykuł „Kontakty z Outlooka do pliku (VCF albo CSV)” w usłudze „Moja poczta”
 * (/uslugi-it/uslugi/moja-poczta/kontakty-z-outlooka). Polecenie 2026-11-15: eksporter kontaktów z Outlooka (Microsoft 365) do innego
 * systemu w CSV albo VCF i strona pomocy /pomocne/vcardy (modules/vcardy). Microsoft 365 wyłączamy 20 listopada 2026 r.
 *
 * Opisuje: pobranie pliku na /pomocne/vcardy, wybór formatu, import do chmury (aplikacja Kontakty), dPoczty, iPhone'a, Androida,
 * Thunderbirda; kopię z przeniesienia do chmury (PimImport) po wyłączeniu Microsoft 365; ochronę pliku z danymi osobowymi.
 * „Zobacz też” w obie strony z artykułami o kontaktach (tylko te, które istnieją; dopisuje, nie nadpisuje cudzych powiązań).
 * Uruchamiają: migracja '2026-11_it_portal_vcardy' i cli/it_portal_vcardy_seed.php. Ponowne uruchomienie nadpisuje treść artykułu.
 */

/** Artykuły łączone w „Zobacz też”: [usługa, slug]. */
const ITP_VCARDY_RELATED = [['moja-poczta', 'kontakty'], ['moja-poczta', 'kontakty-import-eksport'], ['chmura-plikow', 'kontakty-i-kalendarz'], ['moja-poczta', 'dpoczta-a-outlook']];

/** @return array{category:string, saved:string, linked:list<string>} */
function itPortalVcardyApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    require_once __DIR__ . '/itPortalCloudSeed.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $rep = ['category' => '', 'saved' => '', 'linked' => []];
    $cat = db_one("SELECT id, name FROM itp_categories WHERE slug = 'moja-poczta'");
    if (!$cat) { $rep['category'] = 'brak usługi „Moja poczta” (najpierw php cli/it_portal_dpoczta_seed.php)'; return $rep; }
    $catId = (int)$cat['id'];
    $rep['category'] = (string)$cat['name'];
    $slug = 'kontakty-z-outlooka';
    [$title, $keywords, $blocks] = itPortalVcardyArticle($app);
    $old = db_one("SELECT id, sort_order, related, is_published, is_pinned FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $rep['saved'] = ($old ? 'nadpisany' : 'nowy') . ": moja-poczta/{$slug} — {$title}";

    $find = static fn(string $c, string $a) => db_one("SELECT a.id, a.title, a.related FROM itp_articles a JOIN itp_categories c ON c.id = a.category_id WHERE c.slug = ? AND a.slug = ?", [$c, $a]);
    $rel = [];
    foreach (ITP_VCARDY_RELATED as [$c, $a]) if ($r = $find($c, $a)) $rel[(int)$r['id']] = $r;
    if ($dryRun) { foreach ($rel as $r) $rep['linked'][] = (string)$r['title']; return $rep; }

    $id = itPortalArticleSave([
        'title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
        'is_published' => $old ? (int)$old['is_published'] : 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : 0,
        'sort_order' => $old ? (int)$old['sort_order'] : 50, 'menu_group' => 'Telefon, programy, kontakty i kalendarz',
        'related' => array_values(array_unique(array_merge(itPortalIds($old['related'] ?? '[]'), array_keys($rel)))),
    ], $old ? (int)$old['id'] : 0);
    // w drugą stronę: ten artykuł w „Zobacz też” powiązanych (dopisanie do ich listy)
    foreach ($rel as $rid => $r) {
        $ids = itPortalIds($r['related']);
        if (in_array($id, $ids, true)) continue;
        $ids[] = $id;
        db_exec("UPDATE itp_articles SET related = ? WHERE id = ?", [json_encode(array_values(array_unique($ids))), $rid]);
        $rep['linked'][] = (string)$r['title'];
    }
    return $rep;
}

/** @return array{0:string,1:string,2:array} tytuł, słowa kluczowe, bloki */
function itPortalVcardyArticle(string $app): array {
    $page  = $app . '/pomocne/vcardy';
    $dav   = $app . '/kalendarz-kontakty';
    $hd    = $app . '/helpdesk/new.php';
    $cloud = itPortalCloudUrl();
    $step  = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    $blocks = [
        ['type' => 'callout', 'kind' => 'important', 'html' => '<p>Microsoft 365 wyłączamy 20 listopada 2026 r. Potem Outlook w Microsoft 365 przestanie działać. '
            . 'Jeśli trzymasz w nim kontakty, pobierz je wcześniej na stronie <a href="' . $page . '">Kontakty z Outlooka</a> w SZO.</p>'],

        ['type' => 'text', 'html' => '<p>Strona w SZO pobiera kontakty z Twojej skrzynki Outlook w Microsoft 365 i zapisuje je w jednym pliku. '
            . 'Ten plik wczytasz potem do chmury Fundacji, do poczty dPoczta, do telefonu albo do programu pocztowego. '
            . 'Zalogowana osoba widzi tylko swoje konto Microsoft 365. Kontaktów innych osób strona nie pokazuje.</p>'
            . '<p>Plik może mieć jeden z dwóch formatów. VCF, czyli vCard, wczytasz w chmurze, w telefonie i w większości programów. '
            . 'Jeśli nie wiesz, który wybrać, wybierz ten. CSV to arkusz z kolumnami w układzie Outlooka. Przyda się w poczcie dPoczta, '
            . 'a otworzysz go też w Excelu albo w LibreOffice.</p>'],

        ['type' => 'wizard', 'title' => 'Pobranie pliku', 'intro' => '', 'steps' => [
            $step('Otwórz stronę w SZO', '<p>Zaloguj się do SZO i wejdź na stronę <a href="' . $page . '">Kontakty z Outlooka</a>. '
                . 'Strona sama znajdzie Twoje konto Microsoft 365.</p>'),
            $step('Wybierz kontakty', '<p>Jeśli w Outlooku masz kontakty w kilku folderach, wybierz jeden albo zostaw „Wszystkie foldery”. '
                . 'Nazwa folderu trafi do kategorii kontaktu, więc po imporcie łatwo je rozpoznasz.</p>'),
            $step('Wybierz format', '<p>Zaznacz VCF albo CSV i kliknij „Pobierz kontakty”. Plik zapisze się w folderze Pobrane. '
                . 'W nazwie ma Twój login i dzisiejszą datę.</p>'),
        ]],
        ['type' => 'text', 'html' => '<p>Przenosimy imię i nazwisko, adresy e-mail, numery telefonów, firmę, dział i stanowisko, adresy pocztowe, '
            . 'datę urodzin, stronę WWW, notatki i kategorie. Zdjęć kontaktów nie przenosimy.</p>'],

        ['type' => 'text', 'html' => '<h2>Chmura Fundacji</h2>'
            . '<p>Kontakty w chmurze możesz mieć też w telefonie i w programie na komputerze, a zmiana zrobiona w jednym miejscu pojawi się w pozostałych.</p><ol>'
            . '<li>Zaloguj się do <a href="' . $cloud . '">chmury Fundacji</a> i otwórz aplikację Kontakty z menu na górnym pasku.</li>'
            . '<li>Na dole lewego panelu kliknij „Ustawienia kontaktów”.</li>'
            . '<li>Kliknij „Importuj kontakty”, wybierz książkę adresową i wskaż pobrany plik VCF z komputera.</li></ol>'
            . '<p>Administrator przenosi też kontakty z Outlooka do chmury sam. Trafiają do książki adresowej „Kontakty z Outlooka”. '
            . 'Jeśli ją już masz, nie wczytuj pliku do chmury drugi raz, bo kontakty się zdublują. Strona w SZO pokazuje, czy Twoje kontakty są już w chmurze.</p>'
            . '<p>Jak połączyć kontakty z chmury z telefonem, przeczytasz w artykule <a href="' . $app . '/uslugi-it/uslugi/chmura-plikow/kontakty-i-kalendarz">'
            . 'Kalendarz i kontakty z chmury w poczcie, telefonie i programie</a>. Samo połączenie zrobisz na stronie <a href="' . $dav . '">Kalendarz i kontakty z chmury</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Poczta dPoczta</h2><ol>'
            . '<li>Zaloguj się na <a href="https://dpoczta.pl">dpoczta.pl</a> i przejdź do „Kontakty”.</li>'
            . '<li>Kliknij ikonę chmurki do importu na górnym pasku. Podpowiedź pojawi się po najechaniu kursorem.</li>'
            . '<li>Wskaż pobrany plik CSV albo VCF i kliknij „Importuj”.</li></ol>'
            . '<p>Kontakty w dPoczcie zostają w poczcie. Nie łączą się z chmurą ani z telefonem. '
            . 'Gdy wczytasz ten sam plik i tu, i w chmurze, będziesz mieć dwie osobne kopie, które same się nie zsynchronizują.</p>'],

        ['type' => 'text', 'html' => '<h2>iPhone i iPad</h2><ol>'
            . '<li>Wyślij sobie plik VCF e-mailem albo zapisz go w aplikacji Pliki.</li>'
            . '<li>Stuknij plik i wybierz „Dodaj wszystkie kontakty”.</li></ol>'
            . '<h2>Telefon z Androidem</h2><ol>'
            . '<li>Skopiuj plik VCF na telefon, na przykład wyślij go sobie e-mailem i zapisz.</li>'
            . '<li>W aplikacji Kontakty otwórz menu, potem „Ustawienia” albo „Zarządzaj kontaktami”, i wybierz „Importuj”.</li>'
            . '<li>Wskaż plik i konto, na którym mają się zapisać kontakty.</li></ol>'
            . '<p>Nazwy pozycji w menu różnią się trochę między telefonami. Kontakty wczytane z pliku zostają tylko w telefonie. '
            . 'Jeśli chcesz mieć je też w chmurze, wczytaj plik do chmury i połącz z nią telefon.</p>'],

        ['type' => 'text', 'html' => '<h2>Thunderbird i inne programy</h2>'
            . '<p>W Thunderbirdzie otwórz Książkę adresową, wybierz „Narzędzia” i „Importuj”, a potem wskaż plik VCF albo CSV. '
            . 'Inne programy pocztowe zwykle mają podobną opcję importu kontaktów.</p>'],

        ['type' => 'text', 'html' => '<h2>Po wyłączeniu Microsoft 365</h2>'
            . '<p>Gdy Microsoft 365 już nie odpowiada, strona w SZO może dać Ci kopię kontaktów zrobioną przy przenoszeniu do chmury. '
            . 'Zobaczysz wtedy datę tej kopii i przycisk „Pobierz kopię kontaktów”. Jeśli kopii nie ma, <a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>W pliku są dane osobowe innych osób. Nie wysyłaj go innym osobom i nie zostawiaj na wspólnym komputerze. '
            . 'Gdy wczytasz kontakty, usuń plik z folderu Pobrane i z Kosza. Jeśli plik był w wiadomości e-mail, usuń też tę wiadomość.</p>'],
        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Coś nie działa? <a href="' . $hd . '">Napisz do Helpdesku IT</a> i podaj, gdzie wczytujesz kontakty '
            . 'i jaki komunikat się pojawił. Nie wysyłaj nam pliku z kontaktami.</p>'],
    ];
    return ['Kontakty z Outlooka do pliku (VCF albo CSV)',
        'kontakty, outlook, microsoft 365, eksport, import, pobierz kontakty, plik, vcf, vcard, csv, książka adresowa, przeniesienie kontaktów, '
        . 'wyłączenie microsoft 365, chmura, nextcloud, dpoczta, roundcube, telefon, iphone, android, thunderbird, excel, pomocne/vcardy, vcardy',
        $blocks];
}
