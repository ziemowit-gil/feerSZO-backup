<?php
/**
 * TaskNotificationService — kolejkowanie, diagnostyka i transakcyjny zapis zadań
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  DIAGNOZA: Dlaczego powiadomienia "giną" w czystym PHP                   │
 * ├──────────────────────────────────────────────────────────────────────────┤
 * │  1. BLOKOWANIE HTTP: task_notify_*() wywołuje approval_send_email()      │
 * │     synchronicznie w tym samym żądaniu HTTP. Timeout M365/SMTP (>30s)    │
 * │     wstrzymuje całą odpowiedź API. FIX: kolejkuj → przetwarzaj przez cron│
 * │                                                                          │
 * │  2. BRAK KOLEJKI Z RETRIES: mail_queue istnieje dla ogólnych e-maili,    │
 * │     ale task_notify_*() może ominąć ją przy szybkiej M365/SMTP. Gdy      │
 * │     kanał upadnie między powiadomieniami — brak retries, brak alertu.    │
 * │     FIX: task_notifications_queue z max_attempts i statusami.            │
 * │                                                                          │
 * │  3. CICHE BŁĘDY: wszystkie send-paths są w try{}catch{} bez logowania.   │
 * │     _tn_log() wywoływany TYLKO przy sukcesie → brak widoczności błędów.  │
 * │     FIX: task_notification_errors — log każdego failure.                 │
 * │                                                                          │
 * │  4. KONFLIKTY TRANSAKCJI: task_notify_created() wywołany po db_insert()  │
 * │     ale POZA transakcją. Jeśli queue INSERT rzuci wyjątek, task już       │
 * │     istnieje w DB bez powiadomień — niemożliwe do wykrycia.              │
 * │     FIX: atomowa transakcja: zadanie + queue w jednym BEGIN/COMMIT.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Użycie:
 *   $svc  = new TaskNotificationService(db());
 *
 *   // Zapis zadania + kolejkowanie w jednej transakcji:
 *   $result = $svc->saveTask($data, $byUid);
 *   // $result['task']       → zapisane zadanie
 *   // $result['diagnostic'] → wynik runPostSaveTest()
 *
 *   // Samo kolejkowanie (gdy piszesz do istniejącego flow):
 *   $svc->queue($userId, $taskId, 'assigned', ['by_uid' => $byUid, ...]);
 *
 *   // Worker cron (cron/tasks_notification_worker.php):
 *   $stats = $svc->processQueue(50);
 *
 *   // Diagnostyka ad-hoc po zapisie:
 *   $diag = $svc->runPostSaveTest($taskId, 'created');
 *
 * Wymagane include przed użyciem: config.php, db.php, functions.php
 */

class TaskNotificationService
{
    private \PDO $db;
    private static bool $schemaReady = false;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
        if (!self::$schemaReady) {
            $this->ensureSchema();
            self::$schemaReady = true;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  TRANSAKCYJNY ZAPIS ZADANIA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Atomowo zapisuje nowe zadanie i kolejkuje powiadomienia.
     * Transakcja gwarantuje, że albo oba zapisy się udają, albo żaden.
     *
     * @param array{
     *   workspace_id: int,
     *   list_id:      int,
     *   title:        string,
     *   description?: string,
     *   priority?:    int,
     *   due_date?:    string|null,
     *   start_date?:  string|null,
     *   assignees?:   int[],
     *   area_id?:     int|null,
     *   unit_id?:     int|null,
     * } $data
     * @param int $byUid  ID tworzącego użytkownika
     * @return array{task: array, queue_ids: int[], diagnostic: array}
     * @throws \InvalidArgumentException przy brakujących polach
     * @throws \RuntimeException przy błędzie zapisu DB
     */
    public function saveTask(array $data, int $byUid): array
    {
        // ── Walidacja ────────────────────────────────────────────────────────
        $wsId   = (int)($data['workspace_id'] ?? 0);
        $listId = (int)($data['list_id']      ?? 0);
        $title  = trim($data['title']         ?? '');

        if (!$wsId || !$listId || $title === '') {
            throw new \InvalidArgumentException('Wymagane: workspace_id, list_id, title.');
        }

        // ── Transakcja: zadanie + kolejka powiadomień ────────────────────────
        $this->db->beginTransaction();
        $taskId   = 0;
        $queueIds = [];

        try {
            $now    = date('Y-m-d H:i:s');
            $maxPos = (float)($this->db->query(
                "SELECT COALESCE(MAX(position),0)+1 FROM tasks WHERE list_id={$listId} AND deleted_at IS NULL"
            )->fetchColumn() ?? 1);

            // 1. INSERT zadania
            $stmt = $this->db->prepare(
                "INSERT INTO tasks
                 (workspace_id, list_id, title, description, position, priority,
                  start_date, due_date, area_id, unit_id, created_by, created_at, updated_at)
                 VALUES
                 (:workspace_id,:list_id,:title,:description,:position,:priority,
                  :start_date,:due_date,:area_id,:unit_id,:created_by,:created_at,:updated_at)"
            );
            $stmt->execute([
                ':workspace_id' => $wsId,
                ':list_id'      => $listId,
                ':title'        => $title,
                ':description'  => trim($data['description'] ?? ''),
                ':position'     => $maxPos,
                ':priority'     => max(1, min(4, (int)($data['priority'] ?? 2))),
                ':start_date'   => $data['start_date'] ?? null,
                ':due_date'     => $data['due_date']   ?? null,
                ':area_id'      => ($data['area_id'] ?? null) ?: null,
                ':unit_id'      => ($data['unit_id'] ?? null) ?: null,
                ':created_by'   => $byUid,
                ':created_at'   => $now,
                ':updated_at'   => $now,
            ]);
            $taskId = (int)$this->db->lastInsertId();

            // 2. Przypisania (opcjonalne)
            if (!empty($data['assignees']) && is_array($data['assignees'])) {
                $aStmt = $this->db->prepare(
                    "INSERT OR IGNORE INTO task_assignments (task_id,user_id,assigned_by,assigned_at)
                     VALUES (?,?,?,?)"
                );
                foreach ($data['assignees'] as $aUid) {
                    $aUid = (int)$aUid;
                    if ($aUid > 0) {
                        $aStmt->execute([$taskId, $aUid, $byUid, $now]);
                    }
                }
            }

            // 3. Kolejkuj powiadomienia in-app i e-mail dla liderów obszaru
            $leaders = $this->db->prepare(
                "SELECT DISTINCT u.id, u.name, u.email
                 FROM users u
                 LEFT JOIN task_workspace_members m ON m.user_id=u.id AND m.workspace_id=?
                 WHERE u.is_active=1
                   AND u.email IS NOT NULL AND u.email!=''
                   AND (m.role IN ('admin','editor') OR u.is_admin=1)
                 LIMIT 20"
            );
            $leaders->execute([$wsId]);

            foreach ($leaders->fetchAll(\PDO::FETCH_ASSOC) as $u) {
                if ((int)$u['id'] === $byUid) continue;

                $payload = [
                    'title'      => 'Nowe zadanie: ' . $title,
                    'body'       => 'Zadanie dodane przez użytkownika #' . $byUid,
                    'task_title' => $title,
                    'subject'    => 'Nowe zadanie: ' . $title,
                ];

                // In-app
                $queueIds[] = $this->queueInTransaction($taskId, (int)$u['id'], 'created', 'inapp', $payload);
                // E-mail
                $queueIds[] = $this->queueInTransaction($taskId, (int)$u['id'], 'created', 'email', $payload);
            }

            $this->db->commit();

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw new \RuntimeException('saveTask failed: ' . $e->getMessage(), 0, $e);
        }

        // ── Diagnostyka po zapisie (poza transakcją) ─────────────────────────
        $diagnostic = $this->runPostSaveTest($taskId, 'created');

        return [
            'task'       => $this->db->query("SELECT * FROM tasks WHERE id={$taskId}")->fetch(\PDO::FETCH_ASSOC),
            'queue_ids'  => array_filter($queueIds),
            'diagnostic' => $diagnostic,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  KOLEJKA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Dodaj powiadomienie do kolejki (publiczne — używaj z istniejących task_notify_*()).
     *
     * @param string $channel  'email' | 'sms' | 'inapp'
     * @param string $scheduleAt  ISO datetime lub '' = natychmiast
     * @return int  ID wpisu w kolejce; 0 przy błędzie (ciche, nie blokuje)
     */
    public function queue(
        int    $userId,
        int    $taskId,
        string $eventType,
        array  $payload    = [],
        string $channel    = 'email',
        string $scheduleAt = ''
    ): int {
        try {
            return $this->queueInTransaction($taskId, $userId, $eventType, $channel, $payload, $scheduleAt);
        } catch (\Throwable $e) {
            $this->logError($taskId, $userId, $eventType, $channel, 'queue_insert: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Przetwórz oczekujące elementy kolejki.
     * Wywoływany przez cron/tasks_notification_worker.php.
     *
     * @return array{processed:int, sent:int, failed:int, skipped:int}
     */
    public function processQueue(int $limit = 50): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        $rows = $this->db->prepare(
            "SELECT * FROM task_notifications_queue
             WHERE status = 'pending'
               AND attempts < max_attempts
               AND scheduled_at <= datetime('now','localtime')
             ORDER BY created_at ASC
             LIMIT ?"
        );
        $rows->execute([$limit]);
        $items = $rows->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $stats['processed']++;
            $qId = (int)$item['id'];

            // Oznacz jako "in_progress" — zapobiega podwójnemu przetworzeniu
            $this->db->prepare(
                "UPDATE task_notifications_queue
                 SET status='processing', attempts=attempts+1 WHERE id=?"
            )->execute([$qId]);

            try {
                $result = $this->dispatch($item);

                if ($result === null) {
                    // Pominięte: dedup, brak preferencji, brak e-maila
                    $this->markQueue($qId, 'skipped');
                    $stats['skipped']++;
                } elseif ($result === true) {
                    $this->markQueue($qId, 'sent');
                    $stats['sent']++;
                } else {
                    $attempts  = (int)$item['attempts'] + 1;
                    $newStatus = $attempts >= (int)$item['max_attempts'] ? 'failed' : 'pending';
                    $this->db->prepare(
                        "UPDATE task_notifications_queue SET status=?, error_msg='dispatch returned false' WHERE id=?"
                    )->execute([$newStatus, $qId]);
                    $stats['failed']++;
                }

            } catch (\Throwable $e) {
                $attempts  = (int)$item['attempts'] + 1;
                $newStatus = $attempts >= (int)$item['max_attempts'] ? 'failed' : 'pending';
                $this->db->prepare(
                    "UPDATE task_notifications_queue SET status=?, error_msg=? WHERE id=?"
                )->execute([$newStatus, mb_substr($e->getMessage(), 0, 500), $qId]);
                $this->logError((int)$item['task_id'], (int)$item['user_id'], $item['event_type'], $item['channel'], $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  POST-SAVE DIAGNOSTICS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Automatyczny test po zapisie — sprawdza wszystkie kanały i logi.
     *
     * Wywoływany natychmiast po task_notify_*() lub po saveTask().
     * Nie rzuca wyjątków — zawsze zwraca tablicę z wynikami.
     *
     * @return array{
     *   ok:            bool,
     *   task_id:       int,
     *   event:         string,
     *   checked_at:    string,
     *   checks:        array<array{test:string, pass:bool|null, msg:string}>,
     *   warnings:      string[],
     *   recent_errors: array[],
     * }
     */
    public function runPostSaveTest(int $taskId, string $eventType): array
    {
        $result = [
            'ok'            => true,
            'task_id'       => $taskId,
            'event'         => $eventType,
            'checked_at'    => date('Y-m-d H:i:s'),
            'checks'        => [],
            'warnings'      => [],
            'recent_errors' => [],
        ];

        // ── Test 1: Zadanie istnieje w bazie ─────────────────────────────────
        try {
            $task = $this->db->prepare("SELECT id, title FROM tasks WHERE id=? AND deleted_at IS NULL");
            $task->execute([$taskId]);
            $taskRow = $task->fetch(\PDO::FETCH_ASSOC);

            if (!$taskRow) {
                $result['ok']       = false;
                $result['checks'][] = ['test' => 'task_exists', 'pass' => false, 'msg' => 'Zadanie nie istnieje w bazie — rollback?'];
                return $result;
            }
            $result['checks'][] = ['test' => 'task_exists', 'pass' => true, 'msg' => 'Zadanie zapisane: „' . ($taskRow['title'] ?? '') . '"'];
        } catch (\Throwable $e) {
            $result['ok']       = false;
            $result['checks'][] = ['test' => 'task_exists', 'pass' => false, 'msg' => 'Błąd DB: ' . $e->getMessage()];
            return $result;
        }

        // ── Test 2: In-app w tabeli notifications (ostatnie 5 min) ───────────
        try {
            $nRow = $this->db->prepare(
                "SELECT COUNT(*) AS c FROM notifications
                 WHERE url LIKE ? AND type='task'
                   AND created_at >= datetime('now','-5 minutes','localtime')"
            );
            $nRow->execute(['%task=' . $taskId . '%']);
            $nCnt = (int)($nRow->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

            if ($nCnt > 0) {
                $result['checks'][] = ['test' => 'inapp_created', 'pass' => true,
                    'msg' => "In-app: {$nCnt} powiadomień w tabeli notifications"];
            } else {
                $result['checks'][] = ['test' => 'inapp_created', 'pass' => false,
                    'msg' => 'Brak in-app (ostatnie 5 min) — brak odbiorców lub błąd notif_create()'];
                $result['warnings'][] = 'Brak odbiorców in-app może oznaczać, że brak liderów w obszarze roboczym.';
            }
        } catch (\Throwable $e) {
            $result['checks'][] = ['test' => 'inapp_created', 'pass' => null, 'msg' => 'notifications niedostępna: ' . $e->getMessage()];
        }

        // ── Test 3: E-mail wysłany → wpis w task_notification_log ────────────
        try {
            $lRow = $this->db->prepare(
                "SELECT COUNT(*) AS c, GROUP_CONCAT(DISTINCT channel) AS ch
                 FROM task_notification_log
                 WHERE event_type=? AND ref_id=?
                   AND sent_at >= datetime('now','-5 minutes','localtime')"
            );
            $lRow->execute([$eventType, $taskId]);
            $log = $lRow->fetch(\PDO::FETCH_ASSOC);
            $lCnt = (int)($log['c'] ?? 0);

            if ($lCnt > 0) {
                $result['checks'][] = ['test' => 'send_log', 'pass' => true,
                    'msg' => "Wysłano: {$lCnt} wpisów w logu, kanały: " . ($log['ch'] ?? '?')];
            } else {
                $result['checks'][] = ['test' => 'send_log', 'pass' => false,
                    'msg' => 'Brak wpisów w task_notification_log (ostatnie 5 min)'];
                $result['warnings'][] = 'Brak logu e-mail: możliwy dedup, rate limit (20/dzień), brak e-maila u odbiorcy lub błąd SMTP/M365.';
            }
        } catch (\Throwable $e) {
            $result['checks'][] = ['test' => 'send_log', 'pass' => null, 'msg' => 'task_notification_log niedostępna: ' . $e->getMessage()];
        }

        // ── Test 4: Wpisy w kolejce task_notifications_queue ─────────────────
        try {
            $qRow = $this->db->prepare(
                "SELECT COUNT(*) AS c, GROUP_CONCAT(DISTINCT status) AS st
                 FROM task_notifications_queue
                 WHERE task_id=? AND event_type=?
                   AND created_at >= datetime('now','-5 minutes','localtime')"
            );
            $qRow->execute([$taskId, $eventType]);
            $q = $qRow->fetch(\PDO::FETCH_ASSOC);
            $qCnt = (int)($q['c'] ?? 0);

            if ($qCnt > 0) {
                $result['checks'][] = ['test' => 'queue_entries', 'pass' => true,
                    'msg' => "Kolejka: {$qCnt} wpisów, statusy: " . ($q['st'] ?? '?')];
            } else {
                $result['checks'][] = ['test' => 'queue_entries', 'pass' => null,
                    'msg' => 'Brak wpisów w task_notifications_queue — system nie używa kolejki dla tego zdarzenia'];
            }
        } catch (\Throwable $e) {
            $result['checks'][] = ['test' => 'queue_entries', 'pass' => null, 'msg' => 'task_notifications_queue niedostępna'];
        }

        // ── Test 5: Kanał e-mail aktywny ─────────────────────────────────────
        $result['checks'][] = $this->checkEmailChannel();

        // ── Test 6: Backlog w mail_queue ─────────────────────────────────────
        try {
            $mqRow = $this->db->prepare(
                "SELECT COUNT(*) AS c FROM mail_queue WHERE status IN ('pending','retry')"
            );
            $mqRow->execute();
            $mqCnt = (int)($mqRow->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

            if ($mqCnt > 100) {
                $result['checks'][] = ['test' => 'mail_queue_backlog', 'pass' => false,
                    'msg' => "mail_queue backlog: {$mqCnt} oczekujących e-maili — opóźnione dostarczenie"];
                $result['warnings'][] = "Uruchom mail_queue worker: php cron/mail_queue.php";
            } else {
                $result['checks'][] = ['test' => 'mail_queue_backlog', 'pass' => true,
                    'msg' => "mail_queue OK: {$mqCnt} oczekujących"];
            }
        } catch (\Throwable $e) {
            $result['checks'][] = ['test' => 'mail_queue_backlog', 'pass' => null, 'msg' => 'mail_queue niedostępna'];
        }

        // ── Test 7: Błędy z task_notification_errors ─────────────────────────
        try {
            $eRow = $this->db->prepare(
                "SELECT event_type, channel, error_msg, created_at
                 FROM task_notification_errors
                 WHERE task_id=? AND created_at >= datetime('now','-10 minutes','localtime')
                 ORDER BY created_at DESC LIMIT 5"
            );
            $eRow->execute([$taskId]);
            $errors = $eRow->fetchAll(\PDO::FETCH_ASSOC);

            if (!empty($errors)) {
                $result['ok']           = false;
                $result['recent_errors'] = $errors;
                $result['checks'][]     = ['test' => 'error_log', 'pass' => false,
                    'msg' => count($errors) . ' błędów w logu diagnostycznym (ostatnie 10 min)'];
            } else {
                $result['checks'][] = ['test' => 'error_log', 'pass' => true, 'msg' => 'Brak błędów w task_notification_errors'];
            }
        } catch (\Throwable $e) {
            // Tabela może jeszcze nie istnieć na starszych instalacjach
            $result['checks'][] = ['test' => 'error_log', 'pass' => null, 'msg' => 'task_notification_errors niedostępna (stara instalacja?)'];
        }

        // Wynik ogólny: czy jakikolwiek test hard-fail?
        $hardFails = array_filter($result['checks'], fn($c) => $c['pass'] === false);
        if (!empty($hardFails)) {
            $result['ok'] = false;
        }

        // Zapisz do PHP error_log jeśli coś nie gra
        if (!$result['ok']) {
            error_log('[NOTIF_DIAG] task=' . $taskId . ' event=' . $eventType
                . ' fails=' . count($hardFails)
                . ' | ' . json_encode(array_column($hardFails, 'msg')));
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PRIVATE: WYSYŁKA Z KOLEJKI
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Przetwarza jeden element kolejki.
     * @return true=wysłano, false=błąd, null=pominięto
     */
    private function dispatch(array $item): ?bool
    {
        $userId    = (int)$item['user_id'];
        $taskId    = (int)$item['task_id'];
        $eventType = $item['event_type'];
        $channel   = $item['channel'];
        $payload   = json_decode($item['payload'] ?? '{}', true) ?: [];

        if ($channel === 'inapp')  return $this->dispatchInApp($userId, $taskId, $payload);
        if ($channel === 'email')  return $this->dispatchEmail($userId, $taskId, $eventType, $payload);
        if ($channel === 'sms')    return $this->dispatchSms($userId, $taskId, $eventType, $payload);

        return null;
    }

    private function dispatchInApp(int $userId, int $taskId, array $payload): ?bool
    {
        try {
            require_once __DIR__ . '/notifications.php';
            notif_create(
                $userId, 'task',
                $payload['title'] ?? 'Powiadomienie zadania',
                $payload['body']  ?? '',
                '/tasks/index.php?task=' . $taskId
            );
            return true;
        } catch (\Throwable $e) {
            $this->logError($taskId, $userId, 'inapp', 'inapp', $e->getMessage());
            return false;
        }
    }

    private function dispatchEmail(int $userId, int $taskId, string $eventType, array $payload): ?bool
    {
        try {
            require_once __DIR__ . '/approval.php';
            require_once __DIR__ . '/task_notify.php';

            $u = $this->db->prepare("SELECT name, email FROM users WHERE id=? AND is_active=1");
            $u->execute([$userId]);
            $user = $u->fetch(\PDO::FETCH_ASSOC);
            if (!$user || !$user['email']) return null;

            if (!_tn_should_send($userId, $eventType, $taskId)) return null;

            $subject = $payload['subject'] ?? 'Powiadomienie: ' . ($payload['task_title'] ?? '#' . $taskId);
            $html    = $payload['html']    ?? '<p>' . htmlspecialchars($subject) . '</p>';

            $ok = (bool)approval_send_email($user['email'], $subject, $html, 'task', null, 20);

            if ($ok) {
                _tn_log($userId, $eventType, $taskId, 'email');
            } else {
                $this->logError($taskId, $userId, $eventType, 'email', 'approval_send_email() zwróciło false/0');
            }

            return $ok;

        } catch (\Throwable $e) {
            $this->logError($taskId, $userId, $eventType, 'email', $e->getMessage());
            return false;
        }
    }

    private function dispatchSms(int $userId, int $taskId, string $eventType, array $payload): ?bool
    {
        try {
            require_once __DIR__ . '/sms.php';
            require_once __DIR__ . '/task_notify.php';
            if (!sms_is_enabled()) return null;

            $pref = task_notify_get_pref($userId);
            if (empty($pref['notify_sms'])) return null;

            $u = $this->db->prepare("SELECT phone_number FROM users WHERE id=?");
            $u->execute([$userId]);
            $phone = $u->fetch(\PDO::FETCH_ASSOC)['phone_number'] ?? '';
            if (!$phone) return null;

            $msg = $payload['sms_text'] ?? 'FEER SZO. Nowe powiadomienie zadania.';
            $ok  = (bool)sms_send($phone, $msg);
            if ($ok) {
                _tn_log($userId, $eventType, $taskId, 'sms');
            }
            return $ok;

        } catch (\Throwable $e) {
            $this->logError($taskId, $userId, $eventType, 'sms', $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PRIVATE: HELPERY
    // ─────────────────────────────────────────────────────────────────────────

    /** INSERT do kolejki — używaj wewnątrz aktywnej transakcji lub solo. */
    private function queueInTransaction(
        int    $taskId,
        int    $userId,
        string $eventType,
        string $channel,
        array  $payload    = [],
        string $scheduleAt = ''
    ): int {
        $this->db->prepare(
            "INSERT INTO task_notifications_queue
             (user_id, task_id, event_type, channel, payload, scheduled_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $userId,
            $taskId,
            $eventType,
            $channel,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $scheduleAt ?: date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function markQueue(int $qId, string $status): void
    {
        $this->db->prepare(
            "UPDATE task_notifications_queue SET status=?, sent_at=datetime('now','localtime') WHERE id=?"
        )->execute([$status, $qId]);
    }

    private function checkEmailChannel(): array
    {
        try {
            if (!function_exists('approval_send_email')) {
                return ['test' => 'email_channel', 'pass' => false, 'msg' => 'approval_send_email() nie załadowana'];
            }

            // Sprawdź M365
            $m365 = $this->db->prepare("SELECT value FROM settings WHERE key='m365_tenant_id' LIMIT 1");
            $m365->execute();
            if ($m365->fetchColumn()) {
                return ['test' => 'email_channel', 'pass' => true, 'msg' => 'E-mail: M365 Graph API skonfigurowany'];
            }

            // Sprawdź SMTP
            $smtp = $this->db->prepare("SELECT value FROM settings WHERE key='smtp_host' LIMIT 1");
            $smtp->execute();
            if ($smtp->fetchColumn()) {
                return ['test' => 'email_channel', 'pass' => true, 'msg' => 'E-mail: SMTP skonfigurowany'];
            }

            return ['test' => 'email_channel', 'pass' => true, 'msg' => 'E-mail: dostępna funkcja mail() (ostatni fallback)'];

        } catch (\Throwable $e) {
            return ['test' => 'email_channel', 'pass' => false, 'msg' => 'Błąd sprawdzania e-mail: ' . $e->getMessage()];
        }
    }

    private function logError(
        int    $taskId,
        int    $userId,
        string $eventType,
        string $channel,
        string $errorMsg,
        array  $context = []
    ): void {
        try {
            $this->db->prepare(
                "INSERT INTO task_notification_errors
                 (task_id, user_id, event_type, channel, error_msg, context)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([
                $taskId,
                $userId,
                $eventType,
                $channel,
                mb_substr($errorMsg, 0, 1000),
                json_encode($context, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $inner) {
            error_log('[NOTIF_SVC] Log error failed: ' . $inner->getMessage() . ' | orig: ' . $errorMsg);
        }
    }

    private function ensureSchema(): void
    {
        try {
            // Kolejka powiadomień z retries i statusami
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS task_notifications_queue (
                    id           INTEGER  PRIMARY KEY AUTOINCREMENT,
                    user_id      INTEGER  NOT NULL,
                    task_id      INTEGER  NOT NULL,
                    event_type   TEXT     NOT NULL,
                    channel      TEXT     NOT NULL DEFAULT 'email',
                    payload      TEXT     NOT NULL DEFAULT '{}',
                    status       TEXT     NOT NULL DEFAULT 'pending',
                    error_msg    TEXT     DEFAULT NULL,
                    attempts     INTEGER  NOT NULL DEFAULT 0,
                    max_attempts INTEGER  NOT NULL DEFAULT 3,
                    scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    sent_at      DATETIME DEFAULT NULL,
                    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_tnq_pending ON task_notifications_queue(status, scheduled_at)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_tnq_task   ON task_notifications_queue(task_id, event_type)");

            // Log błędów diagnostycznych
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS task_notification_errors (
                    id         INTEGER  PRIMARY KEY AUTOINCREMENT,
                    task_id    INTEGER  NOT NULL DEFAULT 0,
                    user_id    INTEGER  NOT NULL DEFAULT 0,
                    event_type TEXT     NOT NULL,
                    channel    TEXT     NOT NULL DEFAULT 'email',
                    error_msg  TEXT     NOT NULL,
                    context    TEXT     NOT NULL DEFAULT '{}',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_tne_task ON task_notification_errors(task_id, created_at)");

        } catch (\Throwable $e) {
            // Schemat może już istnieć — ignoruj
        }
    }
}
