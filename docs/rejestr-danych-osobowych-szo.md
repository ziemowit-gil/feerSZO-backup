# Dane osobowe w SZO — analiza zbiorów (wersja robocza)

Źródło: schemat `umowy.db` (569 tabel, bez odczytu zawartości) + kod `includes/`, `modules/`, `cron/`.
Dokument roboczy dla IOD/zarządu — **podstawy prawne, role (ADO/procesor) i okresy retencji to propozycje do potwierdzenia**, nie ustalenia. Nie zastępuje rejestru czynności przetwarzania (art. 30 RODO); w SZO nie ma dziś takiego rejestru (jest tylko `rodo/` = upoważnienia i `gdpr_clauses` = klauzule).

## 1. Kategorie danych

| Kategoria | Przykładowe pola / tabele |
|---|---|
| Identyfikacja | imię, nazwisko, nazwisko rodowe, imiona rodziców, miejsce i data urodzenia (`users.rpts_*`, `persons`, `umowy_*`, `onboarding_volunteers`, `crm_contacts`) |
| Identyfikatory państwowe | **PESEL** (`persons`, `umowy_zlecenie/dzielo/praca/wolontariat`, `onboarding_volunteers`, `crm_contacts`, `k30_clients`, `donations`, `skierowania_badania`, `pelnomocnictwa`, `rodo_authorizations`, `users.rpts_pesel`), NIP, **seria i nr dowodu** (`persons`, `umowy_zlecenie/praca`, `onboarding_volunteers`) |
| Kontaktowe | e-mail, telefony, adres zamieszkania/korespondencyjny, adres e-Doręczeń (`users`, `persons`, `crm_*`, `k30_*`, `ev_registrations`, `helpdesk_tickets`) |
| Finansowe | rachunki bankowe (`onboarding_volunteers`, `edok_*`, `kdok_dostawcy`, `donations`, `k30_ti_student_accounts`, `zlecenie_rachunki`), wynagrodzenia, ZUS/US, przelewy, płatności Stripe/PayU/P24 |
| **Szczególne (art. 9)** | **zdrowie/niepełnosprawność**: `k30_ti_accessibility_profiles` (technologie tyflo), `ti_nabor_submissions.participant_medical_notes`, `skierowania_badania` (badania lekarskie), `k30_clients.equipment`; **biometria**: `webauthn_credentials` (klucze publiczne — nie wzorce biometryczne, ale uwierzytelnianie silne), zdjęcia profilowe `user_profiles.avatar_*` |
| Karalność (art. 10) | RPTS (`users.rpts_*`, `includes/rpts.php`) — zaświadczenie z rejestru przestępstw na tle seksualnym, wolontariusze/kadra pracujący z małoletnimi |
| Małoletni | `users.is_minor/guardian_*`, `umowy_wolontariat.rodzic_*`, `k30_ti_student_accounts.guardian_*`, `ti_nabor_submissions` (klasa, data ur./PESEL), zgody przedstawiciela ustawowego |
| Dane techniczne / logi | IP, user agent, sesje, próby logowania (`login_log`, `login_attempts`, `user_sessions`, `*_audit_log`, `*_access_log`, `saml_sso_log`, `kdok_history`), tokeny, TOTP, kody SMS, PIN mobilny |
| Treści komunikacji | maile (`mail_queue`, `email_send_log`, `crm_communications`, `poczta`), SMS, wiadomości TI, zgłoszenia helpdesk, komentarze |
| Edukacyjne | oceny, frekwencja, testy, egzaminy, prace domowe, protokoły, certyfikaty (`k30_ti_*`) |

## 2. Zbiory (propozycja podziału na czynności przetwarzania)

| # | Zbiór | Osoby | Główne tabele / moduły | Dane | Podstawa (propozycja) |
|---|---|---|---|---|---|
| 1 | Konta użytkowników i uwierzytelnianie | pracownicy, współpracownicy, wolontariusze, admini | `users`, `webauthn_credentials`, `user_sessions`, `login_log`, `sms_login_tokens`, `password_reset_tokens`, `saml_sso_log`, `user_profiles`, `m365_*`, LDAP | imię, nazwisko, e-mail, telefon, hasła (hash), TOTP, WebAuthn, IP, zdjęcie | art. 6(1)(b)/(f) |
| 2 | Umowy cywilnoprawne i kadry | zleceniobiorcy, wykonawcy dzieł, pracownicy, wolontariusze, kontrahenci-osoby | `umowy_zlecenie/dzielo/praca/wolontariat/uslugi/inne/powierzenie`, `persons`, `contract_*`, `zlecenie_*` (rachunki, rozliczenia, ewidencja), `timesheets`, `byli_osoby`, `pelnomocnictwa`, `rodo_authorizations` | PESEL, dowód, adres, rachunek, US, ZUS, wynagrodzenie | art. 6(1)(b),(c) |
| 3 | Wolontariat — nabór, onboarding, RPTS | kandydaci, wolontariusze (także małoletni), opiekunowie | `onboarding_volunteers`, `volunteer_applications`, `users.rpts_*`, zgody przedstawiciela, weryfikacja małoletnich, `skierowania_badania` | PESEL, dowód, rachunek, RPTS (art. 10), zdrowie (badania) | art. 6(1)(b),(c); 9(2)(b); 10 |
| 4 | Rekrutacja | kandydaci do pracy | `rekr_applications`, `rekr_email_log`, `rekr_*` | imię, nazwisko, e-mail, telefon, CV/załączniki | art. 6(1)(a)/(b); retencja do zakończenia naboru |
| 5 | Teleinformatyka (TI) — uczestnicy szkoleń | uczestnicy (w tym małoletni, osoby z niepełnosprawnością wzroku), rodzice, osoby upoważnione | `k30_clients`, `k30_ti_student_accounts`, `k30_ti_enrollments`, `k30_ti_attendance`, `k30_ti_grades`, `k30_ti_tests*`, `k30_ti_exam*`, `k30_ti_protocols*`, `k30_ti_accessibility_profiles`, `k30_ti_authorized_persons`, `k30_ti_parent_tokens`, `k30_ti_messages`, `k30_ti_payments/billing`, `k30_ti_terms_accepts`, `ti_nabor_*` | dane kontaktowe, PESEL/data ur., zdrowie (art. 9), oceny, frekwencja, płatności, IP akceptacji regulaminu | art. 6(1)(b),(c); 9(2)(a)/(h) |
| 6 | TI — kadra dydaktyczna | prowadzący, konsultanci | `k30_ti_instructor_*`, `ti_consultant_*`, `k30_ti_duty`, urlopy, wynagrodzenie (payout), Zoom | dane kontaktowe, kalendarze, stawki | art. 6(1)(b) |
| 7 | Licencje | licencjodawcy, posiadacze, wnioskodawcy | `licenses`, `license_*`, AM5/AM7 | e-mail, telefon, adres, IP podpisu | art. 6(1)(b),(c) |
| 8 | CRM | kontakty, osoby kontaktowe, beneficjenci, darczyńcy | `crm_contacts`, `crm_contact_persons`, `crm_cases`, `crm_communications`, `crm_consents`, `crm_campaign_*`, `crm_suppressions`, `crm_form_intake_log`, `crm_offer_*` | imię, nazwisko, PESEL, adres, e-mail, telefon, historia kontaktów, zgody marketingowe, IP | art. 6(1)(a),(f); retencja: `includes/crm_retention.php` |
| 9 | Darowizny | darczyńcy | `donations` | imię, nazwisko, adres, PESEL, NIP, rachunek | art. 6(1)(c) (PIT, rachunkowość 5 lat) |
| 10 | Księgowość, EODoK, KDOK, Faktury, płatności | kontrahenci-osoby, odbiorcy faktur, płatnicy | `edok_*`, `kdok_*`, `invoices`, `zwroty_kosztow`, `stripe/payu/p24_payments`, `us_przelewy`, `edok_zus_przelewy`, portal kontrahenta | NIP, rachunki, e-mail, kwoty, dokumenty księgowe | art. 6(1)(b),(c) |
| 11 | EZD — dokumentacja i korespondencja | nadawcy/adresaci pism, strony spraw | `ezd_sprawy`, `ezd_pisma`, `ezd_rpwy`, `ezd_external_shares`, `ezd_log`, `correspondence`, `contract_letters` (Postivo), e-Doręczenia | dane adresowe, treść pism (dowolne kategorie) | art. 6(1)(c),(e) |
| 12 | Poczta, kalendarz i zadania | pracownicy, korespondenci | `poczta*`, `mail_queue`, `email_send_log`, `tasks`, `task_*`, `tidycal_bookings` | adresy e-mail, treść wiadomości, zadania | art. 6(1)(b),(f) |
| 13 | Helpdesk IT i kontrola IT | zgłaszający, operatorzy | `helpdesk_tickets/escalations`, `it_service_passwords`, `it_control` | e-mail, telefon, opis zgłoszenia, hasła usług (przechowywanie!) | art. 6(1)(b),(f) |
| 14 | Wydarzenia, Komunikaty, Wsparcie OU | uczestnicy wydarzeń, odbiorcy | `ev_registrations`, `announcements`, `szo_assistance_requests`, `szo_consultation_cards`, `wsparcie_ou`, `k30_pl_*` | imię, e-mail, telefon, opis problemu | art. 6(1)(a),(b) |
| 15 | RODO — rejestry własne | osoby upoważnione, osoby, których dane usunięto | `rodo_authorizations`, `rodo_deletion_log`, `gdpr_clauses` (akceptacje), `users.gdpr_statement_*`, `crm_consents` | PESEL, IP, daty zgód | art. 6(1)(c) |
| 16 | Bezpieczeństwo i audyt | wszyscy | `admin_audit_log`, `api_audit_log`, `impersonation_requests`, `k30_access_log`, `*_access_log`, `audit_logs` (karty NFC), `smart_cards` | IP, e-mail, akcje, znaczniki czasu | art. 6(1)(f), (c) |
| 17 | Asystent AI | pracownicy (zapytania) | `asystent_ai.php`, `chatbot/` | treść zapytań + wyniki z danych SZO wysyłane do Anthropic | art. 6(1)(f) — **ryzyko**: wyciek danych do podmiotu trzeciego |
| 18 | Zewnętrzne aplikacje (MSUES, SAML IdP, API) | konta, osoby importowane | `api/v1/msues_users.php`, `msues_mail.php`, `saml_idp` | lista kont, e-maile koordynatorów | art. 6(1)(b),(f) |

## 3. Odbiorcy / podmioty przetwarzające (wynik z kodu `includes/`)

Microsoft 365 / Graph / SharePoint (konta, poczta, pliki, sync), Fakturownia (faktury), Comarch Betterfly, KSeF, Stripe, PayU, Przelewy24 (płatności), SMS (smsapi, SerwerSMS, Twilio), Zoom (spotkania TI), TidyCal (rezerwacje), DocuSign, Autenti (podpisy), Postivo (poczta papierowa/hybrydowa), ownCloud, LDAP, Redmine (helpdesk → zadania), Nozbe (import zadań), Canva (dostęp), **Anthropic** (asystent AI), hosting MyDevil. Każdy wymaga umowy powierzenia (art. 28) lub oceny roli ADO↔ADO, a poza EOG — podstawy transferu.

## 4. Obserwacje i ryzyka (do weryfikacji)

1. **Brak rejestru czynności przetwarzania (art. 30)** — niniejszy dokument jest do tego punktem wyjścia.
2. **PESEL i seria/nr dowodu przechowywane w wielu miejscach** (min. 10 tabel) — brak jednego źródła prawdy, utrudnia realizację praw osób i retencję. Sprawdzić, czy kolumny są szyfrowane (nie wynika ze schematu).
3. **Dane szczególne**: zdrowie uczestników TI (profil dostępności, `participant_medical_notes`) i badania wolontariuszy; RPTS (art. 10) — wymagają podstawy z art. 9/10, ograniczenia dostępu i ewentualnie **DPIA** (osoby z niepełnosprawnością, małoletni, profilowanie — kryteria WP29).
4. **Małoletni** — zgody przedstawicieli: `guardian_consent` (co 6 mies.), weryfikacja co 90 dni; spójność z naborem TI do sprawdzenia.
5. **Retencja** — jedyny mechanizm anonimizacji jest w CRM (`crm_retention.php`); poza tym tylko czyszczenie `sprawdz_konto_log`, `kdok`, harmonogramów TI. **Brak polityki retencji** dla umów, TI, rekrutacji, logów (IP), poczty, helpdesku.
6. **Logi z IP** w >30 tabelach — ustalić okres przechowywania.
7. **`it_service_passwords`** — hasła usług (nawet czasowo) to ryzyko; sprawdzić szyfrowanie i retencję.
8. **Asystent AI (Anthropic)** — ocenić, jakie dane osobowe trafiają w zapytaniach; umowa powierzenia/DPA, transfer poza EOG.
9. **Lokalne pliki z bazą w repozytorium roboczym** (`umowy.db`, `umowy.production.db` — kopia produkcyjna, 12 MB) — kopie z danymi osobowymi poza środowiskiem produkcyjnym; upewnić się, że nie trafiają do gita/backupów bez szyfrowania.
10. Osobne systemy poza SZO z danymi tej samej organizacji: MSUES (`/Users/zgil/feer-rbs`), vcard-cms, feerSZO-v2 — ujęte tylko jako interfejsy.

## 5. Proponowane następne kroki

1. Potwierdzić z IOD podział na zbiory (sekcja 2) i role ADO/procesor.
2. Uzupełnić dla każdego zbioru: cel, podstawę, odbiorców, retencję, środki techniczne (art. 30 ust. 1 lit. a–g).
3. Dodać do SZO moduł „Rejestr czynności przetwarzania” (powiązany z `gdpr_clauses` i `crm_consents`).
4. Zaprojektować politykę retencji dla zbiorów 2–5, 12, 13, 16 wg wzoru `crm_retention`.
5. DPIA dla zbiorów 3, 5 (zdrowie, małoletni) i 17 (AI).
