<?php
/**
 * modules/it_portal/lib/itPortalZadaniaSeed.php — usługa „Zadania w SZO” w Portalu Usług IT (/uslugi-it/uslugi/zadania-szo).
 * Polecenie 2026-11-14: „opisz zadania w SZO” (wcześniej: „zadań [w dPoczcie] nie używamy, są od tego /tasks w SZO”).
 * Źródła: moduł tasks/ (pasek sekcji tasks/includes/tasks_pivot.php, okno zadania, powiadomienia, zadania z e-maila, archiwum po 7 dniach,
 * potwierdzanie Z/ZP/O, SLA, obciążenie, reguły, szablony, zespoły, uprawnienia pól i plików). Prosty język (humanizer), bez myślników i tabel.
 * Używa migracja '2026-11_it_portal_zadania_szo'.
 */

/** Zasiew usługi i jej artykułów (bezpieczny do ponownego uruchomienia; „Zobacz też” zostaje). @return list<string> */
function itPortalZadaniaApply(string $app): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $t = $app . '/tasks/';
    $intro = itPortalSanitizeHtml('<p>Zadania prowadzimy w SZO, w module <a href="' . $t . '">Zadania</a>. Każdy zespół i obszar ma tam swoje zadania, terminy, pliki i komentarze, '
        . 'widoczne dla wszystkich osób, które w nim pracują. Z „Zadań” w dPoczcie, z Outlooka ani z Microsoft To Do nie korzystamy.</p>'
        . '<p>Zacznij od instrukcji „Zadania w SZO: pierwsze kroki”. Jeśli czegoś brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'zadania-szo'");
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Zadania w SZO', 'slug' => 'zadania-szo', 'icon' => 'service', 'sort_order' => 26, 'is_active' => 1,
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['Wolontariusz'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }
    $rep = []; $i = 0;
    foreach (itPortalZadaniaArticles($app) as $slug => [$title, $keywords, $blocks, $pinned, $group]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => (++$i) * 10, 'menu_group' => $group, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $rep[] = "zadania-szo/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ", #{$id})";
    }
    return $rep;
}

/** Artykuły: slug => [tytuł, słowa kluczowe, bloki, przypięty, grupa w menu]. */
function itPortalZadaniaArticles(string $app): array {
    $app = rtrim($app, '/');
    $t = $app . '/tasks/';
    $b = $app . '/uslugi-it/uslugi/zadania-szo/';
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $tt, string $h): array => ['title' => $tt, 'html' => $h, 'images' => []];
    $g1 = 'Dla każdego'; $g2 = 'Dla liderów obszarów';
    return [
        'pierwsze-kroki' => ['Zadania w SZO: pierwsze kroki', 'zadania, moduł zadania, tasks, obszar, obszar roboczy, zespół, lider, członek, rola, tablica, kanban, tabela, moje zadania, wszystkie zadania, kalendarz, dashboard, skrzynka, archiwum, wykresy', [
            ['type' => 'text', 'html' => '<p>Moduł <a href="' . $t . '">Zadania</a> w SZO służy do planowania i śledzenia pracy. Zadania są pogrupowane w obszary robocze, '
                . 'na przykład obszar działu albo projektu. W każdym obszarze są kolumny, takie jak „Do zrobienia” i „Gotowe”, a w kolumnach zadania z terminem, wykonawcą, opisem, komentarzami i plikami.</p>'],
            ['type' => 'text', 'html' => '<h2>Obszary i role</h2>'
                . '<p>Widzisz tylko obszary, do których należysz, bezpośrednio albo przez swój zespół. W każdym obszarze masz jedną z ról. '
                . 'Lider obszaru zakłada i przydziela zadania, potwierdza ich wykonanie i ustawia obszar. Członek wykonuje zadania, komentuje je i dodaje pliki. '
                . 'Obserwator tylko ogląda. Zadania w obszarze zakłada lider, więc jeśli potrzebujesz nowego zadania, poproś o nie lidera albo zgłoś problem w zadaniu.</p>'
                . '<p>Obszar zmienisz przyciskiem z nazwą obszaru na górze ekranu, obok napisu „Zadania”. Lista ma wyszukiwarkę, a pozycja „Wszystkie obszary” pokazuje zadania ze wszystkich naraz.</p>'],
            ['type' => 'text', 'html' => '<h2>Pasek na górze</h2><ul>'
                . '<li>„Dashboard”: podsumowanie, czyli ile zadań jest otwartych, przeterminowanych i ukończonych.</li>'
                . '<li>„Skrzynka”: nowe sprawy, które czekają na Ciebie.</li>'
                . '<li>„Wszystkie zadania”: zadania wybranego obszaru jako tabela albo tablica z kolumnami.</li>'
                . '<li>„Moje zadania”: tylko zadania przydzielone Tobie, ze wszystkich obszarów. Od tego warto zaczynać dzień.</li>'
                . '<li>„Kalendarz”: zadania z terminami w widoku miesiąca albo listy.</li>'
                . '<li>„Pliki”: pliki obszaru i jego zadań.</li>'
                . '<li>„Archiwum”: zadania ukończone ponad 7 dni temu.</li>'
                . '<li>„Wykresy”: statystyki obszaru.</li>'
                . '<li>„Ustawienia” po prawej: między innymi powiadomienia.</li></ul>'
                . '<p>Liderzy widzą też „Problemy”, „Obciążenie” i „SLA”, opisane w artykule <a href="' . $b . 'dla-liderow">Zadania: funkcje dla liderów obszarów</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Tabela i tablica</h2>'
                . '<p>„Wszystkie zadania” pokażesz jako tabelę, wygodną do przeglądania i sortowania, albo jako tablicę z kolumnami, na której zadanie przeciągasz myszą z kolumny do kolumny, '
                . 'na przykład z „Do zrobienia” do „W trakcie”. Zadanie przeciągnięte do kolumny ukończonych jest oznaczone jako wykonane.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Dzwonek w górnym pasku Zadań pokazuje nowe powiadomienia o zadaniach i odtwarza krótki dźwięk, gdy przyjdzie coś nowego. '
                . 'Co dalej, opisują artykuły <a href="' . $b . 'praca-z-zadaniem">Praca z zadaniem</a> i <a href="' . $b . 'powiadomienia">Powiadomienia i kalendarz w telefonie</a>.</p>'],
        ], true, $g1],

        'praca-z-zadaniem' => ['Praca z zadaniem: komentarze, pliki, checklisty, terminy', 'zadanie, okno zadania, komentarz, plik, załącznik, checklista, podzadania, termin, szybkie daty, etykiety, tagi, zależności, obserwujący, powiązania, koszulka ezd, kontakt crm, umowa, powtarzanie, ukończ, zgłoś problem, potwierdzenie, archiwum', [
            ['type' => 'text', 'html' => '<p>Kliknij zadanie, a po prawej otworzy się jego okno. Po lewej są szczegóły i historia zmian, a po prawej komentarze i pliki.</p>'],
            ['type' => 'text', 'html' => '<h2>Szczegóły</h2>'
                . '<p>Na górze jest tytuł, opis, kolumna, wykonawcy, priorytet i termin. Przy polu terminu są szybkie przyciski „+7 dni”, „+14 dni” i „Za miesiąc”. '
                . 'Co możesz zmienić sam, zależy od Twojej roli w obszarze. Pól, których nie możesz edytować, nie zmienisz, ale je widzisz.</p>'
                . '<p>Niżej są kafle: „Powiązania”, czyli połączenie zadania z koszulką EZD, kontaktem w CRM, uczestnikiem szkolenia albo umową (wklejasz adres karty z SZO), '
                . '„Jednostka”, „Zależności”, czyli zadania, które muszą być skończone wcześniej, „Tagi” do oznaczania zadań i „Powtarzanie” dla zadań cyklicznych, na przykład co miesiąc.</p>'],
            ['type' => 'text', 'html' => '<h2>Komentarze, pliki i checklisty</h2>'
                . '<p>W komentarzach rozmawiasz o zadaniu, zamiast pisać osobne e-maile. Każdy komentarz widzą wszyscy w obszarze i zostaje w historii zadania.</p>'
                . '<p>Pliki zadania leżą w jego folderze. „Wgraj do folderu zadania” dodaje plik z komputera, a „Dodaj z folderów obszaru” podpina plik, który już jest w obszarze. '
                . 'Pliki zadań widzą wszyscy w obszarze.</p>'
                . '<p>Checklista dzieli zadanie na mniejsze kroki do odhaczania. Obserwujący to osoby, które nie wykonują zadania, ale dostają powiadomienia o zmianach.</p>'],
            ['type' => 'text', 'html' => '<h2>Ukończenie i potwierdzenie</h2>'
                . '<p>Gdy skończysz, oznacz zadanie jako wykonane albo przeciągnij je do kolumny ukończonych. Lider obszaru może potwierdzić wykonanie albo je odrzucić z podaniem powodu. '
                . 'W tabeli widać to w kolumnie „Weryf.”: Z to zakończone i jeszcze niesprawdzone, ZP to potwierdzone, a O to odrzucone. Odrzucone zadanie popraw i oznacz jako wykonane jeszcze raz.</p>'
                . '<p>Zadanie ukończone ponad 7 dni temu samo przechodzi do „Archiwum”. Nic nie znika, a lider może je stamtąd przywrócić.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Coś blokuje zadanie albo termin jest nierealny? Użyj w zadaniu opcji zgłoszenia problemu liderowi, zamiast czekać do terminu. '
                . 'Lider zobaczy zgłoszenie w swoich „Problemach”.</p>'],
        ], false, $g1],

        'powiadomienia' => ['Powiadomienia i kalendarz w telefonie', 'powiadomienia, e-mail, podsumowanie dzienne, dzwonek, ustawienia powiadomień, adres powiadomień, odpowiedź e-mailem, komentarz e-mailem, kalendarz, ical, ics, telefon, google calendar, outlook', [
            ['type' => 'text', 'html' => '<p>Zadania wysyłają powiadomienia, gdy dostaniesz nowe zadanie, ktoś skomentuje Twoje zadanie, zbliża się termin albo lider potwierdzi wykonanie. '
                . 'Widzisz je pod dzwonkiem w Zadaniach i dostajesz e-mailem.</p>'],
            ['type' => 'text', 'html' => '<h2>Ustawienia powiadomień</h2>'
                . '<p>Kliknij „Ustawienia” na pasku Zadań i wybierz powiadomienia. Na górze są gotowe zestawy, a niżej przełączniki dla każdego rodzaju zdarzenia. Zmiany przełączników zapisują się same.</p>'
                . '<p>Jeśli dostajesz za dużo wiadomości, włącz podsumowanie dzienne: zamiast osobnych e-maili przyjdzie jedna wiadomość dziennie. Pilne sprawy, na przykład eskalacje, przychodzą od razu.</p>'
                . '<p>Powiadomienia mogą przychodzić na inny adres niż ten z konta. Po wpisaniu nowego adresu dostaniesz na niego wiadomość z linkiem do potwierdzenia. Dopiero po kliknięciu adres zacznie działać.</p>'],
            ['type' => 'text', 'html' => '<h2>Odpowiedź e-mailem</h2>'
                . '<p>Na powiadomienie o zadaniu możesz po prostu odpisać w poczcie. Twoja odpowiedź trafi do zadania jako komentarz. Nie zmieniaj tematu wiadomości, bo po nim system rozpoznaje zadanie.</p>'],
            ['type' => 'text', 'html' => '<h2>Zadania w kalendarzu telefonu</h2>'
                . '<p>W „Kalendarzu” Zadań możesz wygenerować prywatny link do kalendarza w formacie iCal. Dodaj go w kalendarzu telefonu, Google albo w kalendarzu dPoczty jako kalendarz z adresu internetowego. '
                . 'Pokaże zadania przydzielone Tobie, które jeszcze nie są ukończone. Linku nie udostępniaj innym, bo każdy, kto go zna, zobaczy Twoje zadania.</p>'],
        ], false, $g1],

        'zadania-z-e-maila' => ['Zadania z e-maila', 'zadanie z e-maila, utwórz zadanie, skrzynka crm, poczta ezd, skrzynka obszaru, zad, potwierdzenie, imap, mail', [
            ['type' => 'text', 'html' => '<p>Wiadomość e-mail, która wymaga pracy, możesz zamienić w zadanie. Treść wiadomości trafi do opisu zadania, a załączniki do jego plików.</p>'],
            ['type' => 'text', 'html' => '<h2>Z wiadomości w SZO</h2>'
                . '<p>W Skrzynce CRM otwórz wiadomość i w menu „Więcej” wybierz „Utwórz zadanie”. W Poczcie EZD ta sama opcja jest w widoku wątku. '
                . 'Wybierz obszar i kolumnę, popraw tytuł i zapisz. Zadania w obszarze zakłada lider, więc ta opcja działa w obszarach, w których jesteś liderem.</p>'],
            ['type' => 'text', 'html' => '<h2>Skrzynka obszaru</h2>'
                . '<p>Lider może podłączyć do obszaru skrzynkę pocztową. Każda nowa wiadomość, która do niej przyjdzie, staje się zadaniem w tym obszarze, a nadawca dostaje potwierdzenie z numerem zadania w temacie, na przykład [ZAD-123]. '
                . 'Gdy nadawca odpisze na to potwierdzenie, jego odpowiedź trafi do tego samego zadania jako komentarz, a nie jako nowe zadanie.</p>'
                . '<p>System pomija automatyczne odpowiedzi i wiadomości typu no-reply, żeby nie tworzyć zadań ze śmieci.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Jak podłączyć skrzynkę obszaru, opisuje artykuł <a href="' . $b . 'dla-liderow">Zadania: funkcje dla liderów obszarów</a>.</p>'],
        ], false, $g1],

        'dla-liderow' => ['Zadania: funkcje dla liderów obszarów', 'lider, lider obszaru, nowe zadanie, przydziel, szablony, reguły automatyzacji, sla, eskalacja, obciążenie, problemy, potwierdź wykonanie, odrzuć, archiwum, przywróć, skrzynka obszaru, zespoły, uprawnienia pól, uprawnienia plików, ustawienia obszaru', [
            ['type' => 'text', 'html' => '<p>Lider obszaru zakłada i przydziela zadania, pilnuje terminów i sprawdza wykonanie. Ten artykuł opisuje funkcje, które widzą tylko liderzy.</p>'],
            ['type' => 'wizard', 'title' => 'Nowe zadanie', 'intro' => '', 'steps' => [
                $step('Kliknij Nowe zadanie', '<p>W „Wszystkich zadaniach” wybranego obszaru kliknij „Nowe zadanie”. Kolumna nie jest obowiązkowa. Bez niej zadanie trafi do pierwszej nieukończonej kolumny.</p>'),
                $step('Opisz i przydziel', '<p>Wpisz tytuł i opis, wybierz wykonawców, priorytet i termin. Szybkie przyciski przy terminie ustawią go na za tydzień, dwa tygodnie albo miesiąc.</p>'),
                $step('Zapisz', '<p>Po zapisaniu wykonawcy dostaną powiadomienie.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Sprawdzanie wykonania</h2>'
                . '<p>Gdy wykonawca oznaczy zadanie jako wykonane, możesz je potwierdzić albo odrzucić. Przy odrzuceniu podaj powód, który dostanie wykonawca. '
                . '„Problemy” na pasku pokazują problemy zgłoszone przez członków obszaru. Zadania z „Archiwum” przywrócisz, jeśli trzeba do nich wrócić.</p>'],
            ['type' => 'text', 'html' => '<h2>Szablony i reguły</h2>'
                . '<p>Szablon zadań to lista powtarzalnych kroków, na przykład przy przyjęciu nowej osoby. „Zastosuj szablon” tworzy od razu wszystkie zadania w wybranej kolumnie. '
                . 'Szablony przygotowuje administrator w „Ustawieniach”, w części „Szablony zadań”.</p>'
                . '<p>Reguły automatyzacji same coś robią, gdy zadanie powstanie, zostanie przeniesione albo ukończone, na przykład dodają tag albo przydzielają osobę. Ustawisz je w „Ustawieniach”, w części „Reguły automatyzacji”.</p>'],
            ['type' => 'text', 'html' => '<h2>Terminy i obciążenie</h2>'
                . '<p>„SLA” pozwala ustawić, ile czasu mają zadania o danym priorytecie. Gdy czas mija, system wysyła eskalację. '
                . '„Obciążenie” pokazuje, ile pracy ma każda osoba w obszarze, na podstawie szacowanego czasu zadań, i pozwala pobrać to jako plik CSV.</p>'],
            ['type' => 'text', 'html' => '<h2>Skrzynka obszaru</h2>'
                . '<p>W ustawieniach lidera możesz podłączyć do obszaru skrzynkę pocztową. Wpisz adres, serwer i hasło skrzynki. Przy zapisie system sprawdzi logowanie. '
                . 'Od tej chwili nowe wiadomości ze skrzynki będą zadaniami, jak opisuje artykuł <a href="' . $b . 'zadania-z-e-maila">Zadania z e-maila</a>.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Zespoły, uprawnienia pól i uprawnienia do plików ustawia administrator systemu. Jeśli ktoś powinien dostać dostęp do obszaru albo inną rolę, '
                . '<a href="' . $hd . '">napisz do Helpdesku IT</a>.</p>'],
        ], false, $g2],
    ];
}
