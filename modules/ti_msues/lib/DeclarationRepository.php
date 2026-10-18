<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/DeclarationRepository.php — oświadczenia prowadzących (trainer_declarations): zapis, wersje, obieg statusów,
 * skan podpisanego oświadczenia i historia akceptacji (trainer_declaration_events).
 *
 * Wersjonowanie: wiersz = wersja; series_id = id pierwszej wersji. Nowa wersja kopiuje treść ostatniej i startuje jako robocza;
 * zatwierdzenie wersji oznacza wcześniejsze zatwierdzone tej serii jako „zastąpione”.
 * Zatwierdzenie pod audyt wymaga wgranego podpisanego skanu; kierownik może zatwierdzić także własne (adnotacja w historii). Aktor: ['id' => int, 'name' => string, 'staff' => bool].
 */
require_once __DIR__ . '/msuesSchema.php';
require_once __DIR__ . '/DeclarationStatus.php';
require_once __DIR__ . '/DeclarationForm.php';
require_once __DIR__ . '/MsuesStorage.php';

final class DeclarationRepository
{
    public function __construct()
    {
        msuesMigrate();
    }

    private function hydrate(array $r): array
    {
        $r['id'] = (int)$r['id'];
        $r['trainer_id'] = (int)$r['trainer_id'];
        $r['course_id'] = $r['course_id'] !== null ? (int)$r['course_id'] : null;
        $r['version'] = (int)$r['version'];
        $r['series_id'] = (int)($r['series_id'] ?: $r['id']);
        $r['data'] = array_merge(DeclarationForm::defaults(), json_decode((string)$r['data'], true) ?: []);
        $r['statusEnum'] = DeclarationStatus::tryFrom((string)$r['status']) ?? DeclarationStatus::Draft;
        return $r;
    }

    private const SELECT = "SELECT d.*, u.name AS trainer_name, u.email AS trainer_email, c.name AS course_name, c.display_name AS course_display, c.group_code AS course_code
                              FROM trainer_declarations d
                              LEFT JOIN users u ON u.id = d.trainer_id
                              LEFT JOIN k30_ti_courses c ON c.id = d.course_id";

    public function get(int $id): ?array
    {
        $r = db_one(self::SELECT . " WHERE d.id = ?", [$id]);
        return $r ? $this->hydrate($r) : null;
    }

    /** Filtry: trainer, course, status, q (imię i nazwisko). Domyślnie bez wersji zastąpionych. */
    public function search(array $f = [], bool $withSuperseded = false): array
    {
        $w = []; $p = [];
        if (!empty($f['trainer'])) { $w[] = 'd.trainer_id = ?'; $p[] = (int)$f['trainer']; }
        if (!empty($f['course']))  { $w[] = 'd.course_id = ?';  $p[] = (int)$f['course']; }
        if (!empty($f['status']) && DeclarationStatus::tryFrom((string)$f['status'])) { $w[] = 'd.status = ?'; $p[] = (string)$f['status']; }
        elseif (!$withSuperseded) { $w[] = "d.status <> 'superseded'"; }
        if (trim((string)($f['q'] ?? '')) !== '') { $w[] = 'LOWER(u.name) LIKE ?'; $p[] = '%' . mb_strtolower(trim((string)$f['q'])) . '%'; }
        $rows = db_all(self::SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY d.updated_at DESC, d.id DESC", $p);
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    public function forTrainer(int $trainerId, bool $withSuperseded = true): array
    {
        return $this->search(['trainer' => $trainerId], $withSuperseded);
    }

    public function versions(int $seriesId): array
    {
        return array_map(fn($r) => $this->hydrate($r), db_all(self::SELECT . " WHERE d.series_id = ? OR d.id = ? ORDER BY d.version DESC", [$seriesId, $seriesId]));
    }

    /** Ważne oświadczenie pod audyt dla prowadzącego i usługi: dedykowane usłudze, a gdy go brak — ogólne (bez usługi). */
    public function approvedFor(int $trainerId, ?int $courseId): ?array
    {
        if ($courseId) {
            $r = db_one(self::SELECT . " WHERE d.trainer_id = ? AND d.status = 'approved' AND d.course_id = ? ORDER BY d.decided_at DESC, d.id DESC LIMIT 1", [$trainerId, $courseId]);
            if ($r) return $this->hydrate($r);
        }
        $r = db_one(self::SELECT . " WHERE d.trainer_id = ? AND d.status = 'approved' AND d.course_id IS NULL ORDER BY d.decided_at DESC, d.id DESC LIMIT 1", [$trainerId]);
        return $r ? $this->hydrate($r) : null;
    }

    /** Najświeższe oświadczenie w obiegu (do podpowiedzi „co dalej” na checkliście). */
    public function latestFor(int $trainerId, ?int $courseId): ?array
    {
        $r = db_one(self::SELECT . " WHERE d.trainer_id = ? AND d.status <> 'superseded' AND (d.course_id = ? OR d.course_id IS NULL)
                                    ORDER BY CASE WHEN d.course_id IS NULL THEN 1 ELSE 0 END, d.id DESC LIMIT 1", [$trainerId, (int)$courseId]);
        return $r ? $this->hydrate($r) : null;
    }

    public function create(int $trainerId, ?int $courseId, string $project, array $data, array $actor): int
    {
        if (!MsuesSzoData::user($trainerId)) throw new InvalidArgumentException('Nie ma takiego prowadzącego.');
        if ($courseId && !MsuesSzoData::course($courseId)) throw new InvalidArgumentException('Nie ma takiej usługi (grupy).');
        $now = msuesNow();
        $id = db_insert('trainer_declarations', [
            'version' => 1, 'trainer_id' => $trainerId, 'course_id' => $courseId ?: null, 'project' => mb_substr(trim($project), 0, 190),
            'status' => DeclarationStatus::Draft->value, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_by' => $actor['id'] ?: null, 'created_name' => (string)$actor['name'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        db_exec("UPDATE trainer_declarations SET series_id = ? WHERE id = ?", [$id, $id]);
        $this->event($id, 'create', '', DeclarationStatus::Draft->value, 'Utworzono oświadczenie (wersja 1).', $actor);
        return $id;
    }

    public function update(int $id, array $data, string $project, array $actor): void
    {
        $d = $this->require($id);
        if (!$d['statusEnum']->editable()) throw new DomainException('Oświadczenie w statusie „' . $d['statusEnum']->label() . '” nie może być edytowane — utwórz nową wersję.');
        db_exec("UPDATE trainer_declarations SET data = ?, project = ?, updated_at = ? WHERE id = ?",
            [json_encode($data, JSON_UNESCAPED_UNICODE), mb_substr(trim($project), 0, 190), msuesNow(), $id]);
        $this->event($id, 'edit', $d['status'], $d['status'], 'Zmieniono treść oświadczenia.', $actor);
    }

    /** Zmiana statusu wg DeclarationStatus::canTransitionTo(); przy odrzuceniu wymagane uzasadnienie. */
    public function transition(int $id, DeclarationStatus $to, array $actor, string $note = ''): void
    {
        $d = $this->require($id);
        $from = $d['statusEnum'];
        if (!$from->canTransitionTo($to, !empty($actor['staff']))) {
            throw new DomainException('Niedozwolona zmiana statusu: „' . $from->label() . '” → „' . $to->label() . '”.');
        }
        $note = trim($note);
        $now = msuesNow();
        if ($to === DeclarationStatus::Pending) {
            $miss = DeclarationForm::missingForSubmit($d['data']);
            if ($miss) throw new DomainException('Uzupełnij przed wysłaniem do weryfikacji: ' . implode(', ', $miss) . '.');
            db_exec("UPDATE trainer_declarations SET status = ?, submitted_at = ?, submitted_by = ?, updated_at = ? WHERE id = ?",
                [$to->value, $now, $actor['id'] ?: null, $now, $id]);
        } elseif ($to === DeclarationStatus::Approved) {
            if ($d['scan_path'] === '') throw new DomainException('Do zatwierdzenia pod audyt potrzebny jest wgrany skan podpisanego oświadczenia.');
            // Kierownik może zatwierdzić własne oświadczenie — w historii zostaje adnotacja
            if ((int)$d['trainer_id'] === (int)$actor['id']) $note = trim('Zatwierdzone przez składającego (kierownik). ' . $note);
            db()->beginTransaction();
            try {
                foreach (db_all("SELECT id FROM trainer_declarations WHERE series_id = ? AND status = 'approved' AND id <> ?", [$d['series_id'], $id]) as $old) {
                    db_exec("UPDATE trainer_declarations SET status = 'superseded', updated_at = ? WHERE id = ?", [$now, (int)$old['id']]);
                    $this->event((int)$old['id'], 'superseded', 'approved', 'superseded', 'Zastąpione wersją ' . $d['version'] . '.', $actor);
                }
                db_exec("UPDATE trainer_declarations SET status = ?, decided_at = ?, decided_by = ?, decided_name = ?, decision_note = ?, updated_at = ? WHERE id = ?",
                    [$to->value, $now, $actor['id'] ?: null, (string)$actor['name'], $note, $now, $id]);
                db()->commit();
            } catch (\Throwable $e) { db()->rollBack(); throw $e; }
        } elseif ($to === DeclarationStatus::Rejected) {
            if ($note === '') throw new DomainException('Podaj powód odrzucenia — prowadzący zobaczy go przy oświadczeniu.');
            db_exec("UPDATE trainer_declarations SET status = ?, decided_at = ?, decided_by = ?, decided_name = ?, decision_note = ?, updated_at = ? WHERE id = ?",
                [$to->value, $now, $actor['id'] ?: null, (string)$actor['name'], $note, $now, $id]);
        } else {
            db_exec("UPDATE trainer_declarations SET status = ?, updated_at = ? WHERE id = ?", [$to->value, $now, $id]);
        }
        $this->event($id, 'status', $from->value, $to->value, $note, $actor);
    }

    /** Nowa wersja (kopia treści ostatniej wersji serii) — dozwolona, gdy w serii nie ma już wersji w obiegu. */
    public function newVersion(int $id, array $actor): int
    {
        $d = $this->require($id);
        $open = db_one("SELECT id, version FROM trainer_declarations WHERE series_id = ? AND status IN ('draft','pending','rejected') LIMIT 1", [$d['series_id']]);
        if ($open) throw new DomainException('Wersja ' . (int)$open['version'] . ' tego oświadczenia jest jeszcze w obiegu — dokończ ją zamiast zakładać nową.');
        $last = db_one("SELECT * FROM trainer_declarations WHERE series_id = ? ORDER BY version DESC LIMIT 1", [$d['series_id']]);
        $data = json_decode((string)$last['data'], true) ?: [];
        $data['data'] = date('Y-m-d');
        $now = msuesNow();
        $nid = db_insert('trainer_declarations', [
            'series_id' => $d['series_id'], 'version' => (int)$last['version'] + 1, 'trainer_id' => $d['trainer_id'], 'course_id' => $d['course_id'],
            'project' => (string)$last['project'], 'status' => DeclarationStatus::Draft->value, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'ezd_sprawa_id' => $last['ezd_sprawa_id'] ?: null,
            'created_by' => $actor['id'] ?: null, 'created_name' => (string)$actor['name'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->event($nid, 'create', '', DeclarationStatus::Draft->value, 'Nowa wersja ' . ((int)$last['version'] + 1) . ' (na podstawie wersji ' . (int)$last['version'] . ').', $actor);
        return $nid;
    }

    /** Wgranie skanu podpisanego oświadczenia (robocze / oczekujące / odrzucone). */
    public function attachScan(int $id, string $tmp, string $origName, array $actor, bool $uploaded = true): void
    {
        $d = $this->require($id);
        if (!in_array($d['statusEnum'], [DeclarationStatus::Draft, DeclarationStatus::Pending, DeclarationStatus::Rejected], true)) {
            throw new DomainException('Skan można wgrać tylko do oświadczenia w obiegu.');
        }
        [$path, $name] = MsuesStorage::store($tmp, $origName, MsuesStorage::SCAN_EXT, 'okk' . $id, $uploaded);
        db_exec("UPDATE trainer_declarations SET scan_path = ?, scan_name = ?, scan_at = ?, scan_by = ?, updated_at = ? WHERE id = ?",
            [$path, $name, msuesNow(), $actor['id'] ?: null, msuesNow(), $id]);
        $this->event($id, 'scan', $d['status'], $d['status'], 'Wgrano skan podpisanego oświadczenia: ' . $name . ($d['scan_path'] !== '' ? ' (zastąpił poprzedni)' : '') . '.', $actor);
    }

    public function setPdfHash(int $id, string $sha): void
    {
        db_exec("UPDATE trainer_declarations SET pdf_sha256 = ? WHERE id = ?", [$sha, $id]);
    }

    public function setEzd(int $id, int $sprawaId, ?int $pismoId, array $actor, string $znak): void
    {
        $d = $this->require($id);
        db_exec("UPDATE trainer_declarations SET ezd_sprawa_id = ?, ezd_pismo_id = ?, updated_at = ? WHERE id = ?", [$sprawaId, $pismoId, msuesNow(), $id]);
        $this->event($id, 'ezd', $d['status'], $d['status'], 'Zarejestrowano w EZD: koszulka ' . $znak . ($pismoId ? ', pismo #' . $pismoId : '') . '.', $actor);
    }

    public function delete(int $id, array $actor): void
    {
        $d = $this->require($id);
        if ($d['statusEnum'] !== DeclarationStatus::Draft || $d['version'] !== 1 || $d['ezd_sprawa_id']) {
            throw new DomainException('Usunąć można tylko pierwszą wersję roboczą bez koszulki EZD — pozostałe zostają w historii.');
        }
        db_exec("DELETE FROM trainer_declaration_events WHERE declaration_id = ?", [$id]);
        db_exec("DELETE FROM trainer_declarations WHERE id = ?", [$id]);
        if (function_exists('audit_log')) audit_log('msues.declaration.delete', ['id' => $id, 'trainer_id' => $d['trainer_id']], $actor['id'] ?: null);
    }

    public function events(int $seriesId): array
    {
        return db_all("SELECT e.*, d.version FROM trainer_declaration_events e JOIN trainer_declarations d ON d.id = e.declaration_id
                        WHERE d.series_id = ? OR d.id = ? ORDER BY e.id DESC", [$seriesId, $seriesId]);
    }

    public function counts(): array
    {
        $o = [];
        foreach (db_all("SELECT status, COUNT(*) AS n FROM trainer_declarations GROUP BY status") as $r) $o[(string)$r['status']] = (int)$r['n'];
        return $o;
    }

    private function require(int $id): array
    {
        $d = $this->get($id);
        if (!$d) throw new DomainException('Nie ma takiego oświadczenia.');
        return $d;
    }

    private function event(int $id, string $action, string $from, string $to, string $note, array $actor): void
    {
        db_insert('trainer_declaration_events', [
            'declaration_id' => $id, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'note' => $note,
            'actor_id' => $actor['id'] ?: null, 'actor_name' => (string)$actor['name'], 'created_at' => msuesNow(),
        ]);
        if (function_exists('audit_log') && in_array($action, ['status', 'create', 'scan'], true)) {
            try { audit_log('msues.declaration.' . $action, ['id' => $id, 'from' => $from, 'to' => $to], $actor['id'] ?: null); } catch (\Throwable $e) {}
        }
    }
}

