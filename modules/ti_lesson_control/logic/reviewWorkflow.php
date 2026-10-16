<?php
/**
 * modules/ti_lesson_control/logic/reviewWorkflow.php — ręczna weryfikacja wpisów (panel audytora).
 *
 * Audytor = personel panelu dydaktyka (kierownik, admin — dyd_is_staff()); sprawdza to strona,
 * tu przekazujemy tylko $actor (id, rola, czy admin). Zasady:
 *  - przejścia wyłącznie z tabeli TRANSITIONS (strażnik), komentarz obowiązkowy przy „do poprawy”,
 *    „zakwestionuj” i „cofnij do weryfikacji”,
 *  - blokada optymistyczna: decyzja podaje revision, którą widział audytor; inna = ktoś zmienił w międzyczasie,
 *  - rozdział obowiązków: nikt (poza administratorem) nie weryfikuje własnej lekcji,
 *  - przed decyzją lekcja jest przeliczana (refreshOne) — zatwierdzamy aktualną treść, nie tę sprzed godziny,
 *  - każda decyzja w jednej transakcji: UPDATE k30_ti_lc_checks + zdarzenie w k30_ti_lc_events + audit_logs (ti_lc.*).
 */

require_once __DIR__ . '/lessonControlService.php';

final class ReviewException extends \RuntimeException {}

final class ReviewWorkflow
{
    public const APPROVE = 'approve';
    public const RETURN  = 'return';
    public const DISPUTE = 'dispute';
    public const REOPEN  = 'reopen';

    /** akcja => [status docelowy, dozwolone statusy źródłowe, wymagany komentarz, typ zdarzenia, etykieta] */
    public const TRANSITIONS = [
        self::APPROVE => [TiLcStatus::ZATWIERDZONY, [TiLcStatus::NIEZWERYFIKOWANY, TiLcStatus::POPRAWIONY, TiLcStatus::ZAKWESTIONOWANY, TiLcStatus::DO_POPRAWY], false, 'review_approved', 'Zatwierdź'],
        self::RETURN  => [TiLcStatus::DO_POPRAWY, [TiLcStatus::NIEZWERYFIKOWANY, TiLcStatus::POPRAWIONY, TiLcStatus::ZATWIERDZONY, TiLcStatus::ZAKWESTIONOWANY], true, 'review_returned', 'Do poprawy'],
        self::DISPUTE => [TiLcStatus::ZAKWESTIONOWANY, [TiLcStatus::NIEZWERYFIKOWANY, TiLcStatus::POPRAWIONY, TiLcStatus::ZATWIERDZONY], true, 'review_disputed', 'Zakwestionuj'],
        self::REOPEN  => [TiLcStatus::NIEZWERYFIKOWANY, [TiLcStatus::ZATWIERDZONY, TiLcStatus::DO_POPRAWY, TiLcStatus::ZAKWESTIONOWANY], true, 'review_reopened', 'Cofnij do weryfikacji'],
    ];

    public function __construct(private readonly LessonControlService $service = new LessonControlService()) {}

    /** Akcje dostępne dla bieżącego stanu (do przycisków). */
    public static function allowedActions(array $check): array {
        $out = [];
        foreach (self::TRANSITIONS as $a => [$to, $fromList]) {
            if (!in_array($check['review_status'], $fromList, true)) continue;
            if ($a === self::APPROVE && $check['auto_status'] === TiLcStatus::BRAK_WPISU) continue;   // nie ma czego zatwierdzić
            if (in_array($check['auto_status'], [TiLcStatus::NIE_DOTYCZY, TiLcStatus::OCZEKUJE], true) && $a !== self::REOPEN) continue;
            $out[] = $a;
        }
        return $out;
    }

    /**
     * @param array{id:int,role:string,is_admin:bool} $actor
     * @return array stan kontroli po decyzji
     */
    public function decide(int $sessionId, string $action, array $actor, int $revision, string $comment = ''): array {
        if (!isset(self::TRANSITIONS[$action])) throw new ReviewException('Nieznana akcja.');
        [$to, , $needComment, $eventType] = self::TRANSITIONS[$action];
        $comment = trim($comment);
        if ($needComment && mb_strlen($comment) < 3) throw new ReviewException('Podaj komentarz — jest wymagany przy tej decyzji.');
        if (mb_strlen($comment) > 2000) $comment = mb_substr($comment, 0, 2000);

        $before = $this->service->refreshOne($sessionId);
        if (!$before) throw new ReviewException('Ta lekcja nie podlega jeszcze kontroli (odbędzie się w przyszłości albo została odwołana).');
        if ((int)$before['revision'] !== $revision) {
            throw new ReviewException('Wpis lub jego status zmienił się w międzyczasie — sprawdź aktualny stan i zdecyduj jeszcze raz.');
        }
        if (!in_array($action, self::allowedActions($before), true)) {
            throw new ReviewException('Tej decyzji nie można podjąć przy statusie „' . TiLcStatus::label($before['review_status']) . '”.');
        }
        if ((int)$before['instructor_id'] === (int)$actor['id'] && empty($actor['is_admin'])) {
            throw new ReviewException('Nie weryfikujesz własnych lekcji — poproś innego kierownika.');
        }
        if ($action === self::APPROVE && !in_array($before['auto_status'], [TiLcStatus::PRAWIDLOWY, TiLcStatus::OPOZNIONY], true) && $comment === '') {
            throw new ReviewException('Wpis jest niepełny — zatwierdzenie mimo braków wymaga komentarza.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE k30_ti_lc_checks SET review_status = ?, reviewed_by = ?, reviewed_at = ?, review_comment = ?, revision = revision + 1"
                               . ($action === self::RETURN ? ", reminder_count = 0" : "") . "
                                  WHERE session_id = ? AND revision = ?");
            $st->execute([$to, (int)$actor['id'], date('Y-m-d H:i:s'), $comment, $sessionId, $revision]);
            if ($st->rowCount() !== 1) throw new ReviewException('Ktoś właśnie zmienił ten wpis — odśwież stronę.');
            TiLcEventLog::add($sessionId, $eventType, $before['review_status'], $to, (int)$actor['id'], (string)$actor['role'], $comment,
                ['auto_status' => $before['auto_status'], 'revision' => $revision + 1], (string)$before['entry_hash']);
            self::audit('ti_lc.' . $eventType, ['session_id' => $sessionId, 'from' => $before['review_status'], 'to' => $to,
                        'auto_status' => $before['auto_status'], 'comment' => $comment], (int)$actor['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return LessonControlService::check($sessionId) ?? [];
    }

    /** Zatwierdza hurtem wpisy PRAWIDŁOWE (bez braków i w terminie); pomija resztę. */
    public function approveBulk(array $sessionIds, array $actor): array {
        $ok = 0; $skipped = [];
        foreach (array_unique(array_map('intval', $sessionIds)) as $sid) {
            $c = LessonControlService::check($sid);
            if (!$c || $c['auto_status'] !== TiLcStatus::PRAWIDLOWY) { $skipped[$sid] = 'nie jest prawidłowy'; continue; }
            try { $this->decide($sid, self::APPROVE, $actor, (int)$c['revision']); $ok++; }
            catch (ReviewException $e) { $skipped[$sid] = $e->getMessage(); }
        }
        return ['approved' => $ok, 'skipped' => $skipped];
    }

    /** Wspólny dziennik audytu — błąd zapisu nie cofa decyzji (ślad jest w k30_ti_lc_events). */
    private static function audit(string $action, array $details, int $userId): void {
        try {
            require_once dirname(__DIR__, 3) . '/modules/audit_logs/logic/audit_logs.php';
            audit_log($action, $details, $userId ?: 0);
        } catch (\Throwable $e) {}
    }
}
