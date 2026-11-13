<?php
/**
 * modules/it_portal/lib/itPortalGlossarySeed.php — artykuł „Terminologia” w usłudze „Migracja” Portalu Usług IT
 * (/uslugi-it/uslugi/migracja/terminologia). Polecenie 2026-11-13: „Nowy artykuł - terminologia”; doprecyzowanie:
 * „SZO - Panel Współpracownika lub System Zarządzania Organizacją, ale to to samo”.
 *
 * Słowniczek pojęć z artykułów o migracji, poczcie i logowaniu, wyjaśnionych prostym językiem. Nazwy i adresy zgodne
 * z innymi artykułami portalu (itPortalMigracjaSeed, itPortalDpocztaSeed, itPortalFirstLoginSeed).
 * Nadpisuje treść, tytuł i słowa kluczowe; zostawia id, przypięcie, publikację, powiązane i kolejność. Nowy artykuł trafia
 * na koniec usługi. Bezpieczne do ponownego uruchomienia. Używa migracja '2026-11_it_portal_glossary' i cli/it_portal_glossary_seed.php.
 */

/** @return array{status:string, article:string} */
function itPortalGlossaryApply(string $app, bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'migracja'");
    if (!$cat) return ['status' => 'brak usługi „Migracja” — pominięto', 'article' => ''];
    $catId = (int)$cat['id'];
    $old = db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = 'terminologia'", [$catId]);
    $title = 'Terminologia: słowniczek pojęć';
    $rep = ['status' => $old ? 'nadpisany' : 'utworzony', 'article' => "migracja/terminologia — {$title}"];
    if ($dryRun) return $rep;
    $sort = $old ? (int)$old['sort_order'] : (int)(db_one("SELECT MAX(sort_order) AS m FROM itp_articles WHERE category_id = ?", [$catId])['m'] ?? 0) + 10;
    itPortalArticleSave([
        'title' => $title, 'slug' => 'terminologia', 'category_id' => $catId,
        'keywords' => 'terminologia, słowniczek, słownik, pojęcia, skróty, co to jest, SZO, Panel Współpracownika, System Zarządzania Organizacją, IAM, SSO, logowanie jednokrotne, '
                    . 'CPL, System Tożsamości, chmura, Nextcloud, OneDrive, dpoczta, webmail, IMAP, SMTP, POP3, 2FA, Helpdesk, Microsoft 365, migracja, vendor lock-in, phishing',
        'blocks' => json_encode(itPortalGlossaryBlocks($app), JSON_UNESCAPED_UNICODE),
        'related' => $old ? (json_decode((string)$old['related'], true) ?: []) : [],
        'is_published' => $old ? (int)$old['is_published'] : 1, 'is_pinned' => $old ? (int)$old['is_pinned'] : 0,
        'sort_order' => $sort,
    ], $old ? (int)$old['id'] : 0);
    return $rep;
}

/** Bloki artykułu: grupy pojęć jako listy „pojęcie — wyjaśnienie”. */
function itPortalGlossaryBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $group = static function (string $title, array $terms): array {
        $li = '';
        foreach ($terms as [$term, $def]) $li .= '<li><strong>' . $term . '</strong>: ' . $def . '</li>';
        return ['type' => 'text', 'html' => '<h2>' . $title . '</h2><ul>' . $li . '</ul>'];
    };

    return [
        ['type' => 'text', 'html' => '<p>W instrukcjach o migracji, poczcie i logowaniu pojawiają się nazwy systemów i skróty. Tu wyjaśniamy je krótko i prostym językiem.</p>'],

        $group('Systemy Fundacji', [
            ['SZO', 'Panel Współpracownika, inaczej System Zarządzania Organizacją. To jedna i ta sama aplikacja pod adresem <a href="' . $app . '">szo.feer.org.pl</a>. '
                . 'Są w niej m.in. umowy, zadania, Helpdesk i ten Portal Usług IT.'],
            ['Panel Współpracownika', 'druga nazwa SZO. Obie nazwy oznaczają to samo.'],
            ['Portal Usług IT', 'strona z instrukcjami, na której teraz jesteś (<a href="' . $app . '/uslugi-it">/uslugi-it</a>).'],
            ['Helpdesk IT', 'miejsce w SZO, w którym zgłaszasz problem albo prośbę do działu IT. Każde zgłoszenie ma numer i historię odpowiedzi. <a href="' . $hd . '">Nowe zgłoszenie</a>.'],
            ['CPL', 'Centralny Portal Logowania, czyli strona logowania SZO. Pozwala też wybrać, do którego profilu chcesz wejść.'],
            ['System Tożsamości', 'część SZO z ustawieniami Twojego konta (<a href="' . $app . '/tozsamosc/index.php">/tozsamosc</a>): hasła, logowanie jednokrotne, urządzenia i bezpieczeństwo konta.'],
        ]),

        $group('Logowanie i hasła', [
            ['IAM', 'system tożsamości Fundacji (z angielskiego <span lang="en">Identity and Access Management</span>). Jedno konto IAM i jedno hasło otwiera SZO, chmurę plików i kolejne aplikacje Fundacji. '
                . 'Strona logowania IAM ma adres <code>logto.feer.org.pl</code>.'],
            ['Logowanie jednokrotne (SSO)', 'logujesz się raz, kontem IAM, i wchodzisz do kilku aplikacji bez ponownego wpisywania hasła. W SZO to przycisk „Zaloguj przez logowanie jednokrotne”.'],
            ['Hasło IAM', 'hasło do konta IAM i do chmury plików. Od 1 grudnia 2026 r. posłuży do wszystkich aplikacji Fundacji oprócz poczty.'],
            ['Hasło do poczty', 'osobne hasło do skrzynki pocztowej. Musi być inne niż hasło IAM.'],
            ['Hasło startowe', 'hasło, które dostajesz od nas na początek. Przy pierwszym logowaniu panel poprosi Cię o własne.'],
            ['Nowe hasło', 'strona <a href="' . $app . '/nowehaslo">/nowehaslo</a>, na której sam zmienisz hasło IAM albo hasło do poczty. Zmianę potwierdzasz kodem.'],
            ['Kod SMS, kod jednorazowy', 'kilka cyfr, które wysyłamy, żeby potwierdzić, że to Ty. Kod działa krótko i tylko raz.'],
            ['2FA, weryfikacja dwuetapowa', 'drugi krok logowania. Oprócz hasła podajesz kod z aplikacji w telefonie. Chroni konto, nawet gdy ktoś pozna hasło.'],
        ]),

        $group('Poczta', [
            ['Skrzynka', 'Twoja poczta służbowa pod adresem <code>imie.nazwisko@feer.org.pl</code>. Wolontariusz i pracownik mają skrzynkę o pojemności 5 GB.'],
            ['dpoczta.pl, webmail', 'poczta w przeglądarce, bez instalowania programu. Otwierasz ją na <a href="https://dpoczta.pl">dpoczta.pl</a>.'],
            ['dhosting', 'polski dostawca, u którego od 14 listopada 2026 r. działa poczta Fundacji.'],
            ['Program pocztowy', 'aplikacja do poczty w komputerze albo telefonie, np. Outlook, Thunderbird, Apple Mail, Gmail.'],
            ['IMAP', 'sposób połączenia programu pocztowego ze skrzynką. Wiadomości zostają na serwerze, więc widzisz je tak samo w telefonie, komputerze i przeglądarce. Polecamy go.'],
            ['POP3', 'starszy sposób połączenia. Pobiera wiadomości na jedno urządzenie i zwykle usuwa je z serwera.'],
            ['SMTP', 'sposób wysyłania poczty z programu pocztowego. W ustawieniach programu to „serwer poczty wychodzącej”.'],
            ['Autoresponder, autoodpowiedź', 'automatyczna odpowiedź, np. na czas urlopu.'],
            ['Cleanbox', 'filtr spamu w dpoczta.pl. Ustawisz w nim zaufanych i zablokowanych nadawców.'],
            ['Spam, phishing', 'spam to niechciana poczta. Phishing to wiadomość, która podszywa się pod kogoś znanego, żeby wyłudzić hasło, kod albo pieniądze.'],
        ]),

        $group('Pliki i migracja', [
            ['Chmura plików', 'miejsce na pliki Fundacji pod adresem <code>nc.feer.org.pl</code>, działające na programie Nextcloud. Zastępuje OneDrive: trzymasz w niej pliki, udostępniasz je i synchronizujesz z komputerem i telefonem.'],
            ['Nextcloud', 'program, na którym działa chmura plików Fundacji. Ma też aplikacje na komputer i telefon.'],
            ['Microsoft 365, Office 365', 'pakiet usług Microsoftu: poczta Outlook, OneDrive, Teams, SharePoint. Fundacja wyłącza go 20 listopada 2026 r. Do logowania w SZO zostaje tylko administratorom.'],
            ['OneDrive', 'dysk w chmurze Microsoftu. Pliki, których potrzebujesz, przenieś do chmury plików Fundacji przed 20 listopada 2026 r.'],
            ['Migracja', 'przeniesienie poczty, plików i logowania z Microsoft 365 do nowych narzędzi. Opis: <a href="' . $app . '/uslugi-it/uslugi/migracja/migracja">Migracja z Microsoft 365</a>.'],
            ['Migracja poczty', 'strona <a href="' . $app . '/migracjapoczty">/migracjapoczty</a>, na której przeniesiesz pocztę do nowej skrzynki i pobierzesz PDF z danymi logowania.'],
            ['<span lang="en">Vendor lock-in</span>', 'uzależnienie od jednego dostawcy. Gdy zmienia ceny albo zasady, trudno od niego odejść. To jeden z powodów migracji.'],
        ]),

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Nie ma tu pojęcia, którego szukasz? <a href="' . $hd . '">Napisz do Helpdesku</a>, dopiszemy je.</p>'],
    ];
}
