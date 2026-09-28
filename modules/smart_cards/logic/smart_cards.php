<?php
/**
 * modules/smart_cards/logic/smart_cards.php — karty stykowe (identyfikacyjne i dostępowe do biura).
 *
 * Tabele:
 *  - card_templates         wzory kart (design_config = JSON wyglądu), jeden domyślny,
 *  - access_zones           strefy dostępu z poziomem bezpieczeństwa 1–5,
 *  - card_applications      wnioski o wydanie karty (pending → approved | rejected),
 *  - smart_cards            fizyczne karty: card_uid (UID chipu) UNIQUE, status, ważność,
 *  - card_zone_permissions  uprawnienia do stref — przypięte ALBO do wzoru (dziedziczą
 *                           je wszystkie karty z tego wzoru), ALBO do pojedynczej karty.
 *
 * Cykl życia karty:
 *   active ─blokada─▶ blocked ─odblokowanie─▶ active
 *     │                 │
 *     ├──zagubienie─────┴──▶ lost (stan końcowy)
 *     └──upływ ważności──▶ expired ─przedłużenie─▶ active
 *
 * Bezpieczeństwo stref:
 *  - dostęp ma wyłącznie karta active z niewygasłą datą ważności (checkAccess
 *    sprawdza oba warunki niezależnie od tego, czy wygaszanie zdążyło zmienić status),
 *  - blokada natychmiast zdejmuje indywidualne uprawnienia karty (odkładane do
 *    smart_cards.suspended_zones, przywracane przy odblokowaniu), a uprawnienia
 *    ze wzoru przestają działać, bo karta nie jest aktywna,
 *  - zagubienie kasuje indywidualne uprawnienia bezpowrotnie — odnaleziona karta
 *    nie wraca do obiegu, wydaje się nową.
 *
 * Zatwierdzenie wniosku w jednej transakcji: zmienia status wniosku (warunkowo —
 * odporne na podwójne kliknięcie), tworzy kartę z UID odczytanym z czytnika albo
 * wygenerowanym, nadaje wskazane strefy i zapisuje ślady w audit_logs.
 *
 * Programowanie NFC (mikroaplikacja modules/smart_cards/nfc/):
 *  - karta z UID wygenerowanym (uid_source='generated') dostaje przy programowaniu
 *    prawdziwy UID odczytany z chipu (serialNumber w Web NFC),
 *  - na chip trafia rekord NDEF z adresem weryfikacji i jednorazowo wylosowanym
 *    tokenem; w bazie tylko jego SHA-256 (nfc_token_hash),
 *  - weryfikacja wymaga zgodności OBU: UID chipu i tokenu — skopiowanie samego
 *    rekordu NDEF na inny tag nie wystarczy (klon UID na kartach „magic” nadal
 *    możliwy — to ograniczenie kart stykowych/NFC bez kryptografii, nie aplikacji).
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/audit_logs/logic/audit_logs.php';

const SCARD_STATUSES = [
    'active'  => ['label' => 'Aktywna',      'plural' => 'Aktywne',      'icon' => 'bi-shield-check'],
    'blocked' => ['label' => 'Zablokowana',  'plural' => 'Zablokowane',  'icon' => 'bi-lock'],
    'expired' => ['label' => 'Wygasła',      'plural' => 'Wygasłe',      'icon' => 'bi-hourglass-bottom'],
    'lost'    => ['label' => 'Zagubiona',    'plural' => 'Zagubione',    'icon' => 'bi-question-octagon'],
];

const SCARD_APP_STATUSES = [
    'pending'  => ['label' => 'Oczekuje',     'icon' => 'bi-hourglass-split'],
    'approved' => ['label' => 'Zatwierdzony', 'icon' => 'bi-check2-circle'],
    'rejected' => ['label' => 'Odrzucony',    'icon' => 'bi-x-circle'],
];

const SCARD_LEVELS = [
    1 => 'Ogólnodostępna',
    2 => 'Wewnętrzna',
    3 => 'Ograniczona',
    4 => 'Poufna',
    5 => 'Krytyczna',
];

const SCARD_PATTERNS = ['none' => 'Brak', 'waves' => 'Fale', 'grid' => 'Siatka', 'circles' => 'Okręgi', 'lines' => 'Linie'];
const SCARD_CHIPS    = ['gold' => 'Złoty', 'silver' => 'Srebrny'];

const SCARD_DEFAULT_DESIGN = [
    'bg_from' => '#1e3a8a',
    'bg_to'   => '#0f172a',
    'angle'   => 135,
    'text'    => '#ffffff',
    'accent'  => '#38bdf8',
    'chip'    => 'gold',
    'pattern' => 'waves',
    'label'   => 'KARTA DOSTĘPU',
];

const SCARD_DEFAULT_VALIDITY_YEARS = 2;
const SCARD_MAX_VALIDITY_YEARS     = 10;

function smart_cards_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS card_templates (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        design_config TEXT NOT NULL DEFAULT '{}',
        is_default    INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME
    )");
    // co najwyżej jeden wzór domyślny — wymuszone przez bazę, nie tylko przez kod
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_card_templates_default ON card_templates(is_default) WHERE is_default = 1");

    $pdo->exec("CREATE TABLE IF NOT EXISTS access_zones (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        zone_name      TEXT NOT NULL UNIQUE COLLATE NOCASE,
        description    TEXT,
        security_level INTEGER NOT NULL DEFAULT 1 CHECK (security_level BETWEEN 1 AND 5),
        is_active      INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS card_applications (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id       INTEGER NOT NULL,
        reason        TEXT NOT NULL,
        status        TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by    INTEGER,
        decided_by    INTEGER,
        decided_at    DATETIME,
        decision_note TEXT,
        card_id       INTEGER REFERENCES smart_cards(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_card_apps_status ON card_applications(status, created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_card_apps_user   ON card_applications(user_id)");
    // jeden oczekujący wniosek na osobę
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_card_apps_pending ON card_applications(user_id) WHERE status = 'pending'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS smart_cards (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        card_uid        TEXT NOT NULL UNIQUE,
        template_id     INTEGER REFERENCES card_templates(id) ON DELETE RESTRICT,
        user_id         INTEGER NOT NULL,
        status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','blocked','expired','lost')),
        expires_at      DATETIME NOT NULL,
        application_id  INTEGER,
        issued_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        issued_by       INTEGER,
        status_reason   TEXT,
        status_changed_at DATETIME,
        suspended_zones TEXT
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_smart_cards_user    ON smart_cards(user_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_smart_cards_status  ON smart_cards(status, expires_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_smart_cards_tpl     ON smart_cards(template_id)");
    // v2: programowanie NFC
    $cols = array_column($pdo->query("PRAGMA table_info(smart_cards)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach ([
        'uid_source'     => "TEXT NOT NULL DEFAULT 'generated'",
        'programmed_at'  => 'DATETIME',
        'programmed_by'  => 'INTEGER',
        'nfc_token_hash' => 'TEXT',
    ] as $col => $def) {
        if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE smart_cards ADD COLUMN {$col} {$def}");
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_smart_cards_token ON smart_cards(nfc_token_hash)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS card_zone_permissions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        card_id     INTEGER REFERENCES smart_cards(id) ON DELETE CASCADE,
        template_id INTEGER REFERENCES card_templates(id) ON DELETE CASCADE,
        zone_id     INTEGER NOT NULL REFERENCES access_zones(id) ON DELETE CASCADE,
        granted_by  INTEGER,
        granted_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        CHECK ((card_id IS NULL) <> (template_id IS NULL))
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_czp_card ON card_zone_permissions(card_id, zone_id) WHERE card_id IS NOT NULL");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_czp_tpl  ON card_zone_permissions(template_id, zone_id) WHERE template_id IS NOT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_czp_zone ON card_zone_permissions(zone_id)");

    audit_logs_migrate();

    // Pierwsze uruchomienie: wzór domyślny, żeby wniosek dało się od razu zatwierdzić
    if (!(int)$pdo->query("SELECT COUNT(*) FROM card_templates")->fetchColumn()) {
        $pdo->prepare("INSERT INTO card_templates (name, design_config, is_default) VALUES (?, ?, 1)")
            ->execute(['Standardowa', json_encode(SCARD_DEFAULT_DESIGN, JSON_UNESCAPED_UNICODE)]);
    }
}

/** Wygląd wzoru z JSON — brakujące/nieprawidłowe pola zastępowane domyślnymi. */
function scard_design(?string $json): array {
    $d = json_decode((string)$json, true);
    return scard_normalize_design(is_array($d) ? $d : []);
}

function scard_normalize_design(array $in): array {
    $d = SCARD_DEFAULT_DESIGN;
    foreach (['bg_from', 'bg_to', 'text', 'accent'] as $k) {
        if (isset($in[$k]) && is_string($in[$k]) && preg_match('/^#[0-9a-fA-F]{6}$/', $in[$k])) $d[$k] = strtolower($in[$k]);
    }
    if (isset($in['angle']) && is_numeric($in['angle'])) $d['angle'] = max(0, min(360, (int)$in['angle']));
    if (isset($in['chip'], SCARD_CHIPS[$in['chip']])) $d['chip'] = $in['chip'];
    if (isset($in['pattern'], SCARD_PATTERNS[$in['pattern']])) $d['pattern'] = $in['pattern'];
    if (isset($in['label']) && is_string($in['label'])) $d['label'] = mb_substr(trim($in['label']), 0, 32);
    return $d;
}

/** UID do wyświetlenia w grupach po 2 bajty: 04A1 B2C3 D4E5 F6. */
function scard_uid_display(string $uid): string {
    $hex = str_replace(':', '', $uid);
    return trim(implode(' ', str_split($hex, 4)));
}

/** Błąd walidacji / reguły biznesowej — komunikat do pokazania użytkownikowi. */
class SmartCardException extends RuntimeException {}

class SmartCardService
{
    private PDO $pdo;
    private int $userId;

    public function __construct(?array $user = null)
    {
        smart_cards_migrate();
        $this->pdo    = db();
        $this->userId = (int)($user['id'] ?? 0);
    }

    // ── Wzory kart ────────────────────────────────────────────────────────────

    public function templates(): array
    {
        $rows = $this->pdo->query(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM smart_cards c WHERE c.template_id = t.id) AS cards_total,
                    (SELECT COUNT(*) FROM smart_cards c WHERE c.template_id = t.id AND c.status = 'active') AS cards_active
             FROM card_templates t ORDER BY t.is_default DESC, t.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['design']   = scard_design($r['design_config']);
            $r['zone_ids'] = $this->templateZoneIds((int)$r['id']);
        }
        return $rows;
    }

    public function template(int $id): ?array
    {
        $s = $this->pdo->prepare("SELECT * FROM card_templates WHERE id = ?");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['design'] = scard_design($r['design_config']);
        $r['zone_ids'] = $this->templateZoneIds($id);
        return $r;
    }

    public function defaultTemplateId(): int
    {
        return (int)$this->pdo->query("SELECT id FROM card_templates WHERE is_default = 1")->fetchColumn();
    }

    /** Zapis wzoru (nowy gdy $id = null) wraz ze strefami dziedziczonymi przez karty tego wzoru. */
    public function saveTemplate(?int $id, string $name, array $design, bool $isDefault, array $zoneIds): int
    {
        $name = mb_substr(trim($name), 0, 80);
        if ($name === '') throw new SmartCardException('Podaj nazwę wzoru karty.');
        $design  = scard_normalize_design($design);
        $zoneIds = $this->validZoneIds($zoneIds);
        $now = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            if ($id) {
                $old = $this->template($id);
                if (!$old) throw new SmartCardException('Wzór nie istnieje.');
                if ($old['is_default'] && !$isDefault) {
                    throw new SmartCardException('Nie można odznaczyć wzoru domyślnego — wskaż jako domyślny inny wzór.');
                }
            }
            if ($isDefault) $this->pdo->exec("UPDATE card_templates SET is_default = 0 WHERE is_default = 1");
            $json = json_encode($design, JSON_UNESCAPED_UNICODE);
            if ($id) {
                $this->pdo->prepare("UPDATE card_templates SET name = ?, design_config = ?, is_default = ?, updated_at = ? WHERE id = ?")
                    ->execute([$name, $json, $isDefault ? 1 : 0, $now, $id]);
            } else {
                $this->pdo->prepare("INSERT INTO card_templates (name, design_config, is_default, created_at) VALUES (?, ?, ?, ?)")
                    ->execute([$name, $json, $isDefault ? 1 : 0, $now]);
                $id = (int)$this->pdo->lastInsertId();
            }
            $before = $this->templateZoneIds($id);
            $this->pdo->prepare("DELETE FROM card_zone_permissions WHERE template_id = ?")->execute([$id]);
            $ins = $this->pdo->prepare("INSERT INTO card_zone_permissions (template_id, zone_id, granted_by, granted_at) VALUES (?, ?, ?, ?)");
            foreach ($zoneIds as $z) $ins->execute([$id, $z, $this->userId ?: null, $now]);

            $this->audit('smart_cards.template_saved', [
                'template_id'   => $id,
                'name'          => $name,
                'is_default'    => $isDefault ? 1 : 0,
                'zones_added'   => implode(',', array_diff($zoneIds, $before)),
                'zones_removed' => implode(',', array_diff($before, $zoneIds)),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public function deleteTemplate(int $id): void
    {
        $t = $this->template($id);
        if (!$t) throw new SmartCardException('Wzór nie istnieje.');
        if ($t['is_default']) throw new SmartCardException('Nie można usunąć wzoru domyślnego.');
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM smart_cards WHERE template_id = ?");
        $s->execute([$id]);
        if ((int)$s->fetchColumn()) throw new SmartCardException('Wzór jest używany przez wydane karty — nie można go usunąć.');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM card_templates WHERE id = ?")->execute([$id]);
            $this->audit('smart_cards.template_deleted', ['template_id' => $id, 'name' => $t['name']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function templateZoneIds(int $templateId): array
    {
        $s = $this->pdo->prepare("SELECT zone_id FROM card_zone_permissions WHERE template_id = ? ORDER BY zone_id");
        $s->execute([$templateId]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── Strefy dostępu ────────────────────────────────────────────────────────

    public function zones(bool $activeOnly = false): array
    {
        return $this->pdo->query(
            "SELECT z.*,
                    (SELECT COUNT(*) FROM card_zone_permissions p WHERE p.zone_id = z.id AND p.card_id IS NOT NULL) AS cards_direct,
                    (SELECT COUNT(*) FROM card_zone_permissions p WHERE p.zone_id = z.id AND p.template_id IS NOT NULL) AS templates
             FROM access_zones z" . ($activeOnly ? " WHERE z.is_active = 1" : '') . "
             ORDER BY z.security_level, z.zone_name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveZone(?int $id, string $name, string $description, int $level, bool $active): int
    {
        $name = mb_substr(trim($name), 0, 80);
        $description = mb_substr(trim($description), 0, 500);
        if ($name === '') throw new SmartCardException('Podaj nazwę strefy.');
        if (!isset(SCARD_LEVELS[$level])) throw new SmartCardException('Poziom bezpieczeństwa musi być w zakresie 1–5.');

        $dup = $this->pdo->prepare("SELECT id FROM access_zones WHERE zone_name = ? COLLATE NOCASE AND id <> ?");
        $dup->execute([$name, (int)$id]);
        if ($dup->fetchColumn()) throw new SmartCardException('Strefa o nazwie „' . $name . '” już istnieje.');

        $this->pdo->beginTransaction();
        try {
            if ($id) {
                $s = $this->pdo->prepare("SELECT * FROM access_zones WHERE id = ?");
                $s->execute([$id]);
                $old = $s->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new SmartCardException('Strefa nie istnieje.');
                $this->pdo->prepare("UPDATE access_zones SET zone_name = ?, description = ?, security_level = ?, is_active = ? WHERE id = ?")
                    ->execute([$name, $description !== '' ? $description : null, $level, $active ? 1 : 0, $id]);
                $this->audit('smart_cards.zone_updated', [
                    'zone_id' => $id, 'name' => $name,
                    'level'   => (int)$old['security_level'] !== $level ? $old['security_level'] . '→' . $level : null,
                    'active'  => (int)$old['is_active'] !== (int)$active ? ($active ? 'włączona' : 'wyłączona') : null,
                ]);
            } else {
                $this->pdo->prepare("INSERT INTO access_zones (zone_name, description, security_level, is_active) VALUES (?, ?, ?, ?)")
                    ->execute([$name, $description !== '' ? $description : null, $level, $active ? 1 : 0]);
                $id = (int)$this->pdo->lastInsertId();
                $this->audit('smart_cards.zone_created', ['zone_id' => $id, 'name' => $name, 'level' => $level]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Usunięcie strefy tylko bez przypisań — w użyciu można ją wyłączyć (dostęp znika od razu). */
    public function deleteZone(int $id): void
    {
        $s = $this->pdo->prepare("SELECT zone_name FROM access_zones WHERE id = ?");
        $s->execute([$id]);
        $name = $s->fetchColumn();
        if ($name === false) throw new SmartCardException('Strefa nie istnieje.');
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM card_zone_permissions WHERE zone_id = ?");
        $c->execute([$id]);
        if ((int)$c->fetchColumn()) {
            throw new SmartCardException('Strefa „' . $name . '” jest przypisana do kart lub wzorów. Wyłącz ją zamiast usuwać — dostęp zniknie natychmiast, a historia zostanie.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM access_zones WHERE id = ?")->execute([$id]);
            $this->audit('smart_cards.zone_deleted', ['zone_id' => $id, 'name' => $name]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return int[] tylko istniejące i aktywne strefy */
    private function validZoneIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (!$ids) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->pdo->prepare("SELECT id FROM access_zones WHERE is_active = 1 AND id IN ({$ph})");
        $s->execute($ids);
        $ok = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        if (count($ok) !== count($ids)) throw new SmartCardException('Część wskazanych stref nie istnieje lub jest wyłączona.');
        sort($ok);
        return $ok;
    }

    // ── Wnioski ───────────────────────────────────────────────────────────────

    public function submitApplication(int $userId, string $reason): int
    {
        $reason = mb_substr(trim($reason), 0, 2000);
        if ($userId <= 0 || !$this->userExists($userId)) throw new SmartCardException('Wskaż osobę, dla której składany jest wniosek.');
        if (mb_strlen($reason) < 5) throw new SmartCardException('Uzasadnij wniosek (min. 5 znaków), np. „nowy pracownik — dostęp do biura”.');

        $this->pdo->beginTransaction();
        try {
            $p = $this->pdo->prepare("SELECT COUNT(*) FROM card_applications WHERE user_id = ? AND status = 'pending'");
            $p->execute([$userId]);
            if ((int)$p->fetchColumn()) throw new SmartCardException('Ta osoba ma już oczekujący wniosek — poczekaj na decyzję.');
            $this->pdo->prepare("INSERT INTO card_applications (user_id, reason, status, created_at, created_by) VALUES (?, ?, 'pending', ?, ?)")
                ->execute([$userId, $reason, date('Y-m-d H:i:s'), $this->userId ?: null]);
            $id = (int)$this->pdo->lastInsertId();
            $this->audit('smart_cards.application_submitted', ['application_id' => $id, 'for_user_id' => $userId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public function applications(string $status = '', int $limit = 200): array
    {
        $w = isset(SCARD_APP_STATUSES[$status]) ? 'WHERE a.status = ?' : '';
        $s = $this->pdo->prepare(
            "SELECT a.*, u.name AS user_name, u.email AS user_email, d.name AS decided_by_name,
                    c.card_uid, c.status AS card_status,
                    (SELECT COUNT(*) FROM smart_cards x WHERE x.user_id = a.user_id AND x.status IN ('active','blocked')) AS user_live_cards
             FROM card_applications a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN users d ON d.id = a.decided_by
             LEFT JOIN smart_cards c ON c.id = a.card_id
             {$w}
             ORDER BY CASE a.status WHEN 'pending' THEN 0 ELSE 1 END, a.created_at DESC LIMIT ?"
        );
        $params = $w ? [$status, $limit] : [$limit];
        foreach ($params as $i => $v) $s->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Zatwierdzenie wniosku = wydanie karty. $uid — odczyt z czytnika albo '' (wygeneruj).
     * $replaceExisting — dotychczasowe karty aktywne/zablokowane tej osoby zostają
     * zablokowane z adnotacją o zastąpieniu; bez tej zgody zatwierdzenie jest odrzucane.
     */
    public function approveApplication(int $appId, int $templateId, string $expiresAt, array $zoneIds, string $uid = '', bool $replaceExisting = false, string $note = ''): int
    {
        $tpl = $this->template($templateId);
        if (!$tpl) throw new SmartCardException('Wybierz wzór karty.');
        $expires = self::parseExpiry($expiresAt);
        $zoneIds = $this->validZoneIds($zoneIds);
        $uid     = $uid !== '' ? self::normalizeUid($uid) : '';
        $note    = mb_substr(trim($note), 0, 1000);
        $now     = date('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare("SELECT * FROM card_applications WHERE id = ?");
            $s->execute([$appId]);
            $app = $s->fetch(PDO::FETCH_ASSOC);
            if (!$app) throw new SmartCardException('Wniosek nie istnieje.');
            if ($app['status'] !== 'pending') throw new SmartCardException('Wniosek został już rozpatrzony (' . mb_strtolower(SCARD_APP_STATUSES[$app['status']]['label']) . ').');

            $live = $this->pdo->prepare("SELECT * FROM smart_cards WHERE user_id = ? AND status IN ('active','blocked')");
            $live->execute([(int)$app['user_id']]);
            $liveCards = $live->fetchAll(PDO::FETCH_ASSOC);
            if ($liveCards && !$replaceExisting) {
                throw new SmartCardException('Ta osoba ma już kartę (' . implode(', ', array_map(fn($c) => scard_uid_display($c['card_uid']), $liveCards))
                    . '). Zaznacz „Zastąp dotychczasową kartę”, aby ją unieważnić przy wydaniu nowej.');
            }

            $uidFromReader = $uid !== '';
            if (!$uidFromReader) $uid = $this->generateUid();
            $u = $this->pdo->prepare("SELECT COUNT(*) FROM smart_cards WHERE card_uid = ?");
            $u->execute([$uid]);
            if ((int)$u->fetchColumn()) throw new SmartCardException('Karta o UID ' . scard_uid_display($uid) . ' jest już w ewidencji.');

            // warunkowa zmiana statusu — drugi równoległy „Zatwierdź” nic nie zrobi
            $upd = $this->pdo->prepare("UPDATE card_applications SET status = 'approved', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ? AND status = 'pending'");
            $upd->execute([$this->userId ?: null, $now, $note !== '' ? $note : null, $appId]);
            if ($upd->rowCount() !== 1) throw new SmartCardException('Wniosek został właśnie rozpatrzony przez kogoś innego.');

            foreach ($liveCards as $old) {
                $this->setStatus($old, 'blocked', 'Zastąpiona nową kartą (wniosek #' . $appId . ')', $now);
            }

            $this->pdo->prepare(
                "INSERT INTO smart_cards (card_uid, uid_source, template_id, user_id, status, expires_at, application_id, issued_at, issued_by, status_changed_at)
                 VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)"
            )->execute([$uid, $uidFromReader ? 'reader' : 'generated', $templateId, (int)$app['user_id'], $expires, $appId, $now, $this->userId ?: null, $now]);
            $cardId = (int)$this->pdo->lastInsertId();

            $ins = $this->pdo->prepare("INSERT INTO card_zone_permissions (card_id, zone_id, granted_by, granted_at) VALUES (?, ?, ?, ?)");
            foreach ($zoneIds as $z) $ins->execute([$cardId, $z, $this->userId ?: null, $now]);

            $this->pdo->prepare("UPDATE card_applications SET card_id = ? WHERE id = ?")->execute([$cardId, $appId]);

            $this->audit('smart_cards.application_approved', ['application_id' => $appId, 'for_user_id' => (int)$app['user_id'], 'card_id' => $cardId, 'note' => $note]);
            $this->audit('smart_cards.card_issued', [
                'card_id' => $cardId, 'card_uid' => $uid, 'for_user_id' => (int)$app['user_id'],
                'template_id' => $templateId, 'expires_at' => substr($expires, 0, 10),
                'zones' => implode(',', $zoneIds), 'replaced' => implode(',', array_column($liveCards, 'id')),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $cardId;
    }

    public function rejectApplication(int $appId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') throw new SmartCardException('Podaj powód odrzucenia — zobaczy go wnioskujący.');
        $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare("UPDATE card_applications SET status = 'rejected', decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ? AND status = 'pending'");
            $upd->execute([$this->userId ?: null, date('Y-m-d H:i:s'), $reason, $appId]);
            if ($upd->rowCount() !== 1) throw new SmartCardException('Wniosek nie istnieje albo został już rozpatrzony.');
            $this->audit('smart_cards.application_rejected', ['application_id' => $appId, 'reason' => $reason]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ── Karty ─────────────────────────────────────────────────────────────────

    /** @return array{rows:array, total:int} */
    public function cards(string $status, string $q, int $limit, int $offset): array
    {
        $this->expireOverdue();
        $where = ['1=1'];
        $p = [];
        if (isset(SCARD_STATUSES[$status])) { $where[] = 'c.status = ?'; $p[] = $status; }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $hex  = '%' . addcslashes(strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $q)), '%_\\') . '%';
            $where[] = "(u.name LIKE ? ESCAPE '\\' OR u.email LIKE ? ESCAPE '\\' OR REPLACE(c.card_uid, ':', '') LIKE ? ESCAPE '\\')";
            array_push($p, $like, $like, $hex);
        }
        $w = implode(' AND ', $where);
        $from = "FROM smart_cards c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN card_templates t ON t.id = c.template_id WHERE {$w}";

        $cnt = $this->pdo->prepare("SELECT COUNT(*) {$from}");
        $cnt->execute($p);
        $total = (int)$cnt->fetchColumn();

        $s = $this->pdo->prepare("SELECT c.*, u.name AS user_name, u.email AS user_email, t.name AS template_name, t.design_config
                                  {$from} ORDER BY CASE c.status WHEN 'active' THEN 0 WHEN 'blocked' THEN 1 ELSE 2 END, c.issued_at DESC LIMIT ? OFFSET ?");
        foreach ($p as $i => $v) $s->bindValue($i + 1, $v);
        $s->bindValue(count($p) + 1, $limit, PDO::PARAM_INT);
        $s->bindValue(count($p) + 2, $offset, PDO::PARAM_INT);
        $s->execute();
        return ['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    }

    public function card(int $id): ?array
    {
        $this->expireOverdue();
        $s = $this->pdo->prepare("SELECT c.*, u.name AS user_name, u.email AS user_email, t.name AS template_name, t.design_config,
                                         ib.name AS issued_by_name
                                  FROM smart_cards c
                                  LEFT JOIN users u  ON u.id = c.user_id
                                  LEFT JOIN users ib ON ib.id = c.issued_by
                                  LEFT JOIN card_templates t ON t.id = c.template_id
                                  WHERE c.id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function userCards(int $userId): array
    {
        $this->expireOverdue();
        $s = $this->pdo->prepare("SELECT c.*, t.name AS template_name, t.design_config FROM smart_cards c
                                  LEFT JOIN card_templates t ON t.id = c.template_id
                                  WHERE c.user_id = ? ORDER BY CASE c.status WHEN 'active' THEN 0 ELSE 1 END, c.issued_at DESC");
        $s->execute([$userId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function userApplications(int $userId): array
    {
        $s = $this->pdo->prepare("SELECT a.*, c.card_uid FROM card_applications a LEFT JOIN smart_cards c ON c.id = a.card_id
                                  WHERE a.user_id = ? ORDER BY a.created_at DESC");
        $s->execute([$userId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function block(int $cardId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') throw new SmartCardException('Podaj powód blokady.');
        $this->transition($cardId, 'blocked', ['active'], $reason);
    }

    public function unblock(int $cardId, string $note = ''): void
    {
        $this->transition($cardId, 'active', ['blocked'], mb_substr(trim($note), 0, 1000));
    }

    public function markLost(int $cardId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if ($reason === '') throw new SmartCardException('Opisz okoliczności zagubienia (kiedy, gdzie).');
        $this->transition($cardId, 'lost', ['active', 'blocked', 'expired'], $reason);
    }

    /** Przedłużenie ważności — karta aktywna albo wygasła (wygasła wraca do aktywnych). */
    public function renew(int $cardId, string $expiresAt): void
    {
        $expires = self::parseExpiry($expiresAt);
        $this->pdo->beginTransaction();
        try {
            $card = $this->lockCard($cardId);
            if (!in_array($card['status'], ['active', 'expired'], true)) {
                throw new SmartCardException('Przedłużyć można tylko kartę aktywną lub wygasłą (ta jest: ' . mb_strtolower(SCARD_STATUSES[$card['status']]['label']) . ').');
            }
            if ($expires <= $card['expires_at']) throw new SmartCardException('Nowa data ważności musi być późniejsza niż obecna.');
            $now = date('Y-m-d H:i:s');
            $this->pdo->prepare("UPDATE smart_cards SET expires_at = ?, status = 'active', status_changed_at = CASE WHEN status <> 'active' THEN ? ELSE status_changed_at END WHERE id = ?")
                ->execute([$expires, $now, $cardId]);
            $this->audit('smart_cards.card_renewed', [
                'card_id' => $cardId, 'card_uid' => $card['card_uid'],
                'from' => substr($card['expires_at'], 0, 10), 'to' => substr($expires, 0, 10),
                'reactivated' => $card['status'] === 'expired' ? 1 : null,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ── Uprawnienia do stref ──────────────────────────────────────────────────

    /**
     * Strefy karty: z wzoru (source=template) i indywidualne (source=card).
     * 'effective' = czy uprawnienie działa teraz (karta aktywna, ważna, strefa włączona).
     */
    public function cardZones(array $card): array
    {
        $s = $this->pdo->prepare(
            "SELECT z.*, MAX(p.card_id IS NOT NULL) AS direct, MAX(p.template_id IS NOT NULL) AS inherited, MIN(p.granted_at) AS granted_at
             FROM card_zone_permissions p JOIN access_zones z ON z.id = p.zone_id
             WHERE p.card_id = ? OR p.template_id = ?
             GROUP BY z.id ORDER BY z.security_level DESC, z.zone_name"
        );
        $s->execute([(int)$card['id'], (int)$card['template_id']]);
        $live = $this->isLive($card);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $z) {
            $z['effective'] = $live && (int)$z['is_active'] === 1;
            $out[] = $z;
        }
        return $out;
    }

    /** Strefy zawieszone przy blokadzie (wrócą po odblokowaniu). */
    public function suspendedZones(array $card): array
    {
        $ids = json_decode((string)$card['suspended_zones'], true);
        if (!is_array($ids) || !$ids) return [];
        $ids = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $s = $this->pdo->prepare("SELECT * FROM access_zones WHERE id IN ({$ph}) ORDER BY zone_name");
        $s->execute($ids);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function grantZone(int $cardId, int $zoneId): void
    {
        [$zoneId] = $this->validZoneIds([$zoneId]) ?: [0];
        if (!$zoneId) throw new SmartCardException('Wybierz strefę.');
        $this->pdo->beginTransaction();
        try {
            $card = $this->lockCard($cardId);
            if ($card['status'] !== 'active' || !$this->isLive($card)) {
                throw new SmartCardException('Uprawnienia można nadawać tylko aktywnej, ważnej karcie.');
            }
            $ins = $this->pdo->prepare("INSERT OR IGNORE INTO card_zone_permissions (card_id, zone_id, granted_by, granted_at) VALUES (?, ?, ?, ?)");
            $ins->execute([$cardId, $zoneId, $this->userId ?: null, date('Y-m-d H:i:s')]);
            if ($ins->rowCount() !== 1) throw new SmartCardException('Karta ma już tę strefę.');
            $this->audit('smart_cards.zone_granted', ['card_id' => $cardId, 'card_uid' => $card['card_uid'], 'zone_id' => $zoneId, 'zone' => $this->zoneName($zoneId)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function revokeZone(int $cardId, int $zoneId): void
    {
        $this->pdo->beginTransaction();
        try {
            $card = $this->lockCard($cardId);
            $del = $this->pdo->prepare("DELETE FROM card_zone_permissions WHERE card_id = ? AND zone_id = ?");
            $del->execute([$cardId, $zoneId]);
            if ($del->rowCount() !== 1) throw new SmartCardException('Karta nie ma indywidualnego uprawnienia do tej strefy (strefy ze wzoru zmienia się we wzorze).');
            $this->audit('smart_cards.zone_revoked', ['card_id' => $cardId, 'card_uid' => $card['card_uid'], 'zone_id' => $zoneId, 'zone' => $this->zoneName($zoneId)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Czy karta o danym UID otwiera strefę — punkt decyzyjny dla czytników / testera.
     * @return array{granted:bool, reason:string, card:?array}
     */
    public function checkAccess(string $uid, int $zoneId, bool $log = true): array
    {
        $this->expireOverdue();
        try { $uid = self::normalizeUid($uid); } catch (SmartCardException $e) { return ['granted' => false, 'reason' => $e->getMessage(), 'card' => null]; }
        $s = $this->pdo->prepare("SELECT c.*, u.name AS user_name FROM smart_cards c LEFT JOIN users u ON u.id = c.user_id WHERE c.card_uid = ?");
        $s->execute([$uid]);
        $card = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        $z = $this->pdo->prepare("SELECT * FROM access_zones WHERE id = ?");
        $z->execute([$zoneId]);
        $zone = $z->fetch(PDO::FETCH_ASSOC);

        $reason = '';
        if (!$zone)                                   $reason = 'Nieznana strefa.';
        elseif (!(int)$zone['is_active'])             $reason = 'Strefa jest wyłączona.';
        elseif (!$card)                               $reason = 'Karta nie figuruje w ewidencji.';
        elseif ($card['status'] !== 'active')         $reason = 'Karta nieaktywna: ' . mb_strtolower(SCARD_STATUSES[$card['status']]['label']) . '.';
        elseif (!$this->isLive($card))                $reason = 'Karta straciła ważność.';
        else {
            $p = $this->pdo->prepare("SELECT 1 FROM card_zone_permissions WHERE zone_id = ? AND (card_id = ? OR template_id = ?) LIMIT 1");
            $p->execute([$zoneId, (int)$card['id'], (int)$card['template_id']]);
            if (!$p->fetchColumn()) $reason = 'Brak uprawnienia do strefy.';
        }
        $granted = $reason === '';
        if ($log) {
            $this->audit($granted ? 'smart_cards.access_granted' : 'smart_cards.access_denied', [
                'card_id' => $card['id'] ?? null, 'card_uid' => $uid, 'zone_id' => $zoneId, 'zone' => $zone['zone_name'] ?? null,
                'reason' => $granted ? null : $reason,
            ]);
        }
        return ['granted' => $granted, 'reason' => $granted ? 'Dostęp przyznany.' : $reason, 'card' => $card];
    }

    // ── Wygaszanie, statystyki, historia ──────────────────────────────────────

    /** Aktywne karty po terminie → expired (z wpisem audytowym per karta). */
    public function expireOverdue(): int
    {
        $now = date('Y-m-d H:i:s');
        $s = $this->pdo->prepare("SELECT id, card_uid, expires_at FROM smart_cards WHERE status = 'active' AND expires_at < ?");
        $s->execute([$now]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return 0;
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            $upd = $this->pdo->prepare("UPDATE smart_cards SET status = 'expired', status_changed_at = ?, status_reason = 'Upływ terminu ważności' WHERE id = ? AND status = 'active'");
            $n = 0;
            foreach ($rows as $r) {
                $upd->execute([$now, $r['id']]);
                if ($upd->rowCount()) {
                    $n++;
                    audit_log('smart_cards.card_expired', ['card_id' => (int)$r['id'], 'card_uid' => $r['card_uid'], 'expires_at' => substr($r['expires_at'], 0, 10)], 0);
                }
            }
            if ($own) $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($own) $this->pdo->rollBack();
            throw $e;
        }
        return $n;
    }

    public function stats(): array
    {
        $this->expireOverdue();
        $out = array_fill_keys(array_keys(SCARD_STATUSES), 0);
        foreach ($this->pdo->query("SELECT status, COUNT(*) c FROM smart_cards GROUP BY status") as $r) $out[$r['status']] = (int)$r['c'];
        $out['total']   = array_sum($out);
        $out['pending'] = (int)$this->pdo->query("SELECT COUNT(*) FROM card_applications WHERE status = 'pending'")->fetchColumn();
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM smart_cards WHERE status = 'active' AND expires_at < ?");
        $s->execute([date('Y-m-d H:i:s', strtotime('+30 days'))]);
        $out['expiring'] = (int)$s->fetchColumn();
        return $out;
    }

    /** Zdarzenia karty ze wspólnego audit_logs (JSON1: details.card_id). */
    public function history(int $cardId): array
    {
        $s = $this->pdo->prepare(
            "SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.action LIKE 'smart\\_cards.%' ESCAPE '\\' AND json_valid(a.details) AND json_extract(a.details, '$.card_id') = CAST(? AS INTEGER)
             ORDER BY a.id DESC LIMIT 200"
        );
        $s->execute([$cardId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function users(): array
    {
        return $this->pdo->query("SELECT id, name, email FROM users WHERE COALESCE(is_active, 1) = 1 ORDER BY name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Programowanie NFC ─────────────────────────────────────────────────────

    /** Aktywne karty czekające na zaprogramowanie chipu (najpierw najstarsze). */
    public function toProgram(): array
    {
        $this->expireOverdue();
        return $this->pdo->query(
            "SELECT c.id, c.card_uid, c.uid_source, c.expires_at, c.issued_at, u.name AS user_name, u.email AS user_email,
                    t.name AS template_name, t.design_config
             FROM smart_cards c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN card_templates t ON t.id = c.template_id
             WHERE c.status = 'active' AND c.programmed_at IS NULL
             ORDER BY c.issued_at"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Krok 1: chip przyłożony — wiąże jego UID z kartą i losuje token do zapisania w NDEF.
     * Karta z UID wygenerowanym przyjmuje UID chipu; z UID z czytnika — musi się zgadzać.
     * Ponowne wywołanie (np. nieudany zapis) unieważnia poprzedni token.
     * @return array{token:string, card_uid:string, url:string}
     */
    public function prepareProgramming(int $cardId, string $chipUid): array
    {
        $chipUid = self::normalizeUid($chipUid);
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->pdo->beginTransaction();
        try {
            $card = $this->lockCard($cardId);
            if ($card['status'] !== 'active' || !$this->isLive($card)) throw new SmartCardException('Programować można tylko aktywną, ważną kartę.');
            if ($card['programmed_at']) throw new SmartCardException('Karta jest już zaprogramowana. Do nowego chipu wydaj nową kartę (wniosek).');
            if ($card['card_uid'] !== $chipUid) {
                if ($card['uid_source'] === 'reader') {
                    throw new SmartCardException('To inny chip: karta ma UID ' . scard_uid_display($card['card_uid']) . ', przyłożono ' . scard_uid_display($chipUid) . '.');
                }
                $d = $this->pdo->prepare("SELECT id FROM smart_cards WHERE card_uid = ? AND id <> ?");
                $d->execute([$chipUid, $cardId]);
                if ($other = $d->fetchColumn()) throw new SmartCardException('Ten chip jest już przypisany do innej karty (#' . $other . ').');
            }
            $this->pdo->prepare("UPDATE smart_cards SET card_uid = ?, uid_source = 'reader', nfc_token_hash = ? WHERE id = ?")
                ->execute([$chipUid, hash('sha256', $token), $cardId]);
            $this->audit('smart_cards.nfc_prepared', [
                'card_id' => $cardId, 'card_uid' => $chipUid,
                'uid_rebound' => $card['card_uid'] !== $chipUid ? $card['card_uid'] . ' → ' . $chipUid : null,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['token' => $token, 'card_uid' => $chipUid, 'url' => self::verifyUrl($token)];
    }

    /** Krok 2: zapis NDEF się powiódł — karta oznaczona jako zaprogramowana. */
    public function confirmProgramming(int $cardId, string $chipUid, string $token, bool $locked): void
    {
        $chipUid = self::normalizeUid($chipUid);
        $this->pdo->beginTransaction();
        try {
            $card = $this->lockCard($cardId);
            if ($card['card_uid'] !== $chipUid || !$card['nfc_token_hash'] || !hash_equals($card['nfc_token_hash'], hash('sha256', $token))) {
                throw new SmartCardException('Potwierdzenie nie pasuje do przygotowanego zapisu — zaprogramuj kartę ponownie.');
            }
            if ($card['programmed_at']) throw new SmartCardException('Karta jest już zaprogramowana.');
            $this->pdo->prepare("UPDATE smart_cards SET programmed_at = ?, programmed_by = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $this->userId ?: null, $cardId]);
            $this->audit('smart_cards.nfc_programmed', ['card_id' => $cardId, 'card_uid' => $chipUid, 'read_only' => $locked ? 1 : 0]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Odczyt chipu: UID + (opcjonalnie) token z NDEF → karta i ocena autentyczności.
     * @return array{card:?array, authentic:?bool, message:string}
     */
    public function identifyTag(string $chipUid, string $token = ''): array
    {
        $this->expireOverdue();
        try { $chipUid = self::normalizeUid($chipUid); } catch (SmartCardException $e) { return ['card' => null, 'authentic' => false, 'message' => $e->getMessage()]; }
        $s = $this->pdo->prepare("SELECT c.*, u.name AS user_name, u.email AS user_email, t.name AS template_name, t.design_config
                                  FROM smart_cards c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN card_templates t ON t.id = c.template_id
                                  WHERE c.card_uid = ?");
        $s->execute([$chipUid]);
        $card = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$card) return ['card' => null, 'authentic' => false, 'message' => 'Chip ' . scard_uid_display($chipUid) . ' nie figuruje w ewidencji.'];
        if (!$card['programmed_at']) return ['card' => $card, 'authentic' => null, 'message' => 'Karta w ewidencji, ale chip nie został jeszcze zaprogramowany.'];
        $ok = $token !== '' && $card['nfc_token_hash'] && hash_equals($card['nfc_token_hash'], hash('sha256', $token));
        return ['card' => $card, 'authentic' => $ok, 'message' => $ok ? 'Chip autentyczny — UID i token zgodne.' : 'UWAGA: token na chipie nie zgadza się z ewidencją (możliwa kopia lub nadpisany chip).'];
    }

    /** Publiczna weryfikacja po samym tokenie z NDEF (bez danych osobowych). */
    public function verifyToken(string $token): ?array
    {
        if ($token === '' || strlen($token) > 64) return null;
        $this->expireOverdue();
        $s = $this->pdo->prepare("SELECT status, expires_at FROM smart_cards WHERE nfc_token_hash = ? AND programmed_at IS NOT NULL");
        $s->execute([hash('sha256', $token)]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function verifyUrl(string $token): string
    {
        return rtrim(APP_URL, '/') . '/modules/smart_cards/v.php?t=' . rawurlencode($token);
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────

    /** UID z czytnika: 4, 7 lub 10 bajtów hex (dowolne separatory) → „04:A1:B2:…”. */
    public static function normalizeUid(string $raw): string
    {
        $hex = strtoupper(preg_replace('/[\s:\-]/', '', trim($raw)));
        if (!preg_match('/^[0-9A-F]+$/', $hex) || !in_array(strlen($hex), [8, 14, 20], true)) {
            throw new SmartCardException('UID karty to 4, 7 lub 10 bajtów w zapisie szesnastkowym (np. 04:A1:B2:C3:D4:E5:F6).');
        }
        return implode(':', str_split($hex, 2));
    }

    /** Data ważności (Y-m-d) → koniec dnia; w przyszłości, maks. SCARD_MAX_VALIDITY_YEARS lat. */
    public static function parseExpiry(string $date): string
    {
        $d = DateTime::createFromFormat('!Y-m-d', trim($date));
        if (!$d || $d->format('Y-m-d') !== trim($date)) throw new SmartCardException('Podaj prawidłową datę ważności.');
        if ($d->format('Y-m-d') <= date('Y-m-d')) throw new SmartCardException('Data ważności musi być w przyszłości.');
        if ($d > new DateTime('+' . SCARD_MAX_VALIDITY_YEARS . ' years')) throw new SmartCardException('Karta może być ważna najwyżej ' . SCARD_MAX_VALIDITY_YEARS . ' lat.');
        return $d->format('Y-m-d') . ' 23:59:59';
    }

    public static function defaultExpiry(): string
    {
        return date('Y-m-d', strtotime('+' . SCARD_DEFAULT_VALIDITY_YEARS . ' years'));
    }

    private function isLive(array $card): bool
    {
        return $card['status'] === 'active' && $card['expires_at'] >= date('Y-m-d H:i:s');
    }

    /**
     * Zmiana statusu z kontrolą przejścia — wspólna dla blokady, odblokowania, zagubienia.
     * @param string[] $from dozwolone statusy wyjściowe
     */
    private function transition(int $cardId, string $to, array $from, string $reason): void
    {
        $this->pdo->beginTransaction();
        try {
            $card = $this->lockCard($cardId);
            if (!in_array($card['status'], $from, true)) {
                throw new SmartCardException('Nie można zmienić statusu karty z „' . mb_strtolower(SCARD_STATUSES[$card['status']]['label'])
                    . '” na „' . mb_strtolower(SCARD_STATUSES[$to]['label']) . '”.');
            }
            if ($to === 'active' && $card['expires_at'] < date('Y-m-d H:i:s')) {
                throw new SmartCardException('Karta straciła ważność — zamiast odblokowania przedłuż ją albo wydaj nową.');
            }
            $this->setStatus($card, $to, $reason, date('Y-m-d H:i:s'));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Zmiana statusu wewnątrz otwartej transakcji + skutki dla uprawnień:
     *  blocked → indywidualne strefy odkładane do suspended_zones i zdejmowane,
     *  active (z blocked) → przywrócenie odłożonych stref, które wciąż są włączone,
     *  lost → indywidualne strefy kasowane bezpowrotnie.
     */
    private function setStatus(array $card, string $to, string $reason, string $now): void
    {
        $cardId = (int)$card['id'];
        $direct = $this->pdo->prepare("SELECT zone_id FROM card_zone_permissions WHERE card_id = ?");
        $direct->execute([$cardId]);
        $directIds = array_map('intval', $direct->fetchAll(PDO::FETCH_COLUMN));
        $suspended = json_decode((string)$card['suspended_zones'], true);
        $suspended = is_array($suspended) ? array_map('intval', $suspended) : [];

        $newSuspended = $card['suspended_zones'];
        $restored = [];
        if ($to === 'blocked') {
            $newSuspended = json_encode(array_values(array_unique(array_merge($suspended, $directIds))));
            $this->pdo->prepare("DELETE FROM card_zone_permissions WHERE card_id = ?")->execute([$cardId]);
        } elseif ($to === 'lost') {
            $newSuspended = null;
            $this->pdo->prepare("DELETE FROM card_zone_permissions WHERE card_id = ?")->execute([$cardId]);
        } elseif ($to === 'active' && $suspended) {
            $ph = implode(',', array_fill(0, count($suspended), '?'));
            $ok = $this->pdo->prepare("SELECT id FROM access_zones WHERE is_active = 1 AND id IN ({$ph})");
            $ok->execute($suspended);
            $ins = $this->pdo->prepare("INSERT OR IGNORE INTO card_zone_permissions (card_id, zone_id, granted_by, granted_at) VALUES (?, ?, ?, ?)");
            foreach ($ok->fetchAll(PDO::FETCH_COLUMN) as $z) { $ins->execute([$cardId, (int)$z, $this->userId ?: null, $now]); $restored[] = (int)$z; }
            $newSuspended = null;
        }

        $upd = $this->pdo->prepare("UPDATE smart_cards SET status = ?, status_reason = ?, status_changed_at = ?, suspended_zones = ? WHERE id = ? AND status = ?");
        $upd->execute([$to, $reason !== '' ? $reason : null, $now, $newSuspended, $cardId, $card['status']]);
        if ($upd->rowCount() !== 1) throw new SmartCardException('Status karty zmienił się w trakcie operacji. Odśwież stronę.');

        $action = ['blocked' => 'card_blocked', 'lost' => 'card_lost', 'active' => 'card_unblocked'][$to] ?? 'card_status';
        $this->audit('smart_cards.' . $action, [
            'card_id' => $cardId, 'card_uid' => $card['card_uid'], 'for_user_id' => (int)$card['user_id'],
            'from' => $card['status'], 'to' => $to, 'reason' => $reason,
            'zones_suspended' => $to === 'blocked' ? implode(',', $directIds) : null,
            'zones_revoked'   => $to === 'lost' ? implode(',', $directIds) : null,
            'zones_restored'  => $restored ? implode(',', $restored) : null,
        ]);
    }

    private function lockCard(int $cardId): array
    {
        // SQLite blokuje zapis na poziomie bazy; odczyt w transakcji + warunkowy UPDATE wystarcza
        $s = $this->pdo->prepare("SELECT * FROM smart_cards WHERE id = ?");
        $s->execute([$cardId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new SmartCardException('Karta nie istnieje.');
        return $c;
    }

    private function generateUid(): string
    {
        $chk = $this->pdo->prepare("SELECT COUNT(*) FROM smart_cards WHERE card_uid = ?");
        for ($i = 0; $i < 10; $i++) {
            // 7-bajtowy UID jak w chipach NFC/MIFARE (pierwszy bajt 04 = producent NXP)
            $uid = implode(':', str_split('04' . strtoupper(bin2hex(random_bytes(6))), 2));
            $chk->execute([$uid]);
            if (!(int)$chk->fetchColumn()) return $uid;
        }
        throw new RuntimeException('Nie udało się wygenerować unikalnego UID karty.');
    }

    private function userExists(int $id): bool
    {
        $s = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ?");
        $s->execute([$id]);
        return (bool)$s->fetchColumn();
    }

    private function zoneName(int $id): string
    {
        $s = $this->pdo->prepare("SELECT zone_name FROM access_zones WHERE id = ?");
        $s->execute([$id]);
        return (string)$s->fetchColumn();
    }

    private function audit(string $action, array $details): void
    {
        audit_log($action, array_filter($details, fn($v) => $v !== null && $v !== ''), $this->userId ?: null);
    }
}
