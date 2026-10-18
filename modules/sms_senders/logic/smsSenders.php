<?php
/**
 * modules/sms_senders/logic/smsSenders.php — nadawca SMS wg rodzaju wiadomości.
 *
 * Każdy SMS wysyłany przez system ma RODZAJ (ostatni parametr sms_send / sms_send_with_fallback / ti_lesson_sms_notify): kod
 * logowania, kod 2FA, dane dostępowe, powiadomienie o zajęciach, przypomnienie o wpłacie… Katalog rodzajów jest poniżej.
 * Dla każdego rodzaju administrator wybiera w Ustawieniach SMS nadawcę: nazwę (np. „tte”) albo ECO (bez nazwy nadawcy);
 * puste = domyślny nadawca dostawcy. Wartość leży w ustawieniu sms_sender_kind_<rodzaj>, a rozstrzyga ją sms_kind_sender()
 * w includes/sms.php — tam jest też obsługa u każdego dostawcy: SerwerSMS (kanał normalny z nazwą albo ECO), smsapi.pl (pole from),
 * brama HTTP ({sender}); Twilio wysyła zawsze z numeru nadawcy z konfiguracji.
 *
 * Rodzaje z szablonami SMS (TI) pochodzą z sms_tpl_registry() — nowy szablon pojawia się tu sam, jego klucz jest rodzajem.
 * Pozostałe rodzaje są na liście STATIC_KINDS. cli/smsKindsLint.php pilnuje, żeby każde wywołanie wysyłki miało rodzaj z katalogu.
 */
require_once dirname(__DIR__, 3) . '/includes/sms.php';
require_once dirname(__DIR__, 3) . '/includes/sms_templates.php';
require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';

final class SmsSenders
{
    /** Najdłuższa nazwa nadawcy (alfanumeryczna nazwa nadawcy w SMS ma maks. 11 znaków). */
    public const NAME_MAX = 11;

    private const G_CODES   = 'Kody jednorazowe i weryfikacja';
    private const G_ACCESS  = 'Dane dostępowe i konta';
    private const G_TI      = 'TI — Zajęcia';
    private const G_TI_PAY  = 'TI — Rozliczenia i płatności';
    private const G_MODULES = 'Moduły';

    /** Kolejność grup w tabeli; grupa spoza listy (np. z nowego szablonu) trafia na koniec. */
    private const GROUP_ORDER = [self::G_CODES, self::G_ACCESS, self::G_TI, self::G_TI_PAY, self::G_MODULES];

    /**
     * Rodzaje SMS bez szablonu: klucz => [grupa, nazwa, gdzie jest wysyłany].
     * Klucz: a-z, 0-9, _ (do 40 znaków) — to część nazwy ustawienia sms_sender_kind_<klucz>, więc go NIE zmieniamy po wdrożeniu.
     */
    private const STATIC_KINDS = [
        'login_code'             => [self::G_CODES, 'Kod logowania SMS', 'Logowanie kodem SMS (strona logowania).'],
        'twofa_code'             => [self::G_CODES, 'Kod 2FA', 'Logowanie dwuetapowe, ustawienie 2FA, potwierdzenie czynności (step-up).'],
        'phone_verify_code'      => [self::G_CODES, 'Kod weryfikacji numeru telefonu', 'Numer w koncie, dodatkowe numery powiadomień kursanta, onboarding wolontariusza.'],
        'register_code'          => [self::G_CODES, 'Kod rejestracji konta', 'Samodzielna rejestracja konta.'],
        'recovery_code'          => [self::G_CODES, 'Kod odzyskiwania dostępu', 'Odzyskiwanie dostępu, zgłoszenie problemu z logowaniem, kod awaryjny, kod odzyskiwania wolontariusza.'],
        'parent_access_code'     => [self::G_CODES, 'Kod dostępu rodzica do rozliczeń (TI)', 'Panel kursanta — wejście opiekuna do rozliczeń.'],
        'authorization_code'     => [self::G_CODES, 'Kod autoryzacji czynności', 'Podpisanie oświadczenia, zgoda na wejście administratora na konto.'],
        'document_code'          => [self::G_CODES, 'Kod i hasło do bezpiecznych dokumentów', 'EZD (udostępnianie z kodem), poczta z hasłem do pliku ZIP.'],
        'ika_code'               => [self::G_CODES, 'Kod IKA', 'Wysłanie kodu IKA z panelu administratora.'],

        'access_data'            => [self::G_ACCESS, 'Login i hasło do konta', 'Panel kursanta i rodzica, konto MS 365, ownCloud, VLab, reset hasła panelu.'],
        'account_activation'     => [self::G_ACCESS, 'Aktywacja konta — przypomnienia automatyczne', 'Codzienne przypomnienie o braku pierwszego logowania (cron).'],
        'whatsapp_group'         => [self::G_ACCESS, 'Link do grupy WhatsApp', 'Wolontariusz: zaproszenie do grupy.'],

        'ti_bulk_message'        => [self::G_TI, 'Wiadomość masowa do kursantów', 'Komunikacja i wiadomości wysyłane z panelu dydaktyka.'],
        'ti_dydaktyka_notice'    => [self::G_TI, 'Komunikat dydaktyka do grupy', 'Powiadomienie z modułu Dydaktyka dla uczestników kursu.'],
        'ti_lesson_cancelled'    => [self::G_TI, 'Odwołane zajęcia', 'Odwołanie lekcji.'],
        'ti_week_schedule'       => [self::G_TI, 'Plan zajęć na tydzień', 'Wysyłka planu bieżącego tygodnia z panelu dydaktyka.'],
        'ti_waiting_list'        => [self::G_TI, 'Lista oczekujących — kontakt', 'SMS do osoby z listy oczekujących.'],
        'ti_consult_message'     => [self::G_TI, 'Wiadomość do beneficjentów konsultacji', 'Komunikacja w trybie Konsultacje panelu dydaktyka: przypomnienia o terminach, zmiany, zaproszenia z kolejki.'],
        'ti_exam_notice'         => [self::G_TI, 'Powiadomienie o egzaminie', 'Agent ti_exam_notify: egzamin udostępniony / zamyka się w ciągu 24 h (treść: szablony ti_exam_open, ti_exam_closing).'],
        'ti_lesson_entry_reminder' => [self::G_TI, 'Zaległe wpisy z zajęć — przypomnienie dla prowadzącego', 'Agent ti_lesson_control: zbiorcze przypomnienie o brakującym lub niepełnym wpisie z lekcji (Kontrola wpisów; wyłączone, póki nie włączy kierownik).'],

        'ti_licencja_am5'        => [self::G_TI, 'Licencje AM5 / Office 365 AM7 — obieg', 'Licencje dydaktyczne i Office 365: wszczęcie procesu (osoba zainteresowana), link do podpisu wniosku i oświadczeń, decyzja, przypomnienia o terminie 7 dni.'],
        'ti_msues_notice'        => [self::G_TI, 'Certyfikacja MSUES — informacja dla kadry', 'Kadra › Certyfikacja MSUES: uruchomienie certyfikacji, przypomnienie i wysyłka pojedyncza do prowadzącego.'],
        'ti_nabor_status'        => [self::G_TI, 'Nabór do grup — status zgłoszenia', 'Nabór do grup TI: zmiana statusu zgłoszenia (SMS do rodzica / opiekuna).'],

        'ti_billing_notice'      => [self::G_TI_PAY, 'Rozliczenie za miesiąc', 'Powiadomienie o należności za zajęcia (kwota, termin, rachunek).'],
        'payment_account_notice' => [self::G_TI_PAY, 'Numer rachunku do wpłat', 'Portal płatności: nowy lub skorygowany numer rachunku.'],
        'payment_notice'         => [self::G_TI_PAY, 'Komunikat portalu płatności', 'Zbiorcze komunikaty do płacących.'],

        'tasks_notify'           => [self::G_MODULES, 'Zadania — powiadomienia', 'Przypisanie, termin, zmiany w zadaniach; test powiadomień.'],
        'helpdesk'               => [self::G_MODULES, 'Helpdesk — zgłoszenia', 'Zmiana statusu i nowa odpowiedź operatora dla zgłaszającego.'],
        'crm_message'            => [self::G_MODULES, 'CRM — SMS do kontaktu', 'Wiadomość wysłana z karty kontaktu CRM (z podpisem).'],
        'assistance'             => [self::G_MODULES, 'Asysta — zgłoszenia i przydziały', 'Status zgłoszenia i przydział asysty.'],
        'admin_message'          => [self::G_MODULES, 'Wiadomość od administratora', 'SMS wysyłane ręcznie z panelu administratora: przypomnienie o aktywacji konta, założenie konta wolontariusza.'],
    ];

    /**
     * Wszystkie rodzaje SMS: klucz => dane, w kolejności wyświetlania (statyczne, potem z rejestru szablonów).
     *
     * @return array<string, array{group:string, label:string, hint:string, template:bool}>
     */
    public static function kinds(): array
    {
        $out = [];
        foreach (self::STATIC_KINDS as $kind => [$group, $label, $hint]) {
            $out[$kind] = ['group' => $group, 'label' => $label, 'hint' => $hint, 'template' => false];
        }
        foreach (sms_tpl_registry() as $key => $def) {
            $out[$key] = ['group' => (string)($def['group'] ?? self::G_TI), 'label' => (string)$def['label'],
                          'hint'  => (string)($def['description'] ?? ''), 'template' => true];
        }
        return $out;
    }

    /**
     * Rodzaje pogrupowane do tabeli: grupa => [rodzaj => dane], grupy w ustalonej kolejności.
     *
     * @return array<string, array<string, array{group:string, label:string, hint:string, template:bool}>>
     */
    public static function groups(): array
    {
        $g = array_fill_keys(self::GROUP_ORDER, []);
        foreach (self::kinds() as $kind => $info) $g[$info['group']][$kind] = $info;
        return array_filter($g);
    }

    public static function exists(string $kind): bool
    {
        return isset(self::kinds()[$kind]);
    }

    /**
     * Zapisane wartości: rodzaj => „ECO” albo nazwa nadawcy (tylko rodzaje z ustawieniem). Czytane wprost z bazy —
     * sms_setting() ma pamięć podręczną na proces, a po zapisie w tym samym żądaniu pokazywałaby stare.
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        $out = [];
        foreach (db_all("SELECT key_, value FROM settings WHERE key_ LIKE 'sms_sender_kind_%'") as $r) {
            $key = (string)$r['key_'];
            if (!str_starts_with($key, 'sms_sender_kind_')) continue;          // „_” w LIKE to znak wieloznaczny
            $v = trim((string)$r['value']);
            if ($v !== '') $out[substr($key, strlen('sms_sender_kind_'))] = $v;
        }
        return $out;
    }

    /**
     * Oczyszcza wpisanego nadawcę: „ECO” (w dowolnej wielkości liter) → „ECO”; w nazwie zostają litery, cyfry, spacja, kropka,
     * myślnik i podkreślnik, skrócone do NAME_MAX znaków. Pusty wynik = brak ustawienia (domyślny nadawca dostawcy).
     */
    public static function normalize(string $value): string
    {
        $v = trim($value);
        if ($v === '') return '';
        if (strcasecmp($v, 'ECO') === 0) return 'ECO';
        return substr(trim((string)preg_replace('/[^A-Za-z0-9 ._-]/', '', $v)), 0, self::NAME_MAX);
    }

    /**
     * Zapisuje nadawców wg rodzaju. Rodzaje spoza katalogu i nieobecne w $values są pomijane (brak pola = bez zmiany);
     * pusta wartość usuwa ustawienie. Zmiany trafiają do audytu (sms.sender_kinds_saved: rodzaj → [było, jest]).
     *
     * @param array<string,mixed> $values rodzaj => wpisana wartość
     * @return array{changed: array<string,array{0:string,1:string}>, adjusted: list<string>}
     *         changed: rodzaje ze zmianą; adjusted: rodzaje, których wpis trzeba było poprawić (niedozwolone znaki, za długi)
     */
    public static function save(array $values, int $userId = 0): array
    {
        $before = self::all();
        $changed = []; $adjusted = [];
        foreach (array_keys(self::kinds()) as $kind) {
            if (!array_key_exists($kind, $values)) continue;
            $raw = is_string($values[$kind]) ? trim($values[$kind]) : '';
            $new = self::normalize($raw);
            if ($raw !== '' && strcasecmp($new, $raw) !== 0) $adjusted[] = $kind;
            $old = $before[$kind] ?? '';
            if ($new === $old) continue;

            $key = sms_kind_setting_key($kind);
            if ($key === '') continue;
            if ($new === '') {
                db()->prepare("DELETE FROM settings WHERE key_=?")->execute([$key]);
            } elseif (db_one("SELECT 1 FROM settings WHERE key_=?", [$key])) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$new, $key]);
            } else {
                db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$key, $new]);
            }
            $changed[$kind] = [$old, $new];
        }
        if ($changed) audit_log('sms.sender_kinds_saved', ['changed' => $changed], $userId);
        return ['changed' => $changed, 'adjusted' => $adjusted];
    }

    /**
     * Nadawca, który obowiąże dla rodzaju u wskazanego dostawcy (do kolumny „Obowiązuje teraz”).
     *
     * @return array{label:string, own:bool} own — nadawca ustawiony dla rodzaju (nie domyślny dostawcy)
     */
    public static function effective(string $provider, string $kind): array
    {
        if ($provider === 'twilio') return ['label' => 'numer nadawcy Twilio (bez zmian)', 'own' => false];
        if ($provider === 'sendly') return ['label' => 'Sender ID z panelu Sendly (bez zmian)', 'own' => false];
        $own = sms_kind_sender($kind);
        $s = $own ?? match ($provider) {
            'serwersms'   => sms_serwersms_sender(),
            'httprequest' => sms_setting('sms_sender_name') ?: 'INFO',
            default       => sms_smsapi_sender($kind, sms_setting('sms_api_token') === ''),
        };
        $label = match (true) {
            $provider === 'serwersms' && $s === '' => 'ECO',
            $provider === 'serwersms'              => $s . ' (kanał normalny)',
            $s === ''                              => 'domyślny nadawca konta',
            default                                => $s,
        };
        return ['label' => $label, 'own' => $own !== null];
    }
}
