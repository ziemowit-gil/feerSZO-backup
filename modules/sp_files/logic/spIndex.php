<?php
/**
 * modules/sp_files/logic/spIndex.php — lokalny indeks plików biblioteki SharePoint (podstawa dopasowania skanów do umów).
 *
 * Skany umów, które ktoś położył na SharePoincie ręcznie, nie mają nazw znanych aplikacji, więc nie da się ich
 * znaleźć zapytaniem „po ścieżce”. Indeks to lokalna lista plików biblioteki (nazwa, folder, rozmiar, adres) — SpMatcher
 * porównuje z nią umowy bez pliku, bez jednego zapytania do Graph na umowę. Nie pobiera treści plików.
 *
 * Przeglądanie: wszerz, folder po folderze (Graph .../children, strony po 200). Biblioteka może być duża, więc
 * przeglądanie jest wznawialne: kolejka folderów leży w sp_index_runs, a refresh() robi tyle, ile zmieści się w budżecie
 * czasu żądania (domyślnie 20 s), i zwraca state=running — wywołujący ponawia, aż dostanie complete (ekran admina:
 * przycisk „Kontynuuj”; CLI: pętla). Jeden krok naraz (blokada w wierszu przebiegu, wygasa po 2 minutach).
 *
 * Zakończony bez błędów przebieg zastępuje indeks (pliki usunięte z SharePointa znikają). Przebieg z błędami (folder
 * nieczytelny, limit plików) kończy się jako partial i NICZEGO nie usuwa — nie wiemy, co jest w nieodczytanych folderach.
 * Indeksowane są tylko pliki, które da się przypisać do umowy (pdf, jpg, jpeg, png, docx), niepuste. Pliki o nazwach
 * nadawanych przez aplikację (RRRRMMDD_GGMMSS_hex8.ext) są zapisane z app_named=1: nie są kandydatami do dopasowania
 * (to kopie plików już wgranych do umów). Czy oryginał „utraconego” pliku leży na SharePoincie, rozpoznajemy po samej nazwie
 * pliku (hasFileNamed) — także dla starszych nazw spoza wzorca.
 *
 * Zakres: ustawienie sp_match_folder (ścieżka folderu względem korzenia biblioteki); puste = cała biblioteka.
 */
require_once __DIR__ . '/spFilesSchema.php';
require_once __DIR__ . '/spFiles.php';

final class SpIndex
{
    /** Rozszerzenia plików, które da się przypisać do umowy (jak upload umów: handle_upload()). */
    public const ATTACH_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];

    /** Twardy limit liczby plików w indeksie (ochrona pamięci przy dopasowaniu). */
    public const MAX_FILES = 100000;

    private const MAX_DEPTH       = 12;
    private const LOCK_SECONDS    = 120;
    private const CHECKPOINT_EVERY = 25;      // co tyle folderów zapis kolejki (przy awarii kroku stracimy najwyżej tyle)
    private const APP_NAME_RE     = '~^\d{8}_\d{6}_[0-9a-f]{8}\.[A-Za-z0-9]{1,5}$~';

    /** Czy nazwa wygląda na nadaną przez aplikację przy uploadzie (RRRRMMDD_GGMMSS_hex8.ext), a nie na skan wgrany ręcznie. */
    public static function isAppName(string $name): bool
    {
        return preg_match(self::APP_NAME_RE, $name) === 1;
    }

    /** Folder startowy przeglądania (ustawienie sp_match_folder; '' = cała biblioteka). Czytany prosto z bazy — bez pamięci podręcznej. */
    public static function startFolder(): string
    {
        $row = db_one("SELECT value FROM settings WHERE key_ = 'sp_match_folder'");
        return trim(str_replace('\\', '/', (string)($row['value'] ?? '')), '/');
    }

    /** Zapis folderu startowego (ścieżka względem korzenia biblioteki; '' = cała biblioteka). Zwraca zapisaną, znormalizowaną wartość. */
    public static function setStartFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', trim($folder)), '/');
        if ($folder !== '' && (str_contains($folder, '..') || str_contains($folder, '//') || preg_match('/[\x00-\x1f<>:"|?*]/', $folder) || strlen($folder) > 300)) {
            throw new \InvalidArgumentException('Niedozwolona ścieżka folderu.');
        }
        if (!function_exists('m365_save_setting')) require_once dirname(__DIR__, 3) . '/includes/m365.php';
        m365_save_setting('sp_match_folder', $folder);
        return $folder;
    }

    /**
     * Stan indeksu do wyświetlenia.
     *
     * @return array{files:int,app_files:int,running:bool,queue:int,run:?array,last:?array,age_hours:?float}
     *         files: pliki-kandydaci, app_files: pliki o nazwach aplikacji, run: ostatni przebieg, last: ostatni zakończony (complete/partial)
     */
    public static function status(): array
    {
        sp_files_migrate();
        $files = (int)(db_one("SELECT COUNT(*) AS n FROM sp_library_files WHERE app_named = 0")['n'] ?? 0);
        $app   = (int)(db_one("SELECT COUNT(*) AS n FROM sp_library_files WHERE app_named = 1")['n'] ?? 0);
        $run   = db_one("SELECT * FROM sp_index_runs ORDER BY id DESC LIMIT 1") ?: null;
        $last  = db_one("SELECT * FROM sp_index_runs WHERE status IN ('complete','partial') ORDER BY id DESC LIMIT 1") ?: null;
        $running = $run !== null && $run['status'] === 'running';
        $queue = $running ? count(json_decode((string)$run['queue'], true) ?: []) : 0;
        $age   = $last && !empty($last['finished_at']) ? round((time() - (int)strtotime((string)$last['finished_at'])) / 3600, 1) : null;
        return ['files' => $files, 'app_files' => $app, 'running' => $running, 'queue' => $queue, 'run' => $run, 'last' => $last, 'age_hours' => $age];
    }

    /** Pliki-kandydaci z indeksu (bez plików o nazwach aplikacji). @return list<array{item_id:string,name:string,folder:string,path:string,ext:string,size:int,web_url:string,modified_at:string}> */
    public static function files(): array
    {
        sp_files_migrate();
        $rows = db_all("SELECT item_id, name, folder, path, ext, size, web_url, modified_at FROM sp_library_files WHERE app_named = 0 ORDER BY path");
        foreach ($rows as &$r) $r['size'] = (int)$r['size'];
        return $rows;
    }

    /** Wpis indeksu po ID elementu (także o nazwie aplikacji) albo null. */
    public static function find(string $itemId): ?array
    {
        sp_files_migrate();
        $r = db_one("SELECT * FROM sp_library_files WHERE item_id = ?", [$itemId]);
        if (!$r) return null;
        $r['size'] = (int)$r['size'];
        return $r;
    }

    /**
     * Czy w indeksie jest plik o tej nazwie (dowolny — także o nazwie spoza wzorca aplikacji, bo starsze pliki miały inne nazwy).
     * Tak wygląda oryginał „utraconego” pliku umowy: wskazanie w umowie ma nazwę pliku, który leży na SharePoincie.
     */
    public static function hasFileNamed(string $name): bool
    {
        sp_files_migrate();
        return $name !== '' && (bool)db_one("SELECT 1 AS x FROM sp_library_files WHERE name = ? LIMIT 1", [$name]);
    }

    /** Przerywa trwający przebieg (kolejka przepada; dotychczasowy indeks zostaje). */
    public static function abort(): void
    {
        sp_files_migrate();
        db()->prepare("UPDATE sp_index_runs SET status = 'aborted', finished_at = ?, message = 'Przerwano ręcznie.', locked_until = 0 WHERE status = 'running'")
            ->execute([date('Y-m-d H:i:s')]);
    }

    // ── Przeglądanie biblioteki ──────────────────────────────────────────────

    /**
     * Wykonuje jeden krok przeglądania w budżecie czasu $maxSeconds (zawsze przynajmniej jedną stronę folderu).
     * $fresh: porzuca trwający przebieg i zaczyna od nowa; bez niego kontynuuje trwający (albo zaczyna nowy, gdy żadnego nie ma).
     *
     * @return array{state:string,run_id:int,files:int,folders:int,queue:int,errors:int,message:string}
     *         state: running (wywołaj ponownie) | complete | partial | error | busy (krok trwa w innym oknie)
     */
    public static function refresh(int $maxSeconds = 20, bool $fresh = false, ?M365Graph $graph = null): array
    {
        sp_files_migrate();
        @set_time_limit(max(60, $maxSeconds + 45));
        try {
            $c = SpFiles::context($graph);
        } catch (\Throwable $e) {
            return self::result('error', null, $e->getMessage());
        }

        $run = db_one("SELECT * FROM sp_index_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1") ?: null;
        if ($run && $fresh) {
            db()->prepare("UPDATE sp_index_runs SET status = 'aborted', finished_at = ?, message = 'Zastąpiony nowym przebiegiem.', locked_until = 0 WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), (int)$run['id']]);
            $run = null;
        }
        if (!$run) {
            try { $run = self::startRun($c); } catch (\Throwable $e) { return self::result('error', null, mb_substr($e->getMessage(), 0, 300)); }
        }
        $id = (int)$run['id'];

        $now  = time();
        $lock = db()->prepare("UPDATE sp_index_runs SET locked_until = ? WHERE id = ? AND status = 'running' AND locked_until < ?");
        $lock->execute([$now + self::LOCK_SECONDS, $id, $now]);
        if ($lock->rowCount() !== 1) {
            return self::result('busy', $run, 'Indeksowanie jest właśnie wykonywane w innym oknie — spróbuj za chwilę.');
        }

        $queue   = json_decode((string)$run['queue'], true);
        $queue   = is_array($queue) ? $queue : [];
        $done    = (int)$run['folders_done'];
        $seen    = (int)$run['files_seen'];
        $errors  = (int)$run['errors'];
        $message = (string)$run['message'];
        $deadline = microtime(true) + max(0, $maxSeconds);
        $capped  = false;
        $steps   = 0;
        $sinceCheckpoint = 0;
        $pdo  = db();
        $ins  = $pdo->prepare("INSERT OR REPLACE INTO sp_library_files
                (item_id, name, folder, path, ext, size, web_url, modified_at, app_named, run_id, indexed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stamp = date('Y-m-d H:i:s');
        $cur   = null;

        try {
            while ($queue) {
                if ($steps > 0 && microtime(true) >= $deadline) break;           // postęp zawsze: przynajmniej jedna strona
                $cur = array_shift($queue);
                $steps++;
                try {
                    $page = self::listPage($c['g'], $c['drive'], $cur);
                } catch (\Throwable $e) {
                    $errors++; $done++;
                    $message = mb_substr('Nie odczytano folderu „' . (string)($cur['path'] ?? '') . '”: ' . $e->getMessage(), 0, 300);
                    continue;
                }
                $depth = (int)($cur['depth'] ?? 0);
                $path  = (string)($cur['path'] ?? '');
                $pdo->beginTransaction();
                try {
                    foreach ($page['items'] as $it) {
                        $name = (string)($it['name'] ?? '');
                        $iid  = (string)($it['id'] ?? '');
                        if ($name === '' || $iid === '') continue;
                        $childPath = ltrim($path . '/' . $name, '/');
                        if (isset($it['folder'])) {
                            if ($depth + 1 > self::MAX_DEPTH) continue;
                            if (strcasecmp($childPath, 'Forms') === 0) continue;       // systemowy folder biblioteki
                            $queue[] = ['id' => $iid, 'path' => $childPath, 'next' => '', 'depth' => $depth + 1];
                            continue;
                        }
                        if (!isset($it['file'])) continue;
                        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        $size = (int)($it['size'] ?? 0);
                        if (!in_array($ext, self::ATTACH_EXT, true) || $size <= 0) continue;
                        $web = (string)($it['webUrl'] ?? '');
                        $ins->execute([$iid, $name, $path, $childPath, $ext, $size, preg_match('~^https://~i', $web) ? $web : '',
                                       (string)($it['lastModifiedDateTime'] ?? ''), self::isAppName($name) ? 1 : 0, $id, $stamp]);
                        $seen++;
                    }
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                if ($page['next'] !== '') array_unshift($queue, ['id' => (string)($cur['id'] ?? ''), 'path' => $path, 'next' => $page['next'], 'depth' => $depth]);
                else $done++;

                if ($seen >= self::MAX_FILES) { $capped = true; $queue = []; break; }
                if (++$sinceCheckpoint >= self::CHECKPOINT_EVERY) {
                    $sinceCheckpoint = 0;
                    self::save($id, $queue, $done, $seen, $errors, $message, 'running', true);
                }
            }
        } catch (\Throwable $e) {
            // błąd bazy / nieoczekiwany: folder w toku wraca na początek kolejki, blokada zostaje zwolniona
            if (is_array($cur)) array_unshift($queue, $cur);
            self::save($id, $queue, $done, $seen, $errors, mb_substr($e->getMessage(), 0, 300), 'running', false);
            return self::result('error', db_one("SELECT * FROM sp_index_runs WHERE id = ?", [$id]), mb_substr($e->getMessage(), 0, 300));
        }

        $state = 'running';
        if (!$queue) {
            if ($capped)                    { $state = 'partial'; $message = 'Osiągnięto limit ' . self::MAX_FILES . ' plików — indeks jest niepełny.'; }
            elseif ($errors === 0)          { $state = 'complete'; $message = ''; }
            elseif ($done - $errors === 0)  { $state = 'error'; }
            else                            { $state = 'partial'; $message = $errors . ' folderów nie udało się odczytać (ostatni błąd: ' . $message . ').'; }
        }
        $saved = self::save($id, $queue, $done, $seen, $errors, $message, $state, false);
        if (!$saved) return self::result('aborted', db_one("SELECT * FROM sp_index_runs WHERE id = ?", [$id]), 'Przebieg został przerwany w innym oknie.');
        if ($state === 'complete') {
            // pełny, bezbłędny przebieg: pliki, których już nie ma na SharePoincie, znikają z indeksu
            $pdo->prepare("DELETE FROM sp_library_files WHERE run_id <> ?")->execute([$id]);
        }
        return self::result($state, db_one("SELECT * FROM sp_index_runs WHERE id = ?", [$id]), $message);
    }

    /** Przegląda aż do końca (CLI, testy): wywołuje refresh() w pętli. Zwraca ostatni wynik. */
    public static function refreshAll(bool $fresh = true, int $sliceSeconds = 20, ?M365Graph $graph = null): array
    {
        $res = self::refresh($sliceSeconds, $fresh, $graph);
        for ($guard = 0; $res['state'] === 'running' && $guard < 100000; $guard++) $res = self::refresh($sliceSeconds, false, $graph);
        return $res;
    }

    // ── Wnętrze ──────────────────────────────────────────────────────────────

    /** @param array{g:M365Graph,site:string,drive:string,base:string} $c */
    private static function startRun(array $c): array
    {
        $folder = self::startFolder();
        $root   = ['id' => '', 'path' => '', 'next' => '', 'depth' => 0];
        if ($folder !== '') {
            $it = $c['g']->sp_item_by_path($c['drive'], $folder);
            if (!$it || !isset($it['folder'])) throw new \RuntimeException('Folder „' . $folder . '” (zakres przeszukiwania) nie istnieje w bibliotece.');
            $root = ['id' => (string)$it['id'], 'path' => $folder, 'next' => '', 'depth' => 0];
        }
        $pdo = db();
        $pdo->prepare("INSERT INTO sp_index_runs (started_at, status, start_folder, queue) VALUES (?, 'running', ?, ?)")
            ->execute([date('Y-m-d H:i:s'), $folder, json_encode([$root], JSON_UNESCAPED_UNICODE)]);
        return db_one("SELECT * FROM sp_index_runs WHERE id = ?", [(int)$pdo->lastInsertId()]) ?: [];
    }

    /** Jedna strona folderu; przy chwilowym błędzie (429 / 5xx / sieć) jedno ponowienie po krótkiej przerwie. */
    private static function listPage(M365Graph $g, string $drive, array $cur): array
    {
        $last = null;
        for ($try = 0; $try < 2; $try++) {
            try {
                return $g->sp_children_page($drive, (string)($cur['id'] ?? ''), (string)($cur['next'] ?? ''));
            } catch (\Throwable $e) {
                $last = $e;
                if ($try === 0 && in_array($g->last_status(), [0, 429, 500, 502, 503, 504], true)) { usleep(1500000); continue; }
                break;
            }
        }
        throw $last ?? new \RuntimeException('SharePoint: nieznany błąd.');
    }

    /** Zapis stanu przebiegu; false, gdy przebieg nie jest już „running” (przerwany w innym oknie). $keepLock: punkt kontrolny w trakcie kroku. */
    private static function save(int $id, array $queue, int $done, int $seen, int $errors, string $message, string $status, bool $keepLock): bool
    {
        $finished = in_array($status, ['complete', 'partial', 'error'], true);
        $st = db()->prepare("UPDATE sp_index_runs SET queue = ?, folders_done = ?, files_seen = ?, errors = ?, message = ?, status = ?,
                                    finished_at = ?, locked_until = ? WHERE id = ? AND status = 'running'");
        $st->execute([json_encode(array_values($queue), JSON_UNESCAPED_UNICODE), $done, $seen, $errors, mb_substr($message, 0, 500), $status,
                      $finished ? date('Y-m-d H:i:s') : null, $keepLock ? time() + self::LOCK_SECONDS : 0, $id]);
        return $st->rowCount() === 1;
    }

    /** @return array{state:string,run_id:int,files:int,folders:int,queue:int,errors:int,message:string} */
    private static function result(string $state, ?array $run, string $message = ''): array
    {
        $queue = $run ? count(json_decode((string)($run['queue'] ?? '[]'), true) ?: []) : 0;
        return ['state' => $state, 'run_id' => (int)($run['id'] ?? 0), 'files' => (int)($run['files_seen'] ?? 0), 'folders' => (int)($run['folders_done'] ?? 0),
                'queue' => $queue, 'errors' => (int)($run['errors'] ?? 0), 'message' => $message !== '' ? $message : (string)($run['message'] ?? '')];
    }
}
