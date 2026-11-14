<?php
/**
 * modules/it_portal/lib/itPortalEzdOgolnySeed.php — wstęp usługi „EZD” i artykuły ogólne (polecenie 2026-11-14: „opisz EZD”).
 * Terminologia UI (KRYTYCZNE, zob. pamięć project_ezd_teczka_koszulka): sprawa = „Koszulka”, teczka JRWA = „Segregator” (rodzaj męski),
 * moduł = „EZD Wirtualne biurko”; „w koszulce (RPW)” w dzienniku podawczym = przesyłka zarejestrowana, nieprzydzielona.
 * Menu EZD (includes/menu.php): Pulpit EZD, Dziennik podawczy, Koszulki, Segregatory aktowe, Wykaz akt (JRWA), Terminarz, Do podpisu,
 * Poczta EZD, Pełnomocnictwa, Zaświadczenia, Rejestr dokumentów, Kody partnerskie. Grupy menu: Na start / Codzienna praca / Narzędzia.
 * Używa migracja '2026-11_it_portal_ezd_ogolny'.
 */

function itPortalEzdOgolnyApply(string $app): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'ezd'");
    if (!$cat) return ['brak usługi „EZD” — pominięto'];
    $catId = (int)$cat['id'];
    $b = $app . '/uslugi-it/uslugi/ezd/';
    $intro = itPortalSanitizeHtml('<p>EZD Wirtualne biurko to elektroniczne zarządzanie dokumentacją w SZO. Rejestrujesz w nim przychodzącą i wychodzącą korespondencję, '
        . 'prowadzisz sprawy w koszulkach, przekazujesz pisma do załatwienia i podpisu i przechowujesz dokumenty zgodnie z wykazem akt.</p>'
        . '<p>Zacznij od instrukcji <a href="' . $b . 'pierwsze-kroki">EZD Wirtualne biurko: pierwsze kroki</a>. '
        . 'Jeśli czegoś w niej brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>');
    db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    $rep = [];
    foreach (itPortalEzdOgolnyArticles($app) as $slug => [$title, $keywords, $blocks, $pinned, $group, $sort]) {
        $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
        itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId, 'keywords' => $keywords,
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => $pinned ? 1 : 0,
            'sort_order' => $sort, 'menu_group' => $group, 'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
        $rep[] = "ezd/{$slug} — {$title}";
    }
    // istniejące artykuły innych seedów: do grupy „Narzędzia”
    foreach (['drukowanie-etykiet' => 40, 'kopie-dokumentow-w-chmurze' => 41] as $slug => $sort) {
        db_exec("UPDATE itp_articles SET menu_group = 'Narzędzia', sort_order = ? WHERE category_id = ? AND slug = ?", [$sort, $catId, $slug]);
    }
    return $rep;
}

/** slug => [tytuł, słowa kluczowe, bloki, przypięty, grupa, kolejność] */
function itPortalEzdOgolnyArticles(string $app): array {
    $e = $app . '/ezd/';
    $b = $app . '/uslugi-it/uslugi/ezd/';
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        'pierwsze-kroki' => ['EZD Wirtualne biurko: pierwsze kroki', 'ezd, wirtualne biurko, elektroniczne zarządzanie dokumentacją, kancelaria, koszulka, segregator, jrwa, wykaz akt, pulpit ezd, menu ezd, moje sprawy, moje pisma', [
            ['type' => 'text', 'html' => '<p>EZD Wirtualne biurko to część SZO do prowadzenia dokumentacji Fundacji. Otworzysz je z menu SZO, pozycją „Pulpit EZD”. '
                . 'Wszystko, co wpływa do Fundacji i z niej wychodzi, rejestrujemy tu, a każdy dokument trafia do właściwej sprawy.</p>'],
            ['type' => 'text', 'html' => '<h2>Jak ułożone są dokumenty</h2>'
                . '<p>Segregator odpowiada jednej pozycji z wykazu akt (JRWA), na przykład dla umów albo zaświadczeń, i ma jej symbol.</p>'
                . '<p>Koszulka to jedna sprawa w segregatorze. Ma swój znak, złożony z symbolu segregatora, numeru i roku. W koszulce są pisma przychodzące, wychodzące i wewnętrzne, umowy, '
                . 'dokumenty wewnętrzne, notatki i pliki. Dla spraw, które ciągną się cały rok, na przykład rejestru zaświadczeń, jest koszulka ciągła.</p>'
                . '<p>Pismo to pojedynczy dokument w koszulce, na przykład list, który przyszedł, albo odpowiedź, którą wysyłamy.</p>'],
            ['type' => 'text', 'html' => '<h2>Pozycje w menu EZD</h2><ul>'
                . '<li><a href="' . $e . '">Pulpit EZD</a>: Twoje koszulki i pisma, terminy i rzeczy, które czekają na Ciebie.</li>'
                . '<li>Dziennik podawczy: rejestracja korespondencji przychodzącej i wychodzącej.</li>'
                . '<li>Koszulki: wszystkie sprawy, do których masz dostęp, z wyszukiwarką.</li>'
                . '<li>Segregatory aktowe: pozycje z wykazu akt i ich koszulki.</li>'
                . '<li>Wykaz akt (JRWA): lista symboli i kategorii archiwalnych.</li>'
                . '<li>Terminarz: terminy koszulek i dekretacji w jednym widoku.</li>'
                . '<li>Do podpisu: dokumenty, które czekają na Twój podpis.</li>'
                . '<li>Poczta EZD: skrzynki pocztowe podłączone do EZD.</li>'
                . '<li>Pełnomocnictwa i Zaświadczenia: rejestry prowadzone w EZD.</li>'
                . '<li>Rejestr dokumentów: numery i kody nadane dokumentom, dla administratorów i Zarządu.</li></ul>'
                . '<p>Część pozycji widzą tylko osoby z odpowiednimi uprawnieniami, więc Twoje menu może być krótsze.</p>'],
            ['type' => 'text', 'html' => '<h2>Pulpit EZD</h2>'
                . '<p>Na pulpicie są zakładki „Moje sprawy” i „Moje pisma” z koszulkami i pismami, którymi się zajmujesz, ich etapem i terminem. '
                . 'Zacznij od nich dzień: zobaczysz, co jest pilne i co czeka na Twoją decyzję. O zbliżających się terminach EZD przypomina też rano e-mailem.</p>'],
            ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Dalej: <a href="' . $b . 'korespondencja">Korespondencja: dziennik podawczy i książka nadawcza</a> '
                . 'oraz <a href="' . $b . 'koszulki-i-dekretacje">Koszulki, pisma i dekretacje</a>. Brak dostępu do EZD albo do segregatora? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
        ], true, 'Na start', 10],

        'korespondencja' => ['Korespondencja w EZD: dziennik podawczy i książka nadawcza', 'korespondencja, dziennik podawczy, rpw, rpw-w, książka nadawcza, przesyłka, list, skan, przychodząca, wychodząca, w koszulce, przekazanie, referent, poczta ezd, e-doręczenia', [
            ['type' => 'text', 'html' => '<p>Każda przesyłka, która przychodzi do Fundacji albo z niej wychodzi, dostaje numer w EZD. Dzięki temu wiadomo, kiedy przyszła, kto się nią zajmuje i w jakiej sprawie została załatwiona.</p>'],
            ['type' => 'wizard', 'title' => 'Przesyłka przychodząca', 'intro' => '', 'steps' => [
                $step('Zarejestruj', '<p>W „Dzienniku podawczym” dodaj przesyłkę: nadawcę, datę wpływu i krótki opis. Przesyłka dostanie numer w formacie numer i rok.</p>'),
                $step('Dodaj skan', '<p>Zeskanuj przesyłkę i dołącz skan. Oryginał papierowy odłóż w ustalonym miejscu.</p>'),
                $step('Przekaż albo przydziel', '<p>Przekaż przesyłkę osobie, która się nią zajmie, albo od razu przydziel ją do istniejącej albo nowej koszulki. '
                    . 'Wtedy w koszulce powstaje pismo przychodzące. Dopóki przesyłka nie jest przydzielona do sprawy, ma w dzienniku status „w koszulce (RPW)”.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Przesyłka wychodząca</h2>'
                . '<p>Listy, które wysyłamy, wpisujemy do książki nadawczej (RPW-W). Każda przesyłka ma numer RPW-W, odbiorcę, adres i datę nadania. '
                . 'Z książki nadawczej wydrukujesz etykiety adresowe na koperty, jak opisuje artykuł <a href="' . $b . 'drukowanie-etykiet">Drukowanie etykiet w EZD</a>.</p>'],
            ['type' => 'text', 'html' => '<h2>Poczta EZD</h2>'
                . '<p>„Poczta EZD” pokazuje wiadomości e-mail ze skrzynek podłączonych do EZD. Wiadomość możesz zarejestrować jako pismo w koszulce albo utworzyć z niej zadanie w module Zadania SZO.</p>'],
            ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Nie zostawiaj przesyłek bez przydziału. Gdy nie wiesz, do której koszulki należy pismo, zapytaj przełożonego albo osobę, która prowadzi dziennik podawczy.</p>'],
        ], false, 'Codzienna praca', 20],

        'koszulki-i-dekretacje' => ['Koszulki, pisma i dekretacje', 'koszulka, nowa koszulka, sprawa, znak koszulki, pismo, umowa, dokument wewnętrzny, notatka, grupa plików, koszulka ciągła, etap, dekretacja, dyspozycja, do załatwienia, do akceptacji, do podpisu, do realizacji, termin, terminarz, zamknięcie koszulki, archiwum', [
            ['type' => 'wizard', 'title' => 'Nowa koszulka', 'intro' => '', 'steps' => [
                $step('Wybierz segregator', '<p>Otwórz „Segregatory aktowe” i wybierz segregator z właściwym symbolem z wykazu akt. Jeśli nie wiesz, który wybrać, sprawdź „Wykaz akt (JRWA)”.</p>'),
                $step('Załóż koszulkę', '<p>Kliknij „Nowa koszulka”, wpisz tytuł sprawy i w razie potrzeby termin. Znak koszulki nada się sam.</p>'),
                $step('Dodaj dokumenty', '<p>W koszulce dodaj pisma, umowy albo dokumenty wewnętrzne i dołącz pliki. Pliki możesz układać w grupy, na przykład „Korespondencja” i „Załączniki do umowy”.</p>'),
            ]],
            ['type' => 'text', 'html' => '<h2>Karta koszulki</h2>'
                . '<p>Na karcie koszulki widzisz wszystkie jej dokumenty, pliki i historię. Notatki to krótkie wpisy dla osób, które pracują przy sprawie. Ważną notatkę możesz przypiąć na górze. '
                . 'Koszulka przechodzi kolejne etapy, od wszczęcia przez dekretację, realizację i akceptację do zakończenia, więc od razu widać, na jakim jest etapie.</p>'],
            ['type' => 'text', 'html' => '<h2>Dekretacje</h2>'
                . '<p>Dekretacja to polecenie dla konkretnej osoby, co ma zrobić z pismem albo koszulką. Dyspozycja może brzmieć „do załatwienia”, „do akceptacji”, „do podpisu” albo „do realizacji” i ma termin. '
                . 'Osoba, której zadekretowano pismo, widzi je na swoim pulpicie, a dzień przed terminem i w dniu terminu dostaje przypomnienie e-mailem.</p>'
                . '<p>Dokumenty do podpisu zbiera pozycja „Do podpisu” w menu, a wszystkie terminy pokazuje „Terminarz”.</p>'],
            ['type' => 'text', 'html' => '<h2>Zamknięcie i archiwum</h2>'
                . '<p>Gdy sprawa jest załatwiona, zamknij koszulkę. Zamknięta koszulka zostaje w segregatorze i w wyszukiwarce, ale nie można już do niej dodawać dokumentów bez ponownego otwarcia. '
                . 'Po zakończeniu roku koszulki trafiają do archiwum zgodnie z kategorią archiwalną segregatora.</p>'],
            ['type' => 'callout', 'kind' => 'tip', 'html' => '<p>Zadania, które nie są dokumentami, na przykład przygotowanie spotkania, prowadź w module Zadania SZO. '
                . 'Zadanie możesz powiązać z koszulką, wklejając adres jej karty, jak opisuje usługa <a href="' . $app . '/uslugi-it/uslugi/zadania-szo/praca-z-zadaniem">Zadania w SZO</a>.</p>'],
        ], false, 'Codzienna praca', 30],
    ];
}
