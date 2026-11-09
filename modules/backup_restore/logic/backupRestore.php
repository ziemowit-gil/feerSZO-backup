<?php
/**
 * modules/backup_restore/logic/backupRestore.php — przywracanie bazy SQLite z lokalnej kopii zapasowej.
 *
 * Kopie: pliki typu „db” (backup_kind()) w backups/RRRR-MM/ oraz w backups/ (kopie sprzed aktualizacji
 * upgrade_*.db), także zaszyfrowane .enc. Dwa tryby:
 *   • „Wybrane tabele” — odzyskanie np. dokumentów EODoK bez cofania reszty systemu,
 *   • „Cała baza”      — wszystkie tabele z kopii, poza tabelami trzymanymi z bieżącej bazy
 *                        (dziennik audytu, sesje zalogowanych osób).
 *
 * Bezpieczeństwo:
 *   • kopia musi przejść PRAGMA integrity_check (sprawdzamy kopię roboczą, nie oryginał),
 *   • PRZED zmianą powstaje obowiązkowa kopia bieżącej bazy (umowy_pre-restore_*.db) — bez niej nic nie ruszamy,
 *   • przywracanie idzie przez to samo połączenie PDO (db()): ATTACH kopii roboczej + DELETE/INSERT w jednej
 *     transakcji BEGIN IMMEDIATE (wszystko albo nic). Nie kopiujemy pliku na żywą bazę (tryb WAL — groziłoby
 *     uszkodzeniem) i nie używamy SQLite3::backup (druga kopia biblioteki SQLite w procesie PHP to znana
 *     przyczyna uszkodzeń bazy),
 *   • wyzwalacze przywracanych tabel są na czas kopiowania zdejmowane i odtwarzane w tej samej transakcji,
 *     tabele wirtualne (FTS) i ich tabele cieni są pomijane,
 *   • sqlite_sequence zostaje z bieżącej bazy — identyfikatory nadane po kopii nie zostaną użyte ponownie.
 */

require_once dirname(__DIR__, 3) . '/includes/backup.php';

/** Tabele zawsze brane z bieżącej bazy (nie przywracane): ślad audytu i sesje zalogowanych osób. */
const BACKUP_RESTORE_KEEP_CURRENT = ['audit_logs', 'admin_audit_log', 'php_sessions'];

/** Ustawienia (settings.key_) zachowywane z bieżącej bazy, gdy przywracana jest tabela settings. */
const BACKUP_RESTORE_KEEP_SETTINGS = ['backup_encrypt_key'];

/** Sufiksy tabel cieni tabel wirtualnych FTS3/4/5. */
const BACKUP_RESTORE_SHADOW_SUFFIXES = ['_data', '_idx', '_content', '_docsize', '_config', '_segments', '_segdir', '_stat'];

/** Identyfikator SQL w cudzysłowie. */
function backupRestoreQuoteIdent(string $name): string {
    return '"' . str_replace('"', '""', $name) . '"';
}

/** Czas utworzenia kopii: znacznik z nazwy (RRRRMMDD_GGMMSS), a gdy go brak — mtime pliku. */
function backupRestoreFileTs(string $name, int $mtime): int {
    if (preg_match('/(\d{8})_(\d{6})/', $name, $m)) {
        $ts = DateTime::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]);
        if ($ts) return $ts->getTimestamp();
    }
    return $mtime;
}

/**
 * Lista kopii bazy do przywrócenia, najnowsze pierwsze.
 * @return array<int, array{rel:string,full:string,name:string,ts:int,size:int,enc:bool,date:string,isYesterday:bool,isPreRestore:bool,isUpgrade:bool}>
 */
function backupRestoreCandidates(): array {
    $root = realpath(backup_root());
    if (!$root) return [];
    $paths = array_merge(glob($root . '/*') ?: [], glob($root . '/????-??/*') ?: []);
    $yesterday = date('Y-m-d', strtotime('yesterday'));
    $out = [];
    foreach ($paths as $p) {
        $name = basename($p);
        if (!is_file($p) || backup_kind($name) !== 'db') continue;
        $size = (int)(filesize($p) ?: 0);
        if ($size === 0) continue;
        $ts = backupRestoreFileTs($name, (int)(filemtime($p) ?: 0));
        $out[] = [
            'rel'          => ltrim(substr($p, strlen($root)), '/'),
            'full'         => $p,
            'name'         => $name,
            'ts'           => $ts,
            'size'         => $size,
            'enc'          => backup_is_encrypted($name),
            'date'         => date('Y-m-d', $ts),
            'isYesterday'  => date('Y-m-d', $ts) === $yesterday,
            'isPreRestore' => str_contains($name, 'pre-restore'),
            'isUpgrade'    => str_starts_with($name, 'upgrade_'),
        ];
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $out;
}

/** Domyślna kopia: najnowsza z wczoraj (bez pre-restore), a gdy jej brak — najnowsza sprzed dzisiejszej północy. */
function backupRestoreDefault(array $candidates): ?array {
    foreach ($candidates as $c) if ($c['isYesterday'] && !$c['isPreRestore']) return $c;
    $midnight = strtotime('today');
    foreach ($candidates as $c) if (!$c['isPreRestore'] && $c['ts'] < $midnight) return $c;
    return null;
}

/** Kopia o ścieżce względnej $rel — tylko spośród znanych kandydatów (bez path traversal). */
function backupRestoreFind(string $rel): ?array {
    $rel = trim($rel, '/');
    if ($rel === '') return null;
    foreach (backupRestoreCandidates() as $c) if ($c['rel'] === $rel) return $c;
    return null;
}

/** Etykieta kopii na listach wyboru. */
function backupRestoreLabel(array $c): string {
    $tags = [];
    if ($c['isYesterday'])  $tags[] = 'wczoraj';
    if ($c['isPreRestore']) $tags[] = 'kopia sprzed przywrócenia';
    if ($c['isUpgrade'])    $tags[] = 'sprzed aktualizacji';
    if ($c['enc'])          $tags[] = 'szyfrowana';
    return date('d.m.Y H:i', $c['ts']) . ' — ' . $c['name'] . ' (' . backupRestoreSize($c['size']) . ')'
         . ($tags ? ' · ' . implode(', ', $tags) : '');
}

function backupRestoreSize(int $bytes): string {
    return $bytes > 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** Usuwa plik roboczy razem z ewentualnymi -wal/-shm/-journal. */
function backupRestoreCleanup(?string $path): void {
    if (!$path) return;
    foreach (['', '-wal', '-shm', '-journal'] as $sfx) if (is_file($path . $sfx)) @unlink($path . $sfx);
}

/**
 * Kopia robocza do odczytu: zaszyfrowaną odszyfrowujemy, jawną kopiujemy (oryginał nie jest otwierany przez SQLite,
 * więc nie powstają przy nim pliki -wal/-shm i nie zmienia się jego suma SHA-256). Sprawdza PRAGMA integrity_check.
 * @return array{ok:bool,msg:string,path?:string}
 */
function backupRestorePrepare(array $cand): array {
    if (!is_file($cand['full'])) return ['ok' => false, 'msg' => 'Plik kopii nie istnieje.'];
    if ($cand['enc']) {
        $work = backup_decrypt_file($cand['full']);
        if (!$work) return ['ok' => false, 'msg' => 'Nie udało się odszyfrować kopii (brak lub błędny klucz szyfrowania).'];
    } else {
        $work = backup_root() . '/.restore_work_' . bin2hex(random_bytes(6)) . '.db';
        if (!@copy($cand['full'], $work)) return ['ok' => false, 'msg' => 'Nie udało się przygotować kopii roboczej (miejsce na dysku, uprawnienia?).'];
    }
    try {
        $chk = new PDO('sqlite:' . $work);
        $chk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $row = $chk->query('PRAGMA integrity_check')->fetch(PDO::FETCH_NUM);
        $chk = null;
        if (!$row || strtolower((string)$row[0]) !== 'ok') {
            backupRestoreCleanup($work);
            return ['ok' => false, 'msg' => 'Kopia nie przeszła kontroli integralności (integrity_check) — przerwano, baza bez zmian.'];
        }
    } catch (\Throwable $e) {
        backupRestoreCleanup($work);
        return ['ok' => false, 'msg' => 'Nieprawidłowy plik bazy: ' . $e->getMessage()];
    }
    return ['ok' => true, 'msg' => '', 'path' => $work];
}

/**
 * Tabele bazy (bez sqlite_*) z rozpoznaniem tabel wirtualnych i ich tabel cieni.
 * @return array<string, array{sql:string, skip:?string}> skip = powód pominięcia albo null
 */
function backupRestoreTables(PDO $pdo, string $schema = 'main'): array {
    $rows = $pdo->query('SELECT name, sql FROM ' . backupRestoreQuoteIdent($schema) . ".sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
    $virtual = [];
    foreach ($rows as $name => $sql) {
        if (stripos(ltrim((string)$sql), 'CREATE VIRTUAL TABLE') === 0) $virtual[] = $name;
    }
    $out = [];
    foreach ($rows as $name => $sql) {
        $skip = null;
        if (in_array($name, $virtual, true)) $skip = 'tabela wirtualna';
        else foreach ($virtual as $v) {
            foreach (BACKUP_RESTORE_SHADOW_SUFFIXES as $sfx) if ($name === $v . $sfx) { $skip = 'tabela pomocnicza ' . $v; break 2; }
        }
        if ($skip === null && in_array($name, BACKUP_RESTORE_KEEP_CURRENT, true)) $skip = 'zawsze z bieżącej bazy';
        $out[$name] = ['sql' => (string)$sql, 'skip' => $skip];
    }
    return $out;
}

function backupRestoreCount(PDO $pdo, string $schema, string $table): ?int {
    try {
        $st = $pdo->query('SELECT COUNT(*) FROM ' . backupRestoreQuoteIdent($schema) . '.' . backupRestoreQuoteIdent($table));
        $n = (int)$st->fetchColumn();
        $st->closeCursor();
        return $n;
    } catch (\Throwable $e) { return null; }
}

/**
 * Porównanie liczby wierszy: bieżąca baza vs kopia.
 * @return array{ok:bool,msg:string,rows?:array<int,array{name:string,cur:?int,bak:?int,skip:?string,diff:bool}>}
 */
function backupRestoreCompare(array $cand): array {
    $prep = backupRestorePrepare($cand);
    if (!$prep['ok']) return $prep;
    try {
        $bak = new PDO('sqlite:' . $prep['path']);
        $bak->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $bakTables = backupRestoreTables($bak);
        $curTables = backupRestoreTables(db());
        $rows = [];
        foreach (array_unique(array_merge(array_keys($bakTables), array_keys($curTables))) as $name) {
            $inBak = isset($bakTables[$name]);
            $cur = isset($curTables[$name]) ? backupRestoreCount(db(), 'main', $name) : null;
            $bk  = $inBak ? backupRestoreCount($bak, 'main', $name) : null;
            $skip = $inBak ? $bakTables[$name]['skip'] : 'brak w kopii (tabela nowsza niż kopia)';
            $rows[] = ['name' => $name, 'cur' => $cur, 'bak' => $bk, 'skip' => $skip, 'diff' => $cur !== $bk];
        }
        $bak = null;
        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
        return ['ok' => true, 'msg' => '', 'rows' => $rows];
    } catch (\Throwable $e) {
        return ['ok' => false, 'msg' => 'Nie udało się odczytać kopii: ' . $e->getMessage()];
    } finally {
        backupRestoreCleanup($prep['path']);
    }
}

/**
 * Obowiązkowa kopia bieżącej bazy przed przywróceniem (VACUUM INTO + quick_check; szyfrowana, gdy szyfrowanie
 * jest włączone; wpis w manifeście). @return array{ok:bool,msg:string,path?:string,rel?:string}
 */
function backupRestoreSafetyCopy(): array {
    $dir = backup_root() . '/' . date('Y-m');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return ['ok' => false, 'msg' => 'Nie można utworzyć katalogu kopii: ' . $dir];
    $path = $dir . '/umowy_pre-restore_' . date('Ymd_His') . '.db';
    if (file_exists($path)) $path = $dir . '/umowy_pre-restore_' . date('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.db';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($path));
        $chk = new PDO('sqlite:' . $path);
        $chk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ok = strtolower((string)$chk->query('PRAGMA quick_check')->fetchColumn()) === 'ok';
        $chk = null;
        if (!$ok) { backupRestoreCleanup($path); return ['ok' => false, 'msg' => 'Kopia bieżącej bazy nie przeszła kontroli — przerwano.']; }
    } catch (\Throwable $e) {
        backupRestoreCleanup($path);
        return ['ok' => false, 'msg' => 'Nie udało się wykonać kopii bieżącej bazy: ' . $e->getMessage()];
    }
    foreach (['-wal', '-shm'] as $sfx) if (is_file($path . $sfx)) @unlink($path . $sfx);
    if (backup_encryption_enabled()) {
        $enc = backup_encrypt_file($path);
        if ($enc) $path = $enc;
    }
    backup_manifest_record($path, ['db_integrity' => 'ok', 'pre_restore' => true]);
    return ['ok' => true, 'msg' => '', 'path' => $path, 'rel' => (string)backup_rel($path)];
}

/**
 * Przywraca tabele z kopii. $mode: 'full' (wszystkie tabele kopii poza wyłączonymi) albo 'tables' (tylko $tables).
 * @return array{ok:bool,msg:string,safety?:string,tables?:array,created?:array,newer?:array,skipped?:array}
 */
function backupRestoreRun(array $cand, string $mode, array $tables = []): array {
    if (!in_array($mode, ['full', 'tables'], true)) return ['ok' => false, 'msg' => 'Nieznany tryb przywracania.'];
    if ($mode === 'tables') {
        $tables = array_values(array_unique(array_map('strval', $tables)));
        if (!$tables) return ['ok' => false, 'msg' => 'Nie wybrano żadnej tabeli.'];
        foreach ($tables as $t) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) return ['ok' => false, 'msg' => 'Nieprawidłowa nazwa tabeli: ' . $t];
        }
    }

    $prep = backupRestorePrepare($cand);
    if (!$prep['ok']) return $prep;
    $work = $prep['path'];

    try {
        // Lista tabel z kopii i walidacja wyboru — zanim cokolwiek zmienimy.
        $bak = new PDO('sqlite:' . $work);
        $bak->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $bakTables = backupRestoreTables($bak);
        $bakIndexes = $bak->query("SELECT tbl_name, sql FROM sqlite_master WHERE type='index' AND sql IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $bak = null;

        $skipped = [];
        if ($mode === 'full') {
            $targets = [];
            foreach ($bakTables as $name => $info) {
                if ($info['skip'] === null) $targets[] = $name; else $skipped[$name] = $info['skip'];
            }
        } else {
            foreach ($tables as $t) {
                if (!isset($bakTables[$t])) return ['ok' => false, 'msg' => 'Tabeli „' . $t . '” nie ma w kopii.'];
                if ($bakTables[$t]['skip'] !== null) return ['ok' => false, 'msg' => 'Tabeli „' . $t . '” nie przywracamy (' . $bakTables[$t]['skip'] . ').'];
            }
            $targets = $tables;
        }
        if (!$targets) return ['ok' => false, 'msg' => 'Brak tabel do przywrócenia.'];

        $pdo = db();
        $curTables = backupRestoreTables($pdo);
        $newer = $mode === 'full' ? array_values(array_diff(array_keys($curTables), array_keys($bakTables))) : [];

        // Ustawienia zachowywane z bieżącej bazy (np. klucz szyfrowania kopii — bez niego kopia pre-restore byłaby nie do odczytu).
        $keepSettings = [];
        if (in_array('settings', $targets, true) && isset($curTables['settings'])) {
            foreach (BACKUP_RESTORE_KEEP_SETTINGS as $k) {
                $st = $pdo->prepare('SELECT value FROM settings WHERE key_=?');
                $st->execute([$k]);
                $v = $st->fetchColumn();
                $st->closeCursor(); // otwarty kursor blokuje VACUUM INTO („SQL statements in progress”)
                $st = null;
                if ($v !== false) $keepSettings[$k] = (string)$v;
            }
        }

        // Obowiązkowa kopia bieżącej bazy — bez niej nie przywracamy.
        $safety = backupRestoreSafetyCopy();
        if (!$safety['ok']) return $safety;

        $before = [];
        foreach ($targets as $t) $before[$t] = isset($curTables[$t]) ? backupRestoreCount($pdo, 'main', $t) : null;

        $result = [];
        $created = [];
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec('ATTACH DATABASE ' . $pdo->quote($work) . ' AS bak');
        $inTx = false;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $inTx = true;

            // Wyzwalacze przywracanych tabel: zdjąć na czas kopiowania, odtworzyć po nim (ta sama transakcja).
            $trigStmt = $pdo->query("SELECT name, tbl_name, sql FROM main.sqlite_master WHERE type='trigger' AND sql IS NOT NULL");
            $triggers = array_values(array_filter($trigStmt->fetchAll(PDO::FETCH_ASSOC), fn($tr) => in_array($tr['tbl_name'], $targets, true)));
            foreach ($triggers as $tr) $pdo->exec('DROP TRIGGER IF EXISTS main.' . backupRestoreQuoteIdent($tr['name']));

            foreach ($targets as $t) {
                $q = backupRestoreQuoteIdent($t);
                if (!isset($curTables[$t])) {
                    // Tabeli nie ma w bieżącej bazie — tworzymy ją z definicji z kopii (z indeksami).
                    $pdo->exec($bakTables[$t]['sql']);
                    foreach ($bakIndexes as $ix) {
                        if ($ix['tbl_name'] !== $t) continue;
                        try { $pdo->exec($ix['sql']); } catch (\Throwable $e) { /* indeks o tej nazwie już jest — pomijamy */ }
                    }
                    $created[] = $t;
                }
                $curCols = array_column($pdo->query('PRAGMA main.table_info(' . $q . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
                $bakCols = array_column($pdo->query('PRAGMA bak.table_info(' . $q . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
                $cols = array_values(array_intersect($curCols, $bakCols));
                if (!$cols) { $skipped[$t] = 'brak wspólnych kolumn'; continue; }
                $colList = implode(', ', array_map('backupRestoreQuoteIdent', $cols));
                $pdo->exec('DELETE FROM main.' . $q);
                $pdo->exec('INSERT INTO main.' . $q . ' (' . $colList . ') SELECT ' . $colList . ' FROM bak.' . $q);
                $result[$t] = [
                    'before'  => $before[$t],
                    'after'   => backupRestoreCount($pdo, 'main', $t),
                    'missing' => array_values(array_diff($curCols, $bakCols)), // kolumny nowsze niż kopia — wartości domyślne
                ];
            }

            foreach ($keepSettings as $k => $v) {
                $pdo->prepare('INSERT OR REPLACE INTO main.settings (key_, value) VALUES (?, ?)')->execute([$k, $v]);
            }
            foreach ($triggers as $tr) $pdo->exec($tr['sql']);

            $pdo->exec('COMMIT');
            $inTx = false;
        } catch (\Throwable $e) {
            if ($inTx) { try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) {} }
            return ['ok' => false, 'msg' => 'Przywracanie przerwane, baza bez zmian (wycofano transakcję): ' . $e->getMessage(), 'safety' => $safety['rel']];
        } finally {
            try { $pdo->exec('DETACH DATABASE bak'); } catch (\Throwable $e) {}
            $pdo->exec('PRAGMA foreign_keys=ON');
        }

        $n = count($result);
        return [
            'ok'      => true,
            'msg'     => ($mode === 'full' ? 'Przywrócono bazę z kopii ' : 'Przywrócono ' . $n . ' tabel(e) z kopii ')
                       . $cand['name'] . ' (' . date('d.m.Y H:i', $cand['ts']) . '). Kopia sprzed przywrócenia: ' . $safety['rel'] . '.',
            'safety'  => $safety['rel'],
            'tables'  => $result,
            'created' => $created,
            'newer'   => $newer,
            'skipped' => $skipped,
        ];
    } finally {
        backupRestoreCleanup($work);
    }
}
