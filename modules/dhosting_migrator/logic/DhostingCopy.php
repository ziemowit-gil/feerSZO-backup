<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/logic/DhostingCopy.php — kopiowanie poczty z Microsoft 365 na skrzynkę dhosting (w tle, CRON).
 *
 * Zadanie (dhosting_copy_jobs) powstaje przy zakładaniu skrzynki / nadawaniu nowego hasła (admin albo użytkownik), bo tylko
 * wtedy znamy hasło do skrzynki dhosting — albo gdy użytkownik sam je poda. Hasło trzymamy zaszyfrowane (fcEncrypt) wyłącznie
 * do końca kopiowania: po zakończeniu, anulowaniu albo ostatecznym błędzie jest kasowane.
 *
 * Przebieg (agent cron/agents/dhosting_copy.php co 5 min, porcja ~4 min):
 *  1. plan: foldery z Graph (rekurencyjnie, bez Skrzynki nadawczej, Historii konwersacji, Problemów z synchronizacją…)
 *     → foldery IMAP (Odebrane → INBOX, Wysłane/Szkice/Usunięte/Wiadomości-śmieci/Archiwum → foldery specjalne serwera
 *     wg SPECIAL-USE albo nazw; reszta z tą samą hierarchią), brakujące tworzymy;
 *  2. wiadomości folderami, najstarsze najpierw: MIME z Graph → APPEND na dhosting (flagi \Seen, \Flagged, \Draft, data odbioru);
 *     każda skopiowana trafia do dhosting_copy_items (skrzynka + identyfikator niezmienny Graph) — wznowienie ani ponowne
 *     kopiowanie tej samej skrzynki (np. dosłanie nowych wiadomości) nie robi duplikatów; nieudane próbuje kolejne zadanie.
 *  3. Niedostępność (Microsoft, dhosting, IMAP, także odrzucone logowanie) → status „czeka”, ponowienie co 6 h
 *     (polecenie 2026-11-13), najdłużej 7 dni bez postępu — potem błąd i skasowanie hasła.
 */
require_once dirname(__DIR__) . '/lib/DhostingConfig.php';
require_once dirname(__DIR__) . '/lib/DhostingImap.php';
require_once dirname(__DIR__) . '/lib/DhostingGraphMail.php';
require_once dirname(__DIR__) . '/lib/DhostingHelpdesk.php';

final class DhostingCopy
{
    public const RETRY_HOURS = 6;
    public const MAX_ATTEMPTS = 28;            // 28 × 6 h = 7 dni bez postępu
    private const PASS_CTX = 'dhosting_copy_jobs.password_enc';
    public const ACTIVE = ['queued', 'running', 'waiting'];
    public const LABELS = [
        'queued' => 'w kolejce', 'running' => 'kopiuje', 'waiting' => 'czeka na ponowienie',
        'done' => 'zakończone', 'error' => 'błąd', 'cancelled' => 'anulowane',
    ];

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        if (function_exists('szo_schema_current') && szo_schema_current('dhosting_copy_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS dhosting_copy_jobs (
            id INTEGER PRIMARY KEY,
            mailbox VARCHAR(190) NOT NULL,
            source VARCHAR(190) NOT NULL,
            user_id INTEGER NULL,
            via VARCHAR(10) NOT NULL DEFAULT '',
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(19) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'queued',
            password_enc TEXT NULL,
            state_json TEXT NULL,
            total_items INTEGER NOT NULL DEFAULT 0,
            copied_items INTEGER NOT NULL DEFAULT 0,
            failed_items INTEGER NOT NULL DEFAULT 0,
            bytes INTEGER NOT NULL DEFAULT 0,
            attempts INTEGER NOT NULL DEFAULT 0,
            next_run_at VARCHAR(19) NULL,
            locked_until VARCHAR(19) NULL,
            started_at VARCHAR(19) NULL,
            finished_at VARCHAR(19) NULL,
            updated_at VARCHAR(19) NULL,
            last_error TEXT NULL)");
        try { db()->exec("CREATE INDEX idx_dhcopy_mailbox ON dhosting_copy_jobs(mailbox)"); } catch (\Throwable $e) {}
        try { db()->exec("CREATE INDEX idx_dhcopy_status ON dhosting_copy_jobs(status, next_run_at)"); } catch (\Throwable $e) {}
        // result: 1 = skopiowana, 2 = zniknęła w trakcie (pomijamy), 0 = nieudana (np. za duża) — ponawia dopiero inne zadanie
        db()->exec("CREATE TABLE IF NOT EXISTS dhosting_copy_items (
            mailbox VARCHAR(190) NOT NULL,
            msg_id VARCHAR(255) NOT NULL,
            job_id INTEGER NOT NULL,
            result INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (mailbox, msg_id))");
    }

    /** Czy kopiowanie jest możliwe (serwer IMAP dhosting — domyślnie imap.dpoczta.pl — i skonfigurowany Microsoft 365). */
    public static function available(): bool
    {
        return DhostingConfig::imapAddress() !== '' && DhostingGraphMail::configured();
    }

    // ── Kolejka ────────────────────────────────────────────────────────────────────────────────

    /** Dodaje zadanie (albo odświeża aktywne dla tej skrzynki nowym hasłem). $source = id albo login konta Microsoft. */
    public static function queue(string $mailbox, string $source, string $password, ?int $userId, string $via, string $by): int
    {
        self::migrate();
        $mailbox = strtolower($mailbox);
        $now = date('Y-m-d H:i:s');
        $enc = (string)fcEncrypt($password, self::PASS_CTX);
        $active = self::activeFor($mailbox);
        if ($active) {
            db()->prepare("UPDATE dhosting_copy_jobs SET password_enc=?, source=?, status='queued', attempts=0, next_run_at=?, last_error=NULL, updated_at=? WHERE id=?")
                ->execute([$enc, $source, $now, $now, (int)$active['id']]);
            return (int)$active['id'];
        }
        db()->prepare("INSERT INTO dhosting_copy_jobs (mailbox, source, user_id, via, created_by, created_at, status, password_enc, next_run_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, 'queued', ?, ?, ?)")
            ->execute([$mailbox, $source, $userId, $via, $by, $now, $enc, $now, $now]);
        return (int)db()->lastInsertId();
    }

    public static function activeFor(string $mailbox): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_copy_jobs WHERE mailbox=? AND status IN ('queued','running','waiting') ORDER BY id DESC LIMIT 1", [strtolower($mailbox)]);
        return $r ?: null;
    }

    public static function latestFor(string $mailbox): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_copy_jobs WHERE mailbox=? ORDER BY id DESC LIMIT 1", [strtolower($mailbox)]);
        return $r ?: null;
    }

    /** Zadania do panelu admina (najnowsze najpierw), bez haseł. */
    public static function jobs(int $limit = 200): array
    {
        self::migrate();
        $st = db()->prepare("SELECT id, mailbox, source, user_id, via, created_by, created_at, status, total_items, copied_items, failed_items, bytes,
                                    attempts, next_run_at, started_at, finished_at, updated_at, last_error
                             FROM dhosting_copy_jobs ORDER BY CASE WHEN status IN ('queued','running','waiting') THEN 0 ELSE 1 END, id DESC LIMIT " . max(1, $limit));
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function job(int $id): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM dhosting_copy_jobs WHERE id=?", [$id]);
        return $r ?: null;
    }

    public static function cancel(int $id): void
    {
        self::migrate();
        $q = db()->prepare("UPDATE dhosting_copy_jobs SET status='cancelled', password_enc=NULL, finished_at=?, updated_at=? WHERE id=? AND status IN ('queued','running','waiting')");
        $q->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        if ($q->rowCount() > 0 && ($job = self::job($id))) self::helpdesk($job, 'Anulowano kopiowanie poczty (zadanie #' . $id . ').');
    }

    /** Zdarzenie kopiowania w zgłoszeniu Helpdesku skrzynki (DhostingHelpdesk; nigdy nie przerywa kopiowania). */
    private static function helpdesk(array $job, string $note): void
    {
        $src = (string)($job['source'] ?? '');
        DhostingHelpdesk::event((string)$job['mailbox'], $note,
            ['user_id' => (int)($job['user_id'] ?? 0), 'login' => str_contains($src, '@') ? $src : '']);
    }

    /** „Ponów teraz”: zadanie czekające wraca do kolejki najbliższego przebiegu CRON. */
    public static function runSoon(int $id): void
    {
        self::migrate();
        db()->prepare("UPDATE dhosting_copy_jobs SET status='queued', next_run_at=?, updated_at=? WHERE id=? AND status IN ('queued','waiting')")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
    }

    public static function percent(array $job): int
    {
        $t = (int)$job['total_items'];
        if ($job['status'] === 'done') return 100;
        return $t > 0 ? min(99, (int)floor(100 * (int)$job['copied_items'] / $t)) : 0;
    }

    // ── Przebieg (CRON) ────────────────────────────────────────────────────────────────────────

    /** Przetwarza zadania, których czas nadszedł, aż do $budget sekund. Zwraca linie dziennika. */
    public static function runDue(int $budget = 240): array
    {
        self::migrate();
        $deadline = time() + $budget;
        $log = [];
        if (!self::available()) return ['[SKIP] Kopiowanie poczty nieskonfigurowane (serwer IMAP dhosting albo Microsoft 365).'];
        $now = date('Y-m-d H:i:s');
        $due = db()->prepare("SELECT id FROM dhosting_copy_jobs WHERE status IN ('queued','running','waiting')
                              AND (next_run_at IS NULL OR next_run_at <= ?) AND (locked_until IS NULL OR locked_until < ?) ORDER BY id");
        $due->execute([$now, $now]);
        foreach ($due->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (time() >= $deadline - 10) break;
            $log[] = self::runJob((int)$id, $deadline);
        }
        return $log ?: ['[SKIP] Brak zadań kopiowania do wykonania.'];
    }

    public static function runJob(int $id, int $deadline): string
    {
        $now = date('Y-m-d H:i:s');
        $lock = db()->prepare("UPDATE dhosting_copy_jobs SET locked_until=?, status='running', started_at=COALESCE(started_at, ?), updated_at=?
                               WHERE id=? AND (locked_until IS NULL OR locked_until < ?) AND status IN ('queued','running','waiting')");
        $lock->execute([date('Y-m-d H:i:s', $deadline + 300), $now, $now, $id, $now]);
        if ($lock->rowCount() !== 1) return "[SKIP] #{$id} zajęte albo nieaktywne";

        $job = self::job($id);
        $state = json_decode((string)($job['state_json'] ?? ''), true) ?: [];
        $copiedBefore = (int)$job['copied_items'];
        $c = ['copied' => (int)$job['copied_items'], 'failed' => (int)$job['failed_items'], 'bytes' => (int)$job['bytes'], 'total' => (int)$job['total_items']];
        $imap = null;
        try {
            $pass = (string)fcDecrypt((string)$job['password_enc'], self::PASS_CTX);
            if ($pass === '') throw new RuntimeException('Brak hasła do nowej skrzynki — uruchom kopiowanie ponownie z hasłem.');
            $graph = new DhostingGraphMail();
            $imap = new DhostingImap(DhostingConfig::imapAddress());
            $imap->connect();
            $imap->login((string)$job['mailbox'], $pass);
            if (empty($state['folders'])) {
                $state = self::plan($graph, $imap, (string)$job['source']);
                $c['total'] = array_sum(array_column($state['folders'], 'total'));
                self::save($id, $state, $c, null);
            }
            $finished = self::copyFolders($id, (string)$job['mailbox'], $graph, $imap, (string)$job['source'], $state, $c, $deadline);
            $imap->logout();
            if ($finished) {
                db()->prepare("UPDATE dhosting_copy_jobs SET status='done', password_enc=NULL, finished_at=?, locked_until=NULL, next_run_at=NULL,
                               attempts=0, state_json=?, copied_items=?, failed_items=?, bytes=?, updated_at=?, last_error=? WHERE id=?")
                    ->execute([date('Y-m-d H:i:s'), json_encode($state, JSON_UNESCAPED_UNICODE), $c['copied'], $c['failed'], $c['bytes'], date('Y-m-d H:i:s'),
                               $c['failed'] ? $c['failed'] . ' wiadomości nie dało się skopiować (np. za duże dla serwera).' : null, $id]);
                try { if (function_exists('audit_log')) audit_log('dhosting.copy_done', ['mailbox' => $job['mailbox'], 'copied' => $c['copied'], 'failed' => $c['failed']], 0); } catch (\Throwable $e) {}
                self::helpdesk($job, 'Kopiowanie poczty zakończone: skopiowano ' . $c['copied'] . ' wiadomości'
                    . ($c['failed'] ? ', nie udało się ' . $c['failed'] . ' (np. za duże dla serwera)' : '') . '.');
                return "[OK] #{$id} {$job['mailbox']}: zakończone, skopiowano {$c['copied']}, błędów {$c['failed']}";
            }
            db()->prepare("UPDATE dhosting_copy_jobs SET status='queued', locked_until=NULL, next_run_at=?, attempts=0, last_error=NULL, updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
            return "[OK] #{$id} {$job['mailbox']}: {$c['copied']}/{$c['total']} — ciąg dalszy w następnym przebiegu";
        } catch (\Throwable $e) {
            if ($imap) { try { $imap->logout(); } catch (\Throwable $e2) {} }
            self::save($id, $state, $c, null);
            $attempts = ($c['copied'] > $copiedBefore ? 0 : (int)$job['attempts']) + 1;
            $msg = mb_substr($e->getMessage(), 0, 500);
            if ($attempts >= self::MAX_ATTEMPTS) {
                db()->prepare("UPDATE dhosting_copy_jobs SET status='error', password_enc=NULL, attempts=?, locked_until=NULL, next_run_at=NULL, finished_at=?, last_error=?, updated_at=? WHERE id=?")
                    ->execute([$attempts, date('Y-m-d H:i:s'), 'Przerwano po 7 dniach prób: ' . $msg, date('Y-m-d H:i:s'), $id]);
                self::helpdesk($job, 'Kopiowanie poczty przerwane po 7 dniach prób (skopiowano ' . $c['copied'] . ' z ' . $c['total'] . '). Ostatni błąd: ' . $msg);
                return "[ERROR] #{$id} {$job['mailbox']}: koniec prób — {$msg}";
            }
            $next = date('Y-m-d H:i:s', time() + self::RETRY_HOURS * 3600);
            db()->prepare("UPDATE dhosting_copy_jobs SET status='waiting', attempts=?, locked_until=NULL, next_run_at=?, last_error=?, updated_at=? WHERE id=?")
                ->execute([$attempts, $next, $msg, date('Y-m-d H:i:s'), $id]);
            // tylko pierwsza nieudana próba z rzędu — bez notatki co 6 h
            if ($attempts === 1) self::helpdesk($job, 'Kopiowanie poczty czeka: ' . $msg . ' Ponowienie co ' . self::RETRY_HOURS . ' h (najbliższe ' . $next . ').');
            return "[WAIT] #{$id} {$job['mailbox']}: {$msg} — ponowienie {$next}";
        }
    }

    /** Plan folderów: Graph → IMAP; brakujące foldery tworzy. */
    private static function plan(DhostingGraphMail $graph, DhostingImap $imap, string $source): array
    {
        $gFolders = $graph->folders($source);
        $existing = $imap->listFolders();
        $d = $imap->delimiter() ?: '/';
        $byName = [];
        $byAttr = [];
        foreach ($existing as $f) {
            $byName[mb_strtolower($f['name'])] = $f['name'];
            foreach ($f['attrs'] as $a) $byAttr[strtolower($a)] = $f['name'];
        }
        $specialMap = [
            'sentitems'    => ['\\sent', ['Sent', 'Sent Items', 'Sent Messages', 'Wysłane'], 'Sent'],
            'drafts'       => ['\\drafts', ['Drafts', 'Szkice'], 'Drafts'],
            'deleteditems' => ['\\trash', ['Trash', 'Deleted Items', 'Deleted Messages', 'Kosz'], 'Trash'],
            'junkemail'    => ['\\junk', ['Junk', 'Spam', 'Junk E-mail'], 'Junk'],
            'archive'      => ['\\archive', ['Archive', 'Archiwum'], 'Archive'],
        ];
        $targets = [];   // id Graph → nazwa UTF-8 na serwerze
        $out = [];
        foreach ($gFolders as $f) {
            $wk = $f['special'];
            if ($wk === 'inbox') {
                $name = 'INBOX';
            } elseif ($wk !== null && isset($specialMap[$wk])) {
                [$attr, $names, $default] = $specialMap[$wk];
                $name = $byAttr[$attr] ?? null;
                foreach ($names as $n) { if ($name === null && isset($byName[mb_strtolower($n)])) $name = $byName[mb_strtolower($n)]; }
                $name ??= $default;
            } else {
                $leaf = str_replace($d, '_', trim($f['name'])) ?: 'Folder';
                $parentPath = array_slice($f['path'], 0, -1);
                $parentName = null;
                if ($parentPath) {
                    // rodzic jest wcześniej na liście (przejście w głąb) — szukamy go po ścieżce
                    foreach ($out as $o) { if ($o['path'] === $parentPath) { $parentName = $o['target']; break; } }
                }
                $name = $parentName !== null ? $parentName . $d . $leaf : $leaf;
            }
            if ($name === 'INBOX' || isset($byName[mb_strtolower($name)])) {
                $raw = $name === 'INBOX' ? 'INBOX' : DhostingImap::encodeName($byName[mb_strtolower($name)]);
            } else {
                $raw = $imap->create($name);
                $byName[mb_strtolower($name)] = $name;
            }
            $out[] = ['id' => $f['id'], 'path' => $f['path'], 'special' => $wk, 'target' => $name, 'imap' => $raw,
                      'total' => (int)$f['total'], 'next' => '', 'done' => (int)$f['total'] === 0];
        }
        return ['folders' => $out, 'planned_at' => date('Y-m-d H:i:s')];
    }

    /** Kopiuje folder po folderze do $deadline. true = wszystko skopiowane. */
    private static function copyFolders(int $id, string $mailbox, DhostingGraphMail $graph, DhostingImap $imap, string $source, array &$state, array &$c, int $deadline): bool
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dhm');
        $seen = db()->prepare("SELECT job_id, result FROM dhosting_copy_items WHERE mailbox=? AND msg_id=?");
        $ins  = db()->prepare("INSERT INTO dhosting_copy_items (mailbox, msg_id, job_id, result) VALUES (?, ?, ?, ?)");
        $upd  = db()->prepare("UPDATE dhosting_copy_items SET job_id=?, result=? WHERE mailbox=? AND msg_id=?");
        $mark = function (string $mid, int $result) use ($ins, $upd, $mailbox, $id): void {
            try { $ins->execute([$mailbox, $mid, $id, $result]); }
            catch (\Throwable $e) { $upd->execute([$id, $result, $mailbox, $mid]); }
        };
        try {
            foreach ($state['folders'] as $fi => &$f) {
                if (!empty($f['done'])) continue;
                $resetOnce = false;
                while (true) {
                    if (time() >= $deadline) { self::save($id, $state, $c, null); return false; }
                    $cur = (string)$f['next'];
                    try {
                        $page = $graph->messagesPage($source, (string)$f['id'], $cur);
                    } catch (DhostingGraphGone $e) {
                        $f['done'] = true; break;                         // folder usunięty w trakcie
                    } catch (DhostingGraphUnavailable | DhostingGraphDenied $e) {
                        throw $e;
                    } catch (DhostingGraphException $e) {
                        if ($cur === '' || $resetOnce) throw $e;           // przeterminowany nextLink — od początku folderu (duplikaty odsiewa rejestr)
                        $f['next'] = ''; $resetOnce = true; continue;
                    }
                    foreach ($page['items'] as $m) {
                        if (time() >= $deadline) { $f['next'] = $cur; self::save($id, $state, $c, null); return false; }
                        $mid = (string)$m['id'];
                        $seen->execute([$mailbox, $mid]);
                        $prev = $seen->fetch(PDO::FETCH_ASSOC) ?: null;
                        $seen->closeCursor();
                        if ($prev) {
                            $mine = (int)$prev['job_id'] === $id;
                            if ((int)$prev['result'] === 1 && !$mine) { $c['copied']++; continue; }   // przeniesiona wcześniej innym zadaniem — jest na serwerze
                            if ((int)$prev['result'] !== 0 || $mine) continue;                          // już policzona w tym zadaniu / zniknęła
                        }
                        try {
                            $c['bytes'] += $graph->downloadMime($source, $mid, $tmp);
                        } catch (DhostingGraphGone $e) {
                            $mark($mid, 2); continue;        // wiadomość zniknęła w trakcie
                        }
                        $flags = [];
                        if (!empty($m['isRead'])) $flags[] = '\\Seen';
                        if (($m['flag']['flagStatus'] ?? '') === 'flagged') $flags[] = '\\Flagged';
                        if (!empty($m['isDraft'])) $flags[] = '\\Draft';
                        $ts = strtotime((string)($m['receivedDateTime'] ?? $m['sentDateTime'] ?? '')) ?: null;
                        try {
                            $imap->appendFile((string)$f['imap'], $tmp, $flags, $ts);
                            $mark($mid, 1);
                            $c['copied']++;
                        } catch (DhostingImapCommandFailed $e) {
                            $mark($mid, 0);               // np. za duża — liczymy jako nieudaną, idziemy dalej
                            $c['failed']++;
                        }
                        if (($c['copied'] + $c['failed']) % 25 === 0) self::save($id, $state, $c, null);
                    }
                    $f['next'] = (string)$page['next'];
                    if ($f['next'] === '') { $f['done'] = true; self::save($id, $state, $c, null); break; }
                }
            }
            unset($f);
            return true;
        } finally {
            @unlink($tmp);
        }
    }

    private static function save(int $id, array $state, array $c, ?string $err): void
    {
        db()->prepare("UPDATE dhosting_copy_jobs SET state_json=?, total_items=?, copied_items=?, failed_items=?, bytes=?, updated_at=? WHERE id=?")
            ->execute([$state ? json_encode($state, JSON_UNESCAPED_UNICODE) : null, max($c['total'], $c['copied'] + $c['failed']), $c['copied'], $c['failed'], $c['bytes'], date('Y-m-d H:i:s'), $id]);
    }

    /** Sprawdzenie hasła do skrzynki dhosting (samoobsługa). true = OK, null = serwer niedostępny, false = złe hasło. */
    public static function checkPassword(string $mailbox, string $password): ?bool
    {
        $imap = new DhostingImap(DhostingConfig::imapAddress(), 15);
        try {
            $imap->connect();
            $imap->login($mailbox, $password);
            $imap->logout();
            return true;
        } catch (DhostingImapAuthFailed $e) {
            return false;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
