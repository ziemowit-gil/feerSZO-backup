<?php
/**
 * includes/szo_features.php — katalog funkcji SZO („jak to zrobić w systemie").
 *
 * To wiedza o SAMYM SYSTEMIE, nie o dokumentach organizacji: gdzie kliknąć,
 * jaka jest kolejność kroków, co się dzieje po złożeniu wniosku. Baza wiedzy
 * (procedury, uchwały, zasady) odpowiada „jak to działa u nas formalnie";
 * ten katalog odpowiada „gdzie to zrobić w SZO".
 *
 * Konsument: asystent AI (narzędzie `funkcje_systemu` w includes/asystent_ai.php).
 * Uzupełnieniem katalogu są dwa dynamiczne źródła, których nie trzymamy tutaj:
 *   • includes/modules_catalog.php — jakie moduły istnieją i czy są włączone,
 *   • includes/menu.php (menu_search_index) — realne linki dla uprawnień
 *     bieżącego użytkownika.
 *
 * Pozycja katalogu:
 *   id      — stabilny identyfikator (do cytowania w odpowiedzi),
 *   title   — nazwa czynności widziana przez użytkownika,
 *   path    — ścieżka względem APP_URL (pusta = funkcja bez własnego ekranu),
 *   module  — klucz settings gatujący funkcję ('' = zawsze dostępna),
 *   roles   — 'all' | 'viewer' (panel wolontariusza) | 'editor' | 'admin',
 *   kw      — słowa kluczowe (synonimy, potoczne nazwy) do wyszukiwania,
 *   desc    — co ta funkcja robi,
 *   steps   — kolejne kroki użytkownika (opcjonalne).
 */

/** Pełny katalog funkcji SZO. */
function szo_features(): array {
    return [
        // ── Panel wolontariusza / współpracownika ────────────────────────────
        [
            'id' => 'panel_pulpit', 'title' => 'Pulpit panelu — moja umowa',
            'path' => '/panel/index.php', 'module' => '', 'roles' => 'all',
            'kw' => 'panel pulpit moja umowa start strona główna przegląd numer umowy status',
            'desc' => 'Wejście do panelu: dane mojej umowy, jej status i termin, skróty do najczęstszych spraw oraz widżet „Moje zadania".',
            'steps' => ['Zaloguj się', 'Panel wolontariusza → Pulpit', 'Jeśli masz kilka umów — wybierz aktywną umowę z listy u góry'],
        ],
        [
            'id' => 'panel_wniosek', 'title' => 'Złożenie wniosku lub pisma do organizacji',
            'path' => '/panel/apply.php', 'module' => '', 'roles' => 'all',
            'kw' => 'wniosek pismo podanie zgłoszenie prośba wystąpienie załącznik wyślij wniosek formularz',
            'desc' => 'Formularz wniosku/pisma do organizacji. Rodzaje wniosków (i wymagane pola) definiuje administrator; do wniosku można dołączyć plik. Odpowiedź wraca do panelu, a status widać na liście „Moje wnioski".',
            'steps' => ['Panel → Wyślij wniosek', 'Wybierz rodzaj wniosku', 'Uzupełnij pola i dołącz załącznik (jeśli trzeba)', 'Wyślij — wniosek trafia do administracji', 'Status i odpowiedź sprawdzisz na tej samej stronie'],
        ],
        [
            'id' => 'panel_godziny', 'title' => 'Ewidencja godzin (karta miesięczna)',
            'path' => '/panel/timesheets.php', 'module' => 'timesheets_enabled', 'roles' => 'all',
            'kw' => 'godziny ewidencja czas pracy karta miesiąc rozliczenie godzin ile godzin wolontariat timesheet',
            'desc' => 'Miesięczna karta godzin: wpisujesz liczbę godzin i opis, wysyłasz do zatwierdzenia opiekunowi. Karta ma status (szkic → złożona → zatwierdzona/odrzucona). Godziny mogą być też liczone z czasu zapisanego w zadaniach.',
            'steps' => ['Panel → Ewidencja godzin', 'Wybierz miesiąc', 'Wpisz godziny i opis czynności', 'Zapisz jako szkic albo złóż do zatwierdzenia', 'Po zatwierdzeniu karta jest zablokowana do edycji'],
        ],
        [
            'id' => 'panel_zaswiadczenie', 'title' => 'Wniosek o zaświadczenie',
            'path' => '/panel/certificates.php', 'module' => 'certificates_enabled', 'roles' => 'all',
            'kw' => 'zaświadczenie potwierdzenie wolontariatu referencje dokument do szkoły uczelni pracy staż',
            'desc' => 'Wniosek o zaświadczenie o współpracy/wolontariacie. Podajesz cel zaświadczenia, organizacja wystawia dokument (papierowo lub z podpisem elektronicznym) i udostępnia go w panelu.',
            'steps' => ['Panel → Zaświadczenia', 'Kliknij „Nowy wniosek"', 'Wpisz cel (np. do uczelni)', 'Wyślij i czekaj na wystawienie', 'Gotowe zaświadczenie pobierzesz z tej samej listy'],
        ],
        [
            'id' => 'panel_zwroty', 'title' => 'Zwrot kosztów',
            'path' => '/panel/zwroty.php', 'module' => '', 'roles' => 'all',
            'kw' => 'zwrot kosztów wydatki delegacja bilet paragon faktura rachunek koszty przejazdu refundacja',
            'desc' => 'Zgłoszenie zwrotu poniesionych kosztów: kwota, opis, skan dowodu zakupu. Wniosek przechodzi weryfikację, a status widzisz w panelu.',
            'steps' => ['Panel → Zwrot kosztów', 'Dodaj nowy wniosek', 'Wpisz kwotę i opis, dołącz skan paragonu/faktury', 'Wyślij do weryfikacji'],
        ],
        [
            'id' => 'panel_rozwiazanie', 'title' => 'Rozwiązanie umowy',
            'path' => '/panel/terminations.php', 'module' => 'terminations_enabled', 'roles' => 'all',
            'kw' => 'rozwiązanie umowy rezygnacja wypowiedzenie zakończenie współpracy odejście rozstanie',
            'desc' => 'Wniosek o rozwiązanie umowy składa się WYŁĄCZNIE tą ścieżką (nie przez zwykły wniosek). Podajesz proponowaną datę i powód; organizacja wniosek rozpatruje i potwierdza rozwiązanie.',
            'steps' => ['Panel → Rozwiązanie umowy', 'Wybierz umowę i wpisz proponowaną datę zakończenia', 'Uzasadnij wniosek', 'Wyślij — decyzja wróci do panelu'],
        ],
        [
            'id' => 'panel_dyspozycyjnosc', 'title' => 'Dyspozycyjność i urlopy',
            'path' => '/panel/dyspozycyjnosc.php', 'module' => 'dyspozycyjnosc_enabled', 'roles' => 'all',
            'kw' => 'dyspozycyjność urlop wolne nieobecność grafik dostępność terminy kiedy mogę',
            'desc' => 'Deklarujesz swoje terminy dostępności i zgłaszasz urlop/nieobecność. Opiekun zatwierdza zgłoszenie.',
            'steps' => ['Panel → Dyspozycyjność i urlopy', 'Zaznacz terminy dostępności', 'Dla urlopu: dodaj zgłoszenie z datami', 'Wyślij do zatwierdzenia opiekunowi'],
        ],
        [
            'id' => 'panel_pisma', 'title' => 'Moje pisma',
            'path' => '/panel/letters.php', 'module' => 'letters_enabled', 'roles' => 'all',
            'kw' => 'pisma korespondencja moje pisma dokumenty do mnie listy przesyłki',
            'desc' => 'Pisma wystawione do mnie w kontekście umowy — do odczytu i pobrania.',
        ],
        [
            'id' => 'panel_wiadomosci', 'title' => 'Wiadomości z administracją',
            'path' => '/panel/messages.php', 'module' => 'messages_enabled', 'roles' => 'all',
            'kw' => 'wiadomości czat kontakt z biurem napisać do administracji pytanie do opiekuna',
            'desc' => 'Wątek wiadomości między mną a administracją, przypisany do mojej umowy.',
        ],
        [
            'id' => 'panel_helpdesk', 'title' => 'Zgłoszenie do Helpdesku IT',
            'path' => '/panel/helpdesk.php', 'module' => 'helpdesk_enabled', 'roles' => 'all',
            'kw' => 'helpdesk it awaria problem komputer laptop hasło konto nie działa zgłoszenie usterka wsparcie techniczne ticket',
            'desc' => 'Zgłoszenie problemu technicznego (sprzęt, konto, dostęp, aplikacje). Zgłoszenie dostaje numer i status; postęp widać w panelu, a zgłaszający dostaje powiadomienia.',
            'steps' => ['Panel → Helpdesk IT', 'Nowe zgłoszenie', 'Wybierz kategorię i opisz problem', 'Wyślij — otrzymasz numer zgłoszenia'],
        ],
        [
            'id' => 'panel_blad', 'title' => 'Zgłoszenie błędu systemu',
            'path' => '', 'module' => '', 'roles' => 'all',
            'kw' => 'zgłoś błąd bug usterka systemu coś nie działa sugestia poprawka feedback',
            'desc' => 'Przycisk „Zgłoś błąd" jest w nagłówku każdego modułu — otwiera krótki formularz, który tworzy zgłoszenie w Helpdesku razem z adresem strony, na której byłeś.',
            'steps' => ['Kliknij „Zgłoś błąd" w nagłówku strony', 'Opisz, co się stało', 'Wyślij — dostaniesz numer zgłoszenia'],
        ],
        [
            'id' => 'panel_zadania', 'title' => 'Moje zadania (tablica Kanban)',
            'path' => '/tasks/index.php', 'module' => 'tasks_enabled', 'roles' => 'all',
            'kw' => 'zadania kanban tablica todo obowiązki co mam zrobić termin deadline czas w zadaniu obszary',
            'desc' => 'Tablica zadań z obszarami pracy: zadania przypisane do mnie, terminy, priorytet (kropka), rejestrowanie czasu, potwierdzanie i odrzucanie zadań. Skrót do moich zadań jest też na pulpicie panelu.',
            'steps' => ['Menu → Zadania', 'Przełącznik obszarów w górnym pasku wybiera obszar pracy', 'Kliknij zadanie, aby zobaczyć szczegóły', 'Zadanie potwierdzasz (Z), odrzucasz (O) lub oznaczasz jako wykonane'],
        ],
        [
            'id' => 'panel_zgody', 'title' => 'Zgody i oświadczenia',
            'path' => '/panel/zgody.php', 'module' => '', 'roles' => 'all',
            'kw' => 'zgoda rodzica opiekuna przedstawiciela ustawowego oświadczenie małoletni niepełnoletni odnowienie zgody',
            'desc' => 'Zgody wymagane do współpracy — m.in. zgoda przedstawiciela ustawowego dla osoby małoletniej, odnawiana okresowo. System sam przypomina o odnowieniu mailem do opiekuna.',
        ],
        [
            'id' => 'panel_gdpr', 'title' => 'Oświadczenie o ochronie danych (IT)',
            'path' => '/panel/gdpr_statement.php', 'module' => '', 'roles' => 'all',
            'kw' => 'oświadczenie rodo ochrona danych it podpisanie oświadczenia dostęp do panelu zablokowany',
            'desc' => 'Oświadczenie o zasadach ochrony danych i bezpieczeństwa IT. Do momentu podpisania panel może być zablokowany — podpisujesz je raz, elektronicznie.',
        ],
        [
            'id' => 'panel_rodo', 'title' => 'Upoważnienie RODO do przetwarzania danych',
            'path' => '/panel/rodo.php', 'module' => '', 'roles' => 'all',
            'kw' => 'rodo upoważnienie przetwarzanie danych osobowych zakres upoważnienia',
            'desc' => 'Podgląd mojego upoważnienia do przetwarzania danych osobowych — widoczne, gdy organizacja wystawiła je do mojej umowy.',
        ],
        [
            'id' => 'panel_podpis_dok', 'title' => 'Podpisanie dokumentu',
            'path' => '/panel/sign_document.php', 'module' => 'doc_signing_enabled', 'roles' => 'all',
            'kw' => 'podpisz dokument podpis elektroniczny certyfikat x509 podpis kwalifikowany weryfikacja podpisu',
            'desc' => 'Wgranie dokumentu podpisanego własnym certyfikatem X.509 — system kryptograficznie weryfikuje podpis po wgraniu.',
        ],
        [
            'id' => 'panel_szkolenie', 'title' => 'Rezerwacja terminu szkolenia',
            'path' => '/panel/szkolenie.php', 'module' => 'tidycal_enabled', 'roles' => 'all',
            'kw' => 'szkolenie termin rezerwacja spotkanie wprowadzające umów się kalendarz tidycal',
            'desc' => 'Wybór wolnego terminu szkolenia/spotkania z kalendarza organizacji (TidyCal) i potwierdzenie rezerwacji mailem.',
        ],
        [
            'id' => 'panel_moodle', 'title' => 'Kursy e-learning (Moodle)',
            'path' => '/panel/moodle.php', 'module' => 'moodle_enabled', 'roles' => 'all',
            'kw' => 'kursy moodle e-learning szkolenia online nauka platforma kurs obowiązkowy',
            'desc' => 'Zapisy na kursy i wejście na platformę Moodle z konta SZO.',
        ],
        [
            'id' => 'panel_kalendarz', 'title' => 'Kalendarz organizacji',
            'path' => '/panel/calendar.php', 'module' => 'org_calendar_enabled', 'roles' => 'all',
            'kw' => 'kalendarz wydarzenia terminy spotkania organizacji ics harmonogram',
            'desc' => 'Lista wydarzeń organizacji pobierana z kanału ICS (Outlook/Google).',
        ],
        [
            'id' => 'panel_komunikaty', 'title' => 'Komunikaty organizacji',
            'path' => '/komunikaty/index.php', 'module' => '', 'roles' => 'all',
            'kw' => 'komunikaty ogłoszenia aktualności informacje tablica ogłoszeń newsy megafon',
            'desc' => 'Ogłoszenia organizacji — z kategoriami, przypięciem najważniejszych i oznaczaniem jako przeczytane. Licznik nieprzeczytanych widać w nagłówku panelu.',
        ],
        [
            'id' => 'panel_katalog', 'title' => 'Książka telefoniczna / katalog osób',
            'path' => '/directory/index.php', 'module' => '', 'roles' => 'all',
            'kw' => 'książka telefoniczna katalog kontakty telefon do kogo zadzwonić email współpracownika jednostki',
            'desc' => 'Wyszukiwanie osób i jednostek organizacji: telefon, e-mail, stanowisko, przypisanie do jednostki.',
        ],
        [
            'id' => 'panel_zasady', 'title' => 'Zasady organizacji i przewodnik po panelu',
            'path' => '/org_intro/index.php', 'module' => '', 'roles' => 'all',
            'kw' => 'zasady organizacji wprowadzenie onboarding przewodnik po panelu jak korzystać z systemu pierwsze kroki regulamin',
            'desc' => 'Zasady obowiązujące w organizacji (z potwierdzeniem zapoznania) oraz przewodnik po panelu — jak korzystać z systemu krok po kroku (/org_intro/panel_guide.php).',
        ],
        [
            'id' => 'panel_procedury', 'title' => 'Procedury i dokumenty organizacji',
            'path' => '/panel/procedures.php', 'module' => 'procedures_enabled', 'roles' => 'all',
            'kw' => 'procedury instrukcje regulaminy dokumenty organizacji wzory do pobrania polityka',
            'desc' => 'Procedury wewnętrzne (z wersjami i załącznikami) oraz dokumenty organizacji do pobrania (/panel/org_documents.php).',
        ],
        [
            'id' => 'panel_pomoc', 'title' => 'Pomoc i FAQ panelu',
            'path' => '/panel/pomoc.php', 'module' => '', 'roles' => 'all',
            'kw' => 'pomoc faq często zadawane pytania wsparcie nie wiem jak instrukcja',
            'desc' => 'Najczęstsze pytania o panel i kontakt do wsparcia.',
        ],

        // ── Konto, logowanie, bezpieczeństwo ────────────────────────────────
        [
            'id' => 'konto_ustawienia', 'title' => 'Ustawienia konta i zmiana hasła',
            'path' => '/panel/password.php', 'module' => '', 'roles' => 'all',
            'kw' => 'hasło zmiana hasła ustawienia konta numer telefonu dane kontaktowe profil',
            'desc' => 'Zmiana hasła do systemu, numer telefonu do powiadomień SMS i podstawowe ustawienia konta.',
        ],
        [
            'id' => 'konto_m365', 'title' => 'Konto Microsoft 365',
            'path' => '/panel/m365.php', 'module' => 'm365_enabled', 'roles' => 'all',
            'kw' => 'microsoft 365 office outlook teams onedrive konto służbowe logowanie microsoft reset hasła office',
            'desc' => 'Dane konta Microsoft 365 i reset hasła do niego (/panel/m365_password.php). Uwaga: adresy w domenie organizacji logują się do SZO WYŁĄCZNIE przez Microsoft 365 — nie hasłem lokalnym.',
        ],
        [
            'id' => 'konto_tozsamosc', 'title' => 'Moduł Tożsamość — konto, dostępy, PIN',
            'path' => '/tozsamosc/index.php', 'module' => '', 'roles' => 'all',
            'kw' => 'tożsamość uid numer konta dostępy pin dialer hasło entra id konto systemowe',
            'desc' => 'Karta mojej tożsamości: numer konta (UID), przypisane dostępy, hasło, PIN do aplikacji mobilnej „Dzwoń". Logowanie do modułu Tożsamość jest osobne od panelu.',
        ],
        [
            'id' => 'konto_2fa', 'title' => 'Dwuetapowe logowanie (2FA) i klucze sprzętowe',
            'path' => '/panel/2fa_settings.php', 'module' => '', 'roles' => 'all',
            'kw' => '2fa dwuetapowe uwierzytelnianie kod sms aplikacja authenticator klucz yubikey webauthn fido bezpieczeństwo logowania',
            'desc' => 'Włączenie drugiego składnika logowania: kod z aplikacji/SMS oraz klucze sprzętowe WebAuthn (/panel/webauthn.php). Dla ról administracyjnych klucz może być wymagany przy każdym logowaniu.',
        ],
        [
            'id' => 'konto_ika', 'title' => 'Kody IKA (autoryzacja operacji)',
            'path' => '/panel/set_my_codes.php', 'module' => 'ika_enabled', 'roles' => 'all',
            'kw' => 'ika kod autoryzacyjny potwierdzenie operacji kody jednorazowe indywidualny kod',
            'desc' => 'Indywidualny Kod Autoryzacyjny — potwierdza operacje krytyczne (np. wejście do aplikacji mobilnej). Tutaj ustawiasz/odnawiasz swoje kody.',
        ],
        [
            'id' => 'konto_sesje', 'title' => 'Sesje i bezpieczeństwo',
            'path' => '/panel/sessions.php', 'module' => '', 'roles' => 'all',
            'kw' => 'sesje urządzenia wyloguj zdalnie historia logowań podejrzane logowanie',
            'desc' => 'Lista moich aktywnych sesji i urządzeń — z możliwością zdalnego wylogowania.',
        ],
        [
            'id' => 'konto_motyw', 'title' => 'Motyw, kolor i kontrast panelu',
            'path' => '/panel/panel_color.php', 'module' => '', 'roles' => 'all',
            'kw' => 'motyw kolor ciemny tryb kontrast dostępność wygląd panelu czcionka',
            'desc' => 'Własny kolor panelu oraz tryb jasny/ciemny/wysoki kontrast — ustawienie per użytkownik.',
        ],
        [
            'id' => 'konto_paleta', 'title' => 'Szybka nawigacja (Ctrl+K) i launcher modułów',
            'path' => '', 'module' => '', 'roles' => 'all',
            'kw' => 'ctrl+k paleta poleceń szukaj w menu launcher waffle przełączanie modułów szybkie przejście nie mogę znaleźć',
            'desc' => 'Ctrl+K otwiera paletę poleceń — wpisz nazwę ekranu i przejdziesz do niego. Ikona kratki (waffle) w nagłówku przełącza między modułami; układ launchera wybierasz sam (lista/szuflada/overlay).',
        ],

        // ── Umowy i dokumenty (administracja) ───────────────────────────────
        [
            'id' => 'umowy_nowa', 'title' => 'Wystawienie nowej umowy',
            'path' => '/contracts/', 'module' => '', 'roles' => 'editor',
            'kw' => 'nowa umowa wystawienie umowy dodaj umowę wolontariat zlecenie dzieło usługi praca powierzenie kreator umowy',
            'desc' => 'Rejestr umów po typach (wolontariat, zlecenie, dzieło, usługi, praca, powierzenie, inne): dodanie umowy, wydruk, podpis, załączniki, aneksy i rozwiązania. Menu akcji na liście to dropdown „⋮".',
            'steps' => ['Menu → Umowy → wybierz typ umowy', 'Dodaj nową umowę i uzupełnij dane strony', 'Zapisz, wygeneruj dokument do podpisu', 'Po podpisaniu ustaw status i dołącz skan'],
        ],
        [
            'id' => 'umowy_aneks', 'title' => 'Aneks i wniosek o zmianę umowy',
            'path' => '/amendments/', 'module' => 'approvals_enabled', 'roles' => 'editor',
            'kw' => 'aneks zmiana umowy wniosek o zmianę akceptacja obieg dokumentów zatwierdzenie zmiany',
            'desc' => 'Wnioski o zmianę warunków umowy, ścieżki akceptacji i aneksy — z historią decyzji.',
        ],
        [
            'id' => 'umowy_rejestr', 'title' => 'Rejestr Umów (numeracja globalna)',
            'path' => '/reports/', 'module' => 'reports_enabled', 'roles' => 'editor',
            'kw' => 'rejestr umów numer rejestru wydruk rejestru numeracja globalna zestawienie umów',
            'desc' => 'Przeglądanie i wydruk Rejestru Umów według numeru rejestru — numeracja jest globalna dla wszystkich typów umów.',
        ],
        [
            'id' => 'umowy_pisma', 'title' => 'Pisma do umów',
            'path' => '/letters/', 'module' => 'letters_enabled', 'roles' => 'editor',
            'kw' => 'pismo do umowy wygeneruj pismo wysyłka listowna postivo nadanie korespondencja wychodząca',
            'desc' => 'Generowanie pism w kontekście umowy z pól rejestrowych, wydruk, koperty i nadanie u operatora.',
        ],
        [
            'id' => 'obiegi', 'title' => 'Obiegi (procesy micro-BPM)',
            'path' => '/obiegi/', 'module' => 'obiegi_enabled', 'roles' => 'editor',
            'kw' => 'obieg proces bpm akceptacja krok przekazanie sprawy workflow ścieżka zatwierdzania',
            'desc' => 'Uniwersalne procesy z krokami przypisanymi do ról — dokument sam przechodzi do kolejnej osoby po zatwierdzeniu kroku.',
        ],

        // ── Wirtualne biurko (EZD) ───────────────────────────────────────────
        [
            'id' => 'ezd_koszulka', 'title' => 'EZD — Koszulki (sprawy) i Segregatory',
            'path' => '/ezd/sprawy/index.php', 'module' => 'ezd_enabled', 'roles' => 'editor',
            'kw' => 'ezd koszulka sprawa segregator akta jrwa wykaz akt dekretacja znak sprawy prowadzenie sprawy',
            'desc' => 'Elektroniczne zarządzanie dokumentacją: Koszulka = sprawa, Segregator = jednostka aktowa z klasyfikacją JRWA. Do tego dekretacje, terminy i znak sprawy. Moduł bywa dostępny tylko z sieci firmowej (VPN).',
            'steps' => ['Wirtualne biurko → Koszulki', 'Utwórz koszulkę i wskaż klasę JRWA', 'Dołącz pisma z Dziennika podawczego lub Poczty EZD', 'Dekretuj na osobę prowadzącą', 'Zakończ sprawę i przekaż do Segregatora'],
        ],
        [
            'id' => 'ezd_rpw', 'title' => 'EZD — Dziennik podawczy i Książka nadawcza',
            'path' => '/ezd/rpw/index.php', 'module' => 'ezd_enabled', 'roles' => 'editor',
            'kw' => 'rpw dziennik podawczy wpływ korespondencja przychodząca książka nadawcza przesyłka wychodząca doręczenie fikcja doręczenia',
            'desc' => 'Rejestracja korespondencji wpływającej (RPW) i wychodzącej (książka nadawcza) — z terminami liczonymi od doręczenia.',
        ],
        [
            'id' => 'ezd_poczta', 'title' => 'EZD — Poczta i eDoręczenia',
            'path' => '/ezd/poczta/index.php', 'module' => 'ezd_enabled', 'roles' => 'editor',
            'kw' => 'poczta ezd email skrzynka import maila edoręczenia ade spinacz scal pdf szablony pism',
            'desc' => 'Skrzynki pocztowe w EZD: import e-maila do sprawy, eDoręczenia (adres ADE), Spinacz (scalanie plików do jednego PDF), szablony pism i korespondencja seryjna.',
        ],
        [
            'id' => 'ezd_archiwum', 'title' => 'EZD — Archiwum zakładowe',
            'path' => '/ezd/archiwum/index.php', 'module' => 'ezd_enabled', 'roles' => 'editor',
            'kw' => 'archiwum spis zdawczo-odbiorczy brakowanie kategoria archiwalna przekazanie akt',
            'desc' => 'Spisy zdawczo-odbiorcze i brakowanie dokumentacji według kategorii archiwalnej z JRWA.',
        ],

        // ── CRM, wydarzenia, komunikacja zewnętrzna ─────────────────────────
        [
            'id' => 'crm_kontakt', 'title' => 'CRM — kontakty, sprawy, oferty',
            'path' => '/crm/', 'module' => 'crm_enabled', 'roles' => 'editor',
            'kw' => 'crm kontakt beneficjent osoba kontaktowa sprawa oferta darowizna zgody segmenty ika kartoteka',
            'desc' => 'Kartoteka kontaktów i spraw CRM: osoby kontaktowe, usługi na rzecz organizacji, oferty (warianty A/B/C), zgody per cel przetwarzania, beneficjenci programów, załączniki i skrzynka CRM.',
            'steps' => ['Menu → CRM → Kontakty', 'Znajdź kontakt lub dodaj nowy', 'Prowadź sprawę i notatki na karcie kontaktu', 'Zgody i wysyłki filtrowane są po celu przetwarzania'],
        ],
        [
            'id' => 'crm_kampania', 'title' => 'CRM — newslettery i kampanie',
            'path' => '/crm/campaign/index.php', 'module' => 'crm_enabled', 'roles' => 'editor',
            'kw' => 'newsletter kampania mailing wysyłka masowa edytor blokowy mosaico statystyki otwarć klikniecia wypisz się',
            'desc' => 'Kampanie e-mail z edytorem blokowym, śledzeniem otwarć i klików, listą wypisanych i automatyzacjami zdarzenie → akcja.',
        ],
        [
            'id' => 'wydarzenia', 'title' => 'Wydarzenia — rejestracja i check-in',
            'path' => '/events/index.php', 'module' => 'events_enabled', 'roles' => 'editor',
            'kw' => 'wydarzenie webinar konferencja rejestracja uczestników bilet qr check-in zapisy formularz zgłoszeń embed',
            'desc' => 'Organizacja wydarzeń online i stacjonarnych: formularz rejestracji (także do wklejenia na stronę), bilety QR, check-in na wejściu i synchronizacja z CRM.',
        ],
        [
            'id' => 'helpdesk_operator', 'title' => 'Helpdesk IT — obsługa zgłoszeń',
            'path' => '/helpdesk/index.php', 'module' => 'helpdesk_enabled', 'roles' => 'editor',
            'kw' => 'helpdesk obsługa zgłoszeń ticket przypisanie eskalacja makra sla zamknięcie zgłoszenia śledzenie po tokenie',
            'desc' => 'Kolejka zgłoszeń IT: przypisanie, statusy, makra odpowiedzi, eskalacje i publiczne śledzenie zgłoszenia po tokenie.',
        ],

        // ── Finanse i rozliczenia ────────────────────────────────────────────
        [
            'id' => 'faktury', 'title' => 'Faktury',
            'path' => '/crm/invoices/index.php', 'module' => 'invoices_enabled', 'roles' => 'editor',
            'kw' => 'faktura wystawienie faktury fakturownia ksef pdf płatność numer faktury seria rozliczenie',
            'desc' => 'Rejestr faktur wystawianych z ofert CRM, rozliczeń i ręcznie; dokument powstaje w fakturownia.pl albo KSeF, SZO trzyma kontekst, numer, PDF i status płatności.',
        ],
        [
            'id' => 'darowizny', 'title' => 'Darowizny i dokumenty do PIT',
            'path' => '/ksiegowosc/', 'module' => 'donations_enabled', 'roles' => 'editor',
            'kw' => 'darowizna darczyńca wpłata pit odliczenie potwierdzenie roczne oświadczenie o przyjęciu darowizny rzeczowej',
            'desc' => 'Rejestr darowizn pieniężnych i rzeczowych. Uwaga: to DWA różne dokumenty — roczne potwierdzenie do PIT (pomocnicze) oraz oświadczenie o przyjęciu darowizny rzeczowej (wymagane ustawą).',
        ],
        [
            'id' => 'rachunki_zlecenie', 'title' => 'Rachunki do umów zlecenia',
            'path' => '/contracts/', 'module' => 'contract_zlecenie', 'roles' => 'editor',
            'kw' => 'rachunek do umowy zlecenia wystawienie rachunku podpisany skan token dla zleceniobiorcy rozliczenie zlecenia',
            'desc' => 'Rejestr rachunków do umów zlecenia: rachunek udostępniany zleceniobiorcy linkiem z tokenem, zwrot podpisanego skanu i przypomnienia o terminie.',
        ],
        [
            'id' => 'platnosci', 'title' => 'Płatności online (Stripe / PayU)',
            'path' => '', 'module' => '', 'roles' => 'admin',
            'kw' => 'stripe payu płatność online bramka opłata link do płatności webhook zapłata',
            'desc' => 'Dwie bramki płatnicze do opłat kursantów i darowizn; wpłata księguje się automatycznie po webhooku.',
        ],

        // ── Zarządzanie systemem (administracja) ────────────────────────────
        [
            'id' => 'admin_uzytkownicy', 'title' => 'Użytkownicy, role i dodatkowe moduły',
            'path' => '/admin/users.php', 'module' => '', 'roles' => 'admin',
            'kw' => 'użytkownik konto rola uprawnienia dodaj użytkownika zablokuj dostęp moduły per użytkownik przepisz użytkownika impersonacja',
            'desc' => 'Konta i role, nadawanie pojedynczych modułów ponad rolę, wcielanie się w użytkownika (z audytem), przepisanie danych między kontami i wymuszone wylogowanie.',
        ],
        [
            'id' => 'admin_moduly', 'title' => 'Włączanie i wyłączanie modułów',
            'path' => '/admin/modules_settings.php', 'module' => '', 'roles' => 'admin',
            'kw' => 'moduły włącz wyłącz funkcje systemu konfiguracja modułu ustawienia organizacji typy umów',
            'desc' => 'Przełączniki wszystkich modułów SZO, typów umów i integracji — plus skróty do ekranów konfiguracji poszczególnych modułów.',
        ],
        [
            'id' => 'admin_ai', 'title' => 'Ustawienia AI i asystent',
            'path' => '/admin/ai_settings.php', 'module' => '', 'roles' => 'admin',
            'kw' => 'ai anthropic claude klucz api model asystent chatbot publiczny link widżet asystenta',
            'desc' => 'Klucz API i model Anthropic, publiczny link do asystenta (/chatbot/{token}) wraz z kodem do wklejenia na stronę oraz przełącznik widżetu asystenta w modułach.',
        ],
        [
            'id' => 'admin_menu', 'title' => 'Widoczność menu i sekcji panelu',
            'path' => '/admin/menu_config.php', 'module' => '', 'roles' => 'admin',
            'kw' => 'menu ukryj pozycję sekcje panelu widoczność nawigacja porządek menu',
            'desc' => 'Ukrywanie pozycji menu administracyjnego i sekcji panelu wolontariusza bez wyłączania całego modułu.',
        ],
        [
            'id' => 'admin_kopie', 'title' => 'Kopie zapasowe i aktualizacje',
            'path' => '/admin/backups.php', 'module' => '', 'roles' => 'admin',
            'kw' => 'backup kopia zapasowa przywracanie aktualizacja systemu wersja migracje upgrade sharepoint',
            'desc' => 'Kopie przyrostowe bazy (co 4 h, z retencją) i aktualizacja systemu: pobranie zmian, migracje schematu, wersja aplikacji.',
        ],
        [
            'id' => 'admin_api', 'title' => 'REST API i klucze dostępu',
            'path' => '/admin/api_keys.php', 'module' => '', 'roles' => 'admin',
            'kw' => 'api rest klucz bearer token integracja zewnętrzna crm ezd karty30 zakres scope',
            'desc' => 'Klucze API z zakresami (m.in. crm:read/write, ezd:read) dla integracji zewnętrznych oraz audyt wywołań.',
        ],

        // ── Karty 30 / TI ────────────────────────────────────────────────────
        [
            'id' => 'ti_kursant', 'title' => 'TI — panel kursanta i opiekuna',
            'path' => '/karty30/ti/kursant/index.php', 'module' => '', 'roles' => 'all',
            'kw' => 'kursant lekcje plan zajęć oceny zadania testy rodzic opiekun konto kursanta nauka online rozliczenia ti',
            'desc' => 'Panel kursanta: moje lekcje, materiały i zadania, oceny, testy, wiadomości do prowadzącego, zmiana terminu lekcji i rozliczenia. Rodzic/opiekun ma własne, stałe konto.',
        ],
        [
            'id' => 'ti_dydaktyk', 'title' => 'TI — panel prowadzącego (dydaktyka)',
            'path' => '/karty30/ti/dydaktyk/index.php', 'module' => '', 'roles' => 'all',
            'kw' => 'prowadzący dydaktyk lekcja obecność oceny zadania dostępność urlop wynagrodzenie wypłata plan nauczania',
            'desc' => 'Panel prowadzącego: lekcje i obecność, oceny i zadania, dostępność i urlopy, zmiana terminu, plan nauczania oraz rozliczenie wynagrodzenia za lekcje. Logowanie prowadzącego jest osobne od panelu.',
        ],
    ];
}

/**
 * Wyszukaj funkcje po słowach kluczowych, z filtrem uprawnień i włączonych modułów.
 *
 * @param string $query  fraza użytkownika
 * @param string $role   'viewer' | 'editor' | 'admin' (widok, dla którego szukamy)
 * @param int    $limit  maks. liczba wyników
 * @return array lista pozycji katalogu (z kluczem 'score')
 */
function szo_features_search(string $query, string $role = 'viewer', int $limit = 6): array {
    $words = array_values(array_filter(
        preg_split('/\s+/u', mb_strtolower(trim($query))),
        fn($w) => mb_strlen($w) >= 3
    ));
    if (!$words) return [];

    $rank = ['viewer' => 1, 'editor' => 2, 'admin' => 3];
    $mine = $rank[$role] ?? 1;

    $out = [];
    foreach (szo_features() as $f) {
        // Uprawnienia: 'all' widzą wszyscy, 'viewer' też (panel ma każdy zalogowany).
        $need = $f['roles'] === 'all' ? 1 : ($rank[$f['roles']] ?? 1);
        if ($need > $mine) continue;
        // Moduł wyłączony → funkcji nie ma co polecać.
        if (($f['module'] ?? '') !== '' && function_exists('module_enabled') && !module_enabled($f['module'])) continue;

        $hay_title = mb_strtolower($f['title']);
        $hay_kw    = mb_strtolower($f['kw'] ?? '');
        $hay_desc  = mb_strtolower(($f['desc'] ?? '') . ' ' . implode(' ', $f['steps'] ?? []));
        $score = 0;
        foreach ($words as $w) {
            if (mb_strpos($hay_title, $w) !== false) $score += 4;
            if (mb_strpos($hay_kw,    $w) !== false) $score += 3;
            if (mb_strpos($hay_desc,  $w) !== false) $score += 1;
        }
        if ($score > 0) { $f['score'] = $score; $out[] = $f; }
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $limit);
}
