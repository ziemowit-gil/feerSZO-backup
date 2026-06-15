<?php
/**
 * includes/updater.php
 * Wspólna logika aktualizacji KODU aplikacji (git) — używana przez:
 *   - upgrade.php   (panel webowy)
 *   - cli/migrate.php (CLI / docker/update.sh / cron)
 *
 * Migracje SCHEMATU bazy są osobno: migrate_tenant_db() w setup/setup_sql.php.
 * Ten plik zajmuje się tylko pobieraniem kodu z repozytorium git oraz cache PHP.
 *
 * Uwaga dot. uprawnień w kontenerze: repo jest bind-montowane z hosta, więc
 * .git bywa własnością innego użytkownika niż www-data → git odmawia operacji
 * ("dubious ownership"). Dlatego KAŻDE polecenie git uruchamiamy z
 * `-c safe.directory=<repo>`, co neutralizuje ten problem bez globalnej zmiany.
 */

if (!function_exists('upd_base_dir')) {

/** Katalog repozytorium (korzeń aplikacji). */
function upd_base_dir(): string {
    return defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
}

/**
 * Uruchom polecenie git w repo. Zwraca ['code'=>int, 'out'=>string].
 * code === 0 oznacza sukces. Wyjście zawiera stdout+stderr.
 */
function upd_git(string $args): array {
    $base = upd_base_dir();
    $full = 'git -C ' . escapeshellarg($base)
          . ' -c safe.directory=' . escapeshellarg($base)
          . ' ' . $args . ' 2>&1';

    if (function_exists('exec')) {
        $out = []; $code = 0;
        @exec($full, $out, $code);
        return ['code' => $code, 'out' => trim(implode("\n", $out))];
    }
    // Fallback: shell_exec (bez kodu wyjścia — heurystyka po treści).
    $raw = @shell_exec($full);
    return ['code' => ($raw === null ? 127 : 0), 'out' => trim((string)$raw)];
}

/** Czy git jest dostępny i katalog jest repozytorium. */
function upd_git_available(): bool {
    if (!is_dir(upd_base_dir() . '/.git')) return false;
    $r = upd_git('--version');
    return $r['code'] === 0 && str_contains($r['out'], 'git version');
}

/** Czy katalog .git jest zapisywalny (warunek konieczny dla pull). */
function upd_repo_writable(): bool {
    return is_writable(upd_base_dir() . '/.git');
}

/** Aktualna gałąź (np. "main") lub '' gdy detached/nieznana. */
function upd_current_branch(): string {
    $r = upd_git('rev-parse --abbrev-ref HEAD');
    $b = $r['code'] === 0 ? trim($r['out']) : '';
    return ($b && $b !== 'HEAD') ? $b : '';
}

/** Pełny hash lokalnego HEAD. */
function upd_local_head(): string {
    $r = upd_git('rev-parse HEAD');
    return $r['code'] === 0 ? trim($r['out']) : '';
}

/** Krótki hash dowolnej referencji (np. 'origin/main'). '' gdy brak. */
function upd_short(string $ref): string {
    $r = upd_git('rev-parse --short ' . escapeshellarg($ref));
    return $r['code'] === 0 ? trim($r['out']) : '';
}

/** Czy drzewo robocze ma niezacommitowane zmiany (porter status). */
function upd_is_dirty(): bool {
    $r = upd_git('status --porcelain');
    return $r['code'] === 0 && trim($r['out']) !== '';
}

/** Pobierz zmiany z origin (fetch). Zwraca ['ok'=>bool,'out'=>string]. */
function upd_fetch(?string $branch = null): array {
    $branch = $branch ?: (upd_current_branch() ?: 'main');
    $r = upd_git('fetch --prune origin ' . escapeshellarg($branch));
    return ['ok' => $r['code'] === 0, 'out' => $r['out']];
}

/**
 * Ile commitów jesteśmy z tyłu/przodu względem origin/<branch>.
 * Wymaga wcześniejszego fetch. Zwraca ['behind'=>int,'ahead'=>int].
 */
function upd_behind_ahead(?string $branch = null): array {
    $branch = $branch ?: (upd_current_branch() ?: 'main');
    $r = upd_git('rev-list --left-right --count '
        . escapeshellarg('HEAD...origin/' . $branch));
    if ($r['code'] !== 0) return ['behind' => 0, 'ahead' => 0, 'ok' => false];
    // Wyjście: "<ahead>\t<behind>" (lewa = HEAD, prawa = origin)
    $parts = preg_split('/\s+/', trim($r['out']));
    return [
        'ahead'  => (int)($parts[0] ?? 0),
        'behind' => (int)($parts[1] ?? 0),
        'ok'     => true,
    ];
}

/** Parsuj surowy log `%h|%ci|%s` na listę commitów z typem (feat/fix/...). */
function upd_parse_commits(string $raw): array {
    $items = [];
    foreach (explode("\n", trim($raw)) as $line) {
        if ($line === '') continue;
        [$hash, $date, $msg] = array_pad(explode('|', $line, 3), 3, '');
        $type = 'other';
        if (preg_match('/^(feat|fix|refactor|docs|style|test|chore|perf)/', $msg, $m)) {
            $type = $m[1];
            $msg  = ltrim(preg_replace('/^' . $m[1] . '(\([^)]+\))?:\s*/', '', $msg));
        }
        $items[] = [
            'hash' => $hash,
            'date' => $date ? date('d.m.Y', strtotime($date)) : '',
            'msg'  => $msg,
            'type' => $type,
        ];
    }
    return $items;
}

/** Commity oczekujące na pobranie: HEAD..origin/<branch>. Wymaga fetch. */
function upd_incoming_commits(?string $branch = null, int $limit = 50): array {
    $branch = $branch ?: (upd_current_branch() ?: 'main');
    $r = upd_git("log --no-merges --format='%h|%ci|%s' -" . (int)$limit
        . ' ' . escapeshellarg('HEAD..origin/' . $branch));
    if ($r['code'] !== 0 || $r['out'] === '') return [];
    return upd_parse_commits($r['out']);
}

/** Ostatnie N commitów lokalnej historii. */
function upd_recent_commits(int $limit = 50): array {
    $r = upd_git("log --no-merges --format='%h|%ci|%s' -" . (int)$limit);
    if ($r['code'] !== 0 || $r['out'] === '') return [];
    return upd_parse_commits($r['out']);
}

/**
 * Pobierz kod (fast-forward) z origin/<branch>.
 * Świadomie TYLKO --ff-only: z poziomu weba nie rozwiązujemy merge/konfliktów.
 * Zwraca ['ok'=>bool,'out'=>string,'before'=>hash,'after'=>hash,'changed'=>bool].
 */
function upd_pull(?string $branch = null): array {
    $branch = $branch ?: (upd_current_branch() ?: 'main');
    $before = upd_local_head();

    if (upd_is_dirty()) {
        return [
            'ok' => false, 'changed' => false,
            'before' => $before, 'after' => $before,
            'out' => 'Drzewo robocze ma niezacommitowane zmiany — pull wstrzymany. '
                   . 'Zacommituj lub wycofaj zmiany na serwerze (git status).',
        ];
    }

    $r = upd_git('pull --ff-only origin ' . escapeshellarg($branch));
    $after = upd_local_head();
    $ok = $r['code'] === 0;
    return [
        'ok'      => $ok,
        'out'     => $r['out'],
        'before'  => $before,
        'after'   => $after,
        'changed' => $ok && $before !== '' && $before !== $after,
    ];
}

/** Wyzeruj OPcache (jeśli włączony) — by nowy kod zadziałał od razu. */
function upd_reset_opcache(): bool {
    if (function_exists('opcache_reset')) {
        return @opcache_reset();
    }
    return false;
}

/**
 * Kopia zapasowa bazy danych przed migracją.
 * SQLite: kopiuje plik. MySQL: zwraca informację (backup robi się na hoście).
 * Zwraca ['ok'=>bool, 'file'=>?string, 'size'=>?string, 'msg'=>?string].
 */
function upd_backup_db(): array {
    $type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
    if ($type !== 'sqlite') {
        return ['ok' => false, 'msg' => 'Backup automatyczny tylko dla SQLite (MySQL — użyj mysqldump na hoście).'];
    }
    $db_path = defined('DB_PATH') ? DB_PATH : (upd_base_dir() . '/umowy.db');
    if (!file_exists($db_path)) {
        return ['ok' => false, 'msg' => 'Plik bazy nie istnieje: ' . $db_path];
    }
    $bak_dir = upd_base_dir() . '/backups';
    if (!is_dir($bak_dir)) @mkdir($bak_dir, 0755, true);
    $bak_file = $bak_dir . '/upgrade_' . date('Ymd_His') . '.db';
    if (!@copy($db_path, $bak_file)) {
        return ['ok' => false, 'msg' => 'Nie można skopiować pliku bazy (uprawnienia?).'];
    }
    return [
        'ok'   => true,
        'file' => $bak_file,
        'size' => round(filesize($bak_file) / 1024, 1) . ' KB',
    ];
}

} // function_exists guard
