<?php
declare(strict_types=1);

/**
 * modules/sso_logto/logic/CloudFilesCopy.php — kopiowanie plików z OneDrive (Microsoft 365) do chmury Nextcloud / ownCloud (w tle, CRON).
 *
 * Zadanie (sso_files_jobs) powstaje, gdy SZO nadaje hasło do konta w chmurze (migrator, Zarządzanie dostępem), bo zapis
 * w chmurze idzie przez WebDAV na koncie użytkownika (administrator chmury nie pisze do cudzych plików). Hasło trzymamy
 * zaszyfrowane (fcEncrypt) tylko do końca kopiowania: po zakończeniu, anulowaniu albo ostatecznym błędzie jest kasowane.
 *
 * Przebieg (agent cron/agents/sso_cloud_files.php co 5 min, porcja ~4 min, wznawianie bez duplikatów):
 *   foldery przechodzimy wszerz (kolejka w state_json), stronami po 200 pozycji z Graph; pliki trafiają do folderu
 *   „OneDrive” w chmurze z tą samą strukturą i datą modyfikacji (X-OC-Mtime). Każdy skopiowany plik zapisujemy
 *   w sso_files_items (konto w chmurze + id elementu OneDrive), więc ponowne zadanie dla tej samej osoby dosyła tylko nowe.
 *   Pomijamy notesy OneNote i skróty do cudzych plików oraz pliki ponad MAX_BYTES (pojedynczy PUT).
 *   Niedostępność (Microsoft, chmura) → „czeka”, ponowienie co RETRY_HOURS h, najdłużej MAX_ATTEMPTS prób bez postępu.
 *   Odrzucone hasło w chmurze albo brak uprawnienia Files.Read.All → błąd od razu (ponawianie nic nie da).
 */
require_once dirname(__DIR__) . '/lib/SsoConfig.php';
require_once dirname(__DIR__) . '/lib/GraphDrive.php';

final class CloudFilesCopy
{
    public const TARGET = 'OneDrive';
    public const MAX_BYTES = 2147483648;       // 2 GB — większe pliki wymagają wysyłki w częściach (nieobsługiwane)
    public const RETRY_HOURS = 6;
    public const MAX_ATTEMPTS = 28;            // 28 × 6 h = 7 dni bez postępu
    private const PASS_CTX = 'sso_files_jobs.password_enc';
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
        if (function_exists('szo_schema_current') && szo_schema_current('sso_files_migrate', __FILE__)) return;
        db()->exec("CREATE TABLE IF NOT EXISTS sso_files_jobs (
            id INTEGER PRIMARY KEY,
            cloud_uid VARCHAR(128) NOT NULL,
            source VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL DEFAULT '',
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            created_at VARCHAR(19) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'queued',
            password_enc TEXT NULL,
            state_json TEXT NULL,
            total_files INTEGER NOT NULL DEFAULT 0,
            copied_files INTEGER NOT NULL DEFAULT 0,
            skipped_files INTEGER NOT NULL DEFAULT 0,
            failed_files INTEGER NOT NULL DEFAULT 0,
            bytes INTEGER NOT NULL DEFAULT 0,
            attempts INTEGER NOT NULL DEFAULT 0,
            next_run_at VARCHAR(19) NULL,
            locked_until VARCHAR(19) NULL,
            started_at VARCHAR(19) NULL,
            finished_at VARCHAR(19) NULL,
            updated_at VARCHAR(19) NULL,
            last_error TEXT NULL)");
        try { db()->exec("CREATE INDEX idx_ssofiles_status ON sso_files_jobs(status, next_run_at)"); } catch (\Throwable $e) {}
        // result: 1 = skopiowany, 2 = pominięty (za duży / zniknął), 0 = nieudany — ponawia dopiero kolejne zadanie,
        //         3 = folder dodany do kolejki zadania job_id (żeby ponownie czytana strona nie dublowała folderów)
        db()->exec("CREATE TABLE IF NOT EXISTS sso_files_items (
            cloud_uid VARCHAR(128) NOT NULL,
            item_id VARCHAR(255) NOT NULL,
            job_id INTEGER NOT NULL,
            result INTEGER NOT NULL DEFAULT 1,
            PRIMARY KEY (cloud_uid, item_id))");
    }

    public static function available(): bool
    {
        return SsoConfig::cloudConfigured() && GraphDrive::configured();
    }

    /** Nowe zadanie albo odświeżenie aktywnego (nowe hasło, kolejka od nowa — skopiowane pliki i tak są pomijane). */
    public static function queue(string $cloudUid, string $source, string $password, string $email, string $by): int
    {
        self::migrate();
        $now = date('Y-m-d H:i:s');
        $enc = (string)fcEncrypt($password, self::PASS_CTX);
        $active = db_one("SELECT id FROM sso_files_jobs WHERE cloud_uid=? AND status IN ('queued','running','waiting') ORDER BY id DESC LIMIT 1", [$cloudUid]);
        if ($active) {
            db()->prepare("UPDATE sso_files_jobs SET password_enc=?, source=?, status='queued', attempts=0, next_run_at=?, last_error=NULL, updated_at=? WHERE id=?")
                ->execute([$enc, $source, $now, $now, (int)$active['id']]);
            return (int)$active['id'];
        }
        db()->prepare("INSERT INTO sso_files_jobs (cloud_uid, source, email, created_by, created_at, status, password_enc, next_run_at, updated_at)
                       VALUES (?, ?, ?, ?, ?, 'queued', ?, ?, ?)")
            ->execute([$cloudUid, $source, strtolower($email), $by, $now, $enc, $now, $now]);
        return (int)db()->lastInsertId();
    }

    public static function jobs(int $limit = 200): array
    {
        self::migrate();
        return db_all("SELECT id, cloud_uid, source, email, created_by, created_at, status, total_files, copied_files, skipped_files, failed_files, bytes,
                              attempts, next_run_at, started_at, finished_at, updated_at, last_error
                       FROM sso_files_jobs ORDER BY CASE WHEN status IN ('queued','running','waiting') THEN 0 ELSE 1 END, id DESC LIMIT " . max(1, $limit));
    }

    public static function latestFor(string $cloudUid): ?array
    {
        self::migrate();
        $r = $cloudUid !== '' ? db_one("SELECT * FROM sso_files_jobs WHERE cloud_uid=? ORDER BY id DESC LIMIT 1", [$cloudUid]) : null;
        return $r ?: null;
    }

    public static function job(int $id): ?array
    {
        self::migrate();
        $r = db_one("SELECT * FROM sso_files_jobs WHERE id=?", [$id]);
        return $r ?: null;
    }

    public static function cancel(int $id): bool
    {
        self::migrate();
        $q = db()->prepare("UPDATE sso_files_jobs SET status='cancelled', password_enc=NULL, finished_at=?, updated_at=? WHERE id=? AND status IN ('queued','running','waiting')");
        $q->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        return $q->rowCount() > 0;
    }

    public static function runSoon(int $id): void
    {
        self::migrate();
        db()->prepare("UPDATE sso_files_jobs SET status='queued', next_run_at=?, updated_at=? WHERE id=? AND status IN ('queued','waiting')")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
    }

    // ── Wykonanie ──────────────────────────────────────────────────────────────────────────

    public static function runDue(int $budget = 240): array
    {
        self::migrate();
        if (!self::available()) return ['[SKIP] Kopiowanie plików nieskonfigurowane (chmura albo Microsoft 365).'];
        $deadline = time() + $budget;
        $now = date('Y-m-d H:i:s');
        $ids = db_all("SELECT id FROM sso_files_jobs WHERE status IN ('queued','running','waiting')
                       AND (next_run_at IS NULL OR next_run_at <= ?) AND (locked_until IS NULL OR locked_until < ?) ORDER BY id", [$now, $now]);
        $log = [];
        foreach ($ids as $r) {
            if (time() >= $deadline - 10) break;
            $log[] = self::runJob((int)$r['id'], $deadline);
        }
        return $log ?: ['[SKIP] Brak zadań kopiowania plików.'];
    }

    public static function runJob(int $id, int $deadline): string
    {
        $now = date('Y-m-d H:i:s');
        $lock = db()->prepare("UPDATE sso_files_jobs SET locked_until=?, status='running', started_at=COALESCE(started_at, ?), updated_at=?
                               WHERE id=? AND (locked_until IS NULL OR locked_until < ?) AND status IN ('queued','running','waiting')");
        $lock->execute([date('Y-m-d H:i:s', $deadline + 300), $now, $now, $id, $now]);
        if ($lock->rowCount() !== 1) return "[SKIP] #{$id} zajęte albo nieaktywne";
        $job = self::job($id);
        $pass = '';
        try { $pass = (string)fcDecrypt((string)($job['password_enc'] ?? ''), self::PASS_CTX); } catch (\Throwable $e) {}
        if ($pass === '') return self::finish($id, 'error', 'Brak hasła do chmury — nadaj nowe hasło w SZO, żeby skopiować pliki.');

        $state = json_decode((string)($job['state_json'] ?? ''), true);
        if (!is_array($state) || !isset($state['queue'])) $state = ['queue' => [['root', '']], 'cur' => null];
        $cnt = ['total' => (int)$job['total_files'], 'copied' => (int)$job['copied_files'], 'skipped' => (int)$job['skipped_files'],
                'failed' => (int)$job['failed_files'], 'bytes' => (int)$job['bytes']];
        $progress = false;
        $graph = new GraphDrive();
        $cloud = SsoConfig::cloud();
        $uid = (string)$job['cloud_uid'];
        $src = (string)$job['source'];
        $tmp = tempnam(sys_get_temp_dir(), 'ssof');

        try {
            $cloud->davMkdirs($uid, $pass, self::TARGET);
            while (time() < $deadline - 20) {
                if ($state['cur'] === null) {
                    if (!$state['queue']) break;                                   // wszystko przejrzane
                    [$fid, $fpath] = array_shift($state['queue']);
                    $state['cur'] = ['id' => $fid, 'path' => $fpath, 'next' => null];
                }
                $cur = $state['cur'];
                try {
                    $page = $graph->children($src, (string)$cur['id'], $cur['next']);
                } catch (GraphDriveGone $e) {
                    $state['cur'] = null;                                          // folder zniknął w trakcie
                    continue;
                }
                $interrupted = false;
                foreach ($page['items'] as $it) {
                    if (time() >= $deadline - 20) { $interrupted = true; break; }   // ta sama strona w następnym przebiegu
                    $path = ($cur['path'] === '' ? '' : $cur['path'] . '/') . self::safeName($it['name']);
                    if ($it['folder']) {
                        // folder trafia do kolejki raz na zadanie (result 3 + job_id), także gdy strona jest czytana ponownie
                        if (!db_one("SELECT result FROM sso_files_items WHERE cloud_uid=? AND item_id=? AND result=3 AND job_id=?", [$uid, $it['id'], $id])) {
                            $cloud->davMkdirs($uid, $pass, self::TARGET . '/' . $path);
                            $state['queue'][] = [$it['id'], $path];
                            self::item($uid, $it['id'], $id, 3);
                        }
                        continue;
                    }
                    if (db_one("SELECT result FROM sso_files_items WHERE cloud_uid=? AND item_id=? AND result IN (1,2)", [$uid, $it['id']])) continue;
                    $cnt['total']++;
                    if ($it['skip'] || $it['size'] > self::MAX_BYTES) {
                        self::item($uid, $it['id'], $id, 2);
                        $cnt['skipped']++;
                        continue;
                    }
                    try {
                        $graph->download($src, $it['id'], $tmp);
                        $cnt['bytes'] += $cloud->davPut($uid, $pass, self::TARGET . '/' . $path, $tmp, $it['mtime'] ?: null);
                        self::item($uid, $it['id'], $id, 1);
                        $cnt['copied']++;
                        $progress = true;
                    } catch (GraphDriveGone $e) {
                        self::item($uid, $it['id'], $id, 2);
                        $cnt['skipped']++;
                    } catch (CloudApiException $e) {
                        if ($e instanceof CloudDenied || $e instanceof CloudFull || $e instanceof CloudUnavailable) throw $e;
                        self::item($uid, $it['id'], $id, 0);                       // np. niedozwolona nazwa w chmurze
                        $cnt['failed']++;
                    }
                    @file_put_contents($tmp, '');
                }
                if (!$interrupted) $state['cur'] = $page['next'] ? ['id' => $cur['id'], 'path' => $cur['path'], 'next' => $page['next']] : null;
                self::save($id, $state, $cnt);
                if ($interrupted || time() >= $deadline - 20) break;
            }
        } catch (CloudDenied | GraphDriveDenied $e) {
            @unlink($tmp);
            self::save($id, $state, $cnt);
            return self::finish($id, 'error', $e->getMessage());
        } catch (\Throwable $e) {
            @unlink($tmp);
            self::save($id, $state, $cnt);
            return self::wait($id, $job, $progress, $e->getMessage());
        }
        @unlink($tmp);
        self::save($id, $state, $cnt);
        if ($state['cur'] === null && !$state['queue']) {
            return self::finish($id, 'done', null, "[OK] #{$id} {$uid}: skopiowano {$cnt['copied']}, pominięto {$cnt['skipped']}, nieudane {$cnt['failed']}");
        }
        db()->prepare("UPDATE sso_files_jobs SET locked_until=NULL, attempts=0, next_run_at=?, updated_at=? WHERE id=?")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        return "[..] #{$id} {$uid}: {$cnt['copied']}/{$cnt['total']} — ciąg dalszy w następnym przebiegu";
    }

    /** Nazwa pliku/folderu bezpieczna dla chmury (ukośniki i znaki sterujące → „_”). */
    public static function safeName(string $n): string
    {
        $n = trim((string)preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '_', $n));
        return $n === '' || $n === '.' || $n === '..' ? '_' : $n;
    }

    private static function item(string $uid, string $itemId, int $jobId, int $result): void
    {
        $sql = DB_TYPE === 'sqlite'
            ? "INSERT OR REPLACE INTO sso_files_items (cloud_uid, item_id, job_id, result) VALUES (?, ?, ?, ?)"
            : "INSERT INTO sso_files_items (cloud_uid, item_id, job_id, result) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id), result=VALUES(result)";
        db()->prepare($sql)->execute([$uid, $itemId, $jobId, $result]);
    }

    private static function save(int $id, array $state, array $c): void
    {
        db()->prepare("UPDATE sso_files_jobs SET state_json=?, total_files=?, copied_files=?, skipped_files=?, failed_files=?, bytes=?, updated_at=? WHERE id=?")
            ->execute([json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $c['total'], $c['copied'], $c['skipped'], $c['failed'], $c['bytes'], date('Y-m-d H:i:s'), $id]);
    }

    private static function wait(int $id, array $job, bool $progress, string $msg): string
    {
        $attempts = $progress ? 1 : (int)$job['attempts'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS) return self::finish($id, 'error', 'Po ' . self::MAX_ATTEMPTS . ' próbach bez postępu: ' . $msg);
        db()->prepare("UPDATE sso_files_jobs SET status='waiting', attempts=?, next_run_at=?, locked_until=NULL, last_error=?, updated_at=? WHERE id=?")
            ->execute([$attempts, date('Y-m-d H:i:s', time() + self::RETRY_HOURS * 3600), mb_substr($msg, 0, 1000), date('Y-m-d H:i:s'), $id]);
        return "[WAIT] #{$id}: {$msg}";
    }

    private static function finish(int $id, string $status, ?string $error, ?string $line = null): string
    {
        db()->prepare("UPDATE sso_files_jobs SET status=?, password_enc=NULL, locked_until=NULL, finished_at=?, updated_at=?, last_error=? WHERE id=?")
            ->execute([$status, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $error !== null ? mb_substr($error, 0, 1000) : null, $id]);
        return $line ?? ($status === 'error' ? "[ERROR] #{$id}: {$error}" : "[{$status}] #{$id}");
    }

    public static function percent(array $job): int
    {
        $t = (int)($job['total_files'] ?? 0);
        if (($job['status'] ?? '') === 'done') return 100;
        return $t > 0 ? (int)min(99, floor(((int)$job['copied_files'] + (int)$job['skipped_files'] + (int)$job['failed_files']) * 100 / $t)) : 0;
    }
}
