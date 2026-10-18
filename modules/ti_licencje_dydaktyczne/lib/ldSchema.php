<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/ldSchema.php — schemat modułu „Licencje dydaktyczne (AM5) i Office 365 (AM7)”
 * (samonaprawa za bramką szo_schema_current; SQL przenośny SQLite ↔ MySQL, znaczniki czasu liczy PHP).
 *
 *   licenses                — sprawa / pula licencji (form_kind AM5 | AM7, numer 4021-MS_AM/NNNNNN/AB): wniosek (request_data) i decyzja
 *                             (decision_data); AM5 — utwór + licencjodawca zewnętrzny; AM7 — Office 365: kursant podpisuje wniosek
 *                             w portalu (requester_*), placówka wydaje decyzję w PDF (decision_pdf), potem zlecenie Helpdesku IT
 *   license_assignments     — przydział licencji osobie (kursant k30_clients / współpracownik users / osoba spoza SZO), token portalu
 *   license_declarations    — oświadczenia AM5/2 i AM5/3 złożone przez portal (PDF, skrót SHA-256, IP, status weryfikacji)
 *   license_events          — dziennik obiegu (kto, kiedy, co) — audyt MSUES 2.0
 *   license_holder_changes  — zmiany danych osoby z portalu (stara/nowa wartość, status applied|pending|rejected);
 *                             dla współpracowników (users) zmiany idą dodatkowo do collaborator_data_changes jako „czeka na akceptację”
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
    // AM7 (Office 365): rodzaj sprawy, podpis wniosku przez kursanta w portalu, PDF decyzji, zlecenie Helpdesku IT
    foreach (["case_number VARCHAR(40) NOT NULL DEFAULT ''", "case_seq INTEGER NOT NULL DEFAULT 0", "form_kind VARCHAR(4) NOT NULL DEFAULT 'AM5'",
              "requester_token_hash VARCHAR(64) NOT NULL DEFAULT ''", "requester_token_expires VARCHAR(19)", "requester_signed_at VARCHAR(19)",
              "requester_signed_ip VARCHAR(64) NOT NULL DEFAULT ''", "requester_signed_name VARCHAR(190) NOT NULL DEFAULT ''",
              "decision_pdf VARCHAR(255) NOT NULL DEFAULT ''", "decision_pdf_sha256 VARCHAR(64) NOT NULL DEFAULT ''", "decided_by INTEGER",
              "helpdesk_ticket_id INTEGER", "helpdesk_number VARCHAR(40) NOT NULL DEFAULT ''"] as $col) {
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
