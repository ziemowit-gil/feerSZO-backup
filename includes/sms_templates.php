<?php
/**
 * includes/sms_templates.php — Rejestr edytowalnych szablonów SMS (moduł TI/Dydaktyka).
 *
 * Wzorowane 1:1 na includes/email_templates.php (rejestr + nadpisanie w bazie +
 * włącz/wyłącz), ale dla SMS: brak HTML, czysty tekst, liczy się długość
 * (limit segmentu SMS = 160 znaków bez polskich diakrytyków / 70 z nimi).
 *
 * Świadomie NIE obejmuje SMS-ów dostarczających dane logowania/hasła/kody
 * jednorazowe (np. hasła do panelu, kody weryfikacyjne telefonu, konta MS365)
 * — to komunikaty bezpieczeństwa, nie "powiadomienia", i nie powinny być
 * edytowalne przez panel (ryzyko przypadkowego usunięcia {{placeholdera}}
 * i wysłania hasła bez treści albo złamania formatu kodu).
 */

function sms_tpl_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS sms_templates (
            key_       TEXT PRIMARY KEY,
            message    TEXT NOT NULL DEFAULT '',
            enabled    INTEGER NOT NULL DEFAULT 1,
            updated_at DATETIME,
            updated_by INTEGER
        )");
    } catch (\Throwable $e) {}
}

/**
 * Rejestr znanych SMS-ów systemowych modułu TI.
 * Każdy wpis: label, group, icon, auto (czy wysyłka automatyczna — enabled=0
 * faktycznie wstrzymuje), description, message (domyślna treść z {{zmienną}}),
 * vars => [name => [label, sample]].
 */
function sms_tpl_registry(): array
{
    return [
        'ti_lesson_added' => [
            'label'       => 'Nowa lekcja dodana',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-calendar-plus',
            'auto'        => true,
            'description' => 'Wysyłane do wszystkich aktywnych uczestników kursu, gdy prowadzący/admin doda nową lekcję (course.php).',
            'message'     => 'Nowe zajecia: {{course}} - {{when}}. Szczegoly w panelu kursanta.',
            'vars'        => [
                'course' => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'when'   => ['label' => 'Data i godzina', 'sample' => '12.09.2026 o 10:00'],
            ],
        ],
        'ti_reschedule_notify' => [
            'label'       => 'Zmiana terminu pojedynczej lekcji',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-calendar2-range',
            'auto'        => true,
            'description' => 'Wysyłane do uczestników kursu, gdy prowadzący zmieni termin jednej lekcji i zaznaczy "powiadom SMS" (panel dydaktyka).',
            'message'     => 'Zmiana terminu zajec: {{course}} -> {{when}}. Szczegoly w panelu kursanta.',
            'vars'        => [
                'course' => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'when'   => ['label' => 'Nowy termin', 'sample' => '12.09.2026 o 10:00'],
            ],
        ],
        'ti_absence_notice' => [
            'label'       => 'Nieobecność prowadzącego — zapowiedź',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-person-x',
            'auto'        => true,
            'description' => 'Wysyłane do uczestników kursów, w których przypadają zajęcia kolidujące z nieobecnością prowadzącego (urlop, L4, nieobecność w godzinach) — gdy kierownik zapisze nieobecność i zaznaczy powiadomienie SMS albo zatwierdzi zgłoszenie prowadzącego. Zajęcia mogą zostać odwołane lub przełożone.',
            'message'     => 'Nieobecnosc prowadzacego {{instructor}}: {{when}}. Zajecia {{course}} moga zostac odwolane lub przelozone. Szczegoly w panelu kursanta.',
            'vars'        => [
                'instructor' => ['label' => 'Prowadzący', 'sample' => 'Jan Kowalski'],
                'when'       => ['label' => 'Termin nieobecności (z godzinami, gdy godzinowa)', 'sample' => '12.09.2026, 10:00–12:00'],
                'course'     => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
            ],
        ],
        'ti_absence_urgent' => [
            'label'       => 'Pilna nieobecność prowadzącego — do kierownika',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-exclamation-triangle',
            'auto'        => true,
            'description' => 'Wysyłane do kierownika i zastępcy (numer z konta), gdy nieobecność prowadzącego zaczyna się dziś lub jutro i ma kolidujące zajęcia do rozstrzygnięcia — zgłoszenie prowadzącego albo wpis kierownika.',
            'message'     => 'PILNE: {{instructor}} niedostepny/a {{when}}. Zajec do rozstrzygniecia: {{n}}. Zgloszenia trenerow w panelu dydaktyka.',
            'vars'        => [
                'instructor' => ['label' => 'Prowadzący', 'sample' => 'Jan Kowalski'],
                'when'       => ['label' => 'Termin nieobecności (z godzinami, gdy godzinowa)', 'sample' => '12.09.2026, 10:00-12:00'],
                'n'          => ['label' => 'Liczba kolidujących zajęć', 'sample' => '3'],
            ],
        ],
        'ti_absence_cancelled' => [
            'label'       => 'Odwołane zajęcia — nieobecność prowadzącego',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-calendar-x',
            'auto'        => true,
            'description' => 'Wysyłane do uczestników kursu, gdy zajęcia zostaną odwołane z powodu nieobecności prowadzącego (odwołanie z listy kolidujących zajęć albo z rozstrzygania zgłoszenia). Jeden SMS na kurs — z listą terminów (do 3, reszta „+N”).',
            'message'     => 'Odwolane zajecia: {{course}} - {{when}} (nieobecnosc prowadzacego).{{contact}} Szczegoly w panelu kursanta.',
            'vars'        => [
                'course'  => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'when'    => ['label' => 'Terminy odwołanych zajęć', 'sample' => '12.09 10:00, 13.09 10:00'],
                'contact' => ['label' => 'Prośba o kontakt w sprawie nowego terminu (z wiodącą spacją albo puste, gdy kierownik jej nie zaznaczył)', 'sample' => ' Prosimy o kontakt w sprawie nowego terminu.'],
            ],
        ],
        'ti_substitute_notify' => [
            'label'       => 'Zastępstwo na zajęciach',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-person-lines-fill',
            'auto'        => true,
            'description' => 'Wysyłane do uczestników kursu, gdy zajęcia w niezmienionym terminie poprowadzi inny prowadzący (rozstrzyganie nieobecności w Zgłoszeniach trenerów).',
            'message'     => 'Zmiana prowadzacego: {{course}} - {{when}}. Zajecia poprowadzi {{instructor}}.',
            'vars'        => [
                'course'     => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'when'       => ['label' => 'Data i godzina', 'sample' => '12.09.2026 o 10:00'],
                'instructor' => ['label' => 'Zastępca', 'sample' => 'Anna Nowak'],
            ],
        ],
        'ti_lesson_reminder' => [
            'label'       => 'Przypomnienie o jutrzejszej lekcji',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-alarm',
            'auto'        => true,
            'description' => 'Cron (cron/ti_lesson_reminders.php, raz dziennie rano) — przypomnienie dla kursantów o zajęciach zaplanowanych na jutro.',
            'message'     => 'Przypomnienie: masz zajecia jutro{{when}} - {{course}}.',
            'vars'        => [
                'course' => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'when'   => ['label' => 'Godzina (z wiodącym " o HH:MM" albo puste)', 'sample' => ' o 10:00'],
            ],
        ],
        'ti_low_attendance' => [
            'label'       => 'Niska frekwencja',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-graph-down',
            'auto'        => true,
            'description' => 'Wysyłane do kursanta (i opiekuna małoletniego), gdy frekwencja na kursie spadnie poniżej progu ustawionego w systemie.',
            'message'     => 'Niska frekwencja: {{course}} - {{pct}}% (prog {{threshold}}%). Prosimy o regularna obecnosc.',
            'vars'        => [
                'course'    => ['label' => 'Nazwa kursu', 'sample' => 'Angielski S1'],
                'pct'       => ['label' => 'Aktualna frekwencja (%)', 'sample' => '55'],
                'threshold' => ['label' => 'Próg ostrzeżenia (%)', 'sample' => '70'],
            ],
        ],
        'ti_new_message' => [
            'label'       => 'Nowa wiadomość w panelu kursanta',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-envelope',
            'auto'        => true,
            'description' => 'Wysyłane do kursanta, gdy dostanie nową wiadomość w panelu (zakładka Wiadomości), jeśli ma włączone powiadomienia SMS.',
            'message'     => '{{org}}: nowa wiadomosc w panelu kursanta. Zaloguj sie, aby przeczytac.',
            'vars'        => [
                'org' => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        // ── Rozliczenie cykliczne licencji (kwota, forma, dzień miesiąca, rabat) ─────────────────────────
        'ti_license_billing_start' => [
            'label'       => 'Licencja: start rozliczenia — w dniu przypisania (do kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-cash-coin',
            'auto'        => true,
            'description' => 'W dniu przypisania licencji z rozliczeniem miesięcznym: kwota, forma rozliczenia, dzień miesiąca i informacja o rabacie. Komunikacja rozliczeniowa — nie wymaga zgody na SMS od dydaktyki.',
            'message'     => 'Przypisano Ci licencje {{software}}. Oplata {{amount}} zl miesiecznie{{discount}} (forma: {{form}}), rozliczana {{day}}. dnia miesiaca. Pierwsza oplata: dzis.',
            'vars'        => [
                'software' => ['label' => 'Nazwa oprogramowania', 'sample' => 'Adobe Creative Cloud'],
                'amount'   => ['label' => 'Kwota miesięczna do zapłaty (po rabacie)', 'sample' => '49,00'],
                'discount' => ['label' => 'Rabat (z wiodącą spacją, np. „ po rabacie 20% (cena 61,25 zl)”, albo puste)', 'sample' => ' po rabacie 20% (cena 61,25 zl)'],
                'form'     => ['label' => 'Forma rozliczenia', 'sample' => 'faktura'],
                'day'      => ['label' => 'Dzień miesiąca rozliczenia', 'sample' => '25'],
                'student'  => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'date'     => ['label' => 'Data pierwszej opłaty', 'sample' => '25.10.2026'],
            ],
        ],
        'ti_license_billing_start_guardian' => [
            'label'       => 'Licencja: start rozliczenia — w dniu przypisania (do opiekuna)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-cash-coin',
            'auto'        => true,
            'description' => 'Jak „start rozliczenia”, ale do opiekuna małoletniego kursanta.',
            'message'     => '{{student}}: przypisano licencje {{software}}. Oplata {{amount}} zl miesiecznie{{discount}} (forma: {{form}}), rozliczana {{day}}. dnia miesiaca. Pierwsza oplata: dzis.',
            'vars'        => [
                'student'  => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'software' => ['label' => 'Nazwa oprogramowania', 'sample' => 'Adobe Creative Cloud'],
                'amount'   => ['label' => 'Kwota miesięczna do zapłaty (po rabacie)', 'sample' => '49,00'],
                'discount' => ['label' => 'Rabat (z wiodącą spacją albo puste)', 'sample' => ' po rabacie 20% (cena 61,25 zl)'],
                'form'     => ['label' => 'Forma rozliczenia', 'sample' => 'faktura'],
                'day'      => ['label' => 'Dzień miesiąca rozliczenia', 'sample' => '25'],
                'date'     => ['label' => 'Data pierwszej opłaty', 'sample' => '25.10.2026'],
            ],
        ],
        'ti_license_billing_reminder' => [
            'label'       => 'Licencja: przypomnienie o rozliczeniu — dzień przed (do kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-bell',
            'auto'        => true,
            'description' => 'Co miesiąc, 1 dzień przed terminem rozliczenia licencji (cron o 7:00).',
            'message'     => 'Jutro ({{date}}) rozliczenie licencji {{software}}: {{amount}} zl{{discount}}, forma: {{form}}.',
            'vars'        => [
                'software' => ['label' => 'Nazwa oprogramowania', 'sample' => 'Adobe Creative Cloud'],
                'amount'   => ['label' => 'Kwota do zapłaty (po rabacie)', 'sample' => '49,00'],
                'discount' => ['label' => 'Rabat (z wiodącą spacją albo puste)', 'sample' => ' (po rabacie 20%)'],
                'form'     => ['label' => 'Forma rozliczenia', 'sample' => 'faktura'],
                'date'     => ['label' => 'Termin rozliczenia', 'sample' => '25.11.2026'],
                'student'  => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
            ],
        ],
        'ti_license_billing_reminder_guardian' => [
            'label'       => 'Licencja: przypomnienie o rozliczeniu — dzień przed (do opiekuna)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-bell',
            'auto'        => true,
            'description' => 'Jak przypomnienie dla kursanta, ale do opiekuna małoletniego.',
            'message'     => '{{student}}: jutro ({{date}}) rozliczenie licencji {{software}}: {{amount}} zl{{discount}}, forma: {{form}}.',
            'vars'        => [
                'student'  => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'software' => ['label' => 'Nazwa oprogramowania', 'sample' => 'Adobe Creative Cloud'],
                'amount'   => ['label' => 'Kwota do zapłaty (po rabacie)', 'sample' => '49,00'],
                'discount' => ['label' => 'Rabat (z wiodącą spacją albo puste)', 'sample' => ' (po rabacie 20%)'],
                'form'     => ['label' => 'Forma rozliczenia', 'sample' => 'faktura'],
                'date'     => ['label' => 'Termin rozliczenia', 'sample' => '25.11.2026'],
            ],
        ],
        'ti_am7_account_password' => [
            'label'       => 'AM7: hasło do nowego konta w tenancie (SMS)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-key',
            'auto'        => true,
            'description' => 'Hasło tymczasowe do konta utworzonego z wniosku AM7 („Utwórz konto w tenancie”). Login wysyłany jest osobno e-mailem. Zmiana hasła przy pierwszym logowaniu.',
            'message'     => 'FEER: haslo tymczasowe do konta {{login}}: {{password}} (zmienisz je przy pierwszym logowaniu). Login i instrukcja - w e-mailu.',
            'vars'        => [
                'login'    => ['label' => 'Login (UPN)', 'sample' => 'anna.nowak@edu.feer.org.pl'],
                'password' => ['label' => 'Hasło tymczasowe', 'sample' => 'Kx7!mQ2wRt9p'],
            ],
        ],
        'ti_license_assigned' => [
            'label'       => 'Nowa licencja przypisana (do kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-key',
            'auto'        => true,
            'description' => 'Wysyłane do kursanta po przypisaniu licencji na oprogramowanie (moduł Licencje kursantów TI) — tylko gdy włączył SMS od dydaktyki. Bez loginu i klucza.',
            'message'     => 'Przypisano Ci licencje: {{software}}{{expires}}. Szczegoly w panelu kursanta.',
            'vars'        => [
                'software' => ['label' => 'Nazwa oprogramowania', 'sample' => 'Adobe Creative Cloud'],
                'expires'  => ['label' => 'Ważność (z wiodącym " (wazna do …)" albo puste)', 'sample' => ' (wazna do 31.12.2026)'],
                'student'  => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'org'      => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        'ti_license_assigned_guardian' => [
            'label'       => 'Nowa licencja przypisana (do opiekuna)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-key',
            'auto'        => true,
            'description' => 'Wysyłane do opiekuna małoletniego kursanta po przypisaniu licencji na oprogramowanie (chyba że wyłączył SMS-y). Bez loginu i klucza.',
            'message'     => '{{student}}: przypisano licencje {{software}}{{expires}}. Szczegoly w panelu kursanta.',
            'vars'        => [
                'student'  => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'software' => ['label' => 'Nazwa oprogramowania', 'sample' => 'Adobe Creative Cloud'],
                'expires'  => ['label' => 'Ważność (z wiodącym " (wazna do …)" albo puste)', 'sample' => ' (wazna do 31.12.2026)'],
                'org'      => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        'ti_plan_link' => [
            'label'       => 'Link do planu zajęć grupy',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-link-45deg',
            'auto'        => false,
            'description' => 'Wysyłane ręcznie z panelu dydaktyka (Linki do planów grup; szkolenia krótkie — przy tworzeniu i przyciskiem w liście) do kursantów grupy i opiekunów z dostępem do panelu rodzica — publiczny adres planu zajęć (bez logowania). Kursant: tylko gdy włączył SMS od dydaktyki; opiekun: chyba że wyłączył SMS-y.',
            'message'     => '{{org}}: plan zajec grupy {{group}} jest pod adresem {{url}} - zawsze aktualny, bez logowania.',
            'vars'        => [
                'group' => ['label' => 'Nazwa grupy', 'sample' => 'Programowanie A'],
                'url'   => ['label' => 'Publiczny adres planu', 'sample' => 'https://ti.example.pl/plan/abc123def456ghi789jkl012'],
                'org'   => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        'ti_payment_reminder' => [
            'label'       => 'Brak wpłaty za zajęcia (przypomnienie)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-cash-coin',
            'auto'        => true,
            'description' => 'Codzienne przypomnienie (o 7:00) o zaległej wpłacie za zajęcia, do czasu zaksięgowania wpłaty — do kursanta albo, gdy jest małoletni, do opiekuna. Włączanie, odstęp i limit: Panel dydaktyka → Przypomnienia o wpłatach. Polskie znaki są zamieniane na ASCII przy wysyłce. Domyślnie bez nazwy organizacji (zmieści się w jednym SMS-ie; nadawcę pokazuje bramka) — można dodać {{org}}.',
            'message'     => 'Brak wplaty za zajecia: {{total}} ({{student}}), termin {{due}}. Konto {{account}}, tytul {{title}}.',
            'vars'        => [
                'org'       => ['label' => 'Nazwa organizacji',                       'sample' => 'Dydaktyka TI'],
                'student'   => ['label' => 'Kursant',                                 'sample' => 'Jan Kowalski'],
                'recipient' => ['label' => 'Adresat (kursant lub opiekun)',           'sample' => 'Maria Kowalska'],
                'total'     => ['label' => 'Zaległa kwota łącznie (z „zl”)',          'sample' => '350,00 zl'],
                'count'     => ['label' => 'Liczba zaległych należności',             'sample' => '2'],
                'due'       => ['label' => 'Termin płatności najstarszej należności', 'sample' => '03.10.2026'],
                'days'      => ['label' => 'Dni po terminie (liczba)',                'sample' => '5'],
                'days_text' => ['label' => 'Dni po terminie (np. „5 dni”)',           'sample' => '5 dni'],
                'account'   => ['label' => 'Numer rachunku do wpłat',                 'sample' => 'PL61 1090 1014 0000 0712 1981 2874'],
                'title'     => ['label' => 'Tytuł przelewu',                          'sample' => 'TI/105 Jan Kowalski'],
            ],
        ],
        'ti_exam_open' => [
            'label'       => 'Egzamin udostępniony',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-patch-question',
            'auto'        => true,
            'description' => 'Agent ti_exam_notify (co godzinę, 8:00–20:00): kursant dostaje raz, gdy egzamin Equi Exams w jego grupie zostaje udostępniony. Numery kursanta tylko przy włączonych „SMS o zajęciach”. Włączanie: Panel dydaktyka → Egzaminy → Powiadomienia o egzaminach (domyślnie wyłączone).',
            'message'     => 'Nowy egzamin: {{tytul}} ({{kurs}}). Dostepny {{do_kiedy}}. Panel kursanta: {{link}}',
            'vars'        => [
                'imie'     => ['label' => 'Imię kursanta',      'sample' => 'Jan'],
                'tytul'    => ['label' => 'Tytuł egzaminu',     'sample' => 'Test koncowy HTML'],
                'kurs'     => ['label' => 'Nazwa grupy',        'sample' => 'Informatyka S1'],
                'do_kiedy' => ['label' => 'Termin („DD.MM.RRRR do HH:MM” albo „do odwołania”)', 'sample' => '20.10.2026 do 23:59'],
                'link'     => ['label' => 'Link do zakładki Egzaminy', 'sample' => 'https://szo.feer.org.pl/karty30/ti/kursant/index.php?tab=egzaminy'],
            ],
        ],
        'ti_exam_closing' => [
            'label'       => 'Egzamin zamyka się w ciągu 24 h',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-hourglass-split',
            'auto'        => true,
            'description' => 'Agent ti_exam_notify (co godzinę, 8:00–20:00): kursant, który nie oddał pracy, dostaje raz przypomnienie, gdy do zamknięcia egzaminu zostało mniej niż 24 h. Włączanie: Panel dydaktyka → Egzaminy → Powiadomienia o egzaminach.',
            'message'     => 'Przypomnienie: egzamin {{tytul}} ({{kurs}}) zamyka sie {{do_kiedy}}. Panel kursanta: {{link}}',
            'vars'        => [
                'imie'     => ['label' => 'Imię kursanta',      'sample' => 'Jan'],
                'tytul'    => ['label' => 'Tytuł egzaminu',     'sample' => 'Test koncowy HTML'],
                'kurs'     => ['label' => 'Nazwa grupy',        'sample' => 'Informatyka S1'],
                'do_kiedy' => ['label' => 'Termin zamknięcia („DD.MM.RRRR do HH:MM”)', 'sample' => '20.10.2026 do 23:59'],
                'link'     => ['label' => 'Link do zakładki Egzaminy', 'sample' => 'https://szo.feer.org.pl/karty30/ti/kursant/index.php?tab=egzaminy'],
            ],
        ],
        'rk_pending_notify' => [
            'label'       => 'Wpis na zajęcia czeka na zatwierdzenie',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-person-check',
            'auto'        => true,
            'description' => 'Wysyłane do prowadzącego (pierwszy stopień) lub kierownika (drugi stopień) po wpisie kursanta na zajęcia — zapisy TI (rekrutacja).',
            'message'     => 'Nowy wpis na zajecia: {{student}}, {{when}}. Wymaga zatwierdzenia w panelu. {{org}}',
            'vars'        => [
                'student' => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'when'    => ['label' => 'Termin (z ew. adnotacją serii)', 'sample' => '05.10.2026 16:00 (seria x3)'],
                'org'     => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        'rk_parent_confirm' => [
            'label'       => 'Prośba o zgodę rodzica (zapis małoletniego)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-person-hearts',
            'auto'        => true,
            'description' => 'Informacyjny SMS do rodzica/opiekuna po zapisie małoletniego kursanta na zajęcia — decyzja i tak zapada w mailu z linkiem.',
            'message'     => 'Kursant {{student}} zapisal sie na zajecia {{when}}. Prosimy zatwierdzic rezerwacje - link wyslalismy e-mailem na adres {{email}}. {{org}}',
            'vars'        => [
                'student' => ['label' => 'Kursant', 'sample' => 'Jan Kowalski'],
                'when'    => ['label' => 'Termin', 'sample' => '05.10.2026 16:00'],
                'email'   => ['label' => 'E-mail rodzica', 'sample' => 'rodzic@example.com'],
                'org'     => ['label' => 'Nazwa organizacji', 'sample' => 'Dydaktyka TI'],
            ],
        ],
        'rk_waitlist_slot_free' => [
            'label'       => 'Zwolniło się miejsce (lista oczekujących)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-bell',
            'auto'        => true,
            'description' => 'Wysyłane do pierwszej osoby z listy oczekujących, gdy na wcześniej pełnym terminie zwolni się miejsce.',
            'message'     => 'Zwolnilo sie miejsce na termin {{when}} - bylas(es) na liscie oczekujacych. Zapisz sie w panelu, dopoki miejsce wolne.',
            'vars'        => [
                'when' => ['label' => 'Termin', 'sample' => '05.10.2026 16:00'],
            ],
        ],
    ];
}

/** Czy klucz istnieje w rejestrze. */
function sms_tpl_exists(string $key): bool
{
    return array_key_exists($key, sms_tpl_registry());
}

/** Czy szablon jest wysyłany automatycznie — enabled=0 faktycznie wstrzymuje wysyłkę. */
function sms_tpl_is_auto(string $key): bool
{
    $reg = sms_tpl_registry();
    return !empty($reg[$key]['auto']);
}

/**
 * Zwraca aktualny szablon (nadpisanie z bazy lub domyślny z rejestru).
 * @return array{message:string, enabled:bool, is_custom:bool}|null
 */
function sms_tpl_get(string $key): ?array
{
    $reg = sms_tpl_registry();
    if (!isset($reg[$key])) return null;
    sms_tpl_migrate();

    $def = $reg[$key];
    $row = null;
    try {
        $row = db_one("SELECT message, enabled FROM sms_templates WHERE key_=?", [$key]);
    } catch (\Throwable $e) {}

    if ($row) {
        return [
            'message'   => $row['message'] !== '' ? $row['message'] : $def['message'],
            'enabled'   => (int)$row['enabled'] === 1,
            'is_custom' => true,
        ];
    }
    return ['message' => $def['message'], 'enabled' => true, 'is_custom' => false];
}

/** Podstawia {{nazwa}} (spacje tolerowane) wartościami z $vars. */
function sms_tpl_substitute(string $tpl, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
    }, $tpl);
}

/**
 * Renderuje SMS systemowy: zwraca treść z podstawionymi zmiennymi i flagę enabled.
 * Wołający MUSI sam sprawdzić 'enabled' i pominąć wysyłkę, gdy false —
 * ta funkcja tylko renderuje tekst, nie wysyła.
 * @return array{message:string, enabled:bool}
 */
function sms_tpl_render(string $key, array $vars = []): array
{
    $t = sms_tpl_get($key);
    if ($t === null) return ['message' => '', 'enabled' => true];
    return ['message' => sms_tpl_substitute($t['message'], $vars), 'enabled' => $t['enabled']];
}

/** Zapisuje nadpisanie szablonu. */
function sms_tpl_save(string $key, string $message, bool $enabled, ?int $user_id = null): bool
{
    if (!sms_tpl_exists($key)) return false;
    sms_tpl_migrate();
    db()->prepare(
        "INSERT INTO sms_templates (key_, message, enabled, updated_at, updated_by)
         VALUES (?,?,?,datetime('now','localtime'),?)
         ON CONFLICT(key_) DO UPDATE SET
            message=excluded.message, enabled=excluded.enabled,
            updated_at=excluded.updated_at, updated_by=excluded.updated_by"
    )->execute([$key, $message, $enabled ? 1 : 0, $user_id]);
    return true;
}

/** Przywraca szablon do domyślnej treści (usuwa nadpisanie). */
function sms_tpl_reset(string $key): void
{
    sms_tpl_migrate();
    db()->prepare("DELETE FROM sms_templates WHERE key_=?")->execute([$key]);
}

/** Przykładowe wartości zmiennych — do podglądu/testu w edytorze. */
function sms_tpl_sample_vars(string $key): array
{
    $reg = sms_tpl_registry();
    if (!isset($reg[$key])) return [];
    $out = [];
    foreach ($reg[$key]['vars'] as $name => $meta) $out[$name] = (string)($meta['sample'] ?? '');
    return $out;
}
