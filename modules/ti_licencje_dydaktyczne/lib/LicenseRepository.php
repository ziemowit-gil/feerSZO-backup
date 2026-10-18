<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseRepository.php — dostęp do danych modułu „Licencje dydaktyczne (AM5)”.
 *
 * LicenseRepository     — licenses + license_events (pula, wniosek AM5/1, decyzja, token licencjodawcy)
 * AssignmentRepository  — license_assignments + license_declarations + license_holder_changes
 * Tokeny portalu: w bazie tylko SHA-256; surowy token istnieje wyłącznie w linku wysłanym osobie.
 */
require_once __DIR__ . '/ldSchema.php';
require_once __DIR__ . '/LicenseEnums.php';

final class LicenseRepository
{
    public function __construct()
    {
        ldMigrate();
    }

    public function get(int $id): ?array
    {
        $r = db_one("SELECT * FROM licenses WHERE id = ?", [$id]);
        return $r ? $this->hydrate($r) : null;
    }

    private function hydrate(array $r): array
    {
        $r['request'] = json_decode((string)($r['request_data'] ?? ''), true) ?: [];
        $r['decision'] = json_decode((string)($r['decision_data'] ?? ''), true) ?: [];
        $r['requestStatus'] = LicenseRequestStatus::tryFrom((string)$r['request_status']) ?? LicenseRequestStatus::Draft;
        $r['form'] = LicenseForm::of($r);
        $r['activeAssignments'] = (int)(db_one("SELECT COUNT(*) AS c FROM license_assignments WHERE license_id = ? AND status <> 'revoked'", [(int)$r['id']])['c'] ?? 0);
        $r['poolStatus'] = LicenseStatus::resolve($r, $r['activeAssignments']);
        return $r;
    }

    /** Lista z filtrem: q (tytuł/licencjodawca/osoba), status (wniosek: draft|started|… albo pula: free|assigned|suspended|expired), overdue. */
    public function search(array $f = []): array
    {
        $w = []; $p = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = "(lower(title) LIKE ? OR lower(licensor_name) LIKE ? OR lower(interested_name) LIKE ? OR lower(course_name) LIKE ? OR lower(case_number) LIKE ?)";
            $like = '%' . mb_strtolower($q) . '%';
            array_push($p, $like, $like, $like, $like, $like);
        }
        $st = (string)($f['status'] ?? '');
        if ($form = LicenseForm::tryFrom((string)($f['form'] ?? ''))) { $w[] = "form_kind = ?"; $p[] = $form->value; }
        if (LicenseRequestStatus::tryFrom($st)) { $w[] = "request_status = ?"; $p[] = $st; }
        $rows = array_map(fn($r) => $this->hydrate($r), db_all("SELECT * FROM licenses" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 500", $p));
        if ($pool = LicenseStatus::tryFrom($st)) $rows = array_values(array_filter($rows, fn($r) => $r['poolStatus'] === $pool));
        if (!empty($f['overdue'])) $rows = array_values(array_filter($rows, fn($r) => self::overdue($r)));
        return $rows;
    }

    /** Wniosek wszczęty i po terminie 7 dni bez decyzji. */
    public static function overdue(array $r): bool
    {
        return ($r['request_status'] ?? '') === LicenseRequestStatus::Started->value && ($r['due_on'] ?? '') !== '' && $r['due_on'] < ldToday();
    }

    /** Liczniki do kafli pulpitu. */
    public function counts(): array
    {
        $c = ['draft' => 0, 'started' => 0, 'overdue' => 0, 'free' => 0, 'assigned' => 0, 'suspended' => 0, 'expired' => 0, 'refused' => 0, 'toVerify' => 0];
        foreach ($this->search() as $r) {
            $c[$r['request_status']] = ($c[$r['request_status']] ?? 0) + 1;
            if (self::overdue($r)) $c['overdue']++;
            if ($r['poolStatus']) $c[$r['poolStatus']->value]++;
        }
        $c['toVerify'] = (int)(db_one("SELECT COUNT(*) AS c FROM license_assignments WHERE status = 'submitted'")['c'] ?? 0);
        return $c;
    }

    /** Nowa sprawa z kolejnym numerem 4021-MS_AM/NNNNNN/AB (unikalny case_seq; przy kolizji równoległego zapisu — ponowienie). */
    public function create(array $cols, array $actor): int
    {
        $now = ldNow();
        $letters = ldCaseLetters((string)($cols['interested_name'] ?? ''), (string)($cols['licensor_name'] ?? ''));
        for ($try = 0; ; $try++) {
            $seq = 1 + (int)(db_one("SELECT MAX(case_seq) AS m FROM licenses")['m'] ?? 0);
            try {
                return db_insert('licenses', $cols + ['case_seq' => $seq, 'case_number' => ldCaseNumber($seq, $letters),
                    'request_status' => LicenseRequestStatus::Draft->value, 'created_by' => $actor['id'] ?: null,
                    'created_name' => (string)$actor['name'], 'created_at' => $now, 'updated_at' => $now]);
            } catch (\PDOException $e) {
                if ($try >= 4) throw $e;
            }
        }
    }

    /** Wniosek roboczy: litery numeru idą za osobą zainteresowaną (po wszczęciu numer jest stały). */
    public function refreshCaseLetters(int $id): void
    {
        $r = db_one("SELECT case_seq, interested_name, licensor_name, request_status FROM licenses WHERE id = ?", [$id]);
        if (!$r || $r['request_status'] !== LicenseRequestStatus::Draft->value || (int)$r['case_seq'] === 0) return;
        db_exec("UPDATE licenses SET case_number = ? WHERE id = ?", [ldCaseNumber((int)$r['case_seq'], ldCaseLetters((string)$r['interested_name'], (string)$r['licensor_name'])), $id]);
    }

    public function update(int $id, array $cols): void
    {
        $cols['updated_at'] = ldNow();
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols)));
        db_exec("UPDATE licenses SET {$set} WHERE id = ?", [...array_values($cols), $id]);
    }

    public function delete(int $id): void
    {
        db_exec("DELETE FROM license_events WHERE license_id = ?", [$id]);
        db_exec("DELETE FROM licenses WHERE id = ?", [$id]);
    }

    /** Nowy token licencjodawcy (zastępuje poprzedni). Zwraca surowy token do linku. */
    public function issueLicensorToken(int $id): string
    {
        $t = bin2hex(random_bytes(24));
        $this->update($id, ['licensor_token_hash' => hash('sha256', $t), 'licensor_token_expires' => date('Y-m-d H:i:s', time() + LD_TOKEN_DAYS * 86400)]);
        return $t;
    }

    public function byLicensorToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $r = db_one("SELECT * FROM licenses WHERE licensor_token_hash = ?", [hash('sha256', $token)]);
        if (!$r || (string)$r['licensor_token_expires'] < ldNow()) return null;
        return $this->hydrate($r);
    }

    /** Token kursanta do podpisu wniosku AM7 (zastępuje poprzedni). */
    public function issueRequesterToken(int $id): string
    {
        $t = bin2hex(random_bytes(24));
        $this->update($id, ['requester_token_hash' => hash('sha256', $t), 'requester_token_expires' => date('Y-m-d H:i:s', time() + LD_TOKEN_DAYS * 86400)]);
        return $t;
    }

    public function byRequesterToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $r = db_one("SELECT * FROM licenses WHERE requester_token_hash = ?", [hash('sha256', $token)]);
        if (!$r || (string)$r['requester_token_expires'] < ldNow()) return null;
        return $this->hydrate($r);
    }

    public function event(int $licenseId, string $action, string $note, array $actor, ?int $assignmentId = null): void
    {
        db_insert('license_events', ['license_id' => $licenseId, 'assignment_id' => $assignmentId, 'action' => $action, 'note' => mb_substr($note, 0, 2000),
            'actor_id' => ($actor['id'] ?? 0) ?: null, 'actor_name' => (string)($actor['name'] ?? ''),
            'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), 'created_at' => ldNow()]);
    }

    public function events(int $licenseId): array
    {
        return db_all("SELECT * FROM license_events WHERE license_id = ? ORDER BY id DESC", [$licenseId]);
    }

    public function notifications(int $licenseId): array
    {
        return db_all("SELECT * FROM notification_logs WHERE module = 'ti_licencje_dyd' AND ((ref_type = 'license' AND ref_id = ?)
                        OR (ref_type = 'assignment' AND ref_id IN (SELECT id FROM license_assignments WHERE license_id = ?))) ORDER BY id DESC LIMIT 200", [$licenseId, $licenseId]);
    }
}

final class AssignmentRepository
{
    public function __construct()
    {
        ldMigrate();
    }

    public function get(int $id): ?array
    {
        $r = db_one("SELECT * FROM license_assignments WHERE id = ?", [$id]);
        return $r ? $this->hydrate($r) : null;
    }

    private function hydrate(array $r): array
    {
        $r['statusEnum'] = LicenseAssignmentStatus::tryFrom((string)$r['status']) ?? LicenseAssignmentStatus::Invited;
        return $r;
    }

    public function forLicense(int $licenseId): array
    {
        return array_map(fn($r) => $this->hydrate($r), db_all("SELECT * FROM license_assignments WHERE license_id = ? ORDER BY id DESC", [$licenseId]));
    }

    /** Przydziały osoby (profil kursanta / współpracownika). */
    public function forHolder(string $kind, int $holderId): array
    {
        return array_map(fn($r) => $this->hydrate($r), db_all("SELECT a.*, l.title AS license_title FROM license_assignments a JOIN licenses l ON l.id = a.license_id
                                                                 WHERE a.holder_kind = ? AND a.holder_id = ? ORDER BY a.id DESC", [$kind, $holderId]));
    }

    public function activeFor(int $licenseId, string $kind, ?int $holderId, string $email): ?array
    {
        if ($holderId) $r = db_one("SELECT * FROM license_assignments WHERE license_id = ? AND holder_kind = ? AND holder_id = ? AND status <> 'revoked'", [$licenseId, $kind, $holderId]);
        else $r = $email !== '' ? db_one("SELECT * FROM license_assignments WHERE license_id = ? AND lower(holder_email) = ? AND status <> 'revoked'", [$licenseId, mb_strtolower($email)]) : null;
        return $r ? $this->hydrate($r) : null;
    }

    public function create(array $cols): int
    {
        return db_insert('license_assignments', $cols);
    }

    public function update(int $id, array $cols): void
    {
        $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols)));
        db_exec("UPDATE license_assignments SET {$set} WHERE id = ?", [...array_values($cols), $id]);
    }

    /** Nowy token portalu licencjobiorcy + termin 7 dni. Zwraca surowy token. */
    public function issueToken(int $id): string
    {
        $t = bin2hex(random_bytes(24));
        $this->update($id, ['token_hash' => hash('sha256', $t), 'token_expires' => date('Y-m-d H:i:s', time() + LD_TOKEN_DAYS * 86400)]);
        return $t;
    }

    public function byToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
        $r = db_one("SELECT * FROM license_assignments WHERE token_hash = ?", [hash('sha256', $token)]);
        if (!$r || (string)$r['token_expires'] < ldNow()) return null;
        return $this->hydrate($r);
    }

    // ── Oświadczenia AM5/2 i AM5/3 ──────────────────────────────────────────────

    public function addDeclaration(array $cols): int
    {
        return db_insert('license_declarations', $cols + ['created_at' => ldNow()]);
    }

    /** Oświadczenia przydziału (najnowsze pierwsze). */
    public function declarations(int $assignmentId): array
    {
        return db_all("SELECT * FROM license_declarations WHERE assignment_id = ? ORDER BY id DESC", [$assignmentId]);
    }

    public function declaration(int $id): ?array
    {
        return db_one("SELECT * FROM license_declarations WHERE id = ?", [$id]) ?: null;
    }

    /** Poprzednie oświadczenia oczekujące/odrzucone tego przydziału stają się „zastąpione”. */
    public function supersedeDeclarations(int $assignmentId): void
    {
        db_exec("UPDATE license_declarations SET status = 'superseded' WHERE assignment_id = ? AND status IN ('pending', 'rejected')", [$assignmentId]);
    }

    public function setDeclarationsStatus(int $assignmentId, LicenseDeclarationStatus $s): void
    {
        db_exec("UPDATE license_declarations SET status = ? WHERE assignment_id = ? AND status = 'pending'", [$s->value, $assignmentId]);
    }

    // ── Zmiany danych z portalu ─────────────────────────────────────────────────

    public function addChange(array $cols): int
    {
        return db_insert('license_holder_changes', $cols + ['created_at' => ldNow(), 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64)]);
    }

    public function changes(int $assignmentId): array
    {
        return db_all("SELECT * FROM license_holder_changes WHERE assignment_id = ? ORDER BY id DESC", [$assignmentId]);
    }

    public function change(int $id): ?array
    {
        return db_one("SELECT * FROM license_holder_changes WHERE id = ?", [$id]) ?: null;
    }

    public function decideChange(int $id, bool $apply, int $actorId): void
    {
        db_exec("UPDATE license_holder_changes SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?", [$apply ? 'applied' : 'rejected', $actorId ?: null, ldNow(), $id]);
    }
}
