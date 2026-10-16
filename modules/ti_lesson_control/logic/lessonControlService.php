<?php
/**
 * modules/ti_lesson_control/logic/lessonControlService.php — kontrola automatyczna (agent CRON, CLI, panel).
 *
 * LessonControlService::run() ocenia lekcje z okresu i zapisuje wynik w k30_ti_lc_checks.
 * Idempotentna: ponowne uruchomienie bez zmian w danych niczego nie zmienia; zdarzenie
 * w śladzie (k30_ti_lc_events) powstaje tylko przy zmianie statusu.
 *
 * Reakcja na edycję wpisu (zmiana odcisku treści):
 *  - ZATWIERDZONY       → NIEZWERYFIKOWANY (zdarzenie entry_changed_after_approval) — decyzja użytkownika,
 *  - DO_POPRAWY         → POPRAWIONY (entry_corrected) — wraca do kolejki audytora,
 *  - pozostałe          → status bez zmian, zdarzenie entry_changed.
 * Tryb --dry-run: to samo liczenie, zero zapisów.
 */

require_once __DIR__ . '/lessonControlSchema.php';
require_once __DIR__ . '/lessonEntryValidator.php';

/** Ślad rewizyjny — tylko dopisywanie. */
final class TiLcEventLog
{
    public static function add(int $sessionId, string $type, string $from = '', string $to = '', ?int $actorId = null,
                               string $actorRole = 'system', string $comment = '', array $payload = [], string $hash = ''): void {
        db()->prepare("INSERT INTO k30_ti_lc_events (session_id, event_type, from_status, to_status, actor_user_id, actor_role, comment, payload_json, entry_hash, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$sessionId, $type, $from, $to, $actorId ?: null, $actorRole, $comment,
                       json_encode($payload ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $hash, date('Y-m-d H:i:s')]);
    }

    /** Historia lekcji, od najnowszych, z nazwą osoby. */
    public static function forSession(int $sessionId): array {
        try {
            return db_all("SELECT e.*, u.name AS actor_name FROM k30_ti_lc_events e LEFT JOIN users u ON u.id = e.actor_user_id
                            WHERE e.session_id = ? ORDER BY e.id DESC", [$sessionId]);
        } catch (\Throwable $e) { return []; }
    }
}

final class ControlRunSummary
{
    public int $checked = 0;
    public int $stored  = 0;
    /** @var array<string,int> */
    public array $byStatus = [];
    /** @var array<int,array{session_id:int,from:string,to:string,review_from:string,review_to:string}> */
    public array $changes = [];
    /** @var array<int,array> wyniki per lekcja (session_id => dane) */
    public array $items = [];

    public function __construct(public readonly string $from, public readonly string $to, public readonly bool $dryRun) {}
}

final class LessonControlService
{
    public function __construct(
        private readonly LessonEntryRepository $repo = new LessonEntryRepository(),
        private readonly LessonEntryValidator $validator = new LessonEntryValidator(),
    ) {}

    /** Domyślny zakres agenta: ostatnie ti_lc_lookback_days dni, nie wcześniej niż ti_lc_start_date. */
    public static function defaultRange(?\DateTimeImmutable $now = null): array {
        $now ??= new \DateTimeImmutable();
        $from = $now->modify('-' . max(1, LessonControlSettings::int('ti_lc_lookback_days')) . ' days')->format('Y-m-d');
        $start = LessonControlSettings::get('ti_lc_start_date');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && $start > $from) $from = $start;
        return [$from, $now->format('Y-m-d')];
    }

    public function run(string $from, string $to, bool $dryRun = false, ?\DateTimeImmutable $now = null, ?int $courseId = null): ControlRunSummary {
        $now ??= new \DateTimeImmutable();
        if (!$dryRun) tiLcMigrate();
        $sum = new ControlRunSummary($from, $to, $dryRun);
        return $this->evaluate($this->repo->sessionIdsBetween($from, $to, $courseId), $now, $sum);
    }

    /** Odświeża jedną lekcję (panel audytora przed decyzją, widok szczegółów). */
    public function refreshOne(int $sessionId, ?\DateTimeImmutable $now = null): ?array {
        tiLcMigrate();
        $this->evaluate([$sessionId], $now ?? new \DateTimeImmutable(), new ControlRunSummary('', '', false));
        return self::check($sessionId);
    }

    public static function check(int $sessionId): ?array {
        try { return db_one("SELECT * FROM k30_ti_lc_checks WHERE session_id = ?", [$sessionId]); } catch (\Throwable $e) { return null; }
    }

    private function evaluate(array $ids, \DateTimeImmutable $now, ControlRunSummary $sum): ControlRunSummary {
        $snaps = $this->repo->loadMany($ids);
        $prev  = $this->existing(array_keys($snaps));
        $pdo   = db();
        $own   = !$sum->dryRun && !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            foreach ($snaps as $sid => $s) {
                $sum->checked++;
                $p    = LessonControlPolicy::forCourse($s->courseId);
                $old  = $prev[$sid] ?? null;
                $keepSubmitted = $old && in_array($old['auto_status'], [TiLcStatus::PRAWIDLOWY, TiLcStatus::OPOZNIONY], true) ? $old['entry_submitted_at'] : null;
                $res  = $this->validator->validate($s, $p, $now, $keepSubmitted);
                $sum->byStatus[$res->autoStatus] = ($sum->byStatus[$res->autoStatus] ?? 0) + 1;

                if ($res->autoStatus === TiLcStatus::NIE_DOTYCZY && !$old) continue;   // przyszłe/trwające lekcje nie trafiają do rejestru

                $review = $old['review_status'] ?? TiLcStatus::NIEZWERYFIKOWANY;
                $newReview = $review; $hashEvent = null;
                if ($old && $old['entry_hash'] !== '' && $old['entry_hash'] !== $res->hash) {
                    if ($review === TiLcStatus::ZATWIERDZONY)   { $newReview = TiLcStatus::NIEZWERYFIKOWANY; $hashEvent = 'entry_changed_after_approval'; }
                    elseif ($review === TiLcStatus::DO_POPRAWY) { $newReview = TiLcStatus::POPRAWIONY;       $hashEvent = 'entry_corrected'; }
                    else                                        { $hashEvent = 'entry_changed'; }
                }
                $autoChanged = !$old || $old['auto_status'] !== $res->autoStatus;
                $sum->items[$sid] = ['snapshot' => $s, 'result' => $res, 'review' => $newReview, 'policy' => $p];
                if ($autoChanged || $newReview !== $review) {
                    $sum->changes[] = ['session_id' => $sid, 'from' => $old['auto_status'] ?? '', 'to' => $res->autoStatus,
                                       'review_from' => $review, 'review_to' => $newReview];
                }
                if ($sum->dryRun) continue;

                $this->store($s, $res, $p, $old, $newReview, $now);
                $sum->stored++;
                if ($autoChanged) {
                    TiLcEventLog::add($sid, $old ? 'auto_status_changed' : 'auto_checked', $old['auto_status'] ?? '', $res->autoStatus,
                        null, 'system', '', ['missing' => array_column($res->missingArray(), 'code'), 'delay_min' => $res->delayMin], $res->hash);
                }
                if ($hashEvent) {
                    TiLcEventLog::add($sid, $hashEvent, $review, $newReview, null, 'system',
                        $hashEvent === 'entry_changed_after_approval' ? 'Wpis zmieniono po zatwierdzeniu — wraca do weryfikacji.' : '', [], $res->hash);
                }
            }
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $sum;
    }

    private function existing(array $ids): array {
        if (!$ids) return [];
        $out = [];
        try {
            foreach (array_chunk($ids, 400) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                foreach (db_all("SELECT * FROM k30_ti_lc_checks WHERE session_id IN ($in)", $chunk) as $r) $out[(int)$r['session_id']] = $r;
            }
        } catch (\Throwable $e) {}   // dry-run przed pierwszą migracją — brak tabeli
        return $out;
    }

    private function store(LessonEntrySnapshot $s, ValidationResult $r, LessonControlPolicy $p, ?array $old, string $review, \DateTimeImmutable $now): void {
        $fmt = fn(?\DateTimeImmutable $d) => $d?->format('Y-m-d H:i:s');
        $vals = [
            'course_id' => $s->courseId, 'instructor_id' => $s->instructorId, 'lesson_date' => $s->lessonDate,
            'auto_status' => $r->autoStatus, 'is_late' => $r->isLate ? 1 : 0,
            'missing_json' => json_encode($r->missingArray(), JSON_UNESCAPED_UNICODE),
            'due_at' => $fmt($r->dueAt), 'entry_submitted_at' => $fmt($r->submittedAt), 'delay_min' => $r->delayMin,
            'entry_hash' => $r->hash, 'rule_snapshot_json' => json_encode($p->snapshot(), JSON_UNESCAPED_UNICODE),
            'review_status' => $review, 'last_checked_at' => $now->format('Y-m-d H:i:s'),
        ];
        if ($old) {
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals)));
            $bump = $review !== $old['review_status'] ? ', revision = revision + 1' : '';
            db()->prepare("UPDATE k30_ti_lc_checks SET $set $bump WHERE session_id = ?")->execute([...array_values($vals), $s->sessionId]);
        } else {
            $vals = ['session_id' => $s->sessionId] + $vals;
            $cols = implode(', ', array_keys($vals));
            db()->prepare("INSERT INTO k30_ti_lc_checks ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ")")->execute(array_values($vals));
        }
    }
}
