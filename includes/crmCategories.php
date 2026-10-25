<?php
/**
 * includes/crmCategories.php — kategorie kontaktów CRM (podział „kim jest dla organizacji”).
 *
 * STATUS (crm_statuses) opisuje etap relacji (prospect → aktywny → nieaktywny). KATEGORIA opisuje rolę: wolontariusz,
 * zleceniobiorca, darczyńca, klient, partner, beneficjent… — kontakt może mieć kilka naraz.
 * Kategorie z regułą automatyczną są przeliczane z danych (umowy po e-mailu, darowizny, faktury, beneficjenci, status
 * „partner”); ręcznie nadane (source='manual') nigdy nie są zdejmowane przez przeliczenie.
 * Wolontariusz / były wolontariusz rozdziela stan umów: z choć jedną aktywną umową vs wyłącznie zakończone.
 */
require_once __DIR__ . '/../modules/contract_status/lib/contractStatuses.php';

const CRM_CATEGORY_SEED = [
    ['wolontariusz',      'Wolontariusz',          '#2E844A', 'vol_active'],
    ['byly_wolontariusz', 'Były wolontariusz',     '#6B7280', 'vol_former'],
    ['zleceniobiorca',    'Zleceniobiorca',        '#0176D3', 'zlec_active'],
    ['pracownik',         'Pracownik',             '#032D60', 'praca_active'],
    ['darczynca',         'Darczyńca',             '#C2410C', 'donor'],
    ['klient',            'Klient',                '#7F2B8B', 'client'],
    ['partner',           'Partner',               '#0F766E', 'partner'],
    ['beneficjent',       'Beneficjent',           '#B45309', 'beneficiary'],
    ['instytucja',        'Urząd / instytucja',    '#374151', null],
    ['media',             'Media',                 '#9333EA', null],
    ['dostawca',          'Dostawca',              '#4B5563', null],
];

function crm_categories_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS crm_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL,
        color TEXT NOT NULL DEFAULT '#6B7280',
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        auto_rule TEXT
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS crm_contact_categories (
        contact_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        source TEXT NOT NULL DEFAULT 'manual',
        created_at TEXT,
        PRIMARY KEY (contact_id, category_id)
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ccc_cat ON crm_contact_categories(category_id)");
    if ((int)(db_one("SELECT COUNT(*) AS n FROM crm_categories")['n'] ?? 0) === 0) {
        $i = 0;
        foreach (CRM_CATEGORY_SEED as [$slug, $label, $color, $rule]) {
            db()->prepare("INSERT OR IGNORE INTO crm_categories (slug,label,color,sort_order,auto_rule) VALUES (?,?,?,?,?)")
                ->execute([$slug, $label, $color, ++$i, $rule]);
        }
    }
}

function crm_categories(bool $only_active = true): array {
    crm_categories_schema();
    return db_all("SELECT c.*, (SELECT COUNT(*) FROM crm_contact_categories x JOIN crm_contacts k ON k.id=x.contact_id AND k.crm_active=1 WHERE x.category_id=c.id) AS cnt
                   FROM crm_categories c " . ($only_active ? "WHERE c.is_active=1 " : "") . "ORDER BY c.sort_order, c.id");
}

function _crm_cat_in(array $codes): string {
    return implode(',', array_map(fn($c) => "'" . str_replace("'", "''", (string)$c) . "'", $codes ?: ['-']));
}

/** SELECT identyfikatorów kontaktów dla reguły automatycznej (null = brak reguły). */
function crm_category_rule_sql(string $rule): ?string {
    $act = _crm_cat_in(contractStatusesInGroup('draft', 'active', 'blocked'));
    $cls = _crm_cat_in(contractStatusesInGroup('closed', 'cancelled'));
    $byMail = fn(string $t, string $in) => "SELECT DISTINCT cc.id FROM {$t} u JOIN crm_contacts cc ON LOWER(TRIM(cc.email))=LOWER(TRIM(u.email)) AND cc.crm_active=1
                                             WHERE COALESCE(TRIM(u.email),'')<>'' AND u.status IN ({$in})";
    return match ($rule) {
        'vol_active'   => $byMail('umowy_wolontariat', $act),
        'vol_former'   => $byMail('umowy_wolontariat', $cls) . " AND cc.id NOT IN (" . $byMail('umowy_wolontariat', $act) . ")",
        'zlec_active'  => $byMail('umowy_zlecenie', $act) . " UNION " . $byMail('umowy_dzielo', $act),
        'praca_active' => $byMail('umowy_praca', $act),
        'donor'        => "SELECT DISTINCT contact_id AS id FROM donations WHERE contact_id IS NOT NULL",
        'client'       => "SELECT DISTINCT contact_id AS id FROM invoices WHERE contact_id IS NOT NULL",
        'partner'      => "SELECT id FROM crm_contacts WHERE crm_active=1 AND status='" . CRM_SERVICES_STATUS . "'",
        'beneficiary'  => "SELECT DISTINCT contact_id AS id FROM crm_beneficiary_links WHERE contact_id IS NOT NULL",
        default        => null,
    };
}

/** Przelicza kategorie automatyczne. @return array<string,array{added:int,removed:int}> */
function crm_categories_recalc(): array {
    crm_categories_schema();
    $out = [];
    $now = date('Y-m-d H:i:s');
    foreach (db_all("SELECT id, slug, auto_rule FROM crm_categories WHERE auto_rule IS NOT NULL AND auto_rule<>''") as $cat) {
        $sql = crm_category_rule_sql((string)$cat['auto_rule']);
        if (!$sql) continue;
        try {
            $ids = array_map('intval', array_filter(array_column(db_all($sql), 'id')));
        } catch (\Throwable $e) { continue; }   // brak tabeli w tej instalacji
        $have = array_map('intval', array_column(db_all("SELECT contact_id FROM crm_contact_categories WHERE category_id=? AND source='auto'", [(int)$cat['id']]), 'contact_id'));
        $add = array_diff($ids, $have);
        $del = array_diff($have, $ids);
        $ins = db()->prepare("INSERT OR IGNORE INTO crm_contact_categories (contact_id, category_id, source, created_at) VALUES (?,?, 'auto', ?)");
        foreach ($add as $cid) $ins->execute([$cid, (int)$cat['id'], $now]);
        $rm = db()->prepare("DELETE FROM crm_contact_categories WHERE contact_id=? AND category_id=? AND source='auto'");
        foreach ($del as $cid) $rm->execute([$cid, (int)$cat['id']]);
        $out[$cat['slug']] = ['added' => count($add), 'removed' => count($del)];
    }
    return $out;
}

/** Kategorie jednego kontaktu: [['slug','label','color','source']]. */
function crm_contact_categories(int $contact_id): array {
    crm_categories_schema();
    return db_all("SELECT c.slug, c.label, c.color, x.source FROM crm_contact_categories x JOIN crm_categories c ON c.id=x.category_id
                   WHERE x.contact_id=? AND c.is_active=1 ORDER BY c.sort_order", [$contact_id]);
}

function crm_contact_category_set(int $contact_id, string $slug, bool $on): bool {
    crm_categories_schema();
    $c = db_one("SELECT id FROM crm_categories WHERE slug=?", [$slug]);
    if (!$c) return false;
    if ($on) {
        db()->prepare("INSERT INTO crm_contact_categories (contact_id, category_id, source, created_at) VALUES (?,?,'manual',?)
                       ON CONFLICT(contact_id, category_id) DO UPDATE SET source='manual'")->execute([$contact_id, (int)$c['id'], date('Y-m-d H:i:s')]);
    } else {
        db()->prepare("DELETE FROM crm_contact_categories WHERE contact_id=? AND category_id=?")->execute([$contact_id, (int)$c['id']]);
    }
    return true;
}

/** @return list<int> aktywne kontakty z którąkolwiek z podanych kategorii (po slugach). */
function crm_category_contact_ids(array $slugs): array {
    crm_categories_schema();
    $slugs = array_values(array_filter(array_map('strval', $slugs)));
    if (!$slugs) return [];
    $ph = implode(',', array_fill(0, count($slugs), '?'));
    return array_map('intval', array_column(db_all(
        "SELECT DISTINCT x.contact_id AS id FROM crm_contact_categories x JOIN crm_categories c ON c.id=x.category_id
         JOIN crm_contacts k ON k.id=x.contact_id AND k.crm_active=1 WHERE c.slug IN ($ph)", $slugs), 'id'));
}
