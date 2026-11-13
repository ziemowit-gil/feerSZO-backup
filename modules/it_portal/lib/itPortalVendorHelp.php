<?php
/**
 * modules/it_portal/lib/itPortalVendorHelp.php — sekcja „Pomoc producenta” na dole instrukcji poczty w Portalu Usług IT (/uslugi-it).
 * Polecenie 2026-11-13: „Sprawdź https://dhosting.pl/pomoc/kategoria/poczta/ i na stronach /uslugi-it w stosownych miejscach
 * dodaj pod spodem sekcję »Pomoc producenta«”.
 *
 * Linki dobrane z kategorii „Poczta” pomocy dhosting.pl (stan 2026-11-13), tylko artykuły dla użytkownika skrzynki
 * (webmail dpoczta.pl, programy pocztowe, Cleanbox). Pominięte instrukcje panelu administratora hostingu (aliasy, SPF,
 * globalne filtry antyspamowe, migracje skrzynek), bo pracownicy i wolontariusze nie mają do niego dostępu.
 *
 * Działa na zapisanych artykułach usługi „Moja poczta”: usuwa poprzednią sekcję (blok tekstowy zaczynający się od
 * ITP_VENDOR_HELP_MARK) i dopisuje aktualną na końcu. Bezpieczne do ponownego uruchomienia. Seeder itPortalDpocztaApply()
 * nadpisuje artykuły bez tej sekcji, więc po ponownym zasiewie trzeba uruchomić to jeszcze raz (cli/it_portal_vendor_help.php).
 * Używa migracja '2026-11_it_portal_vendor_help'.
 */
const ITP_VENDOR_HELP_MARK = '<h2>Pomoc producenta</h2>';

/**
 * Linki do pomocy dhosting.pl: adres artykułu w „Mojej poczcie” => [[tytuł, adres], …].
 * @return array<string, list<array{0:string,1:string}>>
 */
function itPortalVendorHelpLinks(): array {
    $u = static fn(string $s): string => 'https://dhosting.pl/pomoc/baza-wiedzy/' . $s . '/';
    return [
        'logowanie-dpoczta' => [
            ['dPoczta.pl – najważniejsze informacje', $u('dpoczta-pl-najwazniejsze-informacje')],
            ['Możliwe problemy z odbiorem wiadomości e-mail', $u('mozliwe-problemy-z-odbiorem-wiadomosci-e-mail')],
        ],
        'zmiana-hasla-poczty' => [
            ['Zmiana hasła do skrzynki e-mail z poziomu dpoczta.pl', $u('zmiana-hasla-do-skrzynki-e-mail-z-poziomu-dpoczta-pl')],
        ],
        'poczta-w-telefonie' => [
            ['W jaki sposób skonfigurować program pocztowy?', $u('w-jaki-sposob-skonfigurowac-program-pocztowy')],
            ['Konfiguracja poczty na iOS (iPhone)', $u('konfiguracja-poczty-na-ios-11-iphone')],
            ['Konfiguracja poczty na Androidzie', $u('konfiguracja-poczty-na-androidzie-7-0')],
            ['Konfiguracja poczty w Microsoft Outlook (Android)', $u('konfiguracja-poczty-na-microsoft-outlook-android')],
            ['Jak skonfigurować pocztę w Outlook 365?', $u('jak-skonfigurowac-poczte-w-outlook-365')],
            ['Konfiguracja Microsoft Outlook 2016–2019', $u('konfiguracja-microsoft-outlook-2016-2019')],
            ['Konfiguracja programu Thunderbird', $u('konfiguracja-programu-thunderbird')],
            ['Konfiguracja poczty w systemie macOS', $u('konfiguracja-poczty-systemu-mac-os-x')],
            ['Dlaczego nie widzę wiadomości wysłanych z programu pocztowego w dpoczta.pl?', $u('dlaczego-nie-widze-wiadomosci-wyslanych-z-klienta-e-mail-w-dpoczta-pl')],
            ['Folder „Wysłane” w Microsoft Outlook', $u('definiowanie-folderu-na-wiadomosci-wyslane-w-programie-pocztowym-microsoft-outlook')],
            ['Folder „Wysłane” w Mozilla Thunderbird', $u('definiowanie-folderu-na-wiadomosci-wyslane-w-programie-pocztowym-mozilla-thunderbird')],
            ['Folder „Wysłane” w iPhone Mail', $u('definiowanie-folderu-na-wiadomosci-wyslane-w-programie-pocztowym-iphone-mail')],
            ['Co to jest IMAP?', $u('co-to-jest-imap')],
        ],
        'weryfikacja-dwuetapowa' => [
            ['Jak ustawić weryfikację dwuetapową w webmailu?', $u('jak-ustawie-weryfikacje-dwuetapowa-w-webmailu')],
        ],
        'podpis-w-poczcie' => [
            ['Podpis w dpoczta.pl przy pomocy projektanta podpisu', $u('jak-ustawie-podpis-sygnaturke-przy-pomocy-projektanta-podpisu')],
            ['Jak ustawić podpis z użyciem HTML w dpoczta.pl?', $u('jak-ustawic-podpis-z-uzyciem-html-w-dpoczta-pl')],
            ['Tożsamości w dpoczta.pl', $u('tozsamosci-w-dpoczta-pl-roundcube')],
        ],
        'autoresponder' => [
            ['Jak włączyć, wyłączyć i zarządzać autoresponderem?', $u('uruchomienie-autorespondera')],
        ],
        'filtry-i-przekierowanie' => [
            ['Jak korzystać z filtrowania wiadomości w dpoczta.pl?', $u('jak-korzystac-z-filtrowania-wiadomosci-w-dpoczta-pl')],
            ['Jak przekazywać wiadomości z usuwaniem za pomocą filtrów?', $u('przekazywanie-wiadomosci-z-usuwaniem-za-pomoca-filtrow-w-dpoczta-pl')],
            ['Definiowanie folderów skrzynki w dpoczta.pl', $u('definiowanie-folderow-skrzynki-w-dpoczta-pl')],
        ],
        'spam-i-zaufani-nadawcy' => [
            ['Jak korzystać z białych i czarnych list na skrzynkach e-mail?', $u('jak-korzystac-z-bialych-i-czarnych-list-na-skrzynkach-e-mail')],
            ['Jak ustawić punktację filtrowania spamu w dpoczta.pl?', $u('jak-ustawic-punktacje-filtrowania-wiadomosci-spamowych-w-dpoczta-pl')],
            ['Webmail dpoczta.pl – zaufani nadawcy', $u('webmail-dpoczta-pl-zaufani-nadawcy')],
            ['Rodzaje filtrów antyspamowych – CleanBox', $u('rodzaje-filtrow-antyspamowych-spamassassin')],
            ['Czy wiadomości e-mail są skanowane pod kątem wirusów?', $u('czy-wiadomosci-e-mail-sa-skanowane-pod-katem-wirusow')],
            ['Jak sprawdzić nadawcę w źródle wiadomości?', $u('jak-dokonac-podstawowej-analizy-zrodla-wiadomosci-e-mail-pod-katem-nadawcy')],
        ],
        'kontakty-import-eksport' => [
            ['Importowanie i eksportowanie kontaktów w dpoczta.pl', $u('importowanie-i-eksportowanie-kontaktow-w-dpoczta-pl')],
        ],
        'kalendarz' => [
            ['Jak utworzyć nowe spotkanie w kalendarzu?', $u('jak-stworzyc-nowe-spotkanie-w-kalendarzu')],
            ['Jak udostępnić swój kalendarz?', $u('jak-udostepnic-swoj-kalendarz')],
            ['Jak skonfigurować kalendarz w aplikacjach?', $u('jak-skonfigurowac-kalendarz-w-aplikacjach')],
            ['Jak dodać i skonfigurować kalendarz CalDAV?', $u('jak-dodac-i-skonfigurowac-kalendarz-caldav')],
            ['Jak dodać kolejny kalendarz w dpoczta.pl?', $u('jak-dodac-kolejny-kalendarz-w-dpoczta-pl')],
            ['Jak zaimportować lub wyeksportować kalendarz?', $u('jak-zaimportowac-wyeksportowac-kalendarz')],
        ],
        'szablony-odpowiedzi' => [
            ['Jak dodać i korzystać z szablonów odpowiedzi w dpoczta.pl?', $u('jak-dodac-i-korzystac-z-szablonow-odpowiedzi-w-dpoczta-pl')],
        ],
        'powiadomienia-o-poczcie' => [
            ['Powiadomienia o nowej wiadomości w webmailu', $u('jak-ustawic-powiadomienia-systemowe-desktopowe-o-nowej-wiadomosci-email-w-webmailu')],
        ],
        'duze-zalaczniki' => [
            ['Jaki jest limit wielkości załączników?', $u('jaki-jest-limit-wielkosci-zalacznikow-dla-wiadomosci-e-mail')],
        ],
    ];
}

/** Blok tekstowy „Pomoc producenta” z listą linków. */
function itPortalVendorHelpBlock(array $links): array {
    $li = '';
    foreach ($links as [$title, $url]) {
        $li .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($title, ENT_NOQUOTES) . '</a></li>';
    }
    return ['type' => 'text', 'html' => ITP_VENDOR_HELP_MARK
        . '<p>Więcej szczegółów, ze zrzutami ekranu, znajdziesz w pomocy dostawcy poczty (dhosting.pl). Strony otwierają się w serwisie dostawcy.</p>'
        . '<ul>' . $li . '</ul>'];
}

/**
 * Dopisuje (albo odświeża) sekcję „Pomoc producenta” w artykułach usługi „Moja poczta”.
 * @return array{updated:list<string>, missing:list<string>}
 */
function itPortalVendorHelpApply(bool $dryRun = false): array {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $rep = ['updated' => [], 'missing' => []];
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moja-poczta'");
    foreach (itPortalVendorHelpLinks() as $slug => $links) {
        $a = $cat ? db_one("SELECT * FROM itp_articles WHERE category_id = ? AND slug = ?", [(int)$cat['id'], $slug]) : null;
        $blocks = $a ? json_decode((string)$a['blocks'], true) : null;
        if (!is_array($blocks)) { $rep['missing'][] = "moja-poczta/{$slug}"; continue; }
        $blocks = array_values(array_filter($blocks, static fn($b): bool =>
            !(is_array($b) && ($b['type'] ?? '') === 'text' && str_starts_with(ltrim((string)($b['html'] ?? '')), ITP_VENDOR_HELP_MARK))));
        $blocks[] = itPortalVendorHelpBlock($links);
        $rep['updated'][] = "moja-poczta/{$slug} — {$a['title']} (" . count($links) . ' link.)';
        if ($dryRun) continue;
        itPortalArticleSave(['title' => $a['title'], 'slug' => $a['slug'], 'category_id' => $a['category_id'], 'keywords' => $a['keywords'],
            'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE), 'related' => json_decode((string)$a['related'], true) ?: [],
            'is_published' => $a['is_published'], 'is_pinned' => $a['is_pinned'], 'sort_order' => $a['sort_order']], (int)$a['id']);
    }
    return $rep;
}
