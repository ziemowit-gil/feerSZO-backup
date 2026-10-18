<?php
declare(strict_types=1);

/**
 * modules/ti_dop/lib/dopSchema.php — schemat wniosków DOP1 (zwrot nadpłaty) i DOP2 (płatność z subkonta).
 *
 *   dop_requests — wniosek: numer DOP1/NNNNNN/RRRR, uczestnik (k30_clients), dane wniosku (JSON), tryb podpisu (link | paper),
 *                  potwierdzenie przez link (token SHA-256, imię i nazwisko, czas, IP) albo skan podpisanego wniosku,
 *                  kroki obsługi (steps JSON: verify / approve / execute — kto, kiedy, IP, dane), powiązania z księgą
 *                  (ledger_ids, overpayment_ids), PDF, kopia w teczce kursanta (k30_ti_sfile)
 *   dop_events   — dziennik wniosku
 * Powiadomienia logują się w notification_logs (module 'ti_dop').
 */
require_once dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/lib/ldSchema.php';

const DOP_DIR = 'ti_dop';

function dopMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    ldMigrate();   // notification_logs + pomocnicze ldNow()/ldDate()
    if (function_exists('szo_schema_current') && szo_schema_current('ti_dop_migrate', __FILE__)) return;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS dop_requests (
        id {$pk},
        form VARCHAR(6) NOT NULL DEFAULT '',
        seq INTEGER NOT NULL DEFAULT 0,
        number VARCHAR(40) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'signing',
        client_id INTEGER NOT NULL,
        client_name VARCHAR(190) NOT NULL DEFAULT '',
        client_email VARCHAR(190) NOT NULL DEFAULT '',
        client_phone VARCHAR(40) NOT NULL DEFAULT '',
        place VARCHAR(120) NOT NULL DEFAULT '',
        data TEXT,
        sign_mode VARCHAR(8) NOT NULL DEFAULT 'link',
        sign_token_hash VARCHAR(64) NOT NULL DEFAULT '',
        sign_token_expires VARCHAR(19),
        signed_at VARCHAR(19),
        signed_name VARCHAR(190) NOT NULL DEFAULT '',
        signed_ip VARCHAR(64) NOT NULL DEFAULT '',
        scan_path VARCHAR(255) NOT NULL DEFAULT '',
        scan_name VARCHAR(190) NOT NULL DEFAULT '',
        attachments TEXT,
        steps TEXT,
        ledger_ids TEXT,
        pdf_path VARCHAR(255) NOT NULL DEFAULT '',
        folder_file_id INTEGER,
        due_on VARCHAR(10) NOT NULL DEFAULT '',
        created_by INTEGER,
        created_name VARCHAR(190) NOT NULL DEFAULT '',
        created_ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at VARCHAR(19),
        updated_at VARCHAR(19)
    )");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_dop_seq ON dop_requests(form, seq)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dop_client ON dop_requests(client_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dop_status ON dop_requests(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dop_token ON dop_requests(sign_token_hash)");
    $db->exec("CREATE TABLE IF NOT EXISTS dop_events (
        id {$pk},
        request_id INTEGER NOT NULL,
        action VARCHAR(40) NOT NULL DEFAULT '',
        note TEXT,
        actor_id INTEGER,
        actor_name VARCHAR(190) NOT NULL DEFAULT '',
        ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at VARCHAR(19)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dop_events ON dop_events(request_id)");
}
