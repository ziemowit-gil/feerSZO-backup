<?php
/**
 * includes/rekrutacja_offers.php — OGŁOSZENIA rekrutacyjne (starszy moduł).
 *
 * Ten kod mieszkał w includes/rekrutacja.php do commita 89ae6f96, w którym plik
 * przepisano na nowy moduł ZGŁOSZEŃ (funkcje rekr_*). Razem ze starą treścią
 * zniknęła klasa VolunteerModuleManager i tabele volunteer_offers /
 * volunteer_applications — a strony contracts/rekrutacja/*.php nadal ich używają
 * i od tamtej pory kończyły się błędem 500.
 *
 * Kod wraca w OSOBNYM pliku, nie w rekrutacja.php: to dwie różne rzeczy —
 * ogłoszenia (oferty wolontariatu i zgłoszenia do nich) oraz nowy pipeline
 * kandydatów. Rozdzielone nie wchodzą sobie w drogę i widać, co jest czym.
 *
 * DO ROZSTRZYGNIĘCIA: czy oba moduły mają zostać. Jeśli nowy ma zastąpić stary,
 * usuwa się contracts/rekrutacja/ razem z tym plikiem i tabelami; jeśli mają żyć
 * obok siebie — warto rozdzielić im nazwy w menu, bo dziś oba mówią „Zgłoszenia".
 */
declare(strict_types=1);

// ── Auto-migracja tabel ──────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS volunteer_offers (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        title           VARCHAR(500) NOT NULL,
        slug            VARCHAR(520),
        content         TEXT,
        status          VARCHAR(20)  NOT NULL DEFAULT 'draft',
        custom_fields   TEXT         NOT NULL DEFAULT '[]',
        max_candidates  INTEGER      DEFAULT NULL,
        published_at    DATETIME     DEFAULT NULL,
        closed_at       DATETIME     DEFAULT NULL,
        created_by      INTEGER      DEFAULT NULL,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS volunteer_applications (
        id                                  INTEGER PRIMARY KEY AUTOINCREMENT,
        volunteer_offer_id                  INTEGER NOT NULL,
        linked_person_id                    INTEGER DEFAULT NULL,
        linked_user_id                      INTEGER DEFAULT NULL,
        candidate_name                      VARCHAR(500) NOT NULL,
        candidate_email                     VARCHAR(320) NOT NULL,
        candidate_phone                     VARCHAR(50)  DEFAULT NULL,
        status                              VARCHAR(30)  NOT NULL DEFAULT 'new',
        form_data                           TEXT         NOT NULL DEFAULT '{}',
        admin_notes                         TEXT         DEFAULT NULL,
        is_simplified_communication_required INTEGER     NOT NULL DEFAULT 0,
        rejection_reason                    TEXT         DEFAULT NULL,
        interviewed_at                      DATETIME     DEFAULT NULL,
        decided_at                          DATETIME     DEFAULT NULL,
        decided_by                          INTEGER      DEFAULT NULL,
        created_at                          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at                          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (volunteer_offer_id) REFERENCES volunteer_offers(id)
    )");

    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_app_offer_email
                    ON volunteer_applications(volunteer_offer_id, candidate_email)");
    } catch (\Throwable $e) {}

    // Nowe kolumny dla volunteer_offers
    $new_offer_cols = [
        'avail_from' => "ALTER TABLE volunteer_offers ADD COLUMN avail_from DATE DEFAULT NULL",
        'avail_to'   => "ALTER TABLE volunteer_offers ADD COLUMN avail_to DATE DEFAULT NULL",
        'rodo_text'  => "ALTER TABLE volunteer_offers ADD COLUMN rodo_text TEXT DEFAULT NULL",
        // Klauzula z modułu Klauzule RODO (modules/gdpr_clauses) — ma pierwszeństwo przed rodo_text.
        'gdpr_clause_slug' => "ALTER TABLE volunteer_offers ADD COLUMN gdpr_clause_slug VARCHAR(64) DEFAULT NULL",
    ];
    foreach ($new_offer_cols as $col => $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }

    // Nowe kolumny dla volunteer_applications
    $new_app_cols = [
        'avail_from'     => "ALTER TABLE volunteer_applications ADD COLUMN avail_from DATE DEFAULT NULL",
        'avail_to'       => "ALTER TABLE volunteer_applications ADD COLUMN avail_to DATE DEFAULT NULL",
        'rodo_accepted'  => "ALTER TABLE volunteer_applications ADD COLUMN rodo_accepted INTEGER NOT NULL DEFAULT 0",
        'onboarding_id'  => "ALTER TABLE volunteer_applications ADD COLUMN onboarding_id INTEGER DEFAULT NULL",
    ];
    foreach ($new_app_cols as $col => $sql) {
        try { $pdo->exec($sql); } catch (\Throwable $e) {}
    }

    // Kolumna source_application_id w onboarding_volunteers (integracja z rekrutacją)
    try {
        $pdo->exec("ALTER TABLE onboarding_volunteers ADD COLUMN source_application_id INTEGER DEFAULT NULL");
    } catch (\Throwable $e) {}
})();

// ─────────────────────────────────────────────────────────────────────────────

class VolunteerModuleManager
{
    // ── Słowniki statusów ────────────────────────────────────────────────────

    const OFFER_STATUSES = [
        'draft'  => ['label' => 'Szkic',     'color' => 'secondary'],
        'active' => ['label' => 'Aktywne',   'color' => 'success'],
        'closed' => ['label' => 'Zamknięte', 'color' => 'danger'],
    ];

    const APP_STATUSES = [
        'new'       => ['label' => 'Nowe',          'color' => 'primary'],
        'reviewing' => ['label' => 'W trakcie',      'color' => 'info'],
        'interview' => ['label' => 'Rozmowa',        'color' => 'warning'],
        'accepted'  => ['label' => 'Zaakceptowane',  'color' => 'success'],
        'rejected'  => ['label' => 'Odrzucone',      'color' => 'danger'],
        'withdrawn' => ['label' => 'Wycofane',       'color' => 'secondary'],
    ];

    // ══════════════════════════════════════════════════════════════════════════
    //  OGŁOSZENIA (volunteer_offers)
    // ══════════════════════════════════════════════════════════════════════════

    public function createOffer(array $data): int
    {
        $now  = date('Y-m-d H:i:s');
        $slug = $this->slugify($data['title'] ?? 'offer');
        $id   = db_insert('volunteer_offers', [
            'title'          => $data['title'],
            'slug'           => $slug,
            'content'        => $data['content']        ?? '',
            'status'         => $data['status']         ?? 'draft',
            'custom_fields'  => json_encode($data['custom_fields'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
            'max_candidates' => ($data['max_candidates'] !== '' && $data['max_candidates'] !== null)
                                    ? (int)$data['max_candidates'] : null,
            'avail_from'     => ($data['avail_from'] ?? '') ?: null,
            'avail_to'       => ($data['avail_to']   ?? '') ?: null,
            'rodo_text'      => ($data['rodo_text']  ?? '') ?: null,
            'gdpr_clause_slug' => ($data['gdpr_clause_slug'] ?? '') ?: null,
            'published_at'   => ($data['status'] ?? 'draft') === 'active' ? $now : null,
            'created_by'     => $data['created_by'] ?? null,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        // Upewnij się, że slug jest unikalny — dołącz id
        $finalSlug = $slug . '-' . $id;
        db()->prepare("UPDATE volunteer_offers SET slug = ? WHERE id = ? AND slug = ?")
             ->execute([$finalSlug, $id, $slug]);
        return $id;
    }

    public function updateOffer(int $id, array $data): bool
    {
        $current = $this->getOffer($id);
        if (!$current) return false;

        $cols = []; $vals = [];
        $now  = date('Y-m-d H:i:s');

        if (array_key_exists('title', $data)) {
            $cols[] = 'title = ?';       $vals[] = $data['title'];
            $cols[] = 'slug = ?';        $vals[] = $this->slugify($data['title']) . '-' . $id;
        }
        if (array_key_exists('content', $data)) {
            $cols[] = 'content = ?';     $vals[] = $data['content'];
        }
        if (array_key_exists('status', $data)) {
            $cols[] = 'status = ?';      $vals[] = $data['status'];
            if ($data['status'] === 'active' && $current['status'] !== 'active') {
                $cols[] = 'published_at = ?'; $vals[] = $current['published_at'] ?? $now;
            }
            if ($data['status'] === 'closed' && $current['status'] !== 'closed') {
                $cols[] = 'closed_at = ?'; $vals[] = $now;
            }
        }
        if (array_key_exists('custom_fields', $data)) {
            $cols[] = 'custom_fields = ?';
            $vals[] = json_encode($data['custom_fields'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        }
        if (array_key_exists('max_candidates', $data)) {
            $cols[] = 'max_candidates = ?';
            $vals[] = ($data['max_candidates'] !== '' && $data['max_candidates'] !== null)
                          ? (int)$data['max_candidates'] : null;
        }
        if (array_key_exists('avail_from', $data)) {
            $cols[] = 'avail_from = ?';
            $vals[] = ($data['avail_from'] ?? '') ?: null;
        }
        if (array_key_exists('avail_to', $data)) {
            $cols[] = 'avail_to = ?';
            $vals[] = ($data['avail_to'] ?? '') ?: null;
        }
        if (array_key_exists('rodo_text', $data)) {
            $cols[] = 'rodo_text = ?';
            $vals[] = ($data['rodo_text'] ?? '') ?: null;
        }
        if (array_key_exists('gdpr_clause_slug', $data)) {
            $cols[] = 'gdpr_clause_slug = ?';
            $vals[] = ($data['gdpr_clause_slug'] ?? '') ?: null;
        }
        $cols[] = 'updated_at = ?'; $vals[] = $now;
        $vals[] = $id;

        db()->prepare("UPDATE volunteer_offers SET " . implode(', ', $cols) . " WHERE id = ?")
             ->execute($vals);
        return true;
    }

    public function getOffer(int $id): ?array
    {
        $row = db_one("SELECT * FROM volunteer_offers WHERE id = ?", [$id]);
        if (!$row) return null;
        $row['custom_fields'] = json_decode($row['custom_fields'] ?? '[]', true) ?: [];
        $row['app_count']     = (int)db_one(
            "SELECT COUNT(*) AS c FROM volunteer_applications WHERE volunteer_offer_id = ?", [$id]
        )['c'];
        return $row;
    }

    public function getOfferOrFail(int $id): array
    {
        $row = $this->getOffer($id);
        if (!$row) {
            flash_set('error', 'Ogłoszenie rekrutacyjne nie zostało znalezione.');
            header('Location: index.php'); exit;
        }
        return $row;
    }

    public function listOffers(string $status = '', string $q = '', int $limit = 50, int $offset = 0): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($status) { $where[] = 'o.status = ?';          $params[] = $status; }
        if ($q)      { $where[] = 'o.title LIKE ?';        $params[] = '%' . $q . '%'; }
        $params[] = $limit;
        $params[] = $offset;

        $rows = db_all(
            "SELECT o.*,
                    (SELECT COUNT(*) FROM volunteer_applications a WHERE a.volunteer_offer_id = o.id) AS app_count,
                    (SELECT COUNT(*) FROM volunteer_applications a WHERE a.volunteer_offer_id = o.id AND a.status = 'new') AS new_count
             FROM volunteer_offers o
             WHERE " . implode(' AND ', $where) . "
             ORDER BY o.created_at DESC LIMIT ? OFFSET ?",
            $params
        );
        foreach ($rows as &$r) {
            $r['custom_fields'] = json_decode($r['custom_fields'] ?? '[]', true) ?: [];
        }
        return $rows;
    }

    public function countOffers(string $status = '', string $q = ''): int
    {
        $where  = ['1=1'];
        $params = [];
        if ($status) { $where[] = 'status = ?'; $params[] = $status; }
        if ($q)      { $where[] = 'title LIKE ?'; $params[] = '%' . $q . '%'; }
        return (int)db_one(
            "SELECT COUNT(*) AS c FROM volunteer_offers WHERE " . implode(' AND ', $where),
            $params
        )['c'];
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  ZGŁOSZENIA (volunteer_applications)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Sprawdza czy kandydat już figuruje w systemie (user, person, aktywna umowa).
     */
    public function checkIfCandidateExists(string $email): array
    {
        $result = ['exists' => false, 'sources' => [], 'details' => []];
        if (!$email) return $result;

        $u = db_one("SELECT id, name, email, role FROM users WHERE email = ?", [$email]);
        if ($u) {
            $result['exists']    = true;
            $result['sources'][] = 'user';
            $result['details'][] = ['type' => 'user', 'name' => $u['name'], 'role' => $u['role']];
        }

        try {
            $p = db_one("SELECT id, imie_nazwisko FROM persons WHERE email = ?", [$email]);
            if ($p) {
                $result['exists']    = true;
                $result['sources'][] = 'person';
                $result['details'][] = ['type' => 'person', 'name' => $p['imie_nazwisko']];
            }
        } catch (\Throwable $e) {}

        try {
            $v = db_one(
                "SELECT id, numer_umowy, imie_nazwisko FROM umowy_wolontariat
                 WHERE email = ?
                   AND status IN ('aktywna','w_realizacji','zatwierdzony','zatwierdzona','aktywny','obowiazuje')
                 LIMIT 1",
                [$email]
            );
            if ($v) {
                $result['exists']    = true;
                $result['sources'][] = 'wolontariat';
                $result['details'][] = ['type' => 'wolontariat', 'name' => $v['imie_nazwisko'], 'nr' => $v['numer_umowy']];
            }
        } catch (\Throwable $e) {}

        return $result;
    }

    public function createApplication(int $offerId, array $data): array
    {
        $offer = $this->getOffer($offerId);
        if (!$offer) throw new \InvalidArgumentException('Ogłoszenie nie istnieje.');
        if ($offer['status'] !== 'active') {
            throw new \InvalidArgumentException('Zgłoszenia do tego ogłoszenia są zamknięte.');
        }
        if ($offer['max_candidates'] !== null && $offer['app_count'] >= (int)$offer['max_candidates']) {
            throw new \RuntimeException('Osiągnięto maksymalną liczbę kandydatów dla tego ogłoszenia.');
        }

        $email = strtolower(trim($data['candidate_email'] ?? ''));
        $name  = trim($data['candidate_name'] ?? '');
        if (!$email) throw new \InvalidArgumentException('Adres e-mail jest wymagany.');
        if (!$name)  throw new \InvalidArgumentException('Imię i nazwisko jest wymagane.');

        $dup = db_one(
            "SELECT id FROM volunteer_applications WHERE volunteer_offer_id = ? AND candidate_email = ?",
            [$offerId, $email]
        );
        if ($dup) throw new \RuntimeException('Zgłoszenie z tym adresem e-mail już istnieje dla tego ogłoszenia.');

        // Powiązanie z istniejącym kontem / osobą
        $exists    = $this->checkIfCandidateExists($email);
        $person_id = null;
        $user_id   = null;
        foreach ($exists['details'] as $d) {
            if ($d['type'] === 'user'   && !$user_id) {
                $u = db_one("SELECT id FROM users WHERE email = ?", [$email]);
                $user_id = $u ? (int)$u['id'] : null;
            }
            if ($d['type'] === 'person' && !$person_id) {
                $p = db_one("SELECT id FROM persons WHERE email = ?", [$email]);
                $person_id = $p ? (int)$p['id'] : null;
            }
        }

        $now = date('Y-m-d H:i:s');
        $id  = db_insert('volunteer_applications', [
            'volunteer_offer_id'              => $offerId,
            'linked_person_id'                => $person_id,
            'linked_user_id'                  => $user_id,
            'candidate_name'                  => $name,
            'candidate_email'                 => $email,
            'candidate_phone'                 => trim($data['candidate_phone'] ?? '') ?: null,
            'status'                          => 'new',
            'form_data'                       => json_encode($data['form_data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
            'is_simplified_communication_required' => (int)($data['is_simplified_communication_required'] ?? 0),
            'avail_from'                      => ($data['avail_from'] ?? '') ?: null,
            'avail_to'                        => ($data['avail_to']   ?? '') ?: null,
            'rodo_accepted'                   => (int)($data['rodo_accepted'] ?? 0),
            'created_at'                      => $now,
            'updated_at'                      => $now,
        ]);

        return [
            'id'              => $id,
            'candidate_exists'=> $exists['exists'],
            'exists_detail'   => $exists,
        ];
    }

    public function getApplication(int $id): ?array
    {
        $row = db_one(
            "SELECT a.*, o.title AS offer_title, o.status AS offer_status, o.custom_fields AS offer_custom_fields
             FROM volunteer_applications a
             JOIN volunteer_offers o ON o.id = a.volunteer_offer_id
             WHERE a.id = ?",
            [$id]
        );
        if (!$row) return null;
        $row['form_data']          = json_decode($row['form_data']          ?? '{}', true) ?: [];
        $row['offer_custom_fields']= json_decode($row['offer_custom_fields'] ?? '[]', true) ?: [];
        $row['is_known_person']    = (bool)($row['linked_person_id'] || $row['linked_user_id']);
        return $row;
    }

    public function listApplications(int $offerId, string $status = '', int $limit = 200, int $offset = 0): array
    {
        $where  = ['a.volunteer_offer_id = ?'];
        $params = [$offerId];
        if ($status) { $where[] = 'a.status = ?'; $params[] = $status; }
        $params[] = $limit;
        $params[] = $offset;

        $rows = db_all(
            "SELECT a.*,
                    CASE WHEN a.linked_person_id IS NOT NULL OR a.linked_user_id IS NOT NULL THEN 1 ELSE 0 END AS is_known_person,
                    a.onboarding_id
             FROM volunteer_applications a
             WHERE " . implode(' AND ', $where) . "
             ORDER BY a.created_at ASC LIMIT ? OFFSET ?",
            $params
        );
        foreach ($rows as &$r) {
            $r['form_data'] = json_decode($r['form_data'] ?? '{}', true) ?: [];
        }
        return $rows;
    }

    public function updateApplicationStatus(int $id, string $newStatus, int $decidedBy, array $extra = []): bool
    {
        if (!array_key_exists($newStatus, self::APP_STATUSES)) return false;
        $app = db_one("SELECT id FROM volunteer_applications WHERE id = ?", [$id]);
        if (!$app) return false;

        $cols = []; $vals = [];
        $now  = date('Y-m-d H:i:s');
        $cols[] = 'status = ?';     $vals[] = $newStatus;
        $cols[] = 'updated_at = ?'; $vals[] = $now;

        if (in_array($newStatus, ['accepted', 'rejected'], true)) {
            $cols[] = 'decided_at = ?'; $vals[] = $now;
            $cols[] = 'decided_by = ?'; $vals[] = $decidedBy;
        }
        if ($newStatus === 'interview') {
            $cols[] = 'interviewed_at = ?'; $vals[] = $now;
        }
        if (isset($extra['rejection_reason'])) {
            $cols[] = 'rejection_reason = ?'; $vals[] = $extra['rejection_reason'];
        }
        if (isset($extra['admin_notes'])) {
            $cols[] = 'admin_notes = ?'; $vals[] = $extra['admin_notes'];
        }
        $vals[] = $id;
        db()->prepare("UPDATE volunteer_applications SET " . implode(', ', $cols) . " WHERE id = ?")
             ->execute($vals);
        return true;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  HELPERY
    // ══════════════════════════════════════════════════════════════════════════

    private function slugify(string $text): string
    {
        $text = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text) ?? '');
        return trim($text, '-') ?: 'offer';
    }
}

// ── Funkcje pomocnicze ────────────────────────────────────────────────────────

function rekrutacja_offer_badge(string $status): string
{
    $cfg = VolunteerModuleManager::OFFER_STATUSES[$status]
        ?? ['label' => $status, 'color' => 'secondary'];
    return '<span class="badge bg-' . $cfg['color'] . '">' . htmlspecialchars($cfg['label']) . '</span>';
}

function rekrutacja_app_badge(string $status): string
{
    $cfg = VolunteerModuleManager::APP_STATUSES[$status]
        ?? ['label' => $status, 'color' => 'secondary'];
    return '<span class="badge bg-' . $cfg['color'] . '">' . htmlspecialchars($cfg['label']) . '</span>';
}
