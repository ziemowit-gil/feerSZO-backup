<?php
/**
 * modules/it_portal/lib/itPortalCertFeerSeed.php — artykuł „Certyfikat FEER do podpisu i szyfrowania poczty”
 * w Portalu Usług IT (/uslugi-it/uslugi/moja-poczta/certyfikat-feer), grupa „Hasło i bezpieczeństwo”.
 * Polecenie 2026-11-26: „Dodaj certyfikat główny FEER do /uslugi-it” (wewnętrzny urząd FEER, wtyczka feer_cert
 * w poczta.feer.org.pl). Plik certyfikatu: /assets/ca/feer-root-ca.crt. Tekst wg zasad humanizera.
 * Używa migracja '2026-11_it_portal_certyfikat_feer'.
 */

const ITP_FEER_ROOT_SHA256 = '5B:79:87:97:0C:53:95:4D:29:D8:E2:54:97:D2:7E:E1:7F:86:D3:9B:B2:73:58:68:AB:4D:69:EF:38:06:A2:6E';

function itPortalCertFeerApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'moja-poczta'");
    if (!$cat) {
        return 'brak usługi moja-poczta — pominięto';
    }
    $catId = (int)$cat['id'];
    $slug  = 'certyfikat-feer';
    $old   = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $title = 'Certyfikat FEER do podpisu i szyfrowania poczty';
    $id = itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'certyfikat, certyfikat feer, s/mime, smime, podpis wiadomości, podpis cyfrowy, szyfrowanie poczty, certyfikat główny, root ca, urząd certyfikacji, p12, pfx, outlook, thunderbird, apple mail, zaufany certyfikat',
        'blocks' => json_encode(itPortalCertFeerBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0, 'sort_order' => 24,
        'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
    db_exec("UPDATE itp_articles SET menu_group = ?, sort_order = 24 WHERE id = ?", ['Hasło i bezpieczeństwo', $id]);
    return "moja-poczta/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalCertFeerBlocks(string $app): array {
    $hd   = $app . '/helpdesk/new.php';
    $crt  = $app . '/assets/ca/feer-root-ca.crt';
    $wyd  = 'https://poczta.feer.org.pl/?_task=settings&_action=plugin.feer_cert';
    $fp   = ITP_FEER_ROOT_SHA256;
    return [
        ['type' => 'text', 'html' => '<p>Fundacja ma własny urząd certyfikacji FEER. Wydaje on pracownikom ze skrzynką @feer.org.pl certyfikaty S/MIME do poczty. '
            . 'Certyfikatem podpisujesz wiadomości, więc odbiorca widzi, że naprawdę wysłałeś je Ty i że nikt ich po drodze nie zmienił. '
            . 'Możesz też odbierać wiadomości zaszyfrowane, których nie przeczyta nikt poza Tobą.</p>'],

        ['type' => 'text', 'html' => '<h2>Jak dostać certyfikat</h2><ol>'
            . '<li>Zaloguj się do <a href="https://poczta.feer.org.pl/">poczty FEER</a>.</li>'
            . '<li>Otwórz <a href="' . $wyd . '">Ustawienia, Certyfikat FEER</a>.</li>'
            . '<li>Kliknij <strong>Wydaj certyfikat</strong>.</li></ol>'
            . '<p>Certyfikat jest gotowy od razu i od razu działa w poczcie FEER. Przy pisaniu wiadomości włączasz podpis albo szyfrowanie przełącznikiem pod tematem. '
            . 'Jest ważny przez rok, potem wydajesz nowy tak samo.</p>'
            . '<p>Jeśli chcesz podpisywać też w Outlooku, Thunderbirdzie, Apple Mail albo na telefonie, przed kliknięciem wpisz hasło do pliku .p12. '
            . 'Przeglądarka pobierze wtedy plik z certyfikatem, który instalujesz w tym programie. Hasło wymyślasz sam, nigdzie go nie zapisujemy.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Plik .p12 i jego hasło pozwalają podpisywać wiadomości w Twoim imieniu. Nie wysyłaj ich nikomu, także współpracownikom. '
            . 'Po zainstalowaniu w programie usuń plik z pulpitu i z folderu Pobrane. Zgubiony telefon albo komputer z certyfikatem zgłoś od razu w <a href="' . $hd . '">Helpdesku IT</a>.</p>'],

        ['type' => 'text', 'html' => '<h2>Certyfikat główny FEER</h2>'
            . '<p>Poczta FEER ufa certyfikatom FEER od razu. Inne programy pocztowe i odbiorcy spoza Fundacji pokażą podpis jako niezaufany, '
            . 'dopóki nie zainstalują certyfikatu głównego FEER. Wyślij im ten link albo zainstaluj certyfikat u siebie, jeśli podpisujesz w Outlooku, Thunderbirdzie albo Apple Mail.</p>'
            . '<p><a href="' . $crt . '" download="FEER-Root-CA.crt">Pobierz certyfikat główny FEER (FEER-Root-CA.crt)</a></p>'
            . '<p>Przed instalacją porównaj odcisk SHA-256 pliku z tym poniżej. Musi się zgadzać co do znaku:</p>'
            . '<p><code>' . $fp . '</code></p>'],

        ['type' => 'text', 'html' => '<h2>Instalacja certyfikatu głównego</h2>'
            . '<h3>macOS (Apple Mail)</h3><ol>'
            . '<li>Kliknij dwa razy pobrany plik FEER-Root-CA.crt i dodaj go do pęku kluczy „login”.</li>'
            . '<li>W aplikacji Dostęp do pęku kluczy otwórz „FEER Root CA”, rozwiń Zaufanie i przy S/MIME wybierz Zawsze ufaj.</li></ol>'
            . '<h3>Windows (Outlook)</h3><ol>'
            . '<li>Kliknij dwa razy plik i wybierz Zainstaluj certyfikat.</li>'
            . '<li>Wybierz Bieżący użytkownik, potem Umieść wszystkie certyfikaty w następującym magazynie i wskaż Zaufane główne urzędy certyfikacji.</li></ol>'
            . '<h3>Thunderbird</h3><ol>'
            . '<li>Otwórz Ustawienia, Prywatność i bezpieczeństwo, Zarządzaj certyfikatami, kartę Organy certyfikacji.</li>'
            . '<li>Kliknij Importuj, wskaż plik i zaznacz zaufanie do identyfikacji użytkowników poczty.</li></ol>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Certyfikat FEER służy do poczty wewnątrz Fundacji i z partnerami, którzy zainstalują certyfikat główny. '
            . 'Do podpisywania umów i pism z mocą podpisu własnoręcznego potrzebny jest podpis kwalifikowany. '
            . 'Coś nie działa? <a href="' . $hd . '">Napisz do Helpdesku IT</a>.</p>'],
    ];
}
