<?php
/**
 * includes/webmail_clients.php — katalog sposobów otwierania poczty organizacji.
 *
 * JEDNO ŹRÓDŁO PRAWDY dla wszystkich miejsc, które pokazują „w czym czytać
 * pocztę": publicznej strony wyboru (webmail/index.php pod poczta.feer.org.pl),
 * kafla w panelu współpracownika (panel/includes/pv_home.php) i modułu Poczta
 * (poczta/dashboard.php, poczta/index.php przez poczta_webmail_options()).
 *
 * Od przełączenia 14.11.2026 skrzynki @feer.org.pl są na serwerze dPoczty
 * (moduł modules/dhosting_migrator). Decyzja 2026-11-14: webmailem jest
 * dPoczta dostawcy, bez własnych instalacji. Stąd katalog:
 *   • dpoczta  — webmail dostawcy, ZALECANY; adres z ustawienia migratora
 *                `dhosting_webmail_url` (DhostingConfig::webmailOrDefault(),
 *                domyślnie https://dpoczta.pl), nie wpisany na sztywno,
 *   • program  — aplikacja w telefonie albo program na komputerze (IMAP);
 *                prowadzi do instrukcji w Portalu Usług IT, nie do logowania,
 *   • owa      — STARA skrzynka w Microsoft 365 (Outlook w przeglądarce),
 *                pokazywana osobno, bez polecania, tylko do dnia wyłączenia
 *                Microsoft 365 (`sso_ms_off_date`, domyślnie 2026-11-20);
 *                potem znika sama, bez zmian w kodzie.
 * Wycofane 2026-11-14: Roundcube z logowaniem Microsoft 365 (docker/roundcube,
 * nigdzie nie działa), SnappyMail (docker/snappymail, ustawiony na serwer
 * Microsoft 365) i SquirrelMail (nigdy nie wdrożony). Ich ustawienia
 * poczta_webmail_url / poczta_snappy_url / poczta_squirrel_url zostają w bazie,
 * ale nic ich już nie czyta.
 *
 * W tekstach dla użytkowników nie piszemy nazwy dostawcy serwera (zasada
 * z modułu migracji): mówimy „dPoczta".
 *
 * Wymaga: includes/functions.php (org_setting()).
 */

/**
 * JEDEN adres poczty Fundacji: strona wyboru (webmail/index.php).
 *
 * Adres w domenie organizacji, który zawsze prowadzi do aktualnej poczty —
 * nawet gdy zmieni się dostawca albo program. Instrukcje, umowy i maile
 * kierują tutaj, a strona sama wskazuje dPocztę i inne sposoby.
 *
 * Zmiana adresu: Admin → Organizacja → `webmail_url`.
 */
function webmail_chooser_url(): string {
    return rtrim(org_setting('webmail_url') ?: 'https://poczta.feer.org.pl', '/');
}

/** Adres do pokazania (bez schematu) — „poczta.feer.org.pl". */
function webmail_chooser_label(): string {
    return (string)preg_replace('~^https?://~', '', webmail_chooser_url());
}

/**
 * Adres dPoczty w przeglądarce — z ustawień migratora poczty
 * (Admin → /migracjaadmin, pole „adres poczty przez przeglądarkę").
 */
function webmail_dpoczta_url(): string {
    try {
        require_once dirname(__DIR__) . '/modules/dhosting_migrator/lib/DhostingConfig.php';
        return rtrim(DhostingConfig::webmailOrDefault(), '/');
    } catch (\Throwable $e) {
        return 'https://dpoczta.pl';
    }
}

/** Adres dPoczty do pokazania (bez schematu) — „dpoczta.pl". */
function webmail_dpoczta_label(): string {
    return (string)preg_replace('~^https?://~', '', webmail_dpoczta_url());
}

/** Początek dnia z ustawienia RRRR-MM-DD albo z wartości domyślnej; null, gdy żadna nie pasuje. */
function webmail_setting_day(string $key, string $default = ''): ?int {
    $d = trim((string)org_setting($key));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = $default;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
    $t = strtotime($d . ' 00:00:00');
    return $t === false ? null : $t;
}

/** Dzień wyłączenia Microsoft 365 (to samo ustawienie co ekran logowania, SsoConfig::msOffDate()). */
function webmail_ms_off_ts(): int {
    return webmail_setting_day('sso_ms_off_date', '2026-11-20') ?? (int)strtotime('2026-11-20 00:00:00');
}

/** Dzień przełączenia poczty na dPocztę (ustawienie migratora `dhosting_switch_date`); null = nieustawione. */
function webmail_switch_ts(): ?int {
    return webmail_setting_day('dhosting_switch_date');
}

/** Data po polsku: „20 listopada 2026". */
function webmail_date_pl(int $ts): string {
    static $m = ['stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca',
                 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

/**
 * Adres strony SZO (kalendarz i kontakty, instrukcje, helpdesk) — zawsze na hoście SZO.
 * PUŁAPKA: bez zmiennej APP_URL w środowisku APP_URL bierze się z HTTP_HOST, więc pod
 * poczta.feer.org.pl wyszłoby https://poczta.feer.org.pl/…, a .htaccess przepisuje ten host
 * na /webmail/ (404). Podmieniamy wtedy poczta./webmail. na szo.
 */
function webmail_szo_url(string $path): string {
    $base = (string)preg_replace('~^(https?://)(?:poczta|webmail)\.~i', '$1szo.', rtrim(APP_URL, '/'));
    return $base . $path;
}

/**
 * Cechy do zestawienia — kolejność wierszy tabeli porównania.
 * Tylko to, co sprawdzone (instrukcje „Moja poczta" w Portalu Usług IT,
 * strona /kalendarz-kontakty, stan 2026-11-14).
 *
 * @return array<string, array{label:string, note:string}>
 */
function webmail_features(): array {
    return [
        'mailbox'        => ['label' => 'Twoja skrzynka @feer.org.pl i te same wiadomości', 'note' => ''],
        'login'          => ['label' => 'Logowanie adresem e-mail i hasłem do poczty',
                             'note'  => 'To hasło jest inne niż hasło logowania jednokrotnego do SZO i chmury plików.'],
        'noinstall'      => ['label' => 'Działa bez instalowania', 'note' => ''],
        'calendar'       => ['label' => 'Kalendarz i zaproszenia na spotkania', 'note' => ''],
        'cloud_calendar' => ['label' => 'Kalendarz z chmury plików', 'note' => ''],
        'cloud_contacts' => ['label' => 'Kontakty z chmury plików', 'note' => ''],
        'filters'        => ['label' => 'Filtry i autoodpowiedź',
                             'note'  => 'Działają na serwerze, więc obowiązują wszędzie, także gdy dPoczta jest zamknięta.'],
    ];
}

/**
 * Pełny katalog — także wpisy bez adresu i stara skrzynka Microsoft 365.
 * Wartości cech: 'yes' | 'part' | 'no'.
 *   kind   — 'web' (otwiera pocztę w przeglądarce) | 'app' (prowadzi do instrukcji)
 *   legacy — true = stara skrzynka Microsoft 365: nigdy nie polecana, poza zestawieniem,
 *            widoczna tylko przed `until` (dzień wyłączenia Microsoft 365)
 *   go     — tekst przycisku
 *
 * @return array<int, array<string, mixed>>
 */
function webmail_clients_all(): array {
    $off = webmail_ms_off_ts();
    return [
        [
            'key'      => 'dpoczta',
            'kind'     => 'web',
            'legacy'   => false,
            'until'    => null,
            'label'    => 'dPoczta w przeglądarce',
            'short'    => 'dPoczta',
            'icon'     => 'bi-envelope-open',
            'url'      => webmail_dpoczta_url(),
            'badge'    => 'zalecany',
            'tagline'  => 'poczta, kalendarz i kontakty na ' . webmail_dpoczta_label(),
            'hint'     => 'Otwierasz ją w każdej przeglądarce, na komputerze i w telefonie. '
                        . 'Logujesz się pełnym adresem e-mail, na przykład imie.nazwisko@feer.org.pl, i hasłem do poczty.',
            'go'       => 'Otwórz dPocztę',
            'features' => [
                'mailbox' => 'yes', 'login' => 'yes', 'noinstall' => 'yes', 'calendar' => 'yes',
                'cloud_calendar' => 'yes', 'cloud_contacts' => 'no', 'filters' => 'yes',
            ],
            'notes'    => [
                'calendar'       => 'kalendarz w dPoczcie',
                'cloud_calendar' => 'przez „Dodaj kalendarz CalDAV”',
                'cloud_contacts' => 'dPoczta ich nie pobiera',
                'filters'        => 'ustawiasz je tutaj',
            ],
        ],
        [
            'key'      => 'program',
            'kind'     => 'app',
            'legacy'   => false,
            'until'    => null,
            'label'    => 'Program w telefonie lub na komputerze',
            'short'    => 'Program',
            'icon'     => 'bi-phone',
            'url'      => webmail_szo_url('/uslugi-it/uslugi/moja-poczta/poczta-w-telefonie'),
            'badge'    => '',
            'tagline'  => 'aplikacja do poczty w telefonie, Thunderbird albo Outlook na komputerze',
            'hint'     => 'Skrzynkę dodajesz jako konto IMAP. Instrukcja podaje dane serwera do wpisania.',
            'go'       => 'Zobacz instrukcję',
            'features' => [
                'mailbox' => 'yes', 'login' => 'yes', 'noinstall' => 'part', 'calendar' => 'part',
                'cloud_calendar' => 'yes', 'cloud_contacts' => 'yes', 'filters' => 'part',
            ],
            'notes'    => [
                'mailbox'        => 'gdy dodasz ją jako konto IMAP',
                'login'          => 'wpisujesz je raz, przy dodawaniu konta',
                'noinstall'      => 'w telefonie zwykle wystarczy wbudowana aplikacja do poczty',
                'calendar'       => 'zależy od programu',
                'cloud_calendar' => 'połączysz na stronie Kalendarz i kontakty w SZO',
                'cloud_contacts' => 'połączysz na stronie Kalendarz i kontakty w SZO',
                'filters'        => 'działają, ale ustawiasz je w dPoczcie',
            ],
        ],
        [
            'key'      => 'owa',
            'kind'     => 'web',
            'legacy'   => true,
            'until'    => $off,
            'label'    => 'Outlook w przeglądarce',
            'short'    => 'Outlook',
            'icon'     => 'bi-archive',
            'url'      => rtrim(org_setting('poczta_owa_url') ?: 'https://outlook.office.com/mail', '/') . '/',
            'badge'    => 'wyłączany',
            'tagline'  => 'stara skrzynka w Microsoft 365',
            'hint'     => 'Przyda się tylko do zajrzenia do starych wiadomości. Działa do '
                        . webmail_date_pl($off - 86400) . '.',
            'go'       => 'Otwórz starą skrzynkę',
            'features' => [],
        ],
    ];
}

/**
 * Sposoby polecane użytkownikom: bez starej skrzynki Microsoft 365, tylko z ustawionym adresem.
 *
 * @return array<int, array<string, mixed>>
 */
function webmail_clients(): array {
    return array_values(array_filter(webmail_clients_all(), fn($c) => !$c['legacy'] && $c['url'] !== ''));
}

/**
 * Stara skrzynka Microsoft 365 — do dnia wyłączenia; potem pusta lista.
 *
 * @return array<int, array<string, mixed>>
 */
function webmail_clients_legacy(): array {
    $now = time();
    return array_values(array_filter(webmail_clients_all(),
        fn($c) => $c['legacy'] && $c['url'] !== '' && ($c['until'] === null || $now < $c['until'])));
}

/** Symbol + opis tekstowy (dla czytników ekranu) wartości cechy. */
function webmail_mark(string $v): array {
    return match ($v) {
        'yes'  => ['✓', 'yes',  'tak'],
        'part' => ['≈', 'part', 'częściowo'],
        default => ['—', 'no',  'nie'],
    };
}
