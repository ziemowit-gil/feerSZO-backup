<?php
/**
 * Moduł Zwrot Kosztów Wolontariatu
 * ─────────────────────────────────
 * Obsługuje rejestrację, weryfikację i wypłatę wniosków o zwrot kosztów.
 * Obsługuje tryb "bez umowy" (admin bypass), faktury KSeF i powiadomienia email.
 */

declare(strict_types=1);

// ── Auto-migracja tabel ────────────────────────────────────────────────────
(function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();

    // Główna tabela — DROP + CREATE gdy pusta (jednorazowa przebudowa schematu)
    $cnt = 0;
    try { $cnt = (int)$pdo->query("SELECT COUNT(*) FROM zwroty_kosztow")->fetchColumn(); } catch (\Throwable $e) {}
    if ($cnt === 0) {
        try { $pdo->exec("DROP TABLE IF EXISTS zwroty_kosztow"); } catch (\Throwable $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS zwroty_kosztow (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,

        -- Numer wniosku (nadawany przy tworzeniu)
        nr_wniosku           VARCHAR(30) UNIQUE,
        nr_rok               INTEGER NOT NULL,
        nr_miesiac           INTEGER NOT NULL,
        nr_seq               INTEGER NOT NULL,

        -- Powiązania (umowa_id = NULL gdy tryb bez_umowy)
        umowa_type           VARCHAR(30) NOT NULL DEFAULT 'wolontariat',
        umowa_id             INTEGER,
        bez_umowy            INTEGER NOT NULL DEFAULT 0,
        person_id            INTEGER,
        wnioskodawca_id      INTEGER NOT NULL,
        zlozone_przez_id     INTEGER,           -- admin który kliknął Złóż w imieniu

        -- Treść wniosku
        tytul                VARCHAR(500) NOT NULL,
        opis                 TEXT,
        kwota                DECIMAL(10,2) NOT NULL,
        waluta               VARCHAR(3) NOT NULL DEFAULT 'PLN',
        data_wydatku         DATE NOT NULL,
        kategoria            VARCHAR(100),
        zalaczniki           TEXT NOT NULL DEFAULT '[]',

        -- Faktura KSeF
        faktura_elektroniczna INTEGER NOT NULL DEFAULT 0,  -- wolontariusz: mam e-fakturę
        ksef_numer           VARCHAR(100),                 -- admin wpisuje numer KSeF
        ksef_data            DATE,
        ksef_nip_sprzedawcy  VARCHAR(10),

        -- Workflow
        status               VARCHAR(30) NOT NULL DEFAULT 'oczekuje',

        -- Weryfikacja merytoryczna
        weryfikujacy_id      INTEGER,
        data_weryfikacji     DATETIME,
        weryfikacja_uwagi    TEXT,

        -- Zatwierdzenie
        zatwierdzajacy_id    INTEGER,
        data_zatwierdzenia   DATETIME,
        zatwierdzenie_uwagi  TEXT,

        -- Odrzucenie
        odrzucajacy_id       INTEGER,
        data_odrzucenia      DATETIME,
        odrzucenie_powod     TEXT,

        -- Wypłata (status końcowy)
        data_wyplaty         DATE,
        forma_wyplaty        VARCHAR(30),
        nr_przelewu          VARCHAR(100),
        wyplacajacy_id       INTEGER,
        archived_at          DATETIME,

        -- Meta
        created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at           DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Bezpieczny licznik numerów (per rok+miesiąc)
    $pdo->exec("CREATE TABLE IF NOT EXISTS zwroty_numery (
        rok     INTEGER NOT NULL,
        miesiac INTEGER NOT NULL,
        seq     INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (rok, miesiac)
    )");

    // Log zdarzeń
    $pdo->exec("CREATE TABLE IF NOT EXISTS zwroty_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id  INTEGER NOT NULL,
        action      VARCHAR(50) NOT NULL,
        note        TEXT,
        user_id     INTEGER,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Indeksy
    foreach ([
        "CREATE INDEX IF NOT EXISTS idx_zwroty_umowa  ON zwroty_kosztow(umowa_type, umowa_id)",
        "CREATE INDEX IF NOT EXISTS idx_zwroty_status ON zwroty_kosztow(status)",
        "CREATE INDEX IF NOT EXISTS idx_zwroty_wniask ON zwroty_kosztow(wnioskodawca_id)",
        "CREATE INDEX IF NOT EXISTS idx_zwlog_req     ON zwroty_log(request_id)",
    ] as $idx) { try { $pdo->exec($idx); } catch (\Throwable $e) {} }

    // Migracje istniejącej tabeli (gdy ma już dane)
    foreach ([
        "ALTER TABLE zwroty_kosztow ADD COLUMN bez_umowy INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE zwroty_kosztow ADD COLUMN zlozone_przez_id INTEGER",
        "ALTER TABLE zwroty_kosztow ADD COLUMN faktura_elektroniczna INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE zwroty_kosztow ADD COLUMN ksef_numer VARCHAR(100)",
        "ALTER TABLE zwroty_kosztow ADD COLUMN ksef_data DATE",
        "ALTER TABLE zwroty_kosztow ADD COLUMN ksef_nip_sprzedawcy VARCHAR(10)",
        // limit w umowach
        "ALTER TABLE umowy_wolontariat ADD COLUMN limit_zwrotu_kosztow DECIMAL(10,2) DEFAULT NULL",
    ] as $sql) { try { $pdo->exec($sql); } catch (\Throwable $e) {} }
})();


// ═══════════════════════════════════════════════════════════════════════════
// FinanceManager
// ═══════════════════════════════════════════════════════════════════════════
class FinanceManager
{
    private \PDO $db;

    public const STATUSES = [
        'oczekuje'    => ['label' => 'Oczekuje',               'color' => 'secondary'],
        'weryfikacja' => ['label' => 'Weryfikacja merytoryczna','color' => 'info'],
        'zatwierdzony'=> ['label' => 'Zatwierdzony',           'color' => 'success'],
        'do_wyplaty'  => ['label' => 'Do wypłaty',             'color' => 'primary'],
        'wyplacono'   => ['label' => 'Wypłacono',              'color' => 'dark'],
        'odrzucony'   => ['label' => 'Odrzucony',              'color' => 'danger'],
    ];

    public const KATEGORIE = [
        'transport'      => 'Transport (bilet, paliwo)',
        'zakwaterowanie' => 'Zakwaterowanie',
        'wyzywienie'     => 'Wyżywienie',
        'material'       => 'Materiały i środki',
        'komunikacja'    => 'Komunikacja (telefon, internet)',
        'szkolenie'      => 'Szkolenie / kurs',
        'inne'           => 'Inne',
    ];

    public const FORMY_WYPLATY = [
        'przelew' => 'Przelew bankowy',
        'gotowka' => 'Gotówka',
        'karta'   => 'Karta płatnicza',
    ];

    public function __construct() { $this->db = db(); }

    // ── 1. Numer wniosku ──────────────────────────────────────────────────

    public function generateRequestNumber(?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable();
        $rok     = (int)$date->format('Y');
        $miesiac = (int)$date->format('n');

        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->db->prepare(
                "INSERT INTO zwroty_numery (rok, miesiac, seq) VALUES (?,?,1)
                 ON CONFLICT(rok, miesiac) DO UPDATE SET seq = seq + 1"
            )->execute([$rok, $miesiac]);

            $seq = (int)$this->db->query(
                "SELECT seq FROM zwroty_numery WHERE rok={$rok} AND miesiac={$miesiac}"
            )->fetchColumn();

            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw new \RuntimeException('Błąd generowania numeru: ' . $e->getMessage(), 0, $e);
        }

        return sprintf('ZWR/%s/%03d', $date->format('dmY'), $seq);
    }

    // ── 2. Eligibility ────────────────────────────────────────────────────

    /**
     * Weryfikacja uprawnienia do zwrotu kosztów.
     * Gdy $bypass_contract = true (tryb "bez umowy") — pomija weryfikację umowy.
     */
    public function validateEligibility(int $contract_id, string $type = 'wolontariat',
                                        bool $bypass_contract = false): array
    {
        if ($bypass_contract || $contract_id === 0) {
            return [
                'eligible' => true,
                'reason'   => 'Tryb bez powiązanej umowy (dopuszczony przez admina).',
                'umowa'    => null,
                'zuzyty'   => 0.0,
                'dostepny' => null,
                'limit'    => null,
                'bypass'   => true,
            ];
        }

        $table = match ($type) {
            'wolontariat' => 'umowy_wolontariat',
            default       => throw new \InvalidArgumentException("Nieobslugiwany typ: {$type}"),
        };

        $umowa = db_one("SELECT * FROM {$table} WHERE id = ?", [$contract_id]);
        if (!$umowa) return $this->ineligible('Umowa nie istnieje.', null);

        if (empty($umowa['zwrot_kosztow'])) {
            return $this->ineligible('Umowa nie przewiduje zwrotu kosztow (flaga = 0).', $umowa);
        }

        $valid = ['aktywna','w_realizacji','w realizacji','zatwierdzony','zatwierdzona','aktywny','obowiazuje'];
        if (!in_array(strtolower($umowa['status'] ?? ''), $valid, true)) {
            return $this->ineligible(
                "Umowa ma status \"{$umowa['status']}\" — zwroty mozliwe tylko dla aktywnych umow.",
                $umowa
            );
        }

        $limit    = isset($umowa['limit_zwrotu_kosztow']) ? (float)$umowa['limit_zwrotu_kosztow'] : null;
        $zuzyty   = $this->getSumApproved($contract_id, $type);
        $dostepny = $limit !== null ? max(0.0, $limit - $zuzyty) : null;

        if ($limit !== null && $zuzyty >= $limit) {
            return $this->ineligible(
                sprintf('Wyczerpano limit (%.2f PLN). Zatwierdzono juz: %.2f PLN.', $limit, $zuzyty),
                $umowa, $zuzyty, $dostepny
            );
        }

        return [
            'eligible' => true, 'reason' => 'OK',
            'umowa'    => $umowa, 'zuzyty' => $zuzyty,
            'dostepny' => $dostepny, 'limit' => $limit, 'bypass' => false,
        ];
    }

    public function getSumApproved(int $contract_id, string $type = 'wolontariat'): float
    {
        $r = db_one(
            "SELECT COALESCE(SUM(kwota),0) AS s FROM zwroty_kosztow
             WHERE umowa_id=? AND umowa_type=? AND status IN ('zatwierdzony','do_wyplaty','wyplacono')",
            [$contract_id, $type]
        );
        return (float)($r['s'] ?? 0);
    }

    // ── 3. Tworzenie wniosku ──────────────────────────────────────────────

    public function createRequest(int $contract_id, string $type, int $user_id, array $data): int
    {
        $nr  = $this->generateRequestNumber();
        $now = date('Y-m-d H:i:s');

        $person_id   = null;
        $bez_umowy   = (int)($data['bez_umowy'] ?? 0);
        $umowa_id_db = ($bez_umowy || $contract_id === 0) ? null : $contract_id;

        if ($umowa_id_db) {
            $u = db_one("SELECT person_id FROM umowy_wolontariat WHERE id=?", [$umowa_id_db]);
            $person_id = $u['person_id'] ?? null;
        }

        $this->db->prepare(
            "INSERT INTO zwroty_kosztow
             (nr_wniosku, nr_rok, nr_miesiac, nr_seq,
              umowa_type, umowa_id, bez_umowy, person_id,
              wnioskodawca_id, zlozone_przez_id,
              tytul, opis, kwota, waluta, data_wydatku, kategoria, zalaczniki,
              faktura_elektroniczna,
              status, created_at, updated_at)
             VALUES (?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?,?, ?, 'oczekuje',?,?)"
        )->execute([
            $nr,
            (int)date('Y'), (int)date('n'),
            (int)$this->db->query(
                "SELECT seq FROM zwroty_numery WHERE rok=".(int)date('Y')." AND miesiac=".(int)date('n')
            )->fetchColumn(),
            $type, $umowa_id_db, $bez_umowy, $person_id,
            $user_id, isset($data['zlozone_przez_id']) ? (int)$data['zlozone_przez_id'] : null,
            $data['tytul'],
            $data['opis'] ?? null,
            (float)$data['kwota'],
            $data['waluta'] ?? 'PLN',
            $data['data_wydatku'],
            $data['kategoria'] ?? null,
            json_encode($data['zalaczniki'] ?? [], JSON_UNESCAPED_UNICODE),
            (int)($data['faktura_elektroniczna'] ?? 0),
            $now, $now,
        ]);

        $id = (int)$this->db->lastInsertId();
        $this->log($id, 'created', "Wniosek {$nr} zarejestrowany.", $user_id);

        // Powiadomienie → adminów/edytorów
        $this->notify($id, 'created', $user_id);

        return $id;
    }

    // ── 4. Workflow ───────────────────────────────────────────────────────

    public function sendToVerification(int $id, int $uid): void
    {
        $this->transition($id, 'weryfikacja', $uid, 'Przekazano do weryfikacji merytorycznej.');
        $this->notify($id, 'weryfikacja', $uid);
    }

    public function approve(int $id, int $uid, string $uwagi = ''): void
    {
        $this->db->prepare(
            "UPDATE zwroty_kosztow SET status='zatwierdzony',
             zatwierdzajacy_id=?, data_zatwierdzenia=datetime('now'),
             zatwierdzenie_uwagi=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$uid, $uwagi ?: null, $id]);
        $this->log($id, 'approved', "Zatwierdzony. {$uwagi}", $uid);
        $this->notify($id, 'approved', $uid);
    }

    public function schedulePayment(int $id, int $uid): void
    {
        $this->transition($id, 'do_wyplaty', $uid, 'Przekazano do wypłaty.');
        $this->notify($id, 'do_wyplaty', $uid);
    }

    public function markAsPaid(int $id, int $uid, array $data = []): bool
    {
        $req = db_one("SELECT * FROM zwroty_kosztow WHERE id=?", [$id]);
        if (!$req || $req['status'] === 'wyplacono') return false;
        if (!in_array($req['status'], ['zatwierdzony','do_wyplaty'], true)) {
            throw new \LogicException("Zly status do wyplaty: {$req['status']}");
        }

        $this->db->prepare(
            "UPDATE zwroty_kosztow SET status='wyplacono',
             wyplacajacy_id=?, data_wyplaty=?, forma_wyplaty=?, nr_przelewu=?,
             archived_at=datetime('now'), updated_at=datetime('now') WHERE id=?"
        )->execute([
            $uid,
            $data['data_wyplaty']  ?? date('Y-m-d'),
            $data['forma_wyplaty'] ?? 'przelew',
            $data['nr_przelewu']   ?? null,
            $id,
        ]);

        $this->log($id, 'paid', "Wyplacono {$req['kwota']} PLN · {$data['forma_wyplaty']}.", $uid);
        $this->notify($id, 'paid', $uid);
        return true;
    }

    public function reject(int $id, int $uid, string $powod): void
    {
        if (trim($powod) === '') throw new \InvalidArgumentException('Powod odrzucenia jest wymagany.');
        $this->db->prepare(
            "UPDATE zwroty_kosztow SET status='odrzucony',
             odrzucajacy_id=?, data_odrzucenia=datetime('now'),
             odrzucenie_powod=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$uid, $powod, $id]);
        $this->log($id, 'rejected', "Odrzucono: {$powod}", $uid);
        $this->notify($id, 'rejected', $uid);
    }

    // ── 5. KSeF ───────────────────────────────────────────────────────────

    /**
     * Admin uzupełnia dane faktury KSeF.
     */
    public function saveKsef(int $id, int $uid, array $data): void
    {
        $this->db->prepare(
            "UPDATE zwroty_kosztow SET
             ksef_numer=?, ksef_data=?, ksef_nip_sprzedawcy=?,
             updated_at=datetime('now') WHERE id=?"
        )->execute([
            trim($data['ksef_numer'] ?? '') ?: null,
            $data['ksef_data'] ?? null,
            trim($data['ksef_nip_sprzedawcy'] ?? '') ?: null,
            $id,
        ]);
        $nr = trim($data['ksef_numer'] ?? '');
        $this->log($id, 'ksef', "Uzupelniono dane KSeF: {$nr}", $uid);
    }

    // ── 6. Powiadomienia email ────────────────────────────────────────────

    /**
     * Wysyła powiadomienie email dla zdarzenia workflow.
     * Nie rzuca wyjątku — brak maila nie może blokować workflow.
     */
    public function notify(int $request_id, string $event, int $actor_id): void
    {
        try {
            if (!function_exists('mail_queue_add')) {
                @require_once dirname(__DIR__) . '/includes/mail_queue.php';
            }
            if (!function_exists('mail_queue_add')) return;

            $req  = db_one("SELECT * FROM zwroty_kosztow WHERE id=?", [$request_id]);
            if (!$req) return;

            $org  = defined('ORG_NAME') ? ORG_NAME : 'System';
            $url  = (defined('APP_URL') ? APP_URL : '') . "/contracts/zwroty/view.php?id={$request_id}";
            $nr   = h($req['nr_wniosku'] ?? '#'.$request_id);
            $tytul= h($req['tytul']);

            // Pobierz adres wnioskodawcy
            $wnioskodawca = db_one("SELECT name, email FROM users WHERE id=?", [$req['wnioskodawca_id']]);
            $admin_emails = array_column(
                db_all("SELECT email FROM users WHERE role IN ('admin','editor') AND is_active=1 AND email != '' AND email != 'serwis@local'"),
                'email'
            );

            [$subject, $body, $recipients] = match($event) {
                'created' => [
                    "Nowy wniosek o zwrot kosztów — {$nr}",
                    $this->emailBody($org, $url,
                        "Zarejestrowano nowy wniosek o zwrot kosztów",
                        "<strong>{$nr}</strong> · {$tytul}<br>Kwota: <strong>" .
                        number_format((float)$req['kwota'],2,',',' ') . " PLN</strong>",
                        "Sprawdź i przekaż do weryfikacji", "warning"
                    ),
                    $admin_emails,
                ],
                'approved' => [
                    "Wniosek {$nr} zatwierdzony",
                    $this->emailBody($org, $url,
                        "Twój wniosek o zwrot kosztów został zatwierdzony",
                        "<strong>{$nr}</strong> · {$tytul}<br>Kwota: <strong>" .
                        number_format((float)$req['kwota'],2,',',' ') . " PLN</strong>" .
                        ($req['zatwierdzenie_uwagi'] ? "<br>Uwagi: ".h($req['zatwierdzenie_uwagi']) : ''),
                        "Zobacz wniosek", "success"
                    ),
                    array_filter([$wnioskodawca['email'] ?? '']),
                ],
                'do_wyplaty' => [
                    "Wniosek {$nr} przekazany do wypłaty",
                    $this->emailBody($org, $url,
                        "Wniosek gotowy do wypłaty",
                        "<strong>{$nr}</strong> · {$tytul}<br>Kwota: <strong>" .
                        number_format((float)$req['kwota'],2,',',' ') . " PLN</strong>",
                        "Zrealizuj wypłatę", "primary"
                    ),
                    $admin_emails,
                ],
                'paid' => [
                    "Wniosek {$nr} — środki wypłacone",
                    $this->emailBody($org, $url,
                        "Zwrot kosztów został wypłacony",
                        "<strong>{$nr}</strong> · {$tytul}<br>" .
                        "Kwota: <strong>" . number_format((float)$req['kwota'],2,',',' ') . " PLN</strong><br>" .
                        "Forma: " . h($req['forma_wyplaty'] ?? '') .
                        ($req['nr_przelewu'] ? " · Nr: " . h($req['nr_przelewu']) : '') .
                        ($req['data_wyplaty'] ? " · Data: " . date('d.m.Y', strtotime($req['data_wyplaty'])) : ''),
                        "Potwierdzenie wypłaty", "dark"
                    ),
                    array_filter([$wnioskodawca['email'] ?? '']),
                ],
                'rejected' => [
                    "Wniosek {$nr} — odrzucony",
                    $this->emailBody($org, $url,
                        "Wniosek o zwrot kosztów został odrzucony",
                        "<strong>{$nr}</strong> · {$tytul}<br>" .
                        "Powód: <strong>" . h($req['odrzucenie_powod'] ?? '') . "</strong>",
                        "Szczegóły wniosku", "danger"
                    ),
                    array_filter([$wnioskodawca['email'] ?? '']),
                ],
                default => [null, null, []],
            };

            if (!$subject || !$recipients) return;

            foreach (array_unique($recipients) as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                mail_queue_add($email, '', $subject, $body);
            }
            mail_queue_process(count($recipients));

        } catch (\Throwable $e) {
            error_log('[zwroty] notify error: ' . $e->getMessage());
        }
    }

    /** Generuje HTML e-maila w jednolitym stylu systemu */
    private function emailBody(string $org, string $url, string $heading,
                               string $detail, string $cta, string $color): string
    {
        $colors = [
            'warning' => '#f59e0b', 'success' => '#22c55e',
            'primary' => '#2563eb', 'dark'    => '#1e293b', 'danger' => '#ef4444',
        ];
        $c = $colors[$color] ?? '#2563eb';
        return "
        <div style='font-family:system-ui,sans-serif;max-width:560px;margin:0 auto;color:#1e293b'>
          <div style='background:{$c};color:#fff;padding:20px 28px;border-radius:10px 10px 0 0'>
            <div style='font-size:.75rem;opacity:.7;text-transform:uppercase;letter-spacing:.08em'>{$org}</div>
            <div style='font-size:1.25rem;font-weight:700;margin-top:4px'>{$heading}</div>
          </div>
          <div style='background:#fff;border:1px solid #e2e8f0;border-top:none;padding:24px 28px;border-radius:0 0 10px 10px'>
            <p style='margin:0 0 16px'>{$detail}</p>
            <a href='{$url}' style='display:inline-block;padding:10px 22px;background:{$c};color:#fff;text-decoration:none;border-radius:6px;font-weight:600'>{$cta} →</a>
            <hr style='border:none;border-top:1px solid #e2e8f0;margin:20px 0'>
            <p style='color:#94a3b8;font-size:.78rem;margin:0'>
              Wiadomość automatyczna z systemu <strong>{$org}</strong> ·
              <a href='{$url}' style='color:#94a3b8'>Przejdź do wniosku</a>
            </p>
          </div>
        </div>";
    }

    // ── 7. Dane / listy ───────────────────────────────────────────────────

    public function getOrFail(int $id): array
    {
        $r = db_one("SELECT * FROM zwroty_kosztow WHERE id=?", [$id]);
        if (!$r) { http_response_code(404); die('Wniosek nie istnieje.'); }
        return $r;
    }

    public function listForContract(int $contract_id, string $type = 'wolontariat'): array
    {
        return db_all(
            "SELECT z.*, u.name AS wnioskodawca_name
             FROM zwroty_kosztow z
             LEFT JOIN users u ON u.id = z.wnioskodawca_id
             WHERE z.umowa_id=? AND z.umowa_type=?
             ORDER BY z.created_at DESC",
            [$contract_id, $type]
        );
    }

    public function listAll(array $f = []): array
    {
        $where = ['1=1']; $p = [];
        if (!empty($f['status'])) { $where[] = 'z.status=?';    $p[] = $f['status']; }
        if (!empty($f['rok']))    { $where[] = 'z.nr_rok=?';    $p[] = (int)$f['rok']; }
        if (!empty($f['q'])) {
            $like = '%'.$f['q'].'%';
            $where[] = '(z.nr_wniosku LIKE ? OR z.tytul LIKE ? OR w.imie_nazwisko LIKE ?)';
            $p[] = $like; $p[] = $like; $p[] = $like;
        }
        return db_all(
            "SELECT z.*, u.name AS wnioskodawca_name,
                    w.numer_umowy, w.imie_nazwisko AS wolontariusz
             FROM zwroty_kosztow z
             LEFT JOIN users u ON u.id = z.wnioskodawca_id
             LEFT JOIN umowy_wolontariat w ON w.id = z.umowa_id AND z.umowa_type='wolontariat'
             WHERE " . implode(' AND ', $where) . " ORDER BY z.created_at DESC",
            $p
        );
    }

    // ── Prywatne ──────────────────────────────────────────────────────────

    private function transition(int $id, string $status, int $uid, string $note): void
    {
        $this->db->prepare(
            "UPDATE zwroty_kosztow SET status=?, updated_at=datetime('now') WHERE id=?"
        )->execute([$status, $id]);
        $this->log($id, $status, $note, $uid);
    }

    private function ineligible(string $reason, ?array $umowa,
                                float $z = 0, ?float $d = null): array
    {
        return ['eligible'=>false,'reason'=>$reason,'umowa'=>$umowa,
                'zuzyty'=>$z,'dostepny'=>$d,'limit'=>null,'bypass'=>false];
    }

    public function log(int $rid, string $action, string $note, int $uid): void
    {
        try {
            db()->prepare(
                "INSERT INTO zwroty_log (request_id,action,note,user_id,created_at)
                 VALUES (?,?,?,?,datetime('now'))"
            )->execute([$rid, $action, $note, $uid]);
        } catch (\Throwable $e) {}
    }
}


// ── Globalne funkcje skrótu ────────────────────────────────────────────────

function zwroty_status_badge(string $status): string
{
    $cfg = FinanceManager::STATUSES[$status] ?? ['label' => $status, 'color' => 'secondary'];
    $extra = $cfg['color'] === 'info' ? ' text-dark' : '';
    return '<span class="badge bg-' . $cfg['color'] . $extra . '">' . h($cfg['label']) . '</span>';
}

function zwroty_kategoria_label(string $k): string
{
    return FinanceManager::KATEGORIE[$k] ?? $k;
}

function zwroty_nr_html(string $nr): string
{
    return '<span class="badge bg-dark font-monospace fw-normal" style="letter-spacing:.04em;font-size:.8rem">'
         . h($nr) . '</span>';
}
