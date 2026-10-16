<?php
/**
 * modules/it_control/logic/itControlStore.php — historia przebiegów kontroli IT.
 *
 * itc_runs      — jeden przebieg zestawu (suite 'logs' / 'ops'): status zbiorczy, liczniki.
 * itc_results   — wynik każdego sprawdzenia w przebiegu (znaleziska, rekomendacje, metryki jako JSON).
 * itc_mail_log  — każda wysyłka (raport/alert), także wstrzymana przez bezpiecznik albo próbna.
 */

function itControlMigrate(): void {
    if (szo_schema_current('it_control_migrate', __FILE__)) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS itc_runs (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        suite            VARCHAR(20) NOT NULL,
        trigger_         VARCHAR(20) NOT NULL DEFAULT 'cron',
        status           VARCHAR(10) NOT NULL,
        events_processed INTEGER NOT NULL DEFAULT 0,
        critical_count   INTEGER NOT NULL DEFAULT 0,
        warning_count    INTEGER NOT NULL DEFAULT 0,
        fail_count       INTEGER NOT NULL DEFAULT 0,
        started_at       DATETIME NOT NULL,
        finished_at      DATETIME,
        duration_ms      INTEGER NOT NULL DEFAULT 0,
        summary_json     TEXT,
        created_by       INTEGER
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_itc_runs_suite ON itc_runs(suite, started_at)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS itc_results (
        id                   INTEGER PRIMARY KEY AUTOINCREMENT,
        run_id               INTEGER NOT NULL,
        check_id             VARCHAR(80) NOT NULL,
        title                VARCHAR(255) NOT NULL DEFAULT '',
        status               VARCHAR(10) NOT NULL,
        events_processed     INTEGER NOT NULL DEFAULT 0,
        summary              TEXT,
        findings_json        TEXT,
        recommendations_json TEXT,
        metrics_json         TEXT,
        duration_ms          INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_itc_results_run ON itc_results(run_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_itc_results_check ON itc_results(check_id)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS itc_mail_log (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        kind        VARCHAR(10) NOT NULL,
        run_ids     VARCHAR(255) NOT NULL DEFAULT '',
        fingerprint VARCHAR(64) NOT NULL DEFAULT '',
        recipients  TEXT,
        subject     VARCHAR(255) NOT NULL DEFAULT '',
        mail_ids    VARCHAR(255) NOT NULL DEFAULT '',
        status      VARCHAR(12) NOT NULL,
        reason      TEXT,
        created_at  DATETIME NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_itc_mail_kind ON itc_mail_log(kind, status, created_at)");
}

/** Zapis przebiegu z wynikami (transakcja). Zwraca id przebiegu. */
function itControlSaveRun(array $run): int {
    itControlMigrate();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO itc_runs (suite, trigger_, status, events_processed, critical_count, warning_count, fail_count,
                         started_at, finished_at, duration_ms, summary_json, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$run['suite'], $run['trigger'], $run['status'], $run['events_processed'], $run['critical_count'],
                       $run['warning_count'], $run['fail_count'], $run['started_at'], $run['finished_at'], $run['duration_ms'],
                       json_encode($run['summary'] ?? [], JSON_UNESCAPED_UNICODE), $run['created_by'] ?? null]);
        $id = (int)$pdo->lastInsertId();
        $st = $pdo->prepare("INSERT INTO itc_results (run_id, check_id, title, status, events_processed, summary,
                               findings_json, recommendations_json, metrics_json, duration_ms) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach ($run['results'] as $r) {
            $st->execute([$id, $r['check_id'], $r['title'], $r['status'], $r['events_processed'], $r['summary'],
                          json_encode($r['findings'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                          json_encode($r['recommendations'], JSON_UNESCAPED_UNICODE),
                          json_encode($r['metrics'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $r['duration_ms']]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

/** Przebieg z wynikami (lub null). */
function itControlLoadRun(int $id): ?array {
    try {
        $run = db_one("SELECT * FROM itc_runs WHERE id=?", [$id]);
        if (!$run) return null;
        $run['trigger'] = $run['trigger_'];
        $run['summary'] = json_decode((string)$run['summary_json'], true) ?: [];
        $run['results'] = [];
        foreach (db_all("SELECT * FROM itc_results WHERE run_id=? ORDER BY id", [$id]) as $r) {
            $r['findings']        = json_decode((string)$r['findings_json'], true) ?: [];
            $r['recommendations'] = json_decode((string)$r['recommendations_json'], true) ?: [];
            $r['metrics']         = json_decode((string)$r['metrics_json'], true) ?: [];
            $run['results'][] = $r;
        }
        return $run;
    } catch (\Throwable $e) { return null; }
}

/** Ostatni przebieg zestawu (z wynikami). */
function itControlLatestRun(string $suite): ?array {
    try {
        $r = db_one("SELECT id FROM itc_runs WHERE suite=? ORDER BY id DESC LIMIT 1", [$suite]);
        return $r ? itControlLoadRun((int)$r['id']) : null;
    } catch (\Throwable $e) { return null; }
}

/** Historia przebiegów (bez wyników). */
function itControlHistory(?string $suite = null, int $limit = 50, ?string $since = null): array {
    $w = ['1=1']; $p = [];
    if ($suite) { $w[] = 'suite=?'; $p[] = $suite; }
    if ($since) { $w[] = 'started_at>=?'; $p[] = $since; }
    try {
        return db_all("SELECT * FROM itc_runs WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT " . max(1, $limit), $p);
    } catch (\Throwable $e) { return []; }
}

/** Retencja: usuwa przebiegi starsze niż N dni. Zwraca liczbę usuniętych. */
function itControlPrune(int $days): int {
    if ($days < 7) $days = 7;
    $cut = date('Y-m-d H:i:s', time() - $days * 86400);
    try {
        $ids = array_column(db_all("SELECT id FROM itc_runs WHERE started_at < ?", [$cut]), 'id');
        if (!$ids) return 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            db()->exec("DELETE FROM itc_results WHERE run_id IN ($in)");
            db()->exec("DELETE FROM itc_runs WHERE id IN ($in)");
        }
        db()->prepare("DELETE FROM itc_mail_log WHERE created_at < ?")->execute([$cut]);
        return count($ids);
    } catch (\Throwable $e) { return 0; }
}

function itControlMailLog(array $row): void {
    itControlMigrate();
    db()->prepare("INSERT INTO itc_mail_log (kind, run_ids, fingerprint, recipients, subject, mail_ids, status, reason, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$row['kind'], $row['run_ids'] ?? '', $row['fingerprint'] ?? '', $row['recipients'] ?? '',
                   mb_substr((string)($row['subject'] ?? ''), 0, 255), $row['mail_ids'] ?? '', $row['status'],
                   $row['reason'] ?? '', date('Y-m-d H:i:s')]);
}

function itControlMailHistory(int $limit = 30): array {
    try { return db_all("SELECT * FROM itc_mail_log ORDER BY id DESC LIMIT " . max(1, $limit)); }
    catch (\Throwable $e) { return []; }
}
