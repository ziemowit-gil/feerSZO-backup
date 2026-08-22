# Moduł Oferty (CRM → Oferty)

Specyfikacja techniczno-funkcjonalna i dokumentacja wdrożenia modułu ofertowania
dla działalności odpłatnej (szkolenia, doradztwo, ekspertyzy, tyfloinformatyka).

Wdrożone w: `crm/offers/*`, `crm/oferta.php`, `includes/crm_offers.php`,
`crm/settings/offers.php`, `cron/crm_offers_agent.php`.

---

## 0. Zasada nadrzędna — oferta dla osoby fizycznej wymaga potwierdzenia

Oferta skierowana do **osoby fizycznej** (`crm_contacts.type = 'osoba'`) nie może być
uznana za przyjętą wyłącznie na podstawie adnotacji pracownika. Wymagane jest
udokumentowane **potwierdzenie klienta**.

| Etap | Klient instytucjonalny | Osoba fizyczna |
|---|---|---|
| Wysłanie oferty | bez ograniczeń | **ostrzeżenie z checkboxem** przed wysyłką |
| Oznaczenie „Zaakceptowana” | rejestruje opiekun | **zablokowane** bez potwierdzenia |
| Uruchomienie realizacji (konwersja) | dozwolone po akceptacji | **zablokowane** bez potwierdzenia |

Realizacja techniczna:

* `crm_offer_requires_confirmation($offer)` — reguła (typ kontaktu + przełącznik
  `crm_offer_confirm_person`, domyślnie włączony),
* `crm_offer_blocker($offer, 'accept'|'convert')` — **twarda blokada** (serwer),
* `crm_offer_warning($offer)` — **ostrzeżenie** pokazywane zanim użytkownik wyśle
  ofertę lub uruchomi realizację (baner + wymagany checkbox `ack` + `confirm()` w JS),
* `crm_offer_confirmations` — rejestr potwierdzeń (kto, kiedy, jak, IP, oświadczenia,
  załącznik), z możliwością wycofania z podaniem powodu.

Ścieżki potwierdzenia: **online** (klient sam, przez link publiczny — 3 oświadczenia
+ imię i nazwisko + zapis czasu i IP), **e-mail**, **skan/dokument**, **osobiście/protokół**
(trzy ostatnie rejestruje pracownik, opisując podstawę; dla skanu wymagany załącznik).

Cron `crm_offers_agent` po 5 dniach od wysyłki zakłada opiekunowi zadanie
„Brak potwierdzenia oferty …”, a lista ofert ma filtr `?noconf=1` i licznik w menu.

---

## 1. Architektura danych i integracja z CRM

Tabele modułu leżą w bazie CRM (`crm_db()` — domyślnie baza główna, obsługiwana też
osobna baza CRM). Celowo **nie ma JOIN-ów** do tabel bazy głównej (`users`,
`strategy_objectives`) — dane pobierane są osobnym zapytaniem, żeby moduł działał
także w konfiguracji z wydzieloną bazą CRM.

### Powiązania relacyjne

| Z | Do | Znaczenie |
|---|---|---|
| `crm_offers.contact_id` | `crm_contacts(id)` ON DELETE CASCADE | kontrahent / instytucja / osoba fizyczna |
| `crm_offers.case_id` | `crm_cases(id)` ON DELETE SET NULL | sprawa = szansa sprzedaży |
| `crm_offers.owner_id` | `users(id)` (lookup) | handlowiec / koordynator |
| `crm_offers.objective_id` | `strategy_objectives(id)` (lookup) | cel statutowy |
| `strategy_mapping` | `entity_type='crm_offer'`, `entity_id=offers.id` | wkład oferty w cel statutowy |
| `crm_offer_items.catalog_id` | `crm_offer_catalog(id)` ON DELETE SET NULL | pozycja z cennika |
| `crm_offers.converted_id` | `crm_cases` / `crm_activities` / `umowy_uslugi` | efekt konwersji (`converted_type`) |
| `crm_activities` | `followup_activity_id` | automatyczny follow-up |
| `crm_communications` | `template_name='oferta:{numer}'` | historia wysyłek w kartotece |

### Kartoteka kontrahenta w CRM

* **Zakładka „Oferty”** w `crm/contact/view.php` — pełna historia ofert klienta
  (numer, tytuł, wartość, status, ostrzeżenie o braku potwierdzenia) plus agregaty:
  liczba ofert, wygrane / odrzucone / w toku, wartość wygranych.
* **Zaciąganie danych po NIP** — panel „Kontrahent po NIP” w kreatorze:
  najpierw szukamy w kartotece CRM (`crm_contacts.nip`), a gdy nie ma —
  `ceidg_lookup()` (CEIDG → Biała lista VAT MF) tworzy kartotekę
  (`source='oferta_nip'`) i wraca do kreatora z wybranym klientem.
* Widok oferty pokazuje historię ofert klienta i ostatnią komunikację obok dokumentu.

---

## 2. Funkcjonalności i proces

### Kreator ofert (`crm/offers/form.php`)

* katalog usług odpłatnych w każdym wierszu pozycji (autouzupełnienie nazwy, j.m.,
  ceny, stawki VAT, podstawy zwolnienia, opisu i domyślnego celu statutowego),
* pozycje niestandardowe (wpisywane ręcznie), pozycje **opcjonalne** (poza sumą),
* przeliczanie netto / VAT / brutto **na żywo w JS**, autorytatywnie po zapisie
  przez `crm_offer_recalc()` (te same reguły zaokrągleń),
* stawki VAT właściwe dla działalności odpłatnej: `23 / 8 / 5 / 0 / zw. / np.`
  — `zw.` i `np.` to osobne kody (nie „0%”), z polem podstawy prawnej,
* rabat pozycji i rabat ogólny oferty (wymaga uzasadnienia),
* edycja tylko w statusach otwartych; po decyzji klienta → **nowa wersja** oferty.

### Wariantowość

`crm_offer_variants` — pakiety A/B/C w **jednym dokumencie**: własna nazwa, opis,
znacznik „rekomendowany”, własne sumy. Sumy nagłówka oferty = wariant wybrany przez
klienta → rekomendowany → pierwszy. Klient wybiera wariant na stronie publicznej,
wybór zapisuje się w `selected_variant_id` i przechodzi dalej do konwersji.

### Cykl życia i statusy

```
szkic ──(rabat > limit)──> do_zatwierdzenia ──(zatwierdzenie)──> szkic
  │
  └─(wysyłka)─> wyslana ─┬─> zaakceptowana ──(konwersja)──> zrealizowana
                         ├─> odrzucona
                         └─> wygasla (cron, po valid_until)
                         (w każdej chwili: anulowana)
```

Automaty:

* **follow-up** — po wysłaniu zadanie w `crm_activities` dla opiekuna
  (domyślnie +3 dni, `crm_offer_followup_days`), idempotentnie,
* **wygaszanie** ofert po terminie ważności (cron),
* **przypomnienie** o ofercie tracącej ważność (≤ 2 dni) — e-mail do opiekuna,
* **zadanie o braku potwierdzenia** dla ofert osób fizycznych (≥ 5 dni po wysyłce),
* zdarzenia dla silnika automatyzacji CRM: `offer_sent`, `offer_accepted`,
  `offer_rejected` (dostępne w *Ustawienia CRM → Reguły automatyzacji*).

Każda zmiana trafia do `crm_offer_events` (oś czasu w widoku oferty), łącznie
z akcjami klienta (otwarcie strony, akceptacja, potwierdzenie, odrzucenie).

### Konwersja 1 kliknięciem (`crm_offer_convert()`)

| Cel | Efekt |
|---|---|
| **Sprawa CRM** | `crm_cases` — tytuł „Realizacja: …”, opis z pozycjami wariantu, kwotami i kwalifikacją finansowania; dowiązanie do oferty |
| **Zadanie operacyjne** | `crm_activities` dla opiekuna, termin +1 dzień |
| **Umowa o świadczenie usług** | `contracts/uslugi/add.php?offer_id=…` z wypełnionymi danymi klienta, przedmiotem, zakresem, kwotami i stawką VAT; po zapisaniu `crm_offer_link_contract()` dowiązuje umowę do oferty |

Konwersja ustawia status `zrealizowana` i zapisuje `converted_type/_id/_at/_by`.

---

## 3. Aspekty specyficzne dla działalności odpłatnej

Pola na ofercie:

* `funding_source` — odpłatna / nieodpłatna działalność pożytku publicznego,
  gospodarcza, dotacja/projekt, finansowanie mieszane, sponsor,
* `objective_id` + `statutory_note` — cel statutowy (moduł Strategii) oraz
  uzasadnienie statutowe drukowane na dokumencie,
* `accounting_note` — opis merytoryczny dla księgowości (klasyfikacja przychodu,
  konto, projekt/MPK) — **tylko wewnętrznie**,
* per pozycja: `vat_basis` (podstawa zwolnienia/niepodlegania VAT),
  `objective_id`, `merit_note` (notatka merytoryczna),
* dokument automatycznie dodaje notę o zwolnieniu z VAT / niepodleganiu
  i o rodzaju działalności, w ramach której świadczenie jest realizowane.

### Uprawnienia (*Ustawienia CRM → Reguły ofert*)

| Uprawnienie | Klucz `settings` | Domyślnie |
|---|---|---|
| Odczyt / zapis ofert | `can_read/can_write('crm')` | jak CRM |
| Limit rabatu per rola | `crm_offer_discount_limits` (JSON) | brak wpisu → limit domyślny |
| Limit domyślny | `crm_offer_discount_default_limit` | 10 % (admin: 100 %) |
| Zatwierdzanie rabatów ponad limit | `crm_offer_discount_approvers` | `admin` |
| Edycja cennika usług odpłatnych | `crm_offer_catalog_editors` | `admin` |
| Wymóg potwierdzenia dla osoby fizycznej | `crm_offer_confirm_person` | włączony |

Rabat ponad limit autora przestawia ofertę w `do_zatwierdzenia` i blokuje wysyłkę
do czasu zatwierdzenia przez uprawnioną rolę.

Pozostałe ustawienia: `crm_offer_number_prefix` (numeracja `OF/0001/2026`),
`crm_offer_validity_days`, `crm_offer_followup_days`, `crm_offer_footer`.

---

## 4. Model bazodanowy

Migracja idempotentna: `crm_offers_migrate()` w `includes/crm_offers.php`
(wywoływana na wejściu do każdej strony modułu — wzorzec samonaprawy schematu).

| Tabela | Rola | Indeksy |
|---|---|---|
| `crm_offer_catalog` | cennik usług odpłatnych (kod, j.m., cena, VAT, podstawa zwolnienia, cel statutowy, cena minimalna) | `(is_active, sort_order)`, `(category)` |
| `crm_offers` | nagłówek: numer (UNIQUE), wersja, klient, sprawa, opiekun, status, warunki, rabat, sumy, kwalifikacja NGO, potwierdzenie, token publiczny, konwersja, soft-delete | `offer_number` UNIQUE, `(contact_id)`, `(status, valid_until)`, `(owner_id, status)`, `(case_id)`, `(access_token)`, `(updated_at)` |
| `crm_offer_variants` | pakiety A/B/C + sumy wariantu | `(offer_id, sort_order)` |
| `crm_offer_items` | pozycje: ilość, cena, rabat, VAT + podstawa, sumy linii, cel statutowy, opcjonalność | `(offer_id)`, `(variant_id, sort_order)` |
| `crm_offer_confirmations` | potwierdzenia klienta: metoda, osoba, oświadczenia (JSON), plik, IP, UA, wycofanie | `(offer_id)` |
| `crm_offer_events` | dziennik zdarzeń (audyt, także akcje klienta) | `(offer_id, created_at)` |
| `crm_offer_templates` | szablony ofert (gotowe zestawy pozycji) | — |

Sumy są **materializowane** (`total_net/vat/gross` na ofercie i wariancie), żeby
listy, KPI i statystyki liczyły się jednym zapytaniem bez agregacji po pozycjach.

---

## 5. Interfejs

### Lista ofert (`crm/offers/index.php`)

KPI (liczba ofert, pipeline, wartość wygranych, skuteczność) → pasy statusów
z licznikami → filtry (szukanie po numerze/tytule/kliencie/NIP, finansowanie,
opiekun, „Moje”) → wiersze: numer, liczba wariantów, **flaga braku potwierdzenia**,
tytuł, klient, opiekun, wartość, status, termin ważności (podświetlony, gdy ≤ 3 dni).
Baner u góry zbiera oferty osób fizycznych bez potwierdzenia.

### Widok oferty (`crm/offers/view.php`) — kontekst rozmowy na jednym ekranie

* **Nagłówek**: numer, status, wersja, klient, wartość + akcje (edycja, wysyłka,
  wydruk, PDF, nowa wersja, duplikat, nowy link, anulowanie, usunięcie).
* **Baner ostrzegawczy** dla osoby fizycznej bez potwierdzenia (z akcjami).
* **Lewa kolumna**: kartoteka klienta (dane + skrót do „Napisz” i nowej sprawy),
  historia ofert klienta z agregatami, kwalifikacja (finansowanie, cel statutowy,
  opiekun, ważność, odsłony klienta, follow-up), link publiczny, ostatnia komunikacja.
* **Prawa kolumna**: dokument oferty w wersji wewnętrznej (z notatkami), panel
  decyzji klienta (wybór wariantu + akceptacja / odrzucenie z powodem), panel
  potwierdzenia, panel uruchomienia realizacji, oś czasu zdarzeń.

### Strona klienta (`crm/oferta.php?t=TOKEN`)

Bez logowania, `noindex`. Dokument oferty → wybór wariantu → (dla osoby fizycznej)
trzy oświadczenia i imię i nazwisko → „Potwierdzam i akceptuję ofertę” albo
odrzucenie z powodem. Zapisujemy datę, godzinę i IP; opiekun dostaje e-mail.
Link można unieważnić („Nowy link publiczny”).

### Identyfikacja wizualna dokumentu

* **Kolor** — `settings.crm_offer_brand_color` (*Ustawienia CRM → Reguły ofert*, pole koloru),
  domyślnie zieleń marki CRM `#2E844A`. Kolor steruje belką pod nagłówkiem, tytułem
  „OFERTA”, nagłówkami tabel, obramowaniem wariantu rekomendowanego i stopką PDF.
  Świadomie **nie** używamy `volunteer_color` — to kolor panelu wolontariusza.
* **Logo** — `settings.org_logo` (Administracja → Dane organizacji), plik z `assets/logo/`.
  W PDF wstawiane ze ścieżki lokalnej, w HTML z URL-a; brak logo = tylko nazwa organizacji.
* **Typografia** — Lato (tekst) + Montserrat (nagłówki). Strony HTML biorą je z Google Fonts.
  PDF wymaga plików TTF w `assets/fonts/`:
  `Lato-Regular/Bold/Italic/BoldItalic.ttf`, `Montserrat-Regular/Bold/Italic/BoldItalic.ttf`
  (statyczne, nie variable). `crm_offer_pdf_fontdata()` rejestruje w mpdf tylko te rodziny,
  których pliki istnieją — bez nich PDF powstaje w DejaVu Sans, więc brak fontów niczego nie psuje.
* **Dane do płatności** — `settings.org_rachunki_bankowe` to JSON listy rachunków.
  `crm_offer_bank_accounts()` porządkuje je pod rodzaj działalności: dla oferty odpłatnej
  pierwszy jest rachunek z opisem wskazującym na odpłatność / szkolenia / przychody
  (np. „Rozliczenia i przychody ze szkoleń i dz. odpłatnej"), dla dotacji — rachunek
  bieżący. Numer drukowany jest jako IBAN (`PL78 1600 …`), a dodatkowe rachunki
  pokazywane tylko wtedy, gdy mają inną walutę niż oferta.

### Wydruk i PDF

`crm/offers/print.php` — wydruk HTML, `?pdf=1` — PDF (mpdf, DejaVu Sans, stopka
z numerem oferty i paginacją), `?internal=1` — wersja wewnętrzna z notatkami.
PDF można dołączyć do wysyłki e-mail (`crm_offer_pdf_file()`).

---

## 6. Pliki

| Plik | Rola |
|---|---|
| `includes/crm_offers.php` | rdzeń: migracja, stałe, uprawnienia, kalkulacje, cykl życia, potwierdzenia, konwersja, dokument, PDF |
| `crm/offers/index.php` | rejestr ofert |
| `crm/offers/form.php` | kreator / edytor (warianty, katalog, NIP) |
| `crm/offers/view.php` | widok szczegółowy + modale akcji |
| `crm/offers/action.php` | jedyny punkt zmian stanu (POST): wysyłka, statusy, rabat, potwierdzenie, konwersja, wersje |
| `crm/offers/print.php` | wydruk / PDF |
| `crm/offers/catalog.php` | katalog usług odpłatnych (CRUD, osobne uprawnienie) |
| `crm/oferta.php` | publiczna strona oferty (token) |
| `crm/settings/offers.php` | reguły modułu (potwierdzenia, rabaty, numeracja, follow-up, stopka) |
| `cron/crm_offers_agent.php` | wygaszanie, follow-up, przypomnienia, brak potwierdzeń |

Zmiany w istniejących plikach: `crm/includes/header_crm.php` (menu + liczniki),
`crm/settings/_nav.php`, `crm/settings/automations.php` (zdarzenia ofertowe),
`crm/contact/view.php` (zakładka „Oferty”), `crm/dashboard.php` (KPI + szybka akcja),
`contracts/uslugi/add.php` (prefill z oferty + dowiązanie umowy),
`cron/dispatcher.php` (agent `crm_offers`).


---

# Integracja CRM ↔ Microsoft 365 (Outlook)

Dwie strony tej samej integracji. Konfiguracja wychodzącej: *Ustawienia CRM → Microsoft 365*
(`crm/settings/office.php`), przychodzącej: *Administracja → Synchronizacja Outlook*.

## Co było wcześniej (przychodzące)

| Mechanizm | Plik | Działanie |
|---|---|---|
| `OutlookSync::sync_contacts()` | `includes/outlook_sync.php` | kontakty Outlooka → `crm_contacts` (delta, powiązanie przez `outlook_id`) |
| `OutlookSync::sync_calendar()` | — | zdarzenia kalendarza → `crm_events` |
| `OutlookSync::sync_messages()` | — | `inbox` + `sentitems` (delta) → `crm_communications`, dopasowanie po adresie, dedup po `outlook_message_id` |
| `crm_inbox_watch_run()` | `includes/crm_inbox.php` | skrzynka współdzielona → nowe kontakty + log korespondencji |

## Co doszło (wychodzące + punktowe)

`includes/crm_office.php`:

* `crm_office_push_contact($id)` — kontakt CRM → **książka adresowa Outlooka**
  (Graph `POST/PATCH /users/{mailbox}/contacts`). Powiązanie trzymane w tym samym
  `crm_contacts.outlook_id`, którego używa synchronizacja przychodząca, więc kontakt
  nie dubluje się w żadną stronę. Gdy wpis usunięto w Outlooku (HTTP 404) — tworzony jest nowy.
* `crm_office_contact_payload()` — mapowanie: nazwa, imię/nazwisko lub `companyName`,
  e-mail, telefon (komórkowy dla osób, służbowy dla podmiotów), stanowisko, adres
  strukturalny, NIP/KRS i notatka z linkiem do kartoteki; kategorie `CRM` + typ kontaktu.
* `crm_office_pull_contact_mail($id)` — **korespondencja jednego kontaktu na żądanie**:
  Graph `$search=participants:<e-mail>` po skrzynce, filtr dni po stronie PHP, zapis do
  `crm_communications` z dedupem po `outlook_message_id`.
* `crm_office_push_pending($limit)` — dosyłanie zaległych (brak wpisu lub `office_pushed_at < updated_at`).
* `crm_office_permissions()` — sprawdza, czy aplikacja ma `Contacts.ReadWrite` i `Mail.Read`.

Nowe kolumny: `crm_contacts.office_pushed_at`, `office_push_error`, `office_mail_pulled_at`
(migracja `crm_office_migrate()`, idempotentna).

Nowe metody Graph w `includes/m365.php`: `create_outlook_contact()`, `update_outlook_contact()`,
`get_outlook_contact()`, `delete_outlook_contact()`, `get_contact_folders()`,
`search_messages_participant()` oraz prywatne `http_delete()`.

## Punkty wejścia

| Miejsce | Co robi |
|---|---|
| Kartoteka kontaktu → panel „Microsoft 365" | „Dodaj / zaktualizuj w książce adresowej", „Pobierz maile z Outlooka" (AJAX, `crm/api/office.php`), status powiązania i data ostatniego zapisu |
| `crm/settings/office.php` | włączenie zapisu, skrzynka, folder kontaktów, auto-zapis nowych kontaktów, zakres dni i limit maili, masowy zapis zaległych, lista błędów, kontrola uprawnień |
| `CrmManager::createContact()` | auto-zapis nowego kontaktu, gdy `crm_office_auto_push` = 1 (błąd Graph nie przerywa dodawania) |
| `cron/crm_office_push.php` | co 30 min dosyła zaległe kontakty (agent `crm_office_push` w dyspozytorze) |

## Uprawnienia w Entra ID

* `Contacts.ReadWrite` (Application) — zapis kontaktów. **Jest nadane.**
* `Mail.Read` (Application) — pobieranie korespondencji. **Brakuje** w rejestracji
  „[PREPROD] Panel" (są tylko `Mail.Send` i `Contacts.Read/ReadWrite`), dlatego pobieranie
  maili zwraca HTTP 403 — dotyczy też `sync_messages()` i śledzenia skrzynki.
  Dodać w App registrations → API permissions → Microsoft Graph → Application permissions
  i zatwierdzić zgodą administratora.
