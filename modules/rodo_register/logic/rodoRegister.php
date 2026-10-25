<?php
/**
 * modules/rodo_register/logic/rodoRegister.php — obsługa RODO: rejestr czynności przetwarzania (art. 30), podmioty
 * przetwarzające (art. 28), wnioski osób (art. 15–21), naruszenia (art. 33–34) i retencja (art. 5 ust. 1 lit. e).
 *
 * OSOBNE od /rodo/ (upoważnienia) i modules/gdpr_clauses (klauzule informacyjne).
 *
 * Zasady:
 *  1. Dane wniosków i naruszeń (imię, e-mail, PESEL, opisy) są szyfrowane w bazie (modules/field_crypto) —
 *     rejestr RODO nie może sam stać się źródłem wycieku. E-mail i PESEL mają indeks ślepy do wyszukiwania.
 *  2. Realizacja wniosku to MAPA DANYCH (rodoDataMap): lista tabel, w których szukamy osoby po e-mailu / PESEL-u / telefonie.
 *     Nowy zbiór z danymi osobowymi = nowy wpis w mapie, inaczej wniosek o dostęp go nie zobaczy.
 *  3. Usunięcie = anonimizacja tylko tabel oznaczonych erasable. Dane objęte obowiązkiem przechowywania
 *     (umowy, faktury, darowizny, TI) zostają, a wniosek dostaje listę „zatrzymane z mocy prawa” do odpowiedzi.
 *  4. Anonimizacja jest nieodwracalna i uruchamia ją człowiek po przejrzeniu listy — nigdy cron.
 *  5. Retencja: reguły + raport kandydatów (bez automatycznego kasowania).
 */

require_once dirname(__DIR__, 2) . '/field_crypto/lib/fieldCrypto.php';
require_once dirname(__DIR__, 2) . '/field_crypto/logic/fcColumns.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';

const RODO_REQUEST_TYPES = [
    'dostep'       => 'Dostęp do danych (art. 15)',
    'sprostowanie' => 'Sprostowanie (art. 16)',
    'usuniecie'    => 'Usunięcie — „prawo do bycia zapomnianym” (art. 17)',
    'ograniczenie' => 'Ograniczenie przetwarzania (art. 18)',
    'przenoszenie' => 'Przeniesienie danych (art. 20)',
    'sprzeciw'     => 'Sprzeciw (art. 21)',
    'cofniecie'    => 'Cofnięcie zgody (art. 7 ust. 3)',
];
const RODO_REQUEST_STATUS = [
    'nowe'        => ['Nowe', 'text-bg-warning'],
    'weryfikacja' => ['Weryfikacja tożsamości', 'text-bg-info'],
    'w_toku'      => ['W toku', 'text-bg-primary'],
    'zrealizowane' => ['Zrealizowane', 'text-bg-success'],
    'odmowa'      => ['Odmowa / częściowo', 'text-bg-secondary'],
];
const RODO_BREACH_STATUS = [
    'wykryte'   => ['Wykryte', 'text-bg-danger'],
    'ocena'     => ['W ocenie ryzyka', 'text-bg-warning'],
    'zgloszone' => ['Zgłoszone do UODO', 'text-bg-info'],
    'bez_zgloszenia' => ['Bez zgłoszenia (ryzyko nieprawdopodobne)', 'text-bg-secondary'],
    'zamkniete' => ['Zamknięte', 'text-bg-success'],
];
const RODO_PODSTAWY = [
    '6.1.a' => 'art. 6 ust. 1 lit. a — zgoda', '6.1.b' => 'art. 6 ust. 1 lit. b — umowa', '6.1.c' => 'art. 6 ust. 1 lit. c — obowiązek prawny',
    '6.1.e' => 'art. 6 ust. 1 lit. e — zadanie publiczne', '6.1.f' => 'art. 6 ust. 1 lit. f — prawnie uzasadniony interes',
];
const RODO_DEADLINE_DAYS = 30;       // art. 12 ust. 3 — miesiąc na odpowiedź
const RODO_BREACH_HOURS  = 72;       // art. 33 ust. 1 — zgłoszenie do UODO

function rodoMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('rodo_register_migrate', __FILE__)) return;
    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS rodo_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT, zbior TEXT NOT NULL, cel TEXT NOT NULL DEFAULT '', podstawa TEXT NOT NULL DEFAULT '',
        kategorie_osob TEXT NOT NULL DEFAULT '', kategorie_danych TEXT NOT NULL DEFAULT '', szczegolne INTEGER NOT NULL DEFAULT 0,
        odbiorcy TEXT NOT NULL DEFAULT '', transfer_poza_eog TEXT NOT NULL DEFAULT '', retencja TEXT NOT NULL DEFAULT '',
        srodki TEXT NOT NULL DEFAULT '', rola TEXT NOT NULL DEFAULT 'administrator', dpia TEXT NOT NULL DEFAULT 'nie_dotyczy',
        wlasciciel TEXT NOT NULL DEFAULT '', moduly TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'szkic',
        przegladniete_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS rodo_processors (
        id INTEGER PRIMARY KEY AUTOINCREMENT, nazwa TEXT NOT NULL, zakres TEXT NOT NULL DEFAULT '', kraj TEXT NOT NULL DEFAULT 'PL/EOG',
        umowa_status TEXT NOT NULL DEFAULT 'brak', umowa_data TEXT, transfer_podstawa TEXT NOT NULL DEFAULT '', uwagi TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS rodo_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT, typ TEXT NOT NULL, wnioskodawca TEXT, email TEXT, email_idx TEXT NOT NULL DEFAULT '',
        pesel TEXT, pesel_idx TEXT NOT NULL DEFAULT '', opis TEXT, odpowiedz TEXT, kanal TEXT NOT NULL DEFAULT 'email',
        data_wplywu TEXT NOT NULL, termin TEXT NOT NULL, termin_przedluzony INTEGER NOT NULL DEFAULT 0,
        tozsamosc_zweryfikowana_at TEXT, tozsamosc_metoda TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'nowe',
        zamkniete_at TEXT, created_by INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_rodo_req_status ON rodo_requests(status, termin)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_rodo_req_email ON rodo_requests(email_idx)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_rodo_req_pesel ON rodo_requests(pesel_idx)");
    $db->exec("CREATE TABLE IF NOT EXISTS rodo_request_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, request_id INTEGER NOT NULL, zdarzenie TEXT NOT NULL, szczegoly TEXT NOT NULL DEFAULT '',
        user_id INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS rodo_breaches (
        id INTEGER PRIMARY KEY AUTOINCREMENT, wykryto_at TEXT NOT NULL, opis TEXT, kategorie_danych TEXT NOT NULL DEFAULT '',
        liczba_osob INTEGER, skutki TEXT, srodki_zaradcze TEXT NOT NULL DEFAULT '', ryzyko TEXT NOT NULL DEFAULT 'nieoceniono',
        zgloszono_uodo_at TEXT, powiadomiono_osoby_at TEXT, uzasadnienie_braku_zgloszenia TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'wykryte', created_by INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS rodo_retention_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT, zbior TEXT NOT NULL, tabela TEXT NOT NULL, kolumna_daty TEXT NOT NULL,
        miesiecy INTEGER NOT NULL, uwagi TEXT NOT NULL DEFAULT '', aktywna INTEGER NOT NULL DEFAULT 1,
        UNIQUE(tabela, kolumna_daty))");
    // forma zbioru (papierowa / elektroniczna / mieszana) i miejsce przechowywania — dopisane po pierwszej wersji tabeli
    $have = array_column($db->query("PRAGMA table_info(rodo_activities)")->fetchAll(), 'name');
    if (!in_array('forma', $have, true))       $db->exec("ALTER TABLE rodo_activities ADD COLUMN forma TEXT NOT NULL DEFAULT 'elektroniczna'");
    if (!in_array('lokalizacja', $have, true)) $db->exec("ALTER TABLE rodo_activities ADD COLUMN lokalizacja TEXT NOT NULL DEFAULT ''");
    rodoSeed();
}

const RODO_FORMY = [
    'elektroniczna' => 'Elektroniczna (system informatyczny)',
    'papierowa'     => 'Papierowa (teczki, segregatory)',
    'mieszana'      => 'Mieszana (papier + system)',
];

/** Zasiew: zbiory z analizy docs/rejestr-danych-osobowych-szo.md, podmioty z integracji, domyślne reguły retencji. */
function rodoSeed(): void {
    // Uzupełnia brakujące zbiory po nazwie (nie nadpisuje edycji) — nowe zbiory z kolejnych wersji dochodzą same.
    foreach (rodoDefaultActivities() as $a) {
        if (!db_one("SELECT 1 FROM rodo_activities WHERE zbior = ?", [$a['zbior']])) db_insert('rodo_activities', $a + ['status' => 'szkic']);
    }
    if ((int)(db_one("SELECT COUNT(*) c FROM rodo_processors")['c'] ?? 0) === 0) {
        foreach (rodoDefaultProcessors() as [$n, $z, $k, $t]) {
            db_insert('rodo_processors', ['nazwa' => $n, 'zakres' => $z, 'kraj' => $k, 'transfer_podstawa' => $t]);
        }
    }
    rodoBackfillForma();
    // Asystent AI: wpis z pierwszej wersji (przed anonimizacją) zastępujemy nowym, o ile nikt go nie edytował
    foreach (rodoDefaultActivities() as $a) if ($a['zbior'] === 'Asystent AI') {
        db_exec("UPDATE rodo_activities SET cel = ?, kategorie_danych = ?, odbiorcy = ?, moduly = ?, dpia = ?, szczegolne = 0
                 WHERE zbior = 'Asystent AI' AND kategorie_danych = 'Treść zapytań i fragmenty danych SZO wysyłane do dostawcy AI'",
            [$a['cel'], $a['kategorie_danych'], $a['odbiorcy'], $a['moduly'], $a['dpia']]);
    }
    db_exec("UPDATE rodo_processors SET uwagi = 'Co do zasady bez danych osobowych — anonimizacja przed wysyłką (modules/ai_anonymizer). Zweryfikować DPA.' WHERE nazwa = 'Anthropic' AND uwagi = ''");
    foreach ([
        ['Rekrutacja', 'rekr_applications', 'created_at', 12, 'Po zakończeniu naboru, chyba że zgoda na kolejne'],
        ['Wydarzenia', 'ev_registrations', 'created_at', 12, 'Po wydarzeniu'],
        ['Helpdesk IT', 'helpdesk_tickets', 'created_at', 36, 'Zgłoszenia zamknięte'],
        ['Logi logowań', 'login_log', 'created_at', 12, 'Adresy IP — bezpieczeństwo'],
        ['Kolejka poczty', 'mail_queue', 'created_at', 12, 'Treść wiadomości wysłanych'],
        ['Dziennik wysyłek', 'email_send_log', 'sent_at', 24, ''],
        ['CRM formularze', 'crm_form_intake_log', 'created_at', 12, 'Payload z danymi osobowymi'],
    ] as [$z, $t, $c, $m, $u]) {
        db_exec("INSERT OR IGNORE INTO rodo_retention_rules (zbior, tabela, kolumna_daty, miesiecy, uwagi) VALUES (?,?,?,?,?)", [$z, $t, $c, $m, $u]);
    }
}

function rodoDefaultActivities(): array {
    $m = fn(string $zbior, string $cel, string $podst, string $osob, string $dane, int $sz, string $odb, string $ret, string $mod, string $dpia = 'nie_dotyczy') => [
        'zbior' => $zbior, 'cel' => $cel, 'podstawa' => $podst, 'kategorie_osob' => $osob, 'kategorie_danych' => $dane, 'szczegolne' => $sz,
        'odbiorcy' => $odb, 'retencja' => $ret, 'moduly' => $mod, 'dpia' => $dpia,
        'srodki' => 'Kontrola dostępu wg ról, 2FA/WebAuthn, logi audytu, kopie zapasowe, szyfrowanie pól wrażliwych (field_crypto), transmisja HTTPS',
    ];
    $list = [
        $m('Konta użytkowników i uwierzytelnianie', 'Zapewnienie dostępu do systemu i jego bezpieczeństwa', '6.1.b, 6.1.f', 'Pracownicy, współpracownicy, wolontariusze, administratorzy', 'Imię, nazwisko, e-mail, telefon, hash hasła, TOTP/WebAuthn, adres IP, zdjęcie', 0, 'Microsoft 365, dostawcy SMS', 'Czas posiadania konta + 12 mies. (logi)', 'users, login_log, user_sessions, webauthn_credentials'),
        $m('Umowy cywilnoprawne i kadry', 'Zawarcie i rozliczenie umów, obowiązki podatkowe i ubezpieczeniowe', '6.1.b, 6.1.c', 'Zleceniobiorcy, wykonawcy, pracownicy, wolontariusze', 'Imię, nazwisko, PESEL, adres, seria i nr dowodu, rachunek bankowy, wynagrodzenie, US', 0, 'ZUS, urząd skarbowy, banki, SharePoint, DocuSign/Autenti', 'Wg przepisów (ZUS/podatki — do 5 lat od końca roku; dokumentacja pracownicza 10 lat)', 'umowy_*, persons, zlecenie_*, timesheets'),
        $m('Wolontariat — nabór, onboarding, RPTS', 'Organizacja wolontariatu, ochrona małoletnich', '6.1.b, 6.1.c, 9.2.b', 'Kandydaci i wolontariusze (także małoletni), opiekunowie', 'Dane identyfikacyjne, PESEL, rachunek, RPTS (dane o karalności), skierowania na badania', 1, 'Placówki, w których wolontariusz działa', 'Do końca współpracy + okres przedawnienia roszczeń', 'onboarding_volunteers, volunteer_applications, skierowania_badania', 'wymagane'),
        $m('Rekrutacja', 'Przeprowadzenie naboru na stanowiska', '6.1.a, 6.1.b', 'Kandydaci do pracy', 'Imię, nazwisko, e-mail, telefon, CV i treść zgłoszenia', 0, '—', 'Do zakończenia naboru; z odrębnej zgody — do 12 mies.', 'rekr_*'),
        $m('TI — uczestnicy szkoleń', 'Realizacja szkoleń, rozliczenia, e-dziennik', '6.1.b, 6.1.c, 9.2.a, 9.2.h', 'Uczestnicy (w tym małoletni, z niepełnosprawnością wzroku), rodzice, osoby upoważnione', 'Dane kontaktowe, PESEL/data urodzenia, profil dostępności (zdrowie), frekwencja, oceny, płatności', 1, 'Zoom, Stripe/PayU/P24, Microsoft 365, SerwerSMS', 'Dokumentacja szkoleń wg wymogów finansującego; płatności 5 lat', 'k30_*, ti_nabor_*', 'wymagane'),
        $m('TI — kadra dydaktyczna', 'Organizacja zajęć i wynagradzanie prowadzących', '6.1.b', 'Prowadzący, konsultanci', 'Dane kontaktowe, kalendarze, stawki, urlopy', 0, 'Zoom, Microsoft 365', 'Czas współpracy + 5 lat', 'k30_ti_instructor_*, ti_consultant_*'),
        $m('Licencje', 'Obsługa licencji i operacji licencyjnych', '6.1.b, 6.1.c', 'Licencjodawcy, posiadacze licencji, wnioskodawcy', 'E-mail, telefon, adres, IP podpisu', 0, 'Dostawcy licencji', 'Czas licencji + 5 lat', 'licenses, license_*'),
        $m('CRM', 'Relacje z kontrahentami, beneficjentami i darczyńcami, komunikacja', '6.1.a, 6.1.f', 'Kontakty, osoby kontaktowe, beneficjenci, darczyńcy', 'Imię, nazwisko, PESEL, adres, e-mail, telefon, historia kontaktów, zgody, IP', 0, 'Microsoft 365, dostawcy SMS', 'Reguły retencji CRM (includes/crm_retention.php)', 'crm_*'),
        $m('Darowizny', 'Przyjmowanie i dokumentowanie darowizn, PIT', '6.1.c', 'Darczyńcy', 'Imię, nazwisko, adres, PESEL, NIP, rachunek', 0, 'Urząd skarbowy', '5 lat od końca roku podatkowego', 'donations'),
        $m('Księgowość, EODoK, KDOK, faktury, płatności', 'Prowadzenie ksiąg, rozliczenia, płatności', '6.1.b, 6.1.c', 'Kontrahenci-osoby, odbiorcy faktur, płatnicy', 'NIP, rachunki, e-mail, kwoty, dokumenty księgowe', 0, 'Fakturownia, KSeF, Comarch Betterfly, banki, Stripe, PayU, P24', '5 lat od końca roku obrotowego', 'edok_*, kdok_*, invoices, us_przelewy'),
        $m('EZD — dokumentacja i korespondencja', 'Obieg dokumentów i korespondencji', '6.1.c, 6.1.e', 'Nadawcy i adresaci pism, strony spraw', 'Dane adresowe, treść pism', 0, 'Postivo, e-Doręczenia', 'Wg JRWA (kategoria archiwalna)', 'ezd_*, correspondence'),
        $m('Poczta, kalendarz i zadania', 'Komunikacja i organizacja pracy', '6.1.b, 6.1.f', 'Pracownicy, korespondenci', 'Adresy e-mail, treść wiadomości, zadania', 0, 'Microsoft 365, TidyCal, Nozbe', 'Wg reguł skrzynki; kolejka poczty 12 mies.', 'poczta*, mail_queue, tasks*'),
        $m('Helpdesk IT i kontrola IT', 'Obsługa zgłoszeń, bezpieczeństwo IT', '6.1.b, 6.1.f', 'Zgłaszający, operatorzy', 'E-mail, telefon, opis zgłoszenia', 0, 'Redmine', '36 mies. od zamknięcia', 'helpdesk_*, it_control, it_service_passwords'),
        $m('Wydarzenia, komunikaty, wsparcie OU', 'Rejestracja na wydarzenia i obsługa wsparcia', '6.1.a, 6.1.b', 'Uczestnicy wydarzeń, odbiorcy', 'Imię, e-mail, telefon, opis problemu', 0, '—', '12 mies. od wydarzenia', 'ev_*, announcements, szo_assistance_requests'),
        $m('Rejestry RODO', 'Wykazanie zgodności (rozliczalność)', '6.1.c', 'Osoby upoważnione, wnioskodawcy, osoby, których dane usunięto', 'PESEL, IP, daty zgód, treść wniosków (szyfrowane)', 0, 'UODO', 'Czas przetwarzania + 6 lat (rozliczalność)', 'rodo_*, gdpr_clauses'),
        $m('Bezpieczeństwo i audyt', 'Wykrywanie nadużyć i dowody zdarzeń', '6.1.c, 6.1.f', 'Wszyscy użytkownicy', 'IP, e-mail, akcje, znaczniki czasu', 0, '—', '12–24 mies.', 'admin_audit_log, audit_logs, *_access_log'),
        $m('Asystent AI', 'Pomoc w wyszukiwaniu informacji w systemie (instrukcje, procedury, ekrany)', '6.1.f', 'Pracownicy zadający pytania', 'Co do zasady brak danych osobowych: przed wysyłką do dostawcy AI e-mail, PESEL, NIP, rachunek, telefon, dokument oraz imiona i nazwiska ze słownika są zastępowane znacznikami (moduł ai_anonymizer); do modelu trafiają treść pytań i fragmenty dokumentów po anonimizacji', 0, 'Anthropic (po anonimizacji)', 'Brak trwałego zapisu po stronie dostawcy wg umowy — zweryfikować', 'asystent_ai, chatbot, ai_anonymizer', 'nie_dotyczy'),
        $m('Integracje zewnętrzne (MSUES, SAML, API)', 'Udostępnianie kont i wiadomości aplikacjom organizacji', '6.1.b, 6.1.f', 'Użytkownicy, koordynatorzy', 'Lista kont, e-maile', 0, 'Aplikacje organizacji', 'Czas współpracy', 'api/v1/msues_*, saml_idp'),
        $m('Pełnomocnictwa i upoważnienia', 'Rejestr pełnomocnictw i upoważnień do przetwarzania danych', '6.1.c, 6.1.f', 'Pełnomocnicy, mocodawcy, osoby upoważnione', 'Imię, nazwisko, PESEL pełnomocnika, e-mail, zakres umocowania', 0, 'Instytucje, wobec których udzielono pełnomocnictwa', 'Czas obowiązywania + 5 lat', 'pelnomocnictwa, rodo_authorizations'),
        $m('Karty NFC i kontrola dostępu', 'Kontrola dostępu do pomieszczeń i systemów', '6.1.f', 'Pracownicy, współpracownicy', 'Identyfikator karty, imię i nazwisko, zdarzenia użycia karty', 0, '—', 'Czas posiadania karty + 12 mies. (zdarzenia)', 'smart_cards, audit_logs'),
        $m('VPN i dostęp zdalny', 'Bezpieczny zdalny dostęp do zasobów', '6.1.f', 'Użytkownicy VPN', 'Identyfikator konta, adres IP, czas połączeń, klucze/certyfikaty', 0, '—', '12 mies. (logi połączeń)', 'vpn'),
        $m('Certyfikaty X.509 (SzoCert)', 'Logowanie certyfikatem i podpisy lokalne', '6.1.b, 6.1.f', 'Użytkownicy, kontrahenci', 'Imię, nazwisko, e-mail, numer seryjny i odcisk certyfikatu', 0, '—', 'Okres ważności certyfikatu + 5 lat', 'certificates, certs, auth/x509'),
        $m('Portal Kontrahenta (EODoK)', 'Akceptacja dokumentów księgowych przez kontrahentów', '6.1.b, 6.1.c', 'Kontrahenci i ich przedstawiciele', 'NIP, e-mail, hasło (hash), historia akceptacji, IP', 0, '—', 'Czas współpracy + 5 lat', 'portal_kontrahenta, edok_kontrahent_accounts'),
        $m('Konsultacje TI', 'Karty konsultacji i rezerwacje', '6.1.b', 'Uczestnicy konsultacji, konsultanci', 'Imię, kontakt, opis problemu, termin', 0, 'Zoom', 'Wg zasad projektu finansującego', 'szo_consultation_cards, ti_bookings', 'wymagane'),
        $m('Obiegi i zadania', 'Obieg dokumentów i zadań wewnętrznych', '6.1.f', 'Pracownicy, współpracownicy', 'Imię, nazwisko, e-mail, treść zadań i komentarzy, pliki', 0, 'Microsoft 365, Nozbe (import)', 'Czas trwania zadania + 24 mies.', 'obiegi, tasks, task_*'),
        $m('Kopie zapasowe', 'Zapewnienie ciągłości działania i odtwarzania danych', '6.1.c, 6.1.f', 'Wszystkie osoby w systemie', 'Pełna kopia bazy i plików (wszystkie kategorie danych)', 1, 'SharePoint (Microsoft 365), hosting', 'Rotacja kopii; usunięcie osoby wygasa z kopii wg cyklu rotacji', 'cron/agents/backup, sp_backup_full', 'wymagane'),
        $m('Dokumentacja papierowa i archiwum zakładowe', 'Przechowywanie oryginałów umów, zgód i dokumentów podpisanych odręcznie', '6.1.b, 6.1.c', 'Zleceniobiorcy, wolontariusze, uczestnicy, rodzice, kontrahenci', 'Oryginały umów, zgód, oświadczeń, skierowań, protokołów (PESEL, adres, dowód, dane o zdrowiu)', 1, 'Archiwum zewnętrzne (jeśli dotyczy)', 'Wg JRWA i przepisów (5–10 lat; dokumentacja pracownicza 10 lat)', 'ezd (Koszulki/Segregatory), ezd_archiwum', 'wymagane'),
    ];
    // Forma zbioru i miejsce przechowywania: domyślnie elektroniczna; papier tylko tam, gdzie system sam tworzy lub wymaga oryginałów.
    $forma = [
        'Umowy cywilnoprawne i kadry' => ['mieszana', 'Baza SZO + SharePoint + teczki papierowe w archiwum zakładowym'],
        'Wolontariat — nabór, onboarding, RPTS' => ['mieszana', 'Baza SZO + oryginały zgód i zaświadczeń w teczkach'],
        'TI — uczestnicy szkoleń' => ['mieszana', 'Baza SZO + dokumenty podpisane odręcznie (protokoły, zgody)'],
        'Darowizny' => ['mieszana', 'Baza SZO + potwierdzenia papierowe'],
        'EZD — dokumentacja i korespondencja' => ['mieszana', 'Baza EZD + akta papierowe (Koszulki w Segregatorach)'],
        'Rekrutacja' => ['mieszana', 'Baza SZO + dokumenty aplikacyjne (e-mail, papier)'],
        'Pełnomocnictwa i upoważnienia' => ['mieszana', 'Baza SZO + oryginały pełnomocnictw'],
        'Dokumentacja papierowa i archiwum zakładowe' => ['papierowa', 'Archiwum zakładowe / szafy zamykane — wpisać lokalizację'],
        'Kopie zapasowe' => ['elektroniczna', 'SharePoint (Microsoft 365) i serwer hostingu'],
    ];
    foreach ($list as &$a) {
        [$a['forma'], $a['lokalizacja']] = $forma[$a['zbior']] ?? ['elektroniczna', 'Baza danych SZO (hosting)'];
    }
    return $list;
}

/** Uzupełnia formę i lokalizację w wpisach z pierwszej wersji (tylko puste wartości domyślne — bez nadpisywania edycji). */
function rodoBackfillForma(): void {
    foreach (rodoDefaultActivities() as $a) {
        db_exec("UPDATE rodo_activities SET forma = ?, lokalizacja = ? WHERE zbior = ? AND lokalizacja = ''", [$a['forma'], $a['lokalizacja'], $a['zbior']]);
    }
}

function rodoDefaultProcessors(): array {
    return [
        ['Microsoft (Microsoft 365, Graph, SharePoint, Azure)', 'Konta, poczta, pliki, hosting maszyn VLAB', 'EOG/USA', 'DPA Microsoft + EU Data Boundary / SCC'],
        ['Fakturownia', 'Wystawianie faktur', 'PL/EOG', ''],
        ['Comarch (Betterfly)', 'Faktury CRM / TI', 'PL/EOG', ''],
        ['Krajowy System e-Faktur (KSeF)', 'E-faktury', 'PL', 'Organ państwowy — odrębny administrator'],
        ['Stripe', 'Płatności kartą', 'EOG/USA', 'SCC / DPF'],
        ['PayU', 'Płatności online', 'PL', ''],
        ['Przelewy24 (PayPro)', 'Płatności online', 'PL', ''],
        ['SerwerSMS', 'Wysyłka SMS', 'PL', ''],
        ['SMSAPI', 'Wysyłka SMS', 'PL', ''],
        ['Twilio', 'Wysyłka SMS (zapas)', 'USA', 'SCC / DPF'],
        ['Zoom', 'Spotkania szkoleniowe TI', 'USA', 'SCC / DPF'],
        ['TidyCal', 'Rezerwacje terminów', 'USA', 'SCC / DPF'],
        ['DocuSign', 'Podpis elektroniczny', 'USA/EOG', 'SCC / DPF'],
        ['Autenti', 'Podpis elektroniczny', 'PL', ''],
        ['Postivo', 'Poczta papierowa i hybrydowa', 'PL', ''],
        ['Anthropic', 'Asystent AI', 'USA', 'SCC / DPF — zweryfikować DPA'],
        ['Canva', 'Narzędzie graficzne (konta użytkowników)', 'Australia/USA', 'SCC'],
        ['Nozbe', 'Import zadań', 'PL/EOG', ''],
        ['MyDevil (hosting)', 'Hosting aplikacji i bazy', 'PL/EOG', ''],
    ];
}

// ───────────────────────── Wnioski osób ─────────────────────────

function rodoReqCtx(string $col): string { return 'rodo_requests.' . $col; }

function rodoReqEncode(array $d): array {
    $email = trim((string)($d['email'] ?? ''));
    $pesel = preg_replace('/\D/', '', (string)($d['pesel'] ?? ''));
    return [
        'wnioskodawca' => fcEncrypt(trim((string)($d['wnioskodawca'] ?? '')), rodoReqCtx('wnioskodawca')),
        'email' => fcEncrypt($email, rodoReqCtx('email')), 'email_idx' => fcBlind($email, rodoReqCtx('email'), 'email'),
        'pesel' => fcEncrypt($pesel, rodoReqCtx('pesel')), 'pesel_idx' => fcBlind($pesel, rodoReqCtx('pesel'), 'digits'),
        'opis' => fcEncrypt(trim((string)($d['opis'] ?? '')), rodoReqCtx('opis')),
    ];
}

function rodoReqDecode(array $r): array {
    foreach (['wnioskodawca', 'email', 'pesel', 'opis', 'odpowiedz'] as $c) $r[$c] = (string)fcDecrypt($r[$c] ?? null, rodoReqCtx($c));
    $r['dni'] = (int)floor((strtotime((string)$r['termin']) - strtotime(date('Y-m-d'))) / 86400);
    $r['po_terminie'] = $r['dni'] < 0 && !in_array($r['status'], ['zrealizowane', 'odmowa'], true);
    return $r;
}

function rodoReqCreate(array $d, ?int $uid): int {
    $wplyw = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['data_wplywu'] ?? '')) ? $d['data_wplywu'] : date('Y-m-d');
    $typ = isset(RODO_REQUEST_TYPES[$d['typ'] ?? '']) ? $d['typ'] : 'dostep';
    $id = db_insert('rodo_requests', rodoReqEncode($d) + [
        'typ' => $typ, 'kanal' => (string)($d['kanal'] ?? 'email'), 'data_wplywu' => $wplyw,
        'termin' => date('Y-m-d', strtotime($wplyw . ' +' . RODO_DEADLINE_DAYS . ' days')), 'created_by' => $uid,
    ]);
    rodoReqLog($id, 'utworzono', RODO_REQUEST_TYPES[$typ], $uid);
    audit_log('rodo.request.create', ['id' => $id, 'typ' => $typ]);
    return $id;
}

function rodoReqLog(int $id, string $event, string $details = '', ?int $uid = null): void {
    db_insert('rodo_request_log', ['request_id' => $id, 'zdarzenie' => $event, 'szczegoly' => $details, 'user_id' => $uid ?? (current_user()['id'] ?? null)]);
}

function rodoReqGet(int $id): ?array {
    $r = db_one("SELECT * FROM rodo_requests WHERE id = ?", [$id]);
    return $r ? rodoReqDecode($r) : null;
}

function rodoReqList(string $status = ''): array {
    $rows = $status !== '' && $status !== 'wszystkie'
        ? db_all("SELECT * FROM rodo_requests WHERE status = ? ORDER BY termin", [$status])
        : ($status === 'wszystkie' ? db_all("SELECT * FROM rodo_requests ORDER BY id DESC")
            : db_all("SELECT * FROM rodo_requests WHERE status NOT IN ('zrealizowane','odmowa') ORDER BY termin"));
    return array_map('rodoReqDecode', $rows);
}

// ───────────────────────── Mapa danych ─────────────────────────

/**
 * Gdzie szukać osoby. match: email / pesel / telefon → kolumny; export: kolumny pokazywane we wniosku o dostęp
 * (puste = wszystkie poza sekretami); erasable: kolumny czyszczone przy usunięciu (null = dane zatrzymane z mocy prawa);
 * names: kolumny zastępowane „[usunięto]”; why: powód zatrzymania.
 */
function rodoDataMap(): array {
    $legal = fn(string $zbior, string $table, array $match, string $why) => compact('zbior', 'table', 'match', 'why') + ['erasable' => null, 'names' => []];
    return [
        ['zbior' => 'Konta użytkowników', 'table' => 'users', 'match' => ['email' => ['email', 'alt_email', 'guardian_email'], 'pesel' => ['rpts_pesel'], 'telefon' => ['phone_number', 'alt_phone_number']],
            'erasable' => null, 'names' => [], 'why' => 'Konto wyłącza się i anonimizuje procedurą offboardingu (Rejestr byłych osób), nie tutaj'],
        $legal('Osoby (umowy)', 'persons', ['email' => ['email'], 'pesel' => ['pesel'], 'telefon' => ['telefon']], 'Obowiązki podatkowe i ubezpieczeniowe, roszczenia'),
        $legal('Umowy zlecenia', 'umowy_zlecenie', ['email' => ['email'], 'pesel' => ['pesel']], 'Podatki, ZUS (5 lat)'),
        $legal('Umowy o dzieło', 'umowy_dzielo', ['email' => ['email'], 'pesel' => ['pesel']], 'Podatki, ZUS (5 lat)'),
        $legal('Umowy o pracę', 'umowy_praca', ['email' => ['email'], 'pesel' => ['pesel']], 'Dokumentacja pracownicza (10 lat)'),
        $legal('Umowy wolontariackie', 'umowy_wolontariat', ['email' => ['email'], 'pesel' => ['pesel'], 'telefon' => ['telefon']], 'Dowód umowy i ochrony małoletnich'),
        $legal('Umowy usług', 'umowy_uslugi', ['email' => ['email'], 'telefon' => ['telefon']], 'Rachunkowość (5 lat)'),
        $legal('Onboarding wolontariuszy', 'onboarding_volunteers', ['email' => ['email'], 'pesel' => ['pesel'], 'telefon' => ['telefon']], 'Umowa wolontariacka, rozliczenia'),
        $legal('Darowizny', 'donations', ['pesel' => ['donor_pesel']], 'Rachunkowość i PIT (5 lat)'),
        $legal('TI — uczestnicy', 'k30_clients', ['email' => ['email'], 'pesel' => ['pesel'], 'telefon' => ['phone']], 'Dokumentacja szkoleń i rozliczeń'),
        $legal('TI — nabór', 'ti_nabor_submissions', ['email' => ['guardian_email'], 'telefon' => ['guardian_phone']], 'Dokumentacja naboru i szkoleń'),
        $legal('Licencje', 'licenses', ['email' => ['licensor_email', 'interested_email'], 'telefon' => ['licensor_phone', 'interested_phone']], 'Dokumentacja licencji'),
        $legal('Lista wypisów (opt-out)', 'crm_suppressions', ['email' => ['email']], 'Musi zostać, aby respektować sprzeciw i wypisanie'),
        ['zbior' => 'CRM — kontakty', 'table' => 'crm_contacts', 'match' => ['email' => ['email'], 'pesel' => ['pesel'], 'telefon' => ['telefon']],
            'erasable' => ['email', 'telefon', 'adres', 'pesel', 'data_urodzenia', 'notatka', 'stanowisko', 'addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'outlook_id'],
            'names' => ['imie_nazwisko', 'imie', 'nazwisko'], 'why' => ''],
        ['zbior' => 'CRM — osoby kontaktowe', 'table' => 'crm_contact_persons', 'match' => ['email' => ['email'], 'telefon' => ['telefon']],
            'erasable' => ['email', 'telefon'], 'names' => ['imie_nazwisko'], 'why' => ''],
        ['zbior' => 'Rekrutacja', 'table' => 'rekr_applications', 'match' => ['email' => ['email'], 'telefon' => ['telefon']],
            'erasable' => ['email', 'telefon', 'message'], 'names' => ['imie', 'nazwisko'], 'why' => ''],
        ['zbior' => 'Kandydaci na wolontariuszy', 'table' => 'volunteer_applications', 'match' => ['email' => ['candidate_email'], 'telefon' => ['candidate_phone']],
            'erasable' => ['candidate_email', 'candidate_phone', 'form_data', 'admin_notes'], 'names' => ['candidate_name'], 'why' => ''],
        ['zbior' => 'Wydarzenia — rejestracje', 'table' => 'ev_registrations', 'match' => ['email' => ['email'], 'telefon' => ['phone']],
            'erasable' => ['email', 'phone', 'reg_data', 'notes'], 'names' => ['first_name', 'last_name'], 'why' => ''],
        ['zbior' => 'Helpdesk', 'table' => 'helpdesk_tickets', 'match' => ['email' => ['requester_email'], 'telefon' => ['requester_phone']],
            'erasable' => ['requester_email', 'requester_phone'], 'names' => ['requester_name'], 'why' => ''],
        $legal('Logi logowań', 'login_log', ['email' => ['email']], 'Bezpieczeństwo systemu (12 mies.) — wygasają wg retencji'),
        $legal('Kolejka poczty', 'mail_queue', ['email' => ['to_email']], 'Dowód wysyłki — wygasa wg retencji'),
        $legal('Dziennik wysyłek', 'email_send_log', ['email' => ['to_email']], 'Dowód wysyłki — wygasa wg retencji'),
    ];
}

const RODO_SECRET_COLS = ['password', 'totp_secret', 'totp_backup_codes', 'login_code', 'mobile_pin', 'activation_token', 'sms_fallback_code', 'ika_email_otp', 'kdok_ika_hash', 'kdok_ikaks_hash', 'ika_setup_token', 'access_token'];

function rodoTableCols(string $table): array {
    static $c = [];
    if (isset($c[$table])) return $c[$table];
    try { return $c[$table] = array_column(db()->query("PRAGMA table_info(`{$table}`)")->fetchAll(), 'name'); } catch (\Throwable $e) { return $c[$table] = []; }
}

/** Warunek WHERE dla osoby: [sql, parametry] albo null, gdy tabela nie ma żadnej pasującej kolumny. */
function rodoMatchWhere(array $entry, array $subject): ?array {
    $cols = rodoTableCols($entry['table']);
    $parts = []; $p = [];
    $email = mb_strtolower(trim((string)($subject['email'] ?? '')));
    $pesel = preg_replace('/\D/', '', (string)($subject['pesel'] ?? ''));
    $tel = preg_replace('/\D/', '', (string)($subject['telefon'] ?? ''));
    $tel = strlen($tel) > 9 ? substr($tel, -9) : $tel;
    foreach ($entry['match']['email'] ?? [] as $c) if ($email !== '' && in_array($c, $cols, true)) { $parts[] = "LOWER(TRIM(`{$c}`)) = ?"; $p[] = $email; }
    foreach ($entry['match']['pesel'] ?? [] as $c) if (strlen($pesel) === 11 && in_array($c, $cols, true)) {
        [$ps, $pp] = fcMatchPesel($entry['table'], $c, $pesel);   // także gdy kolumna jest zaszyfrowana (field_crypto)
        $parts[] = $ps; array_push($p, ...$pp);
    }
    foreach ($entry['match']['telefon'] ?? [] as $c) if (strlen($tel) === 9 && in_array($c, $cols, true)) { $parts[] = "REPLACE(REPLACE(REPLACE(`{$c}`,' ',''),'-',''),'+','') LIKE ?"; $p[] = '%' . $tel; }
    return $parts ? [implode(' OR ', $parts), $p] : null;
}

/** Znajduje osobę we wszystkich zbiorach: [zbior, table, ids[], erasable, why, rows (bez sekretów)]. */
function rodoFindSubject(array $subject, bool $withRows = false): array {
    $out = [];
    foreach (rodoDataMap() as $e) {
        $w = rodoMatchWhere($e, $subject);
        if (!$w) continue;
        try { $rows = db_all("SELECT * FROM `{$e['table']}` WHERE {$w[0]} LIMIT 200", $w[1]); } catch (\Throwable $ex) { continue; }
        if (!$rows) continue;
        $out[] = [
            'zbior' => $e['zbior'], 'table' => $e['table'], 'ids' => array_column($rows, 'id'), 'count' => count($rows),
            'erasable' => $e['erasable'] !== null, 'why' => $e['why'],
            'rows' => $withRows ? array_map(fn($r) => rodoCleanRow($r), $rows) : [],
        ];
    }
    return $out;
}

function rodoCleanRow(array $r): array {
    foreach ($r as $k => $v) {
        if (in_array($k, RODO_SECRET_COLS, true)) unset($r[$k]);
        elseif (is_string($v) && fcIsEncrypted($v)) $r[$k] = fcDecrypt($v, '') ?? '[zaszyfrowane]';
    }
    return $r;
}

function rodoSubjectOf(array $req): array { return ['email' => $req['email'], 'pesel' => $req['pesel'], 'telefon' => '']; }

/** Eksport (art. 15 / 20): JSON ze wszystkimi znalezionymi wierszami. */
function rodoExport(array $req): array {
    return [
        'wygenerowano' => date('c'), 'wniosek' => $req['id'], 'osoba' => ['email' => $req['email'] !== '' ? $req['email'] : null, 'pesel' => $req['pesel'] !== '' ? fcMask($req['pesel'], 'digits') : null],
        'zbiory' => rodoFindSubject(rodoSubjectOf($req), true),
    ];
}

/** Anonimizacja erasable (art. 17). Zwraca [zbior => liczba wierszy]; tożsamość musi być zweryfikowana. */
function rodoErase(array $req, ?int $uid): array {
    if (empty($req['tozsamosc_zweryfikowana_at'])) throw new RuntimeException('Najpierw zweryfikuj tożsamość wnioskodawcy.');
    $done = [];
    $map = [];
    foreach (rodoDataMap() as $e) $map[$e['table']] = $e;
    db()->beginTransaction();
    try {
        foreach (rodoFindSubject(rodoSubjectOf($req)) as $f) {
            $e = $map[$f['table']];
            if ($e['erasable'] === null) continue;
            $cols = rodoTableCols($e['table']);
            $set = []; $p = [];
            foreach ($e['erasable'] as $c) if (in_array($c, $cols, true)) { $set[] = "`{$c}` = ''"; }
            foreach ($e['names'] as $c) if (in_array($c, $cols, true)) { $set[] = "`{$c}` = ?"; $p[] = '[usunięto]'; }
            if (!$set) continue;
            $ids = array_map('intval', $f['ids']);
            db_exec("UPDATE `{$e['table']}` SET " . implode(', ', $set) . " WHERE id IN (" . implode(',', $ids) . ")", $p);
            $done[$e['zbior']] = count($ids);
        }
        db()->commit();
    } catch (\Throwable $ex) { db()->rollBack(); throw $ex; }
    rodoReqLog((int)$req['id'], 'anonimizacja', json_encode($done, JSON_UNESCAPED_UNICODE), $uid);
    audit_log('rodo.request.erase', ['id' => $req['id'], 'zbiory' => array_keys($done)]);
    return $done;
}

// ───────────────────────── Naruszenia ─────────────────────────

function rodoBreachDecode(array $b): array {
    foreach (['opis', 'skutki'] as $c) $b[$c] = (string)fcDecrypt($b[$c] ?? null, 'rodo_breaches.' . $c);
    $b['uodo_do'] = date('Y-m-d H:i', strtotime((string)$b['wykryto_at'] . ' +' . RODO_BREACH_HOURS . ' hours'));
    $b['uodo_po_terminie'] = empty($b['zgloszono_uodo_at']) && !in_array($b['status'], ['bez_zgloszenia', 'zamkniete'], true) && time() > strtotime($b['uodo_do']);
    return $b;
}

function rodoBreachSave(array $d, ?int $id, ?int $uid): int {
    $row = [
        'wykryto_at' => str_replace('T', ' ', (string)($d['wykryto_at'] ?: date('Y-m-d H:i'))),
        'opis' => fcEncrypt(trim((string)($d['opis'] ?? '')), 'rodo_breaches.opis'),
        'skutki' => fcEncrypt(trim((string)($d['skutki'] ?? '')), 'rodo_breaches.skutki'),
        'kategorie_danych' => trim((string)($d['kategorie_danych'] ?? '')),
        'liczba_osob' => ($d['liczba_osob'] ?? '') === '' ? null : (int)$d['liczba_osob'],
        'srodki_zaradcze' => trim((string)($d['srodki_zaradcze'] ?? '')),
        'ryzyko' => in_array($d['ryzyko'] ?? '', ['nieoceniono', 'nieprawdopodobne', 'ryzyko', 'wysokie'], true) ? $d['ryzyko'] : 'nieoceniono',
        'zgloszono_uodo_at' => ($d['zgloszono_uodo_at'] ?? '') !== '' ? str_replace('T', ' ', $d['zgloszono_uodo_at']) : null,
        'powiadomiono_osoby_at' => ($d['powiadomiono_osoby_at'] ?? '') !== '' ? str_replace('T', ' ', $d['powiadomiono_osoby_at']) : null,
        'uzasadnienie_braku_zgloszenia' => trim((string)($d['uzasadnienie_braku_zgloszenia'] ?? '')),
        'status' => isset(RODO_BREACH_STATUS[$d['status'] ?? '']) ? $d['status'] : 'wykryte',
    ];
    if ($id) { db_update('rodo_breaches', $row, $id); audit_log('rodo.breach.update', ['id' => $id]); return $id; }
    $id = db_insert('rodo_breaches', $row + ['created_by' => $uid]);
    audit_log('rodo.breach.create', ['id' => $id]);
    return $id;
}

// ───────────────────────── Retencja ─────────────────────────

/** Raport: ile wierszy przekroczyło okres przechowywania (tylko raport — nic nie kasuje). */
function rodoRetentionReport(): array {
    $out = [];
    foreach (db_all("SELECT * FROM rodo_retention_rules WHERE aktywna = 1 ORDER BY zbior") as $r) {
        $cols = rodoTableCols($r['tabela']);
        $row = $r + ['kandydatow' => null, 'razem' => null, 'blad' => ''];
        if (!in_array($r['kolumna_daty'], $cols, true)) { $row['blad'] = 'brak tabeli lub kolumny daty'; $out[] = $row; continue; }
        $limit = date('Y-m-d H:i:s', strtotime("-{$r['miesiecy']} months"));
        $row['razem'] = (int)db_one("SELECT COUNT(*) c FROM `{$r['tabela']}`")['c'];
        $row['kandydatow'] = (int)db_one("SELECT COUNT(*) c FROM `{$r['tabela']}` WHERE `{$r['kolumna_daty']}` < ?", [$limit])['c'];
        $out[] = $row;
    }
    return $out;
}

// ───────────────────────── Dostęp: kto może przetwarzać dane ─────────────────────────

/** Klucz modułu z tabel uprawnień (role_permissions / user_permissions) → zbiory (nazwy z rejestru czynności). Mapowanie orientacyjne. */
const RODO_MODULE_ZBIORY = [
    'umowy'        => ['Umowy cywilnoprawne i kadry', 'Wolontariat — nabór, onboarding, RPTS'],
    'osoby'        => ['Umowy cywilnoprawne i kadry'],
    'pisma'        => ['Umowy cywilnoprawne i kadry'],
    'akceptacje'   => ['Umowy cywilnoprawne i kadry'],
    'rozwiazania'  => ['Umowy cywilnoprawne i kadry'],
    'zaswiadczenia' => ['Wolontariat — nabór, onboarding, RPTS'],
    'raporty'      => ['Umowy cywilnoprawne i kadry', 'Wolontariat — nabór, onboarding, RPTS'],
    'crm'          => ['CRM', 'Darowizny'],
    'crm_eksport'  => ['CRM'],
    'crm_import'   => ['CRM'],
    'crm_mailing'  => ['CRM'],
    'crm_ustawienia' => ['CRM'],
    'ezd'          => ['EZD — dokumentacja i korespondencja', 'Dokumentacja papierowa i archiwum zakładowe'],
    'poczta'       => ['Poczta, kalendarz i zadania'],
    'zadania'      => ['Obiegi i zadania', 'Poczta, kalendarz i zadania'],
    'karty30'      => ['TI — uczestnicy szkoleń', 'TI — kadra dydaktyczna', 'Konsultacje TI'],
    'wiadomosci'   => ['Wydarzenia, komunikaty, wsparcie OU'],
    'wydarzenia'   => ['Wydarzenia, komunikaty, wsparcie OU'],
    'zgloszenia'   => ['Helpdesk IT i kontrola IT'],
    'granty'       => [], 'dzialania' => [], 'org' => [], 'procedury' => [], 'slowniki' => [], 'srs' => [],
];

/** Specjalne uprawnienia poza rolami: flagi na koncie i tabele ról cząstkowych → zbiory. */
const RODO_SPECIAL_ZBIORY = [
    'helpdesk_operator'   => ['Operator helpdesk', ['Helpdesk IT i kontrola IT']],
    'rekrutacja_operator' => ['Operator naboru', ['Rekrutacja']],
    'k30_consultant'      => ['Konsultant TI', ['Konsultacje TI', 'TI — uczestnicy szkoleń']],
];

function rodoAccessQuery(string $sql, array $p = []): array { try { return db_all($sql, $p); } catch (\Throwable $e) { return []; } }

/**
 * Dane do raportu „Kto może przetwarzać dane”: role z uprawnieniami do modułów, osoby z uprawnieniami (rola, indywidualne,
 * cząstkowe) oraz zestawienie zbiór → role / osoby. Tylko odczyt — nic nie zmienia.
 * @return array{roles:array, users:array, by_zbior:array}
 */
function rodoAccessData(): array {
    $roles = [];
    foreach (rodoAccessQuery("SELECT * FROM roles ORDER BY sort_order, id") as $r) {
        $perm = [];
        foreach (rodoAccessQuery("SELECT module, can_read, can_write, can_delete FROM role_permissions WHERE role_id = ? AND (can_read+can_write+can_delete) > 0 ORDER BY module", [$r['id']]) as $m) {
            $perm[$m['module']] = ($m['can_read'] ? 'odczyt' : '') . ($m['can_write'] ? ', zapis' : '') . ($m['can_delete'] ? ', usuwanie' : '');
        }
        $roles[$r['name']] = ['name' => $r['name'], 'display' => $r['display_name'], 'opis' => $r['description'], 'is_admin' => $r['name'] === 'admin',
            'users' => (int)(rodoAccessQuery("SELECT COUNT(*) c FROM users WHERE role = ? AND COALESCE(is_active,1) = 1", [$r['name']])[0]['c'] ?? 0), 'modules' => $perm];
    }

    $users = [];
    foreach (rodoAccessQuery("SELECT id, name, first_name, last_name, role, COALESCE(is_active,1) active, helpdesk_operator, rekrutacja_operator, k30_consultant
                              FROM users ORDER BY role, last_name, name") as $u) {
        $label = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: (string)$u['name'];
        $users[(int)$u['id']] = ['id' => (int)$u['id'], 'label' => $label, 'role' => $u['role'], 'active' => (bool)$u['active'], 'direct' => [], 'special' => [], 'zbiory' => []];
        foreach (RODO_SPECIAL_ZBIORY as $flag => [$name, $zb]) if (!empty($u[$flag])) { $users[(int)$u['id']]['special'][] = $name; array_push($users[(int)$u['id']]['zbiory'], ...$zb); }
    }
    foreach (rodoAccessQuery("SELECT user_id, module, can_read, can_write, can_delete FROM user_permissions WHERE (can_read+can_write+can_delete) > 0") as $m) {
        if (!isset($users[(int)$m['user_id']])) continue;
        $users[(int)$m['user_id']]['direct'][] = $m['module'] . ' (' . ($m['can_read'] ? 'odczyt' : '') . ($m['can_write'] ? ', zapis' : '') . ($m['can_delete'] ? ', usuwanie' : '') . ')';
        array_push($users[(int)$m['user_id']]['zbiory'], ...(RODO_MODULE_ZBIORY[$m['module']] ?? []));
    }
    foreach ([['kdok_user_roles', 'KDOK', 'kdok'], ['edok_user_roles', 'EODoK', 'edok'], ['k30_ti_panel_roles', 'Panel TI', 'ti']] as [$tbl, $label, $k]) {
        foreach (rodoAccessQuery("SELECT user_id, role FROM {$tbl}") as $m) {
            if (!isset($users[(int)$m['user_id']])) continue;
            $users[(int)$m['user_id']]['special'][] = $label . ': ' . $m['role'];
            array_push($users[(int)$m['user_id']]['zbiory'], ...($k === 'ti' ? ['TI — uczestnicy szkoleń', 'TI — kadra dydaktyczna'] : ['Księgowość, EODoK, KDOK, faktury, płatności']));
        }
    }
    foreach (rodoAccessQuery("SELECT a.user_id, COUNT(*) n FROM poczta_mailbox_acl a GROUP BY a.user_id") as $m) {
        if (!isset($users[(int)$m['user_id']])) continue;
        $users[(int)$m['user_id']]['special'][] = 'Skrzynki pocztowe: ' . (int)$m['n'];
        $users[(int)$m['user_id']]['zbiory'][] = 'Poczta, kalendarz i zadania';
    }
    // zbiory z roli systemowej (wg modułów roli); administrator = wszystkie
    $allZbiory = array_column(rodoAccessQuery("SELECT zbior FROM rodo_activities ORDER BY zbior"), 'zbior');
    foreach ($users as &$u) {
        $role = $roles[$u['role']] ?? null;
        if ($role && $role['is_admin']) { $u['zbiory'] = $allZbiory; $u['all'] = true; continue; }
        foreach ($role['modules'] ?? [] as $mod => $_) array_push($u['zbiory'], ...(RODO_MODULE_ZBIORY[$mod] ?? []));
        $u['zbiory'] = array_values(array_unique($u['zbiory']));
        sort($u['zbiory']);
        $u['all'] = false;
    }
    unset($u);

    $by = [];
    foreach ($allZbiory as $z) $by[$z] = ['roles' => [], 'users' => []];
    foreach ($roles as $r) {
        if ($r['is_admin']) { foreach ($allZbiory as $z) $by[$z]['roles'][$r['display']] = 'pełny dostęp'; continue; }
        foreach ($r['modules'] as $mod => $lvl) foreach (RODO_MODULE_ZBIORY[$mod] ?? [] as $z) {
            if (isset($by[$z])) $by[$z]['roles'][$r['display']] = trim(($by[$z]['roles'][$r['display']] ?? '') . ' ' . $mod . ': ' . $lvl);
        }
    }
    foreach ($users as $u) if ($u['active'] && !$u['all']) foreach ($u['zbiory'] as $z) if (isset($by[$z])) $by[$z]['users'][$u['label']] = true;
    foreach ($by as &$b) { $b['users'] = array_keys($b['users']); sort($b['users']); }
    unset($b);

    return ['roles' => array_values($roles), 'users' => array_values($users), 'by_zbior' => $by];
}

// ───────────────────────── Podsumowanie (pulpit) ─────────────────────────

function rodoDashboard(): array {
    $today = date('Y-m-d');
    return [
        'wnioski_otwarte' => (int)db_one("SELECT COUNT(*) c FROM rodo_requests WHERE status NOT IN ('zrealizowane','odmowa')")['c'],
        'wnioski_po_terminie' => (int)db_one("SELECT COUNT(*) c FROM rodo_requests WHERE status NOT IN ('zrealizowane','odmowa') AND termin < ?", [$today])['c'],
        'naruszenia_otwarte' => (int)db_one("SELECT COUNT(*) c FROM rodo_breaches WHERE status NOT IN ('zamkniete','bez_zgloszenia')")['c'],
        'czynnosci_szkic' => (int)db_one("SELECT COUNT(*) c FROM rodo_activities WHERE status = 'szkic'")['c'],
        'dpia_wymagane' => (int)db_one("SELECT COUNT(*) c FROM rodo_activities WHERE dpia = 'wymagane'")['c'],
        'podmioty_bez_umowy' => (int)db_one("SELECT COUNT(*) c FROM rodo_processors WHERE umowa_status = 'brak'")['c'],
        'klucz_osobny' => fcHasDedicatedKey(),
    ];
}
