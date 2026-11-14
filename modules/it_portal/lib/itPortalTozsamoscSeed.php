<?php
/**
 * modules/it_portal/lib/itPortalTozsamoscSeed.php — artykuł „System Tożsamości: Twoje konto o koncie” w usłudze „Mój login”
 * (polecenie 2026-11-14: „opisz /tozsamosc”). Źródła: tozsamosc/index.php ($tabs: Przegląd, Dane konta, Zabezpieczenia,
 * Urządzenia i logowania, Usługi, Logowanie jednokrotne, Kod IKA (gdy jest), Moje dane (RODO), Pomoc i informacje, Administracja
 * dla admina; $sec_items: Hasło, Telefon do SMS, Weryfikacja dwuskładnikowa, Klucz bezpieczeństwa), tozsamosc/login.php (osobne
 * logowanie), tozsamosc/mfa.php, modules/account_security (urządzenia, „To nie ja”, powiadomienia), modules/sso_logto/view/userSection.php.
 * Ramka ITP_STAN_NA. Używa migracja '2026-11_it_portal_tozsamosc'.
 */

require_once __DIR__ . '/itPortalLogowanieSzoSeed.php';

function itPortalTozsamoscApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moj-login'");
    if (!$cat) return 'brak usługi „Mój login” — pominięto';
    $catId = (int)$cat['id'];
    $slug = 'system-tozsamosci';
    $title = 'System Tożsamości: Twoje konto o koncie';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $related = $old ? itPortalIds($old['related'] ?? '[]') : [];
    foreach (db_all("SELECT id FROM itp_articles WHERE category_id = ? AND slug IN ('iam','ika','logowanie-do-szo')", [$catId]) as $r) {
        if (!in_array((int)$r['id'], $related, true)) $related[] = (int)$r['id'];
    }
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'system tożsamości, tożsamość, tozsamosc, moje konto, dane konta, uid, identyfikator sieciowy, zabezpieczenia, hasło, telefon do sms, weryfikacja dwuskładnikowa, mfa, 2fa, klucz bezpieczeństwa, passkey, urządzenia i logowania, to nie ja, wyloguj wszędzie, usługi, logowanie jednokrotne, kod ika, rodo, moje dane, rejestr czynności',
        'blocks' => json_encode(itPortalTozsamoscBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 1, 'sort_order' => 3,
        'related' => $related], $old ? (int)$old['id'] : 0);
    return "moj-login/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalTozsamoscBlocks(string $app): array {
    $tz = $app . '/tozsamosc/';
    $s = static fn(string $hash): string => $app . '/tozsamosc/index.php#' . $hash;
    $b = $app . '/uslugi-it/uslugi/moj-login/';
    $hd = $app . '/helpdesk/new.php';
    return [
        itPortalStanNaBlock(),
        ['type' => 'text', 'html' => '<p>System Tożsamości to Twoje konto o koncie. W jednym miejscu sprawdzasz swoje dane w organizacji, ustawiasz hasła i zabezpieczenia, widzisz, gdzie jesteś zalogowany, i z czego korzystasz. '
            . 'Nie służy do codziennej pracy. Zaglądasz tu, gdy chcesz coś zmienić albo sprawdzić.</p>'
            . '<p>Otworzysz go pod adresem <a href="' . $tz . '">System Tożsamości</a> albo z menu SZO, pozycja „System Tożsamości”. Logujesz się tym samym kontem co do SZO. '
            . 'Strona ma własny wygląd, podobny do ustawień konta Microsoft. Z lewej jest menu sekcji, a u góry odnośnik „Wróć do SZO”.</p>'],

        ['type' => 'text', 'html' => '<h2>Sekcje w menu</h2><ul>'
            . '<li><a href="' . $s('przeglad') . '">Przegląd</a>: kafle z najważniejszymi informacjami i skrótami, na przykład stan zabezpieczeń, zmiana hasła i Twoje usługi.</li>'
            . '<li><a href="' . $s('podstawowe') . '">Dane konta</a>: imię i nazwisko, numer UID, identyfikator sieciowy, czyli login do panelu, typ konta i uprawnienia wynikające z roli. Numer UID dostajesz przy zawarciu umowy i zostaje z Tobą na stałe.</li>'
            . '<li><a href="' . $s('bezpieczenstwo') . '">Zabezpieczenia</a>: hasło, telefon do SMS-ów, weryfikacja dwuskładnikowa i klucz bezpieczeństwa. Opis jest niżej.</li>'
            . '<li><a href="' . $s('urzadzenia') . '">Urządzenia i logowania</a>: gdzie i kiedy się logowałeś, znane urządzenia i ustawienia powiadomień o nowych logowaniach.</li>'
            . '<li><a href="' . $s('uslugi') . '">Usługi</a>: systemy, do których logujesz się tym kontem, z informacją, co jest włączone, co czeka na konfigurację, a co jest niedostępne.</li>'
            . '<li><a href="' . $s('sso') . '">Logowanie jednokrotne</a>: Twoje konto IAM i chmury plików. Tu je zakładasz, sprawdzasz login i zmieniasz hasło.</li>'
            . '<li><a href="' . $s('ika') . '">Kod IKA</a>: zmiana kodu potwierdzającego dostęp do chronionych danych. Tę sekcję widzą tylko osoby, które mają kod IKA.</li>'
            . '<li><a href="' . $s('rejestr') . '">Moje dane (RODO)</a>: co dzieje się z Twoimi danymi osobowymi, kto ma upoważnienie do ich przetwarzania i jakie czynności wykonano na Twojej umowie.</li>'
            . '<li><a href="' . $s('opanelu') . '">Pomoc i informacje</a>: krótki opis, do czego służy System Tożsamości i gdzie szukać pomocy.</li></ul>'
            . '<p>Administratorzy widzą jeszcze sekcję Administracja z narzędziami do zarządzania kontami.</p>'],

        ['type' => 'text', 'html' => '<h2>Zabezpieczenia</h2>'
            . '<p>Każde ustawienie rozwijasz osobno. Przy każdym widać, czy jest gotowe, czy czegoś brakuje.</p><ul>'
            . '<li>Hasło: hasło do SZO przy logowaniu e-mailem i hasłem. Hasło IAM i chmury plików zmieniasz w sekcji Logowanie jednokrotne, a hasło do poczty na stronie Nowe hasło.</li>'
            . '<li>Telefon do SMS: numer, na który wysyłamy kody i hasła startowe. Potwierdzasz go kodem z SMS-a.</li>'
            . '<li>Weryfikacja dwuskładnikowa: drugi składnik przy logowaniu, czyli aplikacja z kodami albo SMS, plus kody zapasowe na wypadek utraty telefonu. Zachęcamy, żeby ją włączyć.</li>'
            . '<li>Klucz bezpieczeństwa: klucz sprzętowy albo passkey w telefonie lub komputerze. To najmocniejsze zabezpieczenie logowania.</li></ul>'],

        ['type' => 'text', 'html' => '<h2>Urządzenia i logowania</h2>'
            . '<p>Sekcja pokazuje ostatnie logowania i zapamiętane urządzenia. Jeśli nie rozpoznajesz jakiegoś logowania, kliknij „To nie ja”. Wylogujemy wtedy wszystkie inne urządzenia, a zgłoszenie potwierdzimy e-mailem. Potem zmień hasło. '
            . 'Przyciskiem „Wyloguj wszystkie inne” zamkniesz sesje na pozostałych urządzeniach także bez zgłoszenia. W tej samej sekcji ustawisz, kiedy wysyłamy Ci powiadomienie o nowym logowaniu.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>W danych konta może być jeszcze pole „Login Microsoft 365”. Po wyłączeniu Microsoft 365 nie będzie potrzebne. '
            . 'Do aplikacji Fundacji logujesz się kontem IAM, opisanym w artykule <a href="' . $b . 'iam">IAM: czym jest i jakie są moje dane logowania</a>.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Widzisz w Systemie Tożsamości błędne dane, na przykład złe imię albo rolę? Nie zmieniaj ich na własną rękę, tylko <a href="' . $hd . '">napisz do Helpdesku IT</a>. '
            . 'Dane osobowe, takie jak telefon, adres i rachunek, zmieniasz w Panelu współpracownika.</p>'],
    ];
}
