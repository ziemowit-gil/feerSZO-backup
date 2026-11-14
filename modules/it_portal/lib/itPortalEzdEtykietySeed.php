<?php
/**
 * modules/it_portal/lib/itPortalEzdEtykietySeed.php — artykuł „Drukowanie etykiet w EZD” w usłudze „EZD” Portalu Usług IT.
 * Polecenie 2026-11-14: „EZD opisz drukowanie etykietek”. Źródła: ezd/rpwy/index.php + ezd/rpwy/etykiety.php (etykiety adresowe A4
 * z książki nadawczej RPW-W: kolumny 1–4, wiersze 1–14, margines, odstęp, czcionka, pierwsze wolne pole, Nadawca, Nr RPW-W, Ramki)
 * i ezd/rejestrDokumentow.php (etykiety z kodem dla dokumentów z odpowiednikiem papierowym, arkusz 3×8; tylko administrator i Zarząd).
 * Używa migracja '2026-11_it_portal_ezd_etykiety'.
 */

function itPortalEzdEtykietyApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'ezd'");
    if (!$cat) return 'brak usługi „EZD” — pominięto';
    $slug = 'drukowanie-etykiet';
    $old = db_one("SELECT id, related, is_pinned, sort_order FROM itp_articles WHERE category_id = ? AND slug = ?", [(int)$cat['id'], $slug]);
    $title = 'Drukowanie etykiet w EZD: adresowe i z kodem dokumentu';
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => (int)$cat['id'],
        'keywords' => 'etykiety, etykieta, drukowanie etykiet, etykiety adresowe, książka nadawcza, rpw-w, przesyłki, arkusz a4, naklejki, kod kreskowy, qr, code128, rejestr dokumentów, odpowiednik papierowy, pierwsze wolne pole, margines, skala 100%',
        'blocks' => json_encode(itPortalEzdEtykietyBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1,
        'is_pinned' => $old ? (int)$old['is_pinned'] : 0, 'sort_order' => $old ? (int)$old['sort_order'] : 500,
        'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
    return "ezd/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalEzdEtykietyBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $step = static fn(string $t, string $h): array => ['title' => $t, 'html' => $h, 'images' => []];
    return [
        ['type' => 'text', 'html' => '<p>W EZD wydrukujesz dwa rodzaje etykiet na arkuszach samoprzylepnych A4: etykiety adresowe na koperty, z książki nadawczej, '
            . 'i etykiety z kodem dokumentu do naklejenia na papierowy dokument, z Rejestru dokumentów.</p>'],

        ['type' => 'wizard', 'title' => 'Etykiety adresowe na koperty', 'intro' => '<p>Etykiety powstają z przesyłek wpisanych do książki nadawczej (RPW-W).</p>', 'steps' => [
            $step('Otwórz książkę nadawczą', '<p>W EZD otwórz <a href="' . $app . '/ezd/rpwy/index.php">książkę nadawczą</a> i w razie potrzeby zawęź listę wyszukiwarką albo filtrami.</p>'),
            $step('Zaznacz przesyłki', '<p>Zaznacz przesyłki w pierwszej kolumnie tabeli. Pole w nagłówku zaznacza wszystkie. Nad tabelą zobaczysz, ile przesyłek zaznaczyłeś.</p>'),
            $step('Kliknij Drukuj etykiety adresowe', '<p>Kliknij „Drukuj etykiety adresowe (A4)”. W nowej karcie otworzy się podgląd arkusza, dokładnie taki, jak wyjdzie z drukarki.</p>'),
            $step('Dopasuj arkusz', '<p>Nad podglądem ustaw układ zgodny z Twoimi naklejkami: „Kolumny” (od 1 do 4), „Wiersze” (od 1 do 14), „Margines” i „Odstęp” między etykietami w milimetrach oraz wielkość „Czcionki”. '
                . 'Domyślnie są 2 kolumny i 7 wierszy, margines 15 mm i odstęp 2 mm. Zaznacz, czy na etykiecie ma być „Nadawca” i „Nr RPW-W”. Kliknij „Odśwież podgląd”.</p>'),
            $step('Zacznij od wolnego pola', '<p>Jeśli arkusz jest częściowo zużyty, w polu „Pierwsze wolne pole” wpisz numer pierwszej wolnej naklejki, licząc od lewej w górnym wierszu. Wcześniejsze pola zostaną puste.</p>'),
            $step('Wydrukuj próbę i właściwy arkusz', '<p>Najpierw zaznacz „Ramki (do próby)” i wydrukuj stronę na zwykłym papierze. Przyłóż ją do arkusza naklejek pod światło. '
                . 'Gdy wszystko pasuje, odznacz ramki, włóż arkusz naklejek i kliknij „Drukuj”.</p>'),
        ]],

        ['type' => 'callout', 'kind' => 'important', 'html' => '<p>W oknie drukowania wybierz skalę 100%, czyli „Rzeczywisty rozmiar”, i wyłącz dopasowanie do strony. '
            . 'Inaczej przeglądarka zmniejszy wydruk i etykiety nie trafią w naklejki. Pod podglądem widać, ile jest etykiet, ile arkuszy i jaki jest rozmiar jednej etykiety w milimetrach.</p>'],

        ['type' => 'wizard', 'title' => 'Etykiety z kodem dokumentu', 'intro' => '<p>Ta funkcja jest dla administratorów i Zarządu. Służy do oznaczania papierowych odpowiedników dokumentów kodem kreskowym i QR z Rejestru dokumentów.</p>', 'steps' => [
            $step('Otwórz Rejestr dokumentów', '<p>Otwórz <a href="' . $app . '/ezd/rejestrDokumentow.php">Rejestr dokumentów</a>. Filtr „Papier” pokazuje dokumenty według tego, czy mają papierowy odpowiednik: '
                . '„Do rozstrzygnięcia”, „Do zrobienia (etykieta / numer)”, „Etykieta”, „Numer ręcznie” i „Bez papieru”. Gdy jakieś dokumenty czekają na decyzję, nad listą pojawia się przycisk „Pokaż do rozstrzygnięcia”.</p>'),
            $step('Zaznacz dokumenty', '<p>Zaznacz pozycje w pierwszej kolumnie, „Etykieta”. Pole do zaznaczenia jest tylko przy dokumentach z numeracją losową. Jedną etykietę w formacie PDF pobierzesz też przyciskiem „Etykieta” w wierszu dokumentu.</p>'),
            $step('Ustaw pierwsze wolne pole', '<p>Etykiety drukują się na arkuszu A4 z 24 naklejkami, 3 w rzędzie i 8 rzędów. W polu „pierwsze wolne pole” wpisz numer pierwszej wolnej naklejki, jeśli arkusz jest częściowo zużyty.</p>'),
            $step('Drukuj', '<p>Kliknij „Drukuj etykiety zaznaczonych”. Plik otworzy się w nowej karcie. Wydrukuj go w skali 100% i naklej etykiety na papierowe dokumenty.</p>'),
        ]],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Etykiety źle trafiają w naklejki mimo skali 100%? Zmień margines albo odstęp o 1 do 2 mm i wydrukuj próbę jeszcze raz. '
            . 'Gdy to nie pomaga, <a href="' . $hd . '">napisz do Helpdesku IT</a> i podaj, jakie arkusze naklejek masz, najlepiej z ich symbolem z opakowania.</p>'],
    ];
}
