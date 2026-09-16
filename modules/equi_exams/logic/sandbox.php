<?php
/**
 * modules/equi_exams/logic/sandbox.php — piaskownica wykonania kodu kursanta (Equi Exams).
 *
 * Port z exam-engine (Java): sandbox/{Sandbox,Language,RunResult}.java. Odtwarza
 * dokładnie te same reguły (ulimit -t/-f/-v, umask 077, katalog roboczy per
 * uruchomienie, obcinanie wyjścia) w ramach jednej, świadomie zaakceptowanej
 * zmiany architektury: zamiast osobnego, odizolowanego kontenera (read-only
 * rootfs, cap_drop ALL, brak trasy sieciowej) kod ucznia biegnie w kontenerze
 * `app`, ale jako osobny, nieuprzywilejowany użytkownik systemowy (`examrun`,
 * patrz docker/Dockerfile) — separacja UID przez `sudo -n -u examrun` (ten sam
 * wzorzec co `vlab_ssh_root()` w includes/vlab.php), NIE separacja kontenerowa.
 * Brak izolacji sieciowej i cgroup (pids/mem/cpu na poziomie kontenera) to
 * znana, zaakceptowana regresja — patrz plan migracji.
 *
 * Ten plik jest ładowany w DWÓCH kontekstach:
 *   1. przez PHP działający jako www-data (Apache) — funkcje "publiczne"
 *      (ex_sandbox_run, ex_lang_*, ex_sandbox_available) budują zadanie i
 *      wysyłają je do robotnika przez sudo;
 *   2. przez cli/exam_sandbox_worker.php, uruchamiany JAKO `examrun` — używa
 *      ex_sandbox_worker_run() i pomocniczych funkcji ex_sandbox_exec/cap/
 *      delete_tree/runner_script. Żadna z funkcji robotnika nie dotyka bazy
 *      danych ani configu aplikacji — cała treść zadania przychodzi przez
 *      argument (stdin JSON), żeby nie trzeba było niczego więcej dawać do
 *      odczytu kontu `examrun`.
 */

// ═══════════════════════════════════════════════════════════════════════════
//  Rejestr języków (Language.java)
// ═══════════════════════════════════════════════════════════════════════════

function ex_lang_registry(): array {
    static $reg = null;
    if ($reg !== null) return $reg;
    $reg = [
        'python' => [
            'label' => 'Python 3', 'sourceName' => 'solution.py', 'compileArgs' => null,
            'runArgs' => ['python3', '-I', 'solution.py'], 'limitVirtualMemory' => true,
        ],
        'php' => [
            'label' => 'PHP', 'sourceName' => 'solution.php', 'compileArgs' => null,
            'runArgs' => ['php', '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', 'solution.php'],
            'limitVirtualMemory' => true,
        ],
        'javascript' => [
            'label' => 'JavaScript (Node.js)', 'sourceName' => 'solution.js', 'compileArgs' => null,
            'runArgs' => ['node', '--max-old-space-size={mem}', 'solution.js'], 'limitVirtualMemory' => false,
        ],
        'java' => [
            'label' => 'Java', 'sourceName' => '{main}.java',
            'compileArgs' => ['javac', '-encoding', 'UTF-8', '-nowarn', '-d', '.', '{main}.java'],
            'runArgs' => ['java', '-Xmx{mem}m', '-XX:+UseSerialGC', '-XX:TieredStopAtLevel=1', '-cp', '.', '{main}'],
            'limitVirtualMemory' => false,
        ],
        'c' => [
            'label' => 'C', 'sourceName' => 'solution.c',
            'compileArgs' => ['gcc', '-O2', '-std=c11', '-o', 'solution', 'solution.c', '-lm'],
            'runArgs' => ['./solution'], 'limitVirtualMemory' => true,
        ],
        'cpp' => [
            'label' => 'C++', 'sourceName' => 'solution.cpp',
            'compileArgs' => ['g++', '-O2', '-std=c++17', '-o', 'solution', 'solution.cpp'],
            'runArgs' => ['./solution'], 'limitVirtualMemory' => true,
        ],
        'shell' => [
            'label' => 'Powłoka (sh)', 'sourceName' => 'solution.sh', 'compileArgs' => null,
            'runArgs' => ['sh', 'solution.sh'], 'limitVirtualMemory' => true,
        ],
    ];
    return $reg;
}

/** Zwraca definicję języka (z kluczem 'id' dołączonym) albo null. Rozwiązuje aliasy jak Language.byId(). */
function ex_lang_by_id(?string $id): ?array {
    if ($id === null) return null;
    $key = strtolower(trim($id));
    if ($key === 'py') $key = 'python';
    if ($key === 'js' || $key === 'node') $key = 'javascript';
    if ($key === 'c++') $key = 'cpp';
    if ($key === 'bash' || $key === 'sh') $key = 'shell';
    $reg = ex_lang_registry();
    if (!isset($reg[$key])) return null;
    return ['id' => $key] + $reg[$key];
}

function ex_lang_ids(): array { return array_keys(ex_lang_registry()); }

/** Odpowiednik Language.describeAll() — do /health i katalogu authoringu. */
function ex_lang_describe_all(): array {
    $out = [];
    foreach (ex_lang_registry() as $id => $l) {
        $out[] = ['id' => $id, 'label' => $l['label'], 'compiled' => $l['compileArgs'] !== null];
    }
    return $out;
}

/** Nazwa klasy publicznej w źródle Javy — decyduje o nazwie pliku (Language.mainName). */
function ex_lang_main_name(array $lang, string $source): string {
    if ($lang['id'] !== 'java') return 'solution';
    if (preg_match('/public\s+(?:final\s+|abstract\s+)?class\s+([A-Za-z_$][A-Za-z0-9_$]*)/', $source, $m)) {
        return $m[1];
    }
    return 'Main';
}

function ex_lang_source_file_name(array $lang, string $source): string {
    return str_replace('{main}', ex_lang_main_name($lang, $source), $lang['sourceName']);
}

/** Podstawia {main}/{mem} w szablonie argumentów (Language.resolve). */
function ex_lang_resolve(array $lang, array $template, string $source, int $memoryMb): array {
    $main = ex_lang_main_name($lang, $source);
    $out = [];
    foreach ($template as $a) {
        $out[] = str_replace(['{main}', '{mem}'], [$main, (string)$memoryMb], $a);
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Konfiguracja i dostępność narzędzi (Sandbox.fromEnv / Sandbox.available)
// ═══════════════════════════════════════════════════════════════════════════

function ex_sandbox_config(): array {
    $get = function (string $const, string $env, $default) {
        if (defined($const) && constant($const) !== '') return constant($const);
        $v = getenv($env);
        return ($v !== false && $v !== '') ? $v : $default;
    };
    return [
        'dir'          => (string)$get('EXAM_SANDBOX_DIR', 'EXAM_SANDBOX_DIR', '/tmp'),
        'maxOutput'    => (int)$get('EXAM_SANDBOX_MAX_OUTPUT', 'EXAM_SANDBOX_MAX_OUTPUT', 65536),
        'maxSource'    => (int)$get('EXAM_SANDBOX_MAX_SOURCE', 'EXAM_SANDBOX_MAX_SOURCE', 204800),
        'maxTimeoutMs' => (int)$get('EXAM_SANDBOX_MAX_TIMEOUT_MS', 'EXAM_SANDBOX_MAX_TIMEOUT_MS', 15000),
        'user'         => (string)$get('EXAM_SANDBOX_USER', 'EXAM_SANDBOX_USER', 'examrun'),
        'workerScript' => (string)$get('EXAM_SANDBOX_WORKER', 'EXAM_SANDBOX_WORKER', '/var/www/html/cli/exam_sandbox_worker.php'),
        'phpBinary'    => (string)$get('EXAM_SANDBOX_PHP_BIN', 'EXAM_SANDBOX_PHP_BIN', '/usr/local/bin/php'),
    ];
}

function ex_sandbox_which(string $bin): ?string {
    if ($bin === '' || str_starts_with($bin, './') || str_starts_with($bin, '/')) return $bin;
    $path = getenv('PATH');
    if ($path === false || $path === '') $path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        if ($dir === '') continue;
        $f = rtrim($dir, '/') . '/' . $bin;
        if (is_file($f) && is_executable($f)) return $f;
    }
    return null;
}

/** Czy dany język ma w obrazie działający interpreter/kompilator (Sandbox.available). */
function ex_sandbox_available(string $languageId): bool {
    $lang = ex_lang_by_id($languageId);
    if (!$lang) return false;
    $bin = $lang['compileArgs'] !== null ? $lang['compileArgs'][0] : $lang['runArgs'][0];
    return ex_sandbox_which($bin) !== null;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Kształt wyniku (RunResult)
// ═══════════════════════════════════════════════════════════════════════════

function ex_run_result_failure(string $message): array {
    return [
        'started' => false, 'compiled' => true, 'compileError' => '',
        'stdout' => '', 'stderr' => '', 'exitCode' => -1, 'timedOut' => false,
        'durationMs' => 0, 'engineError' => $message !== '' ? $message : 'Nieznany błąd piaskownicy.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
//  Strona www-data: budowa zadania + wysyłka do robotnika przez sudo
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Uruchamia kod kursanta na jednym wejściu. Odpowiednik Sandbox.run(...).
 * $timeoutMs — limit czasu ściennego (przycinany do EXAM_SANDBOX_MAX_TIMEOUT_MS).
 */
function ex_sandbox_run(string $languageId, string $source, ?string $stdin, int $timeoutMs, int $memoryMb): array {
    $cfg  = ex_sandbox_config();
    $lang = ex_lang_by_id($languageId);
    if (!$lang) return ex_run_result_failure('Nieznany język zadania.');

    $source = (string)$source;
    if (strlen($source) > $cfg['maxSource']) {
        return ex_run_result_failure('Kod przekracza dopuszczalny rozmiar (' . $cfg['maxSource'] . ' znaków).');
    }

    $wall = min($timeoutMs > 0 ? $timeoutMs : 5000, $cfg['maxTimeoutMs']);
    $mem  = $memoryMb > 0 ? $memoryMb : 128;

    $job = [
        'languageId' => $lang['id'],
        'source'     => $source,
        'stdin'      => (string)($stdin ?? ''),
        'wallMs'     => $wall,
        'memoryMb'   => $mem,
        'sandboxDir' => $cfg['dir'],
        'maxOutput'  => $cfg['maxOutput'],
    ];

    return ex_sandbox_dispatch_worker($job, $cfg);
}

/** Wysyła zadanie do cli/exam_sandbox_worker.php uruchomionego jako $cfg['user'] przez `sudo -n`. */
function ex_sandbox_dispatch_worker(array $job, array $cfg): array {
    $jobJson = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jobJson === false) return ex_run_result_failure('Nie udało się zserializować zadania piaskownicy.');

    // Zero argumentów CLI — dane idą wyłącznie przez stdin, żeby reguła sudoers
    // mogła dopuścić dokładnie JEDNO, stałe polecenie bez żadnych wildcardów.
    $argv = ['sudo', '-n', '-u', $cfg['user'], '--', $cfg['phpBinary'], $cfg['workerScript']];
    $cmd  = implode(' ', array_map('escapeshellarg', $argv));

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ex_run_result_failure('Nie udało się uruchomić procesu piaskownicy (sudo -u ' . $cfg['user'] . ').');
    }

    fwrite($pipes[0], $jobJson);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    // Zapasowy, zewnętrzny limit czasu — na wypadek awarii sudo/PHP robotnika
    // samej w sobie (normalnie zadanie kończy `timeout` wewnątrz robotnika,
    // patrz ex_sandbox_exec()). Duży margines na start sudo+php+kompilację.
    $deadline  = microtime(true) + ($job['wallMs'] / 1000) + 15.0;
    $capOuter  = $job['maxOutput'] + 4096;
    $stdoutBuf = '';
    $stderrBuf = '';

    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = null; $except = null;
        @stream_select($read, $write, $except, 0, 200000);
        foreach ($read as $s) {
            $chunk = stream_get_contents($s);
            if ($chunk === false || $chunk === '') continue;
            if ($s === $pipes[1]) { if (strlen($stdoutBuf) < $capOuter) $stdoutBuf .= $chunk; }
            else                  { if (strlen($stderrBuf) < $capOuter) $stderrBuf .= $chunk; }
        }
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            usleep(300000);
            $stdoutBuf .= (string)@stream_get_contents($pipes[1]);
            $stderrBuf .= (string)@stream_get_contents($pipes[2]);
            @fclose($pipes[1]); @fclose($pipes[2]);
            @proc_close($proc);
            return ex_run_result_failure('Piaskownica nie odpowiedziała w oczekiwanym czasie (sudo/robotnik nie reaguje).');
        }
    }
    $stdoutBuf .= (string)@stream_get_contents($pipes[1]);
    $stderrBuf .= (string)@stream_get_contents($pipes[2]);
    @fclose($pipes[1]); @fclose($pipes[2]);
    $exit = proc_close($proc);

    $result = json_decode($stdoutBuf, true);
    if (!is_array($result) || !array_key_exists('engineError', $result)) {
        $diag = trim(mb_substr($stderrBuf, 0, 800));
        return ex_run_result_failure('Piaskownica zwróciła nieprawidłową odpowiedź (sudo/php kod ' . $exit . ').' . ($diag !== '' ? ' ' . $diag : ''));
    }
    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════
//  Strona robotnika (examrun): faktyczne wykonanie kodu ucznia
// ═══════════════════════════════════════════════════════════════════════════

/** Treść wrappera powłoki ustawiającego limity zasobów (odpowiednik Sandbox.RUNNER). */
function ex_sandbox_runner_script(): string {
    return "#!/bin/sh\n"
         . "# Runner piaskownicy — ustawia limity zasobów i oddaje sterowanie programowi kursanta.\n"
         . "ulimit -t \"\${EXAM_CPU:-10}\"   2>/dev/null || true\n"
         . "ulimit -f \"\${EXAM_FSIZE:-8192}\" 2>/dev/null || true\n"
         . "if [ -n \"\${EXAM_AS:-}\" ]; then ulimit -v \"\$EXAM_AS\" 2>/dev/null || true; fi\n"
         . "umask 077\n"
         . "exec \"\$@\"\n";
}

function ex_sandbox_cap(string $s, int $max): string {
    if (mb_strlen($s) <= $max) return $s;
    return mb_substr($s, 0, $max) . "\n… (obcięto — wyjście przekroczyło {$max} znaków)";
}

function ex_sandbox_delete_tree(string $dir): void {
    if ($dir === '' || !is_dir($dir) || is_link($dir)) return;
    $items = @scandir($dir);
    if ($items === false) return;
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') continue;
        $p = $dir . '/' . $it;
        if (is_dir($p) && !is_link($p)) ex_sandbox_delete_tree($p);
        else @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Wywoływane WYŁĄCZNIE wewnątrz cli/exam_sandbox_worker.php (jako $cfg['user']).
 * $job — zdekodowany JSON od strony www-data (patrz ex_sandbox_run()).
 */
function ex_sandbox_worker_run(array $job): array {
    $lang = ex_lang_by_id($job['languageId'] ?? null);
    if (!$lang) return ex_run_result_failure('Nieznany język zadania.');

    $source  = (string)($job['source'] ?? '');
    $stdin   = (string)($job['stdin'] ?? '');
    $wallMs  = max(1, (int)($job['wallMs'] ?? 5000));
    $mem     = max(1, (int)($job['memoryMb'] ?? 128));
    $maxOut  = max(1024, (int)($job['maxOutput'] ?? 65536));
    $rootDir = (string)($job['sandboxDir'] ?? '/tmp');

    $dir = rtrim($rootDir, '/') . '/exam-' . bin2hex(random_bytes(8));
    if (!@mkdir($dir, 0700, true)) {
        return ex_run_result_failure('Piaskownica: nie udało się utworzyć katalogu roboczego (' . $dir . ').');
    }

    try {
        $runner = $dir . '/runner.sh';
        file_put_contents($runner, ex_sandbox_runner_script());
        chmod($runner, 0700);

        $srcFile = $dir . '/' . ex_lang_source_file_name($lang, $source);
        file_put_contents($srcFile, $source);

        $stdinFile = $dir . '/stdin.txt';
        file_put_contents($stdinFile, $stdin);

        // ── Kompilacja (jeśli język tego wymaga) ────────────────────────────
        if ($lang['compileArgs'] !== null) {
            $cc = array_merge(['/bin/sh', $runner], ex_lang_resolve($lang, $lang['compileArgs'], $source, $mem));
            $ce = ex_sandbox_exec($cc, $dir, null, max($wallMs, 10000), $mem, false);
            if ($ce['engineError'] !== '') return ex_run_result_failure($ce['engineError']);
            if ($ce['timedOut'] || $ce['exitCode'] !== 0) {
                return [
                    'started' => true, 'compiled' => false,
                    'compileError' => ex_sandbox_cap($ce['timedOut']
                        ? 'Kompilacja przekroczyła limit czasu.'
                        : ($ce['stderr'] !== '' ? $ce['stderr'] : $ce['stdout']), $maxOut),
                    'stdout' => '', 'stderr' => '', 'exitCode' => -1, 'timedOut' => false,
                    'durationMs' => $ce['durationMs'], 'engineError' => '',
                ];
            }
        }

        // ── Uruchomienie ─────────────────────────────────────────────────────
        $rc = array_merge(['/bin/sh', $runner], ex_lang_resolve($lang, $lang['runArgs'], $source, $mem));
        $re = ex_sandbox_exec($rc, $dir, $stdinFile, $wallMs, $mem, $lang['limitVirtualMemory']);
        if ($re['engineError'] !== '') return ex_run_result_failure($re['engineError']);

        return [
            'started' => true, 'compiled' => true, 'compileError' => '',
            'stdout' => ex_sandbox_cap($re['stdout'], $maxOut),
            'stderr' => ex_sandbox_cap($re['stderr'], $maxOut),
            'exitCode' => $re['exitCode'], 'timedOut' => $re['timedOut'],
            'durationMs' => $re['durationMs'], 'engineError' => '',
        ];
    } finally {
        ex_sandbox_delete_tree($dir);
    }
}

/**
 * Uruchamia jedno polecenie z twardym limitem czasu przez `timeout(1)` (coreutils,
 * zawsze obecne w obrazie Debiana — nie wymaga dodatkowej zależności).
 *
 * `timeout` bez --foreground z definicji umieszcza uruchamiane polecenie w NOWEJ
 * grupie procesów i przy przekroczeniu czasu zabija całą grupę (killpg) — to
 * jedyny niezawodny, sprawdzony sposób ubicia całego drzewa procesów (w tym
 * ewentualnych potomków forkowanych przez kod ucznia) dostępny bez pisania
 * własnej logiki zarządzania grupami procesów w PHP. `--kill-after=2` dobija
 * SIGKILL-em, gdyby program zignorował pierwszy SIGTERM.
 *
 * Zewnętrzna pętla PHP (stream_select) to tylko odczyt strumieni + zapasowy
 * limit czasu na wypadek, gdyby `timeout` samo się zawiesiło (skrajnie mało
 * prawdopodobne) — w tym jedynym awaryjnym przypadku `proc_terminate()` zabija
 * tylko proces `timeout`, nie całą jego grupę; ewentualny osierocony potomek
 * i tak zostanie ograniczony przez odziedziczone ulimity (-t/-f/-v).
 */
function ex_sandbox_exec(array $cmd, string $dir, ?string $stdinFile, int $wallMs, int $memoryMb, bool $limitAs): array {
    $t0 = microtime(true);

    $wallSec = max(1, (int)ceil($wallMs / 1000));
    $full = array_merge(['timeout', '--kill-after=2', '--signal=TERM', $wallSec . 's'], $cmd);
    $cmdline = implode(' ', array_map('escapeshellarg', $full));

    $descriptors = [
        0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = [
        'PATH'   => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME'   => $dir,
        'TMPDIR' => $dir,
        'LANG'   => 'C.UTF-8',
        'LC_ALL' => 'C.UTF-8',
        'EXAM_CPU'   => (string)max(1, intdiv($wallMs, 1000) + 1),
        'EXAM_FSIZE' => '8192',
    ];
    if ($limitAs) $env['EXAM_AS'] = (string)($memoryMb * 1024);

    $out = ['stdout' => '', 'stderr' => '', 'exitCode' => -1, 'timedOut' => false, 'durationMs' => 0, 'engineError' => ''];

    $proc = @proc_open($cmdline, $descriptors, $pipes, $dir, $env);
    if (!is_resource($proc)) {
        $out['engineError'] = 'Nie udało się uruchomić procesu (' . ($cmd[1] ?? $cmd[0] ?? '?') . ').';
        $out['durationMs'] = (int)round((microtime(true) - $t0) * 1000);
        return $out;
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdoutBuf = ''; $stderrBuf = '';
    $capLimit = 512 * 1024; // twardy limit odczytu w PHP; właściwe obcinanie robi ex_sandbox_cap() wyżej
    $hardDeadline = microtime(true) + $wallSec + 5; // margines na start timeout(1)+sh+exec

    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = null; $except = null;
        @stream_select($read, $write, $except, 0, 200000);
        foreach ($read as $s) {
            $chunk = stream_get_contents($s);
            if ($chunk === false || $chunk === '') continue;
            if ($s === $pipes[1]) { if (strlen($stdoutBuf) < $capLimit) $stdoutBuf .= $chunk; }
            else                  { if (strlen($stderrBuf) < $capLimit) $stderrBuf .= $chunk; }
        }
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        if (microtime(true) > $hardDeadline) {
            proc_terminate($proc, 9);
            $out['timedOut'] = true;
            usleep(300000);
            break;
        }
    }
    $stdoutBuf .= (string)@stream_get_contents($pipes[1]);
    $stderrBuf .= (string)@stream_get_contents($pipes[2]);
    @fclose($pipes[1]); @fclose($pipes[2]);
    $exit = proc_close($proc);

    // `timeout` sygnalizuje przekroczenie limitu kodem 124 (SIGTERM) albo 137 (128+9, po --kill-after).
    if ($exit === 124 || $exit === 137) $out['timedOut'] = true;

    $out['stdout']     = $stdoutBuf;
    $out['stderr']     = $stderrBuf;
    $out['exitCode']   = $out['timedOut'] ? -1 : $exit;
    $out['durationMs'] = (int)round((microtime(true) - $t0) * 1000);
    return $out;
}
