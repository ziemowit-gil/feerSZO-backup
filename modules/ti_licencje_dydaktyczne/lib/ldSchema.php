<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/ldSchema.php — schemat modułu „Licencje dydaktyczne (AM5) i oprogramowanie (AM7)”
 * (samonaprawa za bramką szo_schema_current; SQL przenośny SQLite ↔ MySQL, znaczniki czasu liczy PHP).
 *
 *   licenses                — sprawa / pula licencji (form_kind AM5 | AM7, numer 4021-MS_AM/NNNNNN/AB): wniosek (request_data) i decyzja
 *                             (decision_data); AM5 — utwór + licencjodawca zewnętrzny; AM7 — oprogramowanie i usługi cyfrowe: kursant podpisuje wniosek
 *                             w portalu (requester_*), placówka wydaje decyzję w PDF (decision_pdf), potem zlecenie Helpdesku IT
 *   license_assignments     — przydział licencji osobie (kursant k30_clients / współpracownik users / osoba spoza SZO), token portalu
 *   license_declarations    — oświadczenia AM5/2 i AM5/3 złożone przez portal (PDF, skrót SHA-256, IP, status weryfikacji)
 *   license_events          — dziennik obiegu (kto, kiedy, co)
 *   license_holder_changes  — zmiany danych osoby z portalu (stara/nowa wartość, status applied|pending|rejected);
 *                             dla współpracowników (users) zmiany idą dodatkowo do collaborator_data_changes jako „czeka na akceptację”
 *   license_operations      — rejestr operacji: AM11–AM13 (wnioski o licencję / zwrot / finansowanie zewnętrzne) i AM20–AM24 (zmiana płatnika,
 *                             planu, zawieszenie, cofnięcie, przedłużenie posiadanej licencji); rekord JSON w record_json
 *   license_funding         — historia płatników / finansowania posiadanych licencji (resource_key ti:N | am:N | m365:N)
 *   notification_logs       — każda próba powiadomienia (kanał, rodzaj, adresat zamaskowany, sent|failed|skipped, błąd, fallback)
 */
const LD_REALIZATION_DAYS = 7;          // realizacja wniosku AM5/1 i oświadczeń — do 7 dni od wszczęcia / przydziału
const LD_TOKEN_DAYS = 30;               // ważność linku portalu (licencjodawca, licencjobiorca)
const LD_SYMBOL = 'AM5';
const LD_CASE_PREFIX = '4021-MS_AM';   // numer sprawy: 4021-MS_AM/000123/AB (6 cyfr kolejnych + 2 litery)

function ldNow(): string
{
    return date('Y-m-d H:i:s');
}

function ldToday(): string
{
    return date('Y-m-d');
}

/** Data „dd.mm.rrrr” z Y-m-d (puste → ''). */
function ldDate(?string $d): string
{
    $d = trim((string)$d);
    if ($d === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return '';
    return $m[3] . '.' . $m[2] . '.' . $m[1];
}

/** Termin realizacji: data + 7 dni (Y-m-d). */
function ldDue(?string $from = null): string
{
    $t = $from ? strtotime($from) : time();
    return date('Y-m-d', ($t ?: time()) + LD_REALIZATION_DAYS * 86400);
}

/** Dwie litery numeru sprawy: inicjały (imię + nazwisko) bez polskich znaków; brak → 'XX'. */
function ldCaseLetters(string ...$names): string
{
    foreach ($names as $n) {
        $n = strtr(trim($n), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
                              'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z']);
        $w = array_values(array_filter(preg_split('/[^A-Za-z]+/', $n) ?: [], fn($x) => $x !== ''));
        if (count($w) >= 2) return strtoupper($w[0][0] . $w[count($w) - 1][0]);
        if (count($w) === 1 && strlen($w[0]) >= 2) return strtoupper(substr($w[0], 0, 2));
    }
    return 'XX';
}

/** Numer sprawy: 4021-MS_AM/000123/AB. */
function ldCaseNumber(int $seq, string $letters): string
{
    return LD_CASE_PREFIX . '/' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT) . '/' . $letters;
}

/** Nazwa pliku z numeru dokumentu (bez ukośników, spacji i znaków spoza ASCII). */
function ldFileName(string $number): string
{
    return trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $number) ?? '', '-') ?: 'dokument-am5';
}

function ldOrgName(): string
{
    return trim((string)org_setting('org_name')) ?: (defined('ORG_NAME') ? (string)ORG_NAME : 'Fundacja FEER');
}

function ldMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (function_exists('szo_schema_current') && szo_schema_current('ti_licencje_dydaktyczne_migrate', __FILE__)) return;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS licenses (
        id {$pk},
        case_number VARCHAR(40) NOT NULL DEFAULT '',
        case_seq INTEGER NOT NULL DEFAULT 0,
        title VARCHAR(255) NOT NULL DEFAULT '',
        work_description TEXT,
        course_id INTEGER,
        course_name VARCHAR(255) NOT NULL DEFAULT '',
        licensor_name VARCHAR(255) NOT NULL DEFAULT '',
        licensor_email VARCHAR(190) NOT NULL DEFAULT '',
        licensor_phone VARCHAR(40) NOT NULL DEFAULT '',
        licensor_address TEXT,
        applicant_name VARCHAR(255) NOT NULL DEFAULT '',
        applicant_contact TEXT,
        interested_kind VARCHAR(10) NOT NULL DEFAULT '',
        interested_id INTEGER,
        interested_name VARCHAR(190) NOT NULL DEFAULT '',
        interested_email VARCHAR(190) NOT NULL DEFAULT '',
        interested_phone VARCHAR(40) NOT NULL DEFAULT '',
        request_status VARCHAR(16) NOT NULL DEFAULT 'draft',
        request_data TEXT,
        decision_data TEXT,
        license_kind VARCHAR(16) NOT NULL DEFAULT '',
        period_from VARCHAR(10) NOT NULL DEFAULT '',
        period_to VARCHAR(10) NOT NULL DEFAULT '',
        territory VARCHAR(190) NOT NULL DEFAULT '',
        seats INTEGER NOT NULL DEFAULT 0,
        suspended INTEGER NOT NULL DEFAULT 0,
        suspended_reason TEXT,
        licensor_token_hash VARCHAR(64) NOT NULL DEFAULT '',
        licensor_token_expires VARCHAR(19),
        request_pdf VARCHAR(255) NOT NULL DEFAULT '',
        request_pdf_sha256 VARCHAR(64) NOT NULL DEFAULT '',
        started_at VARCHAR(19),
        started_by INTEGER,
        started_name VARCHAR(190) NOT NULL DEFAULT '',
        due_on VARCHAR(10) NOT NULL DEFAULT '',
        decided_at VARCHAR(19),
        decided_via VARCHAR(16) NOT NULL DEFAULT '',
        decided_ip VARCHAR(64) NOT NULL DEFAULT '',
        created_by INTEGER,
        created_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at VARCHAR(19),
        updated_at VARCHAR(19)
    )");
    // AM7 (oprogramowanie): rodzaj sprawy, podpis wniosku przez kursanta w portalu, PDF decyzji, zlecenie Helpdesku IT
    foreach (["case_number VARCHAR(40) NOT NULL DEFAULT ''", "case_seq INTEGER NOT NULL DEFAULT 0", "form_kind VARCHAR(4) NOT NULL DEFAULT 'AM5'",
              "requester_token_hash VARCHAR(64) NOT NULL DEFAULT ''", "requester_token_expires VARCHAR(19)", "requester_signed_at VARCHAR(19)",
              "requester_signed_ip VARCHAR(64) NOT NULL DEFAULT ''", "requester_signed_name VARCHAR(190) NOT NULL DEFAULT ''",
              "decision_pdf VARCHAR(255) NOT NULL DEFAULT ''", "decision_pdf_sha256 VARCHAR(64) NOT NULL DEFAULT ''", "decided_by INTEGER",
              "helpdesk_ticket_id INTEGER", "helpdesk_number VARCHAR(40) NOT NULL DEFAULT ''",
              // konto utworzone w tenancie z poziomu sprawy AM7 (Am7TenantAccount)
              "tenant_kind VARCHAR(8) NOT NULL DEFAULT ''", "tenant_upn VARCHAR(190) NOT NULL DEFAULT ''", "tenant_created_at VARCHAR(19)"] as $col) {
        try { $db->exec("ALTER TABLE licenses ADD COLUMN {$col}"); } catch (\Throwable $e) {}
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_licenses_status ON licenses(request_status)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_licenses_case_seq ON licenses(case_seq)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_licenses_token ON licenses(licensor_token_hash)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_licenses_req_token ON licenses(requester_token_hash)");

    $db->exec("CREATE TABLE IF NOT EXISTS license_assignments (
        id {$pk},
        license_id INTEGER NOT NULL,
        holder_kind VARCHAR(10) NOT NULL DEFAULT 'external',
        holder_id INTEGER,
        holder_name VARCHAR(190) NOT NULL DEFAULT '',
        holder_email VARCHAR(190) NOT NULL DEFAULT '',
        holder_phone VARCHAR(40) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'invited',
        token_hash VARCHAR(64) NOT NULL DEFAULT '',
        token_expires VARCHAR(19),
        due_on VARCHAR(10) NOT NULL DEFAULT '',
        assigned_at VARCHAR(19),
        assigned_by INTEGER,
        assigned_name VARCHAR(190) NOT NULL DEFAULT '',
        submitted_at VARCHAR(19),
        decided_at VARCHAR(19),
        decided_by INTEGER,
        decided_name VARCHAR(190) NOT NULL DEFAULT '',
        decision_note TEXT,
        revoked_at VARCHAR(19),
        revoked_reason TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_asg_license ON license_assignments(license_id, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_asg_token ON license_assignments(token_hash)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_asg_holder ON license_assignments(holder_kind, holder_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS license_declarations (
        id {$pk},
        assignment_id INTEGER NOT NULL,
        doc VARCHAR(8) NOT NULL DEFAULT '',
        number VARCHAR(64) NOT NULL DEFAULT '',
        data TEXT,
        file_path VARCHAR(255) NOT NULL DEFAULT '',
        file_mime VARCHAR(60) NOT NULL DEFAULT '',
        sha256 VARCHAR(64) NOT NULL DEFAULT '',
        signed_at VARCHAR(19),
        signed_ip VARCHAR(64) NOT NULL DEFAULT '',
        signed_ua VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        created_at VARCHAR(19)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_decl_asg ON license_declarations(assignment_id, doc)");

    $db->exec("CREATE TABLE IF NOT EXISTS license_events (
        id {$pk},
        license_id INTEGER NOT NULL,
        assignment_id INTEGER,
        action VARCHAR(40) NOT NULL DEFAULT '',
        note TEXT,
        actor_id INTEGER,
        actor_name VARCHAR(190) NOT NULL DEFAULT '',
        ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at VARCHAR(19)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_events ON license_events(license_id)");
    try { $db->exec("ALTER TABLE license_events ADD COLUMN operation_id INTEGER"); } catch (\Throwable $e) {}
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_events_op ON license_events(operation_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS license_holder_changes (
        id {$pk},
        assignment_id INTEGER NOT NULL,
        holder_kind VARCHAR(10) NOT NULL DEFAULT '',
        holder_id INTEGER,
        field VARCHAR(40) NOT NULL DEFAULT '',
        old_value TEXT,
        new_value TEXT,
        status VARCHAR(16) NOT NULL DEFAULT 'applied',
        cdc_id INTEGER,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        decided_by INTEGER,
        decided_at VARCHAR(19),
        created_at VARCHAR(19)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_changes ON license_holder_changes(assignment_id)");

    // Rejestr operacji na licencjach: wnioski AM11–AM13 i operacje cyklu życia AM20–AM24 (numer 4000-…/734350539/…)
    $db->exec("CREATE TABLE IF NOT EXISTS license_operations (
        id {$pk},
        op_seq INTEGER NOT NULL DEFAULT 0,
        number VARCHAR(80) NOT NULL DEFAULT '',
        form VARCHAR(6) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        account_kind VARCHAR(10) NOT NULL DEFAULT '',
        account_id INTEGER,
        account_name VARCHAR(190) NOT NULL DEFAULT '',
        account_login VARCHAR(190) NOT NULL DEFAULT '',
        account_role VARCHAR(40) NOT NULL DEFAULT '',
        account_email VARCHAR(190) NOT NULL DEFAULT '',
        account_phone VARCHAR(40) NOT NULL DEFAULT '',
        course_id INTEGER,
        course_name VARCHAR(255) NOT NULL DEFAULT '',
        resource_key VARCHAR(40) NOT NULL DEFAULT '',
        current_state TEXT,
        request_data TEXT,
        decision_data TEXT,
        record_json TEXT,
        proof_path VARCHAR(255) NOT NULL DEFAULT '',
        proof_name VARCHAR(190) NOT NULL DEFAULT '',
        execution_mode VARCHAR(30) NOT NULL DEFAULT '',
        execution_notes TEXT,
        helpdesk_ticket_id INTEGER,
        helpdesk_number VARCHAR(40) NOT NULL DEFAULT '',
        billing_id INTEGER,
        pdf_path VARCHAR(255) NOT NULL DEFAULT '',
        pdf_sha256 VARCHAR(64) NOT NULL DEFAULT '',
        due_on VARCHAR(10) NOT NULL DEFAULT '',
        decided_by INTEGER,
        decided_name VARCHAR(190) NOT NULL DEFAULT '',
        decided_at VARCHAR(19),
        decided_ip VARCHAR(64) NOT NULL DEFAULT '',
        executed_at VARCHAR(19),
        executed_by INTEGER,
        created_by INTEGER,
        created_name VARCHAR(190) NOT NULL DEFAULT '',
        created_ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at VARCHAR(19),
        updated_at VARCHAR(19)
    )");
    foreach (["sign_token_hash VARCHAR(64) NOT NULL DEFAULT ''", "sign_token_expires VARCHAR(19)", "signed_at VARCHAR(19)",
              "signed_ip VARCHAR(64) NOT NULL DEFAULT ''", "signed_name VARCHAR(190) NOT NULL DEFAULT ''", "signed_ua VARCHAR(255) NOT NULL DEFAULT ''"] as $col) {
        try { $db->exec("ALTER TABLE license_operations ADD COLUMN {$col}"); } catch (\Throwable $e) {}
    }
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_ops_sign ON license_operations(sign_token_hash)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_lic_ops_seq ON license_operations(op_seq)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_ops_account ON license_operations(account_kind, account_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_ops_resource ON license_operations(resource_key)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_ops_status ON license_operations(status)");

    // Historia płatników / finansowania posiadanych licencji (AM20 i inne): obowiązuje najnowszy wpis z effective_from <= dziś
    $db->exec("CREATE TABLE IF NOT EXISTS license_funding (
        id {$pk},
        resource_key VARCHAR(40) NOT NULL DEFAULT '',
        payer VARCHAR(255) NOT NULL DEFAULT '',
        funding_source VARCHAR(40) NOT NULL DEFAULT '',
        budget_position VARCHAR(190) NOT NULL DEFAULT '',
        payment_mode VARCHAR(12) NOT NULL DEFAULT '',
        amount REAL NOT NULL DEFAULT 0,
        bank_account VARCHAR(64) NOT NULL DEFAULT '',
        effective_from VARCHAR(10) NOT NULL DEFAULT '',
        operation_id INTEGER,
        created_by INTEGER,
        created_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at VARCHAR(19)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lic_funding ON license_funding(resource_key, effective_from)");

    $db->exec("CREATE TABLE IF NOT EXISTS notification_logs (
        id {$pk},
        module VARCHAR(40) NOT NULL DEFAULT '',
        ref_type VARCHAR(40) NOT NULL DEFAULT '',
        ref_id INTEGER,
        type VARCHAR(40) NOT NULL DEFAULT '',
        channel VARCHAR(8) NOT NULL DEFAULT '',
        recipient VARCHAR(190) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT '',
        error TEXT,
        is_fallback INTEGER NOT NULL DEFAULT 0,
        queue_id INTEGER,
        actor_id INTEGER,
        created_at VARCHAR(19)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_notif_logs_ref ON notification_logs(module, ref_type, ref_id)");
}
