<?php
/**
 * includes/webmail_clients.php — katalog klientów poczty (webmaili) organizacji.
 *
 * JEDNO ŹRÓDŁO PRAWDY dla wszystkich miejsc, które pokazują „w czym czytać
 * pocztę": publicznej strony wyboru (webmail/index.php pod poczta.feer.org.pl),
 * kafla w panelu wolontariusza (panel/includes/pv_home.php) i modułu Poczta
 * (poczta/dashboard.php, poczta/index.php przez poczta_webmail_options()).
 *
 * Klient pojawia się w interfejsach TYLKO gdy admin ustawi jego adres
 * (Admin → Poczta → Webmail). Dzięki temu strona nigdy nie reklamuje usługi,
 * której jeszcze nie wdrożono — a wyłączenie klienta to wyczyszczenie pola,
 * bez zmian w kodzie.
 *
 * Skrzynki żyją na Microsoft 365, więc kluczowe kryterium to sposób logowania:
 *   • OWA        — natywny klient Microsoftu,
 *   • Roundcube  — OAuth2/XOAUTH2 (wdrożone, docker/roundcube/),
 *   • SnappyMail — OAuth2 trzeba włączyć w jego konfiguracji (docker/snappymail/),
 *   • SquirrelMail — TYLKO Basic Auth (login+hasło do IMAP). Microsoft wyłączył
 *     Basic Auth dla IMAP/SMTP w Exchange Online na stałe, więc SquirrelMail
 *     NIE zaloguje się do skrzynki @feer.org.pl. Jest w katalogu, bo bywa
 *     potrzebny do skrzynek na innym serwerze IMAP — i dlatego domyślnie nie ma
 *     ustawionego adresu. Szczegóły: docker/squirrelmail/README.md.
 *
 * Wymaga: includes/functions.php (org_setting()).
 */

/**
 * JEDEN adres poczty dla użytkowników: strona wyboru klienta.
 *
 * Wszędzie, gdzie mówimy współpracownikowi „otwórz pocztę", ma stać TEN adres —
 * nie outlook.office.com, nie rc.feer.org.pl, nie portal.office.com. Dziwne,
 * cudze domeny w instrukcjach i mailach są nie do zapamiętania i wyglądają jak
 * phishing; poczta.feer.org.pl jest własna, jedna i sama kieruje dalej
 * (webmail/index.php). Adresy konkretnych klientów zostają TYLKO na tej stronie
 * i w ustawieniach admina.
 *
 * Zmiana adresu: Admin → Organizacja → `webmail_url` (np. gdy dochodzi
 * webmail.feer.org.pl albo domena się zmienia).
 */
function webmail_chooser_url(): string {
    return rtrim(org_setting('webmail_url') ?: 'https://poczta.feer.org.pl', '/');
}

/** Adres do pokazania (bez schematu) — „poczta.feer.org.pl". */
function webmail_chooser_label(): string {
    return (string)preg_replace('~^https?://~', '', webmail_chooser_url());
}

/**
 * Cechy do zestawienia — kolejność wierszy tabeli porównania.
 *
 * @return array<string, array{label:string, note:string}>
 */
function webmail_features(): array {
    return [
        'mailbox'   => ['label' => 'Ta sama skrzynka i te same wiadomości', 'note' => ''],
        'ms_login'  => ['label' => 'Logowanie kontem Microsoft (bez osobnego hasła)', 'note' => ''],
        'calendar'  => ['label' => 'Kalendarz i zapraszanie na spotkania', 'note' => ''],
        'teams'     => ['label' => 'Teams, czat, plan dnia', 'note' => ''],
        'gal'       => ['label' => 'Książka adresowa organizacji', 'note' => ''],
        'shared'    => ['label' => 'Skrzynki współdzielone i dostęp w zastępstwie', 'note' => 'np. fundacja@feer.org.pl'],
        'rules'     => ['label' => 'Reguły sortowania i autoodpowiedź', 'note' => 'ustawione w Outlooku działają na serwerze — obowiązują we wszystkich klientach'],
        'onedrive'  => ['label' => 'Załączanie plików z OneDrive', 'note' => ''],
        'owncloud'  => ['label' => 'Załączanie plików z ownCloud', 'note' => 'magazyn zespołowy Fundacji'],
        'mobile'    => ['label' => 'Wygodne na telefonie', 'note' => ''],
        'light'     => ['label' => 'Szybki przy słabym łączu i na starszym sprzęcie', 'note' => ''],
        'supported' => ['label' => 'Aktywnie rozwijany i łatany', 'note' => ''],
    ];
}

/**
 * Pełny katalog klientów — także tych bez ustawionego adresu.
 * Wartości cech: 'yes' | 'part' | 'no'.
 *
 * @return array<int, array<string, mixed>>
 */
function webmail_clients_all(): array {
    return [
        [
            'key'      => 'owa',
            'label'    => 'Outlook w przeglądarce',
            'short'    => 'Outlook',
            'icon'     => 'bi-microsoft',
            'url'      => rtrim(org_setting('poczta_owa_url') ?: 'https://outlook.office.com/mail', '/') . '/',
            'badge'    => 'zalecany',
            'tagline'  => 'pełny Microsoft 365 — poczta, kalendarz, Teams, skrzynki wspólne',
            'hint'     => 'Natywny klient Microsoftu. Wybierz go, jeśli pracujesz na koncie '
                        . '@feer.org.pl codziennie: umawiasz spotkania, prowadzisz kalendarz, '
                        . 'obsługujesz skrzynkę wspólną.',
            'features' => [
                'mailbox' => 'yes', 'ms_login' => 'yes', 'calendar' => 'yes', 'teams' => 'yes',
                'gal' => 'yes', 'shared' => 'yes', 'rules' => 'yes', 'onedrive' => 'yes',
                'owncloud' => 'no', 'mobile' => 'yes', 'light' => 'part', 'supported' => 'yes',
            ],
        ],
        [
            'key'      => 'rc',
            'label'    => 'Roundcube',
            'short'    => 'Roundcube',
            'icon'     => 'bi-envelope-open',
            'url'      => rtrim(org_setting('poczta_webmail_url') ?: '', '/'),
            'badge'    => '',
            'tagline'  => 'lekki webmail — sama poczta, szybki na słabym łączu',
            'hint'     => 'Tylko poczta: bez kalendarza, Teams i skrzynek współdzielonych. '
                        . 'W zamian otwiera się na słabym łączu i starszym sprzęcie, logujesz się '
                        . 'jednym przyciskiem „Microsoft 365", dołączasz pliki z OneDrive i ownCloud.',
            'features' => [
                'mailbox' => 'yes', 'ms_login' => 'yes', 'calendar' => 'no', 'teams' => 'no',
                'gal' => 'part', 'shared' => 'no', 'rules' => 'no', 'onedrive' => 'yes',
                'owncloud' => 'yes', 'mobile' => 'part', 'light' => 'yes', 'supported' => 'yes',
            ],
            'notes'    => ['gal' => 'kopia kontaktów z Outlooka, odświeżana przy logowaniu',
                           'mobile' => 'przez przeglądarkę (responsywny), bez aplikacji'],
        ],
        [
            'key'      => 'snappy',
            'label'    => 'SnappyMail',
            'short'    => 'SnappyMail',
            'icon'     => 'bi-lightning-charge',
            'url'      => rtrim(org_setting('poczta_snappy_url') ?: '', '/'),
            'badge'    => '',
            'tagline'  => 'najszybszy interfejs — jednostronicowa aplikacja, działa jak PWA',
            'hint'     => 'Nowoczesny, bardzo szybki klient (rozwinięcie RainLoop). Sama poczta, '
                        . 'jak Roundcube, ale lżejszy interfejs i wygodniejszy na telefonie — '
                        . 'można go „zainstalować" jako aplikację z przeglądarki.',
            'features' => [
                'mailbox' => 'yes', 'ms_login' => 'part', 'calendar' => 'no', 'teams' => 'no',
                'gal' => 'no', 'shared' => 'no', 'rules' => 'no', 'onedrive' => 'no',
                'owncloud' => 'no', 'mobile' => 'yes', 'light' => 'yes', 'supported' => 'yes',
            ],
            'notes'    => ['ms_login' => 'OAuth2 do Microsoft 365 trzeba włączyć w konfiguracji SnappyMail (osobna rejestracja aplikacji Azure)',
                           'mobile'   => 'responsywny + instalowalny jako PWA'],
        ],
        [
            'key'      => 'squirrel',
            'label'    => 'SquirrelMail',
            'short'    => 'SquirrelMail',
            'icon'     => 'bi-archive',
            'url'      => rtrim(org_setting('poczta_squirrel_url') ?: '', '/'),
            'badge'    => 'awaryjny',
            'tagline'  => 'archaiczny, ale działa wszędzie — tylko dla skrzynek z hasłem IMAP',
            'hint'     => 'Klient z 2011 roku: czysty HTML, otwiera się na dowolnym sprzęcie '
                        . 'i przeglądarce. NIE zaloguje się do skrzynki Microsoft 365 — nie zna '
                        . 'nowoczesnego logowania (OAuth2), a Microsoft wyłączył logowanie '
                        . 'hasłem do IMAP. Ma sens tylko dla skrzynek na innym serwerze poczty.',
            'features' => [
                'mailbox' => 'part', 'ms_login' => 'no', 'calendar' => 'no', 'teams' => 'no',
                'gal' => 'no', 'shared' => 'no', 'rules' => 'no', 'onedrive' => 'no',
                'owncloud' => 'no', 'mobile' => 'no', 'light' => 'yes', 'supported' => 'no',
            ],
            'notes'    => ['mailbox'   => 'tylko skrzynki spoza Microsoft 365',
                           'ms_login'  => 'brak obsługi OAuth2 — Exchange Online odrzuci logowanie',
                           'supported' => 'ostatnie wydanie 2011 — bez poprawek bezpieczeństwa'],
        ],
    ];
}

/**
 * Klienci gotowi do pokazania użytkownikom — tylko z ustawionym adresem.
 *
 * @return array<int, array<string, mixed>>
 */
function webmail_clients(): array {
    return array_values(array_filter(webmail_clients_all(), fn($c) => $c['url'] !== ''));
}

/** Symbol + opis tekstowy (dla czytników ekranu) wartości cechy. */
function webmail_mark(string $v): array {
    return match ($v) {
        'yes'  => ['✓', 'yes',  'tak'],
        'part' => ['≈', 'part', 'częściowo'],
        default => ['—', 'no',  'nie'],
    };
}
