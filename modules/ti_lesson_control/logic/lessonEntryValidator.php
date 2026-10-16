<?php
/**
 * modules/ti_lesson_control/logic/lessonEntryValidator.php — ocena wpisu z lekcji (kompletność + terminowość).
 *
 * Elementy:
 *  - LessonEntrySnapshot   — niezmienny obraz lekcji i jej wpisu (sesja, obecność, sylabus, materiały, zadania),
 *  - LessonEntryRepository — zbiorcze ładowanie snapshotów (stała liczba zapytań, bez N+1),
 *  - LessonControlPolicy   — reguły z k30_ti_lc_rules: grupa → globalna,
 *  - DeadlineCalculator    — termin wpisu (domyślnie do końca dnia zajęć),
 *  - LessonEntryRule       — jedna reguła = jeden wymagany element (nowa reguła = nowa klasa + wpis w LessonEntryValidator::rules()),
 *  - LessonEntryValidator  — czysta funkcja: snapshot + polityka + „teraz” → ValidationResult (bez zapisu do bazy).
 *
 * Czas złożenia wpisu: aplikacja nie zapisuje go osobno, więc przy PIERWSZYM zobaczeniu kompletnego
 * wpisu bierzemy k30_ti_sessions.updated_at (UTC z datetime('now') → czas lokalny), a gdy go brak — „teraz”.
 * Ręczna flaga docs_complete („dokumentacja uzupełniona”) zalicza temat i obecność — tak jak
 * k30_ti_docs_complete_sql() w includes/karty30.php.
 */

require_once __DIR__ . '/lessonControlSettings.php';

final class LessonEntrySnapshot
{
    public function __construct(
        public readonly int $sessionId,
        public readonly int $courseId,
        public readonly string $courseName,
        public readonly ?int $instructorId,
        public readonly string $instructorName,
        public readonly string $lessonDate,
        public readonly string $timeFrom,
        public readonly string $timeTo,
        public readonly int $durationMin,
        public readonly string $status,
        public readonly string $topic,
        public readonly string $notes,
        public readonly string $materialUrl,
        public readonly bool $hasHomework,
        public readonly bool $docsManual,
        public readonly bool $trackAttendance,
        public readonly ?string $updatedAtUtc,
        /** @var array<int,array{client_id:int,attended:int,cancelled:int,no_show:int}> */
        public readonly array $attendance,
        /** @var int[] klienci zapisani w dniu lekcji */
        public readonly array $enrolledClientIds,
        /** @var int[] */
        public readonly array $curriculumIds,
        public readonly bool $courseHasCurriculum,
        /** @var int[] */
        public readonly array $materialIds,
        /** @var int[] */
        public readonly array $homeworkIds,
    ) {}

    public function isHeld(): bool { return in_array($this->status, LessonEntryRepository::heldStatuses(), true); }
    public function isRemoteMaterial(): bool { return $this->status === 'remote_material'; }

    /** Koniec lekcji wg harmonogramu (czas lokalny). */
    public function endsAt(): \DateTimeImmutable {
        $date = $this->lessonDate;
        if (preg_match('/^\d{1,2}:\d{2}/', $this->timeTo)) return new \DateTimeImmutable($date . ' ' . substr($this->timeTo, 0, 5));
        if (preg_match('/^\d{1,2}:\d{2}/', $this->timeFrom)) {
            return (new \DateTimeImmutable($date . ' ' . substr($this->timeFrom, 0, 5)))->modify('+' . max(0, $this->durationMin) . ' minutes');
        }
        return new \DateTimeImmutable($date . ' 23:59:00');
    }

    /** Ostatnia zmiana sesji w czasie lokalnym (updated_at zapisywane jest w UTC). */
    public function updatedAtLocal(): ?\DateTimeImmutable {
        if (!$this->updatedAtUtc) return null;
        try {
            return (new \DateTimeImmutable($this->updatedAtUtc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable $e) { return null; }
    }

    /** Odcisk treści wpisu — zmiana = wpis edytowany (wraca do weryfikacji). */
    public function hash(): string {
        $att = array_map(fn($a) => $a['client_id'] . ':' . $a['attended'] . ':' . $a['cancelled'] . ':' . $a['no_show'], $this->attendance);
        sort($att);
        $cur = $this->curriculumIds; sort($cur);
        $mat = $this->materialIds;   sort($mat);
        $hw  = $this->homeworkIds;   sort($hw);
        return hash('sha256', json_encode([
            trim($this->topic), trim($this->notes), trim($this->materialUrl), $this->hasHomework, $this->docsManual,
            $this->status, $att, $cur, $mat, $hw,
        ], JSON_UNESCAPED_UNICODE));
    }
}

final class LessonEntryRepository
{
    /** Statusy lekcji odbytej (te same co K30_TI_HELD_STATUSES). */
    public static function heldStatuses(): array {
        return defined('K30_TI_HELD_STATUSES') ? K30_TI_HELD_STATUSES : ['held', 'individual_change', 'remote_material'];
    }

    /** Id lekcji z okresu [od, do] (daty Y-m-d), które mogą wymagać wpisu (bez odwołanych). */
    public function sessionIdsBetween(string $from, string $to, ?int $courseId = null): array {
        $sql = "SELECT s.id FROM k30_ti_sessions s WHERE s.lesson_date BETWEEN ? AND ?
                  AND s.status NOT IN ('cancelled','reserved')";
        $p = [$from, $to];
        if ($courseId) { $sql .= " AND s.course_id = ?"; $p[] = $courseId; }
        return array_map('intval', array_column(db_all($sql . " ORDER BY s.lesson_date, s.time_from", $p), 'id'));
    }

    /** @return array<int,LessonEntrySnapshot> */
    public function loadMany(array $sessionIds): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $sessionIds))));
        if (!$ids) return [];
        $out = [];
        foreach (array_chunk($ids, 400) as $chunk) $out += $this->loadChunk($chunk);
        return $out;
    }

    private function loadChunk(array $ids): array {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_all(
            "SELECT s.id, s.course_id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.status,
                    COALESCE(s.topic,'') AS topic, COALESCE(s.notes,'') AS notes, COALESCE(s.material_url,'') AS material_url,
                    COALESCE(s.has_homework,0) AS has_homework, COALESCE(s.docs_complete,0) AS docs_manual, s.updated_at,
                    COALESCE(s.instructor_id, c.instructor_id) AS instructor_id, COALESCE(u.name,'') AS instructor_name,
                    COALESCE(NULLIF(c.display_name,''), c.name) AS course_name, COALESCE(c.track_attendance,1) AS track_attendance
               FROM k30_ti_sessions s
               JOIN k30_ti_courses c ON c.id = s.course_id
               LEFT JOIN users u ON u.id = COALESCE(s.instructor_id, c.instructor_id)
              WHERE s.id IN ($in)", $ids);
        if (!$rows) return [];
        $courseIds = array_values(array_unique(array_map(fn($r) => (int)$r['course_id'], $rows)));
        $cin = implode(',', array_fill(0, count($courseIds), '?'));

        $att = [];
        foreach (db_all("SELECT session_id, client_id, attended, COALESCE(cancelled,0) AS cancelled, COALESCE(no_show,0) AS no_show
                           FROM k30_ti_attendance WHERE session_id IN ($in)", $ids) as $a) {
            $att[(int)$a['session_id']][] = ['client_id' => (int)$a['client_id'], 'attended' => (int)$a['attended'],
                                             'cancelled' => (int)$a['cancelled'], 'no_show' => (int)$a['no_show']];
        }
        $enr = [];
        foreach (db_all("SELECT course_id, client_id, start_date, end_date FROM k30_ti_enrollments
                          WHERE course_id IN ($cin) AND status = 'active'", $courseIds) as $e) {
            $enr[(int)$e['course_id']][] = $e;
        }
        $cur = $this->idsBySession("SELECT session_id, curriculum_id AS id FROM k30_ti_session_curriculum WHERE session_id IN ($in)", $ids);
        $mat = $this->idsBySession("SELECT session_id, id FROM k30_ti_materials WHERE session_id IN ($in) AND COALESCE(is_active,1) = 1", $ids);
        $hw  = $this->idsBySession("SELECT session_id, id FROM k30_ti_homework WHERE session_id IN ($in) AND COALESCE(is_active,1) = 1", $ids);
        $withCurr = [];
        try {
            foreach (db_all("SELECT DISTINCT course_id FROM k30_ti_curriculum WHERE course_id IN ($cin)", $courseIds) as $c) $withCurr[(int)$c['course_id']] = true;
        } catch (\Throwable $e) {}

        $out = [];
        foreach ($rows as $r) {
            $sid = (int)$r['id']; $cid = (int)$r['course_id']; $date = (string)$r['lesson_date'];
            $enrolled = [];
            foreach ($enr[$cid] ?? [] as $e) {
                if (!empty($e['start_date']) && $e['start_date'] > $date) continue;
                if (!empty($e['end_date']) && $e['end_date'] < $date) continue;
                $enrolled[] = (int)$e['client_id'];
            }
            $out[$sid] = new LessonEntrySnapshot(
                $sid, $cid, (string)$r['course_name'], $r['instructor_id'] !== null ? (int)$r['instructor_id'] : null,
                (string)$r['instructor_name'], $date, (string)$r['time_from'], (string)$r['time_to'], (int)$r['duration_min'],
                (string)$r['status'], (string)$r['topic'], (string)$r['notes'], (string)$r['material_url'],
                (bool)$r['has_homework'], (bool)$r['docs_manual'], (bool)$r['track_attendance'],
                $r['updated_at'] ? (string)$r['updated_at'] : null,
                $att[$sid] ?? [], $enrolled, $cur[$sid] ?? [], isset($withCurr[$cid]), $mat[$sid] ?? [], $hw[$sid] ?? [],
            );
        }
        return $out;
    }

    private function idsBySession(string $sql, array $ids): array {
        $out = [];
        try { foreach (db_all($sql, $ids) as $r) $out[(int)$r['session_id']][] = (int)$r['id']; } catch (\Throwable $e) {}
        return $out;
    }
}

final class LessonControlPolicy
{
    public function __construct(
        public readonly int $courseId,           // 0 = reguła globalna
        public readonly string $deadlineMode,    // end_of_day | hours_after_end
        public readonly int $deadlineHours,
        public readonly int $graceMin,
        public readonly bool $reqTopic,
        public readonly int $topicMinLen,
        public readonly bool $reqAttendance,
        public readonly bool $reqCurriculum,
        public readonly bool $reqMaterials,
        public readonly bool $reqHomeworkIfFlag,
    ) {}

    private static ?array $cache = null;

    public static function fromRow(array $r): self {
        return new self((int)($r['course_id'] ?? 0),
            in_array($r['deadline_mode'] ?? '', ['end_of_day', 'hours_after_end'], true) ? $r['deadline_mode'] : 'end_of_day',
            max(1, (int)($r['deadline_hours'] ?? 24)), max(0, (int)($r['grace_min'] ?? 0)),
            (bool)($r['req_topic'] ?? 1), max(1, (int)($r['topic_min_len'] ?? 3)), (bool)($r['req_attendance'] ?? 1),
            (bool)($r['req_curriculum'] ?? 1), (bool)($r['req_materials'] ?? 0), (bool)($r['req_homework_if_flag'] ?? 1));
    }

    /** Reguła grupy, a gdy jej brak — globalna. */
    public static function forCourse(int $courseId): self {
        if (self::$cache === null) {
            self::$cache = [];
            try { foreach (db_all("SELECT * FROM k30_ti_lc_rules") as $r) self::$cache[(int)$r['course_id']] = $r; } catch (\Throwable $e) {}
        }
        return self::fromRow(self::$cache[$courseId] ?? self::$cache[0] ?? []);
    }

    public static function resetCache(): void { self::$cache = null; }

    public function snapshot(): array { return get_object_vars($this); }

    public function deadlineLabel(): string {
        $base = $this->deadlineMode === 'hours_after_end' ? $this->deadlineHours . ' h od zakończenia lekcji' : 'do końca dnia zajęć';
        return $base . ($this->graceMin ? ' (+' . $this->graceMin . ' min tolerancji)' : '');
    }
}

final class DeadlineCalculator
{
    public function dueAt(LessonEntrySnapshot $s, LessonControlPolicy $p): \DateTimeImmutable {
        $due = $p->deadlineMode === 'hours_after_end'
            ? $s->endsAt()->modify('+' . $p->deadlineHours . ' hours')
            : new \DateTimeImmutable($s->lessonDate . ' 23:59:59');
        return $p->graceMin ? $due->modify('+' . $p->graceMin . ' minutes') : $due;
    }
}

final class RuleViolation
{
    public function __construct(public readonly string $code, public readonly string $label, public readonly string $detail = '') {}
    public function toArray(): array { return ['code' => $this->code, 'label' => $this->label, 'detail' => $this->detail]; }
}

interface LessonEntryRule
{
    public function code(): string;
    public function applies(LessonEntrySnapshot $s, LessonControlPolicy $p): bool;
    public function check(LessonEntrySnapshot $s, LessonControlPolicy $p): ?RuleViolation;
}

abstract class BaseEntryRule implements LessonEntryRule
{
    protected function violation(string $detail = ''): RuleViolation { return new RuleViolation($this->code(), TI_LC_ELEMENTS[$this->code()] ?? $this->code(), $detail); }
}

final class TopicRule extends BaseEntryRule
{
    private const PLACEHOLDERS = ['lekcja', 'zajęcia', 'zajecia', 'temat', 'brak', '-', '—', 'x', '.', '?'];
    public function code(): string { return 'topic'; }
    public function applies(LessonEntrySnapshot $s, LessonControlPolicy $p): bool { return $p->reqTopic && !$s->docsManual; }
    public function check(LessonEntrySnapshot $s, LessonControlPolicy $p): ?RuleViolation {
        $t = trim($s->topic);
        if ($t === '') return $this->violation('nie wpisano tematu');
        if (in_array(mb_strtolower($t), self::PLACEHOLDERS, true)) return $this->violation('temat jest tylko zaślepką („' . $t . '”)');
        if (mb_strlen($t) < $p->topicMinLen) return $this->violation('temat krótszy niż ' . $p->topicMinLen . ' znaki');
        return null;
    }
}

final class AttendanceRule extends BaseEntryRule
{
    public function code(): string { return 'attendance'; }
    public function applies(LessonEntrySnapshot $s, LessonControlPolicy $p): bool {
        return $p->reqAttendance && !$s->docsManual && $s->trackAttendance && !$s->isRemoteMaterial();
    }
    public function check(LessonEntrySnapshot $s, LessonControlPolicy $p): ?RuleViolation {
        if (!$s->attendance) return $this->violation('nie sprawdzono obecności');
        $marked  = array_column($s->attendance, 'client_id');
        $missing = array_diff($s->enrolledClientIds, $marked);
        return $missing ? $this->violation('brak obecności ' . count($missing) . ' uczestnik(ów)') : null;
    }
}

final class CurriculumRule extends BaseEntryRule
{
    public function code(): string { return 'curriculum'; }
    public function applies(LessonEntrySnapshot $s, LessonControlPolicy $p): bool { return $p->reqCurriculum && $s->courseHasCurriculum; }
    public function check(LessonEntrySnapshot $s, LessonControlPolicy $p): ?RuleViolation {
        return $s->curriculumIds ? null : $this->violation('nie zaznaczono punktów sylabusa zrealizowanych na lekcji');
    }
}

final class MaterialsRule extends BaseEntryRule
{
    public function code(): string { return 'materials'; }
    public function applies(LessonEntrySnapshot $s, LessonControlPolicy $p): bool { return $p->reqMaterials; }
    public function check(LessonEntrySnapshot $s, LessonControlPolicy $p): ?RuleViolation {
        return (trim($s->materialUrl) !== '' || $s->materialIds || $s->homeworkIds) ? null : $this->violation('brak materiałów przypiętych do lekcji');
    }
}

final class HomeworkRule extends BaseEntryRule
{
    public function code(): string { return 'homework'; }
    public function applies(LessonEntrySnapshot $s, LessonControlPolicy $p): bool { return $p->reqHomeworkIfFlag && $s->hasHomework; }
    public function check(LessonEntrySnapshot $s, LessonControlPolicy $p): ?RuleViolation {
        return $s->homeworkIds ? null : $this->violation('lekcja ma zaznaczone zadanie domowe, ale nie dodano treści zadania');
    }
}

final class ValidationResult
{
    public function __construct(
        public readonly string $autoStatus,
        public readonly bool $isLate,
        /** @var RuleViolation[] */
        public readonly array $missing,
        public readonly ?\DateTimeImmutable $dueAt,
        public readonly ?\DateTimeImmutable $submittedAt,
        public readonly int $delayMin,
        public readonly string $hash,
    ) {}

    public function missingArray(): array { return array_map(fn(RuleViolation $v) => $v->toArray(), $this->missing); }
}

final class LessonEntryValidator
{
    /** @var LessonEntryRule[] */
    private array $rules;

    public function __construct(private readonly DeadlineCalculator $deadlines = new DeadlineCalculator(), ?array $rules = null) {
        $this->rules = $rules ?? self::rules();
    }

    /** Domyślny zestaw reguł — tu dopisuj nowe. */
    public static function rules(): array {
        return [new TopicRule(), new AttendanceRule(), new CurriculumRule(), new MaterialsRule(), new HomeworkRule()];
    }

    /**
     * @param ?string $prevSubmittedAt  wcześniej ustalony czas złożenia kompletnego wpisu (z k30_ti_lc_checks)
     */
    public function validate(LessonEntrySnapshot $s, LessonControlPolicy $p, \DateTimeImmutable $now, ?string $prevSubmittedAt = null): ValidationResult {
        $hash = $s->hash();
        $closedOrPast = $s->isHeld() || in_array($s->status, ['planned', 'scheduled'], true);
        if (!$closedOrPast || $s->endsAt() > $now) {
            return new ValidationResult(TiLcStatus::NIE_DOTYCZY, false, [], null, null, 0, $hash);
        }
        $due = $this->deadlines->dueAt($s, $p);

        $missing = [];
        foreach ($this->rules as $rule) {
            if (!$rule->applies($s, $p)) continue;
            if ($v = $rule->check($s, $p)) $missing[] = $v;
        }

        if (!$missing) {
            $submitted = $prevSubmittedAt ? new \DateTimeImmutable($prevSubmittedAt) : $this->estimateSubmittedAt($s, $now);
            $late  = $submitted > $due;
            $delay = $late ? (int)floor(($submitted->getTimestamp() - $due->getTimestamp()) / 60) : 0;
            return new ValidationResult($late ? TiLcStatus::OPOZNIONY : TiLcStatus::PRAWIDLOWY, $late, [], $due, $submitted, $delay, $hash);
        }

        if ($now <= $due) return new ValidationResult(TiLcStatus::OCZEKUJE, false, $missing, $due, null, 0, $hash);

        $delay = (int)floor(($now->getTimestamp() - $due->getTimestamp()) / 60);
        $status = $this->isEmpty($s) ? TiLcStatus::BRAK_WPISU : TiLcStatus::NIEPELNY_WPIS;
        return new ValidationResult($status, true, $missing, $due, null, $delay, $hash);
    }

    /** „Brak wpisu” = nic nie udokumentowano: bez tematu, obecności, sylabusa, materiałów i ręcznej flagi. */
    private function isEmpty(LessonEntrySnapshot $s): bool {
        return trim($s->topic) === '' && !$s->attendance && !$s->curriculumIds && !$s->materialIds
            && !$s->homeworkIds && trim($s->materialUrl) === '' && !$s->docsManual;
    }

    /**
     * Pierwsze zobaczenie kompletnego wpisu. Ostatnia zmiana sesji (updated_at) z przedziału [start lekcji, teraz]
     * = moment złożenia; zmiana sprzed lekcji albo brak znacznika = wpis gotowy na koniec lekcji (bez fałszywych
     * „opóźnionych” przy pierwszym uruchomieniu na starych lekcjach). Późniejsze edycje nie przesuwają tego czasu.
     */
    private function estimateSubmittedAt(LessonEntrySnapshot $s, \DateTimeImmutable $now): \DateTimeImmutable {
        $end   = $s->endsAt();
        $start = $end->modify('-' . max(0, $s->durationMin) . ' minutes');
        $u = $s->updatedAtLocal();
        if ($u && $u <= $now && $u >= $start) return $u;
        return $end < $now ? $end : $now;
    }
}
