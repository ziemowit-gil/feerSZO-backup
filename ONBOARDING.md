# feerSZO — ONBOARDING

Wewnętrzny system zarządzania organizacją FEER (umowy, EZD, CRM, karty30/TI, panel wolontariusza i in.).

## Stos i konwencje

- **PHP + SQLite** (jedna baza `umowy.db`, ścieżka `DB_PATH`), integracja z **Microsoft 365 / SharePoint (Graph)**.
- **Migracje w kodzie**: moduły tworzą/rozszerzają tabele przez `CREATE TABLE IF NOT EXISTS` + listę `ALTER TABLE ADD COLUMN` (no-op, gdy kolumna istnieje). Dodając kolumnę pamiętaj, by dopisać ją **i do CREATE, i do listy ALTER**.
- **Cron**: jeden dyspozytor `cron/dispatcher.php` uruchamiany co minutę; rejestruje agentów z interwałami (i opcjonalnym oknem godzinowym `schedule`).
- **UI**: głównie Bootstrap; nowe moduły stopniowo na Tailwind/Alpine.
- **Uprawnienia**: `require_login()`, `can_edit()`, `is_admin()`, `require_role('admin')`, `module_enabled('<flaga>')`.

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
