<?php
/**
 * includes/modules_catalog.php — jedno źródło prawdy o modułach SZO.
 *
 * Katalog (etykieta, ikona, opis, ekran konfiguracji) był dotąd zaszyty
 * w admin/modules_settings.php. Wyciągnięty tutaj, bo korzysta z niego także
 * asystent AI — musi wiedzieć, jakie funkcje ma system i czy są włączone.
 *
 * Klucz każdej pozycji = klucz w tabeli settings (`*_enabled`, `contract_*`).
 */

/** Katalog modułów w grupach tematycznych: grupa => [klucz => [label,icon,desc,config?]]. */
function modules_catalog(): array {
    return [
    'Moduły systemu' => [
        'crm_enabled'          => ['label' => 'CRM',                           'icon' => 'bi-people-fill',           'desc' => 'Zarządzanie kontaktami, sprawami CRM, notatkami, pismami i powiązaniami z EZD.',     'config' => 'crm_access.php'],
        'ezd_enabled'          => ['label' => 'Wirtualne biurko',                'icon' => 'bi-building-gear',         'desc' => 'Elektroniczne zarządzanie dokumentacją — Teczki, Sprawy, Pisma, Umowy, Dekretacje.'],
        'org_enabled'          => ['label' => 'Struktura Organizacyjna',       'icon' => 'bi-diagram-3',             'desc' => 'Hierarchia jednostek, stanowiska, przypisania osobowe, zastępstwa i historia zmian.'],
        'procedures_enabled'   => ['label' => 'Procedury wewnętrzne',          'icon' => 'bi-journal-bookmark-fill', 'desc' => 'Rejestr procedur z wersjonowaniem, powiązaniami i załącznikami.'],
        'doc_signing_enabled'  => ['label' => 'Podpisz dokument',              'icon' => 'bi-pen',                   'desc' => 'Wgrywanie dokumentów do podpisania własnym certyfikatem X.509 poza systemem, z kryptograficzną weryfikacją podpisu po wgraniu.'],
        'tasks_enabled'        => ['label' => 'Tablica zadań (Kanban)',        'icon' => 'bi-kanban',           'desc' => 'Zarządzanie zadaniami — widoczne dla wszystkich zalogowanych użytkowników.'],
        'messages_enabled'     => ['label' => 'System wiadomości',             'icon' => 'bi-chat-dots',        'desc' => 'Komunikacja między administratorami a wolontariuszami i wykonawcami.',          'config' => 'msg_settings.php'],
        'onboarding_enabled'   => ['label' => 'Zgłoszenia wolontariuszy',      'icon' => 'bi-person-plus',      'desc' => 'Formularz samodzielnego zgłoszenia i kreator onboardingu dla nowych wolontariuszy.', 'config' => 'onboarding_settings.php'],
        'reports_enabled'      => ['label' => 'Zestawienia i raporty',         'icon' => 'bi-bar-chart-line',   'desc' => 'Statystyki, wykresy i eksport danych umów.'],
        'approvals_enabled'    => ['label' => 'Obieg dokumentów (akceptacje)', 'icon' => 'bi-check2-square',    'desc' => 'Wnioski o zmiany umów, ścieżki akceptacji, aneksy.',                              'config' => 'approval_workflows.php'],
        'obiegi_enabled'       => ['label' => 'Obiegi (micro-BPM)',            'icon' => 'bi-diagram-2',        'desc' => 'Uniwersalne procesy z automatycznym przekazywaniem i zatwierdzaniem — definiowane kroki przypisane do ról.', 'config' => 'obiegi.php'],
        'terminations_enabled' => ['label' => 'Rozwiązania umów',              'icon' => 'bi-file-earmark-x',   'desc' => 'Wnioski o rozwiązanie umowy składane przez wolontariuszy i wykonawców.'],
        'dyspozycyjnosc_enabled' => ['label' => 'Dyspozycyjność i urlopy',      'icon' => 'bi-calendar-heart',   'desc' => 'Wolontariusz sam określa terminy dostępności i zgłasza urlopy; opiekun formalnie je zatwierdza.'],
        'certificates_enabled' => ['label' => 'Zaświadczenia',                 'icon' => 'bi-award',            'desc' => 'Generowanie i wydawanie zaświadczeń dla wolontariuszy i wykonawców.',             'config' => 'certificates.php'],
        'invoices_enabled'     => ['label' => 'Faktury',                       'icon' => 'bi-receipt',          'desc' => 'Rejestr faktur wystawianych z ofert CRM, rozliczeń TI i ręcznie. Dokument księgowy powstaje w fakturownia.pl albo KSeF; SZO trzyma kontekst, numer, PDF i status płatności. Faktury z TI mają własną serię TI/nr/mm/rok i załącznik z rozliczeniem środków.', 'config' => 'invoices_settings.php'],
        'donations_enabled'    => ['label' => 'Darowizny',                     'icon' => 'bi-gift',             'desc' => 'Rejestr darowizn pieniężnych i rzeczowych z dokumentami dla darczyńców: roczne potwierdzenie do PIT (dokument pomocniczy — podstawą odliczenia jest dowód wpłaty na rachunek) oraz oświadczenie o przyjęciu darowizny rzeczowej, które ustawa wymaga wprost (art. 26 ust. 7 ustawy o PIT).'],
        'letters_enabled'      => ['label' => 'Pisma',                         'icon' => 'bi-envelope-paper',   'desc' => 'Pisma i korespondencja generowana w kontekście umów.'],
        'moodle_enabled'          => ['label' => 'Moodle — e-learning',           'icon' => 'bi-mortarboard',        'desc' => 'Integracja z platformą Moodle — zapisy na kursy, synchronizacja użytkowników.',          'config' => 'moodle.php'],
        'correspondence_enabled'  => ['label' => 'Korespondencja',               'icon' => 'bi-mailbox2',           'desc' => 'Rejestr korespondencji przychodzącej i wychodzącej — nadawcy, odbiorcy, statusy, załączniki.'],
        'wsparcie_ou_enabled'     => ['label' => 'Wsparcie zewnętrzne OU',        'icon' => 'bi-building-add',       'desc' => 'Ewidencja godzin wsparcia świadczonego przez podmioty zewnętrzne (wyszukiwane po KRS), w rozbiciu na miesiące, z zatwierdzaniem/odrzucaniem.'],
        'resolutions_enabled'     => ['label' => 'Uchwały i Zarządzenia',        'icon' => 'bi-hammer',             'desc' => 'Rejestr uchwał, zarządzeń i decyzji z automatyczną numeracją, treścią i skanami.'],
        'events_enabled'          => ['label' => 'Moduł Wydarzeń',               'icon' => 'bi-calendar-event',     'desc' => 'Organizacja wydarzeń online (webinary) i stacjonarnych — rejestracja uczestników, bilety QR, check-in, integracja CRM.', 'config' => 'events_settings.php'],
        'poczta_enabled'          => ['label' => 'Moduł Poczty',                 'icon' => 'bi-envelope',           'desc' => 'Automatyczne skanowanie wybranych skrzynek Microsoft 365 w tle i wiązanie e-maili z osią czasu kontaktów CRM.', 'config' => 'poczta_settings.php'],
        'org_calendar_enabled'    => ['label' => 'Kalendarz organizacji (ICS)',  'icon' => 'bi-calendar3',          'desc' => 'Kalendarz organizacji pobierany z kanału ICS (Outlook 365, Google Calendar i inne) — widoczny w panelu wolontariusza jako lista wydarzeń.', 'config' => 'org_calendar.php'],
        'byli_enabled'            => ['label' => 'Rejestr byłych osób',           'icon' => 'bi-person-dash',        'desc' => 'Rejestr byłych współpracowników — imię, nazwisko, miasto, okres współpracy, powód odejścia i uwagi (z opcją zastrzeżenia danych dla zarządu).'],
        'pelnomocnictwa_enabled'  => ['label' => 'Rejestr pełnomocnictw',         'icon' => 'bi-person-badge',       'desc' => 'Samodzielny rejestr pełnomocnictw i upoważnień (mocodawca, pełnomocnik, zakres, ważność) — inspirowany klasą JRWA 013 z modułu EZD, prowadzony niezależnie od Wirtualnego biurka.'],
        'dostepnosc_ngo_enabled'  => ['label' => 'Dostępność NGO',                 'icon' => 'bi-universal-access',        'desc' => 'Zgłoszenia asysty na wydarzeniach, specjalne potrzeby wolontariuszy (PJM, wózek, pętla indukcyjna, neuroróżnorodność, dostępność komunikacyjno-informacyjna).'],
        'projekty_enabled'        => ['label' => 'Dedykowane dla projektów',       'icon' => 'bi-folder-symlink',          'desc' => 'Narzędzia dedykowane konkretnym projektom: karty doradztwa ADNGO, formularze zewnętrzne per-projekt.'],
    ],
    'Integracje i bezpieczeństwo' => [
        'm365_enabled'         => ['label' => 'Microsoft 365 / Azure AD',     'icon' => 'bi-microsoft',        'desc' => 'Logowanie OAuth, provisioning kont M365, synchronizacja użytkowników i grup.',    'config' => 'm365_settings.php'],
        'sms_enabled'          => ['label' => 'Bramka SMS',                   'icon' => 'bi-phone',            'desc' => 'Wysyłka powiadomień SMS (kody fallback, przypomnienia, alerty).',                 'config' => 'sms_settings.php'],
        'ika_enabled'          => ['label' => 'Kody IKA (autoryzacja)',       'icon' => 'bi-shield-lock',      'desc' => 'Indywidualny Kod Autoryzacyjny — dwuetapowe potwierdzenie operacji krytycznych.', 'config' => 'manage_cpc.php'],
        'webauthn_enabled'     => ['label' => 'Klucze sprzętowe (WebAuthn)', 'icon' => 'bi-usb-plug',         'desc' => 'Uwierzytelnianie kluczami FIDO2/WebAuthn (YubiKey i inne tokeny fizyczne).',       'config' => 'login_settings.php'],
        'mobile_pin_enabled'   => ['label' => 'Logowanie PIN-em (dialer)',   'icon' => 'bi-telephone-outbound', 'desc' => 'Szybkie wejście do mobilnej aplikacji „Dzwoń" numerem konta (UID) i PIN-em, zamiast Microsoft 365. Sesja otwiera WYŁĄCZNIE dialer i nadal wymaga kodu IKA. Nieaktywne, dopóki użytkownik sam nie ustawi PIN-u w module Tożsamość; blokada pojedynczego konta — w karcie użytkownika.'],
        'vpn_enabled'          => ['label' => 'VPN — dostęp do sieci',       'icon' => 'bi-shield-lock',      'desc' => 'Wnioski o dostęp do VPN, zatwierdzanie i wydawanie konfiguracji, ewidencja dostępów. Blokadę „moduł tylko przez VPN" (np. EZD) włączasz w ustawieniach organizacji.', 'config' => 'vpn.php'],
        'cloudflare_enabled'   => ['label' => 'Cloudflare DNS',              'icon' => 'bi-globe2',           'desc' => 'Wizualne zarządzanie rekordami DNS (A/AAAA/CNAME/TXT/MX/NS/SRV/CAA) stref widocznych dla skonfigurowanego tokenu API Cloudflare.', 'config' => 'cloudflare_settings.php'],
        'apaczka_enabled'      => ['label' => 'Apaczka — przesyłki',         'icon' => 'bi-box-seam',         'desc' => 'Integracja z platformą Apaczka do zamawiania i śledzenia przesyłek kurierskich.',  'config' => 'apaczka_settings.php'],
        'furgonetka_enabled'   => ['label' => 'Furgonetka — przesyłki',      'icon' => 'bi-truck',            'desc' => 'Integracja z platformą Furgonetka do zamawiania przesyłek i etykiet.',             'config' => 'furgonetka_settings.php'],
        'ceidg_enabled'        => ['label' => 'CEIDG — weryfikacja firm',     'icon' => 'bi-building-check',   'desc' => 'Automatyczna weryfikacja danych wykonawców w rejestrze CEIDG (GUS).',              'config' => 'ceidg_settings.php'],
        'tidycal_enabled'      => ['label' => 'TidyCal — rezerwacja szkoleń', 'icon' => 'bi-calendar2-check',  'desc' => 'Rezerwacja terminów szkoleń z panelu i portalu przez kalendarz TidyCal (wolne terminy, potwierdzenia).', 'config' => 'tidycal_settings.php'],
    ],
    'Typy umów' => [
        'contract_wolontariat' => ['label' => 'Umowa wolontariacka',           'icon' => 'bi-heart',            'desc' => 'Porozumienia wolontariackie (ustawa o działalności pożytku publicznego i o wolontariacie).'],
        'contract_zlecenie'    => ['label' => 'Umowa zlecenie',                'icon' => 'bi-person-lines-fill','desc' => 'Umowy zlecenie z osobami fizycznymi — CIT/PIT, ZUS.'],
        'contract_uslugi'      => ['label' => 'Umowa o świadczenie usług',     'icon' => 'bi-briefcase',        'desc' => 'Umowy z firmami i przedsiębiorcami prowadzącymi działalność.'],
        'contract_dzielo'      => ['label' => 'Umowa o dzieło',                'icon' => 'bi-palette',          'desc' => 'Umowy o dzieło — jednorazowe projekty twórcze lub techniczne.'],
        'contract_praca'       => ['label' => 'Umowa o pracę',                 'icon' => 'bi-building',         'desc' => 'Umowy o pracę i dokumenty kadrowe (Kodeks pracy).'],
        'contract_powierzenie' => ['label' => 'Umowa powierzenia zadania publicznego', 'icon' => 'bi-bank',     'desc' => 'Umowy o powierzenie / wsparcie realizacji zadania publicznego z dotacją (ustawa o działalności pożytku publicznego, art. 16).'],
        'contract_inne'        => ['label' => 'Inna umowa',                    'icon' => 'bi-file-text',        'desc' => 'Niestandardowe umowy i dokumenty nieujęte w pozostałych typach.'],
    ],
    ];
}

/** Spłaszczony katalog: klucz => pozycja + 'key' i 'group'. */
function modules_catalog_flat(): array {
    $out = [];
    foreach (modules_catalog() as $group => $items) {
        foreach ($items as $key => $it) {
            $it['key']   = $key;
            $it['group'] = $group;
            $out[$key]   = $it;
        }
    }
    return $out;
}
