# feerSZO — ONBOARDING

Wewnętrzny system zarządzania organizacją FEER (umowy, EZD, CRM, karty30/TI, panel wolontariusza i in.).

## Stos i konwencje

- **PHP + SQLite** (jedna baza `umowy.db`, ścieżka `DB_PATH`), integracja z **Microsoft 365 / SharePoint (Graph)**.
- **Migracje w kodzie**: moduły tworzą/rozszerzają tabele przez `CREATE TABLE IF NOT EXISTS` + listę `ALTER TABLE ADD COLUMN` (no-op, gdy kolumna istnieje). Dodając kolumnę pamiętaj, by dopisać ją **i do CREATE, i do listy ALTER**.
- **Cron**: jeden dyspozytor `cron/dispatcher.php` uruchamiany co minutę; rejestruje agentów z interwałami (i opcjonalnym oknem godzinowym `schedule`).
- **UI**: głównie Bootstrap; nowe moduły stopniowo na Tailwind/Alpine.
- **Uprawnienia**: `require_login()`, `can_edit()`, `is_admin()`, `require_role('admin')`, `module_enabled('<flaga>')`.

---

## Moduł: Rejestr pełnomocnictw

Samodzielny rejestr pełnomocnictw (świadomie **poza EZD** — dawny rejestr na klasie JRWA `013` był zbyt ciężki operacyjnie). Jedyne źródło prawdy; stary rejestr EZD tylko przekierowuje. Flaga modułu: `pelnomocnictwa_enabled`; dostęp `can_edit()`, usuwanie `is_admin()`. Menu: „Umowy → Obsługa umów".

### Pliki

- **`includes/pelnomocnictwa.php`** — rdzeń: migracja tabeli, CRUD, statusy, generowanie HTML dokumentu i **PDF (mPDF)**, historia/audyt, helpery rodzajów i zwrotu. Niezależny od EZD.
- **`includes/pelnomocnictwa_ezd.php`** — most do EZD (koszulki), celowo odseparowany; wszystkie funkcje no-op, gdy EZD wyłączone.
- **`pelnomocnictwa/index.php`** — panel: lista, filtr (q/status/rok), formularz dodawania/edycji, banery (wygasające / brak podpisu), akcje.
- **`pelnomocnictwa/dokument.php`** — dokument 1:1 na ekranie (edytowalne pola, druk/PDF przeglądarki).
- **`pelnomocnictwa/pdf.php`** — eksport do pliku PDF (mPDF), inline/`&download=1`.
- **`pelnomocnictwa/dokument_download.php`** — pobranie podpisanego skanu (auth + lookup po id).
- **`pelnomocnictwa/search_kontrahent.php`** — podpowiedzi osób z umów; **`search_user.php`** — podpowiedzi kont użytkowników (OSOBNE!).
- **`panel/includes/pv_pelnomocnictwa.php`** — widżet „Moje pełnomocnictwa" w panelu.
- **`api/v1/pelnomocnictwa.php`** — REST (scope `pelnomocnictwa:read`/`:write`).
- **`cron/pelnomocnictwa_expiry_reminder.php`** — przypomnienia o wygasaniu (dyspozytor `pelnomocnictwa_expiry`, rano).

### Model danych (tabela `pelnomocnictwa`)

- Podstawa: `numer` (auto `P/0001/2026`), `mocodawca`, `pelnomocnik`, `pelnomocnik_pesel`, `zakres` (1 pozycja/linia), `forma`, `data_udzielenia` / `data_waznosci` / `data_odwolania`, `uwagi`, `podpisujacy` + `podpisujacy_funkcja`.
- Rozszerzenia: `pelnomocnik_user_id` (powiązanie z kontem), `zwrot` (`pan`/`pani`), `rodzaj` (`ogolne`/`korespondencja`) + `kor_tryb` (`ogolne`/`konkretne`/`wylaczenie`) + `kor_szczegoly`, `ezd_sprawa_id`, `dokument_*` (skan).
- **Status wyliczany z dat** (Ważne / Wygasłe / Odwołane) — nie przechowywany.
- Osobna tabela **`pelnomocnictwa_log`** = historia zmian (audyt).

### Funkcje

- **CRUD + generowanie dokumentu** 1:1 wg papierowego wzoru (Times/DejaVu, boilerplate organizacji z `admin/org_settings.php`: `org_sad_rejestrowy` i in.). Pola do ręcznej odmiany (np. nazwa organizacji) są `contenteditable`.
- **Rodzaj „do odbioru korespondencji"** — ogólne / do konkretnej przesyłki / z wyłączeniem (`pelnomocnictwo_kor_opis()` buduje formułę); osobny wariant tytułu i treści dokumentu.
- **Zwrot Pan/Pani** wybierany na etapie wpisu → w dokumencie stałe „Panu"/„Pani" (`pelnomocnictwo_zwrot_celownik()`).
- **Eksport PDF** (`pelnomocnictwo_pdf_html()` + `pelnomocnictwo_pdf_render()`).
- **Historia zmian (audyt)** — `pelnomocnictwo_log()` na create/update/revoke/upload/skan/reminder; oś czasu w edycji.
- **Szybkie „Odwołaj"** z listy (`pelnomocnictwo_revoke()`, data odwołania = dziś).
- **Powiązanie z kontem** (`pelnomocnik_user_id`) → widżet „Moje pełnomocnictwa" w panelu + lepszy widget „Zastępstwa" w EZD (`pelnomocnictwa_for_user()`).
- **Przypomnienia o wygasaniu** — 30/7/1 dni: dzwonek (`notif` typ `pelnomocnictwo`) do zakładającego + powiązanego pełnomocnika, e-mail do zakładającego; baner w module (`pelnomocnictwa_expiring()`).
- **Upload podpisanego skanu** (PDF/JPG/PNG) → `uploads/pelnomocnictwa/`.

### Integracja z EZD (koszulki) i wymuszanie podpisu

- Przy zapisie każdego wpisu automatycznie zakładana jest **koszulka (sprawa) w EZD** w segregatorze symbol `013` (`pelnomocnictwo_ensure_koszulka()`, idempotentne, `ref_type='pelnomocnictwo'`, zapis `ezd_sprawa_id`). Opt-out: `org_setting('pelnomocnictwa_ezd_auto')='0'`.
- **Wymuszanie podpisu** — `pelnomocnictwo_needs_signature()` / `pelnomocnictwa_missing_signature()`: baner braków, wezwanie w edycji, badge „brak podpisu". Podpisany skan → `pelnomocnictwo_ezd_register_signed()` (pismo wewnętrzne + załącznik w koszulce). Przycisk „PDF → koszulka" (`pelnomocnictwo_ezd_attach_generated()`).

### Pułapki

- Dodając kolumnę: **i CREATE, i lista ALTER** (inaczej „no such column" na istniejącej bazie).
- `search_kontrahent.php` (osoby z umów) ≠ `search_user.php` (konta) — różne cele.
- Most EZD musi zostać cichy, gdy EZD off (nie może przerwać zapisu pełnomocnictwa — błędy łapane, zwracają null).

---

## Moduł: Kopie zapasowe (backup)

Trzy niezależne kanały kopii + monitoring. Wszystko gated rolą **admin**; panel: **`admin/backups.php`**.

### 1. Kanał lokalny (przyrostowy)

- **Agent:** `cron/agents/backup.php` — dyspozytor `backup`, **co 4h (interval 14400), całą dobę**.
- **Zakres:** baza (`VACUUM INTO`, pomijana gdy niezmieniona), `uploads/` (tar.gz tylko zmienionych plików), `certs/` (pełny tar, gdy zmieniony — klucze prywatne app + SAML).
- **Znaczniki** w `backups/`: `.last_db_mtime`, `.last_uploads_ts`, `.last_certs_ts`.
- **Wynik:** `backups/YYYY-MM/` (katalog niedostępny przez HTTP).
- **Rotacja:** zawsze min. **3 ostatnie kopie każdego typu** (db/uploads/certs), reszta starsza niż **30 dni** usuwana. Grupowanie po `backup_kind()`.

### 2. Kanał SharePoint (osobny)

- `cron/agents/sp_backup_incremental.php` (co 6h) + `cron/agents/sp_backup_full.php` (nocą 1:00–3:00). Własne znaczniki `.last_sp_*` — **nie mieszać** z lokalnymi. Cicho pomijają, gdy `sp_enabled != 1`. Realny backup **off-site**.
- **Parytet z lokalnym:** obejmuje bazę, `uploads/` **i `certs/`**; pliki są **szyfrowane** (`_sp_backup_prepare()`), gdy szyfrowanie włączone. Foldery na SP: `sp_backup_folder/{incremental,full}/YYYY-MM/`.
- **Alerty:** przy nieudanej wysyłce agenci wołają `backup_alert()` (dzwonek + e-mail do adminów). Sukces → znacznik `.last_sp_ok`.
- **Retencja:** `sp_backup_retention()` (po pełnym backupie, raz na dobę) zachowuje min. 3 najnowsze kopie każdego typu w każdym kanale, starsze niż `sp_backup_retention_days` (dom. 90) usuwa.
- **Panel:** dashboard „Kondycja kopii" pokazuje ostatnią wysyłkę na SP (wiek), folder i próg retencji.

### 3. Wspólna logika — `includes/backup.php`

Używana przez agenta, monitor i panel:

- **Szyfrowanie (AES-256-CBC, PBKDF2, openssl CLI):** `backup_encrypt_file()` / `backup_decrypt_file()`. Włączone, gdy jest klucz: stała **`BACKUP_ENCRYPT_KEY`** w `config.php` (priorytet) **lub** ustawienie `backup_encrypt_key`. Zaszyfrowane pliki mają sufiks `.enc`.
  - ⚠ **Pułapka klucza:** klucz w `settings` leży w tej samej bazie, którą szyfruje — przy totalnej utracie serwera odtworzenie z `.enc` wymaga klucza z zewnątrz. Panel wymusza **zapisanie klucza off-site**; realny off-site to pełny backup SP.
- **Manifest integralności:** `backups/manifest.json` (rel→`{sha256,size,mtime,kind,encrypted,db_integrity}`). `backup_manifest_record()` (agent zapisuje per plik; dla bazy `integrity_check` **przed** szyfrowaniem), `backup_manifest_prune()` po rotacji.
- **Weryfikacja:** `backup_verify(deep=true)` — porównuje SHA-256 z manifestem, dla baz `PRAGMA integrity_check` (deszyfruje, gdy jest klucz). Statusy: `ok / changed / unmanifested / missing / integrity_fail / unreadable`.
- **Przywracanie:** `backup_restore_db()` (walidacja `integrity_check` **przed** podmianą + kopia bezpieczeństwa `umowy_pre-restore_*.db`), `backup_restore_archive()` (rozpakowanie uploads/certs do katalogu głównego, nadpisanie). Deszyfrują automatycznie, gdy trzeba.
- **Rozpoznawanie typu:** `backup_kind()` → `db` / `uploads` / `certs` (rozumie też warianty `.enc`).

### 4. Monitoring i alerty

- **Agent** przy błędzie zbiera przyczyny (`$err_log`) i wysyła alert; przy sukcesie zapisuje znacznik `backups/.last_run_ok`.
- **`cron/backup_monitor.php`** — dyspozytor `backup_monitor`, **codziennie 7:00–9:00**:
  - **RPO / przeterminowanie:** `backup_is_stale()` vs próg **`backup_alert_max_hours`** (domyślnie 8h) — alert, gdy brak świeżej kopii.
  - **Integralność:** `backup_verify()` — alert przy uszkodzeniu/zmianie/braku.
  - ⚠ Monitor woła `mail_queue_process()` — **nie odpalaj go z CLI podczas dev** (wyśle realną kolejkę poczty).
- **`backup_alert(title, body, dedupKey, minIntervalH)`** — do wszystkich adminów: **dzwonek** (`notif_create`, typ `backup`) **+ e-mail** (`mail_queue_add`), z deduplikacją przez `settings` (`backup_alert_*`).

### 5. Panel `admin/backups.php`

- **Dashboard „Kondycja kopii":** ostatni udany backup + wiek, status RPO (aktualny / przeterminowany), liczby kopii per typ (baza / uploads / certs), status szyfrowania.
- **Konfigurowalny próg alertu** (godziny) wprost z panelu (`backup_alert_max_hours`).
- **Widoczne sumy SHA-256** przy plikach (kopiowanie kliknięciem).
- **Automatyczna codzienna weryfikacja + prune manifestu** (przez `backup_monitor`), plus przycisk **„Zweryfikuj integralność"** na żądanie (tabela wyników).
- **Karta „Szyfrowanie kopii":** włącz (generuje klucz), podgląd/kopiowanie klucza z ostrzeżeniem, wyłącz.
- **Per kopia:** pobierz, **przywróć** (twardy confirm; baza z `integrity_check` + kopia pre-restore), usuń; badge `.enc` i typ certs. Akcje przywracania i szyfrowania trafiają do `admin_audit`.
- **„Uruchom backup teraz"** oraz **„Backup do SharePoint"** (gdy SP skonfigurowany).

### Szybka ściąga

| Chcę… | Gdzie |
|---|---|
| Włączyć szyfrowanie | Panel → karta „Szyfrowanie" (lub `BACKUP_ENCRYPT_KEY` w `config.php`) |
| Zmienić próg alertu RPO | Panel → „Alert gdy brak kopii przez … h" (`backup_alert_max_hours`) |
| Sprawdzić spójność kopii | Panel → „Zweryfikuj integralność" (lub `cron/backup_monitor.php`) |
| Przywrócić bazę | Panel → przy pliku `.db` → „Przywróć" (robi kopię pre-restore) |
| Objąć nowy katalog | `cron/agents/backup.php` (wzorem sekcji `certs/`) |

**Uwaga:** `backups/` jest w `.gitignore`. Nie commituj kopii ani `manifest.json`.
