<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/msuesSchema.php — schemat modułu „Certyfikacja MSUES 2.0” (samonaprawa za bramką szo_schema_current).
 *
 *   trainer_declarations        — oświadczenia o kwalifikacjach i kompetencjach (wiersz = wersja; series_id = id pierwszej wersji)
 *   trainer_declaration_events  — historia: utworzenie, edycje, zmiany statusu, skan, EZD (kto, kiedy, uwaga)
 *   msues_staff_documents       — dokumenty kadry do checklisty: CV, dyplomy, certyfikaty, umowy spoza rejestru
 *   msues_service_profiles      — profil usługi MSUES (grupa TI): role, analiza potrzeb, efekty, materiały, wsparcie, ewaluacja
 *   k30_pl_rooms.msues_norms    — spełnione normy sali i wyposażenia (JSON kodów)
 *   msues_service_profiles.in_catalog / catalog_* — wpis w publicznym katalogu szkoleń (MsuesCatalog)
 *
 * SQL przenośny (SQLite ↔ MySQL): znaczniki czasu liczy PHP (msuesNow()), bez funkcji dat bazy.
 * Ustawienia organizacji (tabela settings): require_trainer_formal_declaration, msues_ezd_jrwa, msues_ezd_auto, msues_launched_*.
 */
const MSUES_SETTING_REQUIRE = 'require_trainer_formal_declaration';

function msuesNow(): string
{
    return date('Y-m-d H:i:s');
}

/** Ustawienie organizacji czytane wprost z bazy (org_setting() trzyma wartość w pamięci procesu — po zmianie byłaby nieaktualna). */
function msuesSetting(string $key): string
{
    try { return (string)(db_one("SELECT value FROM settings WHERE key_ = ?", [$key])['value'] ?? ''); } catch (\Throwable $e) { return ''; }
}

function msuesMigrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (szo_schema_current('ti_msues_migrate', __FILE__)) return;
    $pk = (defined('DB_TYPE') && DB_TYPE !== 'sqlite') ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db = db();
    $db->exec("CREATE TABLE IF NOT EXISTS trainer_declarations (
        id {$pk},
        series_id INTEGER,
        version INTEGER NOT NULL DEFAULT 1,
        trainer_id INTEGER NOT NULL,
        course_id INTEGER,
        project VARCHAR(190) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'draft',
        data TEXT,
        scan_path VARCHAR(255) NOT NULL DEFAULT '',
        scan_name VARCHAR(190) NOT NULL DEFAULT '',
        scan_at VARCHAR(19),
        scan_by INTEGER,
        pdf_sha256 VARCHAR(64) NOT NULL DEFAULT '',
        ezd_sprawa_id INTEGER,
        ezd_pismo_id INTEGER,
        submitted_at VARCHAR(19),
        submitted_by INTEGER,
        decided_at VARCHAR(19),
        decided_by INTEGER,
        decided_name VARCHAR(190) NOT NULL DEFAULT '',
        decision_note TEXT,
        created_by INTEGER,
        created_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at VARCHAR(19),
        updated_at VARCHAR(19)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_trainer_decl_trainer ON trainer_declarations(trainer_id, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_trainer_decl_course ON trainer_declarations(course_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_trainer_decl_series ON trainer_declarations(series_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS trainer_declaration_events (
        id {$pk},
        declaration_id INTEGER NOT NULL,
        action VARCHAR(32) NOT NULL DEFAULT '',
        from_status VARCHAR(16) NOT NULL DEFAULT '',
        to_status VARCHAR(16) NOT NULL DEFAULT '',
        note TEXT,
        actor_id INTEGER,
        actor_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at VARCHAR(19)
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_trainer_decl_ev ON trainer_declaration_events(declaration_id)");

    $db->exec("CREATE TABLE IF NOT EXISTS msues_staff_documents (
        id {$pk},
        trainer_id INTEGER NOT NULL,
        kind VARCHAR(32) NOT NULL DEFAULT 'inne',
        title VARCHAR(255) NOT NULL DEFAULT '',
        issued_on VARCHAR(10) NOT NULL DEFAULT '',
        valid_to VARCHAR(10) NOT NULL DEFAULT '',
        file_path VARCHAR(255) NOT NULL DEFAULT '',
        file_name VARCHAR(190) NOT NULL DEFAULT '',
        mime VARCHAR(100) NOT NULL DEFAULT '',
        size INTEGER NOT NULL DEFAULT 0,
        sha256 VARCHAR(64) NOT NULL DEFAULT '',
        note TEXT,
        uploaded_by INTEGER,
        uploaded_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at VARCHAR(19),
        deleted_at VARCHAR(19),
        deleted_by INTEGER
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_msues_docs_trainer ON msues_staff_documents(trainer_id, kind)");

    // Profil usługi MSUES (grupa TI oznaczona jako usługa w standardzie): role, analiza potrzeb, efekty, materiały, wsparcie, ewaluacja
    $db->exec("CREATE TABLE IF NOT EXISTS msues_service_profiles (
        course_id INTEGER PRIMARY KEY,
        is_msues INTEGER NOT NULL DEFAULT 0,
        service_kind VARCHAR(8) NOT NULL DEFAULT 'open',
        opiekun_id INTEGER,
        koordynator_id INTEGER,
        needs_analysis_on VARCHAR(10) NOT NULL DEFAULT '',
        needs_analysis_note TEXT,
        learning_outcomes TEXT,
        requirements TEXT,
        materials_reviewed_on VARCHAR(10) NOT NULL DEFAULT '',
        materials_note TEXT,
        support_form TEXT,
        evaluation_method TEXT,
        evaluation_report_on VARCHAR(10) NOT NULL DEFAULT '',
        evaluation_summary TEXT,
        certificate_doc VARCHAR(255) NOT NULL DEFAULT '',
        updated_by INTEGER,
        updated_name VARCHAR(190) NOT NULL DEFAULT '',
        updated_at VARCHAR(19)
    )");
    // Katalog publiczny szkoleń (karty30/ti/dydaktyk/katalog.php, /katalog/ na ti.*): publikacja wpisu i jego treść
    foreach (["in_catalog INTEGER NOT NULL DEFAULT 0", "catalog_title VARCHAR(190) NOT NULL DEFAULT ''", "catalog_summary TEXT",
              "catalog_audience TEXT", "catalog_order INTEGER NOT NULL DEFAULT 0"] as $col) {
        try { $db->exec("ALTER TABLE msues_service_profiles ADD COLUMN {$col}"); } catch (\Throwable $e) {}
    }
    // Sala: spełnione normy MSUES (JSON listy kodów z MsuesRequirements::ROOM_NORMS) + kto i kiedy sprawdził
    foreach (["msues_norms TEXT", "msues_checked_at VARCHAR(19)", "msues_checked_by VARCHAR(190) NOT NULL DEFAULT ''"] as $col) {
        try { $db->exec("ALTER TABLE k30_pl_rooms ADD COLUMN {$col}"); } catch (\Throwable $e) {}
    }
}
